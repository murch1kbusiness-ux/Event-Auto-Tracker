"""
sheets.py - Write events to Google Sheets or a CSV fallback.

Google Sheets mode: active when SPREADSHEET_ID is set and credentials.json exists.
CSV fallback mode:  writes to output/events.csv - no credentials required.

Duplicate key (shared by both modes): Venue Name + Event Title + Date
Newsletter Notes is never overwritten - existing values are always preserved.
"""

import csv
import json
import sys
from pathlib import Path

from config import (
    BASE_DIR,
    GOOGLE_CREDENTIALS_FILE,
    SPREADSHEET_ID,
    SHEET_NAME,
    SHEET_URL,
    COLUMNS,
    OUTPUT_CSV,
)

FRONTEND_DATA_DIR = BASE_DIR / "river-city-events-portal" / "data"
FRONTEND_CSV = FRONTEND_DATA_DIR / "events.csv"
FRONTEND_JSON = FRONTEND_DATA_DIR / "events.json"
FRONTEND_META = FRONTEND_DATA_DIR / "scan_meta.json"

FRONTEND_KEYS = {
    "Venue Name": "venue_name",
    "Event Title": "event_title",
    "Date": "date",
    "Time": "time",
    "Link": "link",
    "Source URL": "source_url",
    "Last Checked": "last_checked",
    "Newsletter Notes": "newsletter_notes",
}


def _normalize_date(val) -> str:
    """Normalize any date representation to YYYY-MM-DD string for dedup key."""
    if isinstance(val, (int, float)):
        # Google Sheets date serial: days since 1899-12-30
        from datetime import date, timedelta
        try:
            return (date(1899, 12, 30) + timedelta(days=int(val))).strftime("%Y-%m-%d")
        except Exception:
            return str(val)
    s = str(val).strip()
    # Already ISO format
    if len(s) == 10 and s[4] == "-" and s[7] == "-":
        return s
    # Try parsing common locale formats like M/D/YYYY or D/M/YYYY
    from datetime import datetime
    for fmt in ("%m/%d/%Y", "%d/%m/%Y", "%Y/%m/%d", "%b %d, %Y", "%B %d, %Y"):
        try:
            return datetime.strptime(s, fmt).strftime("%Y-%m-%d")
        except ValueError:
            continue
    return s


def _dup_key_from_dict(row: dict) -> tuple:
    return (row.get("Venue Name", ""), row.get("Event Title", ""), _normalize_date(row.get("Date", "")))


def _dup_key_from_list(row: list, header: list) -> tuple:
    idx = {col: i for i, col in enumerate(header)}
    date_raw = row[idx["Date"]] if "Date" in idx else ""
    return (
        row[idx.get("Venue Name", 0)] if "Venue Name" in idx else "",
        row[idx.get("Event Title", 1)] if "Event Title" in idx else "",
        _normalize_date(date_raw),
    )


def _is_sheets_configured() -> bool:
    return bool(SPREADSHEET_ID) and Path(GOOGLE_CREDENTIALS_FILE).exists()


def _rows_to_dicts(rows: list, header: list) -> list:
    normalized = []
    for row in rows:
        padded = row + [""] * max(0, len(header) - len(row))
        normalized.append({col: padded[i] if i < len(padded) else "" for i, col in enumerate(header)})
    return normalized


def _load_csv_dicts(path: Path) -> list:
    if not path.exists() or path.stat().st_size == 0:
        return []

    with open(path, newline="", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        return [{col: row.get(col, "") for col in COLUMNS} for row in reader]


def _frontend_meta(events: list, total_found: int, new_count: int, source_status=None, source_errors=None) -> dict:
    skipped = max(0, total_found - new_count)
    tba_count = sum(1 for ev in events if str(ev.get("Time", "")).strip().upper() in ("", "TBA", "N/A", "TO BE ANNOUNCED"))
    return {
        "total_found": total_found,
        "new_count": new_count,
        "skipped_duplicates": skipped,
        "skipped": skipped,
        "venues_checked": len(source_status or []) or len({ev.get("Venue Name", "") for ev in events if ev.get("Venue Name", "")}),
        "total_stored": len(events),
        "tba_times": tba_count,
        "last_checked": max((ev.get("Last Checked", "") for ev in events), default=""),
        "sheet_url": SHEET_URL or "Google Sheet URL pending",
        "source_status": source_status or [],
        "source_errors": source_errors or [],
    }


def _write_frontend_files(events: list, meta: dict) -> None:
    FRONTEND_DATA_DIR.mkdir(parents=True, exist_ok=True)

    clean_events = [{col: ev.get(col, "") for col in COLUMNS} for ev in events]

    # Normalize Time field: convert empty/null/TBA variants to "TBA"
    for ev in clean_events:
        time_val = ev.get("Time", "").strip().upper()
        if time_val in ("", "TBA", "N/A", "TO BE ANNOUNCED", "TBD"):
            ev["Time"] = "TBA"
        else:
            ev["Time"] = ev.get("Time", "").strip()

    with open(FRONTEND_CSV, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=COLUMNS)
        writer.writeheader()
        writer.writerows(clean_events)

    frontend_events = [
        {FRONTEND_KEYS[col]: ev.get(col, "") for col in COLUMNS}
        for ev in clean_events
    ]
    with open(FRONTEND_JSON, "w", encoding="utf-8") as f:
        json.dump(frontend_events, f, ensure_ascii=False, indent=2)

    with open(FRONTEND_META, "w", encoding="utf-8") as f:
        json.dump(meta, f, ensure_ascii=False, indent=2)

    print(f"     Frontend data refreshed: {FRONTEND_JSON}")

def update_frontend_meta(fields: dict) -> None:
    FRONTEND_DATA_DIR.mkdir(parents=True, exist_ok=True)
    meta = {}
    if FRONTEND_META.exists():
        try:
            with open(FRONTEND_META, encoding="utf-8") as f:
                meta = json.load(f)
        except (json.JSONDecodeError, OSError):
            meta = {}
    meta.update(fields)
    with open(FRONTEND_META, "w", encoding="utf-8") as f:
        json.dump(meta, f, ensure_ascii=False, indent=2)


def write_to_sheets(events: list, source_status=None, source_errors=None) -> tuple:
    """
    Append new events to Google Sheets.
    Returns (total_found, new_count).
    """
    try:
        import gspread
        from google.oauth2.service_account import Credentials
    except ImportError:
        print(
            "[ERROR] gspread / google-auth not installed.\n"
            "        Run: pip install gspread google-auth"
        )
        sys.exit(1)

    scopes = [
        "https://www.googleapis.com/auth/spreadsheets",
        "https://www.googleapis.com/auth/drive",
    ]
    creds = Credentials.from_service_account_file(GOOGLE_CREDENTIALS_FILE, scopes=scopes)
    client = gspread.authorize(creds)
    spreadsheet = client.open_by_key(SPREADSHEET_ID)
    try:
        sheet = spreadsheet.worksheet(SHEET_NAME)
    except gspread.exceptions.WorksheetNotFound:
        sheet = spreadsheet.add_worksheet(title=SHEET_NAME, rows=1000, cols=len(COLUMNS))
        print(f"  Created worksheet '{SHEET_NAME}'")

    existing_values = sheet.get_all_values()

    # Detect whether the sheet already has a COLUMNS header row.
    has_header = (
        bool(existing_values)
        and existing_values[0][:len(COLUMNS)] == COLUMNS
    )

    if not existing_values:
        sheet.insert_row(COLUMNS, index=1, value_input_option="RAW")
        existing_keys = set()
        data_rows = []
        header = COLUMNS
    elif has_header:
        header = existing_values[0]
        data_rows = existing_values[1:]
        existing_keys = {_dup_key_from_list(row, header) for row in data_rows}
    else:
        # Sheet has data but no header — insert header at row 1.
        sheet.insert_row(COLUMNS, index=1, value_input_option="RAW")
        header = COLUMNS
        data_rows = existing_values  # all rows are event data
        existing_keys = {_dup_key_from_list(row, header) for row in data_rows}
        print("  Inserted missing header row")

    new_rows = []
    seen_this_run = set()
    for ev in events:
        key = _dup_key_from_dict(ev)
        if key not in existing_keys and key not in seen_this_run:
            new_rows.append([ev.get(col, "") for col in COLUMNS])
            seen_this_run.add(key)

    if new_rows:
        # RAW keeps dates as plain text "2026-05-07" so re-reads match.
        sheet.append_rows(new_rows, value_input_option="RAW")
        print(f"\n  Wrote {len(new_rows)} new event(s) to Google Sheets")
    else:
        print("\n  No new events - all already in sheet")

    existing_dicts = _rows_to_dicts(data_rows, header)
    new_dicts = [dict(zip(COLUMNS, row)) for row in new_rows]
    all_events = existing_dicts + new_dicts
    _write_frontend_files(all_events, _frontend_meta(all_events, len(events), len(new_rows), source_status, source_errors))

    return len(events), len(new_rows)


def write_to_csv(events: list, source_status=None, source_errors=None) -> tuple:
    """
    Append new events to output/events.csv.
    Returns (total_found, new_count).
    """
    OUTPUT_CSV.parent.mkdir(parents=True, exist_ok=True)

    existing_keys = set()
    file_exists = OUTPUT_CSV.exists() and OUTPUT_CSV.stat().st_size > 0

    if file_exists:
        with open(OUTPUT_CSV, newline="", encoding="utf-8") as f:
            reader = csv.reader(f)
            rows = list(reader)
        if rows:
            header = rows[0]
            for row in rows[1:]:
                existing_keys.add(_dup_key_from_list(row, header))

    new_rows = []
    seen_this_run = set()
    for ev in events:
        key = _dup_key_from_dict(ev)
        if key not in existing_keys and key not in seen_this_run:
            new_rows.append([ev.get(col, "") for col in COLUMNS])
            seen_this_run.add(key)

    if not file_exists:
        with open(OUTPUT_CSV, "w", newline="", encoding="utf-8") as f:
            writer = csv.writer(f)
            writer.writerow(COLUMNS)
            writer.writerows(new_rows)
    else:
        with open(OUTPUT_CSV, "a", newline="", encoding="utf-8") as f:
            writer = csv.writer(f)
            writer.writerows(new_rows)

    if new_rows:
        print(f"\n  Wrote {len(new_rows)} new event(s) to CSV")
        print(f"     {OUTPUT_CSV}")
    else:
        print("\n  No new events - all already in CSV")

    all_events = _load_csv_dicts(OUTPUT_CSV)
    _write_frontend_files(all_events, _frontend_meta(all_events, len(events), len(new_rows), source_status, source_errors))

    return len(events), len(new_rows)


def save_events(events: list, source_status=None, source_errors=None) -> tuple:
    """Route to Sheets or CSV based on configuration."""
    if _is_sheets_configured():
        print("\nGoogle Sheets configured")
        return write_to_sheets(events, source_status, source_errors)
    else:
        print("\nGoogle Sheets not configured - using CSV fallback")
        return write_to_csv(events, source_status, source_errors)
