<?php

# Control base, derived (no hardcoded /srv vs /files/Server): the dir holding
# this dashboard. dirname(__DIR__) works in-app and when run as a file; when the
# script is piped over SSH stdin (__DIR__ unreliable) the poller passes the base
# as the first absolute-path arg.
function fleet_base(): string {
	$arg = $_SERVER['argv'][1] ?? '';
	if (is_string($arg) && $arg !== '' && $arg[0] === '/') return rtrim($arg, '/');
	return dirname(__DIR__);
}

function fleet_ping(array $hosts, bool $local = false): array {
	$hosts = array_values(array_unique($hosts));
	if (!$hosts || !function_exists('curl_multi_init')) return [];
	$mh = curl_multi_init();
	$handles = [];
	foreach ($hosts as $i => $host){
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => 'https://'.$host.'/',
			CURLOPT_NOBODY => true,
			CURLOPT_RESOLVE => $local ? [$host.':443:127.0.0.1'] : [],
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_RETURNTRANSFER => true,
		]);
		curl_multi_add_handle($mh, $ch);
		$handles[$host] = $ch;
	}
	do {
		$status = curl_multi_exec($mh, $running);
		if ($running) curl_multi_select($mh, 1);
	} while ($running > 0 && $status === CURLM_OK);
	$out = [];
	foreach ($handles as $host => $ch){
		$out[$host] = ['code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'ms' => (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000)];
		curl_multi_remove_handle($mh, $ch);
		curl_close($ch);
	}
	curl_multi_close($mh);
	return $out;
}

function fleet_visitors($db): array {
	if (!is_array($db) || empty($db['host']) || empty($db['database'])) return [];
	try {
		$pdo = new PDO('mysql:host='.$db['host'].';dbname='.$db['database'], (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		return array_map('intval', $pdo->query('SELECT host, COUNT(DISTINCT token) c FROM visitors GROUP BY host')->fetchAll(PDO::FETCH_KEY_PAIR));
	}
	catch (\Throwable $e){
		return [];
	}
}

function fleet_env(string $block): string {
	if (str_contains($block, 'phlo_dev')) return 'dev';
	if (str_contains($block, 'phlo_stage')) return 'stage';
	return 'prod';
}

function fleet_caddy_apps(): array {
	$apps = [];
	$skip = ['us', 'control', 'whatsapp'];
	foreach (glob(fleet_base().'/sites/*.caddy') as $file){
		$appName = basename($file, '.caddy');
		if (in_array($appName, $skip, true)) continue;
		$blocks = [];
		$hosts = null;
		$buf = '';
		foreach (explode("\n", (string)@file_get_contents($file)) as $line){
			if (preg_match('/^([a-z0-9*][^{(\n]*?)\s*\{/', $line, $m)){
				if ($hosts !== null) $blocks[] = ['hosts' => $hosts, 'block' => $buf];
				$hosts = trim($m[1]);
				$buf = $line."\n";
			} elseif ($hosts !== null){
				$buf .= $line."\n";
			}
		}
		if ($hosts !== null) $blocks[] = ['hosts' => $hosts, 'block' => $buf];
		foreach ($blocks as $b){
			$env = fleet_env($b['block']);
			$hostnames = [];
			foreach (explode(',', $b['hosts']) as $host){
				$host = trim($host);
				if ($host === '' || !str_contains($host, '.')) continue;
				$hostnames[] = $host;
			}
			if (!$hostnames) continue;
			$nonWww = array_values(array_filter($hostnames, fn($h) => !str_starts_with($h, 'www.')));
			$candidates = $nonWww ?: $hostnames;
			usort($candidates, fn($a, $c) => strlen($a) <=> strlen($c) ?: strcmp($a, $c));
			$primary = $candidates[0];
			$he = str_starts_with($primary, 'dev.') ? 'dev' : (str_starts_with($primary, 'stage.') ? 'stage' : $env);
			$aliases = array_values(array_filter($hostnames, fn($h) => $h !== $primary));
			$apps[] = ['host' => $primary, 'aliases' => $aliases, 'env' => $he, 'app' => $appName];
		}
	}
	return $apps;
}

function fleet_collect(): array {
	$apps = fleet_caddy_apps();

	$errors = [];
	foreach (glob(dirname(fleet_base()).'/*/data/errors.json') as $file){
		$data = json_decode((string)@file_get_contents($file), true);
		if (!is_array($data) || !$data) continue;
		$occ = 0;
		$latest = '';
		foreach ($data as $e){
			$occ += (int)($e['count'] ?? 1);
			$lo = (string)($e['lastOccurred'] ?? '');
			if ($lo > $latest) $latest = $lo;
		}
		$errors[basename(dirname(dirname($file)))] = ['unique' => count($data), 'occurrences' => $occ, 'latest' => $latest, 'entries' => array_slice($data, 0, 100, true)];
	}

	$mem = ['MemTotal' => 0, 'MemAvailable' => 0];
	foreach (explode("\n", (string)@file_get_contents('/proc/meminfo')) as $line){
		if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $line, $mm)) $mem[$mm[1]] = (int)$mm[2] * 1024;
	}
	$load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
	$cpu = max(1, substr_count((string)@file_get_contents('/proc/cpuinfo'), 'processor'));
	$diskTotal = (int)@disk_total_space('/');
	$diskFree = (int)@disk_free_space('/');
	$uptime = (int)(float)strtok((string)@file_get_contents('/proc/uptime'), ' ');

	$version = '?';
	if (preg_match("/const phlo\\s*=\\s*'([^']+)'/", (string)@file_get_contents(fleet_base().'/phlo/phlo.php'), $vm)) $version = $vm[1];

	$engineFiles = glob(fleet_base().'/phlo/*.php') ?: [fleet_base().'/phlo/phlo.php'];
	$buildTs = 0;
	foreach ($engineFiles as $ef) $buildTs = max($buildTs, (int)@filemtime($ef));
	$build = $buildTs ? date('Y-m-d H:i', $buildTs) : '?';

	$ini = @parse_ini_file(fleet_base().'/dashboard/data/creds.ini', true, INI_SCANNER_RAW);
	$visitors = fleet_visitors(is_array($ini) ? ($ini['mysql'] ?? null) : null);

	return [
		'server' => gethostname(),
		'phlo' => $version,
		'build' => $build,
		'time' => time(),
		'visitors' => $visitors,
		'metrics' => [
			'cpu_count' => $cpu,
			'load1' => round($load[0] ?? 0, 2),
			'mem_total' => $mem['MemTotal'],
			'mem_used' => max(0, $mem['MemTotal'] - $mem['MemAvailable']),
			'disk_total' => $diskTotal,
			'disk_used' => max(0, $diskTotal - $diskFree),
			'uptime_sec' => $uptime,
		],
		'apps' => $apps,
		'errors' => $errors,
		'whatsapp' => fleet_whatsapp(),
	];
}

function fleet_whatsapp(): array {
	$list = [];
	foreach (glob(fleet_base().'/config/wa*.js') ?: [] as $f){
		if (!preg_match("/\\(\\s*'([a-z0-9]+)'\\s*,\\s*(\\d+)\\s*,\\s*'([^']+)'/", (string)@file_get_contents($f), $m)) continue;
		$port = (int)$m[2];
		$ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => 'secret: '.$m[3], 'timeout' => 3, 'ignore_errors' => true]]);
		$res = ($h = @file_get_contents('http://127.0.0.1:'.$port.'/health', false, $ctx)) ? json_decode($h, true) : null;
		$entry = ['instance' => $m[1], 'port' => $port, 'status' => is_array($res) ? (string)($res['status'] ?? 'onbekend') : 'offline'];
		if (is_array($res)){
			$entry['uptime'] = (int)($res['uptime'] ?? 0);
			$entry['webhook'] = (bool)($res['webhook'] ?? false);
			if (($res['status'] ?? '') !== 'ready'){
				$qr = ($q = @file_get_contents('http://127.0.0.1:'.$port.'/qr', false, $ctx)) ? json_decode($q, true) : null;
				if (is_array($qr) && !empty($qr['qr'])) $entry['qr'] = (string)$qr['qr'];
			}
		}
		$list[] = $entry;
	}
	return $list;
}

if (!defined('FLEET_COLLECT_LIB')) echo json_encode(fleet_collect(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
