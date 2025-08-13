<?php

namespace App\Traits;

trait CampaignManager {
    private $activeCampaigns = [];
    
    /**
     * Check if a campaign is active for a chat
     */
    protected function hasCampaign($chatId) {
        return isset($this->activeCampaigns[$chatId]);
    }
    
    /**
     * Start tracking a new campaign
     */
    protected function trackCampaign($chatId, $campaign) {
        $this->activeCampaigns[$chatId] = [
            'started' => time(),
            'data' => $campaign,
            'processed' => 0,
            'success' => 0,
            'failed' => 0
        ];
    }
    
    /**
     * Stop tracking a campaign
     */
    protected function stopCampaign($chatId) {
        if (isset($this->activeCampaigns[$chatId])) {
            unset($this->activeCampaigns[$chatId]);
            return true;
        }
        return false;
    }
    
    /**
     * Update campaign progress
     */
    protected function updateCampaignProgress($chatId, $success) {
        if (isset($this->activeCampaigns[$chatId])) {
            $this->activeCampaigns[$chatId]['processed']++;
            if ($success) {
                $this->activeCampaigns[$chatId]['success']++;
            } else {
                $this->activeCampaigns[$chatId]['failed']++;
            }
        }
    }
    
    /**
     * Get campaign status message
     */
    protected function getCampaignStatus($chatId) {
        if (!isset($this->activeCampaigns[$chatId])) {
            return "No active campaign";
        }
        
        $campaign = $this->activeCampaigns[$chatId];
        $total = count($campaign['data']['recipients']);
        $processed = $campaign['processed'];
        $success = $campaign['success'];
        $failed = $campaign['failed'];
        $percent = $total > 0 ? round(($processed / $total) * 100) : 0;
        
        return "📊 Campaign Progress:\n" .
               "✉️ Processed: {$processed}/{$total}\n" .
               "✅ Success: {$success}\n" .
               "❌ Failed: {$failed}\n" .
               "📈 Progress: {$percent}%";
    }
}
