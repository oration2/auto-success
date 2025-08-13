<?php

namespace App\Handlers;

use App\Core\TelegramBotCore;

/**
 * Status Command Handler
 * 
 * Handles the /status command
 */
class StatusCommandHandler extends CommandHandler
{
    /**
     * @inheritdoc
     */
    protected $commands = ['status'];
    
    /**
     * @inheritdoc
     */
    protected $description = 'Check system status';
    
    /**
     * @inheritdoc
     */
    protected $usage = '/status [smtp]';
    
    /**
     * @inheritdoc
     */
    public function handle($chatId, $command, $args = [], $meta = [])
    {
        // Check for subcommand
        $subcommand = isset($args[0]) ? strtolower($args[0]) : '';
        
        if ($subcommand === 'smtp') {
            return $this->showSmtpStatus($chatId);
        }
        
        return $this->showGeneralStatus($chatId);
    }
    
    /**
     * Show general bot status
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    private function showGeneralStatus($chatId)
    {
        // Get services
        $smtpManager = $this->bot->getSmtpManager();
        $userService = $this->bot->getUserService();
        
        // Get user data
        $userData = $this->bot->getUserData($chatId);
        $plan = $userService->getUserPlan($chatId);
        $isAdmin = $this->bot->isAdmin($chatId);
        
        // Build status message
        $message = "🔄 *System Status*\n\n";
        
        // Show user status
        $message .= "*Your Account:*\n";
        
        if ($plan) {
            $message .= "• Plan: {$plan['name']}\n";
            
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
            $message .= "• Plan: None\n";
            $message .= "• Status: ❓ No active plan\n";
        }
        
        // Show daily and monthly usage
        $today = date('Y-m-d');
        $month = date('Y-m');
        
        $todaySent = $userData['daily_stats'][$today]['sent'] ?? 0;
        $monthSent = $userData['monthly_stats'][$month]['sent'] ?? 0;
        
        $message .= "• Today: {$todaySent} emails sent\n";
        $message .= "• This Month: {$monthSent} emails sent\n\n";
        
        // Show SMTP status summary
        $smtps = $smtpManager->getAllSmtps();
        $activeSmtps = $smtpManager->getActiveSmtps();
        
        $message .= "*SMTP Status:*\n";
        $message .= "• Total SMTPs: " . count($smtps) . "\n";
        $message .= "• Active SMTPs: " . count($activeSmtps) . "\n";
        $message .= "• Success Rate: " . $this->formatPercent($smtpManager->getSuccessRate()) . "\n";
        
        // Show system status if admin
        if ($isAdmin) {
            // Get memory usage
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = ini_get('memory_limit');
            
            // Get queue information
            $queueSize = 0; // This would come from a queue manager
            $activeWorkers = 0; // This would come from worker status
            
            $message .= "\n*System Status:*\n";
            $message .= "• Memory: " . $this->formatBytes($memoryUsage) . " / {$memoryLimit}\n";
            $message .= "• Queue Size: {$queueSize}\n";
            $message .= "• Active Workers: {$activeWorkers}\n";
            $message .= "• Uptime: " . $this->getUptime() . "\n";
        }
        
        // Add action buttons
        $keyboard = [
            [
                ['text' => '📈 SMTP Status', 'callback_data' => 'smtp_status']
            ],
            [
                ['text' => '🔄 Refresh Status', 'callback_data' => 'refresh_status']
            ]
        ];
        
        // Add admin actions
        if ($isAdmin) {
            $keyboard[] = [
                ['text' => '⚙️ Admin Panel', 'callback_data' => 'admin_panel']
            ];
        }
        
        // Add main menu button
        $keyboard[] = [
            ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Show SMTP status
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    private function showSmtpStatus($chatId)
    {
        // Get SMTP manager
        $smtpManager = $this->bot->getSmtpManager();
        
        // Get SMTP stats
        $smtps = $smtpManager->getAllSmtps();
        $activeSmtps = $smtpManager->getActiveSmtps();
        $cooldownSmtps = $smtpManager->getCooldownSmtps();
        $disabledSmtps = $smtpManager->getDisabledSmtps();
        
        // Calculate stats
        $totalCount = count($smtps);
        $activeCount = count($activeSmtps);
        $cooldownCount = count($cooldownSmtps);
        $disabledCount = count($disabledSmtps);
        
        // Build status message
        $message = "📊 *SMTP Status*\n\n";
        
        // Overall stats
        $message .= "*Summary:*\n";
        $message .= "• Total SMTPs: {$totalCount}\n";
        $message .= "• Active: {$activeCount}\n";
        $message .= "• Cooldown: {$cooldownCount}\n";
        $message .= "• Disabled: {$disabledCount}\n\n";
        
        // Success rate
        $totalSent = $smtpManager->getTotalSent();
        $totalErrors = $smtpManager->getTotalErrors();
        $successRate = $smtpManager->getSuccessRate();
        
        $message .= "*Performance:*\n";
        $message .= "• Success Rate: " . $this->formatPercent($successRate) . "\n";
        $message .= "• Total Sent: {$totalSent}\n";
        $message .= "• Total Errors: {$totalErrors}\n\n";
        
        // Show top performing SMTPs
        $topSmtps = $smtpManager->getTopPerformingSmtps(3);
        if (count($topSmtps) > 0) {
            $message .= "*Top Performing SMTPs:*\n";
            
            foreach ($topSmtps as $index => $smtp) {
                $host = $smtp['host'] ?? 'unknown';
                $sent = $smtp['sent'] ?? 0;
                $successRate = $smtp['success_rate'] ?? 0;
                
                $message .= ($index + 1) . ". {$host} - " . $this->formatPercent($successRate) . " ({$sent} sent)\n";
            }
        }
        
        // Add action buttons
        $keyboard = [
            [
                ['text' => '🔄 Refresh SMTP Status', 'callback_data' => 'smtp_status_refresh']
            ],
            [
                ['text' => '➕ Add SMTP', 'callback_data' => 'smtp_add']
            ],
            [
                ['text' => '📋 SMTP List', 'callback_data' => 'smtp_list']
            ],
            [
                ['text' => '🔙 Status Menu', 'callback_data' => 'status']
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
