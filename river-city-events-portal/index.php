<?php
date_default_timezone_set('America/New_York');

require_once __DIR__ . '/includes/scraper.php';

$config_path = __DIR__ . '/includes/config.php';
$config = file_exists($config_path) ? (require $config_path) : [];
if (!is_array($config)) $config = [];

$github_url = htmlspecialchars($config['github_url'] ?? 'https://github.com/murch1kbusiness-ux/Event-Auto-Tracker', ENT_QUOTES);
$base_url = trim($config['base_url'] ?? '');
if ($base_url === '' && !empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $base_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . ($dir === '' ? '' : $dir);
}
$base_url = rtrim($base_url ?: 'http://localhost:8000', '/');
$csv_url = trim($config['public_csv_url'] ?? '') ?: $base_url . '/export_csv.php';
$sheet_url = trim($config['sheet_url'] ?? getenv('SHEET_URL') ?: '');
$sheet_preview_url = $sheet_url !== '' ? $sheet_url : 'Google Sheet URL pending';

$current_mode = source_mode();
$mode_label = $current_mode === 'live' ? 'Live public pages' : 'Demo fallback pages';

$sources = [
    ['label' => 'The Bitter End', 'url' => 'https://bitterend.com/', 'note' => 'VenuePilot widget API source'],
    ['label' => 'City Parks Events', 'url' => 'https://www.nycgovparks.org/events/ajax/aggregate/common', 'note' => 'NYC Parks events feed'],
    ['label' => 'The Slowdown', 'url' => 'https://theslowdown.com/events/', 'note' => 'Live events page'],
    ['label' => 'Coolidge Corner Theatre', 'url' => 'https://coolidge.org/showtimes', 'note' => 'Showtimes page'],
    ['label' => 'Comedy Cellar', 'url' => 'https://www.comedycellar.com/', 'note' => 'Public homepage showtimes'],
];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="A PHP dashboard that collects events from five public venue pages into CSV and JSON files.">
  <title>River City Events Portal</title>
  <link rel="stylesheet" href="assets/styles.css">
  <script>
    window.APP_CSV_URL = <?= json_encode($csv_url) ?>;
    window.APP_SHEET_URL = <?= json_encode($sheet_preview_url) ?>;
    window.APP_SOURCE_PAGES = <?= json_encode($sources, JSON_UNESCAPED_SLASHES) ?>;
  </script>
</head>
<body>
  <header class="topbar">
    <div class="topbar-main">
      <a class="brand" href="./">River City Events Portal</a>
      <nav class="topnav" aria-label="Main links">
        <a href="<?= $github_url ?>" target="_blank" rel="noopener">GitHub</a>
        <a href="https://qw1-cloud.com" target="_blank" rel="noopener">Portfolio</a>
      </nav>
    </div>
    <div class="topbar-actions">
      <button class="btn primary" id="btn-scan" type="button">Run Scan</button>
      <a class="btn" href="export_csv.php">Download CSV</a>
    </div>
  </header>

  <main class="page">
    <section class="intro-panel">
      <div>
        <p class="eyebrow">Newsletter event collector</p>
        <h1>Pull upcoming events from five NYC venues.</h1>
      </div>
    </section>

    <section class="status-strip" aria-label="Scan status">
      <div class="status-item">
        <span class="status-label">Last scan</span>
        <strong id="status-last-scan">Not run yet</strong>
      </div>
      <div class="status-item">
        <span class="status-label">Venues checked</span>
        <strong id="status-venues">0</strong>
      </div>
      <div class="status-item">
        <span class="status-label">Total events</span>
        <strong id="status-total">0</strong>
      </div>
      <div class="status-item">
        <span class="status-label">New events</span>
        <strong id="status-new">0</strong>
      </div>
      <div class="status-item">
        <span class="status-label">Duplicates skipped</span>
        <strong id="status-duplicates">0</strong>
      </div>
      <div class="status-item">
        <span class="status-label">TBA times</span>
        <strong id="status-tba">0</strong>
      </div>
    </section>

    <div id="scan-result" class="scan-result" role="status" aria-live="polite"></div>

    <section class="tool-grid">
      <div class="table-panel" id="events">
        <div class="panel-head">
          <div>
            <h2>Events</h2>
          </div>
        </div>

        <div class="filters" aria-label="Table filters">
          <label>
            <span>Search</span>
            <input id="search-input" type="search" placeholder="Title or venue" autocomplete="off">
          </label>
          <label>
            <span>Venue</span>
            <select id="venue-filter">
              <option value="">All venues</option>
            </select>
          </label>
          <label>
            <span>Sort</span>
            <select id="sort-select">
              <option value="date-asc">Date ↑</option>
              <option value="date-desc">Date ↓</option>
              <option value="title">Title A–Z</option>
              <option value="venue">Venue A–Z</option>
              <option value="source">Source A–Z</option>
              <option value="checked">Last Checked ↑</option>
              <option value="checked-desc">Last Checked ↓</option>
            </select>
          </label>
          <label>
            <span>Range</span>
            <select id="range-filter">
              <option value="all">Show all stored</option>
              <option value="7">Next 7 days</option>
              <option value="30" selected>Next 30 days</option>
            </select>
          </label>
          <button class="btn compact" id="btn-reset" type="button">Reset</button>
        </div>

        <div class="table-wrap">
          <table id="events-table">
            <colgroup>
              <col style="width:140px">
              <col style="min-width:200px">
              <col style="width:120px">
              <col style="width:90px">
              <col style="width:70px">
              <col style="width:130px">
              <col style="width:130px">
              <col style="min-width:180px">
            </colgroup>
            <thead>
              <tr>
                <th data-sort="venue" class="sortable">Venue <span class="sort-icon">↕</span></th>
                <th data-sort="title" class="sortable">Event <span class="sort-icon">↕</span></th>
                <th data-sort="date" class="sortable th-active">Date <span class="sort-icon">↑</span></th>
                <th>Time</th>
                <th>Link</th>
                <th data-sort="source" class="sortable">Source <span class="sort-icon">↕</span></th>
                <th data-sort="checked" class="sortable">Last Checked <span class="sort-icon">↕</span></th>
                <th>Newsletter Notes</th>
              </tr>
            </thead>
            <tbody id="events-tbody">
              <tr>
                <td colspan="8" class="empty-cell">No events stored yet. Click Run Scan.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <aside class="side-panel">
        <section class="source-status-card" id="status">
          <h2>Source status</h2>
          <div id="source-status-list" class="status-list">
            <p class="muted">Run a scan to see source status.</p>
          </div>
        </section>

        <section class="telegram-card" id="sheets-status">
          <h2>Google Sheet Integration</h2>
          <div id="sheets-display" style="display: none;">
            <div style="display: flex; align-items: center; gap: 16px; margin: 16px 0;">
              <div>
                <p class="muted" style="margin: 0 0 4px 0;">Status:</p>
                <p id="sheets-status-text" style="font-size: 1.1em; font-weight: 600; margin: 0; color: #2ecc71;">✓ Configured</p>
              </div>
              <a id="sheets-open-link" href="#" target="_blank" rel="noopener" class="btn">Open Sheet</a>
            </div>
          </div>
          <div id="sheets-not-configured" style="display: none;">
            <p class="muted">Sync scan results to a Google Sheet for team access and notes.</p>
            <p style="color: #e74c3c; font-weight: 500;">⚠ Not configured — contact admin to set up.</p>
            <p class="muted"><small>Admin: Add <code>SPREADSHEET_ID</code> and <code>credentials.json</code> to .env</small></p>
          </div>
        </section>

        <section class="telegram-card" id="telegram-settings">
          <h2>Telegram Delivery</h2>

          <div id="telegram-display-mode" class="settings-status">
            <div style="display: flex; align-items: center; gap: 16px; margin: 16px 0;">
              <div>
                <p class="muted" style="margin: 0 0 4px 0;">Your Telegram ID:</p>
                <p id="telegram-chat-display" style="font-size: 1.1em; font-weight: 600; margin: 0;">Not set</p>
              </div>
              <button class="btn compact" id="btn-edit-telegram" type="button">✎ Edit</button>
            </div>
          </div>

          <div id="telegram-edit-mode" class="settings-form" style="display: none;">
            <label>
              <span>Telegram Chat ID</span>
              <input id="telegram-chat-id" type="text" autocomplete="off" placeholder="Enter your Telegram Chat ID">
            </label>
            <p class="muted"><small>To find your chat ID: send any message to your bot, then open <code>https://api.telegram.org/bot[BOT_TOKEN]/getUpdates</code> and copy the <code>chat.id</code> value.</small></p>
            <div class="settings-actions">
              <button class="btn primary" id="btn-save-telegram" type="button">Save</button>
              <button class="btn" id="btn-cancel-telegram" type="button">Cancel</button>
            </div>
            <p id="telegram-settings-result" class="settings-result" role="status" aria-live="polite"></p>
          </div>

          <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #eee;">
            <button class="btn primary" id="btn-send-last-telegram" type="button">Send Last Scan to Telegram</button>
            <p id="telegram-send-result" class="settings-result" role="status" aria-live="polite"></p>
          </div>
        </section>

        <section class="source-panel" id="sources">
          <h2>Source pages</h2>
          <ul class="source-list">
            <?php foreach ($sources as $source): ?>
              <li>
                <a href="<?= htmlspecialchars($source['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($source['label'], ENT_QUOTES) ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      </aside>
    </section>
  </main>

  <footer class="footer">
  </footer>

  <div id="toast" class="toast"></div>
  <script src="assets/app.js"></script>
</body>
</html>
