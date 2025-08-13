<?php

namespace App\Services;

/**
 * User Service
 * 
 * Handles user management and operations
 */
class UserService
{
    // User data storage
    private $userData = [];
    
    // Config manager
    private $configManager;
    
    // Logger
    private $logger;
    
    // Plan data
    private $plans = [];
    
    /**
     * Constructor
     * 
     * @param object $configManager ConfigManager instance
     * @param object|null $logger Logger instance
     */
    public function __construct($configManager, $logger = null)
    {
        $this->configManager = $configManager;
        $this->logger = $logger;
        
        // Load plans
        $this->plans = $configManager->get('plans', []);
        
        // Load user data
        $this->loadUserData();
    }
    
    /**
     * Load user data
     * 
     * @return bool Success status
     */
    public function loadUserData()
    {
        $userData = $this->configManager->loadUserData();
        
        if (is_array($userData)) {
            $this->userData = $userData;
            return true;
        }
        
        return false;
    }
    
    /**
     * Save user data
     * 
     * @return bool Success status
     */
    public function saveUserData()
    {
        return $this->configManager->saveUserData($this->userData);
    }
    
    /**
     * Get user data
     * 
     * @param string $chatId Chat ID
     * @return array|null User data
     */
    public function getUserData($chatId)
    {
        return $this->userData[$chatId] ?? null;
    }
    
    /**
     * Save specific user data
     * 
     * @param string $chatId Chat ID
     * @param array $data User data
     * @return bool Success status
     */
    public function saveUser($chatId, $data)
    {
        $this->userData[$chatId] = $data;
        return $this->saveUserData();
    }
    
    /**
     * Update user data
     * 
     * @param string $chatId Chat ID
     * @param array $data Data to update
     * @return bool Success status
     */
    public function updateUser($chatId, $data)
    {
        if (!isset($this->userData[$chatId])) {
            $this->userData[$chatId] = [];
        }
        
        $this->userData[$chatId] = array_merge($this->userData[$chatId], $data);
        return $this->saveUserData();
    }
    
    /**
     * Set user state
     * 
     * @param string $chatId Chat ID
     * @param string $state State name
     * @param array $data State data
     * @return bool Success status
     */
    public function setState($chatId, $state, $data = [])
    {
        if (!isset($this->userData[$chatId])) {
            $this->userData[$chatId] = [];
        }
        
        $this->userData[$chatId]['state'] = $state;
        
        if (!empty($data)) {
            if (!isset($this->userData[$chatId]['state_data'])) {
                $this->userData[$chatId]['state_data'] = [];
            }
            
            $this->userData[$chatId]['state_data'] = $data;
        }
        
        return $this->saveUserData();
    }
    
    /**
     * Get user state
     * 
     * @param string $chatId Chat ID
     * @return string|null Current state
     */
    public function getState($chatId)
    {
        return $this->userData[$chatId]['state'] ?? null;
    }
    
    /**
     * Get user state data
     * 
     * @param string $chatId Chat ID
     * @return array State data
     */
    public function getStateData($chatId)
    {
        return $this->userData[$chatId]['state_data'] ?? [];
    }
    
    /**
     * Clear user state
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    public function clearState($chatId)
    {
        if (isset($this->userData[$chatId])) {
            unset($this->userData[$chatId]['state']);
            unset($this->userData[$chatId]['state_data']);
            return $this->saveUserData();
        }
        
        return false;
    }
    
    /**
     * Set user plan
     * 
     * @param string $chatId Chat ID
     * @param string $planId Plan ID
     * @param int|null $duration Override duration in days (null to use plan default)
     * @return bool Success status
     */
    public function setPlan($chatId, $planId, $duration = null)
    {
        if (!isset($this->plans[$planId])) {
            if ($this->logger) {
                $this->logger->warning("Invalid plan ID: {$planId}");
            }
            return false;
        }
        
        if (!isset($this->userData[$chatId])) {
            $this->userData[$chatId] = [];
        }
        
        $plan = $this->plans[$planId];
        
        // Set plan data
        $this->userData[$chatId]['plan'] = $planId;
        $this->userData[$chatId]['plan_start'] = time();
        
        // Set plan duration
        if ($duration !== null) {
            $this->userData[$chatId]['plan_duration'] = (int)$duration;
        } else {
            $this->userData[$chatId]['plan_duration'] = $plan['duration'] ?? 0;
        }
        
        // Calculate expiry time
        if ($this->userData[$chatId]['plan_duration'] > 0) {
            $this->userData[$chatId]['plan_expiry'] = time() + ($this->userData[$chatId]['plan_duration'] * 86400);
        } else {
            // Unlimited duration
            $this->userData[$chatId]['plan_expiry'] = -1;
        }
        
        return $this->saveUserData();
    }
    
    /**
     * Check if plan is expired
     * 
     * @param string $chatId Chat ID
     * @return bool Expiration status
     */
    public function isPlanExpired($chatId)
    {
        $userData = $this->getUserData($chatId);
        if (!$userData) {
            return true;
        }
        
        // No plan
        if (!isset($userData['plan'])) {
            return true;
        }
        
        // Unlimited plan
        if (isset($userData['plan_expiry']) && $userData['plan_expiry'] == -1) {
            return false;
        }
        
        // Check expiry
        if (isset($userData['plan_expiry']) && $userData['plan_expiry'] > time()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get user plan information
     * 
     * @param string $chatId Chat ID
     * @return array|null Plan information
     */
    public function getUserPlan($chatId)
    {
        $userData = $this->getUserData($chatId);
        
        if (!$userData) {
            return null;
        }
        
        $planName = $userData['plan'] ?? 'trial';
        $plans = $this->config->get('plans', []);
        
        if (!isset($plans[$planName])) {
            // Fallback to trial if plan not found
            $planName = 'trial';
            if (!isset($plans[$planName])) {
                return null;
            }
        }
        
        $plan = $plans[$planName];
        
        // Add user-specific information
        $planExpiry = $userData['plan_expires'] ?? 0;
        
        return [
            'name' => $planName,
            'display_name' => $plan['name'] ?? $planName,
            'duration' => $plan['duration'] ?? 30,
            'price' => $plan['price'] ?? 0,
            'emails_per_hour' => $plan['emails_per_hour'] ?? 30,
            'emails_per_day' => $plan['emails_per_day'] ?? 100,
            'description' => $plan['description'] ?? '',
            'expiry_time' => $planExpiry,
            'expired' => $planExpiry > 0 && $planExpiry < time(),
            'max_campaigns' => $plan['max_campaigns'] ?? 1
        ];
    }
    
    /**
     * Update user email stats
     * 
     * @param string $chatId Chat ID
     * @param int $sent Number of emails sent
     * @param int $errors Number of errors
     * @return bool Success status
     */
    public function updateEmailStats($chatId, $sent = 0, $errors = 0)
    {
        if (!isset($this->userData[$chatId])) {
            return false;
        }
        
        if (!isset($this->userData[$chatId]['email_stats'])) {
            $this->userData[$chatId]['email_stats'] = [
                'total_sent' => 0,
                'total_errors' => 0,
                'campaigns' => 0,
                'last_campaign' => null
            ];
        }
        
        $this->userData[$chatId]['email_stats']['total_sent'] += $sent;
        $this->userData[$chatId]['email_stats']['total_errors'] += $errors;
        
        // Update daily and monthly counts
        $now = time();
        $today = date('Y-m-d', $now);
        $month = date('Y-m', $now);
        
        if (!isset($this->userData[$chatId]['daily_stats'])) {
            $this->userData[$chatId]['daily_stats'] = [];
        }
        
        if (!isset($this->userData[$chatId]['monthly_stats'])) {
            $this->userData[$chatId]['monthly_stats'] = [];
        }
        
        if (!isset($this->userData[$chatId]['daily_stats'][$today])) {
            $this->userData[$chatId]['daily_stats'][$today] = [
                'sent' => 0,
                'errors' => 0
            ];
        }
        
        if (!isset($this->userData[$chatId]['monthly_stats'][$month])) {
            $this->userData[$chatId]['monthly_stats'][$month] = [
                'sent' => 0,
                'errors' => 0
            ];
        }
        
        $this->userData[$chatId]['daily_stats'][$today]['sent'] += $sent;
        $this->userData[$chatId]['daily_stats'][$today]['errors'] += $errors;
        
        $this->userData[$chatId]['monthly_stats'][$month]['sent'] += $sent;
        $this->userData[$chatId]['monthly_stats'][$month]['errors'] += $errors;
        
        return $this->saveUserData();
    }
    
    /**
     * Start new campaign
     * 
     * @param string $chatId Chat ID
     * @param array $campaignData Campaign data
     * @return string|false Campaign ID or false on failure
     */
    public function startCampaign($chatId, $campaignData)
    {
        if (!isset($this->userData[$chatId])) {
            return false;
        }
        
        // Create campaign ID
        $campaignId = uniqid('campaign_');
        
        // Initialize campaign
        if (!isset($this->userData[$chatId]['campaigns'])) {
            $this->userData[$chatId]['campaigns'] = [];
        }
        
        $this->userData[$chatId]['campaigns'][$campaignId] = array_merge([
            'id' => $campaignId,
            'start_time' => time(),
            'status' => 'active',
            'sent' => 0,
            'errors' => 0
        ], $campaignData);
        
        // Update email stats
        if (!isset($this->userData[$chatId]['email_stats'])) {
            $this->userData[$chatId]['email_stats'] = [
                'total_sent' => 0,
                'total_errors' => 0,
                'campaigns' => 0,
                'last_campaign' => null
            ];
        }
        
        $this->userData[$chatId]['email_stats']['campaigns']++;
        $this->userData[$chatId]['email_stats']['last_campaign'] = $campaignId;
        
        if (!$this->saveUserData()) {
            return false;
        }
        
        return $campaignId;
    }
    
    /**
     * Update campaign stats
     * 
     * @param string $chatId Chat ID
     * @param string $campaignId Campaign ID
     * @param int $sent Number of emails sent
     * @param int $errors Number of errors
     * @return bool Success status
     */
    public function updateCampaignStats($chatId, $campaignId, $sent = 0, $errors = 0)
    {
        if (!isset($this->userData[$chatId]) || 
            !isset($this->userData[$chatId]['campaigns']) ||
            !isset($this->userData[$chatId]['campaigns'][$campaignId])) {
            return false;
        }
        
        $campaign = &$this->userData[$chatId]['campaigns'][$campaignId];
        
        $campaign['sent'] += $sent;
        $campaign['errors'] += $errors;
        
        if ($sent > 0 || $errors > 0) {
            $campaign['last_activity'] = time();
        }
        
        // Update user email stats too
        $this->updateEmailStats($chatId, $sent, $errors);
        
        return $this->saveUserData();
    }
    
    /**
     * Complete campaign
     * 
     * @param string $chatId Chat ID
     * @param string $campaignId Campaign ID
     * @return bool Success status
     */
    public function completeCampaign($chatId, $campaignId)
    {
        if (!isset($this->userData[$chatId]) || 
            !isset($this->userData[$chatId]['campaigns']) ||
            !isset($this->userData[$chatId]['campaigns'][$campaignId])) {
            return false;
        }
        
        $this->userData[$chatId]['campaigns'][$campaignId]['status'] = 'completed';
        $this->userData[$chatId]['campaigns'][$campaignId]['end_time'] = time();
        
        return $this->saveUserData();
    }
    
    /**
     * Get all users
     * 
     * @return array User data
     */
    public function getAllUsers()
    {
        return $this->userData;
    }
    
    /**
     * Get user count
     * 
     * @return int Number of users
     */
    public function getUserCount()
    {
        return count($this->userData);
    }
    
    /**
     * Delete user
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    public function deleteUser($chatId)
    {
        if (isset($this->userData[$chatId])) {
            unset($this->userData[$chatId]);
            return $this->saveUserData();
        }
        
        return false;
    }
}
