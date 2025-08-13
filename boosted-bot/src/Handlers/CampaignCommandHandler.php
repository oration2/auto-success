<?php

namespace App\Handlers;

use App\Core\TelegramBotCore;

/**
 * Campaign Command Handler
 * 
 * Handles the /campaign command
 */
class CampaignCommandHandler extends CommandHandler
{
    /**
     * @inheritdoc
     */
    protected $commands = ['campaign'];
    
    /**
     * @inheritdoc
     */
    protected $description = 'Manage email campaigns';
    
    /**
     * @inheritdoc
     */
    protected $usage = '/campaign [action] [id]';
    
    /**
     * @inheritdoc
     */
    public function handle($chatId, $command, $args = [], $meta = [])
    {
        // Get action (default to 'menu')
        $action = isset($args[0]) ? strtolower($args[0]) : 'menu';
        $campaignId = isset($args[1]) ? $args[1] : null;
        
        // Handle different actions
        switch ($action) {
            case 'new':
                return $this->newCampaign($chatId);
                
            case 'list':
                return $this->listCampaigns($chatId);
                
            case 'status':
                return $this->campaignStatus($chatId, $campaignId);
                
            case 'delete':
                return $this->deleteCampaign($chatId, $campaignId);
                
            default:
                return $this->showCampaignMenu($chatId);
        }
    }
    
    /**
     * Show campaign menu
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    private function showCampaignMenu($chatId)
    {
        // Get user data
        $userData = $this->bot->getUserData($chatId);
        
        $message = "📧 *Campaign Management*\n\n";
        
        // Check if user has active campaigns
        $activeCampaigns = $userData['active_campaigns'] ?? [];
        $campaignCount = count($activeCampaigns);
        
        if ($campaignCount > 0) {
            $message .= "You have {$campaignCount} active campaign(s).\n\n";
        } else {
            $message .= "You don't have any active campaigns.\n\n";
        }
        
        $message .= "What would you like to do?";
        
        $keyboard = [
            [
                ['text' => '🆕 New Campaign', 'callback_data' => 'campaign_new']
            ],
            [
                ['text' => '📋 My Campaigns', 'callback_data' => 'campaign_list']
            ],
            [
                ['text' => '📊 Campaign Stats', 'callback_data' => 'campaign_stats']
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
     * Create a new campaign
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    private function newCampaign($chatId)
    {
        // Get user data
        $userData = $this->bot->getUserData($chatId);
        $plan = $this->bot->getUserService()->getUserPlan($chatId);
        
        // Check plan limits
        $activeCampaigns = $userData['active_campaigns'] ?? [];
        $maxCampaigns = $plan['max_campaigns'] ?? 1;
        
        if (count($activeCampaigns) >= $maxCampaigns) {
            $message = "⛔ *Campaign Limit Reached*\n\n";
            $message .= "Your current plan allows a maximum of {$maxCampaigns} active campaigns.\n\n";
            $message .= "Please upgrade your plan or complete/delete an existing campaign before creating a new one.";
            
            $keyboard = [
                [
                    ['text' => '💰 Upgrade Plan', 'callback_data' => 'upgrade_plan']
                ],
                [
                    ['text' => '📋 My Campaigns', 'callback_data' => 'campaign_list']
                ],
                [
                    ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
                ]
            ];
            
            $this->bot->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            
            return false;
        }
        
        // Show setup menu directly
        $this->bot->showSetupMenu($chatId);
        
        return true;
    }
    
    /**
     * List user campaigns
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    private function listCampaigns($chatId)
    {
        // Get user data
        $userData = $this->bot->getUserData($chatId);
        $activeCampaigns = $userData['active_campaigns'] ?? [];
        $completedCampaigns = $userData['completed_campaigns'] ?? [];
        
        $message = "📋 *Your Campaigns*\n\n";
        
        // Active campaigns
        $message .= "*Active Campaigns:*\n";
        if (count($activeCampaigns) > 0) {
            foreach ($activeCampaigns as $id => $campaign) {
                $status = $campaign['status'];
                $progress = $campaign['progress'] ?? 0;
                $total = $campaign['total'] ?? 0;
                
                // Status icon
                $icon = '▶️'; // Default (running)
                if ($status === 'paused') {
                    $icon = '⏸️';
                } elseif ($status === 'queued') {
                    $icon = '⏳';
                } elseif ($status === 'completed') {
                    $icon = '✅';
                } elseif ($status === 'error') {
                    $icon = '❌';
                }
                
                // Format subject for display
                $subject = $campaign['subject'] ?? 'No Subject';
                if (strlen($subject) > 25) {
                    $subject = substr($subject, 0, 22) . '...';
                }
                
                // Show progress
                $progressPercent = ($total > 0) ? round(($progress / $total) * 100, 1) : 0;
                $message .= "{$icon} `{$id}`: {$subject} - {$progressPercent}% ({$progress}/{$total})\n";
            }
        } else {
            $message .= "No active campaigns\n";
        }
        
        // Recent completed campaigns (up to 5)
        $message .= "\n*Completed Campaigns:*\n";
        if (count($completedCampaigns) > 0) {
            $recentCampaigns = array_slice($completedCampaigns, -5, 5, true);
            foreach ($recentCampaigns as $id => $campaign) {
                $subject = $campaign['subject'] ?? 'No Subject';
                if (strlen($subject) > 25) {
                    $subject = substr($subject, 0, 22) . '...';
                }
                
                $sent = $campaign['sent'] ?? 0;
                $total = $campaign['total'] ?? 0;
                $successRate = ($total > 0) ? round(($sent / $total) * 100, 1) : 0;
                
                $message .= "✅ `{$id}`: {$subject} - {$successRate}% ({$sent}/{$total})\n";
            }
        } else {
            $message .= "No completed campaigns\n";
        }
        
        // Build keyboard with campaign actions
        $keyboard = [];
        
        // Add campaign-specific actions if campaigns exist
        if (count($activeCampaigns) > 0) {
            $keyboard[] = [
                ['text' => '⏸️ Pause Campaign', 'callback_data' => 'campaign_pause']
            ];
            $keyboard[] = [
                ['text' => '▶️ Resume Campaign', 'callback_data' => 'campaign_resume']
            ];
            $keyboard[] = [
                ['text' => '❌ Delete Campaign', 'callback_data' => 'campaign_delete']
            ];
        }
        
        // Always add these options
        $keyboard[] = [
            ['text' => '🆕 New Campaign', 'callback_data' => 'campaign_new']
        ];
        $keyboard[] = [
            ['text' => '🔙 Campaign Menu', 'callback_data' => 'campaign_menu']
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Show campaign status
     * 
     * @param string $chatId Chat ID
     * @param string|null $campaignId Campaign ID
     * @return bool Success status
     */
    private function campaignStatus($chatId, $campaignId = null)
    {
        // Get user data
        $userData = $this->bot->getUserData($chatId);
        $activeCampaigns = $userData['active_campaigns'] ?? [];
        
        // If no campaign ID provided and only one active campaign, use that
        if (!$campaignId && count($activeCampaigns) === 1) {
            $campaignId = array_key_first($activeCampaigns);
        }
        
        // If no campaign ID provided and multiple campaigns, show list
        if (!$campaignId) {
            $message = "📊 *Campaign Status*\n\n";
            $message .= "Please select a campaign to view its status:";
            
            $keyboard = [];
            
            // Add button for each campaign
            foreach ($activeCampaigns as $id => $campaign) {
                $subject = $campaign['subject'] ?? 'No Subject';
                if (strlen($subject) > 30) {
                    $subject = substr($subject, 0, 27) . '...';
                }
                
                $keyboard[] = [
                    ['text' => "{$id}: {$subject}", 'callback_data' => "campaign_status_{$id}"]
                ];
            }
            
            // Add back button
            $keyboard[] = [
                ['text' => '🔙 Campaign Menu', 'callback_data' => 'campaign_menu']
            ];
            
            $this->bot->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            
            return true;
        }
        
        // Check if campaign exists
        if (!isset($activeCampaigns[$campaignId])) {
            $this->bot->sendTelegramMessage($chatId, "❓ Campaign `{$campaignId}` not found. Use /campaign list to see your campaigns.", [
                'parse_mode' => 'Markdown'
            ]);
            return false;
        }
        
        // Get campaign data
        $campaign = $activeCampaigns[$campaignId];
        
        // Build status message
        $message = "📊 *Campaign Status: #{$campaignId}*\n\n";
        
        // Basic info
        $message .= "*Subject:* " . ($campaign['subject'] ?? 'No Subject') . "\n";
        $message .= "*Status:* " . $this->getStatusText($campaign['status']) . "\n";
        
        // Progress
        $progress = $campaign['progress'] ?? 0;
        $total = $campaign['total'] ?? 0;
        $progressPercent = ($total > 0) ? round(($progress / $total) * 100, 1) : 0;
        
        $message .= "*Progress:* {$progressPercent}% ({$progress}/{$total})\n";
        
        // Success rate
        $sent = $campaign['sent'] ?? 0;
        $errors = $campaign['errors'] ?? 0;
        $attempted = $sent + $errors;
        $successRate = ($attempted > 0) ? round(($sent / $attempted) * 100, 1) : 0;
        
        $message .= "*Success Rate:* {$successRate}%\n";
        $message .= "*Sent:* {$sent}\n";
        $message .= "*Errors:* {$errors}\n\n";
        
        // Timing information
        $startTime = $campaign['start_time'] ?? 0;
        $lastSendTime = $campaign['last_send_time'] ?? 0;
        $estimatedCompletion = 0;
        
        if ($startTime > 0) {
            $startText = date('Y-m-d H:i:s', $startTime);
            $message .= "*Started:* {$startText}\n";
            
            // Calculate estimated completion if campaign is running and has progress
            if ($campaign['status'] === 'running' && $progress > 0 && $total > $progress) {
                $elapsedTime = time() - $startTime;
                $timePerEmail = $elapsedTime / $progress;
                $remainingEmails = $total - $progress;
                $estimatedTimeLeft = $timePerEmail * $remainingEmails;
                
                // Only show if reasonable estimate (at least sent 5% of total)
                if ($progress > ($total * 0.05)) {
                    $estimatedCompletion = time() + $estimatedTimeLeft;
                    $estimatedCompletionText = date('Y-m-d H:i:s', $estimatedCompletion);
                    $message .= "*Est. Completion:* {$estimatedCompletionText}\n";
                }
            }
        }
        
        // Last activity
        if ($lastSendTime > 0) {
            $lastActivityText = date('Y-m-d H:i:s', $lastSendTime);
            $message .= "*Last Activity:* {$lastActivityText}\n\n";
        }
        
        // Add campaign actions
        $keyboard = [];
        
        // Action buttons based on status
        if ($campaign['status'] === 'running') {
            $keyboard[] = [
                ['text' => '⏸️ Pause Campaign', 'callback_data' => "campaign_pause_{$campaignId}"]
            ];
        } elseif ($campaign['status'] === 'paused') {
            $keyboard[] = [
                ['text' => '▶️ Resume Campaign', 'callback_data' => "campaign_resume_{$campaignId}"]
            ];
        }
        
        $keyboard[] = [
            ['text' => '❌ Delete Campaign', 'callback_data' => "campaign_delete_{$campaignId}"]
        ];
        
        $keyboard[] = [
            ['text' => '🔄 Refresh Status', 'callback_data' => "campaign_status_{$campaignId}"]
        ];
        
        $keyboard[] = [
            ['text' => '🔙 Campaign List', 'callback_data' => 'campaign_list']
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Delete a campaign
     * 
     * @param string $chatId Chat ID
     * @param string|null $campaignId Campaign ID
     * @return bool Success status
     */
    private function deleteCampaign($chatId, $campaignId = null)
    {
        // Get user data
        $userData = $this->bot->getUserData($chatId);
        $activeCampaigns = $userData['active_campaigns'] ?? [];
        
        // If no campaign ID provided and only one active campaign, use that
        if (!$campaignId && count($activeCampaigns) === 1) {
            $campaignId = array_key_first($activeCampaigns);
        }
        
        // If no campaign ID provided and multiple campaigns, show list
        if (!$campaignId) {
            $message = "❌ *Delete Campaign*\n\n";
            $message .= "Please select a campaign to delete:";
            
            $keyboard = [];
            
            // Add button for each campaign
            foreach ($activeCampaigns as $id => $campaign) {
                $subject = $campaign['subject'] ?? 'No Subject';
                if (strlen($subject) > 30) {
                    $subject = substr($subject, 0, 27) . '...';
                }
                
                $keyboard[] = [
                    ['text' => "{$id}: {$subject}", 'callback_data' => "campaign_delete_confirm_{$id}"]
                ];
            }
            
            // Add back button
            $keyboard[] = [
                ['text' => '🔙 Cancel', 'callback_data' => 'campaign_list']
            ];
            
            $this->bot->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            
            return true;
        }
        
        // Check if campaign exists
        if (!isset($activeCampaigns[$campaignId])) {
            $this->bot->sendTelegramMessage($chatId, "❓ Campaign `{$campaignId}` not found. Use /campaign list to see your campaigns.", [
                'parse_mode' => 'Markdown'
            ]);
            return false;
        }
        
        // Confirm deletion (normally would be handled via callback)
        $message = "⚠️ *Confirm Deletion*\n\n";
        $message .= "Are you sure you want to delete campaign `{$campaignId}`?\n\n";
        
        // Campaign info
        $campaign = $activeCampaigns[$campaignId];
        $subject = $campaign['subject'] ?? 'No Subject';
        $status = $this->getStatusText($campaign['status']);
        $progress = $campaign['progress'] ?? 0;
        $total = $campaign['total'] ?? 0;
        
        $message .= "*Subject:* {$subject}\n";
        $message .= "*Status:* {$status}\n";
        $message .= "*Progress:* {$progress}/{$total}\n\n";
        
        $message .= "This action cannot be undone.";
        
        $keyboard = [
            [
                ['text' => '✅ Yes, Delete', 'callback_data' => "campaign_delete_confirmed_{$campaignId}"]
            ],
            [
                ['text' => '❌ No, Cancel', 'callback_data' => "campaign_status_{$campaignId}"]
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Get readable status text
     * 
     * @param string $status Status code
     * @return string Readable status
     */
    private function getStatusText($status)
    {
        $statusTexts = [
            'queued' => '⏳ Queued',
            'running' => '▶️ Running',
            'paused' => '⏸️ Paused',
            'completed' => '✅ Completed',
            'error' => '❌ Error',
            'deleted' => '🗑️ Deleted'
        ];
        
        return $statusTexts[$status] ?? $status;
    }
}
