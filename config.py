"""
config.py — Central configuration loaded from .env

All tuneable settings live here so the other modules stay clean.
"""

import os
import json
from pathlib import Path
from dotenv import load_dotenv

load_dotenv()

BASE_DIR = Path(__file__).parent

# ---------------------------------------------------------------------------
# Google Sheets
# ---------------------------------------------------------------------------
GOOGLE_CREDENTIALS_FILE = os.getenv("GOOGLE_CREDENTIALS_FILE", "credentials.json")
SPREADSHEET_ID = os.getenv("SPREADSHEET_ID", "")
SHEET_NAME = os.getenv("SHEET_NAME", "Events")
SHEET_URL = os.getenv("SHEET_URL", "")

# ---------------------------------------------------------------------------
# Telegram
# ---------------------------------------------------------------------------
TELEGRAM_CONFIG_PATH = Path(os.getenv("TELEGRAM_CONFIG_PATH", BASE_DIR / "private" / "telegram_settings.json"))

def _load_telegram_settings() -> dict:
    if not TELEGRAM_CONFIG_PATH.exists():
        return {}
    try:
        with open(TELEGRAM_CONFIG_PATH, encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}

_telegram_settings = _load_telegram_settings()
TELEGRAM_BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN", "") or _telegram_settings.get("telegram_bot_token", "")
TELEGRAM_CHAT_ID = os.getenv("TELEGRAM_CHAT_ID", "") or _telegram_settings.get("telegram_chat_id", "")

# ---------------------------------------------------------------------------
# Scraper behaviour
# ---------------------------------------------------------------------------
# Set USE_MOCK=false in .env to fetch live_url instead of the local HTML file
USE_MOCK = os.getenv("USE_MOCK", "false").lower() == "true"
# How many days ahead to collect events (Eastern Time context — see README)
DAYS_AHEAD = int(os.getenv("DAYS_AHEAD", "30"))

# ---------------------------------------------------------------------------
# Output
# ---------------------------------------------------------------------------
OUTPUT_CSV = BASE_DIR / "output" / "events.csv"
LOG_DIR = BASE_DIR / "logs"
LOG_FILE = LOG_DIR / "scraper.log"

# Column order — position 7 (Newsletter Notes) must never be overwritten by the scraper
COLUMNS = [
    "Venue Name",
    "Event Title",
    "Date",
    "Time",
    "Link",
    "Source URL",
    "Last Checked",
    "Newsletter Notes",
]

# ---------------------------------------------------------------------------
# Venue definitions
#
# To switch a single venue from mock to live, change its "use_mock" value to
# False, or set USE_MOCK=false to switch all at once.
# ---------------------------------------------------------------------------
VENUES = [
    {
        "name": "The Bitter End",
        "mock_file": BASE_DIR / "mock_site" / "bitter-end.html",
        "live_url": "https://bitterend.com/",
        "parser": "parse_bitter_end_live" if not USE_MOCK else "parse_music_bar",
        "use_mock": USE_MOCK,
    },
    {
        "name": "City Parks Events",
        "mock_file": BASE_DIR / "mock_site" / "city-parks.html",
        "live_url": "https://www.nycgovparks.org/events/ajax/aggregate/common",
        "parser": "parse_nyc_parks_live" if not USE_MOCK else "parse_parks",
        "use_mock": USE_MOCK,
    },
    {
        "name": "The Slowdown",
        "mock_file": BASE_DIR / "mock_site" / "slowdown.html",
        "live_url": "https://theslowdown.com/events/",
        "parser": "parse_slowdown_live" if not USE_MOCK else "parse_music_bar",
        "use_mock": USE_MOCK,
    },
    {
        "name": "Coolidge Corner Theatre",
        "mock_file": BASE_DIR / "mock_site" / "coolidge.html",
        "live_url": "https://coolidge.org/showtimes",
        "parser": "parse_coolidge_live" if not USE_MOCK else "parse_parks",
        "use_mock": USE_MOCK,
    },
    {
        "name": "Comedy Cellar",
        "mock_file": BASE_DIR / "mock_site" / "comedy-cellar.html",
        "live_url": "https://www.comedycellar.com/",
        "parser": "parse_comedy_cellar_live" if not USE_MOCK else "parse_music_bar",
        "use_mock": USE_MOCK,
    },
]
