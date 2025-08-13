<?php

namespace App\Handlers;

use App\Core\TelegramBotCore;

/**
 * Start Command Handler
 * 
 * Handles the /start command
 */
class StartCommandHandler extends CommandHandler
{
    /**
     * @inheritdoc
     */
    protected $commands = ['start'];
    
    /**
     * @inheritdoc
     */
    protected $description = 'Start using the bot';
    
    /**
     * @inheritdoc
     */
    protected $usage = '/start';
    
    /**
     * @inheritdoc
     */
    public function handle($chatId, $command, $args = [], $meta = [])
    {
        // Check if this is a new user
        $userData = $this->bot->getUserData($chatId);
        $isNewUser = empty($userData);
        
        if ($isNewUser) {
            // Get user info from command meta data if available
            $firstName = $meta['first_name'] ?? 'there';
            
            // Create new user record
            $userData = [
                'chat_id' => $chatId,
                'first_name' => $firstName,
                'join_date' => time(),
                'plan' => 'free',
                'email_stats' => [
                    'total_sent' => 0,
                    'total_errors' => 0,
                    'campaigns' => 0
                ],
                'daily_stats' => [],
                'monthly_stats' => []
            ];
            
            // Save user data
            $this->bot->saveUserData($chatId, $userData);
            
            // Log new user
            $this->bot->log("New user registered: {$chatId} ({$firstName})", 'INFO');
            
            // Send welcome message
            $message = "👋 *Welcome to ChetoBot, {$firstName}!*\n\n";
            $message .= "I'm your email campaign assistant. I can help you manage your email campaigns, track performance, and more.\n\n";
            $message .= "To get started, use the menu below:";
        } else {
            // Get user's first name
            $firstName = $userData['first_name'] ?? 'there';
            
            // Send welcome back message
            $message = "👋 *Welcome back, {$firstName}!*\n\n";
            $message .= "What would you like to do today?";
        }
        
        // Show main menu
        $this->bot->showMainMenu($chatId);
        
        return true;
    }
}
