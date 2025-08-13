<?php
// Continuously print per-user counters and aggregates to verify live updates
// Respect configured path when present
if (file_exists(__DIR__ . '/../config/config.php')) { require_once __DIR__ . '/../config/config.php'; }
$usersFile = defined('USER_DATA_FILE') ? USER_DATA_FILE : (__DIR__ . '/../config/users.json');
if (!is_file($usersFile)) {
  fwrite(STDERR, "users.json not found: {$usersFile}\n");
  exit(1);
}

function snapshot($path) {
  $raw = @file_get_contents($path);
  if ($raw === false) { return []; }
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

function line($id, $ud) {
  $plan = $ud['plan'] ?? 'unknown';
  $h = (int)($ud['emails_sent_hour'] ?? 0);
  $d = (int)($ud['emails_sent_today'] ?? 0);
  $m = (int)($ud['emails_sent_month'] ?? 0);
  $stats = $ud['email_stats'] ?? [];
  $mk = date('Y-m'); $dk = date('Y-m-d');
  $mt = (int)($stats['monthly_stats'][$mk] ?? 0);
  $dt = (int)($stats['daily_stats'][$dk] ?? 0);
  $active = isset($ud['sending_state']['is_sending']) && $ud['sending_state']['is_sending'] ? '▶' : ' ';
  return sprintf("%s id=%s plan=%s | H=%d D=%d M=%d | agg day=%d mon=%d", $active, $id, $plan, $h, $d, $m, $dt, $mt);
}

$last = [];
$iter = 0;
while (true) {
  $all = snapshot($usersFile);
  $lines = [];
  foreach ($all as $id => $ud) {
    $ln = line($id, $ud);
    // print always for active users, otherwise only when counters changed every 5 iterations
    $key = $id.'|'.md5(json_encode([$ud['emails_sent_hour']??0,$ud['emails_sent_today']??0,$ud['emails_sent_month']??0, $ud['email_stats']['daily_stats'][date('Y-m-d')] ?? 0, $ud['email_stats']['monthly_stats'][date('Y-m')] ?? 0]));
    $active = isset($ud['sending_state']['is_sending']) && $ud['sending_state']['is_sending'];
    if ($active || !isset($last[$id]) || $last[$id] !== $key || ($iter % 5) === 0) {
      $lines[] = $ln;
      $last[$id] = $key;
    }
  }
  if (!empty($lines)) {
    echo "\n=== users.json @ ".date('H:i:s')." ===\n";
    foreach ($lines as $ln) { echo $ln, "\n"; }
  }
  $iter++;
  usleep(1000000); // 1s
}
