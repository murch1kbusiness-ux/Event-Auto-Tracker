# River City Events Scraper to Google Sheets

A PHP + Python on-demand web tool that collects upcoming local event listings from five venue websites, writes them to Google Sheets (or a local CSV), and sends a Telegram summary after each run.

---

## About River City Happenings

River City Happenings is a real local newsletter business. The scraper collects events from five real venues:

- **The Bitter End** (NYC music venue) — https://thebitterend.com/schedule/
- **City Parks Events** (NYC Parks Department) — https://www.nycgovparks.org/events
- **The Slowdown** (Indie music venue) — https://theslowdown.com/schedule/
- **Coolidge Corner Theatre** (Events) — https://coolidge.org/events
- **Comedy Cellar** (Stand-up comedy) — https://www.comedycellar.com/line-up/

To allow for safe local testing, the scraper can run in mock mode against local HTML files in `mock_site/`, or live mode against the real websites. Set `USE_MOCK=false` in your `.env` file to scrape live websites.

---

## Business Problem

**Business:** River City Happenings — a curated local newsletter and blog covering events in a small river town.

**Pain point:** Every Tuesday, the newsletter owner manually visits five local venue websites, copy-pastes upcoming events into a Google Sheet, and uses that sheet to write her Friday newsletter. The process takes 30–45 minutes and is easy to get wrong — events get missed, times change, and duplicates slip in.

**Goal:** A script she can run every Tuesday morning that pulls upcoming events automatically, saves them to her Google Sheet, skips anything already listed, and sends a quick Telegram message when it's done so she knows it worked.

---

## What the Automation Does

1. Reads five venue pages (local mock HTML, swappable for live URLs)
2. Extracts event title, date, time, and ticket/info link from each page
3. Filters to events occurring in the **next 30 days**
4. Removes in-page duplicates before writing
5. Compares against existing rows using a dedup key — never overwrites data
6. Appends only new events to Google Sheets or `output/events.csv`
7. Preserves any manually entered **Newsletter Notes** on existing rows
8. Sends a Telegram summary message (falls back to terminal output if unconfigured)

---

## Features

- Five venue-specific HTML parsers — each site has a different structure, which is realistic for real-world scraping work
- Date range filtering: events outside the next 30 days are ignored
- Missing event times default to `TBA` automatically
- Duplicate detection across runs: existing rows are never touched
- Newsletter Notes column is always preserved — the scraper won't overwrite anything you typed manually
- Google Sheets mode with automatic header row creation on first run
- CSV fallback mode — fully functional with no API credentials required
- Telegram notification after each run (gracefully skipped if not configured)
- Mock venue pages make the project safe and self-contained for local testing

---

## How Duplicate Detection Works

The deduplication key is:

> **Venue Name + Event Title + Date**

`Time` is intentionally excluded from the key. Venue websites often post events without a confirmed time and update it later. Including time in the key would create a second row for the same event just because the venue filled in the showtime after the first scrape.

**What happens when a duplicate is found:**
- The existing row is left completely untouched
- Newsletter Notes are preserved
- No new row is appended

This logic works identically in both Google Sheets mode and CSV fallback mode. Existing rows are loaded at the start of each run, and only events with new key combinations are written.

---

## Google Sheets Output

| Column | Notes |
|---|---|
| Venue Name | Source venue |
| Event Title | Event or show name |
| Date | `YYYY-MM-DD` format |
| Time | `H:MM AM/PM` or `TBA` if not listed |
| Link | Ticket or event info URL |
| Source URL | The URL or file path that was scraped |
| Last Checked | Timestamp of this scraper run |
| Newsletter Notes | **Manually filled — never overwritten by the scraper** |

If `SPREADSHEET_ID` and `credentials.json` are present, the scraper writes directly to Google Sheets and creates the header row on the first run. Otherwise it falls back to CSV without any setup required.

---

## Telegram Notification

After each run a message is sent to the configured Telegram chat:

```
Data pulled! Found 18 events. Added 5 new events.
Google Sheet: https://docs.google.com/spreadsheets/d/...
```

If `TELEGRAM_BOT_TOKEN` or `TELEGRAM_CHAT_ID` are not set, the same message is printed to the terminal instead. The scraper never crashes on missing Telegram configuration.

The website also has a Telegram Settings panel. It saves the token and chat ID server-side only and never sends the stored token back to frontend JavaScript.

---

## CSV Fallback Mode

When Google Sheets is not configured, results are saved to:

```
output/events.csv
```

The CSV uses the exact same column order as the Google Sheet. Duplicate detection and Newsletter Notes preservation work identically. This mode requires no credentials and is the easiest way to test the scraper locally.

---

## Mock Venue Pages

Five HTML files in `mock_site/` simulate the five venue websites. Each has a different HTML structure to reflect how real sites differ from one another:

| File | Venue | Structure notes |
|---|---|---|
| `bitter-end.html` | The Bitter End | Music venue schedule |
| `city-parks.html` | City Parks Events | Government-style event entries |
| `slowdown.html` | The Slowdown | Indie venue schedule |
| `coolidge.html` | Coolidge Corner Theatre | Theatre events |
| `comedy-cellar.html` | Comedy Cellar | Comedy show lineup |

Every page includes:
- Past events (should be filtered out)
- Upcoming events within the 30-day window
- At least one event beyond the window (should be filtered out)
- At least one event with a missing time (should become `TBA`)
- At least one duplicate entry (should be caught by in-page dedup)

To switch from mock pages to live scraping, set `USE_MOCK=false` in `.env`. Verify that any live URLs point to publicly accessible pages before enabling this.

---

## Setup

### 1. Clone the repository

```bash
git clone <repo-url>
cd river-city-events-scraper
```

### 2. Create a virtual environment

```bash
python -m venv venv

# Activate — macOS / Linux
source venv/bin/activate

# Activate — Windows
venv\Scripts\activate
```

### 3. Install dependencies

```bash
pip install -r requirements.txt
```

### 4. Configure environment variables

```bash
cp .env.example .env
```

Edit `.env` with your values. For a local test run, the defaults work with no changes — `USE_MOCK=true` is the default and no credentials are required.

---

## Environment Variables

| Variable | Required | Default | Description |
|---|---|---|---|
| `USE_MOCK` | No | `true` | Use local HTML files instead of fetching live URLs |
| `DAYS_AHEAD` | No | `30` | How many days ahead to collect events |
| `GOOGLE_CREDENTIALS_FILE` | For Sheets | `credentials.json` | Path to Google service account JSON |
| `SPREADSHEET_ID` | For Sheets | — | Google Sheets spreadsheet ID (from the URL) |
| `SHEET_NAME` | For Sheets | `Events` | Worksheet tab name |
| `SHEET_URL` | No | — | Full spreadsheet URL for the Telegram message |
| `TELEGRAM_BOT_TOKEN` | For Telegram | — | Telegram bot token from @BotFather |
| `TELEGRAM_CHAT_ID` | For Telegram | — | Chat or channel ID to receive summaries |
| `TELEGRAM_CONFIG_PATH` | No | `private/telegram_settings.json` | Server-side file used when Telegram is configured from the website |

---

## Run Locally

### CSV fallback (no credentials needed)

```bash
python scraper.py
```

Results are written to `output/events.csv`. Run it a second time to verify duplicate skipping — the summary should show `new=0`.

### Google Sheets mode

1. Create a Google Cloud project and enable the **Google Sheets API** and **Google Drive API**
2. Create a **service account** and download its JSON key as `credentials.json`
3. Share the target Google Sheet with the service account's email address (Editor access)
4. Set `SPREADSHEET_ID` in `.env` (the long ID from the sheet's URL)
5. Run: `python scraper.py`

### Syntax check (no dependencies needed)

```bash
python -m py_compile scraper.py sheets.py notifier.py config.py
```

No output means all files passed.

---

## Shared Hosting / On-Demand Mode

The MVP is designed for Sarah to run manually from the website:

1. Open the site
2. Click **Run Scan**
3. PHP starts one Python scraper process
4. The scraper updates Google Sheets
5. The scraper sends one Telegram Bot API `sendMessage` HTTP request
6. The process exits

No always-running bot, polling process, queue, Node server, daemon, or background worker is required.

See `HOSTING.md` for Namecheap/shared-hosting deployment notes, timeout handling, logs, and fallback guidance if Python execution is not available.

## Optional Scheduling Note

The final MVP does not require scheduling. If Sarah later wants automation, use normal hosting cron or a platform scheduler. Suggested schedules using **Eastern Time** context (UTC-4 during EDT, UTC-5 during EST):

**Linux / macOS — cron** (runs at 6:00 AM Eastern during EDT):

```
0 10 * * 2 cd /path/to/project && /path/to/venv/bin/python scraper.py >> logs/scraper.log 2>&1
```

**GitHub Actions** (`cron: '0 10 * * 2'` = 10:00 UTC = 6:00 AM EDT):

```yaml
on:
  schedule:
    - cron: '0 10 * * 2'
```

**Windows Task Scheduler:** Create a weekly task triggered every Tuesday, pointing to `python scraper.py` in the project directory.

---

## Screenshots Checklist

See `screenshots/SCREENSHOTS.md` for the full list of screenshots to capture for the portfolio, including what to show in each one and how to trigger the right terminal output.

---

## Security Notes

- `.env` and `credentials.json` are in `.gitignore` — they will never be committed
- In mock mode (`USE_MOCK=true`), no network requests are made at all
- When fetching live URLs, the scraper sends a descriptive `User-Agent` header: `RiverCityEventsScraper/1.0`
- Google service account permissions should be scoped to the specific spreadsheet only, not the entire Drive
- The scraper does not attempt to bypass logins, CAPTCHAs, paywalls, or any access controls

---

## Tech Stack

| Tool | Purpose |
|---|---|
| Python 3.10+ | Core language |
| requests | HTTP client for live URL fetching and Telegram API calls |
| beautifulsoup4 | HTML parsing |
| python-dotenv | Load `.env` variables at runtime |
| python-dateutil | Flexible date string parsing (handles missing years, abbreviated months, etc.) |
| gspread | Google Sheets read/write via API |
| google-auth | Google service account authentication |
