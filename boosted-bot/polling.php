<?php

require_once 'vendor/autoload.php';

use App\Core\TelegramBotCore;

echo "🔄 TELEGRAM BOT POLLING MODE\n";
echo "============================\n\n";

// Load configuration
if (!defined('BOT_TOKEN')) {
    include 'config/config.php';
}

if (!defined('BOT_TOKEN') || empty(BOT_TOKEN)) {
    echo "❌ Error: BOT_TOKEN not configured\n";
    exit(1);
}

echo "🤖 Bot: @Cheto_inboxing_bot\n";
echo "🔑 Token: " . substr(BOT_TOKEN, 0, 10) . "...\n";
echo "⏱️  Polling for updates... (Press Ctrl+C to stop)\n\n";

// Initialize bot with full initialization
$bot = new TelegramBotCore();
$bot->initialize(); // Make sure all services are properly initialized

$offset = 0;
$timeout = 10;

while (true) {
    try {
        // Get updates from Telegram
        $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getUpdates?offset=$offset&timeout=$timeout";
        $response = @file_get_contents($url);
        
        if ($response === false) {
            echo "⚠️  Network error, retrying...\n";
            sleep(5);
            continue;
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !$data['ok']) {
            echo "❌ API error: " . ($data['description'] ?? 'Unknown') . "\n";
            sleep(5);
            continue;
        }
        
        $updates = $data['result'];
        
        foreach ($updates as $update) {
            $offset = $update['update_id'] + 1;
            
            echo "📥 Update ID: {$update['update_id']}\n";
            
            // Process the update with our bot
            try {
                $result = $bot->handleWebhook($update);
                
                if ($result) {
                    echo "✅ Processed successfully\n";
                } else {
                    echo "⚠️  Processed with fallback\n";
                }
                
                // Show message details
                if (isset($update['message'])) {
                    $msg = $update['message'];
                    $from = $msg['from']['first_name'] ?? 'Unknown';
                    $username = isset($msg['from']['username']) ? '@' . $msg['from']['username'] : '';
                    $text = $msg['text'] ?? '[No text]';
                    $chatId = $msg['chat']['id'];
                    
                    echo "👤 From: $from $username (ID: $chatId)\n";
                    echo "💬 Message: $text\n";
                }
                
                if (isset($update['callback_query'])) {
                    $callback = $update['callback_query'];
                    $from = $callback['from']['first_name'] ?? 'Unknown';
                    $data = $callback['data'] ?? '[No data]';
                    
                    echo "🔘 Callback from: $from\n";
                    echo "📋 Data: $data\n";
                }
                
                echo "---\n";
                
            } catch (\Exception $e) {
                echo "❌ Error processing update: " . $e->getMessage() . "\n";
                echo "---\n";
            }
        }
        
        if (empty($updates)) {
            echo "⏳ Waiting for messages...\n";
        }
        
    } catch (\Exception $e) {
        echo "💥 Polling error: " . $e->getMessage() . "\n";
        sleep(5);
    }
}
