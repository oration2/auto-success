<?php

require_once 'vendor/autoload.php';

if (!defined('BOT_TOKEN')) {
    include 'config/config.php';
}

echo "🧪 TESTING MESSAGE SENDING\n";
echo "==========================\n\n";

$chatId = '1998854061'; // Your chat ID from the logs
$testMessage = "🤖 *Test Message from BoostedBot!*\n\nThis is a test to verify message sending is working correctly.\n\nTime: " . date('Y-m-d H:i:s');

echo "Sending test message to chat ID: $chatId\n";
echo "Message: $testMessage\n\n";

// Direct API call
$url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
$data = [
    'chat_id' => $chatId,
    'text' => $testMessage,
    'parse_mode' => 'Markdown'
];

$options = [
    'http' => [
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'method' => 'POST',
        'content' => http_build_query($data)
    ]
];

$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);
$response = json_decode($result, true);

if ($response && $response['ok']) {
    echo "✅ Message sent successfully!\n";
    echo "Message ID: " . $response['result']['message_id'] . "\n";
    echo "📱 Check your Telegram to see the message!\n";
} else {
    echo "❌ Failed to send message\n";
    echo "Error: " . ($response['description'] ?? 'Unknown error') . "\n";
    echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
}
