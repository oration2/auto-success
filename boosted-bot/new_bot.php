<?php

// Application bootstrap file
require_once __DIR__ . '/vendor/autoload.php';

use App\Core\TelegramBotCore;
use App\Handlers\AdminCommandHandler;
use App\Handlers\CampaignCommandHandler;
use App\Handlers\HelpCommandHandler;
use App\Handlers\StartCommandHandler;
use App\Handlers\StatsCommandHandler;
use App\Handlers\StatusCommandHandler;

// Set timezone
date_default_timezone_set('UTC');

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Register exception handler
set_exception_handler(function($e) {
    error_log("[EXCEPTION] " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    error_log($e->getTraceAsString());
});

// Initialize bot
try {
    $bot = new TelegramBotCore();
    
    // Register command handlers
    $bot->registerCommandHandlers([
        StartCommandHandler::class,
        HelpCommandHandler::class,
        AdminCommandHandler::class,
        CampaignCommandHandler::class,
        StatsCommandHandler::class,
        StatusCommandHandler::class,
    ]);
    
    // Test connection
    if (!$bot->testConnection()) {
        throw new Exception("Failed to connect to Telegram API");
    }
    
    // Start bot
    $bot->startPolling();
} catch (Exception $e) {
    error_log("[CRITICAL] " . $e->getMessage());
    exit(1);
}
