# River City Events Automation Portal

A PHP dashboard for collecting events from five real public venue websites into a searchable table, CSV, and JSON files. Sarah uses this tool to speed up her weekly newsletter planning.

## Live Source Mode

This portal can collect events from these real public websites:

- **The Bitter End** — https://thebitterend.com/schedule/ (Music Bar)
- **City Parks Events** — https://www.nycgovparks.org/events (NYC Parks)
- **The Slowdown** — https://theslowdown.com/schedule/ (Indie Venue)
- **Coolidge Corner Theatre** — https://coolidge.org/events (Film)
- **Comedy Cellar** — https://www.comedycellar.com/line-up/ (Comedy)

## Source Modes

**Live Mode** (default): Fetches events from the real public venue websites above. If a website changes its HTML layout, that particular parser may need a small update. This is the production mode.

**Demo Mode** (testing fallback): Uses local test pages bundled with the portal. Useful for safe testing if a real website becomes unavailable or changes structure.

## What It Does

- Fetches events from five real public venue websites (or demo pages for testing)
- Parses different HTML structures using `DOMDocument` and `DOMXPath`
- Displays the Source mode (Live or Demo) on the dashboard
- Shows Source status after each scan (which venues succeeded, how many events found)
- Keeps only events in the next 30 days
- Stores missing times as `TBA`
- Skips duplicates using Venue Name + Event Title + Date key
- Preserves the Newsletter Notes column so editing isn't lost
- Saves all data to `data/events.csv`, `data/events.json`, and `data/scan_meta.json`
- Shows a Telegram summary preview by default
- Sends a real Telegram message only if bot token and chat ID are added
- Continues scanning other venues if one source fails (partial success)

No Composer, npm, Python server, Node.js server, database, login, or private API is required.

## Local Run

From the project folder:

```powershell
php -S localhost:8000
```

Or with XAMPP PHP:

```powershell
C:\xampp\php\php.exe -S localhost:8000
```

Open:

```text
http://localhost:8000
```

Then:

1. Open a source page from the dashboard.
2. Click `Run Scan`.
3. Check that the table fills with events.
4. Click `Run Scan` again.
5. Confirm the second scan adds `0` new events and skips duplicates.
6. Click `Download CSV`.

The source pages generate dates relative to today using Eastern Time, so the sample events keep moving forward.

## Ethical Scraping

This portfolio project uses ethical web scraping:

- Targets public event pages only (no login required)
- Does not bypass CAPTCHAs or security measures
- Uses a polite User-Agent header
- Requests one source page per scan (no aggressive crawling)
- No private data collection
- Respects website terms of service

If a venue changes its HTML layout, the relevant parser may need a small update. This is expected and documented in the code.

## Configuration

Copy `includes/config.example.php` to `includes/config.php`:

```php
'base_url' => 'http://localhost:8000',  // or https://yourdomain.com/river-city-events
'source_mode' => 'live',  // 'live' or 'demo'
```

### source_mode

- **`live`** (default): Fetches from real public venue pages on the internet
- **`demo`**: Uses local test pages in `/venues/` (safe for testing if a real site changes layout or blocks requests)

`includes/config.php` is ignored by git. Never commit real Telegram tokens or API credentials.

## Telegram

By default, the dashboard shows a preview only.

To send a real Telegram message after a scan, add these in `includes/config.php`:

```php
'telegram_bot_token' => 'YOUR_TOKEN',
'telegram_chat_id'   => 'YOUR_CHAT_ID',
```

Message format:

```text
Data pulled! Found {total_found} events. Added {new_count} new events.
CSV: {public_csv_url}
```

If `public_csv_url` is empty, the app uses `base_url . '/export_csv.php'`.

## Deploy to Namecheap

1. Open cPanel.
2. Open File Manager.
3. Create:

```text
public_html/river-city-events/
```

4. Upload all project files into that folder.
5. Make sure `data/` is writable by PHP.
6. Copy `includes/config.example.php` to `includes/config.php`.
7. Set `base_url` to:

```php
'base_url' => 'https://yourdomain.com/river-city-events',
```

8. Open:

```text
https://yourdomain.com/river-city-events/
```

9. Click `Run Scan`.

## Cron

In Namecheap cPanel, add a cron command like:

```text
php /home/USERNAME/public_html/river-city-events/run_scan.php
```

For daily 8 AM Eastern, set the cPanel schedule to the matching server timezone.
Hosting timezone may differ from Eastern Time, and daylight saving time can shift the UTC offset.

## Project Structure

```text
river-city-events-portal/
├── index.php                          # Dashboard & UI
├── run_scan.php                       # API endpoint for scan trigger
├── export_csv.php                     # CSV download
├── assets/
│   ├── styles.css
│   └── app.js
├── data/
│   ├── events.csv                     # Stored events (CSV)
│   ├── events.json                    # Stored events (JSON)
│   └── scan_meta.json                 # Last scan metadata
├── venues/                            # Mock pages (mock mode)
│   ├── bitter-end.php
│   ├── city-parks.php
│   ├── slowdown.php
│   ├── coolidge.php
│   └── comedy-cellar.php
├── includes/
│   ├── scraper.php                    # Fetch & parse logic
│   ├── storage.php                    # CSV/JSON I/O
│   ├── notifier.php                   # Telegram messages
│   └── config.example.php
├── README.md
├── LICENSE
└── .gitignore
```

## Notes

This is a portfolio case study using public event pages at low request volume. It demonstrates PHP web scraping, DOM parsing, CSV/JSON file handling, and event deduplication logic.
