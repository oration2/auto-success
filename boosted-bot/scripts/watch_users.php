<?php
// Watch users.json and print counters/aggregates on change
// Respect configured USER_DATA_FILE if available
if (file_exists(__DIR__ . '/../config/config.php')) { require_once __DIR__ . '/../config/config.php'; }
$usersFile = defined('USER_DATA_FILE') ? USER_DATA_FILE : (__DIR__ . '/../config/users.json');
if (!is_file($usersFile)) { fwrite(STDERR, "users.json not found\n"); exit(1); }
$filterId = $argv[1] ?? null;
$lastHash = '';
function snap($file){ $j = json_decode(file_get_contents($file), true) ?: []; return $j; }
function line($id,$ud){
  $mk = date('Y-m'); $dk = date('Y-m-d');
  $h = (int)($ud['emails_sent_hour'] ?? 0);
  $d = (int)($ud['emails_sent_today'] ?? 0);
  $m = (int)($ud['emails_sent_month'] ?? 0);
  $plan = $ud['plan'] ?? '?';
  $st = $ud['email_stats'] ?? [];
  $tot = (int)($st['total_sent'] ?? 0);
  $mTot = (int)($st['monthly_stats'][$mk] ?? 0);
  $dTot = (int)($st['daily_stats'][$dk] ?? 0);
  $active = (!empty($ud['sending_state']['is_sending'])) ? 'ACTIVE' : '';
  return sprintf("%s plan=%s H=%d D=%d M=%d | total=%d month=%d day=%d %s", $id, $plan, $h,$d,$m,$tot,$mTot,$dTot,$active);
}
while (true) {
  clearstatcache(true, $usersFile);
  $hash = md5_file($usersFile);
  if ($hash !== $lastHash) {
    $lastHash = $hash;
    $all = snap($usersFile);
    $lines = [];
    foreach ($all as $id=>$ud){
      if ($filterId && (string)$id !== (string)$filterId) { continue; }
      $lines[] = line($id,$ud);
    }
    echo "\n=== " . date('H:i:s') . " users.json updated ===\n";
    foreach ($lines as $ln) { echo $ln, "\n"; }
    fflush(STDOUT);
  }
  usleep(200000); // 200ms
}
