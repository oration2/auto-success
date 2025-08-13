<?php

namespace App\Core;

use App\Services\ConfigManager;
use App\Services\Logger;
use App\Services\TelegramAPI;
use App\Services\UserService;
use App\Services\PlanService;
use App\Services\WorkflowService;
use App\Services\EmailService;
use App\Services\CommandManager;

/**
 * Telegram Bot Core
 * 
 * Main bot class that orchestrates all services and handles webhook requests
 */
class TelegramBotCore
{
    private $config;
    private $logger;
    private $telegramApi;
    private $userService;
    private $planService;
    private $workflowService;
    private $emailService;
    private $commandManager;
    
    private $initialized = false;
    private $services = [];

    public function __construct()
    {
        $this->initializeServices();
    }

    /**
     * Initialize all services with dependency injection
     */
    private function initializeServices()
    {
        try {
            // Core services
            $configFile = __DIR__ . '/../../config/config.php';
            $userFile = __DIR__ . '/../../config/users.json';
            
            $this->config = new ConfigManager($configFile, $userFile);
            $this->logger = new Logger(__DIR__ . '/../../logs/bot.log');
            
            // Get bot token for TelegramAPI
            $botToken = $this->config->get('bot_token', BOT_TOKEN ?? '');
            $this->telegramApi = new TelegramAPI($botToken, 'bot', $this->logger);
            
            // Business services (fully implemented)
            $this->userService = new UserService($this->config, $this->logger);
            
            // Initialize all services
            $this->planService = new PlanService($this->config, $this->logger);
            $this->workflowService = new WorkflowService(
                $this->config, 
                $this->logger, 
                $this->userService, 
                $this->planService,
                null // SmtpManager placeholder
            );
            $this->emailService = new EmailService(
                $this->config,
                $this->logger,
                $this->planService,
                $this->userService,
                $this->workflowService
            );
            $this->commandManager = new CommandManager(
                $this->config,
                $this->logger,
                $this->telegramApi,
                $this->userService
            );
            
            // Manually inject additional services required by commands
            $this->commandManager->addService('planService', $this->planService);
            $this->commandManager->addService('workflowService', $this->workflowService);
            $this->commandManager->addService('emailService', $this->emailService);

            // Store services for easy access
            $this->services = [
                'config' => $this->config,
                'logger' => $this->logger,
                'telegramApi' => $this->telegramApi,
                'userService' => $this->userService,
                'planService' => $this->planService,
                'workflowService' => $this->workflowService,
                'emailService' => $this->emailService,
                'commandManager' => $this->commandManager
            ];

            $this->logger->info('TelegramBotCore services initialized');

        } catch (\Exception $e) {
            error_log('Failed to initialize TelegramBotCore: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Initialize the bot and all services
     * 
     * @return bool Success status
     */
    public function initialize()
    {
        if ($this->initialized) {
            return true;
        }

        try {
            $this->logger->info('Initializing TelegramBotCore...');

            // Initialize only available services
            $this->initialized = true;
            $this->logger->info('TelegramBotCore fully initialized');
            
            return true;

        } catch (\Exception $e) {
            $this->logger->error('TelegramBotCore initialization failed', [
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Handle incoming webhook request
     * 
     * @param array $update Telegram update data
     * @return array Response data
     */
    public function handleWebhook($update)
    {
        if (!$this->initialized) {
            if (!$this->initialize()) {
                return ['success' => false, 'error' => 'Bot not initialized'];
            }
        }

        try {
            $this->logger->info('Processing webhook update', ['update_id' => $update['update_id'] ?? 'unknown']);

            // Determine update type and route accordingly
            if (isset($update['message'])) {
                return $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                return $this->handleCallbackQuery($update['callback_query']);
            } elseif (isset($update['inline_query'])) {
                return $this->handleInlineQuery($update['inline_query']);
            } else {
                $this->logger->warning('Unhandled update type', ['update' => $update]);
                return ['success' => false, 'error' => 'Unhandled update type'];
            }

        } catch (\Exception $e) {
            $this->logger->error('Webhook handling failed', [
                'update' => $update,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return ['success' => false, 'error' => 'Internal error'];
        }
    }

    /**
     * Handle incoming message
     * 
     * @param array $message Telegram message
     * @return array Response data
     */
    private function handleMessage($message)
    {
        try {
            $chatId = $message['chat']['id'] ?? '';
            $messageId = $message['message_id'] ?? '';
            $text = $message['text'] ?? '';
            
            $this->logger->debug('Handling message', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => substr($text, 0, 100)
            ]);

            // If CommandManager is available, use it
            if ($this->commandManager) {
                $result = $this->commandManager->handleMessage($message);
            } else {
                // Fallback handling when CommandManager is not initialized
                $result = $this->handleMessageFallback($message);
            }
            
            $this->logger->info('Message processed', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'success' => $result['success'] ?? false
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Message handling failed', [
                'message' => $message,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => 'Message handling failed'];
        }
    }

    /**
     * Handle callback query (inline keyboard buttons)
     * 
     * @param array $callbackQuery Callback query data
     * @return array Response data
     */
    private function handleCallbackQuery($callbackQuery)
    {
        try {
            $this->logger->debug('Handling callback query', [
                'query_id' => $callbackQuery['id'] ?? '',
                'data' => $callbackQuery['data'] ?? ''
            ]);

            // If CommandManager is available, use it
            if ($this->commandManager) {
                $result = $this->commandManager->handleCallbackQuery($callbackQuery);
            } else {
                // Fallback handling
                $result = [
                    'success' => true,
                    'type' => 'callback_query',
                    'response' => 'Callback received (fallback mode)',
                    'data' => $callbackQuery['data'] ?? ''
                ];
            }
            
            $this->logger->info('Callback query processed', [
                'query_id' => $callbackQuery['id'] ?? '',
                'success' => $result['success'] ?? false
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Callback query handling failed', [
                'callback_query' => $callbackQuery,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => 'Callback query handling failed'];
        }
    }

    /**
     * Handle inline query
     * 
     * @param array $inlineQuery Inline query data
     * @return array Response data
     */
    private function handleInlineQuery($inlineQuery)
    {
        try {
            $queryId = $inlineQuery['id'] ?? '';
            $query = $inlineQuery['query'] ?? '';
            
            $this->logger->debug('Handling inline query', [
                'query_id' => $queryId,
                'query' => $query
            ]);

            // For now, return empty results
            $this->telegramApi->answerInlineQuery($queryId, []);
            
            return ['success' => true, 'type' => 'inline_query'];

        } catch (\Exception $e) {
            $this->logger->error('Inline query handling failed', [
                'inline_query' => $inlineQuery,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => 'Inline query handling failed'];
        }
    }

    /**
     * Get service instance
     * 
     * @param string $serviceName Service name
     * @return object|null Service instance
     */
    public function getService($serviceName)
    {
        return $this->services[$serviceName] ?? null;
    }

    /**
     * Health check
     * 
     * @return array Health status
     */
    public function healthCheck()
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => time(),
            'services' => []
        ];

        try {
            // Check each service
            foreach ($this->services as $name => $service) {
                $serviceHealth = [
                    'name' => $name,
                    'status' => 'unknown'
                ];

                try {
                    if ($service && method_exists($service, 'healthCheck')) {
                        $serviceHealth = array_merge($serviceHealth, $service->healthCheck());
                    } else {
                        $serviceHealth['status'] = $service ? 'healthy' : 'not_initialized';
                    }
                } catch (\Exception $e) {
                    $serviceHealth['status'] = 'unhealthy';
                    $serviceHealth['error'] = $e->getMessage();
                    $health['status'] = 'degraded';
                }

                $health['services'][] = $serviceHealth;
            }

        } catch (\Exception $e) {
            $health['status'] = 'unhealthy';
            $health['error'] = $e->getMessage();
        }

        return $health;
    }

    /**
     * Fallback message handling when CommandManager is not available
     * 
     * @param array $message Telegram message
     * @return array Response data
     */
    private function handleMessageFallback($message)
    {
        $chatId = $message['chat']['id'] ?? '';
        $text = $message['text'] ?? '';
        
        // Basic command recognition
        if (str_starts_with($text, '/start')) {
            return $this->handleStartCommand($chatId, $message);
        } elseif (str_starts_with($text, '/status')) {
            return $this->handleStatusCommand($chatId, $message);
        } elseif (str_starts_with($text, '/help')) {
            return $this->handleHelpCommand($chatId, $message);
        } else {
            // Default response
            return [
                'success' => true,
                'response' => 'Message received (fallback mode). CommandManager not initialized.',
                'type' => 'fallback'
            ];
        }
    }

    /**
     * Handle /start command (fallback)
     */
    private function handleStartCommand($chatId, $message)
    {
        $this->logger->info('Handling /start command (fallback)', ['chat_id' => $chatId]);
        
        // Try to get/create user data
        if ($this->userService) {
            $userData = $this->userService->getUserData($chatId);
            if (!$userData) {
                // Create new user
                $user = $message['from'] ?? [];
                $newUserData = [
                    'chat_id' => $chatId,
                    'username' => $user['username'] ?? '',
                    'first_name' => $user['first_name'] ?? '',
                    'last_name' => $user['last_name'] ?? '',
                    'plan' => 'trial',
                    'plan_expires' => time() + (3 * 24 * 3600), // 3 days trial
                    'created_at' => time(),
                    'last_activity' => time(),
                    'total_emails_sent' => 0
                ];
                
                $this->userService->updateUser($chatId, $newUserData);
                $this->logger->info('New user created (fallback)', ['chat_id' => $chatId]);
                $userData = $newUserData;
            }
        }
        
        $welcomeMessage = "🎉 *Welcome to BoostedBot!* 🤖\n\n";
        $welcomeMessage .= "Your powerful email campaign assistant is ready to help you reach your audience effectively.\n\n";
        $welcomeMessage .= "*Your Current Plan:* " . ucfirst($userData['plan'] ?? 'trial') . "\n\n";
        
        if ($this->planService) {
            $plan = $this->planService->getUserPlan($userData);
            if ($plan) {
                $emailLimit = $plan['emails_per_day'] === -1 ? 'Unlimited' : number_format($plan['emails_per_day']);
                $welcomeMessage .= "*Daily Email Limit:* {$emailLimit}\n";
            }
        }
        
        $welcomeMessage .= "\n*What would you like to do?*";
        
        $keyboard = [
            [
                ['text' => '📤 Send Campaign', 'callback_data' => 'send_campaign'],
                ['text' => '📊 My Status', 'callback_data' => 'status']
            ],
            [
                ['text' => '📁 Upload Files', 'callback_data' => 'upload'],
                ['text' => '⚙️ SMTP Config', 'callback_data' => 'smtp']
            ],
            [
                ['text' => '💎 Plans & Pricing', 'callback_data' => 'plans'],
                ['text' => '❓ Help', 'callback_data' => 'help']
            ]
        ];
        
        $this->telegramApi->sendMessage($chatId, $welcomeMessage, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return [
            'success' => true,
            'command' => 'start',
            'response' => 'Welcome message sent with enhanced features',
            'is_new_user' => !isset($userData) || empty($userData)
        ];
    }

    /**
     * Handle /status command (fallback)
     */
    private function handleStatusCommand($chatId, $message)
    {
        $this->logger->info('Handling /status command (fallback)', ['chat_id' => $chatId]);
        
        $statusMessage = "📊 *Your Account Status*\n\n";
        
        if ($this->userService) {
            $userData = $this->userService->getUserData($chatId);
            if ($userData) {
                $plan = $userData['plan'] ?? 'Unknown';
                $totalSent = $userData['total_emails_sent'] ?? 0;
                $createdDate = isset($userData['created_at']) ? date('Y-m-d', $userData['created_at']) : 'Unknown';
                
                $statusMessage .= "*Plan:* " . ucfirst($plan) . "\n";
                $statusMessage .= "*Total Emails Sent:* " . number_format($totalSent) . "\n";
                $statusMessage .= "*Member Since:* {$createdDate}\n\n";
                
                // Add plan-specific information
                if ($this->planService) {
                    $planDetails = $this->planService->getUserPlan($userData);
                    if ($planDetails) {
                        $emailLimit = $planDetails['emails_per_day'] === -1 ? 'Unlimited' : number_format($planDetails['emails_per_day']);
                        $statusMessage .= "*Daily Email Limit:* {$emailLimit}\n";
                        
                        // Calculate usage for today
                        $today = date('Y-m-d');
                        $todayUsage = $userData['daily_stats'][$today]['sent'] ?? 0;
                        $statusMessage .= "*Today's Usage:* " . number_format($todayUsage) . "\n";
                        
                        if ($planDetails['emails_per_day'] !== -1) {
                            $remaining = max(0, $planDetails['emails_per_day'] - $todayUsage);
                            $statusMessage .= "*Remaining Today:* " . number_format($remaining) . "\n";
                        }
                        
                        // Plan expiry
                        if (isset($userData['plan_expires']) && $userData['plan_expires'] > 0) {
                            $expiryDate = date('Y-m-d', $userData['plan_expires']);
                            $daysLeft = ceil(($userData['plan_expires'] - time()) / 86400);
                            if ($daysLeft > 0) {
                                $statusMessage .= "*Plan Expires:* {$expiryDate} ({$daysLeft} days left)\n";
                            } else {
                                $statusMessage .= "*Plan Status:* ⚠️ Expired\n";
                            }
                        }
                    }
                }
                
                // Active campaigns
                $activeCampaigns = count($userData['active_campaigns'] ?? []);
                $statusMessage .= "*Active Campaigns:* {$activeCampaigns}\n";
                
            } else {
                $statusMessage .= "❓ User data not found. Use /start to initialize.\n";
            }
        } else {
            $statusMessage .= "⚠️ UserService not available.\n";
        }
        
        $statusMessage .= "\n*System Status:*\n";
        $health = $this->healthCheck();
        foreach ($health['services'] as $service) {
            $status = $service['status'] === 'healthy' ? '✅' : ($service['status'] === 'not_initialized' ? '⏳' : '❌');
            $statusMessage .= "$status {$service['name']}\n";
        }
        
        // Add action buttons
        $keyboard = [
            [
                ['text' => '📤 Send Campaign', 'callback_data' => 'send_campaign'],
                ['text' => '💎 Upgrade Plan', 'callback_data' => 'plans']
            ],
            [
                ['text' => '📁 Upload Files', 'callback_data' => 'upload'],
                ['text' => '🔄 Refresh Status', 'callback_data' => 'status']
            ],
            [
                ['text' => '🏠 Main Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->telegramApi->sendMessage($chatId, $statusMessage, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return [
            'success' => true,
            'command' => 'status',
            'response' => 'Enhanced status message sent'
        ];
    }

    /**
     * Handle /help command (fallback)
     */
    private function handleHelpCommand($chatId, $message)
    {
        $this->logger->info('Handling /help command (fallback)', ['chat_id' => $chatId]);
        
        $response = "❓ *Help (Fallback Mode)*\n\n";
        $response .= "Available commands:\n";
        $response .= "/start - Initialize your account\n";
        $response .= "/status - Show your status\n";
        $response .= "/help - Show this help\n\n";
        $response .= "Note: Bot is running in fallback mode. Full features require complete service initialization.";
        
        return [
            'success' => true,
            'command' => 'help',
            'response' => $response
        ];
    }

    // Legacy compatibility methods for existing code
    
    /**
     * Send Telegram message (legacy compatibility)
     */
    public function sendTelegramMessage($chatId, $text, $options = [])
    {
        return $this->telegramApi->sendMessage($chatId, $text, $options);
    }

    /**
     * Check if user is admin (legacy compatibility)
     */
    public function isAdmin($chatId)
    {
        $adminIds = $this->config->get('admin_chat_ids', []);
        return in_array($chatId, $adminIds);
    }

    /**
     * Get user data (legacy compatibility)
     */
    public function getUserData($chatId)
    {
        return $this->userService->getUserData($chatId);
    }

    /**
     * Save user data (legacy compatibility)
     */
    public function saveUserData($chatId, $data)
    {
        return $this->userService->updateUserData($chatId, $data);
    }

    /**
     * Update user data (legacy compatibility)
     */
    public function updateUserData($chatId, $data)
    {
        return $this->userService->updateUserData($chatId, $data);
    }

    /**
     * Get logger (legacy compatibility)
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Get config manager (legacy compatibility)
     */
    public function getConfigManager()
    {
        return $this->config;
    }

    /**
     * Get Telegram API (legacy compatibility)
     */
    public function getTelegramAPI()
    {
        return $this->telegramApi;
    }

    /**
     * Get service health status
     */
    public function getServiceHealth($serviceName)
    {
        $services = [
            'config' => $this->config,
            'logger' => $this->logger,
            'telegramApi' => $this->telegramApi,
            'userService' => $this->userService,
            'planService' => $this->planService,
            'workflowService' => $this->workflowService,
            'emailService' => $this->emailService,
            'commandManager' => $this->commandManager
        ];

        if (!isset($services[$serviceName])) {
            return ['status' => 'unknown', 'service' => $serviceName];
        }

        $service = $services[$serviceName];
        if ($service && method_exists($service, 'isHealthy')) {
            return [
                'status' => $service->isHealthy() ? 'healthy' : 'unhealthy',
                'service' => $serviceName
            ];
        }

        return [
            'status' => $service ? 'healthy' : 'unhealthy',
            'service' => $serviceName
        ];
    }

    /**
     * Get available plans
     */
    public function getAvailablePlans()
    {
        return $this->planService ? $this->planService->getAvailablePlans() : [];
    }

    /**
     * Get user statistics
     */
    public function getUserStats()
    {
        if (!$this->userService) {
            return ['total' => 0, 'active' => 0];
        }

        $allUsers = $this->userService->getAllUsers();
        $total = count($allUsers);
        
        // Count active users (logged in within last 30 days)
        $activeThreshold = time() - (30 * 24 * 60 * 60);
        $active = 0;
        
        foreach ($allUsers as $user) {
            if (($user['last_activity'] ?? 0) > $activeThreshold) {
                $active++;
            }
        }

        return [
            'total' => $total,
            'active' => $active
        ];
    }
}
