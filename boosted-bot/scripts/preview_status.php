<?php
// Print key counters and aggregates for a chat ID from users.json
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Run from CLI\n"); exit(1); }
require_once __DIR__ . '/../config/config.php';

$chatId = $argv[1] ?? null;
if (!$chatId) { fwrite(STDERR, "Usage: php scripts/preview_status.php <chatId>\n"); exit(2); }

$file = defined('USER_DATA_FILE') ? USER_DATA_FILE : (__DIR__ . '/../config/users.json');
if (!is_file($file)) { fwrite(STDERR, "users.json not found: $file\n"); exit(3); }

$json = json_decode(file_get_contents($file), true) ?: [];
if (!isset($json[$chatId])) { fwrite(STDERR, "Chat $chatId not found in users.json\n"); exit(4); }
$ud = $json[$chatId];

$plan = $ud['plan'] ?? 'trial';
$h = (int)($ud['emails_sent_hour'] ?? 0);
$d = (int)($ud['emails_sent_today'] ?? 0);
$m = (int)($ud['emails_sent_month'] ?? 0);

$stats = $ud['email_stats'] ?? [];
$monthKey = date('Y-m');
$dayKey = date('Y-m-d');
$aggDay = (int)($stats['daily_stats'][$dayKey] ?? 0);
$aggMonth = (int)($stats['monthly_stats'][$monthKey] ?? 0);
$total = (int)($stats['total_sent'] ?? 0);

echo "plan=$plan H=$h D=$d M=$m | total=$total month=$aggMonth day=$aggDay\n";
exit(0);
