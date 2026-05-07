<?php
/**
 * notifier.php
 *
 * Sends a Telegram summary after each scan.
 * If TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID are not set in config.php,
 * this returns a "not_configured" status — it never crashes the run.
 */

if (!function_exists('portal_config')) {
    function portal_config(): array {
        $path = __DIR__ . '/config.php';
        if (file_exists($path)) {
            $cfg = require $path;
            return is_array($cfg) ? $cfg : [];
        }
        return [];
    }
}

/**
 * Send (or skip) the post-scan Telegram message via Python notifier.
 *
 * @return array ['sent' => bool, 'reason' => string]
 */
function send_telegram_summary(int $total_found, int $new_count): array {
    $token = project_env('TELEGRAM_BOT_TOKEN');
    $telegram_cfg = telegram_saved_settings();
    $chat_id = trim($telegram_cfg['telegram_chat_id'] ?? '');

    if ($token === '') {
        return ['sent' => false, 'reason' => 'missing_bot_token'];
    }
    if ($chat_id === '') {
        return ['sent' => false, 'reason' => 'missing_chat_id'];
    }

    $root = dirname(__DIR__, 2);
    $python = project_env('PYTHON_BIN') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');

    // Write script to a temp file to avoid escapeshellarg quote-stripping on Windows.
    $tmp = tempnam(sys_get_temp_dir(), 'tg_notify_') . '.py';
    $root_escaped = str_replace('\\', '\\\\', $root);
    file_put_contents($tmp,
        "import sys, io, json\n" .
        "sys.path.insert(0, \"" . $root_escaped . "\")\n" .
        "_real = sys.stdout\n" .
        "sys.stdout = io.StringIO()\n" .
        "try:\n" .
        "    from notifier import send_summary\n" .
        "    result = send_summary(" . (int)$total_found . ", " . (int)$new_count . ")\n" .
        "finally:\n" .
        "    sys.stdout = _real\n" .
        "print(json.dumps(result or {\"sent\": False, \"reason\": \"unknown_error\"}))\n"
    );

    $descriptor_spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($python . ' ' . escapeshellarg($tmp), $descriptor_spec, $pipes, $root);

    if (!is_resource($process)) {
        @unlink($tmp);
        return ['sent' => false, 'reason' => 'notifier_process_failed'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    @unlink($tmp);

    $lines = array_filter(array_map('trim', explode("\n", $stdout)));
    $json_line = end($lines) ?: '';

    if (!$json_line) {
        $reason = $stderr ? ('python_error: ' . substr(trim($stderr), 0, 200)) : 'notifier_no_output';
        return ['sent' => false, 'reason' => $reason];
    }

    $result = json_decode($json_line, true);
    if (!is_array($result)) {
        return ['sent' => false, 'reason' => 'notifier_output_invalid'];
    }
    return ['sent' => (bool)($result['sent'] ?? false), 'reason' => $result['reason'] ?? ''];
}

function telegram_config_path(): string {
    $cfg = portal_config();
    $path = trim($cfg['telegram_config_path'] ?? '') ?: project_env('TELEGRAM_CONFIG_PATH');
    if ($path !== '') {
        return $path;
    }
    return dirname(__DIR__, 2) . '/private/telegram_settings.json';
}

function project_env(string $key): string {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return trim($value);
    }

    $path = dirname(__DIR__, 2) . '/.env';
    if (!file_exists($path)) {
        return '';
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$env_key, $env_value] = explode('=', $line, 2);
        if (trim($env_key) === $key) {
            return trim($env_value, " \t\n\r\0\x0B\"'");
        }
    }

    return '';
}

function telegram_saved_settings(): array {
    $path = telegram_config_path();
    if (!file_exists($path)) {
        return [];
    }

    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function telegram_is_configured(): bool {
    $token = project_env('TELEGRAM_BOT_TOKEN');
    $settings = telegram_saved_settings();
    $chat_id = trim($settings['telegram_chat_id'] ?? '');
    return $token !== '' && $chat_id !== '';
}

function telegram_bot_configured(): bool {
    return project_env('TELEGRAM_BOT_TOKEN') !== '';
}

function telegram_chat_id_configured(): bool {
    $settings = telegram_saved_settings();
    return trim($settings['telegram_chat_id'] ?? '') !== '';
}

function telegram_get_chat_id(): string {
    $settings = telegram_saved_settings();
    return trim($settings['telegram_chat_id'] ?? '');
}

function save_telegram_settings(string $chat_id): array {
    $chat_id = trim($chat_id);
    if ($chat_id === '') {
        return ['saved' => false, 'reason' => 'chat_id_required'];
    }

    $token = project_env('TELEGRAM_BOT_TOKEN');
    if ($token === '') {
        return ['saved' => false, 'reason' => 'bot_token_not_configured_in_env'];
    }

    $path = telegram_config_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return ['saved' => false, 'reason' => 'config_directory_not_writable'];
    }

    $payload = [
        'telegram_chat_id' => $chat_id,
        'updated_at' => date('c'),
    ];

    $ok = file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return ['saved' => $ok !== false, 'reason' => $ok === false ? 'config_file_not_writable' : ''];
}
