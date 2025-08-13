<?php

namespace App\Services;

use App\Interfaces\ServiceInterface;

/**
 * Plan Service
 * 
 * Manages user plans, validation, and pricing
 */
class PlanService implements ServiceInterface
{
    // Config manager
    private $config;
    
    // Logger
    private $logger;
    
    /**
     * Constructor
     * 
     * @param ConfigManager $config Configuration manager
     * @param Logger $logger Logger instance
     */
    public function __construct(ConfigManager $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    /**
     * Initialize the service
     */
    public function initialize(): bool
    {
        try {
            $this->logger?->info('PlanService initialized');
            return true;
        } catch (\Exception $e) {
            $this->logger?->error('PlanService initialization failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Get all available plans
     * 
     * @return array Available plans
     */
    public function getAvailablePlans()
    {
        return $this->config->get('plans', []);
    }
    
    /**
     * Get specific plan by name
     * 
     * @param string $planName Plan name
     * @return array|null Plan data or null if not found
     */
    public function getPlan($planName)
    {
        $plans = $this->getAvailablePlans();
        
        return isset($plans[$planName]) ? $plans[$planName] : null;
    }
    
    /**
     * Validate plan exists and is active
     * 
     * @param string $planName Plan name
     * @return bool Plan validity
     */
    public function isValidPlan($planName)
    {
        $plan = $this->getPlan($planName);
        
        return $plan !== null;
    }
    
    /**
     * Get plan details with calculated information
     * 
     * @param string $planName Plan name
     * @return array|null Extended plan information
     */
    public function getPlanDetails($planName)
    {
        $plan = $this->getPlan($planName);
        
        if (!$plan) {
            return null;
        }
        
        // Calculate additional information
        $details = $plan;
        
        // Add formatted description
        $details['formatted_description'] = $this->formatPlanDescription($plan);
        
        // Add duration in different formats
        if (isset($plan['duration'])) {
            $details['duration_days'] = $plan['duration'];
            $details['duration_hours'] = $plan['duration'] * 24;
            $details['duration_seconds'] = $plan['duration'] * 86400;
        }
        
        // Add pricing information
        if (isset($plan['price'])) {
            $details['price_formatted'] = $this->formatPrice($plan['price']);
            $details['is_free'] = $plan['price'] == 0;
        }
        
        // Add limit information
        $details['has_hourly_limit'] = isset($plan['emails_per_hour']) && $plan['emails_per_hour'] !== -1;
        $details['has_daily_limit'] = isset($plan['emails_per_day']) && $plan['emails_per_day'] !== -1;
        $details['is_unlimited'] = !$details['has_hourly_limit'] && !$details['has_daily_limit'];
        
        return $details;
    }
    
    /**
     * Get user's current plan
     * 
     * @param array $userData User data
     * @return array|null Current plan details
     */
    public function getUserPlan($userData)
    {
        $planName = $userData['plan'] ?? 'trial';
        $plan = $this->getPlanDetails($planName);
        
        if (!$plan) {
            // Fallback to trial if plan not found
            $plan = $this->getPlanDetails('trial');
        }
        
        if (!$plan) {
            return null;
        }
        
        // Add user-specific information
        $planExpiry = $userData['plan_expires'] ?? 0;
        $plan['expiry_time'] = $planExpiry;
        $plan['expired'] = $planExpiry > 0 && $planExpiry < time();
        
        // Calculate remaining time
        if ($planExpiry > 0 && $planExpiry > time()) {
            $remaining = $planExpiry - time();
            $plan['days_remaining'] = ceil($remaining / 86400);
            $plan['hours_remaining'] = ceil($remaining / 3600);
        } else {
            $plan['days_remaining'] = 0;
            $plan['hours_remaining'] = 0;
        }
        
        return $plan;
    }
    
    /**
     * Check if user can send emails based on plan limits
     * 
     * @param array $userData User data
     * @param int $emailCount Number of emails to send
     * @return array Validation result
     */
    public function validateEmailLimits($userData, $emailCount = 1)
    {
        $plan = $this->getUserPlan($userData);
        
        if (!$plan) {
            return [
                'allowed' => false,
                'reason' => 'No valid plan found'
            ];
        }
        
        // Check if plan is expired
        if ($plan['expired']) {
            return [
                'allowed' => false,
                'reason' => 'Plan has expired'
            ];
        }
        
        // Get current usage
        $today = date('Y-m-d');
        $currentHour = date('H');
        $month = date('Y-m');
        
        $dailyUsage = $userData['daily_stats'][$today]['sent'] ?? 0;
        $monthlyUsage = $userData['monthly_stats'][$month]['sent'] ?? 0;
        $hourlyUsage = $userData['hourly_stats'][$today][$currentHour]['sent'] ?? 0;
        
        // Check hourly limit
        if (isset($plan['emails_per_hour']) && $plan['emails_per_hour'] !== -1) {
            $remainingHourly = $plan['emails_per_hour'] - $hourlyUsage;
            
            if ($remainingHourly < $emailCount) {
                return [
                    'allowed' => false,
                    'reason' => 'Hourly limit exceeded',
                    'limit' => $plan['emails_per_hour'],
                    'used' => $hourlyUsage,
                    'remaining' => $remainingHourly
                ];
            }
        }
        
        // Check daily limit
        if (isset($plan['emails_per_day']) && $plan['emails_per_day'] !== -1) {
            $remainingDaily = $plan['emails_per_day'] - $dailyUsage;
            
            if ($remainingDaily < $emailCount) {
                return [
                    'allowed' => false,
                    'reason' => 'Daily limit exceeded',
                    'limit' => $plan['emails_per_day'],
                    'used' => $dailyUsage,
                    'remaining' => $remainingDaily
                ];
            }
        }
        
        // Check system limits if plan allows unlimited
        $systemDailyLimit = $this->config->get('limits.emails_per_day', 10);
        $systemMonthlyLimit = $this->config->get('limits.emails_per_month', 100);
        
        if ($plan['emails_per_day'] === -1 && $dailyUsage + $emailCount > $systemDailyLimit) {
            return [
                'allowed' => false,
                'reason' => 'System daily limit exceeded',
                'limit' => $systemDailyLimit,
                'used' => $dailyUsage,
                'remaining' => $systemDailyLimit - $dailyUsage
            ];
        }
        
        if ($plan['emails_per_day'] === -1 && $monthlyUsage + $emailCount > $systemMonthlyLimit) {
            return [
                'allowed' => false,
                'reason' => 'System monthly limit exceeded',
                'limit' => $systemMonthlyLimit,
                'used' => $monthlyUsage,
                'remaining' => $systemMonthlyLimit - $monthlyUsage
            ];
        }
        
        return [
            'allowed' => true,
            'reason' => 'Within limits'
        ];
    }
    
    /**
     * Assign plan to user
     * 
     * @param string $chatId User chat ID
     * @param string $planName Plan name
     * @param int|null $customDuration Custom duration in days (optional)
     * @return array Assignment result
     */
    public function assignPlan($chatId, $planName, $customDuration = null)
    {
        $plan = $this->getPlan($planName);
        
        if (!$plan) {
            return [
                'success' => false,
                'message' => 'Invalid plan specified'
            ];
        }
        
        // Calculate expiry time
        $duration = $customDuration ?? $plan['duration'] ?? 30;
        $expiryTime = time() + ($duration * 86400);
        
        // Log plan assignment
        $this->logger->info("Assigning plan '{$planName}' to user {$chatId} (duration: {$duration} days)");
        
        return [
            'success' => true,
            'plan_name' => $planName,
            'expiry_time' => $expiryTime,
            'duration_days' => $duration,
            'message' => "Plan '{$plan['name']}' assigned successfully"
        ];
    }
    
    /**
     * Get additional services
     * 
     * @return array Additional services
     */
    public function getAdditionalServices()
    {
        return $this->config->get('additional_services', []);
    }
    
    /**
     * Get specific additional service
     * 
     * @param string $serviceName Service name
     * @return array|null Service data
     */
    public function getAdditionalService($serviceName)
    {
        $services = $this->getAdditionalServices();
        
        return isset($services[$serviceName]) ? $services[$serviceName] : null;
    }
    
    /**
     * Format plan description for display
     * 
     * @param array $plan Plan data
     * @return string Formatted description
     */
    private function formatPlanDescription($plan)
    {
        if (isset($plan['description'])) {
            return $plan['description'];
        }
        
        // Generate description from plan data
        $description = "📋 *{$plan['name']}*\n\n";
        
        if (isset($plan['duration'])) {
            $description .= "⏱️ Duration: {$plan['duration']} days\n";
        }
        
        if (isset($plan['price'])) {
            $description .= "💰 Price: " . $this->formatPrice($plan['price']) . "\n";
        }
        
        if (isset($plan['emails_per_hour'])) {
            $hourly = $plan['emails_per_hour'] === -1 ? 'Unlimited' : number_format($plan['emails_per_hour']);
            $description .= "📧 Emails/hour: {$hourly}\n";
        }
        
        if (isset($plan['emails_per_day'])) {
            $daily = $plan['emails_per_day'] === -1 ? 'Unlimited' : number_format($plan['emails_per_day']);
            $description .= "📅 Emails/day: {$daily}\n";
        }
        
        return $description;
    }
    
    /**
     * Format price for display
     * 
     * @param float $price Price amount
     * @return string Formatted price
     */
    private function formatPrice($price)
    {
        if ($price == 0) {
            return 'Free';
        }
        
        return '$' . number_format($price, 2);
    }
    
    /**
     * Get plan upgrade recommendations
     * 
     * @param array $userData User data
     * @return array Upgrade recommendations
     */
    public function getUpgradeRecommendations($userData)
    {
        $currentPlan = $this->getUserPlan($userData);
        $allPlans = $this->getAvailablePlans();
        
        $recommendations = [];
        
        // Get usage statistics
        $today = date('Y-m-d');
        $month = date('Y-m');
        $dailyUsage = $userData['daily_stats'][$today]['sent'] ?? 0;
        $monthlyUsage = $userData['monthly_stats'][$month]['sent'] ?? 0;
        
        foreach ($allPlans as $planName => $plan) {
            // Skip current plan
            if ($currentPlan && $planName === $currentPlan['name']) {
                continue;
            }
            
            $recommendation = $this->getPlanDetails($planName);
            $recommendation['recommended'] = false;
            $recommendation['reason'] = '';
            
            // Check if this plan would solve current limitations
            if ($currentPlan) {
                // Check if user is hitting limits
                $currentHourlyLimit = $currentPlan['emails_per_hour'] ?? -1;
                $currentDailyLimit = $currentPlan['emails_per_day'] ?? -1;
                
                $planHourlyLimit = $plan['emails_per_hour'] ?? -1;
                $planDailyLimit = $plan['emails_per_day'] ?? -1;
                
                // Recommend if this plan has higher limits
                if (($currentHourlyLimit !== -1 && ($planHourlyLimit === -1 || $planHourlyLimit > $currentHourlyLimit)) ||
                    ($currentDailyLimit !== -1 && ($planDailyLimit === -1 || $planDailyLimit > $currentDailyLimit))) {
                    
                    $recommendation['recommended'] = true;
                    $recommendation['reason'] = 'Higher email limits';
                }
                
                // Check if current plan is expired
                if ($currentPlan['expired']) {
                    $recommendation['recommended'] = true;
                    $recommendation['reason'] = 'Current plan expired';
                }
            }
            
            $recommendations[] = $recommendation;
        }
        
        // Sort by price (free first, then ascending)
        usort($recommendations, function($a, $b) {
            if ($a['price'] == 0 && $b['price'] > 0) return -1;
            if ($b['price'] == 0 && $a['price'] > 0) return 1;
            return $a['price'] <=> $b['price'];
        });
        
        return $recommendations;
    }

    /**
     * Check if the service is healthy and operational
     */
    public function isHealthy(): bool
    {
        return $this->config !== null && !empty($this->getAvailablePlans());
    }

    /**
     * Get the current status of the service
     */
    public function getStatus(): array
    {
        $plans = $this->getAvailablePlans();
        return [
            'service' => 'PlanService',
            'status' => 'operational',
            'plans_loaded' => count($plans),
            'available_plans' => array_keys($plans)
        ];
    }
}
