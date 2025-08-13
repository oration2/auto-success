<?php

namespace App\Commands;

/**
 * Help Command
 * 
 * Handles /help command - shows available commands and usage information
 */
class HelpCommand extends BaseCommand
{
    protected $name = 'help';
    protected $description = 'Show available commands and usage information';
    protected $requiresAuth = false;

    /**
     * Execute the command
     * 
     * @param array $message Telegram message
     * @param array $context Command context
     * @return array Response data
     */
    public function execute($message, $context = [])
    {
        $chatId = $message['chat']['id'] ?? '';
        
        if (empty($chatId)) {
            return ['success' => false, 'error' => 'Invalid chat ID'];
        }

        $this->logExecution($chatId, $context);
        $this->sendTyping($chatId);

        try {
            $helpMessage = $this->getHelpMessage($chatId);
            
            $result = $this->sendMessage($chatId, $helpMessage, [
                'parse_mode' => 'Markdown'
            ]);

            return [
                'success' => $result['success'] ?? false,
                'command' => 'help'
            ];

        } catch (\Exception $e) {
            $this->logger?->error('Help command failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);

            $this->sendMessage($chatId, $this->getUserFriendlyError('internal_error'));
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get help message content
     * 
     * @param string $chatId Chat ID for permission checks
     * @return string Help message
     */
    private function getHelpMessage($chatId)
    {
        $helpMessage = "❓ *Help & Commands*\n\n";
        $helpMessage .= "Welcome to the Email Campaign Bot! Here are the available commands:\n\n";
        
        // Basic commands (available to all users)
        $helpMessage .= "*📋 Basic Commands:*\n";
        $helpMessage .= "/start - Start the bot and show welcome message\n";
        $helpMessage .= "/help - Show this help message\n";
        $helpMessage .= "/status - Check your account status and statistics\n\n";
        
        // Check if user exists for additional commands
        $userData = $this->userService?->getUserData($chatId);
        if ($userData) {
            $helpMessage .= "*📤 Campaign Commands:*\n";
            $helpMessage .= "/send - Send email campaign\n";
            $helpMessage .= "/upload - Upload email list and template files\n";
            $helpMessage .= "/smtp - Configure SMTP settings\n";
            $helpMessage .= "/template - Manage email templates\n\n";
            
            $helpMessage .= "*💎 Account Commands:*\n";
            $helpMessage .= "/plans - View and purchase plans\n\n";
        }
        
        // Admin commands
        if ($this->isAdmin($chatId)) {
            $helpMessage .= "*⚙️ Admin Commands:*\n";
            $helpMessage .= "/admin - Admin panel\n";
            $helpMessage .= "/stats - System statistics\n";
            $helpMessage .= "/broadcast - Send broadcast message\n\n";
        }
        
        $helpMessage .= "*🎯 Quick Start Guide:*\n";
        $helpMessage .= "1. Use /start to initialize your account\n";
        $helpMessage .= "2. Upload your email list with /upload\n";
        $helpMessage .= "3. Configure SMTP with /smtp\n";
        $helpMessage .= "4. Send campaign with /send\n\n";
        
        $helpMessage .= "*📞 Support:*\n";
        $helpMessage .= "Contact: @Ninja111\n";
        $helpMessage .= "Bot: @Cheto_inboxing_bot\n\n";
        
        $helpMessage .= "💡 Tip: You can also use the keyboard buttons below for quick access!";

        return $helpMessage;
    }

    /**
     * Get help keyboard markup
     * 
     * @return array Keyboard markup
     */
    private function getHelpKeyboard()
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🏠 Main Menu', 'callback_data' => 'main_menu'],
                    ['text' => '📊 My Status', 'callback_data' => 'status']
                ],
                [
                    ['text' => '📤 Send Campaign', 'callback_data' => 'send_campaign'],
                    ['text' => '💎 Plans', 'callback_data' => 'plans']
                ],
                [
                    ['text' => '📁 Upload Files', 'callback_data' => 'upload'],
                    ['text' => '⚙️ SMTP Config', 'callback_data' => 'smtp']
                ]
            ]
        ];
    }
}