<?php
/**
 * Mock page for Coolidge Corner Theatre
 * Simulates their movie schedule
 */
date_default_timezone_set('America/New_York');

$events = [
    ['title' => 'Classic Film Series: Hitchcock', 'date' => date('M d, Y', strtotime('+2 days')), 'time' => '7:00 PM', 'link' => '/film/hitchcock-series'],
    ['title' => 'New Documentary Release', 'date' => date('M d, Y', strtotime('+9 days')), 'time' => '8:00 PM', 'link' => '/film/documentary'],
    ['title' => 'International Cinema Night', 'date' => date('M d, Y', strtotime('+16 days')), 'time' => '7:30 PM', 'link' => '/film/international'],
    ['title' => 'Midnight Horror Screening', 'date' => date('M d, Y', strtotime('+23 days')), 'time' => '11:59 PM', 'link' => '/film/horror'],
    ['title' => 'Family Matinee - Adventure Film', 'date' => date('M d, Y', strtotime('+27 days')), 'time' => '2:00 PM', 'link' => '/film/family-matinee'],
];
?><!DOCTYPE html>
<html>
<head><title>Coolidge Corner Theatre - Events</title></head>
<body>
<h1>Coolidge Corner Theatre</h1>
<div class="film-list">
  <?php foreach ($events as $ev): ?>
  <div class="film-event">
    <h4 class="film-title"><?= htmlspecialchars($ev['title']) ?></h4>
    <div class="screening-date"><?= htmlspecialchars($ev['date']) ?></div>
    <div class="screening-time"><?= htmlspecialchars($ev['time']) ?></div>
    <a href="<?= htmlspecialchars($ev['link']) ?>" class="tickets-link">Tickets</a>
  </div>
  <?php endforeach; ?>
</div>
</body>
</html>
