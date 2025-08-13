<?php
// Simulate a custom_smtp plan sending for a real chat id to validate counters and status
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/TelegramBot.php';

$chatId = $argv[1] ?? null;
if (!$chatId) {
  fwrite(STDERR, "Usage: php scripts/smoke_custom_smtp.php <chatId>\n");
  exit(2);
}
putenv('DRY_SEND=1');

$bot = new TelegramBot();

// Seed a tiny email list
$uploadsDir = __DIR__ . '/../uploads';
if (!is_dir($uploadsDir)) { mkdir($uploadsDir, 0755, true); }
$listPath = $uploadsDir . '/test_list_custom.txt';
file_put_contents($listPath, "a@example.com\n b@example.com\n c@example.com\n");

// Prepare user with custom SMTP
$ref = new ReflectionClass($bot);
$prop = $ref->getProperty('userData');
$prop->setAccessible(true);
$userData = $prop->getValue($bot);
$userData[$chatId] = $userData[$chatId] ?? [];
$userData[$chatId]['plan'] = 'custom_smtp';
$userData[$chatId]['plan_expires'] = time() + 30*86400;
$userData[$chatId]['smtp'] = [
  'host' => 'rodasrl.ro',
  'port' => 587,
  'username' => 'daniela.nistor@rodasrl.ro',
  'password' => '0=6Vz*zMZ[XH@JS]',
  'from_email' => 'daniela.nistor@rodasrl.ro',
  'from_name' => 'Notification'
];
$userData[$chatId]['email_list_path'] = realpath($listPath);
$userData[$chatId]['email_subject'] = 'Test Custom SMTP';
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
// Save
$save = $ref->getMethod('saveUserData');
$save->setAccessible(true);
$save->invoke($bot);

// Run loop
$cont = true; $guard = 0;
while ($cont && $guard < 10) {
  $cont = $bot->continueSending($chatId);
  $guard++;
}

// Show status preview using script
passthru('php '.__DIR__.'/preview_status.php '.escapeshellarg($chatId), $code);
exit($code);
