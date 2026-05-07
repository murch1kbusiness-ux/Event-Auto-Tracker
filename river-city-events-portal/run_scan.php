<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/New_York');
set_time_limit(190);

$root = dirname(__DIR__);
$data_dir = __DIR__ . '/data';
$log_dir = $root . '/logs';
$meta_path = $data_dir . '/scan_meta.json';
$events_path = $data_dir . '/events.json';
$runner_log = $log_dir . '/run_scan.log';

function scan_log(string $message): void {
    global $log_dir, $runner_log;
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0775, true);
    }
    file_put_contents($runner_log, date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL, FILE_APPEND);
}

function load_env_file(string $path): array {
    if (!file_exists($path)) {
        return [];
    }

    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
    return $env;
}

function run_command_with_timeout(string $command, string $cwd, int $timeout_seconds): array {
    $descriptor_spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptor_spec, $pipes, $cwd);
    if (!is_resource($process)) {
        return ['exit_code' => 1, 'timed_out' => false, 'output' => ['Could not start scraper process.']];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = [];
    $started = time();
    $timed_out = false;

    while (true) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        if ($stdout !== '') {
            $output[] = $stdout;
        }
        if ($stderr !== '') {
            $output[] = $stderr;
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }

        if ((time() - $started) >= $timeout_seconds) {
            $timed_out = true;
            proc_terminate($process);
            break;
        }

        usleep(200000);
    }

    $output[] = stream_get_contents($pipes[1]);
    $output[] = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);

    return [
        'exit_code' => $timed_out ? 124 : $exit_code,
        'timed_out' => $timed_out,
        'output' => array_values(array_filter(array_map('trim', $output))),
    ];
}

try {
    $env = load_env_file($root . '/.env');
    $python = $env['PYTHON_BIN'] ?? getenv('PYTHON_BIN') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    $timeout = (int)($env['SCAN_TIMEOUT_SECONDS'] ?? getenv('SCAN_TIMEOUT_SECONDS') ?: 180);
    $timeout = max(30, min($timeout, 300));

    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'set USE_MOCK=false&& set PYTHONIOENCODING=utf-8&& ' . escapeshellarg($python) . ' scraper.py';
    } else {
        $command = 'USE_MOCK=false PYTHONIOENCODING=utf-8 ' . escapeshellarg($python) . ' scraper.py';
    }
    scan_log("START command={$command} timeout={$timeout}");

    $result = run_command_with_timeout($command, $root, $timeout);
    scan_log('END exit=' . $result['exit_code'] . ' timed_out=' . ($result['timed_out'] ? 'yes' : 'no'));
    foreach ($result['output'] as $line) {
        scan_log('OUT ' . $line);
    }

    if ($result['timed_out']) {
        http_response_code(504);
        echo json_encode([
            'success' => false,
            'error' => 'Live scraper timed out. Partial previous results may still be visible.',
            'scraper_output' => $result['output'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($result['exit_code'] !== 0) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Live scraper failed. Check logs/run_scan.log and logs/scraper.log.',
            'scraper_output' => $result['output'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $meta = file_exists($meta_path)
        ? json_decode(file_get_contents($meta_path), true)
        : [];
    if (!is_array($meta)) {
        $meta = [];
    }

    $events = file_exists($events_path)
        ? json_decode(file_get_contents($events_path), true)
        : [];
    if (!is_array($events)) {
        $events = [];
    }

    echo json_encode(array_merge($meta, [
        'success' => true,
        'telegram_sent' => (bool)($meta['telegram_sent'] ?? false),
        'telegram_reason' => $meta['telegram_reason'] ?? '',
        'total_stored' => count($events),
        'source_status' => $meta['source_status'] ?? [],
        'source_errors' => $meta['source_errors'] ?? [],
    ]), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    scan_log('FATAL ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Scan failed. Check logs/run_scan.log.',
    ], JSON_UNESCAPED_UNICODE);
}
