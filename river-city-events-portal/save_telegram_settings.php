<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/notifier.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$chat_id = $_POST['telegram_chat_id'] ?? '';

// Check if bot token is configured first
if (!telegram_bot_configured()) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'missing_bot_token',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = save_telegram_settings($chat_id);

if (!$result['saved']) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $result['reason'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'chat_id' => telegram_get_chat_id(),
], JSON_UNESCAPED_UNICODE);
