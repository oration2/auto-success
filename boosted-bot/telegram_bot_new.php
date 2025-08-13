<?php
/**
 * Boosted Telegram Bot - REFACTORED ARCHITECTURE
 * 
 * Modern service-oriented architecture with dependency injection,
 * comprehensive error handling, and modular design.
 * 
 * @version 3.0.0 (Refactored)
 * @author Boosted Bot Team
 */

// Error reporting and timezone settings
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Quiet Xdebug step-debug in CLI runs
if (PHP_SAPI === 'cli' && extension_loaded('xdebug')) {
    @ini_set('xdebug.start_with_request', 'no');
}
date_default_timezone_set('UTC');

// Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

use App\Core\TelegramBotCore;

try {
    // Initialize the refactored bot
    $bot = new TelegramBotCore();
    
    echo "🚀 Starting Boosted Telegram Bot (Refactored Architecture)...\n";
    echo "============================================================\n\n";
    
    // Check if this is a webhook request
    $input = file_get_contents('php://input');
    
    // For CLI testing, also check stdin
    if (empty($input) && php_sapi_name() === 'cli') {
        $stdin = '';
        $handle = fopen('php://stdin', 'r');
        if ($handle) {
            stream_set_blocking($handle, false);
            $stdin = stream_get_contents($handle);
            fclose($handle);
            if (!empty($stdin)) {
                $input = $stdin;
            }
        }
    }
    
    if (!empty($input)) {
        // Handle webhook request
        echo "📥 Processing webhook request...\n";
        
        $update = json_decode($input, true);
        if ($update) {
            $result = $bot->handleWebhook($update);
            
            if ($result) {
                echo "✅ Webhook processed successfully\n";
                http_response_code(200);
                echo "OK";
            } else {
                echo "⚠️  Webhook processed with fallback\n";
                http_response_code(200);
                echo "OK";
            }
        } else {
            echo "❌ Invalid webhook data\n";
            http_response_code(400);
            echo "Bad Request";
        }
    } else {
        // CLI mode - show bot status
        echo "🖥️  CLI Mode - Bot Status Dashboard\n";
        echo "==================================\n\n";
        
        // Show service health
        $services = [
            'config' => 'ConfigManager',
            'logger' => 'Logger',
            'telegramApi' => 'TelegramAPI', 
            'userService' => 'UserService',
            'planService' => 'PlanService',
            'workflowService' => 'WorkflowService',
            'emailService' => 'EmailService',
            'commandManager' => 'CommandManager'
        ];
        
        echo "🔧 SERVICE STATUS:\n";
        echo "------------------\n";
        $healthyCount = 0;
        
        foreach ($services as $key => $name) {
            $health = $bot->getServiceHealth($key);
            $status = $health['status'] === 'healthy' ? '✅' : '❌';
            echo "$status $name: {$health['status']}\n";
            
            if ($health['status'] === 'healthy') {
                $healthyCount++;
            }
        }
        
        echo "\n📊 SUMMARY:\n";
        echo "-----------\n";
        echo "Services: $healthyCount/" . count($services) . " operational\n";
        
        // Show user stats
        $userStats = $bot->getUserStats();
        echo "Users: {$userStats['total']} total, {$userStats['active']} active\n";
        
        // Show available plans
        $plans = $bot->getAvailablePlans();
        echo "Plans: " . count($plans) . " available (" . implode(', ', array_keys($plans)) . ")\n";
        
        echo "\n✅ Bot is ready to receive webhook requests!\n";
        echo "🔗 Configure your Telegram webhook to point to this endpoint.\n\n";
        
        // Show recent logs
        if (file_exists('logs/bot.log')) {
            echo "📝 RECENT LOG ENTRIES:\n";
            echo "---------------------\n";
            $logs = file_get_contents('logs/bot.log');
            $logLines = explode("\n", $logs);
            $recentLogs = array_slice(array_filter($logLines), -5);
            
            foreach ($recentLogs as $log) {
                if (!empty(trim($log))) {
                    echo "  " . $log . "\n";
                }
            }
        }
    }
    
} catch (\Exception $e) {
    echo "💥 CRITICAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    
    // Log the error
    if (function_exists('error_log')) {
        error_log("Telegram Bot Critical Error: " . $e->getMessage());
    }
    
    if (!empty($input)) {
        // Webhook mode - return 500
        http_response_code(500);
        echo "Internal Server Error";
    } else {
        // CLI mode - exit with error code
        exit(1);
    }
}
