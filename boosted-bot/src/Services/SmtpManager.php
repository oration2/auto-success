<?php

namespace App\Services;

/**
 * SMTP Manager Service
 * 
 * Handles SMTP operations, rotation, and tracking
 */
class SmtpManager
{
    // SMTP Configuration
    private $smtpConfigs = [];
    private $currentSmtpIndex = 0;
    private $currentSmtp = null;
    private $verifiedSmtps = [];
    
    // Rotation statistics
    private $smtpRotationStats = [
        'total_sent' => 0,
        'failure_count' => 0,
        'rotation_count' => 0,
        'current_error_count' => 0,
        'error_tracking' => [
            'limit_errors' => [],
            'limit_reset_time' => []
        ]
    ];
    
    // Health tracking
    private $smtpHealthMetrics = [];
    private $rotationStrategy = 'weighted_random'; // 'round_robin' | 'random' | 'weighted_random'
    private $smtpCooldowns = []; // smtpKey => unix timestamp until usable
    private $minCooldownSeconds = 300; // 5 minutes
    private $maxCooldownSeconds = 1800; // 30 minutes
    private $smtpSuspicion = []; // smtpKey => suspicion score

    // Logger reference
    private $logger;
    
    /**
     * Constructor
     * 
     * @param array $smtps Array of SMTP configurations
     * @param object $logger Logger object with log() method
     */
    public function __construct($smtps = [], $logger = null)
    {
        $this->smtpConfigs = $smtps;
        $this->logger = $logger;
        
        // Initialize health tracking
        $this->smtpHealthMetrics = [
            'consecutive_failures' => [],
            'delivery_rates' => [],
            'response_times' => [],
            'bounce_tracking' => [],
            'last_health_check' => []
        ];
    }
    
    /**
     * Load SMTPs from file
     * 
     * @param string $filePath Path to SMTP configuration file
     * @return int Number of SMTPs loaded
     */
    public function loadSmtpsFromFile($filePath)
    {
        if (!file_exists($filePath)) {
            return 0;
        }
        
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $count = 0;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] == '#') {
                continue; // Skip comments and empty lines
            }
            
            $parts = explode(',', $line);
            if (count($parts) < 3) {
                continue; // Invalid format
            }
            
            // Parse host:port
            $hostPort = explode(':', $parts[0]);
            $host = $hostPort[0];
            $port = isset($hostPort[1]) ? (int)$hostPort[1] : 587;
            
            $smtp = [
                'host' => $host,
                'port' => $port,
                'username' => $parts[1],
                'password' => $parts[2],
                'from_email' => isset($parts[3]) ? $parts[3] : $parts[1],
                'from_name' => isset($parts[4]) ? $parts[4] : null,
                'encryption' => isset($parts[5]) ? $parts[5] : 'tls',
                'daily_limit' => isset($parts[6]) ? (int)$parts[6] : 1000,
                'hourly_limit' => isset($parts[7]) ? (int)$parts[7] : 100
            ];
            
            $this->smtpConfigs[] = $smtp;
            $count++;
        }
        
        // Select initial SMTP randomly for load distribution
        if ($count > 0) {
            $this->selectRandomSmtp();
        }
        
        return $count;
    }
    
    /**
     * Add a single SMTP configuration
     * 
     * @param array $smtp SMTP configuration
     * @return bool Success status
     */
    public function addSmtp($smtp)
    {
        // Validate required fields
        $required = ['host', 'username', 'password'];
        foreach ($required as $field) {
            if (!isset($smtp[$field])) {
                return false;
            }
        }
        
        // Set defaults for optional fields
        $smtp['port'] = $smtp['port'] ?? 587;
        $smtp['from_email'] = $smtp['from_email'] ?? $smtp['username'];
        $smtp['from_name'] = $smtp['from_name'] ?? null;
        $smtp['encryption'] = $smtp['encryption'] ?? 'tls';
        $smtp['daily_limit'] = $smtp['daily_limit'] ?? 1000;
        $smtp['hourly_limit'] = $smtp['hourly_limit'] ?? 100;
        
        $this->smtpConfigs[] = $smtp;
        return true;
    }
    
    /**
     * Randomly select a starting SMTP to distribute load
     */
    public function selectRandomSmtp()
    {
        $count = count($this->smtpConfigs);
        if ($count > 1) {
            $this->currentSmtpIndex = mt_rand(0, $count - 1);
            $this->currentSmtp = $this->smtpConfigs[$this->currentSmtpIndex] ?? null;
            return true;
        }
        return false;
    }
    
    /**
     * Get current SMTP configuration
     * 
     * @return array|null Current SMTP configuration
     */
    public function getCurrentSmtp()
    {
        if ($this->currentSmtpIndex < count($this->smtpConfigs)) {
            $this->currentSmtp = $this->smtpConfigs[$this->currentSmtpIndex];
            return $this->currentSmtp;
        }
        return null;
    }
    
    /**
     * Force rotation to next SMTP
     * 
     * @return array|null New SMTP configuration
     */
    public function rotateSmtp()
    {
        $this->smtpRotationStats['rotation_count']++;
        $this->smtpRotationStats['current_error_count'] = 0;
        
        $this->currentSmtpIndex = $this->pickNextSmtpIndex();
        $this->currentSmtp = $this->getCurrentSmtp();
        
        if ($this->logger) {
            $this->logger->log("SMTP rotated to " . ($this->currentSmtp['host'] ?? 'unknown'));
        }
        
        return $this->currentSmtp;
    }
    
    /**
     * Pick next SMTP index based on rotation strategy
     * 
     * @return int SMTP index
     */
    private function pickNextSmtpIndex()
    {
        $count = count($this->smtpConfigs);
        if ($count === 0) return 0;
        if ($count === 1) return 0;

        // Implementation of weighted random selection
        // Based on success rates and cooldown status
        
        $now = time();
        $candidates = [];
        
        for ($i = 0; $i < $count; $i++) {
            if ($i === $this->currentSmtpIndex && $count > 1) {
                // Skip current SMTP to ensure rotation unless it's the only one
                continue;
            }
            
            if (!$this->isOnCooldown($i)) {
                $conf = $this->smtpConfigs[$i];
                $key = $this->getSmtpKey($conf);
                // Use success rate as weight
                $weight = $this->calculateSmtpWeight($key);
                $candidates[] = ['index' => $i, 'weight' => $weight];
            }
        }
        
        // If no candidates (all in cooldown), pick round robin
        if (empty($candidates)) {
            return ($this->currentSmtpIndex + 1) % $count;
        }
        
        if ($this->rotationStrategy === 'round_robin') {
            return ($this->currentSmtpIndex + 1) % $count;
        }
        
        if ($this->rotationStrategy === 'random') {
            $idx = array_rand($candidates);
            return $candidates[$idx]['index'];
        }
        
        // Weighted random selection
        $totalWeight = 0;
        foreach ($candidates as $candidate) {
            $totalWeight += $candidate['weight'];
        }
        
        if ($totalWeight <= 0) {
            // Fallback to round robin if no positive weights
            return ($this->currentSmtpIndex + 1) % $count;
        }
        
        $r = mt_rand() / mt_getrandmax() * $totalWeight;
        $cumulativeWeight = 0;
        
        foreach ($candidates as $candidate) {
            $cumulativeWeight += $candidate['weight'];
            if ($r <= $cumulativeWeight) {
                return $candidate['index'];
            }
        }
        
        // Fallback
        return ($this->currentSmtpIndex + 1) % $count;
    }
    
    /**
     * Calculate weight for SMTP based on performance
     * 
     * @param string $smtpKey SMTP key
     * @return float Weight value
     */
    private function calculateSmtpWeight($smtpKey)
    {
        // Default weight
        $weight = 1.0;
        
        // Add weight based on success rate
        if (isset($this->smtpHealthMetrics['delivery_rates'][$smtpKey])) {
            $rate = $this->smtpHealthMetrics['delivery_rates'][$smtpKey];
            $weight += $rate;
        }
        
        // Reduce weight based on suspicion score
        if (isset($this->smtpSuspicion[$smtpKey])) {
            $suspicion = $this->smtpSuspicion[$smtpKey];
            $weight -= $suspicion * 0.1;
        }
        
        // Ensure minimum weight
        return max(0.1, $weight);
    }
    
    /**
     * Check if SMTP is on cooldown
     * 
     * @param int $index SMTP index
     * @return bool Cooldown status
     */
    private function isOnCooldown($index)
    {
        $key = $this->getSmtpKey($this->smtpConfigs[$index] ?? null);
        if ($key === '') return false;
        
        $until = $this->smtpCooldowns[$key] ?? 0;
        return $until > time();
    }
    
    /**
     * Apply cooldown to current SMTP
     * 
     * @param string $reason Cooldown reason
     */
    public function applyCooldownToCurrentSmtp($reason = '')
    {
        $key = $this->getSmtpKey($this->currentSmtp);
        if ($key === '') return;
        
        $current = $this->smtpCooldowns[$key] ?? 0;
        $base = $this->minCooldownSeconds;
        $next = time() + ($current > time() ? min($this->maxCooldownSeconds, ($current - time()) * 2) : $base);
        $this->smtpCooldowns[$key] = $next;
        
        if ($this->logger) {
            $this->logger->log("Applied cooldown to SMTP {$key} until " . date('H:i:s', $next) . " ({$reason})");
        }
    }
    
    /**
     * Generate a unique key for SMTP
     * 
     * @param array|null $config SMTP config
     * @return string SMTP key
     */
    private function getSmtpKey($config = null)
    {
        $conf = $config ?? $this->currentSmtp ?? [];
        if (empty($conf)) return '';
        
        return ($conf['host'] ?? 'host') . ':' . ($conf['username'] ?? 'user');
    }
    
    /**
     * Track SMTP performance
     * 
     * @param bool $success Operation success status
     * @param float $duration Operation duration
     */
    public function trackSmtpPerformance($success, $duration = 0.0)
    {
        $key = $this->getSmtpKey();
        if ($key === '') return;
        
        // Update delivery rates
        if (!isset($this->smtpHealthMetrics['delivery_rates'][$key])) {
            $this->smtpHealthMetrics['delivery_rates'][$key] = [
                'total' => 0,
                'success' => 0
            ];
        }
        
        $this->smtpHealthMetrics['delivery_rates'][$key]['total']++;
        if ($success) {
            $this->smtpHealthMetrics['delivery_rates'][$key]['success']++;
            $this->smtpRotationStats['total_sent']++;
            // Reduce suspicion on success
            if (isset($this->smtpSuspicion[$key])) {
                $this->smtpSuspicion[$key] = max(0, $this->smtpSuspicion[$key] - 1);
            }
        } else {
            $this->smtpRotationStats['failure_count']++;
            $this->smtpRotationStats['current_error_count']++;
            // Increase suspicion on failure
            if (!isset($this->smtpSuspicion[$key])) {
                $this->smtpSuspicion[$key] = 0;
            }
            $this->smtpSuspicion[$key]++;
            
            // Apply cooldown if multiple consecutive errors
            if (($this->smtpRotationStats['current_error_count'] ?? 0) >= 3) {
                $this->applyCooldownToCurrentSmtp('consecutive_failures');
                $this->rotateSmtp();
            }
        }
        
        // Track response times
        if ($duration > 0) {
            if (!isset($this->smtpHealthMetrics['response_times'][$key])) {
                $this->smtpHealthMetrics['response_times'][$key] = [
                    'count' => 0,
                    'total' => 0,
                    'average' => 0
                ];
            }
            
            $this->smtpHealthMetrics['response_times'][$key]['count']++;
            $this->smtpHealthMetrics['response_times'][$key]['total'] += $duration;
            $this->smtpHealthMetrics['response_times'][$key]['average'] = 
                $this->smtpHealthMetrics['response_times'][$key]['total'] / 
                $this->smtpHealthMetrics['response_times'][$key]['count'];
        }
    }
    
    /**
     * Get all SMTP configurations
     * 
     * @return array SMTP configs
     */
    public function getAllSmtps()
    {
        return $this->smtpConfigs;
    }
    
    /**
     * Get rotation statistics
     * 
     * @return array Rotation stats
     */
    public function getRotationStats()
    {
        return $this->smtpRotationStats;
    }
    
    /**
     * Set rotation strategy
     * 
     * @param string $strategy Strategy name
     * @return bool Success status
     */
    public function setRotationStrategy($strategy)
    {
        $allowed = ['round_robin', 'random', 'weighted_random'];
        if (in_array($strategy, $allowed)) {
            $this->rotationStrategy = $strategy;
            return true;
        }
        return false;
    }
    
    /**
     * Set cooldown periods
     * 
     * @param int $min Minimum cooldown seconds
     * @param int $max Maximum cooldown seconds
     */
    public function setCooldownPeriods($min, $max)
    {
        $this->minCooldownSeconds = max(1, (int)$min);
        $this->maxCooldownSeconds = max($this->minCooldownSeconds, (int)$max);
    }
    
    /**
     * Flag current SMTP as suspicious
     * 
     * @param int $score Suspicion score to add
     */
    public function flagCurrentSmtpSuspicious($score = 1)
    {
        $key = $this->getSmtpKey();
        if ($key === '') return;
        
        if (!isset($this->smtpSuspicion[$key])) {
            $this->smtpSuspicion[$key] = 0;
        }
        
        $this->smtpSuspicion[$key] += $score;
        
        // Auto-cooldown if suspicion is high
        if ($this->smtpSuspicion[$key] >= 5) {
            $this->applyCooldownToCurrentSmtp('high_suspicion');
        }
    }
    
    /**
     * Remove current SMTP permanently
     * 
     * @param string $reason Removal reason
     * @return bool Success status
     */
    public function removeCurrentSmtp($reason = '')
    {
        if (empty($this->smtpConfigs)) {
            return false;
        }
        
        $key = $this->getSmtpKey();
        $removed = array_splice($this->smtpConfigs, $this->currentSmtpIndex, 1);
        
        // Adjust current index
        if (empty($this->smtpConfigs)) {
            $this->currentSmtpIndex = 0;
            $this->currentSmtp = null;
        } else {
            $this->currentSmtpIndex = $this->currentSmtpIndex % count($this->smtpConfigs);
            $this->currentSmtp = $this->getCurrentSmtp();
        }
        
        // Log removal
        if ($this->logger && !empty($removed)) {
            $host = $removed[0]['host'] ?? 'unknown';
            $user = $removed[0]['username'] ?? 'unknown';
            $this->logger->log("Removed SMTP {$host} ({$user}) permanently: {$reason}");
        }
        
        return !empty($removed);
    }
}
