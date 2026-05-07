<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/notifier.php';

echo json_encode([
    'configured' => telegram_is_configured(),
    'bot_configured' => telegram_bot_configured(),
    'chat_id_configured' => telegram_chat_id_configured(),
    'chat_id' => telegram_get_chat_id(),
], JSON_UNESCAPED_UNICODE);
