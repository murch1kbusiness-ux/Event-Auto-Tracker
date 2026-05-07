<?php
/**
 * Mock page for NYC Parks Events
 * Simulates their event listing
 */
date_default_timezone_set('America/New_York');

$events = [
    ['title' => 'Outdoor Movie Night - Central Park', 'date' => date('Y-m-d', strtotime('+3 days')), 'time' => '6:00 PM'],
    ['title' => 'Free Fitness Class in the Park', 'date' => date('Y-m-d', strtotime('+7 days')), 'time' => '7:00 AM'],
    ['title' => 'Community Garden Workshop', 'date' => date('Y-m-d', strtotime('+14 days')), 'time' => '10:00 AM'],
    ['title' => 'Park Cleanup & Picnic', 'date' => date('Y-m-d', strtotime('+21 days')), 'time' => '9:00 AM'],
    ['title' => 'Evening Concert in the Park', 'date' => date('Y-m-d', strtotime('+28 days')), 'time' => '7:30 PM'],
];
?><!DOCTYPE html>
<html>
<head><title>NYC Parks Events</title></head>
<body>
<h1>NYC Parks - Public Events</h1>
<div class="events-list">
  <?php foreach ($events as $ev): ?>
  <article class="event-entry">
    <time datetime="<?= htmlspecialchars($ev['date']) ?>T00:00:00"><?= htmlspecialchars($ev['date']) ?></time>
    <h3><?= htmlspecialchars($ev['title']) ?></h3>
    <div class="event-time"><?= htmlspecialchars($ev['time']) ?></div>
    <a href="/event-details">More Info</a>
  </article>
  <?php endforeach; ?>
</div>
</body>
</html>
