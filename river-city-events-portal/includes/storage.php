<?php
/**
 * storage.php
 *
 * Reads and writes the event data files (CSV + JSON).
 *
 * Duplicate key: venue_name + event_title + date
 * Newsletter Notes are never overwritten.
 */

define('CSV_PATH',  __DIR__ . '/../data/events.csv');
define('JSON_PATH', __DIR__ . '/../data/events.json');
define('META_PATH', __DIR__ . '/../data/scan_meta.json');

// Column order — must stay consistent
$GLOBALS['CSV_HEADERS'] = ['Venue Name', 'Event Title', 'Date', 'Time', 'Link', 'Source URL', 'Last Checked', 'Newsletter Notes'];
$GLOBALS['CSV_KEYS']    = ['venue_name', 'event_title', 'date', 'time', 'link', 'source_url', 'last_checked', 'newsletter_notes'];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function dup_key(array $ev): string {
    return strtolower(
        trim($ev['venue_name'] ?? '') . '|||' .
        trim($ev['event_title'] ?? '') . '|||' .
        trim($ev['date'] ?? '')
    );
}

function ensure_data_dir(): void {
    $dir = dirname(CSV_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ---------------------------------------------------------------------------
// Read
// ---------------------------------------------------------------------------

function load_existing_events(): array {
    if (!file_exists(CSV_PATH)) {
        return [];
    }

    $events = [];
    $fh = fopen(CSV_PATH, 'r');
    if ($fh === false) return [];

    $header_row = fgetcsv($fh, 0, ',', '"', '\\');
    if ($header_row === false) { fclose($fh); return []; }

    // Build a map from CSV column position to our internal key
    $pos_map = [];
    $key_map = array_flip($GLOBALS['CSV_KEYS']);
    foreach ($header_row as $i => $h) {
        // Match CSV header to internal key via position in HEADERS array
        $header_pos = array_search($h, $GLOBALS['CSV_HEADERS']);
        if ($header_pos !== false) {
            $pos_map[$i] = $GLOBALS['CSV_KEYS'][$header_pos];
        }
    }

    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $ev = array_fill_keys($GLOBALS['CSV_KEYS'], '');
        foreach ($row as $i => $val) {
            if (isset($pos_map[$i])) {
                $ev[$pos_map[$i]] = $val;
            }
        }
        $events[] = $ev;
    }
    fclose($fh);
    return $events;
}

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------

function write_csv(array $all_events): void {
    ensure_data_dir();
    $fh = fopen(CSV_PATH, 'w');
    if ($fh === false) {
        throw new RuntimeException('Cannot open CSV for writing: ' . CSV_PATH);
    }
    fputcsv($fh, $GLOBALS['CSV_HEADERS'], ',', '"', '\\');
    foreach ($all_events as $ev) {
        $row = [];
        foreach ($GLOBALS['CSV_KEYS'] as $key) {
            $row[] = $ev[$key] ?? '';
        }
        fputcsv($fh, $row, ',', '"', '\\');
    }
    fclose($fh);
}

function write_json(array $all_events): void {
    ensure_data_dir();
    file_put_contents(
        JSON_PATH,
        json_encode(array_values($all_events), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

function write_scan_meta(array $meta): void {
    ensure_data_dir();
    file_put_contents(META_PATH, json_encode($meta, JSON_PRETTY_PRINT));
}

// ---------------------------------------------------------------------------
// Main save function
// ---------------------------------------------------------------------------

/**
 * Merges new_events with existing stored events.
 * Deduplicates by (venue_name, event_title, date).
 * Preserves newsletter_notes from existing rows.
 * Writes updated CSV + JSON.
 *
 * @return array ['total_found', 'new_count', 'skipped_duplicates', 'total_stored']
 */
function save_events(array $new_events, int $venues_checked = 0, array $source_status = []): array {
    $existing = load_existing_events();

    // Build lookup: dup_key → index in $existing
    $existing_map = [];
    foreach ($existing as $i => $ev) {
        $existing_map[dup_key($ev)] = $i;
    }

    $to_append  = [];
    $seen_new   = [];

    foreach ($new_events as $ev) {
        $key = dup_key($ev);

        // Skip in-run duplicates
        if (isset($seen_new[$key])) continue;
        $seen_new[$key] = true;

        if (!isset($existing_map[$key])) {
            $to_append[] = $ev;
        }
        // If already exists: leave the existing row untouched (preserve notes etc.)
    }

    $all_events = array_merge($existing, $to_append);

    write_csv($all_events);
    write_json($all_events);

    $meta = [
        'total_found'        => count($new_events),
        'new_count'          => count($to_append),
        'skipped_duplicates' => count($new_events) - count($to_append),
        'skipped'            => count($new_events) - count($to_append),
        'venues_checked'     => $venues_checked,
        'total_stored'       => count($all_events),
        'last_checked'       => date('Y-m-d H:i'),
    ];
    if (!empty($source_status)) {
        $meta['source_status'] = $source_status;
    }
    write_scan_meta($meta);

    return $meta;
}
