<?php
/**
 * export_csv.php
 *
 * Sends data/events.csv as a file download.
 * Called by the "Download CSV" button or a direct link.
 */

$csv_path = __DIR__ . '/data/events.csv';

if (!file_exists($csv_path) || filesize($csv_path) === 0) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "No events data yet. Run a scan first by visiting the dashboard.";
    exit;
}

$filename = 'river_city_events_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($csv_path));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($csv_path);
