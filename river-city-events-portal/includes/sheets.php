<?php
/**
 * sheets.php - Check Google Sheets configuration status
 */

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

function sheets_is_configured(): bool {
    $root = dirname(__DIR__, 2);
    $env = load_env_file($root . '/.env');
    $spreadsheet_id = trim($env['SPREADSHEET_ID'] ?? '');
    $creds_file = $root . '/' . ($env['GOOGLE_CREDENTIALS_FILE'] ?? 'credentials.json');
    return $spreadsheet_id !== '' && file_exists($creds_file);
}

function sheets_get_url(): string {
    $root = dirname(__DIR__, 2);
    $env = load_env_file($root . '/.env');
    return trim($env['SHEET_URL'] ?? '');
}

function sheets_build_url(string $spreadsheet_id): string {
    return "https://docs.google.com/spreadsheets/d/{$spreadsheet_id}";
}
