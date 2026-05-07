<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/notifier.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$meta_path = __DIR__ . '/data/scan_meta.json';
if (!file_exists($meta_path)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'No scan summary found yet. Run Scan first.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$meta = json_decode(file_get_contents($meta_path), true);
if (!is_array($meta)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Scan summary is invalid.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = send_telegram_summary((int)($meta['total_found'] ?? 0), (int)($meta['new_count'] ?? 0));

echo json_encode([
    'success' => (bool)($result['sent'] ?? false),
    'reason' => $result['reason'] ?? '',
], JSON_UNESCAPED_UNICODE);
