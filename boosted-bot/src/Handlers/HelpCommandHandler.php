<?php

namespace App\Handlers;

use App\Core\TelegramBotCore;

/**
 * Help Command Handler
 * 
 * Handles the /help command
 */
class HelpCommandHandler extends CommandHandler
{
    /**
     * @inheritdoc
     */
    protected $commands = ['help'];
    
    /**
     * @inheritdoc
     */
    protected $description = 'Show help information';
    
    /**
     * @inheritdoc
     */
    protected $usage = '/help [command]';
    
    /**
     * @inheritdoc
     */
    public function handle($chatId, $command, $args = [], $meta = [])
    {
        // Get user plan
        $userData = $this->bot->getUserData($chatId);
        $isAdmin = $this->bot->isAdmin($chatId);
        
        // Check if asking for help with specific command
        if (isset($args[0])) {
            return $this->showCommandHelp($chatId, $args[0], $isAdmin);
        }
        
        // General help
        $message = "📋 *ChetoBot Help*\n\n";
        $message .= "Here's what I can do for you:\n\n";
        
        // Core commands
        $message .= "*Basic Commands:*\n";
        $message .= "• /start - Start the bot\n";
        $message .= "• /help - Show this help message\n";
        $message .= "• /status - Check your account status\n";
        $message .= "• /stats - View your campaign statistics\n\n";
        
        // Campaign commands
        $message .= "*Campaign Commands:*\n";
        $message .= "• /campaign - Start a new campaign\n";
        $message .= "• /template - Upload HTML template\n";
        $message .= "• /list - Upload email list\n";
        $message .= "• /subject - Set email subject\n";
        $message .= "• /send - Start sending campaign\n";
        $message .= "• /pause - Pause active campaign\n";
        $message .= "• /resume - Resume paused campaign\n\n";
        
        // SMTP commands
        $message .= "*SMTP Commands:*\n";
        $message .= "• /smtp - Manage your SMTP settings\n";
        $message .= "• /smtp add - Add new SMTP\n";
        $message .= "• /smtp status - Check SMTP status\n\n";
        
        // Admin commands (only shown to admins)
        if ($isAdmin) {
            $message .= "*Admin Commands:*\n";
            $message .= "• /admin users - Manage users\n";
            $message .= "• /admin stats - View system statistics\n";
            $message .= "• /admin config - Update configuration\n";
            $message .= "• /admin broadcast - Send message to all users\n\n";
        }
        
        $message .= "Use /help [command] for more information about a specific command.";
        
        // Send help message
        $keyboard = [
            [
                ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Show help for a specific command
     * 
     * @param string $chatId Chat ID
     * @param string $command Command name
     * @param bool $isAdmin Admin status
     * @return bool Success status
     */
    private function showCommandHelp($chatId, $command, $isAdmin)
    {
        // Remove leading slash if present
        $command = ltrim($command, '/');
        
        // Define help data for commands
        $commands = [
            'start' => [
                'title' => 'Start Command',
                'description' => 'Starts the bot and shows the main menu.',
                'usage' => '/start',
                'examples' => ['/start'],
                'admin' => false
            ],
            'help' => [
                'title' => 'Help Command',
                'description' => 'Shows help information about available commands.',
                'usage' => '/help [command]',
                'examples' => ['/help', '/help campaign'],
                'admin' => false
            ],
            'status' => [
                'title' => 'Status Command',
                'description' => 'Shows your current account status, plan details, and limits.',
                'usage' => '/status',
                'examples' => ['/status'],
                'admin' => false
            ],
            'stats' => [
                'title' => 'Statistics Command',
                'description' => 'Shows statistics for your campaigns, including sent emails, delivery rates, and more.',
                'usage' => '/stats [campaign_id]',
                'examples' => ['/stats', '/stats 123'],
                'admin' => false
            ],
            'campaign' => [
                'title' => 'Campaign Command',
                'description' => 'Manages email campaigns. Start a new campaign, view existing ones, or check campaign status.',
                'usage' => '/campaign [action] [id]',
                'examples' => ['/campaign new', '/campaign list', '/campaign status 123'],
                'admin' => false
            ],
            'template' => [
                'title' => 'Template Command',
                'description' => 'Upload or manage HTML email templates.',
                'usage' => '/template',
                'examples' => ['/template'],
                'admin' => false
            ],
            'list' => [
                'title' => 'List Command',
                'description' => 'Upload or manage email lists. Email lists should be plain text files with one email address per line.',
                'usage' => '/list',
                'examples' => ['/list'],
                'admin' => false
            ],
            'subject' => [
                'title' => 'Subject Command',
                'description' => 'Set or update the subject line for your email campaign.',
                'usage' => '/subject [text]',
                'examples' => ['/subject', '/subject Special Offer Inside!'],
                'admin' => false
            ],
            'send' => [
                'title' => 'Send Command',
                'description' => 'Start sending your configured email campaign. Requires a template and email list to be set up first.',
                'usage' => '/send [speed]',
                'examples' => ['/send', '/send 100'],
                'admin' => false
            ],
            'pause' => [
                'title' => 'Pause Command',
                'description' => 'Pause an active email campaign. You can resume it later.',
                'usage' => '/pause [campaign_id]',
                'examples' => ['/pause', '/pause 123'],
                'admin' => false
            ],
            'resume' => [
                'title' => 'Resume Command',
                'description' => 'Resume a previously paused email campaign.',
                'usage' => '/resume [campaign_id]',
                'examples' => ['/resume', '/resume 123'],
                'admin' => false
            ],
            'smtp' => [
                'title' => 'SMTP Command',
                'description' => 'Manage SMTP settings for sending emails. Add new SMTP servers, check status, or remove them.',
                'usage' => '/smtp [action] [params]',
                'examples' => ['/smtp', '/smtp add', '/smtp status', '/smtp remove 1'],
                'admin' => false
            ],
            'admin' => [
                'title' => 'Admin Command',
                'description' => 'Administrative commands for managing the bot and users.',
                'usage' => '/admin [action] [params]',
                'examples' => ['/admin users', '/admin stats', '/admin config'],
                'admin' => true
            ]
        ];
        
        // Check if command exists
        if (!isset($commands[$command])) {
            $this->bot->sendTelegramMessage($chatId, "❓ Unknown command `{$command}`. Use /help to see available commands.", [
                'parse_mode' => 'Markdown'
            ]);
            return false;
        }
        
        // Check admin permissions
        if ($commands[$command]['admin'] && !$isAdmin) {
            $this->bot->sendTelegramMessage($chatId, "⛔ You don't have permission to access this command.", [
                'parse_mode' => 'Markdown'
            ]);
            return false;
        }
        
        // Build help message
        $help = $commands[$command];
        $message = "📚 *{$help['title']}*\n\n";
        $message .= "*Description:*\n{$help['description']}\n\n";
        $message .= "*Usage:*\n`{$help['usage']}`\n\n";
        
        if (!empty($help['examples'])) {
            $message .= "*Examples:*\n";
            foreach ($help['examples'] as $example) {
                $message .= "• `{$example}`\n";
            }
        }
        
        // Send help message
        $keyboard = [
            [
                ['text' => '📋 All Commands', 'callback_data' => 'help_all']
            ],
            [
                ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
}
