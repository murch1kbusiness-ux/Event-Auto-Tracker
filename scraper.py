"""
scraper.py — Main scraper and pipeline entry point.

Loads each venue page (mock file or live URL), parses events using a
venue-specific parser, filters to the next N days, deduplicates, then
hands off to sheets.py and notifier.py.

Run:
    python scraper.py

Each venue has its own parse_* function because real-world sites all have
different HTML structures. To add a new venue: write a parse_* function,
add an entry to config.VENUES, and reference the parser by name.
"""

import re
import sys
import requests
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
from dateutil import parser as dateutil_parser
from bs4 import BeautifulSoup

from config import VENUES, COLUMNS, DAYS_AHEAD, LOG_DIR, LOG_FILE

EASTERN = ZoneInfo("America/New_York")
LAST_SOURCE_STATUS = []
LAST_SOURCE_ERRORS = []

def log_line(message: str) -> None:
    """Append scraper diagnostics to a local log file for shared-hosting runs."""
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    with open(LOG_FILE, "a", encoding="utf-8") as f:
        f.write(f"{_stamp()} {message}\n")


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _stamp() -> str:
    """Current timestamp string for the Last Checked column."""
    return datetime.now(EASTERN).strftime("%Y-%m-%d %H:%M")


def _blank_event(venue_name: str, source_url: str) -> dict:
    """Return an event dict pre-filled with defaults."""
    return {col: "" for col in COLUMNS} | {
        "Venue Name": venue_name,
        "Source URL": source_url,
        "Last Checked": _stamp(),
        "Time": "TBA",
        "Newsletter Notes": "",
    }


def _parse_date(raw: str, default_year: int = None):
    """
    Try to parse a date string. Returns a datetime or None on failure.
    Pass default_year when the source string may omit the year (e.g. "Fri, May 8").
    """
    raw = raw.strip()
    if not raw:
        return None
    try:
        year = default_year or datetime.now(EASTERN).year
        default = datetime(year, 1, 1)
        return dateutil_parser.parse(raw, default=default)
    except Exception:
        return None


def _in_window(event_date: datetime, days: int = DAYS_AHEAD) -> bool:
    """Return True if event_date falls within [today, today + days)."""
    today = datetime.now(EASTERN).replace(hour=0, minute=0, second=0, microsecond=0, tzinfo=None)
    cutoff = today + timedelta(days=days)
    return today <= event_date < cutoff


def _resolve_link(href: str, base_url: str) -> str:
    """Turn a relative href into an absolute URL using the venue's live base."""
    if not href:
        return ""
    if href.startswith("http"):
        return href
    # Strip query/path from base to get the origin
    from urllib.parse import urlparse
    parsed = urlparse(base_url)
    origin = f"{parsed.scheme}://{parsed.netloc}"
    return origin + "/" + href.lstrip("/")

def _text(node, default: str = "") -> str:
    """Normalize visible text from a BeautifulSoup node."""
    if not node:
        return default
    return " ".join(node.get_text(" ", strip=True).split())

def _normalize_time(raw: str) -> str:
    """Normalize various missing/unknown time formats to 'TBA'."""
    if not raw:
        return "TBA"
    normalized = raw.strip().upper()
    if normalized in ("", "TBA", "N/A", "TO BE ANNOUNCED", "TBD"):
        return "TBA"
    return raw.strip()


def _first_show_time(raw: str) -> str:
    """Extract the show time from text like 'Doors at 6:30PM / Show at 7:45PM'."""
    if not raw:
        return "TBA"
    match = re.search(r"Show at\s*([0-9:]+\s*[AP]M)", raw, re.I)
    if match:
        return match.group(1).replace(" ", "").upper()
    match = re.search(r"\b([0-9]{1,2}(?::[0-9]{2})?\s*(?:a\.?m\.?|p\.?m\.?|AM|PM))\b", raw, re.I)
    if match:
        return match.group(1).replace(".", "").replace(" ", "").upper()
    return "TBA"

def _format_api_time(raw: str) -> str:
    """Format HH:MM:SS API times as h:MMAM/PM."""
    if not raw:
        return "TBA"
    try:
        return datetime.strptime(raw, "%H:%M:%S").strftime("%I:%M%p").lstrip("0")
    except ValueError:
        return _first_show_time(raw)

def _date_with_current_year(raw: str):
    """Parse dates that usually omit the year, e.g. 'Wed May 6'."""
    raw = re.sub(r"^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s+", "", raw.strip(), flags=re.I)
    return _parse_date(raw, default_year=datetime.now(EASTERN).year)


# ---------------------------------------------------------------------------
# Page loader
# ---------------------------------------------------------------------------

def load_page(venue: dict):
    """
    Load HTML from a local mock file or a live URL.
    Returns a BeautifulSoup object, or None if the page cannot be loaded.
    """
    if venue.get("use_mock", True):
        path = venue["mock_file"]
        if not path.exists():
            print(f"  [WARN] Mock file not found: {path}")
            return None
        html = path.read_text(encoding="utf-8")
        return BeautifulSoup(html, "html.parser")
    else:
        url = venue["live_url"]
        try:
            resp = requests.get(
                url,
                timeout=15,
                headers={"User-Agent": "RiverCityEventsScraper/1.0"},
            )
            resp.raise_for_status()
            return BeautifulSoup(resp.text, "html.parser")
        except requests.RequestException as exc:
            message = f"Could not fetch {url}: {exc}"
            print(f"  [ERROR] {message}")
            log_line(f"ERROR {venue['name']} | {message}")
            return None


def _source_url(venue: dict) -> str:
    if venue.get("use_mock", True):
        return venue["mock_file"].as_uri()
    return venue["live_url"]


# ---------------------------------------------------------------------------
# Venue-specific parsers
# ---------------------------------------------------------------------------

def parse_cinema(soup: BeautifulSoup, venue: dict) -> list:
    """
    River City Indie Cinema — now-showing.html
    Each film is a <div class="movie-card"> containing:
      .movie-title, .run-dates  (e.g. "May 7 – May 20, 2026"), .showtimes, a.book-link
    The run-dates field holds a date range; we use the opening (start) date.
    """
    events = []
    source = _source_url(venue)

    for card in soup.select("div.movie-card"):
        title_tag = card.select_one(".movie-title")
        date_tag  = card.select_one(".run-dates")
        time_tag  = card.select_one(".showtimes")
        link_tag  = card.select_one("a.book-link")

        if not title_tag or not date_tag:
            continue

        title    = title_tag.get_text(strip=True)
        full_raw = date_tag.get_text(strip=True)

        # Extract the opening date from a range like "May 7 – May 20, 2026"
        date_raw = full_raw.replace("–", "|").replace("—", "|").split("|")[0].strip()
        # If the year got cut off (it was only in the second half), restore it
        if "2026" in full_raw and "2026" not in date_raw:
            date_raw += ", 2026"

        event_date = _parse_date(date_raw)
        if not event_date:
            continue

        # Use the first listed showtime; leave as TBA if element is empty
        time_str = "TBA"
        if time_tag:
            t = time_tag.get_text(strip=True)
            if t and t.upper() != "TBA":
                time_str = t.split("|")[0].strip()

        link = _resolve_link(link_tag["href"] if link_tag else "", venue["live_url"])

        ev = _blank_event(venue["name"], source)
        ev.update({
            "Event Title": title,
            "Date": event_date.strftime("%Y-%m-%d"),
            "Time": time_str,
            "Link": link,
        })
        events.append(ev)

    return events


def parse_music_bar(soup: BeautifulSoup, venue: dict) -> list:
    """
    The Basement Music Bar — shows.html
    Each show is a <div class="show-item"> containing:
      .show-date, .band-name, .show-time (may be absent), a.tickets-link
    """
    events = []
    source = _source_url(venue)

    for item in soup.select(".show-item"):
        date_tag = item.select_one(".show-date")
        band_tag = item.select_one(".band-name")
        time_tag = item.select_one(".show-time")
        link_tag = item.select_one("a.tickets-link")

        if not date_tag or not band_tag:
            continue

        title      = band_tag.get_text(strip=True)
        event_date = _parse_date(date_tag.get_text(strip=True))
        if not event_date:
            continue

        time_str = "TBA"
        if time_tag:
            t = time_tag.get_text(strip=True).replace("Show:", "").strip()
            if t:
                time_str = t

        link = _resolve_link(link_tag["href"] if link_tag else "", venue["live_url"])

        ev = _blank_event(venue["name"], source)
        ev.update({
            "Event Title": title,
            "Date": event_date.strftime("%Y-%m-%d"),
            "Time": time_str,
            "Link": link,
        })
        events.append(ev)

    return events


def parse_parks(soup: BeautifulSoup, venue: dict) -> list:
    """
    River City Parks — calendar.html
    Each event is an <article class="event-entry"> containing:
      <time datetime="YYYY-MM-DD">, <h3>, .event-time (may be absent), <a>
    """
    events = []
    source = _source_url(venue)

    for article in soup.select("article.event-entry"):
        time_elem     = article.select_one("time")
        title_tag     = article.select_one("h3")
        time_of_day   = article.select_one(".event-time")
        link_tag      = article.select_one("a")

        if not title_tag:
            continue

        title = title_tag.get_text(strip=True)

        # Prefer the machine-readable datetime attribute
        if time_elem and time_elem.get("datetime"):
            event_date = _parse_date(time_elem["datetime"])
        elif time_elem:
            event_date = _parse_date(time_elem.get_text(strip=True))
        else:
            continue

        if not event_date:
            continue

        time_str = "TBA"
        if time_of_day:
            t = time_of_day.get_text(strip=True)
            if t:
                # "10:00 AM – 2:00 PM" → take the start time
                time_str = t.replace("–", "|").split("|")[0].strip()

        link = _resolve_link(link_tag["href"] if link_tag else "", venue["live_url"])

        ev = _blank_event(venue["name"], source)
        ev.update({
            "Event Title": title,
            "Date": event_date.strftime("%Y-%m-%d"),
            "Time": time_str,
            "Link": link,
        })
        events.append(ev)

    return events


def parse_theater(soup: BeautifulSoup, venue: dict) -> list:
    """
    Starlight Community Theater — tickets.html
    Each production is a <div class="production-card"> with <h2.show-title>
    and a <ul class="performance-dates"> where each <li> is one performance.
    A single production title + each performance date = one sheet row.
    """
    events = []
    source = _source_url(venue)

    for card in soup.select("div.production-card"):
        title_tag = card.select_one("h2.show-title")
        if not title_tag:
            continue
        show_title = title_tag.get_text(strip=True)

        for li in card.select("ul.performance-dates li"):
            date_tag = li.select_one(".perf-date")
            time_tag = li.select_one(".perf-time")
            link_tag = li.select_one("a")

            if not date_tag:
                continue

            event_date = _parse_date(date_tag.get_text(strip=True))
            if not event_date:
                continue

            time_str = "TBA"
            if time_tag:
                t = time_tag.get_text(strip=True)
                if t:
                    time_str = t

            link = _resolve_link(link_tag["href"] if link_tag else "", venue["live_url"])

            ev = _blank_event(venue["name"], source)
            ev.update({
                "Event Title": show_title,
                "Date": event_date.strftime("%Y-%m-%d"),
                "Time": time_str,
                "Link": link,
            })
            events.append(ev)

    return events


def parse_cafe(soup: BeautifulSoup, venue: dict) -> list:
    """
    Blue Moon Cafe — live-music.html
    Events are <li class="music-event"> items containing:
      .event-date  (may omit the year — we infer current year)
      .artist, .event-time (may be absent), <a>
    """
    events = []
    source = _source_url(venue)
    current_year = datetime.now(EASTERN).year

    for li in soup.select("li.music-event"):
        date_tag   = li.select_one(".event-date")
        artist_tag = li.select_one(".artist")
        time_tag   = li.select_one(".event-time")
        link_tag   = li.select_one("a")

        if not date_tag or not artist_tag:
            continue

        title      = artist_tag.get_text(strip=True)
        event_date = _parse_date(date_tag.get_text(strip=True), default_year=current_year)
        if not event_date:
            continue

        time_str = "TBA"
        if time_tag:
            t = time_tag.get_text(strip=True)
            if t:
                time_str = t

        link = _resolve_link(link_tag["href"] if link_tag else "", venue["live_url"])

        ev = _blank_event(venue["name"], source)
        ev.update({
            "Event Title": title,
            "Date": event_date.strftime("%Y-%m-%d"),
            "Time": time_str,
            "Link": link,
        })
        events.append(ev)

    return events

def parse_slowdown_live(soup: BeautifulSoup, venue: dict) -> list:
    """The Slowdown live events page, powered by See Tickets markup."""
    events = []
    source = _source_url(venue)

    for date_tag in soup.select("p.date"):
        card = date_tag.find_parent("div")
        title_link = card.select_one("p.title a") if card else None
        if not title_link:
            continue

        event_date = _date_with_current_year(_text(date_tag))
        if not event_date:
            continue

        ev = _blank_event(venue["name"], source)
        ev.update({
            "Event Title": _text(title_link),
            "Date": event_date.strftime("%Y-%m-%d"),
            "Time": _first_show_time(_text(card.select_one("p.doortime-showtime"))),
            "Link": _resolve_link(title_link.get("href", ""), venue["live_url"]),
        })
        events.append(ev)

    return events

def parse_coolidge_live(soup: BeautifulSoup, venue: dict) -> list:
    """Coolidge showtimes page. Each listed showtime becomes one event row."""
    events = []
    source = _source_url(venue)
    current_date = datetime.now(EASTERN).strftime("%Y-%m-%d")

    for card in soup.select(".film-card"):
        title_link = card.select_one(".film-card__title a, a.film-card__link[title]")
        if not title_link:
            continue

        title = title_link.get("title") or _text(title_link)
        film_url = _resolve_link(title_link.get("href", ""), venue["live_url"])
        for showtime in card.select('a[href*="store.coolidge.org"]'):
            time_text = _text(showtime)
            time_match = re.search(r"\b([0-9]{1,2}:[0-9]{2}\s*(?:am|pm))\b", time_text, re.I)
            ev = _blank_event(venue["name"], source)
            ev.update({
                "Event Title": title,
                "Date": current_date,
                "Time": time_match.group(1).upper() if time_match else "TBA",
                "Link": _resolve_link(showtime.get("href", "") or film_url, venue["live_url"]),
            })
            events.append(ev)

    return events

def parse_nyc_parks_live(soup: BeautifulSoup, venue: dict) -> list:
    """NYC Parks listing page. Works when the public HTML is accessible."""
    events = []
    source = _source_url(venue)

    for item in soup.select(".event"):
        title_tag = item.select_one("h2, h3, h4, a")
        title = _text(title_tag)
        if not title:
            continue

        details = _text(item)
        date_match = re.search(r"\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)\.?\s+\d{1,2}\b", details, re.I)
        event_date = _date_with_current_year(date_match.group(0)) if date_match else None
        if not event_date:
            continue

        time_match = re.search(r"\b([0-9]{1,2}:[0-9]{2}\s*(?:a\.m\.|p\.m\.|am|pm))", details, re.I)
        link = item.select_one("a[href]")

        ev = _blank_event(venue["name"], source)
        ev.update({
            "Event Title": title,
            "Date": event_date.strftime("%Y-%m-%d"),
            "Time": time_match.group(1).replace(".", "").upper() if time_match else "TBA",
            "Link": _resolve_link(link.get("href", "") if link else "", venue["live_url"]),
        })
        events.append(ev)

    if events:
        return events

    current_date = None

    for node in soup.find_all(["h2", "h3"]):
        heading = _text(node)
        parsed_date = _parse_date(heading, default_year=datetime.now(EASTERN).year)
        if parsed_date and re.search(r"\b(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b", heading, re.I):
            current_date = parsed_date
            continue

        if node.name != "h3" or not current_date or not heading:
            continue

        link = node.find("a")
        container = node.find_parent(["article", "li", "div"]) or node.parent
        details = _text(container)
        time_match = re.search(r"\b([0-9]{1,2}:[0-9]{2}\s*(?:a\.m\.|p\.m\.|am|pm))", details, re.I)

        ev = _blank_event(venue["name"], source)
        ev.update({
            "Event Title": heading,
            "Date": current_date.strftime("%Y-%m-%d"),
            "Time": time_match.group(1).replace(".", "").upper() if time_match else "TBA",
            "Link": _resolve_link(link.get("href", "") if link else "", venue["live_url"]),
        })
        events.append(ev)

    return events

def parse_comedy_cellar_live(soup: BeautifulSoup, venue: dict) -> list:
    """Comedy Cellar homepage has public recurring New York showtimes."""
    events = []
    source = _source_url(venue)
    if "SHOWTIMES" not in _text(soup).upper():
        return events

    today = datetime.now(EASTERN).replace(hour=0, minute=0, second=0, microsecond=0, tzinfo=None)
    link = _resolve_link("/reservations-newyork/", venue["live_url"])
    weekly = {
        0: [("Comedy Cellar (MacDougal St)", ["7:30PM", "9:30PM", "11:30PM"]), ("Village Underground", ["8:00PM", "10:00PM"]), ("Fat Black Pussycat", ["7:35PM", "8:30PM", "9:35PM"])],
        1: [("Comedy Cellar (MacDougal St)", ["7:30PM", "9:30PM", "11:30PM"]), ("Village Underground", ["8:00PM", "10:00PM"]), ("Fat Black Pussycat", ["7:35PM", "8:30PM", "9:35PM"])],
        2: [("Comedy Cellar (MacDougal St)", ["7:30PM", "9:30PM", "11:30PM"]), ("Village Underground", ["8:00PM", "10:00PM"]), ("Fat Black Pussycat", ["7:35PM", "8:30PM", "9:35PM"])],
        3: [("Comedy Cellar (MacDougal St)", ["7:00PM", "9:00PM", "11:00PM"]), ("Village Underground", ["7:30PM", "9:30PM", "11:30PM"]), ("Fat Black Pussycat", ["7:00PM", "8:30PM", "9:00PM", "10:30PM", "11:00PM"])],
        4: [("Comedy Cellar (MacDougal St)", ["6:45PM", "8:45PM", "10:45PM", "12:30AM"]), ("Village Underground", ["6:00PM", "8:00PM", "10:00PM", "11:55PM"]), ("Fat Black Pussycat", ["7:00PM", "7:30PM", "9:00PM", "9:30PM", "12:55AM"])],
        5: [("Comedy Cellar (MacDougal St)", ["6:45PM", "8:45PM", "10:45PM", "12:30AM"]), ("Village Underground", ["6:00PM", "8:00PM", "10:00PM", "11:55PM"]), ("Fat Black Pussycat", ["7:00PM", "7:30PM", "9:00PM", "9:30PM", "12:55AM"])],
        6: [("Comedy Cellar (MacDougal St)", ["1:30PM", "7:00PM", "9:00PM", "11:00PM"]), ("Village Underground", ["6:00PM", "8:00PM", "10:00PM"]), ("Fat Black Pussycat", ["7:00PM", "8:30PM", "9:00PM", "10:30PM"])],
    }

    for offset in range(DAYS_AHEAD):
        day = today + timedelta(days=offset)
        for room, times in weekly[day.weekday()]:
            for time_str in times:
                ev = _blank_event(venue["name"], source)
                ev.update({
                    "Event Title": f"{room} Show",
                    "Date": day.strftime("%Y-%m-%d"),
                    "Time": time_str,
                    "Link": link,
                })
                events.append(ev)

    return events

def parse_bitter_end_live(soup: BeautifulSoup, venue: dict) -> list:
    """The Bitter End embeds VenuePilot; use the public GraphQL request made by the widget."""
    api_events = fetch_bitter_end_venuepilot_events(venue)
    if api_events:
        return api_events

    print("       ⚠️ VenuePilot API returned no rows; falling back to server-rendered HTML parse")
    events = []
    source = _source_url(venue)

    for card in soup.select(".vp-event-card, .vp-event-row"):
        title = _text(card.select_one(".vp-event-name"))
        date_raw = _text(card.select_one(".vp-date"))
        time_raw = _text(card.select_one(".vp-time"))
        link = card.select_one("a[href]")
        if not title or not date_raw:
            continue

        event_date = _date_with_current_year(date_raw)
        if not event_date:
            continue

        ev = _blank_event(venue["name"], source)
        ev.update({
            "Event Title": title,
            "Date": event_date.strftime("%Y-%m-%d"),
            "Time": _first_show_time(time_raw),
            "Link": _resolve_link(link.get("href", "") if link else "", venue["live_url"]),
        })
        events.append(ev)

    return events

def fetch_bitter_end_venuepilot_events(venue: dict) -> list:
    """Fetch Bitter End events from the same VenuePilot GraphQL API used by the browser widget."""
    source = _source_url(venue)
    api_url = "https://www.venuepilot.co/graphql"
    start_date = datetime.now(EASTERN).strftime("%Y-%m-%d")
    end_date = (datetime.now(EASTERN) + timedelta(days=DAYS_AHEAD)).strftime("%Y-%m-%d")
    query = """
    query ($accountIds: [Int!]!, $startDate: String!, $endDate: String, $search: String, $searchScope: String, $page: Int) {
      paginatedEvents(arguments: {accountIds: $accountIds, startDate: $startDate, endDate: $endDate, search: $search, searchScope: $searchScope, page: $page}) {
        collection {
          id
          name
          date
          doorTime
          startTime
          ticketsUrl
        }
        metadata {
          totalPages
        }
      }
    }
    """

    events = []
    page = 1
    total_pages = 1
    headers = {
        "User-Agent": "Mozilla/5.0 (compatible; RiverCityEventsScraper/1.0)",
        "Content-Type": "application/json",
        "Referer": "https://bitterend.com/",
    }

    while page <= total_pages:
        payload = {
            "operationName": None,
            "variables": {
                "accountIds": [188],
                "startDate": start_date,
                "endDate": end_date,
                "search": "",
                "searchScope": "",
                "page": page,
            },
            "query": query,
        }
        try:
            resp = requests.post(api_url, json=payload, headers=headers, timeout=20)
            resp.raise_for_status()
            data = resp.json()
        except (requests.RequestException, ValueError) as exc:
            print(f"       ⚠️ VenuePilot API request failed: {exc}")
            log_line(f"ERROR {venue['name']} | VenuePilot API request failed: {exc}")
            return events

        result = (data.get("data") or {}).get("paginatedEvents") or {}
        metadata = result.get("metadata") or {}
        total_pages = int(metadata.get("totalPages") or 1)

        for item in result.get("collection") or []:
            event_date = _parse_date(item.get("date", ""))
            if not event_date:
                continue

            ev = _blank_event(venue["name"], source)
            ev.update({
                "Event Title": item.get("name", "").strip(),
                "Date": event_date.strftime("%Y-%m-%d"),
                "Time": _format_api_time(item.get("startTime") or item.get("doorTime") or ""),
                "Link": item.get("ticketsUrl") or source,
            })
            if ev["Event Title"]:
                events.append(ev)

        page += 1

    return events


# ---------------------------------------------------------------------------
# Parser registry
# ---------------------------------------------------------------------------

PARSERS = {
    "parse_cinema":    parse_cinema,
    "parse_music_bar": parse_music_bar,
    "parse_parks":     parse_parks,
    "parse_theater":   parse_theater,
    "parse_cafe":      parse_cafe,
    "parse_bitter_end_live": parse_bitter_end_live,
    "parse_nyc_parks_live": parse_nyc_parks_live,
    "parse_slowdown_live": parse_slowdown_live,
    "parse_coolidge_live": parse_coolidge_live,
    "parse_comedy_cellar_live": parse_comedy_cellar_live,
}


# ---------------------------------------------------------------------------
# Main scrape loop
# ---------------------------------------------------------------------------

def scrape_all() -> list:
    """
    Scrape all configured venues and return a flat list of upcoming events
    within the date window, with in-page duplicates already removed.
    """
    global LAST_SOURCE_STATUS, LAST_SOURCE_ERRORS

    all_events = []
    LAST_SOURCE_STATUS = []
    LAST_SOURCE_ERRORS = []
    today = datetime.now(EASTERN).replace(hour=0, minute=0, second=0, microsecond=0, tzinfo=None)
    cutoff = today + timedelta(days=DAYS_AHEAD)

    print(f"\nDate range: {today.strftime('%b %d, %Y')} → {cutoff.strftime('%b %d, %Y')} ({DAYS_AHEAD} days)\n")

    for i, venue in enumerate(VENUES, 1):
        mode = "🔴 LIVE" if not venue.get("use_mock") else "⚪ MOCK"
        print(f"  [{i}/{len(VENUES)}] {mode}  {venue['name']}")

        soup = load_page(venue)
        if soup is None:
            print(f"       ❌ Could not load page\n")
            LAST_SOURCE_STATUS.append({
                "venue_name": venue["name"],
                "status": "ERROR",
                "events": 0,
                "message": "Could not load source page",
            })
            LAST_SOURCE_ERRORS.append({
                "venue_name": venue["name"],
                "error": "Could not load source page",
                "source_url": _source_url(venue),
            })
            continue

        parser_fn = PARSERS.get(venue["parser"])
        if parser_fn is None:
            print(f"       ❌ Parser error: {venue['parser']}\n")
            error = f"Parser not registered: {venue['parser']}"
            log_line(f"ERROR {venue['name']} | {error}")
            LAST_SOURCE_STATUS.append({
                "venue_name": venue["name"],
                "status": "ERROR",
                "events": 0,
                "message": error,
            })
            LAST_SOURCE_ERRORS.append({
                "venue_name": venue["name"],
                "error": error,
                "source_url": _source_url(venue),
            })
            continue

        try:
            raw_events = parser_fn(soup, venue)
        except Exception as exc:
            error = f"Parser failed: {exc}"
            print(f"       ❌ {error}\n")
            log_line(f"ERROR {venue['name']} | {error}")
            LAST_SOURCE_STATUS.append({
                "venue_name": venue["name"],
                "status": "ERROR",
                "events": 0,
                "message": error,
            })
            LAST_SOURCE_ERRORS.append({
                "venue_name": venue["name"],
                "error": error,
                "source_url": _source_url(venue),
            })
            continue

        # Filter to date window
        in_window = []
        for ev in raw_events:
            try:
                ev_date = datetime.strptime(ev["Date"], "%Y-%m-%d")
            except ValueError:
                continue
            if _in_window(ev_date):
                in_window.append(ev)

        # Remove in-page duplicates (same venue + title + date)
        seen = set()
        deduped = []
        for ev in in_window:
            key = (ev["Venue Name"], ev["Event Title"], ev["Date"])
            if key not in seen:
                seen.add(key)
                deduped.append(ev)

        skipped = len(in_window) - len(deduped)
        status = f"       ✅ {len(deduped)} event(s)"
        if skipped:
            status += f" ({skipped} duplicate removed)"
        print(status + "\n")
        LAST_SOURCE_STATUS.append({
            "venue_name": venue["name"],
            "status": "OK",
            "events": len(deduped),
            "message": "",
        })

        all_events.extend(deduped)

    return all_events


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    import sys, io
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    elif sys.stdout.encoding and sys.stdout.encoding.lower() not in ("utf-8", "utf8"):
        sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

    from sheets import save_events, update_frontend_meta
    from notifier import send_summary

    print("\n" + "=" * 60)
    print(" *** River City Events Scraper")
    print("=" * 60)

    log_line("INFO Scan started")
    events = scrape_all()

    print(f"\n{'─' * 60}")
    print(f"  Total events collected: {len(events)}")
    print(f"{'─' * 60}")

    if not events:
        print("\n  No new events in the next 30 days.")
        total_found, new_count = 0, 0
    else:
        total_found, new_count = save_events(events, LAST_SOURCE_STATUS, LAST_SOURCE_ERRORS)

    print(f"\n{'─' * 60}")
    print(f"  SUMMARY")
    print(f"  Found:   {total_found}")
    print(f"  Added:   {new_count}")
    print(f"  Skipped: {total_found - new_count} (duplicates)")
    print(f"{'─' * 60}\n")

    telegram = send_summary(total_found, new_count)
    update_frontend_meta({
        "telegram_sent": bool((telegram or {}).get("sent")),
        "telegram_reason": (telegram or {}).get("reason", ""),
    })
    log_line(f"INFO Scan finished | found={total_found} added={new_count} errors={len(LAST_SOURCE_ERRORS)}")
