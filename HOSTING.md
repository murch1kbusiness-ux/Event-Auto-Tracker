# Shared Hosting Deployment Notes

This MVP is designed as an on-demand web tool.

Runtime flow:

1. User opens the PHP website.
2. User clicks **Run Scan**.
3. `run_scan.php` starts one Python process.
4. `scraper.py` fetches venue pages once.
5. The scraper appends new rows to Google Sheets.
6. The scraper sends one Telegram Bot API HTTP request to `sendMessage`.
7. The Python process exits.

There is no always-running Telegram bot, polling process, queue, cron worker, Node server, daemon, or background service.

## Required Hosting Support

The recommended hosting environment needs:

- PHP 8+
- Python 3.10+
- Ability for PHP to run `proc_open`
- Outbound HTTPS requests from PHP/Python
- Writable local folders: `logs/`, `output/`, `river-city-events-portal/data/`

If Namecheap shared hosting disables Python execution from PHP, use one of these options:

- Namecheap VPS
- Render / Railway / Fly.io / DigitalOcean App Platform
- Any low-cost host that allows PHP to execute Python scripts

A PHP-only fallback is possible, but not recommended for this MVP because the current live scrapers depend on Python libraries and are already tested there.

## `.env`

Create `.env` in the project root:

```env
USE_MOCK=false
DAYS_AHEAD=30

GOOGLE_CREDENTIALS_FILE=credentials.json
SPREADSHEET_ID=
SHEET_NAME=Events
SHEET_URL=

TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
TELEGRAM_CONFIG_PATH=private/telegram_settings.json

PYTHON_BIN=python3
SCAN_TIMEOUT_SECONDS=180
```

On some shared hosts, `PYTHON_BIN` may need to be an absolute path, for example:

```env
PYTHON_BIN=/usr/local/bin/python3
```

## Telegram

Telegram does not require polling or a running bot process. The scraper sends one HTTP request per scan:

```text
https://api.telegram.org/bot<TOKEN>/sendMessage
```

Message format:

```text
Data pulled! Found 226 events. Added 0 new events.
Google Sheet: https://docs.google.com/spreadsheets/d/...
```

Telegram can also be configured from the website. The token and chat ID are saved server-side only in `private/telegram_settings.json` by default. The frontend never receives or displays the saved token; it only shows configured/not configured.

## Logs

Runtime logs are written locally:

- `logs/run_scan.log` - PHP web runner command, timeout, and process output.
- `logs/scraper.log` - Python scraper scan lifecycle and venue errors.

If one venue fails, the scraper continues with the remaining venues and returns partial source status to the dashboard.
