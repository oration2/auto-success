<?php
// Minimal smoke test to simulate a completed campaign and verify stats update
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/TelegramBot.php';

putenv('DRY_SEND=1');

$bot = new TelegramBot();
$chatId = 123456789; // test user id

// Seed a tiny email list
$uploadsDir = __DIR__ . '/../uploads';
if (!is_dir($uploadsDir)) { mkdir($uploadsDir, 0755, true); }
$listPath = $uploadsDir . '/test_list.txt';
file_put_contents($listPath, "a@example.com\n b@example.com\n c@example.com\n");

// Prepare user data with sending_state
$ref = new ReflectionClass($bot);
$prop = $ref->getProperty('userData');
$prop->setAccessible(true);
$userData = $prop->getValue($bot);
$userData[$chatId] = $userData[$chatId] ?? [];
$userData[$chatId]['plan'] = 'free';
$userData[$chatId]['email_list_path'] = realpath($listPath);
$userData[$chatId]['email_subject'] = 'Test';
$userData[$chatId]['sender_name'] = 'Tester';
$userData[$chatId]['html_template'] = '<b>Hello</b>';
$userData[$chatId]['emails_sent_today'] = $userData[$chatId]['emails_sent_today'] ?? 0;
$userData[$chatId]['emails_sent_hour'] = $userData[$chatId]['emails_sent_hour'] ?? 0;
$userData[$chatId]['emails_sent_month'] = $userData[$chatId]['emails_sent_month'] ?? 0;
$userData[$chatId]['sending_state'] = [
  'is_sending' => true,
  'is_paused' => false,
  'current_index' => 0,
  'total_emails' => 3,
  'batch_size' => 5,
  'batch_delay' => 0,
  'smtp_rotation' => false,
  'html_template' => '<b>Hello</b>',
  'start_time' => time(),
  'current_smtp_index' => 0,
];
$prop->setValue($bot, $userData);
// Save user data to disk through method
$save = $ref->getMethod('saveUserData');
$save->setAccessible(true);
$save->invoke($bot);

// Run the processing loop until it completes
$cont = true; $guard = 0;
while ($cont && $guard < 10) {
  $cont = $bot->continueSending($chatId);
  $guard++;
}

// Reload and print summary
$prop->setAccessible(true);
$userData = $prop->getValue($bot);
$ud = $userData[$chatId] ?? [];
$state = $ud['sending_state'] ?? [];
$sentHour = $ud['emails_sent_hour'] ?? 0;
$sentToday = $ud['emails_sent_today'] ?? 0;
$sentMonth = $ud['emails_sent_month'] ?? 0;
$stats = $ud['email_stats'] ?? [];
$monthKey = date('Y-m');
$dayKey = date('Y-m-d');
$monthlySent = $stats['monthly_stats'][$monthKey] ?? 0;
$dailySent = $stats['daily_stats'][$dayKey] ?? 0;

echo "Live counters => hour: {$sentHour}, today: {$sentToday}, month: {$sentMonth}\n";
echo "State => is_sending: ".(($state['is_sending'] ?? null) ? '1' : '0').", sent_count: ".($state['sent_count'] ?? -1).", error_count: ".($state['error_count'] ?? -1).", current_index: ".($state['current_index'] ?? -1).", total_emails: ".($state['total_emails'] ?? -1)."\n";
echo "Aggregates => total_sent: ".($stats['total_sent'] ?? 0).", monthly: {$monthlySent}, daily: {$dailySent}\n";

// Also show raw persisted file values
$usersFile = __DIR__ . '/../config/users.json';
if (is_file($usersFile)) {
  $raw = json_decode(file_get_contents($usersFile), true);
  $rawStats = $raw[$chatId]['email_stats'] ?? [];
  $rawMonth = $rawStats['monthly_stats'][$monthKey] ?? 0;
  $rawDay = $rawStats['daily_stats'][$dayKey] ?? 0;
  echo "Persisted => total_sent: ".($rawStats['total_sent'] ?? 0).", monthly: {$rawMonth}, daily: {$rawDay}\n";
}

// Basic assertions exit code
$ok = ($sentToday >= 3) && (($stats['total_sent'] ?? 0) >= 3) && ($monthlySent >= 3) && ($dailySent >= 3);
exit($ok ? 0 : 1);
