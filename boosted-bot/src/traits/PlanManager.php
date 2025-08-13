<?php

namespace App\Traits;

trait PlanManager {
    /**
     * Compute and persist derived fields for UI: remaining_this_hour, remaining_today, plan_days_left.
     * Stores -1 for unlimited to keep JSON numeric.
     */
    private function refreshDerivedQuotaFields($chatId) {
        if (!isset($this->userData[$chatId])) { return; }
        $u =& $this->userData[$chatId];
        $plan = $this->getPlanLimits($u['plan'] ?? 'free');
        if (!$plan) { return; }

        $hourCap = isset($plan['emails_per_hour']) ? (int)$plan['emails_per_hour'] : 0;
        $dayCap  = isset($plan['emails_per_day']) ? (int)$plan['emails_per_day'] : 0;
        $sentH   = isset($u['emails_sent_hour']) ? (int)$u['emails_sent_hour'] : 0;
        $sentD   = isset($u['emails_sent_today']) ? (int)$u['emails_sent_today'] : 0;

        $u['remaining_this_hour'] = ($hourCap === -1) ? -1 : max(0, $hourCap - $sentH);
        $u['remaining_today']     = ($dayCap === -1)  ? -1 : max(0, $dayCap  - $sentD);

        // Plan days left
        if (isset($u['plan_expires'])) {
            $u['plan_days_left'] = ($u['plan_expires'] > time())
                ? (int)ceil(($u['plan_expires'] - time()) / 86400)
                : 0;
        }
    }
    /**
     * Check and update user's plan status
     * 
     * @param string $chatId User's chat ID
     * @return array Status information
     */
    private function checkPlanStatus($chatId) {
        if (!isset($this->userData[$chatId])) {
            return ['valid' => false, 'message' => 'User not found'];
        }

        $userData = &$this->userData[$chatId];
        $currentTime = time();

        // Check plan expiration
        if (isset($userData['plan_expires']) && $currentTime >= $userData['plan_expires']) {
            // Plan has expired: mark state and notify once. Do NOT auto-downgrade or reset limits.
            $userData['is_expired'] = true;
            $userData['settings']['premium'] = false;
            if (!isset($userData['expired_notified_at'])) {
                $message = "⚠️ *Your Plan Has Expired*\n\n";
                $message .= "Your {$userData['plan']} plan has expired.\n";
                $message .= "Please purchase a new plan to continue using premium features.";

                $keyboard = [
                    [['text' => '🔄 View Plans', 'callback_data' => 'plans']]
                ];

                $this->sendTelegramMessage($chatId, $message, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
                $userData['expired_notified_at'] = $currentTime;
            }
            $this->saveUserData();
            return ['valid' => false, 'message' => 'Plan expired'];
        }

        // Get plan limits
        $plan = $this->getPlanLimits($userData['plan']);
        if (!$plan) {
            return ['valid' => false, 'message' => 'Invalid plan'];
        }

        // Check hourly limit
        $hourReset = $this->checkHourlyLimit($chatId);
        if ($hourReset) {
            $message = "⏳ *Hourly Limit Reset*\n\n";
            $message .= "Your hourly email limit has been reset.\n";
            $message .= "New limit: {$plan['emails_per_hour']} emails per hour";
            
            $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
        }

        // Check daily limit
        $dayReset = $this->checkDailyLimit($chatId);
        if ($dayReset) {
            $message = "📅 *Daily Limit Reset*\n\n";
            $message .= "Your daily email limit has been reset.\n";
            $message .= "New limit: {$plan['emails_per_day']} emails per day";
            
            $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
        }

        // Check if user is approaching limits
        $this->checkApproachingLimits($chatId);

        return ['valid' => true, 'plan' => $plan];
    }

    /**
     * Determine if the user's plan is expired.
     *
     * @param string $chatId
     * @return bool
     */
    private function isPlanExpired($chatId) {
        if (!isset($this->userData[$chatId])) { return false; }
        $userData = $this->userData[$chatId];
        return isset($userData['plan_expires']) && time() >= $userData['plan_expires'];
    }

    /**
     * Get plan limits from configuration
     * 
     * @param string $planId Plan identifier
     * @return array|null Plan configuration
     */
    private function getPlanLimits($planId) {
        return defined('PLANS') && isset(PLANS[$planId]) ? PLANS[$planId] : null;
    }

    /**
     * Check and reset hourly limits if needed
     * 
     * @param string $chatId User's chat ID
     * @return bool True if limit was reset
     */
    private function checkHourlyLimit($chatId) {
        if (!isset($this->userData[$chatId])) {
            return false;
        }

        $userData = &$this->userData[$chatId];
    // Avoid resetting counters during mere status refreshes when not actively sending
    $isActive = isset($userData['sending_state']) && ($userData['sending_state']['is_sending'] ?? false);
        $currentHour = strtotime(date('Y-m-d H:00:00'));
        // Initialize anchor without wiping counts if missing
        if (!isset($userData['last_hour_reset'])) {
            $userData['last_hour_reset'] = $currentHour;
            if (!isset($userData['emails_sent_hour'])) { $userData['emails_sent_hour'] = 0; }
            // Update derived remaining
            $this->refreshDerivedQuotaFields($chatId);
            $this->saveUserData();
            return false;
        }
        // Reset only when the hour boundary has passed
    if ($isActive && $userData['last_hour_reset'] < $currentHour) {
            $userData['emails_sent_hour'] = 0;
            $userData['last_hour_reset'] = $currentHour;
            $this->refreshDerivedQuotaFields($chatId);
            $this->saveUserData();
            return true;
        }

        return false;
    }

    /**
     * Check and reset daily limits if needed
     * 
     * @param string $chatId User's chat ID
     * @return bool True if limit was reset
     */
    private function checkDailyLimit($chatId) {
        if (!isset($this->userData[$chatId])) {
            return false;
        }

        $userData = &$this->userData[$chatId];
    // Avoid resetting counters during mere status refreshes when not actively sending
    $isActive = isset($userData['sending_state']) && ($userData['sending_state']['is_sending'] ?? false);
        $currentDay = strtotime(date('Y-m-d'));
        // Initialize anchor without wiping counts if missing
        if (!isset($userData['last_day_reset'])) {
            $userData['last_day_reset'] = $currentDay;
            if (!isset($userData['emails_sent_today'])) { $userData['emails_sent_today'] = 0; }
            $this->refreshDerivedQuotaFields($chatId);
            $this->saveUserData();
            return false;
        }
        // Reset only when the day boundary has passed
    if ($isActive && $userData['last_day_reset'] < $currentDay) {
            $userData['emails_sent_today'] = 0;
            $userData['last_day_reset'] = $currentDay;
            $this->refreshDerivedQuotaFields($chatId);
            $this->saveUserData();
            return true;
        }

        return false;
    }

    /**
     * Check and reset monthly limits if the month has changed.
     * Resets emails_sent_month at the start of a new UTC month.
     *
     * @param string $chatId User's chat ID
     * @return bool True if reset occurred
     */
    private function checkMonthlyReset($chatId) {
        if (!isset($this->userData[$chatId])) {
            return false;
        }

        $userData = &$this->userData[$chatId];
        // First day of current month at 00:00 UTC
        $currentMonthStart = strtotime(gmdate('Y-m-01 00:00:00'));

        // Initialize anchor without wiping counts if missing
        if (!isset($userData['last_month_reset'])) {
            $userData['last_month_reset'] = $currentMonthStart;
            if (!isset($userData['emails_sent_month'])) { $userData['emails_sent_month'] = 0; }
            $this->saveUserData();
            return false;
        }
        if ($userData['last_month_reset'] < $currentMonthStart) {
            $userData['emails_sent_month'] = 0;
            $userData['last_month_reset'] = $currentMonthStart;
            $this->saveUserData();
            return true;
        }
        return false;
    }

    /**
     * Check if user is approaching their limits and notify them
     * 
     * @param string $chatId User's chat ID
     */
    private function checkApproachingLimits($chatId) {
        if (!isset($this->userData[$chatId])) {
            return;
        }

        $userData = $this->userData[$chatId];
        // Skip notifications when expired
        if (isset($userData['plan_expires']) && time() >= $userData['plan_expires']) {
            return;
        }
        $plan = $this->getPlanLimits($userData['plan']);
        
        if (!$plan) {
            return;
        }

        // Normalize plan limits (-1 means Unlimited)
        $planHour = isset($plan['emails_per_hour']) ? (int)$plan['emails_per_hour'] : 0;
        $planDay = isset($plan['emails_per_day']) ? (int)$plan['emails_per_day'] : 0;
        $isHourUnlimited = ($planHour === -1);
        $isDayUnlimited = ($planDay === -1);

        // Ensure counters exist
        $sentHour = isset($userData['emails_sent_hour']) ? (int)$userData['emails_sent_hour'] : 0;
        $sentDay = isset($userData['emails_sent_today']) ? (int)$userData['emails_sent_today'] : 0;

        // Check hourly limit (only when not unlimited and divisor > 0)
        if (!$isHourUnlimited && $planHour > 0) {
            $hourlyUsage = ($sentHour / $planHour) * 100;
            if ($hourlyUsage >= 80 && $hourlyUsage < 90) {
                $remaining = max(0, $planHour - $sentHour);
                $message = "⚠️ *Approaching Hourly Limit*\n\n";
                $message .= "You have {$remaining} emails remaining this hour.\n";
                $message .= "Your limit will reset at " . date('H:00', strtotime('+1 hour'));
                $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
            }
        }

        // Check daily limit (only when not unlimited and divisor > 0)
        if (!$isDayUnlimited && $planDay > 0) {
            $dailyUsage = ($sentDay / $planDay) * 100;
            if ($dailyUsage >= 80 && $dailyUsage < 90) {
                $remaining = max(0, $planDay - $sentDay);
                $message = "⚠️ *Approaching Daily Limit*\n\n";
                $message .= "You have {$remaining} emails remaining today.\n";
                $message .= "Your limit will reset at midnight UTC";
                $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
            }
        }
    }

    /**
     * Check if user can send more emails
     * 
     * @param string $chatId User's chat ID
     * @return array Status check result
     */
    private function canSendEmails($chatId) {
        if (!isset($this->userData[$chatId])) {
            return ['can_send' => false, 'message' => 'User not found'];
        }

        $userData = $this->userData[$chatId];
        // Hard gate on expired plan
        if (isset($userData['plan_expires']) && time() >= $userData['plan_expires']) {
            $message = "❌ *Plan Expired*\n\n";
            $message .= "Please renew your plan to continue sending emails.";
            return ['can_send' => false, 'message' => $message];
        }
        $plan = $this->getPlanLimits($userData['plan']);
        
        if (!$plan) {
            return ['can_send' => false, 'message' => 'Invalid plan'];
        }

        // Normalize plan limits (-1 means Unlimited)
        $planHour = isset($plan['emails_per_hour']) ? (int)$plan['emails_per_hour'] : 0;
        $planDay = isset($plan['emails_per_day']) ? (int)$plan['emails_per_day'] : 0;
        $hourUnlimited = ($planHour === -1);
        $dayUnlimited = ($planDay === -1);

        // Ensure counters exist
        $sentHour = isset($userData['emails_sent_hour']) ? (int)$userData['emails_sent_hour'] : 0;
        $sentDay = isset($userData['emails_sent_today']) ? (int)$userData['emails_sent_today'] : 0;

        // Check hourly limit if not unlimited
        if (!$hourUnlimited && $sentHour >= $planHour) {
            $nextHour = strtotime(date('Y-m-d H:00:00')) + 3600;
            $message = "❌ *Hourly Limit Reached*\n\n";
            $message .= "You have reached your hourly limit of {$planHour} emails.\n";
            $message .= "Next reset: " . date('H:i', $nextHour) . " UTC";
            return ['can_send' => false, 'message' => $message];
        }

        // Check daily limit if not unlimited
        if (!$dayUnlimited && $sentDay >= $planDay) {
            $nextDay = strtotime('tomorrow midnight');
            $message = "❌ *Daily Limit Reached*\n\n";
            $message .= "You have reached your daily limit of {$planDay} emails.\n";
            $message .= "Next reset: " . date('Y-m-d H:i', $nextDay) . " UTC";
            return ['can_send' => false, 'message' => $message];
        }

        return ['can_send' => true];
    }

    /**
     * Update email send counters
     * 
     * @param string $chatId User's chat ID
     * @param int $count Number of emails sent
     */
    private function updateEmailCounters($chatId, $count = 1) {
        if (!isset($this->userData[$chatId])) {
            return;
        }

        $userData = &$this->userData[$chatId];
        // Ensure counters exist to avoid undefined index warnings and guarantee correct math
        if (!isset($userData['emails_sent_hour']) || !is_numeric($userData['emails_sent_hour'])) {
            $userData['emails_sent_hour'] = 0;
        }
        if (!isset($userData['emails_sent_today']) || !is_numeric($userData['emails_sent_today'])) {
            $userData['emails_sent_today'] = 0;
        }
        if (!isset($userData['emails_sent_month']) || !is_numeric($userData['emails_sent_month'])) {
            $userData['emails_sent_month'] = 0;
        }

        $userData['emails_sent_hour'] += $count;
        $userData['emails_sent_today'] += $count;
        $userData['emails_sent_month'] += $count;

    // Persist derived remaining counters and days left
    $this->refreshDerivedQuotaFields($chatId);
    $this->saveUserData();

        // Check limits after update
        $this->checkApproachingLimits($chatId);
    }
}
