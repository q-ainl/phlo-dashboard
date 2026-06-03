<?php

function fleet_ping(array $hosts): array {
	$hosts = array_values(array_unique($hosts));
	if (!$hosts || !function_exists('curl_multi_init')) return [];
	$mh = curl_multi_init();
	$handles = [];
	foreach ($hosts as $i => $host){
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => 'https://'.$host.'/',
			CURLOPT_NOBODY => true,
			CURLOPT_RESOLVE => [$host.':443:127.0.0.1'],
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

function fleet_collect(): array {
	$apps = [];
	foreach (glob('/srv/control/sites/*.caddy') as $file){
		if (!preg_match_all('/^([a-z0-9*][^{(\n]*)\{/m', (string)@file_get_contents($file), $m)) continue;
		foreach ($m[1] as $line){
			foreach (explode(',', $line) as $host){
				$host = trim($host);
				if (!$host || !str_contains($host, '.')) continue;
				$env = str_starts_with($host, 'dev.') ? 'dev' : (str_starts_with($host, 'stage.') ? 'stage' : 'prod');
				$apps[] = ['host' => $host, 'env' => $env, 'app' => basename($file, '.caddy')];
			}
		}
	}

	$ping = fleet_ping(array_column($apps, 'host'));
	foreach ($apps as $i => $a){
		$apps[$i]['code'] = $ping[$a['host']]['code'] ?? 0;
		$apps[$i]['ms'] = $ping[$a['host']]['ms'] ?? 0;
	}

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

	return [
		'server' => gethostname(),
		'phlo' => $version,
		'time' => time(),
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
