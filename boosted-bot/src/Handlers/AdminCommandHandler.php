<?php

namespace App\Handlers;

use App\Core\TelegramBotCore;

/**
 * Admin Command Handler
 * 
 * Handles the /admin command
 */
class AdminCommandHandler extends CommandHandler
{
    /**
     * @inheritdoc
     */
    protected $commands = ['admin'];
    
    /**
     * @inheritdoc
     */
    protected $description = 'Administrative commands';
    
    /**
     * @inheritdoc
     */
    protected $usage = '/admin [action] [params]';
    
    /**
     * @inheritdoc
     */
    protected $adminOnly = true;
    
    /**
     * @inheritdoc
     */
    public function handle($chatId, $command, $args = [], $meta = [])
    {
        // Check admin permissions
        if (!$this->bot->isAdmin($chatId)) {
            $this->bot->sendTelegramMessage($chatId, "⛔ You are not authorized to use this command.");
            return false;
        }
        
        // Check for subcommand
        $subcommand = isset($args[0]) ? strtolower($args[0]) : 'menu';
        
        // Handle different subcommands
        switch ($subcommand) {
            case 'users':
                return $this->handleUsers($chatId, array_slice($args, 1));
                
            case 'stats':
                return $this->handleStats($chatId, array_slice($args, 1));
                
            case 'config':
                return $this->handleConfig($chatId, array_slice($args, 1));
                
            case 'broadcast':
                return $this->handleBroadcast($chatId, array_slice($args, 1));
                
            case 'menu':
            default:
                return $this->showAdminMenu($chatId);
        }
    }
    
    /**
     * Show admin menu
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    private function showAdminMenu($chatId)
    {
        $message = "⚙️ *Admin Control Panel*\n\n";
        $message .= "What would you like to manage?";
        
        $keyboard = [
            [
                ['text' => '👥 Manage Users', 'callback_data' => 'admin_users']
            ],
            [
                ['text' => '📊 System Stats', 'callback_data' => 'admin_stats']
            ],
            [
                ['text' => '⚙️ Configuration', 'callback_data' => 'admin_config']
            ],
            [
                ['text' => '📢 Broadcast', 'callback_data' => 'admin_broadcast']
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
    
    /**
     * Handle users subcommand
     * 
     * @param string $chatId Chat ID
     * @param array $args Command arguments
     * @return bool Success status
     */
    private function handleUsers($chatId, $args = [])
    {
        // Get action (default to 'list')
        $action = isset($args[0]) ? strtolower($args[0]) : 'list';
        
        switch ($action) {
            case 'info':
                $userId = $args[1] ?? null;
                return $this->getUserInfo($chatId, $userId);
                
            case 'edit':
                $userId = $args[1] ?? null;
                return $this->editUser($chatId, $userId, array_slice($args, 2));
                
            case 'list':
            default:
                return $this->listUsers($chatId);
        }
    }
    
    /**
     * List users
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    private function listUsers($chatId)
    {
        // Get user service
        $userService = $this->bot->getUserService();
        
        // Get all users
        $users = $userService->getAllUsers();
        
        // Count users by plan
        $planCounts = [];
        $activePlanCount = 0;
        $expiredPlanCount = 0;
        
        foreach ($users as $user) {
            $plan = $userService->getUserPlan($user['chat_id']);
            
            if (!$plan) {
                continue;
            }
            
            $planName = $plan['name'] ?? 'unknown';
            
            if (!isset($planCounts[$planName])) {
                $planCounts[$planName] = 0;
            }
            
            $planCounts[$planName]++;
            
            if ($plan['expired']) {
                $expiredPlanCount++;
            } else {
                $activePlanCount++;
            }
        }
        
        // Build message
        $message = "👥 *User Management*\n\n";
        
        // User counts
        $message .= "*User Statistics:*\n";
        $message .= "• Total Users: " . count($users) . "\n";
        $message .= "• Active Plans: {$activePlanCount}\n";
        $message .= "• Expired Plans: {$expiredPlanCount}\n\n";
        
        // Plan breakdown
        $message .= "*Users by Plan:*\n";
        
        foreach ($planCounts as $plan => $count) {
            $message .= "• {$plan}: {$count}\n";
        }
        
        // Recent users (up to 5)
        if (!empty($users)) {
            $recentUsers = array_slice($users, -5, 5, true);
            
            $message .= "\n*Recent Users:*\n";
            
            foreach ($recentUsers as $user) {
                $chatId = $user['chat_id'];
                $firstName = $user['first_name'] ?? 'Unknown';
                $joinDate = date('Y-m-d', $user['join_date'] ?? time());
                
                $message .= "• {$firstName} ({$chatId}) - Joined: {$joinDate}\n";
            }
        }
        
        // Admin actions
        $keyboard = [
            [
                ['text' => '🔍 Find User', 'callback_data' => 'admin_user_find']
            ],
            [
                ['text' => '📈 User Stats', 'callback_data' => 'admin_user_stats']
            ],
            [
                ['text' => '🔙 Admin Menu', 'callback_data' => 'admin_menu']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Get user info
     * 
     * @param string $chatId Chat ID
     * @param string|null $userId User ID to lookup
     * @return bool Success status
     */
    private function getUserInfo($chatId, $userId = null)
    {
        // Check if user ID is provided
        if (!$userId) {
            $message = "🔍 *User Lookup*\n\n";
            $message .= "Please send me the Chat ID of the user you want to look up:";
            
            // Set user state to awaiting user ID
            $this->bot->getUserService()->setState($chatId, 'awaiting_user_id');
            
            $keyboard = [
                [
                    ['text' => '🔙 Cancel', 'callback_data' => 'admin_users']
                ]
            ];
            
            $this->bot->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            
            return true;
        }
        
        // Get user data
        $userData = $this->bot->getUserService()->getUserData($userId);
        
        // Check if user exists
        if (!$userData) {
            $this->bot->sendTelegramMessage($chatId, "❓ User not found with Chat ID: {$userId}");
            return false;
        }
        
        // Get user plan
        $plan = $this->bot->getUserService()->getUserPlan($userId);
        
        // Build message
        $message = "👤 *User Information*\n\n";
        
        // Basic info
        $message .= "*Chat ID:* `{$userId}`\n";
        $message .= "*Name:* " . ($userData['first_name'] ?? 'Unknown') . "\n";
        $message .= "*Join Date:* " . date('Y-m-d', $userData['join_date'] ?? time()) . "\n\n";
        
        // Plan info
        $message .= "*Plan:*\n";
        
        if ($plan) {
            $message .= "• Name: {$plan['name']}\n";
            
            // Show plan expiry
            if ($plan['expired']) {
                $message .= "• Status: ⚠️ Expired\n";
            } else if ($plan['expiry_time'] == -1) {
                $message .= "• Status: ✅ Active (No Expiry)\n";
            } else {
                $daysLeft = ceil(($plan['expiry_time'] - time()) / 86400);
                $message .= "• Status: ✅ Active ({$daysLeft} days left)\n";
            }
            
            // Show rate limits
            if (isset($plan['emails_per_hour'])) {
                $rateLimit = $plan['emails_per_hour'];
                $rateLimitText = ($rateLimit == -1) ? "Unlimited" : $rateLimit;
                $message .= "• Rate Limit: {$rateLimitText}/hour\n";
            }
        } else {
            $message .= "No active plan\n";
        }
        
        // Email stats
        $message .= "\n*Email Statistics:*\n";
        
        $stats = $userData['email_stats'] ?? ['total_sent' => 0, 'total_errors' => 0, 'campaigns' => 0];
        
        $message .= "• Total Sent: " . number_format($stats['total_sent']) . "\n";
        $message .= "• Total Errors: " . number_format($stats['total_errors'] ?? 0) . "\n";
        $message .= "• Campaigns: " . number_format($stats['campaigns']) . "\n";
        
        // Admin actions
        $keyboard = [
            [
                ['text' => '✏️ Edit User', 'callback_data' => "admin_user_edit_{$userId}"]
            ],
            [
                ['text' => '🔄 Refresh Info', 'callback_data' => "admin_user_info_{$userId}"]
            ],
            [
                ['text' => '🔙 User List', 'callback_data' => 'admin_users']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Edit user
     * 
     * @param string $chatId Chat ID
     * @param string|null $userId User ID to edit
     * @param array $args Command arguments
     * @return bool Success status
     */
    private function editUser($chatId, $userId = null, $args = [])
    {
        // Check if user ID is provided
        if (!$userId) {
            $message = "✏️ *Edit User*\n\n";
            $message .= "Please send me the Chat ID of the user you want to edit:";
            
            // Set user state to awaiting user ID for edit
            $this->bot->getUserService()->setState($chatId, 'awaiting_user_id_edit');
            
            $keyboard = [
                [
                    ['text' => '🔙 Cancel', 'callback_data' => 'admin_users']
                ]
            ];
            
            $this->bot->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            
            return true;
        }
        
        // Get user data
        $userData = $this->bot->getUserService()->getUserData($userId);
        
        // Check if user exists
        if (!$userData) {
            $this->bot->sendTelegramMessage($chatId, "❓ User not found with Chat ID: {$userId}");
            return false;
        }
        
        // Check if field and value are provided
        if (count($args) < 2) {
            $message = "✏️ *Edit User* `{$userId}`\n\n";
            $message .= "What would you like to edit?";
            
            $keyboard = [
                [
                    ['text' => '💰 Change Plan', 'callback_data' => "admin_user_plan_{$userId}"]
                ],
                [
                    ['text' => '⏱️ Extend Expiry', 'callback_data' => "admin_user_expiry_{$userId}"]
                ],
                [
                    ['text' => '🚫 Ban User', 'callback_data' => "admin_user_ban_{$userId}"]
                ],
                [
                    ['text' => '🔙 User Info', 'callback_data' => "admin_user_info_{$userId}"]
                ]
            ];
            
            $this->bot->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            
            return true;
        }
        
        // Get field and value
        $field = strtolower($args[0]);
        $value = $args[1];
        
        // Edit user
        switch ($field) {
            case 'plan':
                return $this->setUserPlan($chatId, $userId, $value);
                
            case 'expiry':
                return $this->extendUserExpiry($chatId, $userId, $value);
                
            case 'ban':
                return $this->banUser($chatId, $userId, $value);
                
            default:
                $this->bot->sendTelegramMessage($chatId, "❓ Unknown field: {$field}. Available fields: plan, expiry, ban");
                return false;
        }
    }
    
    /**
     * Set user plan
     * 
     * @param string $chatId Chat ID
     * @param string $userId User ID to edit
     * @param string $planName Plan name
     * @return bool Success status
     */
    private function setUserPlan($chatId, $userId, $planName)
    {
        // Get config manager
        $config = $this->bot->getConfigManager();
        
        // Get available plans
        $plans = $config->get('plans', []);
        
        // Check if plan exists
        if (!isset($plans[$planName])) {
            $availablePlans = implode(', ', array_keys($plans));
            $message = "❓ Unknown plan: {$planName}\n";
            $message .= "Available plans: {$availablePlans}";
            
            $this->bot->sendTelegramMessage($chatId, $message);
            return false;
        }
        
        // Get plan data
        $plan = $plans[$planName];
        
        // Get user data
        $userData = $this->bot->getUserService()->getUserData($userId);
        
        // Update user plan
        $userData['plan'] = $planName;
        
        // Set expiry if plan has duration
        if (isset($plan['duration_days'])) {
            $userData['plan_expiry'] = time() + ($plan['duration_days'] * 86400);
        } else {
            $userData['plan_expiry'] = -1; // No expiry
        }
        
        // Save user data
        $this->bot->getUserService()->saveUser($userId, $userData);
        
        // Confirm
        $message = "✅ *Plan Updated*\n\n";
        $message .= "User `{$userId}` plan changed to: {$planName}";
        
        if (isset($plan['duration_days'])) {
            $expiryDate = date('Y-m-d', $userData['plan_expiry']);
            $message .= "\nExpiry: {$expiryDate}";
        } else {
            $message .= "\nExpiry: Never";
        }
        
        $keyboard = [
            [
                ['text' => '👤 User Info', 'callback_data' => "admin_user_info_{$userId}"]
            ],
            [
                ['text' => '🔙 Admin Menu', 'callback_data' => 'admin_menu']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        // Log action
        $this->bot->log("Admin {$chatId} changed user {$userId} plan to {$planName}", 'INFO');
        
        // Notify user
        $notifyMessage = "💰 *Plan Updated*\n\n";
        $notifyMessage .= "Your plan has been updated to: {$planName}";
        
        if (isset($plan['duration_days'])) {
            $expiryDate = date('Y-m-d', $userData['plan_expiry']);
            $notifyMessage .= "\nExpiry: {$expiryDate}";
        } else {
            $notifyMessage .= "\nExpiry: Never";
        }
        
        $this->bot->sendTelegramMessage($userId, $notifyMessage, [
            'parse_mode' => 'Markdown'
        ]);
        
        return true;
    }
    
    /**
     * Extend user plan expiry
     * 
     * @param string $chatId Chat ID
     * @param string $userId User ID to edit
     * @param string $days Number of days to extend
     * @return bool Success status
     */
    private function extendUserExpiry($chatId, $userId, $days)
    {
        // Validate days
        if (!is_numeric($days) || $days <= 0) {
            $this->bot->sendTelegramMessage($chatId, "❓ Invalid days value: {$days}. Please provide a positive number.");
            return false;
        }
        
        $days = (int)$days;
        
        // Get user data
        $userData = $this->bot->getUserService()->getUserData($userId);
        
        // Check if user exists
        if (!$userData) {
            $this->bot->sendTelegramMessage($chatId, "❓ User not found with Chat ID: {$userId}");
            return false;
        }
        
        // Get current expiry
        $currentExpiry = $userData['plan_expiry'] ?? time();
        
        // Use current time as base if expired
        if ($currentExpiry < time()) {
            $currentExpiry = time();
        }
        
        // Calculate new expiry
        $newExpiry = $currentExpiry + ($days * 86400);
        
        // Update user data
        $userData['plan_expiry'] = $newExpiry;
        
        // Save user data
        $this->bot->getUserService()->saveUser($userId, $userData);
        
        // Format dates
        $currentExpiryDate = date('Y-m-d', $currentExpiry);
        $newExpiryDate = date('Y-m-d', $newExpiry);
        
        // Confirm
        $message = "✅ *Plan Extended*\n\n";
        $message .= "User `{$userId}` plan expiry extended by {$days} days.\n";
        $message .= "Previous expiry: {$currentExpiryDate}\n";
        $message .= "New expiry: {$newExpiryDate}";
        
        $keyboard = [
            [
                ['text' => '👤 User Info', 'callback_data' => "admin_user_info_{$userId}"]
            ],
            [
                ['text' => '🔙 Admin Menu', 'callback_data' => 'admin_menu']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        // Log action
        $this->bot->log("Admin {$chatId} extended user {$userId} plan by {$days} days", 'INFO');
        
        // Notify user
        $notifyMessage = "⏱️ *Plan Extended*\n\n";
        $notifyMessage .= "Your plan has been extended by {$days} days.\n";
        $notifyMessage .= "New expiry date: {$newExpiryDate}";
        
        $this->bot->sendTelegramMessage($userId, $notifyMessage, [
            'parse_mode' => 'Markdown'
        ]);
        
        return true;
    }
    
    /**
     * Ban user
     * 
     * @param string $chatId Chat ID
     * @param string $userId User ID to ban
     * @param string $reason Ban reason
     * @return bool Success status
     */
    private function banUser($chatId, $userId, $reason = 'Unspecified')
    {
        // Get user data
        $userData = $this->bot->getUserService()->getUserData($userId);
        
        // Check if user exists
        if (!$userData) {
            $this->bot->sendTelegramMessage($chatId, "❓ User not found with Chat ID: {$userId}");
            return false;
        }
        
        // Update user data
        $userData['banned'] = true;
        $userData['ban_reason'] = $reason;
        $userData['ban_time'] = time();
        $userData['banned_by'] = $chatId;
        
        // Save user data
        $this->bot->getUserService()->saveUser($userId, $userData);
        
        // Confirm
        $message = "🚫 *User Banned*\n\n";
        $message .= "User `{$userId}` has been banned.\n";
        $message .= "Reason: {$reason}";
        
        $keyboard = [
            [
                ['text' => '↩️ Unban User', 'callback_data' => "admin_user_unban_{$userId}"]
            ],
            [
                ['text' => '👤 User Info', 'callback_data' => "admin_user_info_{$userId}"]
            ],
            [
                ['text' => '🔙 Admin Menu', 'callback_data' => 'admin_menu']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        // Log action
        $this->bot->log("Admin {$chatId} banned user {$userId}. Reason: {$reason}", 'INFO');
        
        // Notify user
        $notifyMessage = "🚫 *Account Banned*\n\n";
        $notifyMessage .= "Your account has been banned.\n";
        $notifyMessage .= "Reason: {$reason}\n\n";
        $notifyMessage .= "If you believe this is an error, please contact support.";
        
        $this->bot->sendTelegramMessage($userId, $notifyMessage, [
            'parse_mode' => 'Markdown'
        ]);
        
        return true;
    }
    
    /**
     * Handle stats subcommand
     * 
     * @param string $chatId Chat ID
     * @param array $args Command arguments
     * @return bool Success status
     */
    private function handleStats($chatId, $args = [])
    {
        // Get user service
        $userService = $this->bot->getUserService();
        
        // Get SMTP manager
        $smtpManager = $this->bot->getSmtpManager();
        
        // Build message
        $message = "📊 *System Statistics*\n\n";
        
        // User stats
        $users = $userService->getAllUsers();
        $totalUsers = count($users);
        $todayUsers = 0;
        $weekUsers = 0;
        
        $oneWeekAgo = time() - (7 * 86400);
        $oneDayAgo = time() - 86400;
        
        foreach ($users as $user) {
            $joinDate = $user['join_date'] ?? time();
            
            if ($joinDate > $oneDayAgo) {
                $todayUsers++;
            }
            
            if ($joinDate > $oneWeekAgo) {
                $weekUsers++;
            }
        }
        
        $message .= "*Users:*\n";
        $message .= "• Total Users: {$totalUsers}\n";
        $message .= "• New Today: {$todayUsers}\n";
        $message .= "• New This Week: {$weekUsers}\n\n";
        
        // SMTP stats
        $smtps = $smtpManager->getAllSmtps();
        $activeSmtps = $smtpManager->getActiveSmtps();
        $cooldownSmtps = $smtpManager->getCooldownSmtps();
        $disabledSmtps = $smtpManager->getDisabledSmtps();
        
        $message .= "*SMTP Status:*\n";
        $message .= "• Total SMTPs: " . count($smtps) . "\n";
        $message .= "• Active SMTPs: " . count($activeSmtps) . "\n";
        $message .= "• Cooldown SMTPs: " . count($cooldownSmtps) . "\n";
        $message .= "• Disabled SMTPs: " . count($disabledSmtps) . "\n";
        
        // Success rate
        $totalSent = $smtpManager->getTotalSent();
        $totalErrors = $smtpManager->getTotalErrors();
        $successRate = $smtpManager->getSuccessRate();
        
        $message .= "\n*Email Stats:*\n";
        $message .= "• Success Rate: " . $this->formatPercent($successRate) . "\n";
        $message .= "• Total Sent: {$totalSent}\n";
        $message .= "• Total Errors: {$totalErrors}\n\n";
        
        // Server stats
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');
        $diskTotal = disk_total_space('/');
        $diskFree = disk_free_space('/');
        $diskUsed = $diskTotal - $diskFree;
        $diskUsagePercent = ($diskUsed / $diskTotal) * 100;
        
        $message .= "*Server:*\n";
        $message .= "• Memory: " . $this->formatBytes($memoryUsage) . " / {$memoryLimit}\n";
        $message .= "• Disk: " . $this->formatBytes($diskUsed) . " / " . $this->formatBytes($diskTotal) . 
            " (" . round($diskUsagePercent, 1) . "%)\n";
        $message .= "• Uptime: " . $this->getUptime();
        
        $keyboard = [
            [
                ['text' => '👥 User Stats', 'callback_data' => 'admin_user_stats']
            ],
            [
                ['text' => '📈 SMTP Stats', 'callback_data' => 'admin_smtp_stats']
            ],
            [
                ['text' => '🔄 Refresh Stats', 'callback_data' => 'admin_stats_refresh']
            ],
            [
                ['text' => '🔙 Admin Menu', 'callback_data' => 'admin_menu']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Handle config subcommand
     * 
     * @param string $chatId Chat ID
     * @param array $args Command arguments
     * @return bool Success status
     */
    private function handleConfig($chatId, $args = [])
    {
        // Get config manager
        $config = $this->bot->getConfigManager();
        
        // Check for subcommand
        if (empty($args)) {
            // Show config menu
            $message = "⚙️ *Configuration*\n\n";
            $message .= "What would you like to configure?";
            
            $keyboard = [
                [
                    ['text' => '📧 SMTP Settings', 'callback_data' => 'admin_config_smtp']
                ],
                [
                    ['text' => '💰 Plans', 'callback_data' => 'admin_config_plans']
                ],
                [
                    ['text' => '🔄 Rate Limits', 'callback_data' => 'admin_config_rates']
                ],
                [
                    ['text' => '📝 Log Settings', 'callback_data' => 'admin_config_logs']
                ],
                [
                    ['text' => '🔙 Admin Menu', 'callback_data' => 'admin_menu']
                ]
            ];
            
            $this->bot->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            
            return true;
        }
        
        // Handle subcommands
        $subcommand = strtolower($args[0]);
        
        switch ($subcommand) {
            case 'get':
                $key = $args[1] ?? null;
                
                if (!$key) {
                    $this->bot->sendTelegramMessage($chatId, "❓ Please provide a config key: /admin config get [key]");
                    return false;
                }
                
                $value = $config->get($key, 'Not set');
                
                $message = "🔍 *Config Value*\n\n";
                $message .= "`{$key}`: ";
                
                if (is_array($value)) {
                    $message .= "```\n" . json_encode($value, JSON_PRETTY_PRINT) . "\n```";
                } else {
                    $message .= "`{$value}`";
                }
                
                $this->bot->sendTelegramMessage($chatId, $message, [
                    'parse_mode' => 'Markdown'
                ]);
                
                return true;
                
            case 'set':
                $key = $args[1] ?? null;
                $value = $args[2] ?? null;
                
                if (!$key || $value === null) {
                    $this->bot->sendTelegramMessage($chatId, "❓ Please provide a config key and value: /admin config set [key] [value]");
                    return false;
                }
                
                // Try to decode JSON value
                if (in_array($value[0], ['{', '[']) && in_array($value[-1], ['}', ']'])) {
                    try {
                        $decodedValue = json_decode($value, true);
                        if ($decodedValue !== null) {
                            $value = $decodedValue;
                        }
                    } catch (\Exception $e) {
                        // Not valid JSON, use as string
                    }
                }
                
                $config->set($key, $value);
                
                $message = "✅ *Config Updated*\n\n";
                $message .= "`{$key}` set to: ";
                
                if (is_array($value)) {
                    $message .= "```\n" . json_encode($value, JSON_PRETTY_PRINT) . "\n```";
                } else {
                    $message .= "`{$value}`";
                }
                
                $this->bot->sendTelegramMessage($chatId, $message, [
                    'parse_mode' => 'Markdown'
                ]);
                
                // Log change
                $this->bot->log("Admin {$chatId} updated config key {$key}", 'INFO');
                
                return true;
                
            default:
                $this->bot->sendTelegramMessage($chatId, "❓ Unknown subcommand: {$subcommand}. Available subcommands: get, set");
                return false;
        }
    }
    
    /**
     * Handle broadcast subcommand
     * 
     * @param string $chatId Chat ID
     * @param array $args Command arguments
     * @return bool Success status
     */
    private function handleBroadcast($chatId, $args = [])
    {
        // Check for message
        if (empty($args)) {
            $message = "📢 *Broadcast Message*\n\n";
            $message .= "Please enter the message you want to broadcast to all users:";
            
            // Set user state to awaiting broadcast message
            $this->bot->getUserService()->setState($chatId, 'awaiting_broadcast_message');
            
            $keyboard = [
                [
                    ['text' => '🔙 Cancel', 'callback_data' => 'admin_menu']
                ]
            ];
            
            $this->bot->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            
            return true;
        }
        
        // Get message
        $broadcastMessage = implode(' ', $args);
        
        // Confirm broadcast
        $message = "📢 *Broadcast Confirmation*\n\n";
        $message .= "Are you sure you want to send this message to all users?\n\n";
        $message .= "```\n{$broadcastMessage}\n```";
        
        // Save message in state
        $this->bot->getUserService()->setState($chatId, 'confirming_broadcast');
        $this->bot->getUserService()->setStateData($chatId, ['message' => $broadcastMessage]);
        
        $keyboard = [
            [
                ['text' => '✅ Send', 'callback_data' => 'admin_broadcast_confirm']
            ],
            [
                ['text' => '❌ Cancel', 'callback_data' => 'admin_broadcast_cancel']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Format bytes to human-readable format
     * 
     * @param int $bytes Bytes
     * @param int $precision Decimal precision
     * @return string Formatted size
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    /**
     * Format percentage
     * 
     * @param float $value Percentage value
     * @param int $precision Decimal precision
     * @return string Formatted percentage
     */
    private function formatPercent($value, $precision = 1)
    {
        return round($value * 100, $precision) . '%';
    }
    
    /**
     * Get system uptime
     * 
     * @return string Formatted uptime
     */
    private function getUptime()
    {
        // Linux system
        if (file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $uptime = explode(' ', $uptime)[0];
            
            return $this->secondsToTime((int)$uptime);
        }
        
        // Process uptime (fallback)
        $startTime = $_SERVER['REQUEST_TIME'] ?? time();
        $uptime = time() - $startTime;
        
        return $this->secondsToTime($uptime);
    }
    
    /**
     * Convert seconds to human-readable time
     * 
     * @param int $seconds Seconds
     * @return string Formatted time
     */
    private function secondsToTime($seconds)
    {
        $dtF = new \DateTime('@0');
        $dtT = new \DateTime("@$seconds");
        
        $days = (int)$dtF->diff($dtT)->format('%a');
        $hours = $dtF->diff($dtT)->format('%h');
        $minutes = $dtF->diff($dtT)->format('%i');
        
        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        } else if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        } else {
            return "{$minutes}m";
        }
    }
}
