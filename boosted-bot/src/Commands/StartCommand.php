<?php

namespace App\Commands;

use App\Services\PlanService;

/**
 * Start Command
 * 
 * Handles /start command
 */
class StartCommand extends BaseCommand
{
    protected $name = 'start';
    protected $description = 'Start the bot and show welcome message';
    protected $requiresAuth = false;
    
    private $planService;

    public function __construct($config = null, $logger = null, $telegramApi = null, $userService = null, PlanService $planService = null)
    {
        parent::__construct($config, $logger, $telegramApi, $userService);
        $this->planService = $planService;
    }

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
            // Check if user already exists
            $userData = $this->userService?->getUserData($chatId);
            $isNewUser = $userData === null;

            if ($isNewUser) {
                // Create new user with trial plan
                $this->createNewUser($chatId, $message);
                $welcomeMessage = $this->getWelcomeMessage();
            } else {
                // Existing user - show status
                $welcomeMessage = $this->getWelcomeBackMessage($userData);
            }

            // Send welcome message
            $result = $this->sendMessage($chatId, $welcomeMessage, [
                'parse_mode' => 'Markdown',
                'reply_markup' => $this->getMainKeyboard()
            ]);

            return [
                'success' => $result['success'] ?? false,
                'is_new_user' => $isNewUser
            ];

        } catch (\Exception $e) {
            $this->logger?->error('Start command failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);

            $this->sendMessage($chatId, $this->getUserFriendlyError('internal_error'));
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create new user
     * 
     * @param string $chatId Chat ID
     * @param array $message Telegram message
     */
    private function createNewUser($chatId, $message)
    {
        $user = $message['from'] ?? [];
        
        $userData = [
            'chat_id' => $chatId,
            'username' => $user['username'] ?? '',
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'plan' => 'trial',
            'plan_expires' => time() + (3 * 24 * 3600), // 3 days trial
            'created_at' => time(),
            'last_activity' => time(),
            'total_emails_sent' => 0,
            'daily_stats' => [],
            'hourly_stats' => [],
            'smtp_configs' => [],
            'files' => [],
            'campaigns' => []
        ];

        $this->userService?->updateUserData($chatId, $userData);
        
        $this->logger?->info('New user created', [
            'chat_id' => $chatId,
            'username' => $userData['username']
        ]);
    }

    /**
     * Get welcome message for new users
     * 
     * @return string Welcome message
     */
    private function getWelcomeMessage()
    {
        return "🎉 *Welcome to Email Campaign Bot!*\n\n" .
               "I'll help you send professional email campaigns efficiently.\n\n" .
               "🎁 You've been granted a *3-day trial* with:\n" .
               "• Up to 100 emails per hour\n" .
               "• Up to 500 emails per day\n" .
               "• HTML email support\n" .
               "• File attachments\n\n" .
               "📋 *Quick Start:*\n" .
               "1. Upload your email list (/upload)\n" .
               "2. Create HTML template (/template)\n" .
               "3. Configure SMTP (/smtp)\n" .
               "4. Send campaign (/send)\n\n" .
               "💡 Use /help to see all available commands or choose from the menu below.";
    }

    /**
     * Get welcome back message for existing users
     * 
     * @param array $userData User data
     * @return string Welcome message
     */
    private function getWelcomeBackMessage($userData)
    {
        // Get plan info if planService is available
        $plan = null;
        if ($this->planService) {
            $plan = $this->planService->getUserPlan($userData['chat_id']);
            $planStatus = $plan && isset($plan['expired']) && !$plan['expired'] ? "✅ Active" : "❌ Expired";
        } else {
            // Fallback when planService is not available
            $planStatus = isset($userData['plan_expires']) && $userData['plan_expires'] > time() ? "✅ Active" : "❌ Expired";
        }
        
        $totalSent = $userData['total_emails_sent'] ?? 0;
        $todaySent = isset($userData['daily_stats'][date('Y-m-d')]) ? $userData['daily_stats'][date('Y-m-d')] : 0;

        return "👋 *Welcome back!*\n\n" .
               "📊 *Your Status:*\n" .
               "Plan: " . ($plan['display_name'] ?? 'Unknown') . " ($planStatus)\n" .
               "Total emails sent: " . number_format($totalSent) . "\n" .
               "Today's emails: " . number_format($todaySent) . "\n\n" .
               "💡 What would you like to do today?";
    }

    /**
     * Get main keyboard markup
     * 
     * @return array Keyboard markup
     */
    private function getMainKeyboard()
    {
        return [
            'keyboard' => [
                [
                    ['text' => '📤 Send Campaign'],
                    ['text' => '📊 My Status']
                ],
                [
                    ['text' => '📁 Upload Files'],
                    ['text' => '⚙️ SMTP Config']
                ],
                [
                    ['text' => '💎 Plans & Pricing'],
                    ['text' => '❓ Help']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
    }
}
