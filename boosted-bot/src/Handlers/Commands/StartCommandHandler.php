<?php

namespace App\Handlers\Commands;

use App\Handlers\CommandHandler;

/**
 * Start Command Handler
 * 
 * Handles the /start command
 */
class StartCommandHandler extends CommandHandler
{
    protected $command = 'start';
    protected $description = 'Start the bot';
    protected $usage = '/start';
    protected $adminOnly = false;
    
    /**
     * Handle the /start command
     * 
     * @param string $chatId Chat ID
     * @param string $message Message text
     * @param array $params Additional parameters
     * @return bool Success status
     */
    public function handle($chatId, $message, $params = [])
    {
        // Get user data or initialize
        $userData = $this->bot->getUserData($chatId) ?: [];
        $isNewUser = empty($userData);
        
        // Update user data with basic info
        if (!isset($userData['chat_id'])) {
            $userData['chat_id'] = $chatId;
        }
        
        if (!isset($userData['start_date'])) {
            $userData['start_date'] = date('Y-m-d H:i:s');
        }
        
        if (!isset($userData['plan'])) {
            $userData['plan'] = 'trial';
        }
        
        // Initialize email stats if needed
        if (!isset($userData['email_stats'])) {
            $userData['email_stats'] = [
                'total_sent' => 0,
                'total_errors' => 0,
                'campaigns' => 0,
                'last_campaign' => null
            ];
        }
        
        // Save updated user data
        $this->bot->saveUserData($chatId, $userData);
        
        // Welcome message
        $welcomeMessage = "👋 *Welcome to ChetoBot!*\n\n";
        
        if ($isNewUser) {
            $welcomeMessage .= "Thanks for starting the bot. This tool helps you send email campaigns efficiently.\n\n";
            $welcomeMessage .= "You've been assigned a free trial plan to get started.";
        } else {
            $welcomeMessage .= "Welcome back! Your email assistant is ready to help you.";
        }
        
        // Send welcome message
        $this->bot->sendTelegramMessage($chatId, $welcomeMessage, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '📧 Set Up Campaign', 'callback_data' => 'setup_menu']
                    ],
                    [
                        ['text' => '📊 My Account', 'callback_data' => 'account_info']
                    ],
                    [
                        ['text' => '🛠 Settings', 'callback_data' => 'settings_menu']
                    ]
                ]
            ])
        ]);
        
        return true;
    }
}
