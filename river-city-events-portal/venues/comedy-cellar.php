<?php
/**
 * Mock page for Comedy Cellar — Line Up
 * Simulates their comedy show schedule
 */
date_default_timezone_set('America/New_York');

$events = [
    ['comic' => 'Stand-Up Comedy Night with Local Legends', 'date' => date('M d', strtotime('+1 days')), 'time' => '8:00 PM'],
    ['comic' => 'Open Mic Comedy Special', 'date' => date('M d', strtotime('+8 days')), 'time' => '9:00 PM'],
    ['comic' => 'Late Night Comedy Showcase', 'date' => date('M d', strtotime('+15 days')), 'time' => '10:30 PM'],
    ['comic' => 'Comedy Club Takeover - National Act', 'date' => date('M d', strtotime('+22 days')), 'time' => '8:30 PM'],
];
?><!DOCTYPE html>
<html>
<head><title>Comedy Cellar Line-up</title></head>
<body>
<h1>Comedy Cellar - Line Up</h1>
<div class="lineup">
  <?php foreach ($events as $ev): ?>
  <div class="comedy-show">
    <div class="show-title"><?= htmlspecialchars($ev['comic']) ?></div>
    <div class="show-date"><?= htmlspecialchars($ev['date']) ?></div>
    <div class="show-time"><?= htmlspecialchars($ev['time']) ?></div>
    <a href="/lineup">Tickets & Info</a>
  </div>
  <?php endforeach; ?>
</div>
</body>
</html>
