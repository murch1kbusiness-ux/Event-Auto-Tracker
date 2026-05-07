<?php
/**
 * Mock page for The Bitter End — Music Bar
 * Simulates their live event schedule page
 */
date_default_timezone_set('America/New_York');

$events = [
    ['title' => 'Jazz Night with The Ensemble', 'date' => date('M d, Y', strtotime('+5 days')), 'time' => '8:00 PM', 'link' => '/event/jazz-night-ensemble'],
    ['title' => 'Funk & Soul Showcase', 'date' => date('M d, Y', strtotime('+12 days')), 'time' => '9:00 PM', 'link' => '/event/funk-soul-showcase'],
    ['title' => 'Acoustic Singer-Songwriter Series', 'date' => date('M d, Y', strtotime('+19 days')), 'time' => '7:30 PM', 'link' => '/event/acoustic-series'],
    ['title' => 'Latin Groove Night', 'date' => date('M d, Y', strtotime('+25 days')), 'time' => '8:30 PM', 'link' => '/event/latin-groove'],
];
?><!DOCTYPE html>
<html>
<head><title>The Bitter End - Schedule</title></head>
<body>
<h1>The Bitter End - Live Music Schedule</h1>
<div class="events-container">
  <?php foreach ($events as $ev): ?>
  <div class="event-card">
    <h3 class="event-title"><?= htmlspecialchars($ev['title']) ?></h3>
    <div class="event-date"><?= htmlspecialchars($ev['date']) ?></div>
    <div class="event-time"><?= htmlspecialchars($ev['time']) ?></div>
    <a href="<?= htmlspecialchars($ev['link']) ?>" class="event-link">Get Tickets</a>
  </div>
  <?php endforeach; ?>
</div>
</body>
</html>
