<?php

require_once 'vendor/autoload.php';

use App\Core\TelegramBotCore;

echo "🔗 TELEGRAM WEBHOOK SETUP\n";
echo "=========================\n\n";

// Load configuration
include 'config/config.php';

if (!defined('BOT_TOKEN') || empty(BOT_TOKEN)) {
    echo "❌ Error: BOT_TOKEN not configured in config/config.php\n";
    exit(1);
}

echo "✅ Bot token loaded: " . substr(BOT_TOKEN, 0, 10) . "...\n\n";

// Get webhook URL from user input
echo "📝 Please enter your webhook URL (e.g., https://yourdomain.com/telegram_bot_new.php):\n";
echo "   Or press Enter to remove webhook (for local testing)\n";
echo "URL: ";

$webhookUrl = trim(fgets(STDIN));

if (empty($webhookUrl)) {
    echo "\n🔄 Removing webhook (for local testing)...\n";
    $apiUrl = "https://api.telegram.org/bot" . BOT_TOKEN . "/deleteWebhook";
} else {
    echo "\n🔄 Setting webhook to: $webhookUrl\n";
    $apiUrl = "https://api.telegram.org/bot" . BOT_TOKEN . "/setWebhook?url=" . urlencode($webhookUrl);
}

// Make API call
$response = file_get_contents($apiUrl);
$result = json_decode($response, true);

if ($result && $result['ok']) {
    if (empty($webhookUrl)) {
        echo "✅ Webhook removed successfully!\n";
        echo "📱 You can now test the bot locally or use polling mode.\n\n";
    } else {
        echo "✅ Webhook set successfully!\n";
        echo "📱 Your bot is now ready to receive messages at: $webhookUrl\n\n";
    }
    
    // Get webhook info
    echo "🔍 Current webhook status:\n";
    $infoUrl = "https://api.telegram.org/bot" . BOT_TOKEN . "/getWebhookInfo";
    $infoResponse = file_get_contents($infoUrl);
    $infoResult = json_decode($infoResponse, true);
    
    if ($infoResult && $infoResult['ok']) {
        $info = $infoResult['result'];
        echo "   URL: " . ($info['url'] ?: 'Not set') . "\n";
        echo "   Pending updates: " . ($info['pending_update_count'] ?? 0) . "\n";
        echo "   Last error: " . ($info['last_error_message'] ?? 'None') . "\n";
        if (isset($info['last_error_date'])) {
            echo "   Last error date: " . date('Y-m-d H:i:s', $info['last_error_date']) . "\n";
        }
    }
    
} else {
    echo "❌ Failed to set webhook!\n";
    echo "Error: " . ($result['description'] ?? 'Unknown error') . "\n";
    exit(1);
}

echo "\n📋 NEXT STEPS:\n";
echo "--------------\n";

if (empty($webhookUrl)) {
    echo "1. For local testing:\n";
    echo "   • Run: php -S localhost:8080 telegram_bot_new.php\n";
    echo "   • Use ngrok or similar to expose to internet\n";
    echo "   • Or test using polling mode\n\n";
    
    echo "2. For production:\n";
    echo "   • Deploy telegram_bot_new.php to your web server\n";
    echo "   • Run this script again with your public URL\n";
} else {
    echo "1. ✅ Test your bot by sending /start to your Telegram bot\n";
    echo "2. ✅ Check logs at logs/bot.log for any issues\n";
    echo "3. ✅ Monitor the CLI dashboard: php telegram_bot_new.php\n";
}

echo "\n🎉 Setup complete! Your bot is ready to use.\n";
