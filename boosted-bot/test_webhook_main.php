<?php

// Simulate a webhook request to the main bot

$webhookData = [
    'update_id' => 999999,
    'message' => [
        'message_id' => 999,
        'chat' => ['id' => 123456789],
        'from' => ['id' => 123456789, 'first_name' => 'Production', 'username' => 'produser'],
        'text' => '/start',
        'date' => time()
    ]
];

// Create a temporary file to simulate webhook input
$tempFile = tempnam(sys_get_temp_dir(), 'webhook_');
file_put_contents($tempFile, json_encode($webhookData));

echo "🔗 Testing webhook endpoint with main bot...\n";
echo "============================================\n\n";

// Simulate webhook request
$command = "cd /workspaces/auto-success/boosted-bot && php telegram_bot_new.php < $tempFile";
$output = shell_exec($command);

echo "📤 Webhook data sent:\n";
echo json_encode($webhookData, JSON_PRETTY_PRINT) . "\n\n";

echo "📥 Bot response:\n";
echo "----------------\n";
echo $output;

// Clean up
unlink($tempFile);

echo "\n✅ Webhook test completed!\n";
