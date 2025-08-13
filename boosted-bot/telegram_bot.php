<?php
/**
 * Boosted Telegram Bot
 * 
 * An enhanced version of the Telegram bot with improved structure,
 * fixed issues, and better organization.
 * 
 * @version 2.0.0
 * @author Boosted Bot Team
 */

// Error reporting and timezone settings
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Quiet Xdebug step-debug in CLI runs
if (PHP_SAPI === 'cli' && extension_loaded('xdebug')) {
    // Avoid connecting to a client unless explicitly requested
    @ini_set('xdebug.start_with_request', 'no');
}
date_default_timezone_set('UTC');

// Load configuration
require_once __DIR__ . '/config/config.php';
// Composer autoloader (for PHPMailer and other vendor libs)
require_once __DIR__ . '/vendor/autoload.php';

// Autoloader for traits and classes
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Required files
require_once __DIR__ . '/src/TelegramBot.php';

// Initialize the bot
try {
    // Create bot instance
    $bot = new TelegramBot();
    
    // Test connection to Telegram API
    if ($bot->testConnection()) {
        $bot->log("Connection to Telegram API successful");
    } else {
        throw new Exception("Failed to connect to Telegram API");
    }
    
    // Initialize campaign tracker
    if (!defined('ACTIVE_CAMPAIGNS')) {
        define('ACTIVE_CAMPAIGNS', []);
    }
    
    // Start the main polling loop
    $bot->log("Bot started successfully");
    $bot->startPolling();
    
} catch (Exception $e) {
    // Log any startup errors
    file_put_contents(__DIR__ . '/logs/error.log', 
        date('[Y-m-d H:i:s] ') . "STARTUP ERROR: " . $e->getMessage() . "\n", 
        FILE_APPEND);
    
    echo "Error: " . $e->getMessage();
}
