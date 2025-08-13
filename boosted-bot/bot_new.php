<?php

/**
 * Telegram Bot Entry Point
 * 
 * Simple entry point for the refactored bot architecture
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\TelegramBotCore;

try {
    // Create bot instance
    $bot = new TelegramBotCore();
    
    // Initialize bot
    if (!$bot->initialize()) {
        throw new \Exception('Failed to initialize bot');
    }
    
    // Handle webhook if called via HTTP
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $update = json_decode($input, true);
        
        if ($update) {
            $result = $bot->handleWebhook($update);
            
            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode($result);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON input']);
        }
    } else {
        // Called via CLI - show status
        echo "✅ Telegram Bot initialized successfully\n";
        
        // Perform health check
        $health = $bot->healthCheck();
        echo "🏥 Health Status: " . $health['status'] . "\n";
        
        if ($health['status'] !== 'healthy') {
            echo "⚠️  Issues detected:\n";
            foreach ($health['services'] as $service) {
                if ($service['status'] !== 'healthy') {
                    echo "  - {$service['name']}: {$service['status']}\n";
                    if (isset($service['error'])) {
                        echo "    Error: {$service['error']}\n";
                    }
                }
            }
        }
        
        echo "\n📋 Available services:\n";
        foreach ($health['services'] as $service) {
            $status = $service['status'] === 'healthy' ? '✅' : '❌';
            echo "  $status {$service['name']}\n";
        }
        
        echo "\n🔗 Use this URL for webhook: https://yourdomain.com" . $_SERVER['PHP_SELF'] . "\n";
    }

} catch (\Exception $e) {
    // Log error
    error_log('Bot error: ' . $e->getMessage());
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
    } else {
        echo "❌ Bot initialization failed: " . $e->getMessage() . "\n";
        echo "📋 Trace:\n" . $e->getTraceAsString() . "\n";
    }
}
