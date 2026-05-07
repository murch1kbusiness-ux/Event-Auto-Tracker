"""
notifier.py — Send a Telegram summary after each scraper run.

If TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID are not configured, the summary is
printed to the terminal instead. This module never raises on missing config or
network errors — the scraper run should always complete cleanly.
"""

import requests

from config import TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID, SHEET_URL


def send_summary(total_found: int, new_count: int) -> None:
    """
    Send or print the post-run summary.

    Args:
        total_found: Total events found within the date window.
        new_count:   Number of new rows actually written (duplicates excluded).
    """
    sheet_url = SHEET_URL or "Google Sheet URL pending"
    message = (
        f"Data pulled! Found {total_found} events. Added {new_count} new events.\n"
        f"Google Sheet: {sheet_url}"
    )

    if not TELEGRAM_BOT_TOKEN or not TELEGRAM_CHAT_ID:
        print("\n📱 Telegram not configured — printing summary:")
        print("─" * 40)
        print(message)
        print("─" * 40)
        return {"sent": False, "reason": "not_configured"}

    api_url = f"https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/sendMessage"
    try:
        resp = requests.post(
            api_url,
            json={"chat_id": TELEGRAM_CHAT_ID, "text": message},
            timeout=10,
        )
        if resp.ok:
            print(f"\n✅ Telegram summary sent")
            return {"sent": True, "reason": ""}
        else:
            print(f"\n❌ Telegram send failed ({resp.status_code})")
            return {"sent": False, "reason": f"telegram_http_{resp.status_code}"}
    except requests.RequestException as exc:
        print(f"\n⚠️  Telegram network error (summary not sent)")
        return {"sent": False, "reason": str(exc)}
