<?php

namespace App\Utils;

use App\TelegramBot;
use App\EmailSender;

/**
 * EmailQueueProcessor Class
 * 
 * Handles email queue processing with improved error handling and SMTP rotation
 */
class EmailQueueProcessor {
    // TelegramBot instance
    private $bot;
    
    // Queue data
    private $chatId;
    private $emails = [];
    private $template = '';
    private $subject = '';
    
    // Processing stats
    private $stats = [
        'total' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'start_time' => 0,
        'end_time' => 0,
        'smtp_rotations' => 0
    ];
    
    // SMTP health tracking
    private $smtpHealth = [];
    private $healthCheckInterval = 50; // Check SMTP health every 50 emails
    private $healthThresholds = [
        'error_rate' => 0.2,      // 20% error rate triggers warning
        'latency' => 5.0,         // 5 seconds average latency triggers warning
        'consecutive_errors' => 3  // 3 consecutive errors triggers rotation
    ];
            
    /**
     * Get a simplified, user-friendly error message
     */
    private function getSimplifiedErrorMessage($error) {
        if (stripos($error, "ssl") !== false || stripos($error, "certificate") !== false) {
            return "SSL/TLS connection error. Rotating SMTP server...";
        } else if (stripos($error, "quota") !== false || stripos($error, "limit") !== false) {
            return "Sending limit reached. Rotating SMTP server...";
        } else if (stripos($error, "spam") !== false || stripos($error, "block") !== false) {
            return "Message blocked by spam filter. Adjusting settings...";
        } else {
            return "Temporary error. Retrying with different settings...";
        }
    }
    
    /**
     * Track SMTP server health
     * 
     * @param string $smtpKey SMTP server identifier
     * @param bool $success Whether the operation was successful
     * @param float $latency Operation latency in seconds
     */
    private function trackSmtpHealth($smtpKey, $success, $latency) {
        if (!isset($this->smtpHealth[$smtpKey])) {
            $this->smtpHealth[$smtpKey] = [
                'total_attempts' => 0,
                'successes' => 0,
                'failures' => 0,
                'total_latency' => 0,
                'consecutive_errors' => 0,
                'last_check_time' => 0,
                'warnings' => []
            ];
        }
        
        $health = &$this->smtpHealth[$smtpKey];
        $health['total_attempts']++;
        $health['total_latency'] += $latency;
        
        if ($success) {
            $health['successes']++;
            $health['consecutive_errors'] = 0;
        } else {
            $health['failures']++;
            $health['consecutive_errors']++;
        }
        
        // Check health status periodically
        if ($health['total_attempts'] % $this->healthCheckInterval === 0) {
            $this->checkSmtpHealth($smtpKey);
        }
    }
    
    /**
     * Check SMTP server health status and take action if needed
     * 
     * @param string $smtpKey SMTP server identifier
     */
    private function checkSmtpHealth($smtpKey) {
        $health = $this->smtpHealth[$smtpKey];
        $warnings = [];
        $needsRotation = false;
        
        // Calculate metrics
        $errorRate = $health['failures'] / $health['total_attempts'];
        $avgLatency = $health['total_latency'] / $health['total_attempts'];
        
        // Check thresholds
        if ($errorRate >= $this->healthThresholds['error_rate']) {
            $warnings[] = sprintf("High error rate: %.1f%%", $errorRate * 100);
            $needsRotation = true;
        }
        
        if ($avgLatency >= $this->healthThresholds['latency']) {
            $warnings[] = sprintf("High latency: %.1fs", $avgLatency);
        }
        
        if ($health['consecutive_errors'] >= $this->healthThresholds['consecutive_errors']) {
            $warnings[] = sprintf("Consecutive errors: %d", $health['consecutive_errors']);
            $needsRotation = true;
        }
        
        // Update health status
        $this->smtpHealth[$smtpKey]['warnings'] = $warnings;
        $this->smtpHealth[$smtpKey]['last_check_time'] = time();
        
        // Notify if there are warnings
        if (!empty($warnings)) {
            $message = "⚠️ *SMTP Health Warning*\n"
                    . "━━━━━━━━━━━━━━\n"
                    . "• Server: `" . substr($smtpKey, 0, 20) . "`\n"
                    . "• Issues:\n";
            
            foreach ($warnings as $warning) {
                $message .= "  - " . $warning . "\n";
            }
            
            if ($needsRotation) {
                $message .= "\n_Initiating SMTP rotation..._";
            }
            
            $this->bot->sendTelegramMessage($this->chatId, $message, [
                'parse_mode' => 'Markdown',
                'disable_notification' => true
            ]);
        }
        
        return $needsRotation;
    }    /**
     * Constructor
     * 
     * @param TelegramBot $bot TelegramBot instance
     * @param string $chatId Chat ID
     */
    public function __construct($bot, $chatId) {
        $this->bot = $bot;
        $this->chatId = $chatId;
        $this->stats['start_time'] = time();
    }
    
    /**
     * Set emails to process
     * 
     * @param array $emails Array of email addresses
     * @return EmailQueueProcessor This instance for method chaining
     */
    public function setEmails($emails) {
        $this->emails = $emails;
        $this->stats['total'] = count($emails);
        return $this;
    }
    
    /**
     * Set email template
     * 
     * @param string $template HTML template
     * @return EmailQueueProcessor This instance for method chaining
     */
    public function setTemplate($template) {
        $this->template = $template;
        return $this;
    }
    
    /**
     * Set email subject
     * 
     * @param string $subject Email subject
     * @return EmailQueueProcessor This instance for method chaining
     */
    public function setSubject($subject) {
        $this->subject = $subject;
        return $this;
    }
    
    /**
     * Initialize sending state with detailed tracking
     */
    private function initializeState() {
        $userData = $this->bot->getUserData($this->chatId);
        $dailySent = isset($userData['emails_sent_today']) ? $userData['emails_sent_today'] : 0;
        $monthSent = isset($userData['emails_sent_month']) ? $userData['emails_sent_month'] : 0;
        
        $state = [
            'is_sending' => true,
            'start_time' => time(),
            'total_emails' => count($this->emails),
            'current_index' => 0,
            'sent_count' => 0,
            'error_count' => 0,
            'last_error' => null,
            'smtp_rotations' => 0,
            'paused' => false,
            'daily_sent' => $dailySent,
            'month_sent' => $monthSent,
            'batch_stats' => [
                'success_rate' => 100,
                'last_batch_size' => 0,
                'last_batch_success' => 0,
                'last_batch_time' => 0
            ]
        ];
        
        $this->bot->updateUserData($this->chatId, ['sending_state' => $state]);
    }
    
    /**
     * Process the email queue
     * 
     * @param int $batchSize Number of emails to process in each batch
     * @param int $delayBetweenBatches Delay between batches in seconds
     * @return array Processing statistics
     */
    public function process($batchSize = 10, $delayBetweenBatches = 5) {
        // Validate inputs
        if (empty($this->emails)) {
            $this->bot->sendTelegramMessage($this->chatId, "⚠️ No emails to process.");
            return $this->stats;
        }
        
        if (empty($this->template)) {
            $this->bot->sendTelegramMessage($this->chatId, "⚠️ No template provided.");
            return $this->stats;
        }
        
        // Initialize sending state
        $this->initializeState();
        
        // Check for active campaign and update state
        if ($this->bot->hasCampaign($this->chatId)) {
            $userData = $this->bot->getUserData($this->chatId);
            if (isset($userData['sending_state']) && $userData['sending_state']['is_sending']) {
                $this->bot->sendTelegramMessage($this->chatId, "⚠️ You already have an active campaign. Please wait for it to finish or use /stop to cancel it.");
                return $this->stats;
            }
        }
        
        // Use default subject if not provided
        if (empty($this->subject)) {
            $this->subject = DEFAULT_SUBJECT;
        }
        
        // Start tracking campaign
        $this->bot->trackCampaign($this->chatId, [
            'recipients' => $this->emails,
            'subject' => $this->subject,
            'template' => $this->template,
            'batch_size' => $batchSize
        ]);
        
        // Process emails in batches
        $batches = array_chunk($this->emails, $batchSize);
        $batchCount = count($batches);
        
        foreach ($batches as $batchIndex => $batch) {
            // Process batch
            $batchStats = $this->processBatch($batch, $batchIndex + 1, $batchCount);
            
            // Update stats
            $this->stats['sent'] += $batchStats['sent'];
            $this->stats['failed'] += $batchStats['failed'];
            $this->stats['skipped'] += $batchStats['skipped'];
            $this->stats['smtp_rotations'] += $batchStats['smtp_rotations'];
            
            // Send batch status
            $progress = round(($batchIndex + 1) / $batchCount * 100);
            $this->bot->sendTelegramMessage($this->chatId, 
                "Batch {$batchIndex}/{$batchCount} completed ({$progress}%)\n" .
                "✅ Sent: {$batchStats['sent']}\n" .
                "❌ Failed: {$batchStats['failed']}\n" .
                "⏭️ Skipped: {$batchStats['skipped']}"
            );
            
            // Delay between batches
            if ($batchIndex < $batchCount - 1) {
                sleep($delayBetweenBatches);
            }
        }
        
        // Send final status
        $this->stats['end_time'] = time();
        $duration = $this->stats['end_time'] - $this->stats['start_time'];
        $minutes = floor($duration / 60);
        $seconds = $duration % 60;
        
        $this->bot->sendTelegramMessage($this->chatId, 
            "✅ Email processing completed in {$minutes}m {$seconds}s\n\n" .
            "📊 Statistics:\n" .
            "- Total: {$this->stats['total']}\n" .
            "- Sent: {$this->stats['sent']}\n" .
            "- Failed: {$this->stats['failed']}\n" .
            "- Skipped: {$this->stats['skipped']}\n" .
            "- SMTP Rotations: {$this->stats['smtp_rotations']}"
        );
        
        return $this->stats;
    }
    
    /**
     * Process a batch of emails
     * 
     * @param array $batch Batch of emails to process
     * @param int $batchNumber Current batch number
     * @param int $totalBatches Total number of batches
     * @return array Batch statistics
     */
    private function processBatch($batch, $batchNumber, $totalBatches) {
        $batchStats = [
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'smtp_rotations' => 0
        ];
        
        // Get email sender from the bot
        $emailSender = $this->bot->getEmailSender();
        
        foreach ($batch as $index => $email) {
            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $batchStats['skipped']++;
                continue;
            }
            
            // Send email with progress indicator
            $this->bot->sendTelegramMessage($this->chatId, "\n▄︻デ//══━一 Sending to: " . $email);
            
            try {
                // Check if campaign is still active
                $userData = $this->bot->getUserData($this->chatId);
                if (!isset($userData['sending_state']) || !$userData['sending_state']['is_sending']) {
                    $this->bot->sendTelegramMessage($this->chatId, "⚠️ Campaign stopped by user.");
                    return $batchStats;
                }

                $result = $emailSender->send($email, $this->subject, $this->template);
                
                if ($result) {
                    $batchStats['sent']++;
                    $this->bot->updateCampaignProgress($this->chatId, true);
                    
                    // Update user data with sent counts
                    $userData = $this->bot->getUserData($this->chatId);
                    $userData['emails_sent_today'] = ($userData['emails_sent_today'] ?? 0) + 1;
                    $userData['emails_sent_month'] = ($userData['emails_sent_month'] ?? 0) + 1;
                    $userData['sending_state']['daily_sent'] = $userData['emails_sent_today'];
                    $userData['sending_state']['month_sent'] = $userData['emails_sent_month'];
                    $this->bot->updateUserData($this->chatId, $userData);
                    
                    $this->bot->sendTelegramMessage($this->chatId, sprintf(
                        "✅ [%d/%d] %s",
                        ($batchNumber - 1) * count($batch) + $index + 1,
                        $this->stats['total'],
                        $email
                    ));
                } else {
                    $batchStats['failed']++;
                    $this->bot->updateCampaignProgress($this->chatId, false);
                    $this->bot->sendTelegramMessage($this->chatId, sprintf(
                        "❌ [%d/%d] %s",
                        ($batchNumber - 1) * count($batch) + $index + 1,
                        $this->stats['total'],
                        $email
                    ));
                }
                
                // Update campaign status periodically (every 5 emails)
                if (($batchStats['sent'] + $batchStats['failed']) % 5 == 0) {
                    $progress = round((($batchNumber - 1) * count($batch) + $index + 1) / ($totalBatches * count($batch)) * 100, 1);
                    $userData = $this->bot->getUserData($this->chatId);
                    $dailyLimit = $this->bot->getPlanDailyLimit($userData['plan'] ?? 'trial');
                    $dailySent = $userData['sending_state']['daily_sent'] ?? 0;
                    $monthSent = $userData['sending_state']['month_sent'] ?? 0;
                    
                    $status = sprintf(
                        "📊 *Campaign Progress*\n\n" .
                        "*Current Campaign:*\n" .
                        "• Progress: %s%%\n" .
                        "• Sent: %d of %d\n" .
                        "• Failed: %d\n" .
                        "• Batch: %d/%d\n\n" .
                        "*Daily Stats:*\n" .
                        "• Sent Today: %d\n" .
                        "• Remaining: %d\n" .
                        "• Success Rate: %s%%",
                        $progress,
                        $batchStats['sent'],
                        $this->stats['total'],
                        $batchStats['failed'],
                        $batchNumber,
                        $totalBatches,
                        $dailySent,
                        max(0, $dailyLimit - $dailySent),
                        round(($batchStats['sent'] / max(1, $batchStats['sent'] + $batchStats['failed'])) * 100, 1)
                    );
                    $this->bot->sendTelegramMessage($this->chatId, $status, ['parse_mode' => 'Markdown']);
                }
            } catch (\Exception $e) {
                $batchStats['failed']++;
                $this->bot->updateCampaignProgress($this->chatId, false);
                
                // Log the error with detailed information
                $errorMessage = sprintf(
                    "Error sending to %s: %s\nStack trace: %s",
                    $email,
                    $e->getMessage(),
                    $e->getTraceAsString()
                );
                $this->bot->log($errorMessage);
                
                // Send user-friendly error message
                $userError = $this->getSimplifiedErrorMessage($e->getMessage());
                $this->bot->sendTelegramMessage($this->chatId, "❌ Error: " . $userError . "\n");
                
                // Update sending state with error info
                $this->bot->updateUserData($this->chatId, [
                    'sending_state' => [
                        'last_error' => $userError,
                        'error_count' => ($userData['sending_state']['error_count'] ?? 0) + 1
                    ]
                ]);
                
                // Track SMTP health and check rotation
                $smtpConfig = $emailSender->getCurrentSmtp();
                $smtpKey = $smtpConfig['host'] . ':' . $smtpConfig['username'];
                $latency = microtime(true) - $startTime;
                
                $this->trackSmtpHealth($smtpKey, false, $latency);
                
                // Check if we need to rotate based on health metrics or error count
                $needsRotation = $this->checkSmtpHealth($smtpKey) ||
                               $emailSender->getErrorCount() >= ($this->bot->getSmtpRotationThreshold() ?? 3);
                
                if ($needsRotation) {
                    $this->bot->sendTelegramMessage($this->chatId, "🔄 Rotating SMTP server due to health issues...");
                    $this->bot->rotateSmtp();
                    $emailSender = $this->bot->getEmailSender();
                    $emailSender->resetErrorCount();
                    $batchStats['smtp_rotations']++;
                    
                    // Small delay after SMTP rotation
                    sleep(2);
                }
            }
            
            // Small delay between emails
            usleep(200000); // 200ms
        }
        
        return $batchStats;
    }
}
