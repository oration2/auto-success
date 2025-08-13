<?php

namespace App\Commands;

use App\Services\PlanService;
use App\Services\EmailService;

/**
 * Status Command
 * 
 * Shows user status, plan information, and usage statistics
 */
class StatusCommand extends BaseCommand
{
    protected $name = 'status';
    protected $description = 'Show your account status and usage statistics';
    
    private $planService;
    private $emailService;

    public function __construct($config = null, $logger = null, $telegramApi = null, $userService = null, PlanService $planService = null, EmailService $emailService = null)
    {
        parent::__construct($config, $logger, $telegramApi, $userService);
        $this->planService = $planService;
        $this->emailService = $emailService;
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

        if (!$this->isAuthorized($chatId)) {
            $this->sendMessage($chatId, $this->getUserFriendlyError('unauthorized'));
            return ['success' => false, 'error' => 'Unauthorized'];
        }

        $this->logExecution($chatId, $context);
        $this->sendTyping($chatId);

        try {
            $userData = $this->userService?->getUserData($chatId);
            if (!$userData) {
                $this->sendMessage($chatId, "❌ User data not found. Please use /start to initialize your account.");
                return ['success' => false, 'error' => 'User data not found'];
            }

            $statusMessage = $this->buildStatusMessage($chatId, $userData);
            
            $result = $this->sendMessage($chatId, $statusMessage, [
                'parse_mode' => 'Markdown',
                'reply_markup' => $this->getStatusKeyboard()
            ]);

            return ['success' => $result['success'] ?? false];

        } catch (\Exception $e) {
            $this->logger?->error('Status command failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);

            $this->sendMessage($chatId, $this->getUserFriendlyError('internal_error'));
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build comprehensive status message
     * 
     * @param string $chatId Chat ID
     * @param array $userData User data
     * @return string Status message
     */
    private function buildStatusMessage($chatId, $userData)
    {
        $message = "📊 *Your Account Status*\n\n";

        // User Information
        $message .= $this->getUserInfo($userData);
        $message .= "\n";

        // Plan Information
        $message .= $this->getPlanInfo($chatId);
        $message .= "\n";

        // Usage Statistics
        $message .= $this->getUsageStats($chatId, $userData);
        $message .= "\n";

        // Configuration Status
        $message .= $this->getConfigStatus($userData);
        $message .= "\n";

        // Recent Activity
        $message .= $this->getRecentActivity($userData);

        return $message;
    }

    /**
     * Get user information section
     * 
     * @param array $userData User data
     * @return string User info
     */
    private function getUserInfo($userData)
    {
        $username = $userData['username'] ? '@' . $userData['username'] : 'N/A';
        $name = trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? ''));
        $joinDate = date('Y-m-d', $userData['created_at'] ?? time());
        $lastActivity = date('Y-m-d H:i', $userData['last_activity'] ?? time());

        return "👤 *User Information*\n" .
               "Name: " . ($name ?: 'N/A') . "\n" .
               "Username: $username\n" .
               "Member since: $joinDate\n" .
               "Last activity: $lastActivity";
    }

    /**
     * Get plan information section
     * 
     * @param string $chatId Chat ID
     * @return string Plan info
     */
    private function getPlanInfo($chatId)
    {
        $plan = $this->planService?->getUserPlan($chatId);
        
        if (!$plan) {
            return "❌ *Plan Information*\nNo plan information available";
        }

        $status = $plan['expired'] ? "❌ Expired" : "✅ Active";
        $expiry = $plan['expiry_time'] > 0 ? date('Y-m-d H:i', $plan['expiry_time']) : 'No expiry';
        $daysLeft = $plan['expiry_time'] > 0 ? max(0, ceil(($plan['expiry_time'] - time()) / 86400)) : '∞';

        $message = "💎 *Plan Information*\n" .
                  "Plan: " . $plan['display_name'] . "\n" .
                  "Status: $status\n" .
                  "Expires: $expiry\n" .
                  "Days left: $daysLeft\n\n" .
                  "📋 *Plan Limits*\n" .
                  "Emails/hour: " . number_format($plan['emails_per_hour']) . "\n" .
                  "Emails/day: " . number_format($plan['emails_per_day']) . "\n" .
                  "Max campaigns: " . $plan['max_campaigns'];

        if ($plan['expired']) {
            $message .= "\n\n⚠️ Your plan has expired. Use /plans to upgrade.";
        }

        return $message;
    }

    /**
     * Get usage statistics section
     * 
     * @param string $chatId Chat ID
     * @param array $userData User data
     * @return string Usage stats
     */
    private function getUsageStats($chatId, $userData)
    {
        $emailStats = $this->emailService?->getEmailStats($chatId);
        $totalSent = $emailStats['total_sent'] ?? 0;
        $todaySent = $emailStats['today_sent'] ?? 0;
        $thisHourSent = $emailStats['this_hour_sent'] ?? 0;

        $planLimits = $emailStats['plan_limits'] ?? [];
        $hourlyLimit = $planLimits['emails_per_hour'] ?? 0;
        $dailyLimit = $planLimits['emails_per_day'] ?? 0;

        $hourlyPercent = $hourlyLimit > 0 ? round(($thisHourSent / $hourlyLimit) * 100, 1) : 0;
        $dailyPercent = $dailyLimit > 0 ? round(($todaySent / $dailyLimit) * 100, 1) : 0;

        $message = "📈 *Usage Statistics*\n" .
                  "Total emails sent: " . number_format($totalSent) . "\n" .
                  "Today's emails: " . number_format($todaySent) . " / " . number_format($dailyLimit) . " ($dailyPercent%)\n" .
                  "This hour: " . number_format($thisHourSent) . " / " . number_format($hourlyLimit) . " ($hourlyPercent%)\n\n";

        // Add usage warnings
        if ($dailyPercent >= 90) {
            $message .= "⚠️ Daily limit almost reached!\n";
        } elseif ($hourlyPercent >= 90) {
            $message .= "⚠️ Hourly limit almost reached!\n";
        }

        // Campaign statistics
        $campaignCount = count($userData['campaigns'] ?? []);
        $message .= "📊 Total campaigns: $campaignCount";

        return $message;
    }

    /**
     * Get configuration status section
     * 
     * @param array $userData User data
     * @return string Config status
     */
    private function getConfigStatus($userData)
    {
        $smtpCount = count($userData['smtp_configs'] ?? []);
        $fileCount = count($userData['files'] ?? []);
        
        $emailLists = 0;
        $htmlTemplates = 0;
        
        foreach ($userData['files'] ?? [] as $file) {
            if (isset($file['type'])) {
                if ($file['type'] === 'email_list') {
                    $emailLists++;
                } elseif ($file['type'] === 'html_template') {
                    $htmlTemplates++;
                }
            }
        }

        $message = "⚙️ *Configuration Status*\n" .
                  "SMTP configs: $smtpCount " . ($smtpCount > 0 ? "✅" : "❌") . "\n" .
                  "Email lists: $emailLists " . ($emailLists > 0 ? "✅" : "❌") . "\n" .
                  "HTML templates: $htmlTemplates " . ($htmlTemplates > 0 ? "✅" : "❌") . "\n" .
                  "Total files: $fileCount";

        if ($smtpCount === 0) {
            $message .= "\n\n⚠️ No SMTP configured. Use /smtp to add configuration.";
        }

        if ($emailLists === 0) {
            $message .= "\n⚠️ No email lists uploaded. Use /upload to add email lists.";
        }

        return $message;
    }

    /**
     * Get recent activity section
     * 
     * @param array $userData User data
     * @return string Recent activity
     */
    private function getRecentActivity($userData)
    {
        $campaigns = $userData['campaigns'] ?? [];
        
        if (empty($campaigns)) {
            return "📝 *Recent Activity*\nNo campaigns sent yet.";
        }

        // Get latest campaigns (last 3)
        $recentCampaigns = array_slice($campaigns, -3, 3, true);
        $recentCampaigns = array_reverse($recentCampaigns, true);

        $message = "📝 *Recent Activity*\n";
        
        foreach ($recentCampaigns as $campaign) {
            $date = date('M j, H:i', $campaign['created_at'] ?? time());
            $subject = substr($campaign['subject'] ?? 'No Subject', 0, 30);
            $count = $campaign['recipient_count'] ?? 0;
            $status = $campaign['status'] ?? 'unknown';
            
            $statusIcon = match($status) {
                'completed' => '✅',
                'failed' => '❌',
                'pending' => '⏳',
                default => '❓'
            };
            
            $message .= "• $date: \"$subject\" ($count emails) $statusIcon\n";
        }

        return $message;
    }

    /**
     * Get status keyboard markup
     * 
     * @return array Keyboard markup
     */
    private function getStatusKeyboard()
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Refresh', 'callback_data' => 'status_refresh'],
                    ['text' => '💎 Upgrade Plan', 'callback_data' => 'show_plans']
                ],
                [
                    ['text' => '📊 Detailed Stats', 'callback_data' => 'detailed_stats'],
                    ['text' => '⚙️ Settings', 'callback_data' => 'settings']
                ],
                [
                    ['text' => '🏠 Main Menu', 'callback_data' => 'main_menu']
                ]
            ]
        ];
    }
}
