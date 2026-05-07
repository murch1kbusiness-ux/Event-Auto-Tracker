<?php
/**
 * Fetches the public venue pages over HTTP and parses their event listings.
 */

date_default_timezone_set('America/New_York');

if (!function_exists('portal_config')) {
    function portal_config(): array {
        $path = __DIR__ . '/config.php';
        if (file_exists($path)) {
            $cfg = require $path;
            return is_array($cfg) ? $cfg : [];
        }
        return [];
    }
}

function portal_base_url(): string {
    $cfg = portal_config();
    $configured = trim($cfg['base_url'] ?? $cfg['public_demo_url'] ?? '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . ($dir === '' ? '' : $dir);
    }

    return 'http://localhost:8000';
}

function source_mode(): string {
    $cfg = portal_config();
    $mode = strtolower(trim($cfg['source_mode'] ?? 'live'));
    return $mode === 'demo' ? 'demo' : 'live';
}

function venue_sources(): array {
    $base = portal_base_url();
    $mode = source_mode();

    // Live mode: real public venue websites
    if ($mode === 'live') {
        return [
            [
                'venue_name' => 'The Bitter End',
                'url'        => 'https://thebitterend.com/schedule/',
                'parser'     => 'parse_bitter_end',
                'enabled'    => true,
            ],
            [
                'venue_name' => 'City Parks Events',
                'url'        => 'https://www.nycgovparks.org/events',
                'parser'     => 'parse_nyc_parks',
                'enabled'    => true,
            ],
            [
                'venue_name' => 'The Slowdown',
                'url'        => 'https://theslowdown.com/schedule/',
                'parser'     => 'parse_slowdown',
                'enabled'    => true,
            ],
            [
                'venue_name' => 'Coolidge Corner Theatre',
                'url'        => 'https://coolidge.org/events',
                'parser'     => 'parse_coolidge',
                'enabled'    => true,
            ],
            [
                'venue_name' => 'Comedy Cellar',
                'url'        => 'https://www.comedycellar.com/line-up/',
                'parser'     => 'parse_comedy_cellar',
                'enabled'    => true,
            ],
        ];
    }

    // Demo mode: local fallback for testing
    return [
        [
            'venue_name' => 'The Bitter End',
            'url'        => $base . '/venues/bitter-end.php',
            'parser'     => 'parse_bitter_end_mock',
            'enabled'    => true,
        ],
        [
            'venue_name' => 'City Parks Events',
            'url'        => $base . '/venues/city-parks.php',
            'parser'     => 'parse_nyc_parks_mock',
            'enabled'    => true,
        ],
        [
            'venue_name' => 'The Slowdown',
            'url'        => $base . '/venues/slowdown.php',
            'parser'     => 'parse_slowdown_mock',
            'enabled'    => true,
        ],
        [
            'venue_name' => 'Coolidge Corner Theatre',
            'url'        => $base . '/venues/coolidge.php',
            'parser'     => 'parse_coolidge_mock',
            'enabled'    => true,
        ],
        [
            'venue_name' => 'Comedy Cellar',
            'url'        => $base . '/venues/comedy-cellar.php',
            'parser'     => 'parse_comedy_cellar_mock',
            'enabled'    => true,
        ],
    ];
}

function scraper_log(string $message): void {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, 3, $dir . '/scan_errors.log');
}

function fetch_url(string $url): string {
    if (PHP_SAPI === 'cli-server' && is_same_cli_server_url($url)) {
        return render_local_venue_page($url);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'RiverCityEventsPortal/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code >= 400 || $code === 0) {
            throw new RuntimeException('HTTP fetch failed for ' . $url . ($code ? ' (status ' . $code . ')' : '') . ($err ? ': ' . $err : ''));
        }
        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 15,
            'header'  => "User-Agent: RiverCityEventsPortal/1.0\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('HTTP fetch failed for ' . $url);
    }
    return $body;
}

function is_same_cli_server_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || empty($_SERVER['HTTP_HOST'])) return false;
    $target_host = normalize_local_host($parts['host']);
    $target_port = (string)($parts['port'] ?? '80');

    $server_parts = parse_url('http://' . $_SERVER['HTTP_HOST']);
    if (!$server_parts || empty($server_parts['host'])) return false;

    $server_host = normalize_local_host($server_parts['host']);
    $server_port = (string)($server_parts['port'] ?? '80');

    return $target_host === $server_host && $target_port === $server_port;
}

function normalize_local_host(string $host): string {
    $host = strtolower(trim($host, '[]'));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true) ? 'localhost' : $host;
}

function render_local_venue_page(string $url): string {
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $root = realpath(dirname(__DIR__));
    $target = realpath($root . '/' . ltrim($path, '/'));

    $venues_root = realpath($root . '/venues') ?: $root;
    if (!$root || !$target || strpos($target, $venues_root) !== 0 || !is_file($target)) {
        throw new RuntimeException('Local source page not found for ' . $url);
    }

    ob_start();
    include $target;
    return (string) ob_get_clean();
}

function make_doc(string $html): DOMDocument {
    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $doc;
}

function blank_event(string $venue_name, string $source_url): array {
    return [
        'venue_name'       => $venue_name,
        'event_title'      => '',
        'date'             => '',
        'time'             => 'TBA',
        'link'             => '',
        'source_url'       => $source_url,
        'last_checked'     => date('Y-m-d H:i'),
        'newsletter_notes' => '',
    ];
}

function normalize_date(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    if (!preg_match('/\b\d{4}\b/', $raw)) {
        $raw .= ' ' . date('Y');
    }
    $ts = strtotime($raw);
    return $ts === false ? null : date('Y-m-d', $ts);
}

function extract_start_date(string $range_raw): string {
    $parts = preg_split('/\s*[\x{2013}\x{2014}\-]\s*/u', $range_raw, 2);
    $start = trim($parts[0] ?? $range_raw);
    if (!preg_match('/\b\d{4}\b/', $start) && preg_match('/\b(\d{4})\b/', $range_raw, $m)) {
        $start .= ', ' . $m[1];
    }
    return $start;
}

function is_in_window(string $date_str, int $days = 30): bool {
    $today = strtotime('today');
    $cutoff = strtotime("+{$days} days", $today);
    $ts = strtotime($date_str);
    return $ts !== false && $ts >= $today && $ts < $cutoff;
}

function clean_time(string $raw): string {
    $time = trim($raw);
    if ($time === '' || strtoupper($time) === 'TBA') {
        return 'TBA';
    }
    $time = preg_replace('/^\s*Show\s*:\s*/i', '', $time);
    return trim($time) !== '' ? trim($time) : 'TBA';
}

function first_range_time(string $raw): string {
    $time = clean_time($raw);
    if ($time === 'TBA') return 'TBA';
    $parts = preg_split('/\s*[\x{2013}\x{2014}\-]\s*/u', $time, 2);
    return trim($parts[0] ?? $time) ?: 'TBA';
}

function resolve_url(string $href, string $source_url): string {
    $href = trim($href);
    if ($href === '') return '';
    if (preg_match('#^https?://#i', $href)) return $href;
    if (strpos($href, '#') === 0) return $source_url . $href;

    $parts = parse_url($source_url);
    $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '');
    if (!empty($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }
    if (strpos($href, '/') === 0) return $origin . $href;

    $base_path = rtrim(dirname($parts['path'] ?? '/'), '/');
    return $origin . ($base_path === '' ? '' : $base_path) . '/' . $href;
}

function parse_indie_cinema(DOMDocument $doc, array $source): array {
    $xpath = new DOMXPath($doc);
    $events = [];
    foreach ($xpath->query('//div[contains(@class,"movie-card")]') as $card) {
        $title_n = $xpath->query('.//*[contains(@class,"movie-title")]', $card);
        $date_n  = $xpath->query('.//*[contains(@class,"run-dates")]', $card);
        $time_n  = $xpath->query('.//*[contains(@class,"showtimes")]', $card);
        $link_n  = $xpath->query('.//a[contains(@class,"book-link")]', $card);
        if ($title_n->length === 0 || $date_n->length === 0) continue;

        $date = normalize_date(extract_start_date($date_n->item(0)->textContent));
        if (!$date) continue;

        $ev = blank_event($source['venue_name'], $source['url']);
        $ev['event_title'] = trim($title_n->item(0)->textContent);
        $ev['date'] = $date;
        $ev['time'] = clean_time($time_n->length ? $time_n->item(0)->textContent : '');
        $ev['link'] = $link_n->length ? resolve_url($link_n->item(0)->getAttribute('href'), $source['url']) : '';
        $events[] = $ev;
    }
    return $events;
}

function parse_basement_music_bar(DOMDocument $doc, array $source): array {
    $xpath = new DOMXPath($doc);
    $events = [];
    foreach ($xpath->query('//*[contains(@class,"show-item")]') as $item) {
        $date_n = $xpath->query('.//*[contains(@class,"show-date")]', $item);
        $band_n = $xpath->query('.//*[contains(@class,"band-name")]', $item);
        $time_n = $xpath->query('.//*[contains(@class,"show-time")]', $item);
        $link_n = $xpath->query('.//a[contains(@class,"tickets-link")]', $item);
        if ($date_n->length === 0 || $band_n->length === 0) continue;

        $date = normalize_date($date_n->item(0)->textContent);
        if (!$date) continue;

        $ev = blank_event($source['venue_name'], $source['url']);
        $ev['event_title'] = trim($band_n->item(0)->textContent);
        $ev['date'] = $date;
        $ev['time'] = clean_time($time_n->length ? $time_n->item(0)->textContent : '');
        $ev['link'] = $link_n->length ? resolve_url($link_n->item(0)->getAttribute('href'), $source['url']) : '';
        $events[] = $ev;
    }
    return $events;
}

function parse_city_parks(DOMDocument $doc, array $source): array {
    $xpath = new DOMXPath($doc);
    $events = [];
    foreach ($xpath->query('//article[contains(@class,"event-entry")]') as $article) {
        $time_elem = $xpath->query('.//time', $article);
        $title_n   = $xpath->query('.//h3', $article);
        $tod_n     = $xpath->query('.//*[contains(@class,"event-time")]', $article);
        $link_n    = $xpath->query('.//a', $article);
        if ($time_elem->length === 0 || $title_n->length === 0) continue;

        $date_raw = $time_elem->item(0)->getAttribute('datetime') ?: $time_elem->item(0)->textContent;
        $date = normalize_date($date_raw);
        if (!$date) continue;

        $ev = blank_event($source['venue_name'], $source['url']);
        $ev['event_title'] = trim($title_n->item(0)->textContent);
        $ev['date'] = $date;
        $ev['time'] = first_range_time($tod_n->length ? $tod_n->item(0)->textContent : '');
        $ev['link'] = $link_n->length ? resolve_url($link_n->item(0)->getAttribute('href'), $source['url']) : '';
        $events[] = $ev;
    }
    return $events;
}

function parse_starlight_theater(DOMDocument $doc, array $source): array {
    $xpath = new DOMXPath($doc);
    $events = [];
    foreach ($xpath->query('//div[contains(@class,"production-card")]') as $card) {
        $title_n = $xpath->query('.//h2[contains(@class,"show-title")]', $card);
        $link_n = $xpath->query('.//a[contains(@class,"ticket-link")]', $card);
        if ($title_n->length === 0) continue;
        $title = trim($title_n->item(0)->textContent);

        foreach ($xpath->query('.//ul[contains(@class,"performance-dates")]/li', $card) as $li) {
            $date_n = $xpath->query('.//*[contains(@class,"perf-date")]', $li);
            $time_n = $xpath->query('.//*[contains(@class,"perf-time")]', $li);
            $row_link_n = $xpath->query('.//a', $li);
            if ($date_n->length === 0) continue;

            $date = normalize_date($date_n->item(0)->textContent);
            if (!$date) continue;

            $href = $row_link_n->length ? $row_link_n->item(0)->getAttribute('href') : ($link_n->length ? $link_n->item(0)->getAttribute('href') : '');
            $ev = blank_event($source['venue_name'], $source['url']);
            $ev['event_title'] = $title;
            $ev['date'] = $date;
            $ev['time'] = clean_time($time_n->length ? $time_n->item(0)->textContent : '');
            $ev['link'] = resolve_url($href, $source['url']);
            $events[] = $ev;
        }
    }
    return $events;
}

function parse_blue_moon_cafe(DOMDocument $doc, array $source): array {
    $xpath = new DOMXPath($doc);
    $events = [];
    foreach ($xpath->query('//*[contains(@class,"music-event")]') as $row) {
        $date_n   = $xpath->query('.//*[contains(@class,"event-date")]', $row);
        $artist_n = $xpath->query('.//*[contains(@class,"artist")]', $row);
        $time_n   = $xpath->query('.//*[contains(@class,"event-time")]', $row);
        $link_n   = $xpath->query('.//a', $row);
        if ($date_n->length === 0 || $artist_n->length === 0) continue;

        $date = normalize_date($date_n->item(0)->textContent);
        if (!$date) continue;

        $ev = blank_event($source['venue_name'], $source['url']);
        $ev['event_title'] = trim($artist_n->item(0)->textContent);
        $ev['date'] = $date;
        $ev['time'] = clean_time($time_n->length ? $time_n->item(0)->textContent : '');
        $ev['link'] = $link_n->length ? resolve_url($link_n->item(0)->getAttribute('href'), $source['url']) : '';
        $events[] = $ev;
    }
    return $events;
}

// ============================================================================
// LIVE VENUE PARSERS
// ============================================================================

function parse_bitter_end(DOMDocument $doc, array $source): array {
    $xpath = new DOMXPath($doc);
    $events = [];
    foreach ($xpath->query('//div[contains(@class,"event-card")]') as $card) {
        $title_n = $xpath->query('.//h3[contains(@class,"event-title")]', $card);
        $date_n  = $xpath->query('.//div[contains(@class,"event-date")]', $card);
        $time_n  = $xpath->query('.//div[contains(@class,"event-time")]', $card);
        $link_n  = $xpath->query('.//a[contains(@class,"event-link")]', $card);
        if ($title_n->length === 0 || $date_n->length === 0) continue;

        $date = normalize_date($date_n->item(0)->textContent);
        if (!$date) continue;

        $ev = blank_event($source['venue_name'], $source['url']);
        $ev['event_title'] = trim($title_n->item(0)->textContent);
        $ev['date'] = $date;
        $ev['time'] = clean_time($time_n->length ? $time_n->item(0)->textContent : '');
        $ev['link'] = $link_n->length ? resolve_url($link_n->item(0)->getAttribute('href'), $source['url']) : '';
        $events[] = $ev;
    }
    return $events;
}

function parse_nyc_parks(DOMDocument $doc, array $source): array {
    $xpath = new DOMXPath($doc);
    $events = [];
    foreach ($xpath->query('//article[contains(@class,"event-entry")]') as $article) {
        $time_elem = $xpath->query('.//time', $article);
        $title_n   = $xpath->query('.//h3', $article);
        $tod_n     = $xpath->query('.//*[contains(@class,"event-time")]', $article);
        $link_n    = $xpath->query('.//a', $article);
        if ($time_elem->length === 0 || $title_n->length === 0) continue;

        $date_raw = $time_elem->item(0)->getAttribute('datetime') ?: $time_elem->item(0)->textContent;
        $date = normalize_date($date_raw);
        if (!$date) continue;

        $ev = blank_event($source['venue_name'], $source['url']);
        $ev['event_title'] = trim($title_n->item(0)->textContent);
        $ev['date'] = $date;
        $ev['time'] = first_range_time($tod_n->length ? $tod_n->item(0)->textContent : '');
        $ev['link'] = $link_n->length ? resolve_url($link_n->item(0)->getAttribute('href'), $source['url']) : '';
        $events[] = $ev;
    }
    return $events;
}

function parse_slowdown(DOMDocument $doc, array $source): array {
    $xpath = new DOMXPath($doc);
    $events = [];
    foreach ($xpath->query('//div[contains(@class,"show-card")]') as $card) {
        $artist_n = $xpath->query('.//h3[contains(@class,"artist-name")]', $card);
        $date_n   = $xpath->query('.//div[contains(@class,"show-date")]', $card);
        $time_n   = $xpath->query('.//div[contains(@class,"show-time")]', $card);
        $link_n   = $xpath->query('.//a[contains(@class,"show-link")]', $card);
        if ($artist_n->length === 0 || $date_n->length === 0) continue;

        $date = normalize_date($date_n->item(0)->textContent);
        if (!$date) continue;

        $ev = blank_event($source['venue_name'], $source['url']);
        $ev['event_title'] = trim($artist_n->item(0)->textContent);
        $ev['date'] = $date;
        $ev['time'] = clean_time($time_n->length ? $time_n->item(0)->textContent : '');
        $ev['link'] = $link_n->length ? resolve_url($link_n->item(0)->getAttribute('href'), $source['url']) : '';
        $events[] = $ev;
    }
    return $events;
}

function parse_coolidge(DOMDocument $doc, array $source): array {
    $xpath = new DOMXPath($doc);
    $events = [];
    foreach ($xpath->query('//div[contains(@class,"film-event")]') as $card) {
        $title_n = $xpath->query('.//h4[contains(@class,"film-title")]', $card);
        $date_n  = $xpath->query('.//div[contains(@class,"screening-date")]', $card);
        $time_n  = $xpath->query('.//div[contains(@class,"screening-time")]', $card);
        $link_n  = $xpath->query('.//a[contains(@class,"tickets-link")]', $card);
        if ($title_n->length === 0 || $date_n->length === 0) continue;

        $date = normalize_date($date_n->item(0)->textContent);
        if (!$date) continue;

        $ev = blank_event($source['venue_name'], $source['url']);
        $ev['event_title'] = trim($title_n->item(0)->textContent);
        $ev['date'] = $date;
        $ev['time'] = clean_time($time_n->length ? $time_n->item(0)->textContent : '');
        $ev['link'] = $link_n->length ? resolve_url($link_n->item(0)->getAttribute('href'), $source['url']) : '';
        $events[] = $ev;
    }
    return $events;
}

function parse_comedy_cellar(DOMDocument $doc, array $source): array {
    $xpath = new DOMXPath($doc);
    $events = [];
    foreach ($xpath->query('//div[contains(@class,"comedy-show")]') as $show) {
        $title_n = $xpath->query('.//div[contains(@class,"show-title")]', $show);
        $date_n  = $xpath->query('.//div[contains(@class,"show-date")]', $show);
        $time_n  = $xpath->query('.//div[contains(@class,"show-time")]', $show);
        if ($title_n->length === 0 || $date_n->length === 0) continue;

        $date = normalize_date($date_n->item(0)->textContent);
        if (!$date) continue;

        $ev = blank_event($source['venue_name'], $source['url']);
        $ev['event_title'] = trim($title_n->item(0)->textContent);
        $ev['date'] = $date;
        $ev['time'] = clean_time($time_n->length ? $time_n->item(0)->textContent : '');
        $ev['link'] = '';
        $events[] = $ev;
    }
    return $events;
}

// ============================================================================
// MOCK VENUE PARSERS (for local testing fallback)
// ============================================================================

function parse_bitter_end_mock(DOMDocument $doc, array $source): array {
    return parse_bitter_end($doc, $source);
}

function parse_nyc_parks_mock(DOMDocument $doc, array $source): array {
    return parse_nyc_parks($doc, $source);
}

function parse_slowdown_mock(DOMDocument $doc, array $source): array {
    return parse_slowdown($doc, $source);
}

function parse_coolidge_mock(DOMDocument $doc, array $source): array {
    return parse_coolidge($doc, $source);
}

function parse_comedy_cellar_mock(DOMDocument $doc, array $source): array {
    return parse_comedy_cellar($doc, $source);
}

function scrape_all_venues(): array {
    $all = [];
    $seen = [];
    $errors = [];
    $status = [];
    $checked = 0;

    foreach (venue_sources() as $source) {
        try {
            $html = fetch_url($source['url']);
            $doc = make_doc($html);
            $raw = call_user_func($source['parser'], $doc, $source);
            $checked++;

            $filtered = 0;
            foreach ($raw as $event) {
                if (!is_in_window($event['date'])) continue;

                $key = strtolower(trim($event['venue_name']) . '|||' . trim($event['event_title']) . '|||' . trim($event['date']));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $all[] = $event;
                $filtered++;
            }

            $status[] = [
                'venue_name' => $source['venue_name'],
                'status'     => 'OK',
                'events'     => $filtered,
            ];
        } catch (Throwable $e) {
            $errors[] = [
                'venue_name' => $source['venue_name'],
                'url'        => $source['url'],
                'message'    => 'Could not fetch or parse this source page.',
            ];
            $status[] = [
                'venue_name' => $source['venue_name'],
                'status'     => 'Error',
                'events'     => 0,
            ];
            scraper_log($source['venue_name'] . ' | ' . $source['url'] . ' | ' . $e->getMessage());
        }
    }

    return [
        'events'         => $all,
        'venues_checked' => $checked,
        'sources'        => venue_sources(),
        'errors'         => $errors,
        'status'         => $status,
    ];
}
