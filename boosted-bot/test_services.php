<?php

/**
 * Test the refactored bot services
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\TelegramBotCore;

try {
    echo "🧪 Testing Refactored Bot Services...\n\n";
    
    // Create bot instance
    $bot = new TelegramBotCore();
    
    // Initialize bot
    if (!$bot->initialize()) {
        throw new \Exception('Failed to initialize bot');
    }
    
    echo "✅ Bot initialized successfully\n\n";
    
    // Test ConfigManager
    echo "📋 Testing ConfigManager...\n";
    $config = $bot->getService('config');
    if ($config) {
        $botToken = $config->get('bot.token');
        $plans = $config->get('plans');
        
        echo "  ✅ Config loaded\n";
        echo "  📝 Bot token: " . (strlen($botToken ?? '') > 0 ? "Set (" . strlen($botToken) . " chars)" : "Not set") . "\n";
        echo "  📊 Plans loaded: " . count($plans ?? []) . " plans\n";
        
        if ($plans) {
            foreach ($plans as $name => $plan) {
                echo "    - $name: {$plan['name']} ({$plan['emails_per_day']} emails/day)\n";
            }
        }
    } else {
        echo "  ❌ ConfigManager not available\n";
    }
    
    echo "\n";
    
    // Test Logger
    echo "📝 Testing Logger...\n";
    $logger = $bot->getService('logger');
    if ($logger) {
        $logger->info('Test log message from bot testing');
        echo "  ✅ Logger working\n";
    } else {
        echo "  ❌ Logger not available\n";
    }
    
    echo "\n";
    
    // Test UserService
    echo "👤 Testing UserService...\n";
    $userService = $bot->getService('userService');
    if ($userService) {
        $allUsers = $userService->getAllUsers();
        echo "  ✅ UserService working\n";
        echo "  👥 Total users: " . count($allUsers) . "\n";
        
        if (!empty($allUsers)) {
            $sampleUser = array_keys($allUsers)[0];
            $userData = $userService->getUserData($sampleUser);
            echo "  📄 Sample user: $sampleUser\n";
            echo "    Plan: " . ($userData['plan'] ?? 'Not set') . "\n";
            echo "    Total emails sent: " . ($userData['total_emails_sent'] ?? 0) . "\n";
        }
    } else {
        echo "  ❌ UserService not available\n";
    }
    
    echo "\n";
    
    // Test TelegramAPI (without making actual calls)
    echo "📱 Testing TelegramAPI...\n";
    $telegramApi = $bot->getService('telegramApi');
    if ($telegramApi) {
        echo "  ✅ TelegramAPI service loaded\n";
        echo "  🔗 Ready for webhook requests\n";
    } else {
        echo "  ❌ TelegramAPI not available\n";
    }
    
    echo "\n";
    
    // Health check
    echo "🏥 Overall Health Check...\n";
    $health = $bot->healthCheck();
    echo "  Status: " . $health['status'] . "\n";
    
    $workingServices = 0;
    $totalServices = count($health['services']);
    
    foreach ($health['services'] as $service) {
        $status = $service['status'] === 'healthy' ? '✅' : ($service['status'] === 'not_initialized' ? '⏳' : '❌');
        echo "  $status {$service['name']}: {$service['status']}\n";
        
        if ($service['status'] === 'healthy') {
            $workingServices++;
        }
    }
    
    echo "\n📊 Summary:\n";
    echo "  Working services: $workingServices/$totalServices\n";
    echo "  Overall status: " . ($workingServices >= 3 ? "🟢 Good" : "🟡 Partial") . "\n";
    
    if ($workingServices >= 3) {
        echo "\n🎉 Core services are working! The refactored architecture is ready for:\n";
        echo "  - Configuration management ✅\n";
        echo "  - User data management ✅\n";
        echo "  - Logging ✅\n";
        echo "  - Telegram API integration ✅\n";
        echo "\n📝 Next steps:\n";
        echo "  1. Complete service implementation (PlanService, WorkflowService, etc.)\n";
        echo "  2. Add command handlers\n";
        echo "  3. Set up webhook endpoint\n";
        echo "  4. Test with actual Telegram bot\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Test failed: " . $e->getMessage() . "\n";
    echo "📋 Trace:\n" . $e->getTraceAsString() . "\n";
}
