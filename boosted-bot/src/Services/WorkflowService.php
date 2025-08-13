<?php

namespace App\Services;

use App\Interfaces\ServiceInterface;

/**
 * Workflow Service
 * 
 * Orchestrates complex workflows like email campaigns
 */
class WorkflowService implements ServiceInterface
{
    // Services
    private $config;
    private $logger;
    private $userService;
    private $planService;
    private $smtpManager;
    
    // Workflow states
    const STATE_PENDING = 'pending';
    const STATE_RUNNING = 'running';
    const STATE_PAUSED = 'paused';
    const STATE_COMPLETED = 'completed';
    const STATE_FAILED = 'failed';
    const STATE_CANCELLED = 'cancelled';
    
    /**
     * Constructor
     * 
     * @param ConfigManager $config Configuration manager
     * @param Logger $logger Logger instance
     * @param UserService $userService User service
     * @param PlanService $planService Plan service
     * @param SmtpManager|null $smtpManager SMTP manager (optional)
     */
    public function __construct(
        ConfigManager $config,
        Logger $logger,
        UserService $userService,
        PlanService $planService,
        $smtpManager = null
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->userService = $userService;
        $this->planService = $planService;
        $this->smtpManager = $smtpManager;
    }
    
    /**
     * Initialize the service
     */
    public function initialize(): bool
    {
        try {
            $this->logger?->info('WorkflowService initialized');
            return true;
        } catch (\Exception $e) {
            $this->logger?->error('WorkflowService initialization failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Validate campaign requirements
     * 
     * @param string $chatId User chat ID
     * @return array Validation result
     */
    public function validateCampaignRequirements($chatId)
    {
        $userData = $this->userService->getUserData($chatId);
        
        if (!$userData) {
            return [
                'valid' => false,
                'errors' => ['User data not found']
            ];
        }
        
        $errors = [];
        $warnings = [];
        
        // Check plan status
        $plan = $this->planService->getUserPlan($userData);
        if (!$plan) {
            $errors[] = 'No active plan found';
        } elseif ($plan['expired']) {
            $errors[] = 'Plan has expired';
        }
        
        // Check required files
        $files = $userData['files'] ?? [];
        
        if (!isset($files['html']) || !file_exists($files['html'])) {
            $errors[] = 'HTML template not uploaded or file missing';
        }
        
        if (!isset($files['txt']) || !file_exists($files['txt'])) {
            $errors[] = 'Email list not uploaded or file missing';
        }
        
        // Check email subject
        if (empty($userData['subject'])) {
            $warnings[] = 'No email subject set - will use default';
        }
        
        // Check sender name
        if (empty($userData['sender_name'])) {
            $warnings[] = 'No sender name set - will use default';
        }
        
        // Check SMTP configuration
        $smtpConfig = $userData['smtp'] ?? null;
        $systemSmtps = $this->smtpManager->getActiveSmtps();
        
        if (!$smtpConfig && empty($systemSmtps)) {
            $errors[] = 'No SMTP configuration available';
        }
        
        // Validate email list if file exists
        if (isset($files['txt']) && file_exists($files['txt'])) {
            $emailValidation = $this->validateEmailList($files['txt']);
            
            if ($emailValidation['valid_count'] === 0) {
                $errors[] = 'No valid email addresses found in list';
            } elseif ($emailValidation['valid_count'] < $emailValidation['total_count']) {
                $warnings[] = sprintf(
                    'Only %d of %d email addresses are valid',
                    $emailValidation['valid_count'],
                    $emailValidation['total_count']
                );
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * Validate email list file
     * 
     * @param string $filePath Path to email list file
     * @return array Validation result
     */
    public function validateEmailList($filePath)
    {
        if (!file_exists($filePath)) {
            return [
                'total_count' => 0,
                'valid_count' => 0,
                'invalid_emails' => [],
                'duplicates' => []
            ];
        }
        
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);
        
        $totalCount = 0;
        $validCount = 0;
        $invalidEmails = [];
        $duplicates = [];
        $seenEmails = [];
        
        foreach ($lines as $line) {
            $email = trim($line);
            
            if (empty($email)) {
                continue;
            }
            
            $totalCount++;
            
            // Check for duplicates
            if (isset($seenEmails[$email])) {
                $duplicates[] = $email;
                continue;
            }
            
            $seenEmails[$email] = true;
            
            // Validate email format
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validCount++;
            } else {
                $invalidEmails[] = $email;
            }
        }
        
        return [
            'total_count' => $totalCount,
            'valid_count' => $validCount,
            'invalid_emails' => $invalidEmails,
            'duplicates' => $duplicates
        ];
    }
    
    /**
     * Prepare campaign for sending
     * 
     * @param string $chatId User chat ID
     * @param array $options Campaign options
     * @return array Preparation result
     */
    public function prepareCampaign($chatId, $options = [])
    {
        // Validate requirements
        $validation = $this->validateCampaignRequirements($chatId);
        
        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors']
            ];
        }
        
        $userData = $this->userService->getUserData($chatId);
        $plan = $this->planService->getUserPlan($userData);
        
        // Load email list
        $emailList = $this->loadEmailList($userData['files']['txt']);
        
        if (empty($emailList)) {
            return [
                'success' => false,
                'errors' => ['Failed to load email list']
            ];
        }
        
        // Check plan limits
        $limitValidation = $this->planService->validateEmailLimits($userData, count($emailList));
        
        if (!$limitValidation['allowed']) {
            return [
                'success' => false,
                'errors' => ['Plan limits exceeded: ' . $limitValidation['reason']]
            ];
        }
        
        // Load HTML template
        $htmlTemplate = file_get_contents($userData['files']['html']);
        
        if (!$htmlTemplate) {
            return [
                'success' => false,
                'errors' => ['Failed to load HTML template']
            ];
        }
        
        // Generate campaign ID
        $campaignId = $this->generateCampaignId($chatId);
        
        // Prepare campaign data
        $campaign = [
            'id' => $campaignId,
            'user_id' => $chatId,
            'subject' => $userData['subject'] ?? $this->config->get('trial.subject', 'Test Email'),
            'sender_name' => $userData['sender_name'] ?? 'ChetoBot',
            'html_template' => $htmlTemplate,
            'email_list' => $emailList,
            'total' => count($emailList),
            'sent' => 0,
            'errors' => 0,
            'progress' => 0,
            'status' => self::STATE_PENDING,
            'created_at' => time(),
            'start_time' => null,
            'end_time' => null,
            'last_send_time' => null,
            'smtp_config' => $userData['smtp'] ?? null,
            'use_system_smtp' => empty($userData['smtp']),
            'options' => array_merge([
                'delay_between_emails' => 1, // seconds
                'batch_size' => 10,
                'max_retries' => 3
            ], $options)
        ];
        
        // Save campaign
        $this->saveCampaign($campaign);
        
        // Update user data
        if (!isset($userData['active_campaigns'])) {
            $userData['active_campaigns'] = [];
        }
        
        $userData['active_campaigns'][$campaignId] = $campaign;
        $this->userService->saveUser($chatId, $userData);
        
        $this->logger->info("Campaign {$campaignId} prepared for user {$chatId} with {$campaign['total']} emails");
        
        return [
            'success' => true,
            'campaign_id' => $campaignId,
            'campaign' => $campaign,
            'warnings' => $validation['warnings'] ?? []
        ];
    }
    
    /**
     * Start campaign
     * 
     * @param string $campaignId Campaign ID
     * @return array Start result
     */
    public function startCampaign($campaignId)
    {
        $campaign = $this->loadCampaign($campaignId);
        
        if (!$campaign) {
            return [
                'success' => false,
                'error' => 'Campaign not found'
            ];
        }
        
        if ($campaign['status'] !== self::STATE_PENDING && $campaign['status'] !== self::STATE_PAUSED) {
            return [
                'success' => false,
                'error' => 'Campaign cannot be started in current state: ' . $campaign['status']
            ];
        }
        
        // Update campaign status
        $campaign['status'] = self::STATE_RUNNING;
        $campaign['start_time'] = time();
        
        $this->saveCampaign($campaign);
        
        // Update user data
        $userData = $this->userService->getUserData($campaign['user_id']);
        if (isset($userData['active_campaigns'][$campaignId])) {
            $userData['active_campaigns'][$campaignId] = $campaign;
            $this->userService->saveUser($campaign['user_id'], $userData);
        }
        
        $this->logger->info("Campaign {$campaignId} started for user {$campaign['user_id']}");
        
        return [
            'success' => true,
            'campaign' => $campaign
        ];
    }
    
    /**
     * Pause campaign
     * 
     * @param string $campaignId Campaign ID
     * @return array Pause result
     */
    public function pauseCampaign($campaignId)
    {
        $campaign = $this->loadCampaign($campaignId);
        
        if (!$campaign) {
            return [
                'success' => false,
                'error' => 'Campaign not found'
            ];
        }
        
        if ($campaign['status'] !== self::STATE_RUNNING) {
            return [
                'success' => false,
                'error' => 'Campaign is not running'
            ];
        }
        
        // Update campaign status
        $campaign['status'] = self::STATE_PAUSED;
        
        $this->saveCampaign($campaign);
        
        // Update user data
        $userData = $this->userService->getUserData($campaign['user_id']);
        if (isset($userData['active_campaigns'][$campaignId])) {
            $userData['active_campaigns'][$campaignId] = $campaign;
            $this->userService->saveUser($campaign['user_id'], $userData);
        }
        
        $this->logger->info("Campaign {$campaignId} paused for user {$campaign['user_id']}");
        
        return [
            'success' => true,
            'campaign' => $campaign
        ];
    }
    
    /**
     * Cancel campaign
     * 
     * @param string $campaignId Campaign ID
     * @return array Cancel result
     */
    public function cancelCampaign($campaignId)
    {
        $campaign = $this->loadCampaign($campaignId);
        
        if (!$campaign) {
            return [
                'success' => false,
                'error' => 'Campaign not found'
            ];
        }
        
        // Update campaign status
        $campaign['status'] = self::STATE_CANCELLED;
        $campaign['end_time'] = time();
        
        $this->saveCampaign($campaign);
        
        // Move from active to completed campaigns
        $userData = $this->userService->getUserData($campaign['user_id']);
        
        if (isset($userData['active_campaigns'][$campaignId])) {
            unset($userData['active_campaigns'][$campaignId]);
            
            if (!isset($userData['completed_campaigns'])) {
                $userData['completed_campaigns'] = [];
            }
            
            $userData['completed_campaigns'][$campaignId] = $campaign;
            $this->userService->saveUser($campaign['user_id'], $userData);
        }
        
        $this->logger->info("Campaign {$campaignId} cancelled for user {$campaign['user_id']}");
        
        return [
            'success' => true,
            'campaign' => $campaign
        ];
    }
    
    /**
     * Get campaign status
     * 
     * @param string $campaignId Campaign ID
     * @return array|null Campaign data
     */
    public function getCampaignStatus($campaignId)
    {
        return $this->loadCampaign($campaignId);
    }
    
    /**
     * Get user campaigns
     * 
     * @param string $chatId User chat ID
     * @return array User campaigns
     */
    public function getUserCampaigns($chatId)
    {
        $userData = $this->userService->getUserData($chatId);
        
        return [
            'active' => $userData['active_campaigns'] ?? [],
            'completed' => $userData['completed_campaigns'] ?? []
        ];
    }
    
    /**
     * Load email list from file
     * 
     * @param string $filePath File path
     * @return array Email list
     */
    private function loadEmailList($filePath)
    {
        if (!file_exists($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);
        
        $emails = [];
        
        foreach ($lines as $line) {
            $email = trim($line);
            
            if (empty($email)) {
                continue;
            }
            
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }
        
        return array_unique($emails);
    }
    
    /**
     * Generate unique campaign ID
     * 
     * @param string $chatId User chat ID
     * @return string Campaign ID
     */
    private function generateCampaignId($chatId)
    {
        return $chatId . '_' . time() . '_' . substr(md5(uniqid()), 0, 8);
    }
    
    /**
     * Save campaign data
     * 
     * @param array $campaign Campaign data
     * @return bool Success status
     */
    private function saveCampaign($campaign)
    {
        $campaignDir = dirname($this->config->get('paths.user_data')) . '/campaigns';
        
        if (!is_dir($campaignDir)) {
            mkdir($campaignDir, 0755, true);
        }
        
        $campaignFile = $campaignDir . '/' . $campaign['id'] . '.json';
        
        return file_put_contents($campaignFile, json_encode($campaign, JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * Load campaign data
     * 
     * @param string $campaignId Campaign ID
     * @return array|null Campaign data
     */
    private function loadCampaign($campaignId)
    {
        $campaignDir = dirname($this->config->get('paths.user_data')) . '/campaigns';
        $campaignFile = $campaignDir . '/' . $campaignId . '.json';
        
        if (!file_exists($campaignFile)) {
            return null;
        }
        
        $json = file_get_contents($campaignFile);
        
        if (!$json) {
            return null;
        }
        
        return json_decode($json, true);
    }
    
    /**
     * Process email sending for a campaign (to be called by queue worker)
     * 
     * @param string $campaignId Campaign ID
     * @param int $batchSize Number of emails to process
     * @return array Processing result
     */
    public function processCampaignBatch($campaignId, $batchSize = 10)
    {
        $campaign = $this->loadCampaign($campaignId);
        
        if (!$campaign || $campaign['status'] !== self::STATE_RUNNING) {
            return [
                'success' => false,
                'error' => 'Campaign not in running state'
            ];
        }
        
        // Get emails to send
        $emailsToSend = array_slice($campaign['email_list'], $campaign['progress'], $batchSize);
        
        if (empty($emailsToSend)) {
            // Campaign completed
            $campaign['status'] = self::STATE_COMPLETED;
            $campaign['end_time'] = time();
            $this->saveCampaign($campaign);
            
            return [
                'success' => true,
                'completed' => true,
                'campaign' => $campaign
            ];
        }
        
        $sent = 0;
        $errors = 0;
        
        foreach ($emailsToSend as $email) {
            // Here would be the actual email sending logic
            // For now, we'll simulate it
            
            $result = $this->sendEmail($campaign, $email);
            
            if ($result['success']) {
                $sent++;
            } else {
                $errors++;
            }
            
            $campaign['progress']++;
            $campaign['last_send_time'] = time();
            
            // Add delay between emails
            if ($campaign['options']['delay_between_emails'] > 0) {
                usleep($campaign['options']['delay_between_emails'] * 1000000);
            }
        }
        
        // Update campaign stats
        $campaign['sent'] += $sent;
        $campaign['errors'] += $errors;
        
        $this->saveCampaign($campaign);
        
        return [
            'success' => true,
            'sent' => $sent,
            'errors' => $errors,
            'campaign' => $campaign
        ];
    }
    
    /**
     * Send individual email (placeholder - would integrate with EmailSender)
     * 
     * @param array $campaign Campaign data
     * @param string $email Email address
     * @return array Send result
     */
    private function sendEmail($campaign, $email)
    {
        // This would integrate with the actual EmailSender class
        // For now, return a simulated result
        
        return [
            'success' => true,
            'email' => $email,
            'message' => 'Email sent successfully'
        ];
    }

    /**
     * Check if the service is healthy and operational
     */
    public function isHealthy(): bool
    {
        return $this->config !== null && $this->logger !== null;
    }

    /**
     * Get the current status of the service
     */
    public function getStatus(): array
    {
        return [
            'service' => 'WorkflowService',
            'status' => 'operational',
            'smtp_manager' => $this->smtpManager ? 'initialized' : 'not_available'
        ];
    }
}
