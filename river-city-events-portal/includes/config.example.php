<?php
/**
 * Copy this file to config.php and edit the values for your hosting account.
 * Never commit real Telegram credentials.
 */
return [
    // Local development example:
    // 'base_url' => 'http://localhost:8000',
    //
    // Namecheap example:
    // 'base_url' => 'https://yourdomain.com/river-city-events',
    'base_url' => '',

    // Source mode: 'live' or 'mock'
    // live  = fetch from real public venue websites
    // mock  = use local test pages in /venues/ (useful if site layouts change)
    'source_mode' => 'live',

    // Optional. Used in the dashboard navigation.
    'github_url' => 'https://github.com/murch1kbusiness-ux/Event-Auto-Tracker',

    // Optional. If empty, Telegram uses base_url . '/export_csv.php'.
    'public_csv_url' => '',
    'sheet_url' => '',
    'telegram_config_path' => dirname(__DIR__, 2) . '/private/telegram_settings.json',

    // Optional Telegram settings.
    'telegram_bot_token' => '',
    'telegram_chat_id'   => '',
];
