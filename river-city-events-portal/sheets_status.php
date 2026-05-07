<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/sheets.php';

$configured = sheets_is_configured();
$url = sheets_get_url();

echo json_encode([
    'configured' => $configured,
    'sheet_url' => $url,
], JSON_UNESCAPED_UNICODE);
