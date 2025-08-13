<?php
// CLI worker to process email sending for a single chat without blocking the bot
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "This script must be run from CLI.\n"); exit(1); }

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/TelegramBot.php';

function parse_args($argv) {
    $args = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) { $args[$m[1]] = $m[2]; }
        elseif (preg_match('/^--(.+)$/', $arg, $m)) { $args[$m[1]] = true; }
    }
    return $args;
}

$args = parse_args($argv);
$chatId = $args['chat-id'] ?? $args['chat_id'] ?? null;
if (!$chatId) {
    fwrite(STDERR, "Usage: php queue_worker.php --chat-id=12345\n");
    exit(2);
}

// Simple lock to avoid duplicate workers for same chat
$lockDir = __DIR__ . '/data/locks';
@mkdir($lockDir, 0755, true);
$lockFile = $lockDir . '/chat_' . preg_replace('/[^0-9]/', '', (string)$chatId) . '.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another worker is already running for chat {$chatId}.\n");
    exit(0);
}
if ($lockHandle) { ftruncate($lockHandle, 0); fwrite($lockHandle, (string)getmypid()); fflush($lockHandle); }

try {
    $bot = new TelegramBot();
    $bot->log("[worker] Started for chat {$chatId}");
    // Warm-up: allow brief time for state to persist
    $warmupTries = 5;
    for ($i = 0; $i < $warmupTries; $i++) {
        $state = $bot->getSendingState($chatId);
        if (($state['is_sending'] ?? false) || ($state['is_paused'] ?? false)) { break; }
        usleep(100000); // 100ms
    }

    // Loop until campaign completes or is paused/stopped
    while (true) {
        $state = $bot->getSendingState($chatId);
        if (!($state['is_sending'] ?? false)) { $bot->log("[worker] Not sending - exiting for {$chatId}"); break; }
        if (($state['is_paused'] ?? false) === true) { $bot->log("[worker] Paused - exiting for {$chatId}"); break; }
        $cont = $bot->continueSending($chatId);
        if ($cont !== true) { $bot->log("[worker] Completed or halted for {$chatId}"); break; }
        // Small cooperative delay; processUserEmails already sleeps per batch
        usleep(100000); // 100ms
    }
} catch (Throwable $e) {
    // Best-effort logging
    @file_put_contents(__DIR__ . '/logs/error.log', date('[Y-m-d H:i:s] ') . "WORKER ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
}

// Release lock
if ($lockHandle) { flock($lockHandle, LOCK_UN); fclose($lockHandle); @unlink($lockFile); }
exit(0);
