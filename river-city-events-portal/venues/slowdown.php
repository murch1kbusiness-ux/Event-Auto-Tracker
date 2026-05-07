<?php
/**
 * Mock page for The Slowdown — Indie Venue
 * Simulates their live show schedule
 */
date_default_timezone_set('America/New_York');

$events = [
    ['artist' => 'Indie Dream Band', 'date' => date('M d', strtotime('+4 days')), 'time' => 'Show: 9:00 PM', 'link' => '/shows/indie-dream'],
    ['artist' => 'Lo-Fi Beats Live', 'date' => date('M d', strtotime('+11 days')), 'time' => 'Show: 10:00 PM', 'link' => '/shows/lo-fi-beats'],
    ['artist' => 'Alternative Rock Night', 'date' => date('M d', strtotime('+18 days')), 'time' => 'Show: 9:30 PM', 'link' => '/shows/alt-rock'],
    ['artist' => 'Experimental Noise Set', 'date' => date('M d', strtotime('+24 days')), 'time' => 'Show: 11:00 PM', 'link' => '/shows/experimental'],
];
?><!DOCTYPE html>
<html>
<head><title>The Slowdown Schedule</title></head>
<body>
<h1>The Slowdown - Live Shows</h1>
<div class="shows-grid">
  <?php foreach ($events as $ev): ?>
  <div class="show-card">
    <h3 class="artist-name"><?= htmlspecialchars($ev['artist']) ?></h3>
    <div class="show-date"><?= htmlspecialchars($ev['date']) ?></div>
    <div class="show-time"><?= htmlspecialchars($ev['time']) ?></div>
    <a href="<?= htmlspecialchars($ev['link']) ?>" class="show-link">Tickets</a>
  </div>
  <?php endforeach; ?>
</div>
</body>
</html>
