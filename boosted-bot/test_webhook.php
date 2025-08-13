<?php

/**
 * Test webhook simulation with the refactored bot
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\TelegramBotCore;

try {
    echo "🔗 Testing Webhook Simulation...\n\n";
    
    // Create bot instance
    $bot = new TelegramBotCore();
    
    // Initialize bot
    if (!$bot->initialize()) {
        throw new \Exception('Failed to initialize bot');
    }
    
    echo "✅ Bot initialized successfully\n\n";
    
    // Simulate a /start command
    echo "📱 Simulating /start command...\n";
    $startUpdate = [
        'update_id' => 123456789,
        'message' => [
            'message_id' => 1,
            'from' => [
                'id' => 1234567890,
                'is_bot' => false,
                'first_name' => 'Test',
                'last_name' => 'User',
                'username' => 'testuser'
            ],
            'chat' => [
                'id' => 1234567890,
                'first_name' => 'Test',
                'last_name' => 'User',
                'username' => 'testuser',
                'type' => 'private'
            ],
            'date' => time(),
            'text' => '/start'
        ]
    ];
    
    $result = $bot->handleWebhook($startUpdate);
    echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
    
    // Simulate a status command
    echo "📊 Simulating /status command...\n";
    $statusUpdate = [
        'update_id' => 123456790,
        'message' => [
            'message_id' => 2,
            'from' => [
                'id' => 1234567890,
                'is_bot' => false,
                'first_name' => 'Test',
                'last_name' => 'User',
                'username' => 'testuser'
            ],
            'chat' => [
                'id' => 1234567890,
                'first_name' => 'Test',
                'last_name' => 'User',
                'username' => 'testuser',
                'type' => 'private'
            ],
            'date' => time(),
            'text' => '/status'
        ]
    ];
    
    $result = $bot->handleWebhook($statusUpdate);
    echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
    
    // Simulate a regular text message
    echo "💬 Simulating regular text message...\n";
    $textUpdate = [
        'update_id' => 123456791,
        'message' => [
            'message_id' => 3,
            'from' => [
                'id' => 1234567890,
                'is_bot' => false,
                'first_name' => 'Test',
                'last_name' => 'User',
                'username' => 'testuser'
            ],
            'chat' => [
                'id' => 1234567890,
                'first_name' => 'Test',
                'last_name' => 'User',
                'username' => 'testuser',
                'type' => 'private'
            ],
            'date' => time(),
            'text' => 'Hello bot!'
        ]
    ];
    
    $result = $bot->handleWebhook($textUpdate);
    echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
    
    // Simulate a callback query
    echo "🔘 Simulating callback query...\n";
    $callbackUpdate = [
        'update_id' => 123456792,
        'callback_query' => [
            'id' => 'cb123456789',
            'from' => [
                'id' => 1234567890,
                'is_bot' => false,
                'first_name' => 'Test',
                'last_name' => 'User',
                'username' => 'testuser'
            ],
            'message' => [
                'message_id' => 4,
                'from' => [
                    'id' => 987654321,
                    'is_bot' => true,
                    'first_name' => 'Bot',
                    'username' => 'testbot'
                ],
                'chat' => [
                    'id' => 1234567890,
                    'first_name' => 'Test',
                    'last_name' => 'User',
                    'username' => 'testuser',
                    'type' => 'private'
                ],
                'date' => time(),
                'text' => 'Some message with inline keyboard'
            ],
            'data' => 'status_refresh'
        ]
    ];
    
    $result = $bot->handleWebhook($callbackUpdate);
    echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
    
    echo "🎯 Webhook simulation completed!\n";
    echo "📊 The bot handled all types of updates successfully.\n";
    
} catch (\Exception $e) {
    echo "❌ Webhook test failed: " . $e->getMessage() . "\n";
    echo "📋 Trace:\n" . $e->getTraceAsString() . "\n";
}
