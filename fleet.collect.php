<?php

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

function fleet_visitors($db): ?int {
	if (!is_array($db) || empty($db['host']) || empty($db['database'])) return null;
	try {
		$pdo = new PDO('mysql:host='.$db['host'].';dbname='.$db['database'], (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		return (int)$pdo->query('SELECT COUNT(DISTINCT token) FROM visitors')->fetchColumn();
	}
	catch (\Throwable $e){
		return null;
	}
}

function fleet_env(string $block): string {
	if (str_contains($block, 'phlo_dev')) return 'dev';
	if (str_contains($block, 'phlo_stage')) return 'stage';
	return 'prod';
}

function fleet_caddy_apps(): array {
	$apps = [];
	foreach (glob('/srv/control/sites/*.caddy') as $file){
		$appName = basename($file, '.caddy');
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
			foreach (explode(',', $b['hosts']) as $host){
				$host = trim($host);
				if ($host === '' || !str_contains($host, '.')) continue;
				$he = str_starts_with($host, 'dev.') ? 'dev' : (str_starts_with($host, 'stage.') ? 'stage' : $env);
				$apps[] = ['host' => $host, 'env' => $he, 'app' => $appName];
			}
		}
	}
	return $apps;
}

function fleet_collect(): array {
	$apps = fleet_caddy_apps();

	$errors = [];
	foreach (glob('/srv/*/data/errors.json') as $file){
		$data = json_decode((string)@file_get_contents($file), true);
		if (!is_array($data) || !$data) continue;
		$occ = 0;
		$latest = '';
		foreach ($data as $e){
			$occ += (int)($e['count'] ?? 1);
			$lo = (string)($e['lastOccurred'] ?? '');
			if ($lo > $latest) $latest = $lo;
		}
		$errors[basename(dirname(dirname($file)))] = ['unique' => count($data), 'occurrences' => $occ, 'latest' => $latest];
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
	if (preg_match("/const phlo\\s*=\\s*'([^']+)'/", (string)@file_get_contents('/srv/control/phlo/phlo.php'), $vm)) $version = $vm[1];

	$ini = @parse_ini_file('/srv/control/dashboard/data/creds.ini', true, INI_SCANNER_RAW);
	$visitors = fleet_visitors(is_array($ini) ? ($ini['mysql'] ?? null) : null);

	return [
		'server' => gethostname(),
		'phlo' => $version,
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
	];
}

if (!defined('FLEET_COLLECT_LIB')) echo json_encode(fleet_collect(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
