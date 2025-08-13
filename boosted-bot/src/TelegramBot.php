<?php
/**
 * TelegramBot Class
 * 
 * Main class for handling Telegram bot operations with improved structure
 * and fixed redeclaration issues.
 */
require_once __DIR__ . '/traits/OrderManager.php';
require_once __DIR__ . '/traits/PlanManager.php';
require_once __DIR__ . '/traits/CampaignManager.php';
require_once __DIR__ . '/traits/StatsService.php';
 
use \PHPMailer\PHPMailer\PHPMailer;
use \PHPMailer\PHPMailer\Exception as PHPMailerException;

class TelegramBot {
    use \App\Traits\OrderManager, \App\Traits\PlanManager, \App\Traits\CampaignManager, \App\Traits\StatsService;
    // Constants
    const SMTP_FILE = __DIR__ . '/../config/smtps.txt';
    const LOG_FILE = __DIR__ . '/../logs/bot.log';
    // Data storage
    private $dataDir;
    
    // Bot properties
    private $token;
    private $username;
    private $apiUrl = 'https://api.telegram.org/bot';
    
    private $fileApiUrl = 'https://api.telegram.org/file/bot';
    
    // User data storage
    private $userData = [];
    
    // File storage path
    private $uploadsDir;
    
    // SMTP configuration
    private $currentSmtp = null;
    private $currentSmtpError = null;
    private $verifiedSmtps = [];
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
    
    // Scheduled tasks
    
    // Admin configuration
    private $adminIds = [];
    
    // Shared EmailSender for system SMTP pool and per-user custom senders
    private $sharedEmailSender = null;
    private $customEmailSenders = [];
    // SMTP pool
    private $smtps = [];
    // Internal scheduled tasks
    private $scheduledTasks = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        if (!defined('BOT_TOKEN')) {
            throw new \Exception('BOT_TOKEN not defined in config');
        }
        $this->token = BOT_TOKEN;
        $this->username = 'Cheto_inboxing_bot';
        $this->apiUrl .= $this->token;
        $this->fileApiUrl .= $this->token;
        $this->dataDir = __DIR__ . '/../data';
        $this->uploadsDir = __DIR__ . '/../uploads';
        
        if (defined('ADMIN_CHAT_IDS')) {
            $this->adminIds = ADMIN_CHAT_IDS;
        }
        
        // Create required directories if they don't exist
        foreach ([$this->dataDir, $this->uploadsDir] as $dir) {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true)) {
                    $error = error_get_last();
                    $this->log("Failed to create required directory {$dir}: " . ($error ? $error['message'] : 'Unknown error'));
                    throw new RuntimeException("Failed to create required directory: {$dir}");
                }
                $this->log("Created directory with secure permissions: {$dir}");
            }
        }
        
        // Clean up old uploads
        $this->cleanupUploads();
        
        // Load configuration
        $this->loadConfig();
        
        // Load SMTPs
        $this->loadSmtps();
        
        // Load user data
        $this->loadUserData();
    }

    /**
     * Load configuration
     */
    private function loadConfig() {
        // Load admin IDs
        if (defined('ADMIN_CHAT_IDS') && is_array(ADMIN_CHAT_IDS)) {
            $this->adminIds = ADMIN_CHAT_IDS;
        }
        
        // Load user data
        $this->loadUserData();
    }

    /**
     * Load user data from file
     */
    private function loadUserData() {
    // Prefer configured path from config.php, fallback to local config/users.json
    $userDataFile = defined('USER_DATA_FILE') ? USER_DATA_FILE : (dirname(__DIR__) . '/config/users.json');
        if (file_exists($userDataFile)) {
            // Shared lock while reading to avoid mid-write reads
            $decoded = [];
            $fh = @fopen($userDataFile, 'r');
            if ($fh) {
                @flock($fh, LOCK_SH);
                $data = stream_get_contents($fh);
                @flock($fh, LOCK_UN);
                fclose($fh);
            } else {
                $data = @file_get_contents($userDataFile);
            }
            if ($data === false) {
                $this->log("Error reading user data from {$userDataFile}");
                return;
            }
            $decoded = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log("Error decoding user data: " . json_last_error_msg());
                return;
            }
            $this->userData = $decoded ?: [];
            // Normalize/migrate schema to handle manual edits or legacy fields
            $changed = $this->normalizeUserDataSchema();
            if ($changed) {
                // Persist normalized data silently
                $this->saveUserData();
            }
            $this->log("Successfully loaded user data for " . count($this->userData) . " users");
        } else {
            $this->log("User data file not found at {$userDataFile}");
            $this->userData = [];
        }
    }

    /**
     * Normalize/migrate user data schema for robustness against manual edits.
     * - Converts legacy counters to current keys
     * - Ensures reset anchors exist (hour/day/month) without zeroing counts
     * - Parses string SMTP into structured array where needed
     * - Ensures minimal required fields exist with safe defaults
     * @return bool true if any changes were made
     */
    private function normalizeUserDataSchema() {
        $changed = false;
        $now = time();
        $hourAnchor = strtotime(date('Y-m-d H:00:00', $now));
        $dayAnchor = strtotime(date('Y-m-d', $now));
        $monthAnchor = strtotime(gmdate('Y-m-01 00:00:00', $now));
    $uploadsDir = __DIR__ . '/../uploads';

        foreach ($this->userData as $uid => &$ud) {
            if (!is_array($ud)) { continue; }

            // Ensure state exists
            if (!isset($ud['state']) || !is_string($ud['state'])) { $ud['state'] = 'idle'; $changed = true; }

            // Counters: migrate legacy keys
            if (!isset($ud['emails_sent_today'])) {
                if (isset($ud['daily_count']) && is_numeric($ud['daily_count'])) {
                    $ud['emails_sent_today'] = (int)$ud['daily_count'];
                } else {
                    $ud['emails_sent_today'] = $ud['emails_sent_today'] ?? 0;
                }
                $changed = true;
            }
            if (!isset($ud['emails_sent_month'])) {
                if (isset($ud['monthly_count']) && is_numeric($ud['monthly_count'])) {
                    $ud['emails_sent_month'] = (int)$ud['monthly_count'];
                } else {
                    $ud['emails_sent_month'] = $ud['emails_sent_month'] ?? 0;
                }
                $changed = true;
            }
            if (!isset($ud['emails_sent_hour'])) { $ud['emails_sent_hour'] = 0; $changed = true; }

            // Reset anchors: if missing, set anchors to current boundaries WITHOUT changing counts
            if (!isset($ud['last_hour_reset'])) { $ud['last_hour_reset'] = $hourAnchor; $changed = true; }
            if (!isset($ud['last_day_reset'])) {
                if (isset($ud['last_day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ud['last_day'])) {
                    $ud['last_day_reset'] = strtotime($ud['last_day']);
                } else {
                    $ud['last_day_reset'] = $dayAnchor;
                }
                $changed = true;
            }
            if (!isset($ud['last_month_reset'])) {
                if (isset($ud['last_month']) && preg_match('/^\d{4}-\d{2}$/', $ud['last_month'])) {
                    $ud['last_month_reset'] = strtotime($ud['last_month'] . '-01 00:00:00');
                } else {
                    $ud['last_month_reset'] = $monthAnchor;
                }
                $changed = true;
            }

            // Plan defaults
            if (!isset($ud['plan'])) { $ud['plan'] = 'trial'; $changed = true; }
            if (!isset($ud['plan_expires'])) {
                // Try legacy trial_end
                if (isset($ud['trial_end']) && is_numeric($ud['trial_end'])) {
                    $ud['plan_expires'] = (int)$ud['trial_end'];
                } else {
                    // Default 3 days from now
                    $ud['plan_expires'] = $now + (3 * 86400);
                }
                $changed = true;
            }

            // SMTP: if string, parse to array; if array, ensure required keys
            if (isset($ud['smtp']) && !empty($ud['smtp'])) {
                if (is_string($ud['smtp'])) {
                    try {
                        $cfg = $this->parseSmtpString($ud['smtp']);
                        $ud['smtp'] = [
                            'host' => $cfg['host'],
                            'port' => $cfg['port'],
                            'username' => $cfg['username'],
                            'password' => $cfg['password'],
                            'from_email' => $cfg['from_email'] ?? $cfg['username'],
                            'from_name' => $cfg['from_name'] ?? 'Notification',
                            'encryption' => $cfg['encryption'] ?? ''
                        ];
                        $changed = true;
                    } catch (\Throwable $e) {
                        // Leave as-is if unparseable
                    }
                } elseif (is_array($ud['smtp'])) {
                    if (!isset($ud['from_email']) && isset($ud['smtp']['username']) && !isset($ud['smtp']['from_email'])) {
                        $ud['smtp']['from_email'] = $ud['smtp']['username'];
                        $changed = true;
                    }
                    if (!isset($ud['smtp']['from_name'])) { $ud['smtp']['from_name'] = 'Notification'; $changed = true; }
                    if (!isset($ud['smtp']['port']) && isset($ud['smtp']['host'])) { $ud['smtp']['port'] = 587; $changed = true; }
                }
            }

            // Ensure uploads/file fields that changed names are carried over if present (no deletion of paths)
            if (isset($ud['files']) && is_array($ud['files'])) {
                if (isset($ud['files']['txt']) && !isset($ud['email_list_path'])) {
                    $ud['email_list_path'] = $ud['files']['txt'];
                    $changed = true;
                }
                if (isset($ud['files']['html']) && !isset($ud['html_template_path'])) {
                    $ud['html_template_path'] = $ud['files']['html'];
                    $changed = true;
                }
            }

            // If file paths point to another workspace or missing, try to relink by basename under current uploads dir
            foreach (['email_list_path', 'html_template_path'] as $pkey) {
                if (!empty($ud[$pkey]) && is_string($ud[$pkey]) && !file_exists($ud[$pkey])) {
                    $base = basename($ud[$pkey]);
                    $candidate = rtrim($uploadsDir, '/').'/'.$base;
                    if (file_exists($candidate)) {
                        $ud[$pkey] = $candidate;
                        $changed = true;
                    }
                }
            }

            // One-time reconciliation: if counters are zero/missing but last campaign completed with sent_count > 0,
            // add those to current hour/day/month when within boundaries, ensuring idempotency via a reconcile key.
            if (isset($ud['sending_state']) && is_array($ud['sending_state'])) {
                $st = $ud['sending_state'];
                $sent = (int)($st['sent_count'] ?? 0);
                $completed = ($st['completed'] ?? false) || (isset($st['current_index'], $st['total_emails']) && $st['current_index'] >= $st['total_emails']);
                $start = (int)($st['start_time'] ?? 0);
                $end = (int)($st['end_time'] ?? $st['last_batch_time'] ?? 0);
                $rk = md5(($start ?: '0').'|'.($st['total_emails'] ?? '0').'|'.$sent);
                $already = isset($ud['reconcile']) && ($ud['reconcile']['key'] ?? '') === $rk;
                $c0 = ((int)($ud['emails_sent_today'] ?? 0) === 0) && ((int)($ud['emails_sent_hour'] ?? 0) === 0) && ((int)($ud['emails_sent_month'] ?? 0) === 0);
                if ($sent > 0 && $completed && !$already && $c0) {
                    // Apply within current boundaries only
                    // Hour boundary check
                    $inHour = ($start >= $hourAnchor) || ($end >= $hourAnchor);
                    $inDay = ($start >= $dayAnchor) || ($end >= $dayAnchor);
                    $inMonth = ($start >= $monthAnchor) || ($end >= $monthAnchor);
                    if (!isset($ud['emails_sent_today'])) { $ud['emails_sent_today'] = 0; }
                    if (!isset($ud['emails_sent_hour'])) { $ud['emails_sent_hour'] = 0; }
                    if (!isset($ud['emails_sent_month'])) { $ud['emails_sent_month'] = 0; }
                    if ($inHour) { $ud['emails_sent_hour'] += $sent; }
                    if ($inDay) { $ud['emails_sent_today'] += $sent; }
                    if ($inMonth) { $ud['emails_sent_month'] += $sent; }
                    $ud['reconcile'] = ['key' => $rk, 'at' => $now];
                    $changed = true;
                }
            }
        }
        unset($ud);
        return $changed;
    }

    /**
     * Get user data
     * 
     * @param string $chatId Chat ID
     * @return array User data
     */
    private function getUserData($chatId) {
        // Initialize user data if it doesn't exist
        if (!isset($this->userData[$chatId])) {
            $this->initUserData($chatId);
            $this->log("Initialized new user data for chat ID {$chatId}");
        }
        return $this->userData[$chatId];
    }

    /**
     * Save user data to file
     */
    private function saveUserData() {
    // Prefer configured path from config.php, fallback to local config/users.json
    $userDataFile = defined('USER_DATA_FILE') ? USER_DATA_FILE : (dirname(__DIR__) . '/config/users.json');
        $dir = dirname($userDataFile);
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0777, true)) {
                $this->log("Error creating directory {$dir}");
                return;
            }
        }

        // Open and exclusively lock file for atomic read-merge-write
        $fh = @fopen($userDataFile, 'c+');
        if (!$fh) {
            $this->log("Error opening {$userDataFile} for writing");
            return;
        }
        @flock($fh, LOCK_EX);
        // Read current on-disk data
        rewind($fh);
        $raw = stream_get_contents($fh);
        $diskData = [];
        if ($raw !== false && strlen($raw) > 0) {
            $diskData = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) { $diskData = []; }
        }
        if (!is_array($diskData)) { $diskData = []; }

        // Merge memory into disk to avoid regressing counters
        $merged = $this->mergeUserDataSets($diskData, $this->userData);
        $json = json_encode($merged, JSON_PRETTY_PRINT);
        if (json_last_error() !== JSON_ERROR_NONE) {
            @flock($fh, LOCK_UN); fclose($fh);
            $this->log("Error encoding user data: " . json_last_error_msg());
            return;
        }
        // Truncate and write
        rewind($fh);
        ftruncate($fh, 0);
        $bytes = fwrite($fh, $json);
        fflush($fh);
        @flock($fh, LOCK_UN);
        fclose($fh);

        // Keep in-memory snapshot in sync with merged result
        $this->userData = $merged;
        $this->log("Successfully saved user data (" . (int)$bytes . " bytes) with merge");
    }

    /**
     * Merge two full user maps (disk and memory) safely to prevent regressions.
     */
    private function mergeUserDataSets($disk, $mem) {
        if (!is_array($disk)) { $disk = []; }
        if (!is_array($mem)) { $mem = []; }
        $out = $disk;
        foreach ($mem as $id => $m) {
            if (!isset($disk[$id])) { $out[$id] = $m; continue; }
            $out[$id] = $this->mergeUserRecords($disk[$id], $m);
        }
        return $out;
    }

    /**
     * Merge a single user's record. Prefers non-regressive values.
     */
    private function mergeUserRecords($disk, $mem) {
        if (!is_array($disk)) { return $mem; }
        if (!is_array($mem)) { return $disk; }
        $out = $disk;

        // Scalars - prefer memory unless it regresses time/counters
        $out['plan'] = $mem['plan'] ?? ($disk['plan'] ?? 'trial');
        // plan_expires: take the later expiry
        if (isset($disk['plan_expires']) || isset($mem['plan_expires'])) {
            $out['plan_expires'] = max((int)($disk['plan_expires'] ?? 0), (int)($mem['plan_expires'] ?? 0));
        }

        // Counters: take max to avoid decrements
        foreach (['emails_sent_hour','emails_sent_today','emails_sent_month'] as $k) {
            $dv = isset($disk[$k]) ? (int)$disk[$k] : 0;
            $mv = isset($mem[$k]) ? (int)$mem[$k] : 0;
            if ($dv === 0 && $mv === 0) { continue; }
            $out[$k] = max($dv, $mv);
        }
        // Reset anchors: take the latest timestamps
        foreach (['last_hour_reset','last_day_reset','last_month_reset'] as $k) {
            $dv = isset($disk[$k]) ? (int)$disk[$k] : 0;
            $mv = isset($mem[$k]) ? (int)$mem[$k] : 0;
            if ($dv || $mv) { $out[$k] = max($dv, $mv); }
        }

        // Derived fields (will be recomputed on updates). Keep mem if present else disk
        foreach (['remaining_this_hour','remaining_today','plan_days_left'] as $k) {
            if (isset($mem[$k])) { $out[$k] = $mem[$k]; }
            elseif (isset($disk[$k])) { $out[$k] = $disk[$k]; }
        }

        // sending_state: pick newer progress (by last_update or current_index)
        $sDisk = $disk['sending_state'] ?? null;
        $sMem  = $mem['sending_state'] ?? null;
        if (is_array($sDisk) || is_array($sMem)) {
            if (!is_array($sDisk)) { $out['sending_state'] = $sMem; }
            elseif (!is_array($sMem)) { $out['sending_state'] = $sDisk; }
            else {
                $luD = (int)($sDisk['last_update'] ?? 0);
                $luM = (int)($sMem['last_update'] ?? 0);
                $ciD = (int)($sDisk['current_index'] ?? 0);
                $ciM = (int)($sMem['current_index'] ?? 0);
                // Prefer memory if it's actively sending and disk is not (prevents startup race flipping to false)
                $activeM = (bool)($sMem['is_sending'] ?? false);
                $activeD = (bool)($sDisk['is_sending'] ?? false);
                $pickMem = ($activeM && !$activeD) || ($luM > $luD) || ($ciM > $ciD);
                $sel = $pickMem ? $sMem : $sDisk;
                // Clamp metrics to valid ranges for the selected state to avoid inflation from stale merges
                $total = (int)($sel['total_emails'] ?? 0);
                $ciSel = (int)($sel['current_index'] ?? 0);
                $sentSel = (int)($sel['sent_count'] ?? 0);
                $errSel = (int)($sel['error_count'] ?? 0);
                if ($total > 0) { $ciSel = min($ciSel, $total); }
                $sentSel = max(0, min($sentSel, $ciSel));
                $errSel = max(0, min($errSel, $ciSel - $sentSel));
                $sel['current_index'] = $ciSel;
                $sel['sent_count'] = $sentSel;
                $sel['error_count'] = $errSel;
                // counters_applied can't exceed sent_count
                $apSel = (int)($sel['counters_applied'] ?? 0);
                $sel['counters_applied'] = min($apSel, $sentSel);
                $out['sending_state'] = $sel;
            }
        }

        // last_campaign snapshot: prefer newer end_time
        $cDisk = $disk['last_campaign'] ?? null;
        $cMem  = $mem['last_campaign'] ?? null;
        if (is_array($cDisk) || is_array($cMem)) {
            if (!is_array($cDisk)) { $out['last_campaign'] = $cMem; }
            elseif (!is_array($cMem)) { $out['last_campaign'] = $cDisk; }
            else {
                $etD = (int)($cDisk['end_time'] ?? 0);
                $etM = (int)($cMem['end_time'] ?? 0);
                $out['last_campaign'] = ($etM >= $etD) ? $cMem : $cDisk;
            }
        }

        // email_stats: avoid decreasing totals; merge daily/monthly by key with max
        $eDisk = $disk['email_stats'] ?? null;
        $eMem  = $mem['email_stats'] ?? null;
        if (is_array($eDisk) || is_array($eMem)) {
            $stats = is_array($eDisk) ? $eDisk : [];
            foreach (['total_sent','total_errors','campaigns','last_campaign'] as $k) {
                $dv = $stats[$k] ?? 0;
                $mv = is_array($eMem) ? ($eMem[$k] ?? 0) : 0;
                if ($k === 'last_campaign') { $stats[$k] = max((int)$dv, (int)$mv); }
                else { $stats[$k] = max((int)$dv, (int)$mv); }
            }
            foreach (['monthly_stats','daily_stats'] as $k) {
                $coll = is_array($eDisk[$k] ?? null) ? $eDisk[$k] : [];
                $memc = is_array($eMem[$k] ?? null) ? $eMem[$k] : [];
                foreach ($memc as $kk => $vv) { $coll[$kk] = max((int)($coll[$kk] ?? 0), (int)$vv); }
                $stats[$k] = $coll;
            }
            $out['email_stats'] = $stats;
        }

        return $out;
    }

    /**
     * Load SMTP configurations
     */
    private $activeCampaigns = [];
    
    private function loadSmtps() {
        $smtps = [];
        $smtpsFile = self::SMTP_FILE;
        
        if (file_exists($smtpsFile)) {
            $lines = file($smtpsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                try {
                    $smtps[] = $this->parseSmtpString($line);
                } catch (Exception $e) {
                    $this->log("Invalid SMTP format: " . $line . " - Error: " . $e->getMessage());
                    continue;
                }
            }
            
            if (empty($smtps)) {
                throw new Exception("No valid SMTP configurations found in file");
            }
        } else {
            throw new Exception("SMTP configuration file not found: " . $smtpsFile);
        }
        
    $this->smtps = $smtps;
    $this->log("Loaded " . count($smtps) . " SMTP configurations successfully");
    $this->verifiedSmtps = [];

        // Auto-verify SMTPs and keep only working ones if available
        try {
            $verified = [];
            foreach ($this->smtps as $idx => $conf) {
                try {
                    $tester = new \App\EmailSender($conf, $this);
                    if ($tester->testConnection()) {
                        $verified[] = $conf;
                    }
                } catch (\Throwable $e) {
                    // skip invalid
                }
                // Short pause to avoid hammering
                usleep(100000);
            }
            // Do not drop unverified servers; keep full list and just record verified ones
            $this->verifiedSmtps = $verified;
            // Reorder list to prefer verified first, then the rest (stable unique)
            if (!empty($this->verifiedSmtps)) {
                $hash = static function($c){ return ($c['host']??'')."|".($c['username']??''); };
                $verifiedKeys = array_flip(array_map($hash, $this->verifiedSmtps));
                $rest = [];
                foreach ($this->smtps as $conf) {
                    if (!isset($verifiedKeys[$hash($conf)])) { $rest[] = $conf; }
                }
                $this->smtps = array_merge($this->verifiedSmtps, $rest);
                $this->log("SMTP verification complete: " . count($verified) . " working servers detected; ordered pool with verified first");
            } else {
                $this->log("SMTP verification found no working servers; using full list");
            }
            // Set current SMTP to first in ordered list if present
            if (!empty($this->smtps)) { $this->currentSmtp = $this->smtps[0]; }
        } catch (\Throwable $e) {
            $this->log('SMTP verification error: ' . $e->getMessage());
        }

        // Initialize shared EmailSender with the verified pool and set rotation to every 5 emails
        try {
            if (!empty($this->smtps)) {
                $this->sharedEmailSender = new \App\EmailSender($this->smtps, $this, 'trial');
                if (method_exists($this->sharedEmailSender, 'setRotationStrategy')) {
                    $this->sharedEmailSender->setRotationStrategy('weighted_random');
                }
                if (method_exists($this->sharedEmailSender, 'setEmailsPerRotation')) {
                    $this->sharedEmailSender->setEmailsPerRotation(1); // rotate every email
                }
                if (method_exists($this->sharedEmailSender, 'selectRandomSmtp')) {
                    $this->sharedEmailSender->selectRandomSmtp();
                }
            }
        } catch (\Throwable $e) {
            $this->log('Failed to initialize shared EmailSender: ' . $e->getMessage());
        }
    }

    /**
     * Persist removal of an SMTP entry when authentication is rejected.
     * It will remove the matching line from config/smtps.txt and refresh in-memory pool on next load.
     */
    public function onSmtpAuthRejected($host, $username, $reason = '') {
        try {
            $file = self::SMTP_FILE;
            if (!is_file($file) || !is_readable($file)) { return; }
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) { return; }
            $normalizedHost = strtolower(trim($host));
            $normalizedUser = strtolower(trim($username));
            $kept = [];
            $removed = [];
            foreach ($lines as $ln) {
                $raw = trim($ln);
                if ($raw === '') { continue; }
                // Robustly parse like parseSmtpString does
                $parts = str_getcsv($raw);
                if (count($parts) < 3) { $kept[] = $ln; continue; }
                $h = strtolower(trim(explode(':', trim($parts[0]))[0]));
                $u = strtolower(trim($parts[1]));
                if ($h === $normalizedHost && $u === $normalizedUser) {
                    $removed[] = $ln;
                    continue; // drop this line
                }
                $kept[] = $ln;
            }
            if (!empty($removed)) {
                // Write back file atomically
                $tmp = $file . '.tmp';
                file_put_contents($tmp, implode(PHP_EOL, $kept) . PHP_EOL);
                @chmod($tmp, 0644);
                rename($tmp, $file);
                $this->log("SMTP removed due to auth rejection: {$host} {$username} (" . count($removed) . " line)" );
                // Optionally notify admins quietly
                foreach ($this->adminIds as $adminId) {
                    try {
                        $msg = "Removed SMTP (auth rejected): $username@$host";
                        $this->sendTelegramMessage($adminId, $msg, ['disable_notification' => true]);
                    } catch (\Throwable $t) {}
                }
            }
        } catch (\Throwable $e) {
            $this->log('onSmtpAuthRejected error: ' . $e->getMessage());
        }
    }

    /**
     * Parse SMTP string into structured array with validation and smart defaults
     * 
     * @param string $smtpString SMTP configuration string
     * @return array Structured SMTP configuration
     * @throws Exception When configuration is invalid
     */
    private function parseSmtpString($smtpString) {
        // Clean input
        $smtpString = trim($smtpString);
        if (empty($smtpString)) {
            throw new Exception("Empty SMTP configuration string");
        }

        // Split with support for escaped commas
        $parts = str_getcsv($smtpString);
        if (count($parts) < 3) {
            throw new Exception("Invalid SMTP format - requires at least host:port, username, password");
        }
        
        // Parse host and port with validation
        $hostPort = explode(':', trim($parts[0]));
        if (count($hostPort) != 2) {
            // Try to determine port based on common defaults
            $host = $hostPort[0];
            $port = 587; // Default to STARTTLS port
            
            if (stripos($host, 'gmail') !== false) {
                $port = 587; // Gmail requires STARTTLS
            } elseif (stripos($host, 'office365') !== false || stripos($host, 'outlook') !== false) {
                $port = 587; // Microsoft services use STARTTLS
            }
        } else {
            $host = trim($hostPort[0]);
            $port = trim($hostPort[1]);
        }
        
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new Exception("Invalid SMTP host: $host");
        }
        
        $port = (int)$port;
        if ($port < 1 || $port > 65535) {
            throw new Exception("Invalid port number: $port");
        }

        // Determine encryption based on port if not specified
        $encryption = isset($parts[5]) ? strtolower(trim($parts[5])) : '';
        if (empty($encryption)) {
            $encryption = match($port) {
                465 => 'ssl',
                587, 2525 => 'tls',
                25 => '', // No encryption for port 25
                default => 'tls'
            };
        }
        
        // Validate encryption setting
        if (!in_array($encryption, ['', 'tls', 'ssl', 'starttls'], true)) {
            throw new Exception("Invalid encryption type: $encryption");
        }
        
        // Build configuration with validation and smart defaults
        $config = [
            'host' => $host,
            'port' => $port,
            'username' => trim($parts[1]),
            'password' => trim($parts[2]),
            'from_email' => isset($parts[3]) ? trim($parts[3]) : trim($parts[1]),
            'from_name' => isset($parts[4]) ? trim($parts[4]) : 'Notification',
            'encryption' => $encryption,
            'daily_limit' => isset($parts[6]) && is_numeric(trim($parts[6])) ? (int)trim($parts[6]) : 500,
            'hourly_limit' => isset($parts[7]) && is_numeric(trim($parts[7])) ? (int)trim($parts[7]) : 50
        ];
        
        // Validate email addresses
        if (!filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid from email address: {$config['from_email']}");
        }
        if (!filter_var($config['username'], FILTER_VALIDATE_EMAIL)) {
            // Some SMTP servers use non-email usernames, so this is just a warning
            trigger_error("Warning: Username is not an email address: {$config['username']}", E_USER_WARNING);
        }
        
        return $config;
    }

    /**
     * Test connection to Telegram API
     * 
     * @return bool True if connection is successful
     */
    public function testConnection() {
        $response = $this->callTelegramApi('getMe');
        return isset($response['ok']) && $response['ok'] === true;
    }



    /**
     * Send a request to Telegram API
     * 
     * @param string $method The API method to call
     * @param array $data The data to send
     * @return array|false Response from Telegram API or false on failure
     */
    private function sendTelegramRequest($method, $data = []) {
        try {
            $url = "https://api.telegram.org/bot{$this->token}/{$method}";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            
            if ($response === false) {
                $this->log("Telegram API request failed: " . curl_error($ch));
                curl_close($ch);
                return false;
            }
            
            curl_close($ch);
            
            $result = json_decode($response, true);
            
            if ($result === null) {
                $this->log("Failed to decode Telegram API response: " . json_last_error_msg());
                return false;
            }
            
            return $result;
        } catch (Exception $e) {
            $this->log("Error in sendTelegramRequest: " . $e->getMessage());
            return false;
        }
    }
    
    // Log method is already defined elsewhere in the file
    
    /**
     * Start the main polling loop
     */
    public function startPolling() {
        $offset = 0;
        $timeout = 30;
        $lastTaskCheck = 0;
        
        while (true) {
            try {
                // Check for internal tasks every 10 seconds
                $now = time();
                if ($now - $lastTaskCheck >= 10) {
                    $this->processInternalTasks();
                    $lastTaskCheck = $now;
                }
                
                $updates = $this->getUpdates($offset, $timeout);
                
                if (!empty($updates)) {
                    foreach ($updates as $update) {
                        // Process update
                        $this->processUpdate($update);
                        
                        // Update offset
                        $offset = $update['update_id'] + 1;
                    }
                }
            } catch (Exception $e) {
                $this->log("Error in polling loop: " . $e->getMessage());
                // Wait a bit before retrying
                sleep(5);
            }
        }
    }

    /**
     * Get updates from Telegram API
     * 
     * @param int $offset Update offset
     * @param int $timeout Long polling timeout
     * @return array Updates
     */
    private function getUpdates($offset, $timeout) {
        $params = [
            'offset' => $offset,
            'timeout' => $timeout
        ];
        
        $response = $this->callTelegramApi('getUpdates', $params);
        
        if (isset($response['ok']) && $response['ok'] === true && isset($response['result'])) {
            return $response['result'];
        }
        
        return [];
    }

    /**
     * Process an update from Telegram
     * 
     * @param array $update Update data
     */
    private function processUpdate($update) {
        try {
            // Update user activity timestamp
            if (isset($update['message']['chat']['id']) || 
                (isset($update['callback_query']) && isset($update['callback_query']['message']['chat']['id']))) {
                $chatId = isset($update['message']) ? $update['message']['chat']['id'] : $update['callback_query']['message']['chat']['id'];
                $this->updateUserActivity($chatId);
            }

            // Process message
            if (isset($update['message'])) {
                $this->processMessage($update['message']);
            }
            
            // Process callback query with improved error handling and rate limiting
            if (isset($update['callback_query']) && is_array($update['callback_query'])) {
                try {
                    // Validate callback query structure
                    if (!isset($update['callback_query']['message']['chat']['id']) ||
                        !isset($update['callback_query']['message']['message_id']) ||
                        !isset($update['callback_query']['data'])) {
                        $this->log("Invalid callback query structure received: " . json_encode($update['callback_query']));
                        return;
                    }
                    
                    $this->processCallbackQuery($update['callback_query']);
                } catch (Exception $e) {
                    $this->log("Error processing callback query: " . $e->getMessage());
                    if (isset($update['callback_query']['message']['chat']['id'])) {
                        $this->sendTelegramMessage(
                            $update['callback_query']['message']['chat']['id'],
                            "⚠️ An error occurred while processing your request. Please try again."
                        );
                    }
                }
            }
            
            // Process inline query
            if (isset($update['inline_query'])) {
                $this->processInlineQuery($update['inline_query']);
            }
        } catch (Exception $e) {
            $this->log("Error processing update: " . $e->getMessage());
        }
    }

    /**
     * Process a message
     * 
     * @param array $message Message data
     */
    private function processMessage($message) {
        try {
            // Get basic message info
            $chatId = $message['chat']['id'];
            $text = isset($message['text']) ? $message['text'] : '';
            
            // Initialize user data if needed
            if (!isset($this->userData[$chatId])) {
                $this->initUserData($chatId);
            }
            
            // Process commands
            if (isset($message['entities']) && $message['entities'][0]['type'] === 'bot_command') {
                $this->processCommand($chatId, $text);
                return;
            }
            
            // Process document uploads
            if (isset($message['document'])) {
                $this->log("Processing document upload for chat ID: " . $chatId);
                $state = isset($this->userData[$chatId]['state']) ? $this->userData[$chatId]['state'] : 'idle';
                
                switch ($state) {
                    case 'waiting_for_html':
                        $this->log("Processing HTML template upload");
                        $this->processHtmlLetterUpload($chatId, $message);
                        break;
                        
                    case 'waiting_for_txt':
                        $this->log("Processing email list upload");
                        $this->processTxtListUpload($chatId, $message);
                        break;
                        
                    default:
                        // Check file extension to try auto-detection
                        $fileName = isset($message['document']['file_name']) ? $message['document']['file_name'] : '';
                        if (preg_match('/\.html?$/i', $fileName)) {
                            $this->log("Auto-detected HTML template upload");
                            $this->userData[$chatId]['state'] = 'waiting_for_html';
                            $this->saveUserData();
                            $this->processHtmlLetterUpload($chatId, $message);
                        } else if (preg_match('/\.txt$/i', $fileName)) {
                            $this->log("Auto-detected email list upload");
                            $this->userData[$chatId]['state'] = 'waiting_for_txt';
                            $this->saveUserData();
                            $this->processTxtListUpload($chatId, $message);
                        } else {
                            // If it's a generic document, treat it as an attachment
                            if (isset($message['document'])) {
                                try {
                                    $doc = $message['document'];
                                    $fileId = $doc['file_id'];
                                    $fileInfo = $this->getFile($fileId);
                                    if ($fileInfo && isset($fileInfo['result']['file_path'])) {
                                        $filePath = $fileInfo['result']['file_path'];
                                        $fileUrl = $this->fileApiUrl . '/' . $filePath;
                                        $this->log("Downloading attachment from URL: {$fileUrl}");
                                        $content = @file_get_contents($fileUrl);
                                        if ($content !== false) {
                                            $uploadsDir = __DIR__ . '/../uploads';
                                            if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }
                                            $ext = pathinfo($doc['file_name'] ?? 'file', PATHINFO_EXTENSION) ?: 'bin';
                                            $local = $uploadsDir . '/attach_' . $chatId . '_' . time() . '_' . substr(md5($fileId), 0, 6) . '.' . $ext;
                                            if (@file_put_contents($local, $content) !== false) {
                                                $ud = $this->getUserData($chatId);
                                                if (!isset($ud['attachments']) || !is_array($ud['attachments'])) { $ud['attachments'] = []; }
                                                $ud['attachments'][] = $local;
                                                $this->userData[$chatId] = $ud;
                                                $this->saveUserData();
                                                $this->sendTelegramMessage($chatId, "📎 Attachment saved: " . basename($local));
                                                return;
                                            }
                                        }
                                    }
                                } catch (\Throwable $t) {
                                    $this->log('Attachment save error: ' . $t->getMessage());
                                }
                            }
                            $this->sendTelegramMessage($chatId, "📄 To upload files:\n1. Click 'Upload HTML Letter' for .html files\n2. Click 'Upload Email List' for .txt files\n3. Send any other document here to save as attachment.");
                        }
                        break;
                }
                return;
            }
            
            // Process regular message based on user state
            $this->processRegularMessage($chatId, $text, $message);
            
        } catch (Exception $e) {
            $this->log("Error in processMessage: " . $e->getMessage());
            $this->sendTelegramMessage($message['chat']['id'], "⚠️ An error occurred while processing your message. Please try again.");
        }
    }

    /**
     * Initialize user data
     * 
     * @param string $chatId Chat ID
     */
    private function initUserData($chatId) {
        // Get plans from configuration
        $plans = defined('PLANS') ? PLANS : [];
        $defaultPlan = 'trial';
        $planDuration = isset($plans[$defaultPlan]) ? $plans[$defaultPlan]['duration'] : 3; // Default to 3 days
        
        $this->userData[$chatId] = [
            'state' => 'idle',
            'files' => [],
            'plan' => $defaultPlan,
            'plan_expires' => time() + ($planDuration * 86400), // Convert days to seconds
            'emails_sent_today' => 0,
            'emails_sent_hour' => 0,
            'emails_sent_month' => 0,
            'last_hour_reset' => strtotime(date('Y-m-d H:00:00')),
            'last_day_reset' => strtotime(date('Y-m-d')),
            'last_month_reset' => strtotime(gmdate('Y-m-01 00:00:00')),
            'last_active' => time(),
            'smtp' => null,
            'settings' => [
                'premium' => $this->isAdmin($chatId)
            ],
            'sending_stats' => [
                'total_sent' => 0,
                'failed' => 0,
                'success_rate' => 0,
                'runtime' => 0,
                'smtp_rotations' => 0
            ],
            'is_demo' => false,
            'daily_sent' => 0,
            'last_send_day' => date('Y-m-d')
        ];
        
        // Save user data after initialization
        $this->saveUserData();
        $this->log("Initialized new user data for chat ID {$chatId}");
    }

    /**
     * Send welcome message with premium branding
     * 
     * @param string $chatId Chat ID
     */
    private function sendWelcomeMessage($chatId) {
        // Get user data
        $userData = $this->getUserData($chatId);
        $planId = isset($userData['plan']) ? $userData['plan'] : 'trial';
        $plans = defined('PLANS') ? PLANS : [];
        $plan = isset($plans[$planId]) ? $plans[$planId] : null;
        
        $message = "🔥 *CHETO INBOX SENDER - SNIPER VERSION* 🔥\n\n";
        $message .= "👋 Welcome to the most advanced email delivery system with ghost-mode technology!\n\n";
        $message .= "🚀 *FEATURES:*\n";
        $message .= "• 🎯 Precision targeting with custom SMTP\n";
        $message .= "• 👻 Ghost mode delivery - bypass spam filters\n";
        $message .= "• 📊 Real-time analytics and delivery reports\n";
        $message .= "• 📎 Support for attachments (PDF, HTML, etc.)\n";
        $message .= "• ↩️ Custom reply-to email options\n";
        $message .= "• ⚡ Lightning-fast delivery with SMTP rotation\n\n";
        
        // Create main menu keyboard
        $keyboard = [
            [
                ['text' => '📊 My Status', 'callback_data' => 'status'],
                ['text' => '💳 Plans & Pricing', 'callback_data' => 'plans']
            ],
            [
                ['text' => '📧 Email Setup', 'callback_data' => 'setup_menu'],
                ['text' => '📝 Help Guide', 'callback_data' => 'help']
            ]
        ];
        
        if ($this->isAdmin($chatId)) {
            $keyboard[] = [
                ['text' => '👑 Admin Panel', 'callback_data' => 'admin_panel']
            ];
        }
        
        if ($plan) {
            $message .= "🔰 *Your current plan:* {$plan['name']}\n";
            
            // If plan has expiration
            if (isset($userData['plan_expires']) && $userData['plan_expires'] > time()) {
                $daysLeft = ceil(($userData['plan_expires'] - time()) / 86400);
                $message .= "⏳ *Expires in:* {$daysLeft} days\n\n";
            }
        }
        
        $message .= "Select an option below to get started:";
        
        // Create main menu keyboard
        $keyboard = [
            [
                ['text' => '📊 My Status', 'callback_data' => 'status'],
                ['text' => '💳 Plans & Pricing', 'callback_data' => 'plans']
            ],
            [
                ['text' => '📧 Email Setup', 'callback_data' => 'setup_menu'],
                ['text' => '📝 Help Guide', 'callback_data' => 'help']
            ],
            [
                ['text' => '📄 Upload HTML Letter', 'callback_data' => 'upload_html'],
                ['text' => '📋 Upload TXT List', 'callback_data' => 'upload_txt']
            ],
            [
                ['text' => '🔄 Placeholders', 'callback_data' => 'placeholders']
            ]
        ];
        
        if ($this->isAdmin($chatId)) {
            $keyboard[] = [
                ['text' => '👑 Admin Panel', 'callback_data' => 'admin_panel']
            ];
        }
        
        $keyboard[] = [
            ['text' => '💬 Support', 'url' => 'https://t.me/Ninja111']
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Process a command
     * 
     * @param string $chatId Chat ID
     * @param string $text Command text
     */
    private function processCommand($chatId, $text) {
        $parts = explode(' ', $text);
        $command = strtolower(str_replace('@' . strtolower($this->username), '', $parts[0]));
        
        // Check admin commands
        $adminCommands = ['/admin', '/stats', '/broadcast', '/users', '/smtp'];
        if (in_array($command, $adminCommands) && !$this->isAdmin($chatId)) {
            $this->sendTelegramMessage($chatId, "❌ You are not authorized to use this command.");
            return;
        }

        
        switch ($command) {
            case '/start':
                $this->sendWelcomeMessage($chatId);
                break;
                
            case '/help':
                $this->sendHelpMessage($chatId);
                break;
                
            case '/status':
                $this->sendStatusMessage($chatId);
                break;
                
            case '/plans':
                $this->showPlans($chatId);
                break;
                
            case '/setsmtp':
                $this->handleSetSmtp($chatId, $parts);
                break;
                
            case '/send':
                try {
                    $userData = $this->getUserData($chatId);
                    if (isset($userData['sending_state']) && 
                        isset($userData['sending_state']['is_sending']) && 
                        $userData['sending_state']['is_sending'] === true) {
                        
                        $message = "⚠️ Email sending is already in progress.\n\n";
                        $message .= "Use /status to check progress or /stop to stop sending.";
                        $this->sendTelegramMessage($chatId, $message);
                        return;
                    }
                    $this->handleSendCommand($chatId);
                } catch (Exception $e) {
                    $this->log("Error in send command: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to start sending. Please try again.");
                }
                break;
        case '/pause':
                try {
                    $userData = $this->getUserData($chatId);
                    if (!isset($userData['sending_state']) || 
                        !isset($userData['sending_state']['is_sending']) || 
                        $userData['sending_state']['is_sending'] !== true) {
                        $this->sendTelegramMessage($chatId, "ℹ️ No active email sending to pause.");
                        return;
                    }
            $this->handlePauseSending($chatId);
                } catch (Exception $e) {
                    $this->log("Error in pause command: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to pause sending. Please try again.");
                }
                break;
                
        case '/resume':
                try {
                    $userData = $this->getUserData($chatId);
                    if (!isset($userData['sending_state']) || 
                        !isset($userData['sending_state']['is_sending']) || 
                        !isset($userData['sending_state']['is_paused']) || 
                        $userData['sending_state']['is_paused'] !== true) {
                        
                        $this->sendTelegramMessage($chatId, "ℹ️ No paused email sending to resume.");
                        return;
                    }
            $this->handleResumeSending($chatId);
                } catch (Exception $e) {
                    $this->log("Error in resume command: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to resume sending. Please try again.");
                }
                break;
                
        case '/stop':
                try {
                    $userData = $this->getUserData($chatId);
                    if (!isset($userData['sending_state']) || 
                        !isset($userData['sending_state']['is_sending']) || 
                        $userData['sending_state']['is_sending'] !== true) {
                        
                        $this->sendTelegramMessage($chatId, "ℹ️ No active email sending to stop.");
                        return;
                    }
            $this->handleStopSending($chatId);
                } catch (Exception $e) {
                    $this->log("Error in stop command: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to stop sending. Please try again.");
                }
                break;
                
            case '/reply':
                $this->handleReplyToCommand($chatId, $parts);
                break;
                
            case '/subject':
                $this->handleSubjectCommand($chatId, $parts);
                break;
                
            case '/admin':
                $this->sendAdminMenu($chatId);
                break;
                
            case '/status':
                $this->sendStatusMessage($chatId);
                break;
                
            case '/broadcast':
                $this->handleBroadcast($chatId, $text);
                break;
                
            case '/users':
                $this->handleUsers($chatId);
                break;
                
            case '/smtp':
                $this->handleSmtpManagement($chatId);
                break;

            case '/setplan':
                if ($this->isAdmin($chatId)) {
                    $this->handleSetPlan($chatId, $parts);
                } else {
                    $this->sendTelegramMessage($chatId, "❌ You are not authorized to use this command.");
                }
                break;

            // (Removed duplicate /users case)
                
            default:
                $this->sendTelegramMessage($chatId, "Unknown command. Use /help to see available commands.");
                break;
        }
    }

    /**
     * Provide concise status summary to user
     */
    private function sendStatusSummary($chatId) {
        $this->loadUserData();
        $userData = $this->userData[$chatId] ?? [];
        if (!isset($userData['sending_state']) || empty($userData['sending_state'])) {
            $this->sendTelegramMessage($chatId, "ℹ️ No active campaign. Use /start to begin.");
                    return;
        }
        $s = $userData['sending_state'];
        $total = $s['total_emails'] ?? 0;
        $sent = $s['sent_count'] ?? 0;
        $err  = $s['error_count'] ?? 0;
        $idx  = $s['current_index'] ?? 0;
        $pct  = $total>0 ? round(($idx / $total)*100,1) : 0;
        $elapsed = isset($s['start_time']) ? (time()-$s['start_time']) : 0;
        $dur = $elapsed < 60 ? $elapsed . 's' : (floor($elapsed/60) . 'm ' . ($elapsed%60) . 's');
        $msg = "📊 *Campaign Status*\n".
               "Progress: {$pct}% ({$idx}/{$total})\n".
               "Hits: {$sent} | Miss: {$err}\n".
               "Elapsed: {$dur}\n".
               (($s['is_paused'] ?? false)?"State: Paused":"State: Running");
        $this->sendTelegramMessage($chatId, $msg, ['parse_mode'=>'Markdown']);
    }

    /**
     * Centralized user stats increment (creates keys if missing)
     */
    private function updateUserSendStats(&$userData, $increment) {
        if ($increment <= 0) { return; }
        if (!isset($userData['emails_sent_today'])) { $userData['emails_sent_today'] = 0; }
        if (!isset($userData['emails_sent_month'])) { $userData['emails_sent_month'] = 0; }
    if (!isset($userData['emails_sent_hour'])) { $userData['emails_sent_hour'] = 0; }
        $userData['emails_sent_today'] += $increment;
        $userData['emails_sent_month'] += $increment;
    $userData['emails_sent_hour'] += $increment;
    }
    private function processRegularMessage($chatId, $text, $message) {
        try {
            // Process based on user state
            $state = isset($this->userData[$chatId]['state']) ? $this->userData[$chatId]['state'] : 'idle';
            $this->log("Processing message in state: {$state} for chat ID: {$chatId}");
            
            // Get user data
            $userData = $this->getUserData($chatId);
            
            switch ($state) {
                case 'idle':
                    // Show upload instructions or main menu
                    if (empty($text)) {
                        $this->showMainMenu($chatId);
                    } else {
                        $this->sendTelegramMessage($chatId, "Send a command to interact with the bot. Type /help for available commands.");
                    }
                    break;
                    
                case 'waiting_for_subject':
                    try {
                        $this->log("Processing subject input for user {$chatId}: " . substr($text, 0, 50) . "...");
                        
                        if (empty(trim($text))) {
                            $this->log("Empty subject received from user {$chatId}");
                            $this->sendTelegramMessage($chatId, 
                                "⚠️ *Invalid Subject*\n\n" .
                                "The subject line cannot be empty.\n" .
                                "Please enter a valid subject for your emails.", 
                                ['parse_mode' => 'Markdown']
                            );
                            return;
                        }
                        
                        // Validate and save the subject
                        $subject = trim($text);
                        if (strlen($subject) > 255) {
                            $this->log("Subject too long from user {$chatId}: " . strlen($subject) . " chars");
                            $this->sendTelegramMessage($chatId,
                                "⚠️ *Subject Too Long*\n\n" .
                                "Email subject must be less than 255 characters.\n" .
                                "Please enter a shorter subject.",
                                ['parse_mode' => 'Markdown']
                            );
                            return;
                        }
                        
                        // Save the subject
                        $userData = $this->getUserData($chatId);
                        $userData['email_subject'] = $subject;
                        $userData['state'] = 'idle';
                        $this->userData[$chatId] = $userData;
                        $this->saveUserData();
                        $this->log("Saved new subject for user {$chatId}");
                        
                        // Confirm and show options
                        $message = "✅ *Subject Updated Successfully*\n\n";
                        $message .= "New subject line:\n`{$subject}`\n\n";
                        $message .= "What would you like to do next?";
                        
                        $this->sendTelegramMessage($chatId, $message, [
                            'parse_mode' => 'Markdown',
                            'reply_markup' => json_encode([
                                'inline_keyboard' => [
                                    [
                                        ['text' => '📧 Start Sending', 'callback_data' => 'start_sending'],
                                        ['text' => '📝 Change Subject', 'callback_data' => 'subject_setup']
                                    ],
                                    [
                                        ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
                                    ]
                                ]
                            ])
                        ]);
                    } catch (Exception $e) {
                        $this->log("Error processing subject input: " . $e->getMessage());
                        $this->sendTelegramMessage($chatId, 
                            "⚠️ An error occurred while saving your subject.\nPlease try again or contact support."
                        );
                    }
                    break;
                    
                case 'waiting_for_sender_name':
                    try {
                        $this->log("Processing sender name input for user {$chatId}: " . substr($text, 0, 50) . "...");
                        
                        if (empty(trim($text))) {
                            $this->log("Empty sender name received from user {$chatId}");
                            $this->sendTelegramMessage($chatId, 
                                "⚠️ *Invalid Sender Name*\n\n" .
                                "The sender name cannot be empty.\n" .
                                "Please enter a valid name for your emails.", 
                                ['parse_mode' => 'Markdown']
                            );
                            return;
                        }
                        
                        // Validate and save the sender name
                        $senderName = trim($text);
                        if (strlen($senderName) > 100) {
                            $this->log("Sender name too long from user {$chatId}: " . strlen($senderName) . " chars");
                            $this->sendTelegramMessage($chatId,
                                "⚠️ *Sender Name Too Long*\n\n" .
                                "Sender name must be less than 100 characters.\n" .
                                "Please enter a shorter name.",
                                ['parse_mode' => 'Markdown']
                            );
                            return;
                        }
                        
                        // Save the sender name
                        $userData = $this->getUserData($chatId);
                        $userData['sender_name'] = $senderName;
                        $userData['state'] = 'idle';
                        $this->userData[$chatId] = $userData;
                        $this->saveUserData();
                    
                    // Confirm and show options
                    $message = "✅ Sender name has been set to:\n`{$text}`";
                    $this->sendTelegramMessage($chatId, $message, [
                        'parse_mode' => 'Markdown',
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [
                                    ['text' => '📧 Start Sending', 'callback_data' => 'start_sending'],
                                    ['text' => '⚙️ Settings', 'callback_data' => 'email_settings']
                                ]
                            ]
                        ])
                    ]);
                    } catch (Exception $e) {
                        $this->log("Error processing sender name: " . $e->getMessage());
                        $this->sendTelegramMessage($chatId, "⚠️ An error occurred while saving your sender name. Please try again.");
                    }
                    break;

                default:
                    // Unknown state
                    $this->log("Unknown state: " . $state . " for user " . $chatId);
                    $this->sendTelegramMessage($chatId, "⚠️ Session error. Type /start to begin again.");
                    $this->userData[$chatId]['state'] = 'idle';
                    $this->saveUserData();
                    break;
            }
        } catch (Exception $e) {
            $this->log("Error in processRegularMessage: " . $e->getMessage());
            $this->sendTelegramMessage($chatId, "⚠️ An error occurred while processing your message. Please try again.");
        }
    }

    /**
     * Process a callback query
     * 
     * @param array $callbackQuery Callback query data
     */
    /**
     * Update user activity timestamp and cleanup stale data
     * 
     * @param string $chatId Chat ID
     */
    private function updateUserActivity($chatId) {
        if (!isset($this->userData[$chatId])) {
            $this->initUserData($chatId);
        }
        
        $this->userData[$chatId]['last_active'] = time();
        
        // Clean up stale campaign data if needed
        if (isset($this->userData[$chatId]['sending_state'])) {
            $state = &$this->userData[$chatId]['sending_state'];
            if (isset($state['is_sending']) && $state['is_sending'] && 
                time() - ($state['last_update'] ?? 0) > 3600) { // 1 hour timeout
                $state['is_sending'] = false;
                $state['is_paused'] = false;
                $this->log("Cleaned up stale campaign state for user {$chatId}");
            }
        }
        
        $this->saveUserData();
    }

    private function processCallbackQuery($callbackQuery) {
        // Validate callback query structure
        if (!is_array($callbackQuery) || 
            !isset($callbackQuery['message']['chat']['id']) ||
            !isset($callbackQuery['message']['message_id']) ||
            !isset($callbackQuery['data'])) {
            $this->log("Invalid callback query structure: " . json_encode($callbackQuery));
            return;
        }
        
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $data = $callbackQuery['data'];
        
        try {
            // Acknowledge callback query immediately
            $this->callTelegramApi('answerCallbackQuery', [
                'callback_query_id' => $callbackQuery['id']
            ]);
            $acknowledged = true; // Mark that we've already acknowledged
            
            // Log callback for debugging
            $this->log("Processing callback query from user {$chatId}: {$data}");
            
            // Initialize user data if needed
            $userData = $this->getUserData($chatId);
            if (!isset($userData['last_callback'])) {
                $userData['last_callback'] = null;
            }
            
            // Prevent duplicate and rapid callback clicks
            $now = time();
            
            // Initialize last_callback if not set
            if (!isset($userData['last_callback'])) {
                $userData['last_callback'] = [
                    'key' => '',
                    'time' => 0
                ];
            }
            
            $callbackKey = $data . '_' . $messageId;
            
            // Check for duplicate or rapid callbacks
            if ($userData['last_callback']['key'] === $callbackKey &&
                ($now - $userData['last_callback']['time']) < 2) { // 2 second cooldown
                $this->log("Ignoring duplicate callback: {$callbackKey}");
                return;
            }
            
            // Update last callback information
            $userData['last_callback'] = [
                'key' => $callbackKey,
                'time' => $now
            ];
            
            // Save the updated user data
            $this->userData[$chatId] = $userData;
            $this->saveUserData();
            $this->saveUserData();

            // Process callback data based on the action
            switch ($data) {
            case 'main_menu':
                $this->showMainMenu($chatId, $messageId);
                break;
                
            case 'status':
                $this->sendStatusMessage($chatId);
                break;
                
            case 'plans':
                $this->showPlans($chatId);
                break;
                
            case 'admin_analytics':
                if ($this->isAdmin($chatId)) {
                    $this->showAnalytics($chatId);
                }
                break;

            case 'admin_pending_orders':
                if ($this->isAdmin($chatId)) {
                    $this->showPendingOrders($chatId);
                }
                break;
                
            case 'admin_smtp_status':
                if ($this->isAdmin($chatId)) {
                    $this->showSmtpStatus($chatId);
                }
                break;
            case 'admin_rotate_smtp':
                if ($this->isAdmin($chatId)) {
                    try {
                        if ($this->sharedEmailSender && method_exists($this->sharedEmailSender, 'flagCurrentSmtpSuspicious')) {
                            $this->sharedEmailSender->flagCurrentSmtpSuspicious();
                        } elseif ($this->sharedEmailSender && method_exists($this->sharedEmailSender, 'rotateToNextSmtp')) {
                            $this->sharedEmailSender->rotateToNextSmtp();
                        }
                        $this->sendTelegramMessage($chatId, "🔁 Rotated shared SMTP.");
                        $this->showSmtpStatus($chatId);
                    } catch (\Throwable $e) {
                        $this->sendTelegramMessage($chatId, "⚠️ Failed to rotate SMTP: " . $e->getMessage());
                    }
                }
                break;
            case 'admin_list_smtp':
                if ($this->isAdmin($chatId)) {
                    $msg = "📋 *Configured SMTPs*\n\n";
                    foreach ($this->smtps as $idx => $smtp) {
                        $active = ($this->currentSmtp && $smtp['username'] === ($this->currentSmtp['username'] ?? '')) ? ' (active)' : '';
                        $msg .= ($idx+1) . ". " . ($smtp['host'] ?? 'host') . ':' . ($smtp['port'] ?? 587) . " — " . ($smtp['username'] ?? 'user') . $active . "\n";
                    }
                    $this->sendTelegramMessage($chatId, $msg, ['parse_mode' => 'Markdown']);
                }
                break;
            case 'admin_add_smtp':
                if ($this->isAdmin($chatId)) {
                    $this->sendTelegramMessage($chatId, "Use /setsmtp to add a new SMTP: /setsmtp host:port,username,SECRET,from_email,from_name,encryption,daily_limit,hourly_limit");
                }
                break;
            case 'admin_remove_smtp':
                if ($this->isAdmin($chatId)) {
                    $this->sendTelegramMessage($chatId, "Removal via editing smtps.txt is disabled by request. Use cooldown/rotation to sideline bad nodes.");
                }
                break;
                
            case 'admin_users':
                if ($this->isAdmin($chatId)) {
                    $this->handleUsers($chatId);
                }
                break;
                
            case 'admin_broadcast':
                if ($this->isAdmin($chatId)) {
                    $this->showBroadcastMenu($chatId);
                }
                break;
                
            case 'admin_settings':
                if ($this->isAdmin($chatId)) {
                    $this->showSystemSettings($chatId);
                }
                break;
                
            case 'setup_menu':
                $this->showEmailSetupMenu($chatId);
                break;
                
            case 'help':
                $this->sendHelpMessage($chatId);
                break;
                
            case 'admin_panel':
                if ($this->isAdmin($chatId)) {
                    $this->sendAdminMenu($chatId);
                }
                break;

            case 'admin_set_plan':
                if ($this->isAdmin($chatId)) {
                    $this->showSetPlanMenu($chatId);
                }
                break;

            case 'admin_list_users':
                if ($this->isAdmin($chatId)) {
                    $this->showUserList($chatId);
                }
                break;

            case 'admin_plan_stats':
                if ($this->isAdmin($chatId)) {
                    $this->showPlanStats($chatId);
                }
                break;
                
            case 'smtp_setup':
                $this->showSmtpSetup($chatId);
                break;
                
            case 'attachment_setup':
                $this->showAttachmentMenu($chatId);
                break;
            case 'clear_attachments':
                $ud = $this->getUserData($chatId);
                $ud['attachments'] = [];
                $this->userData[$chatId] = $ud;
                $this->saveUserData();
                $this->sendTelegramMessage($chatId, "🗑️ Attachments cleared.");
                $this->showAttachmentMenu($chatId);
                break;
                
            case 'reply_setup':
                $this->showReplyToSetup($chatId);
                break;

            case 'start_sending':
                try {
                    $this->log("Start sending button clicked by user {$chatId}");
                    
                    // Get user data
                    $userData = $this->getUserData($chatId);
                    
                    // Verify email list and template are uploaded
                    if (!isset($userData['email_list_path']) || !file_exists($userData['email_list_path'])) {
                        throw new Exception("Please upload your email list first!");
                    }
                    
                    if (!isset($userData['html_template_path']) || !file_exists($userData['html_template_path'])) {
                        throw new Exception("Please upload your HTML template first!");
                    }
                    
                    // Start sending directly (no confirmation needed)
                    $this->log("Starting email sending directly");
                    $this->startEmailSending($chatId);
                    
                } catch (Exception $e) {
                    $this->log("Error in start_sending callback: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to prepare email sending: " . $e->getMessage());
                }
                break;
                
            case 'email_settings':
                $this->showEmailSettings($chatId);
                break;
                
            case 'pause_sending':
                try {
                    $this->handlePauseSending($chatId);
                } catch (Exception $e) {
                    $this->log("Error in pause_sending: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to pause sending: " . $e->getMessage());
                }
                break;
                
            case 'resume_sending':
                try {
                    $this->handleResumeSending($chatId);
                } catch (Exception $e) {
                    $this->log("Error in resume_sending: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to resume sending: " . $e->getMessage());
                }
                break;

            case 'rotate_smtp_now':
                try {
                    // Prefer a custom sender if present for this chat
                    if (isset($this->customEmailSenders[$chatId])) {
                        $sender = $this->customEmailSenders[$chatId];
                        if (method_exists($sender, 'flagCurrentSmtpSuspicious')) {
                            $sender->flagCurrentSmtpSuspicious();
                        } elseif (method_exists($sender, 'rotateToNextSmtp')) {
                            $sender->rotateToNextSmtp();
                        }
                    } elseif ($this->sharedEmailSender) {
                        if (method_exists($this->sharedEmailSender, 'flagCurrentSmtpSuspicious')) {
                            $this->sharedEmailSender->flagCurrentSmtpSuspicious();
                        } elseif (method_exists($this->sharedEmailSender, 'rotateToNextSmtp')) {
                            $this->sharedEmailSender->rotateToNextSmtp();
                        }
                    }

                    $this->sendTelegramMessage($chatId, "🔁 Rotated SMTP for upcoming sends.");
                } catch (Exception $e) {
                    $this->log("Error in rotate_smtp_now: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to rotate SMTP: " . $e->getMessage());
                }
                break;
                
            case 'stop_sending':
                try {
                    $this->handleStopSending($chatId);
                } catch (Exception $e) {
                    $this->log("Error in stop_sending: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to stop sending: " . $e->getMessage());
                }
                break;
                
            case 'placeholders':
                $message = "🔄 *Available Placeholders*\n\n";
                $message .= "Use these placeholders in your email template:\n\n";
                $message .= "• {email} or {{email}} - Recipient's email address\n";
                $message .= "• {random_string} or {{random_string}} - Random string (for spam prevention)\n";
                $message .= "• {date} or {{date}} - Current date\n";
                $message .= "• {time} or {{time}} - Current time\n\n";
                $message .= "Example usage in HTML:\n";
                $message .= "`<p>Hello {email}!</p>`\n";
                $message .= "`<p>Your unique code: {random_string}</p>`";
                
                $keyboard = [
                    [
                        ['text' => '📝 View Template', 'callback_data' => 'view_template'],
                        ['text' => '📧 Email Setup', 'callback_data' => 'setup_menu']
                    ],
                    [
                        ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
                    ]
                ];
                
                $this->sendTelegramMessage($chatId, $message, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
                break;

            case 'view_template':
                // Get user data to check if template exists
                $userData = $this->getUserData($chatId);
                if (!isset($userData['html_template']) || empty($userData['html_template'])) {
                    $message = "⚠️ No HTML template uploaded yet.\n\n";
                    $message .= "Please upload your HTML template first.";
                    
                    $keyboard = [
                        [
                            ['text' => '📄 Upload Template', 'callback_data' => 'upload_html'],
                            ['text' => '🔙 Back', 'callback_data' => 'placeholders']
                        ]
                    ];
                    
                    $this->sendTelegramMessage($chatId, $message, [
                        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                    ]);
                    break;
                }
                
                // Show template preview
                $template = $userData['html_template'];
                $preview = substr($template, 0, 500) . (strlen($template) > 500 ? "..." : "");
                
                $message = "📝 *Current Template Preview*\n\n";
                $message .= "`" . $preview . "`\n\n";
                $message .= "Template length: " . strlen($template) . " characters";
                
                $keyboard = [
                    [
                        ['text' => '📄 Upload New', 'callback_data' => 'upload_html'],
                        ['text' => '🔄 Placeholders', 'callback_data' => 'placeholders']
                    ],
                    [
                        ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
                    ]
                ];
                
                $this->sendTelegramMessage($chatId, $message, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
                break;
                
            case 'stop_sending_confirm':
                try {
                    $this->log("Stop sending confirmed by user {$chatId}");
                    $this->handleStopSending($chatId);
                    $this->sendTelegramMessage($chatId, "✅ Email sending has been stopped.");
                } catch (Exception $e) {
                    $this->log("Error in stop_sending_confirm: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to stop sending: " . $e->getMessage());
                }
                break;
                
            case 'stop_sending_cancel':
                $this->sendTelegramMessage($chatId, "✅ Email sending will continue.");
                break;
                
            case 'cancel_sending':
                try {
                    $userData = $this->getUserData($chatId);
                    $userData['state'] = 'idle';
                    $this->userData[$chatId] = $userData;
                    $this->saveUserData();
                    
                    if (isset($callbackQuery['message']['message_id'])) {
                        $this->editMessageText(
                            $chatId,
                            $callbackQuery['message']['message_id'],
                            "❌ *Email Campaign Cancelled*\n\nThe email campaign has been cancelled.",
                            ['parse_mode' => 'Markdown']
                        );
                    }
                } catch (Exception $e) {
                    $this->log("Error in cancel_sending: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Error while cancelling: " . $e->getMessage());
                }
                break;
                
            case 'subject_setup':
                $userData = $this->getUserData($chatId);
                $currentSubject = isset($userData['email_subject']) ? $userData['email_subject'] : 'Not set';
                $currentName = isset($userData['sender_name']) ? $userData['sender_name'] : 'Not set';
                
                $message = "📝 *Email Subject & Sender Settings*\n\n";
                $message .= "Current settings:\n";
                $message .= "• Subject: `" . $currentSubject . "`\n";
                $message .= "• Sender Name: `" . $currentName . "`\n\n";
                $message .= "Select an option to change:";
                
                $keyboard = [
                    [
                        ['text' => '✏️ Change Subject', 'callback_data' => 'set_subject'],
                        ['text' => '👤 Change Name', 'callback_data' => 'set_sender_name']
                    ],
                    [
                        ['text' => '❌ Clear Subject', 'callback_data' => 'clear_subject'],
                        ['text' => '❌ Clear Name', 'callback_data' => 'clear_sender']
                    ],
                    [
                        ['text' => '🔙 Back', 'callback_data' => 'email_settings']
                    ]
                ];
                
                $this->sendTelegramMessage($chatId, $message, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
                break;

            case 'set_subject':
                $userData = $this->getUserData($chatId);
                $userData['state'] = 'waiting_for_subject';
                $this->userData[$chatId] = $userData;
                $this->saveUserData();
                
                $this->sendTelegramMessage($chatId, 
                    "📝 *Set Email Subject*\n\n" .
                    "Please enter the subject line for your emails.\n" .
                    "Current subject: " . (isset($userData['email_subject']) ? "`{$userData['email_subject']}`" : "Default"),
                    ['parse_mode' => 'Markdown']
                );
                break;

            // (Duplicate set_sender_name / clear_subject / clear_sender cases removed)
                
            case 'email_settings':
                $this->showEmailSettings($chatId);
                break;
                
            case 'upload_html':
                try {
                    $this->handleHtmlUpload($chatId);
                } catch (Exception $e) {
                    $this->log("Error in upload_html callback: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to process HTML upload. Please try again.");
                }
                break;
                
            case 'upload_txt':
                try {
                    $this->handleTxtUpload($chatId);
                } catch (Exception $e) {
                    $this->log("Error in upload_txt callback: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to process email list upload. Please try again.");
                }
                break;
                
            case 'placeholders':
                try {
                    $this->showPlaceholders($chatId);
                } catch (Exception $e) {
                    $this->log("Error in placeholders callback: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "⚠️ Failed to show placeholders. Please try again.");
                }
                break;

            case 'show_plans':
                $this->showPlansMenu($chatId);
                break;
                
            case 'contact_support':
                $this->showContactSupport($chatId);
                break;
                
            // duplicates removed below: stop_sending, pause_sending, resume_sending
                
            case 'email_stats':
                $this->showEmailStats($chatId);
                break;
                
            case 'test_smtp':
                try {
                    $userData = $this->getUserData($chatId);
                    if (!isset($userData['smtp'])) {
                        $this->sendTelegramMessage($chatId, "⚠️ Please configure SMTP first");
                        return;
                    }
                    
                    // Test SMTP connection using a temporary sender
                    $tempSender = new \App\EmailSender($userData['smtp']);
                    $success = $tempSender->testConnection();
                    
                    if ($success) {
                        $this->sendTelegramMessage($chatId, "✅ SMTP connection test successful!");
                    } else {
                        $this->sendTelegramMessage($chatId, "❌ SMTP connection test failed. Please check your settings.");
                    }
                } catch (Exception $e) {
                    $this->log("SMTP test error: " . $e->getMessage());
                    $this->sendTelegramMessage($chatId, "❌ SMTP test failed: " . $e->getMessage());
                }
                break;

            // duplicates removed below: set_subject, set_sender_name, clear_subject, clear_sender, smtp_setup
                
            default:
                // Handle plan selection
                if (str_starts_with($data, 'plan_select_')) {
                    $planId = substr($data, strlen('plan_select_'));
                    $this->handlePlanSelection($chatId, $planId);
                }
                // Handle payment confirmation
                elseif (str_starts_with($data, 'confirm_payment_')) {
                    $orderId = substr($data, strlen('confirm_payment_'));
                    $this->handlePaymentConfirmation($chatId, $orderId);
                }
                // Handle order approval by admin
                elseif (str_starts_with($data, 'approve_order_')) {
                    if ($this->isAdmin($chatId)) {
                        $orderId = substr($data, strlen('approve_order_'));
                        $this->handleOrderApproval($chatId, $orderId);
                    }
                }
                // Handle order rejection by admin
                elseif (str_starts_with($data, 'reject_order_')) {
                    if ($this->isAdmin($chatId)) {
                        $orderId = substr($data, strlen('reject_order_'));
                        $this->handleOrderRejection($chatId, $orderId);
                    }
                } else {
                    $this->log("Unknown callback data: " . $data);
                }
                break;
            }
            
            // Acknowledge callback query only if we haven't already
            if (!isset($acknowledged)) {
                $this->callTelegramApi('answerCallbackQuery', [
                    'callback_query_id' => $callbackQuery['id']
                ]);
            }
        } catch (Exception $e) {
            $this->log("Error in processCallbackQuery: " . $e->getMessage());
            if (!isset($acknowledged)) {
                $this->callTelegramApi('answerCallbackQuery', [
                    'callback_query_id' => $callbackQuery['id'],
                    'text' => "⚠️ An error occurred. Please try again."
                ]);
            }
            if (isset($chatId)) {
                $this->sendTelegramMessage($chatId, "⚠️ An error occurred while processing your request. Please try again.");
            }
        }
    }

    /**
     * Process an inline query
     * 
     * @param array $inlineQuery Inline query data
     */
    private function processInlineQuery($inlineQuery) {
        // Implementation depends on specific inline query requirements
        // Currently not implemented
        return;
    }

    /**
     * Send help message
     * 
     * @param string $chatId Chat ID
     */
    private function sendHelpMessage($chatId) {
        $message = "📋 *CHETO INBOX SENDER COMMANDS*\n\n";
        
        $message .= "🔷 *Basic Commands:*\n";
        $message .= "• /help - Show this help message\n";
        $message .= "• /status - Show your current status and limits\n";
        $message .= "• /plans - View available premium plans\n\n";
        
        $message .= "🔷 *Email Setup:*\n";
        $message .= "• /setsmtp - Configure your SMTP server\n";
    $message .= "  Format: /setsmtp host:port,username,••••••••,from,name\n\n";
        
        $message .= "🔷 *Email Sending:*\n";
        $message .= "• Upload a file named 'list.txt' with email addresses (one per line)\n";
        $message .= "• Upload a file named 'letter.html' with your email template\n";
        $message .= "• Optional: Upload attachments (PDF, images, etc.)\n";
        $message .= "• /send - Start sending emails with controls\n\n";
        
        $message .= "🔷 *During Sending:*\n";
        $message .= "• /pause - Pause the email sending process\n";
        $message .= "• /resume - Resume paused sending process\n";
        $message .= "• /stop - Stop sending completely\n\n";
        
        $message .= "🔷 *Advanced Options:*\n";
        $message .= "• /reply - Set custom reply-to email\n";
        $message .= "• /subject - Set email subject line\n";
        
        if ($this->isAdmin($chatId)) {
            $message .= "\n👑 *Admin Commands:*\n";
            $message .= "• /admin - Show admin control panel\n";
            $message .= "• /stats - Show detailed system statistics\n";
            $message .= "• /broadcast - Send message to all users\n";
            $message .= "• /users - List all users and their status\n";
            $message .= "• /smtp - Manage SMTP configurations\n";
            $message .= "• /setplan - Set user plan by chat ID\n";
        }
        
        $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
    }

    /**
     * Send status message
     * 
     * @param string $chatId Chat ID
     */
    private function sendStatusMessage($chatId) {
    // Load user data fresh in case another worker updated users.json (do not mutate counters here)
    $this->loadUserData();
    $userData = $this->getUserData($chatId);
    // Fallback: if counters are zero but last campaign shows sent_count, present those as provisional for this status
    $sentToday = isset($userData['emails_sent_today']) ? (int)$userData['emails_sent_today'] : 0;
    $sentThisHour = isset($userData['emails_sent_hour']) ? (int)$userData['emails_sent_hour'] : 0;
    $sentThisMonth = isset($userData['emails_sent_month']) ? (int)$userData['emails_sent_month'] : 0;
    // Also load aggregates to avoid under-reporting if they are higher than live counters
    $aggDay = 0; $aggMonth = 0;
    if (isset($userData['email_stats']) && is_array($userData['email_stats'])) {
        $stats = $userData['email_stats'];
        $currentMonth = date('Y-m');
        $currentDay = date('Y-m-d');
        $aggDay = (int)($stats['daily_stats'][$currentDay] ?? 0);
        $aggMonth = (int)($stats['monthly_stats'][$currentMonth] ?? 0);
        // Prefer the higher value for display
        $sentToday = max($sentToday, $aggDay);
        $sentThisMonth = max($sentThisMonth, $aggMonth);
        if ($aggDay > 0) { $sentThisHour = max($sentThisHour, $aggDay); }
    }
    if ($sentToday === 0 && $sentThisHour === 0 && $sentThisMonth === 0) {
        $st = null;
        if (isset($userData['sending_state'])) {
            $st = $userData['sending_state'];
        } elseif (isset($userData['last_campaign'])) {
            $st = $userData['last_campaign'];
        }
        if (is_array($st)) {
            $sc = (int)($st['sent_count'] ?? 0);
            $completed = ($st['completed'] ?? false) || (isset($st['current_index'], $st['total_emails']) && $st['current_index'] >= $st['total_emails']);
            if ($sc > 0 && $completed) {
                // Display-only fallback, don't override persisted counters here
                $sentToday = $sc;
                $sentThisHour = $sc;
                $sentThisMonth = $sc;
            }
        } elseif (isset($userData['email_stats']) && is_array($userData['email_stats'])) {
            // Secondary fallback to aggregated stats when available
            $stats = $userData['email_stats'];
            $currentMonth = date('Y-m');
            $currentDay = date('Y-m-d');
            $aggDay = (int)($stats['daily_stats'][$currentDay] ?? 0);
            $aggMonth = (int)($stats['monthly_stats'][$currentMonth] ?? 0);
            if ($aggDay > 0 || $aggMonth > 0) {
                $sentToday = max($sentToday, $aggDay);
                $sentThisMonth = max($sentThisMonth, $aggMonth);
                // Approximate this hour with today's count when no better data is present
                $sentThisHour = max($sentThisHour, $aggDay);
            }
        }
    }

    // Self-heal: if persisted counters lag behind computed display values (from aggregates/last campaign), bump them up and save.
    $persistH = (int)($userData['emails_sent_hour'] ?? 0);
    $persistD = (int)($userData['emails_sent_today'] ?? 0);
    $persistM = (int)($userData['emails_sent_month'] ?? 0);
    $needsSave = false;
    if ($sentThisHour > $persistH) { $userData['emails_sent_hour'] = $sentThisHour; $needsSave = true; }
    if ($sentToday > $persistD) { $userData['emails_sent_today'] = $sentToday; $needsSave = true; }
    if ($sentThisMonth > $persistM) { $userData['emails_sent_month'] = $sentThisMonth; $needsSave = true; }
    if ($needsSave) {
        // Ensure reset anchors exist
        if (!isset($userData['last_hour_reset'])) { $userData['last_hour_reset'] = strtotime(date('Y-m-d H:00:00')); }
        if (!isset($userData['last_day_reset'])) { $userData['last_day_reset'] = strtotime(date('Y-m-d')); }
        if (!isset($userData['last_month_reset'])) { $userData['last_month_reset'] = strtotime(gmdate('Y-m-01 00:00:00')); }
    if (method_exists($this, 'refreshDerivedQuotaFields')) { $this->refreshDerivedQuotaFields($chatId); }
        $this->userData[$chatId] = $userData;
        $this->saveUserData();
        $this->log("Self-healed counters for {$chatId}: H {$persistH}→{$sentThisHour}, D {$persistD}→{$sentToday}, M {$persistM}→{$sentThisMonth}");
    }
        
        // Trace counters used in status for diagnostics (not shown to user)
        $this->log("Status counters for {$chatId}: H={$sentThisHour}, D={$sentToday}, M={$sentThisMonth}");
            // Build status message
    $message = "📊 *Email Campaign Status*\n\n";
        
        // Plan information
        $planId = isset($userData['plan']) ? $userData['plan'] : 'trial';
        $plans = defined('PLANS') ? PLANS : [];
        $plan = isset($plans[$planId]) ? $plans[$planId] : [
            'name' => 'Demo Trial',
            'duration' => 3,
            'emails_per_hour' => 100,
            'emails_per_day' => 100,
            'description' => 'Trial account with basic features'
        ];
        
        $message .= "💳 *Current Plan:* " . ($plan['name'] ?? 'Demo Trial') . "\n";
        if ($planId === 'trial') {
            $message .= "(Demo: 3 days • 30/hour • 100/day)\n";
        }
        // Show explicit remaining plan days if expiration is tracked
        $planDaysLeft = null;
        if (isset($userData['plan_days_left'])) {
            $planDaysLeft = max(0, (int)$userData['plan_days_left']);
        } elseif (isset($userData['plan_expires'])) {
            $planDaysLeft = ($userData['plan_expires'] > time()) ? (int)ceil(($userData['plan_expires'] - time()) / 86400) : 0;
        }
        if ($planDaysLeft !== null) {
            $message .= "🗓️ Remaining in plan days: {$planDaysLeft}\n";
        }
        
        // Expiration date
    if (isset($userData['plan_expires']) && $userData['plan_expires'] > time()) {
            $daysLeft = ceil(($userData['plan_expires'] - time()) / 86400);
            $message .= "📅 Expires in: {$daysLeft} days\n";
    } elseif (isset($userData['plan_expires'])) {
            $message .= "📅 Status: Expired\n";
        } else {
            $message .= "📅 Status: Active\n";
        }
        
    // Calculate limits and usage
    $isExpired = (isset($userData['plan_expires']) && $userData['plan_expires'] <= time());
    $dailyLimit = $this->getPlanDailyLimit($planId);
    $hourlyLimit = $this->getPlanHourlyLimit($planId);
    // Compute display-only remainings to avoid any race with persisted fields
    $computedRemainingToday = ($dailyLimit === PHP_INT_MAX) ? 'Unlimited' : max(0, $dailyLimit - $sentToday);
    $computedRemainingHour = ($hourlyLimit === PHP_INT_MAX) ? 'Unlimited' : max(0, $hourlyLimit - $sentThisHour);
        
        // Usage limits
    $message .= "\n💾 Usage Limits:\n";
        if ($isExpired) {
            $message .= "• Plan status: Expired\n";
            $message .= "• Renew to restore sending limits.\n";
        } else {
            $message .= "• Emails per hour: " . ($hourlyLimit === PHP_INT_MAX ? "Unlimited" : number_format($hourlyLimit)) . "\n";
            $message .= "• Emails per day: " . ($dailyLimit === PHP_INT_MAX ? "Unlimited" : number_format($dailyLimit)) . "\n";
            $message .= "• Remaining today: " . (is_numeric($computedRemainingToday) ? number_format($computedRemainingToday) : $computedRemainingToday) . "\n";
            $message .= "• Remaining this hour: " . (is_numeric($computedRemainingHour) ? number_format($computedRemainingHour) : $computedRemainingHour) . "\n";
        }
        
        // Current campaign status if active
        if (isset($userData['sending_state']) && isset($userData['sending_state']['is_sending']) && $userData['sending_state']['is_sending']) {
            $state = $userData['sending_state'];
            $progress = round(($state['current_index'] / $state['total_emails']) * 100, 1);
            $message .= "\n📈 *Active Campaign:*\n";
            $message .= "• Total Emails: {$state['total_emails']}\n";
            $message .= "• Progress: {$progress}%\n";
            // Show sent strictly from state to avoid inflated counters
            $message .= "• Sent: " . (int)($state['sent_count'] ?? 0) . "\n";
            
            if (((int)($state['error_count'] ?? 0)) > 0) {
                $message .= "• Failed: " . (int)($state['error_count'] ?? 0) . "\n";
                if (isset($state['last_error'])) {
                    $message .= "• Last Error: {$state['last_error']}\n";
                }
            }
            
            // Omit ETA and speed for a cleaner card
        }

        // Usage statistics
    // Keep usage stats concise
    $message .= "\n📊 *Usage Statistics:*\n";
    $message .= "• Sent This Hour: " . number_format($sentThisHour) . "\n";
    $message .= "• Sent Today: " . number_format($sentToday) . "\n";
    $message .= "• Sent This Month: " . number_format($sentThisMonth) . "\n";

    // Show limit status (deduplicated, info already shown above)
        $message .= "\n⚡ *Sending Limits:*\n";
        if ($isExpired) {
            $message .= "• Your plan is expired. Tap Renew below.\n";
        } else {
            $message .= "• Daily Limit: " . ($dailyLimit == PHP_INT_MAX ? 'Unlimited' : number_format($dailyLimit)) . "\n";
        }
        
        // SMTP information
    // Hide SMTP details to keep the card minimal
        
        // Add appropriate keyboard based on status
        $keyboard = [];
    if (!$isExpired && isset($userData['sending_state']) && isset($userData['sending_state']['is_sending']) && $userData['sending_state']['is_sending']) {
            $keyboard = [
                [
                    ['text' => '⏸️ Pause Sending', 'callback_data' => 'pause_sending'],
                    ['text' => '⏹️ Stop Sending', 'callback_data' => 'stop_sending']
                ],
                [
                    ['text' => '🔄 Refresh Status', 'callback_data' => 'status'],
                    ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
                ]
            ];
    } else {
            $keyboard = [
                [
                    ['text' => '🔄 Refresh Status', 'callback_data' => 'status']
                ],
                [
            ['text' => ($isExpired ? '💳 Renew Plan' : '🔙 Main Menu'), 'callback_data' => ($isExpired ? 'plans' : 'main_menu')]
                ]
            ];
        }
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Show SMTP setup menu
     * 
     * @param string $chatId Chat ID
     */
    private function showSmtpSetup($chatId) {
        // Get user plan
        $userData = $this->getUserData($chatId);
        $planId = isset($userData['plan']) ? $userData['plan'] : 'trial';
        
        // Block SMTP setup for trial/free users
        if ($planId === 'trial' || $planId === 'free') {
            $message = "⛔ *SMTP Configuration Locked*\n\n";
            $message .= "Custom SMTP configuration is only available for premium users.\n";
            $message .= "Please upgrade to unlock this feature.\n\n";
            $message .= "Current plan: *" . ucfirst($planId) . "*\n\n";
            $message .= "Benefits of upgrading:\n";
            $message .= "• Custom SMTP servers\n";
            $message .= "• Higher sending limits\n";
            $message .= "• Custom subjects and sender names\n";
            
            $keyboard = [
                [
                    ['text' => '🚀 Upgrade Plan', 'callback_data' => 'show_plans'],
                    ['text' => '🔙 Back', 'callback_data' => 'setup_menu']
                ]
            ];
            
            $this->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            return;
        }
        
        $message = "⚙️ *SMTP Configuration*\n\n";
        $message .= "Configure your SMTP server for sending emails:\n\n";
        $message .= "1. Enter your SMTP details using the format:\n";
    $message .= "`/setsmtp host:port,username,********,from,name`\n\n";
        $message .= "Example:\n";
        $message .= "`/setsmtp smtp.gmail.com:587,user@gmail.com,pass,from@gmail.com,John`\n\n";
        $message .= "Current SMTP Status:";
        
        $userData = $this->getUserData($chatId);
        if (isset($userData['smtp'])) {
            $smtp = $userData['smtp'];
            $message .= "\n✅ *Configured*\n";
            $message .= "• Host: `{$smtp['host']}:{$smtp['port']}`\n";
            $message .= "• Username: `{$smtp['username']}`\n";
            $message .= "• From: `" . (isset($smtp['from_email']) ? $smtp['from_email'] : $smtp['username']) . "`\n";
        } else {
            $message .= "\n❌ *Not Configured*";
        }
        
        $keyboard = [
            [
                ['text' => '🔄 Test SMTP', 'callback_data' => 'test_smtp'],
                ['text' => '🗑️ Clear SMTP', 'callback_data' => 'clear_smtp']
            ],
            [
                ['text' => '🔙 Back to Setup', 'callback_data' => 'setup_menu']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Process subject input from user
     * 
     * @param int $chatId The user's chat ID
     * @param string $text The subject text
     */
    private function processSubjectInput($chatId, $text) {
        // Check if user has premium plan
        $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
        $userPlan = isset($userData['plan']) ? $userData['plan'] : 'trial';
        
        // Only paid plan users can customize subject
        if ($userPlan === 'free' || $userPlan === 'trial') {
            $message = "⛔ *Subject Customization Locked*\n\n";
            $message .= "Custom email subjects are only available for premium users.\n";
            $message .= "Please upgrade to unlock this feature.\n\n";
            $message .= "Current plan: *" . ucfirst($userPlan) . "*";
            
            $keyboard = [
                [
                    ['text' => '🔓 Upgrade Plan', 'callback_data' => 'show_plans'],
                    ['text' => '💬 Contact Support', 'callback_data' => 'contact_support']
                ],
                [
                    ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
                ],
                [
                    ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
                ]
            ];
            
            $this->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            return;
        }
        
        // For premium users, process the subject
        $subject = trim($text);
        
        if (empty($subject)) {
            $this->sendTelegramMessage($chatId, "❌ Subject cannot be empty. Please enter a valid subject.");
            return;
        }
        
        // Save the subject to user data
        $userData['email_subject'] = $subject;
        $userData['state'] = 'idle'; // Reset state
        $this->userData[$chatId] = $userData;
        $this->saveUserData();
        
        // Confirm to user
        $message = "✅ Email subject has been set to: *{$subject}*\n\nYou can now continue with your email setup."; 
        
        $keyboard = [
            [
                ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
            ],
            [
                ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Show analytics data to admin
     * 
     * @param string $chatId Chat ID
     */
    private function showAnalytics($chatId) {
        if (!$this->isAdmin($chatId)) {
            $this->sendTelegramMessage($chatId, "❌ You are not authorized to view analytics.");
            return;
        }

        // Get total users
        $totalUsers = count($this->userData);
        
        // Get active users (active in last 24 hours)
        $activeUsers = 0;
        $now = time();
        foreach ($this->userData as $userData) {
            if (isset($userData['last_active']) && $now - $userData['last_active'] < 86400) {
                $activeUsers++;
            }
        }

        // Get total emails sent today
        $totalEmailsToday = 0;
        foreach ($this->userData as $userData) {
            $totalEmailsToday += isset($userData['emails_sent_today']) ? $userData['emails_sent_today'] : 0;
        }

        // Get total emails sent this month
        $totalEmailsMonth = 0;
        foreach ($this->userData as $userData) {
            $totalEmailsMonth += isset($userData['emails_sent_month']) ? $userData['emails_sent_month'] : 0;
        }

        // Build analytics message
        $message = "📊 *System Analytics*\n\n";
        
        $message .= "👥 *User Statistics:*\n";
        $message .= "• Total Users: " . number_format($totalUsers) . "\n";
        $message .= "• Active Users (24h): " . number_format($activeUsers) . "\n\n";
        
        $message .= "📧 *Email Statistics:*\n";
        $message .= "• Sent Today: " . number_format($totalEmailsToday) . "\n";
        $message .= "• Sent This Month: " . number_format($totalEmailsMonth) . "\n\n";
        
        $message .= "📈 *SMTP Status:*\n";
        $message .= "• Available SMTPs: " . count($this->smtps) . "\n";
        $message .= "• Current SMTP: " . ($this->currentSmtp ? $this->currentSmtp['host'] : 'None') . "\n";
        $message .= "• Rotation Count: " . $this->smtpRotationStats['rotation_count'] . "\n";
        $message .= "• Total Failures: " . $this->smtpRotationStats['failure_count'] . "\n";
        
        // Add keyboard for admin actions
        $keyboard = [
            [
                ['text' => '📊 Plan Stats', 'callback_data' => 'admin_plan_stats'],
                ['text' => '👥 List Users', 'callback_data' => 'admin_list_users']
            ],
            [
                ['text' => '📧 SMTP Status', 'callback_data' => 'admin_smtp_status'],
                ['text' => '📝 Pending Orders', 'callback_data' => 'admin_pending_orders']
            ],
            [
                ['text' => '🔙 Back to Admin', 'callback_data' => 'admin_panel']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Show sender name setup menu
     * 
     * @param int $chatId The user's chat ID
     */
    private function showSenderNameSetup($chatId) {
        $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
        $currentSenderName = isset($userData['sender_name']) ? $userData['sender_name'] : 'Not set';
        $userPlan = isset($userData['plan']) ? $userData['plan'] : 'free';
        
        // Check if user has premium plan
        if ($userPlan === 'free') {
            $message = "⛔ *Sender Name Customization Locked*\n\n";
            $message .= "Current sender name: *{$currentSenderName}*\n\n";
            $message .= "Custom sender names are only available for premium users.\n";
            $message .= "Please upgrade to any premium plan to unlock this feature.";
            
            $keyboard = [
                [
                    ['text' => '🔓 Upgrade Plan', 'callback_data' => 'show_plans'],
                    ['text' => '💬 Contact Support', 'callback_data' => 'contact_support']
                ],
                [
                    ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
                ],
                [
                    ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
                ]
            ];
            
            $this->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            return;
        }
        
        // For premium users, show sender name setup
        $message = "👤 *Sender Name Setup*\n\n";
        $message .= "Current sender name: *{$currentSenderName}*\n\n";
        $message .= "Please enter a new sender name for your emails. Send your message with the name text.";
        
        $keyboard = [
            [
                ['text' => '🔙 Back to Email Settings', 'callback_data' => 'email_settings']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        // Set user state to wait for sender name input
        $userData['state'] = 'waiting_for_sender_name';
        $this->userData[$chatId] = $userData;
        $this->saveUserData();
    }

    /**
     * Show available premium plans menu
     * 
     * @param int $chatId The user's chat ID
     */
    private function showPlansMenu($chatId) {
        $message = "🌟 *CHETO INBOX SENDER PLANS*\n\n";
        $message .= "Choose your perfect sending power:\n\n";
        
        $message .= "*⚡ Rotation Plan 3 Days - $130*\n";
        $message .= "• 1,000 emails per hour\n";
        $message .= "• 7,000 emails per day\n";
        $message .= "• Premium SMTP rotation\n";
        $message .= "• Full speed sending\n\n";
        
        $message .= "*⚡⚡ Rotation Plan 7 Days - $300*\n";
        $message .= "• 1,500 emails per hour\n";
        $message .= "• 8,000 emails per day\n";
        $message .= "• Premium SMTP rotation\n";
        $message .= "• Enhanced delivery success\n\n";
        
        $message .= "*⚡⚡⚡ Rotation Plan 14 Days - $650*\n";
        $message .= "• 2,000 emails per hour\n";
        $message .= "• 10,000 emails per day\n";
        $message .= "• Premium SMTP rotation\n";
        $message .= "• Maximum delivery rate\n\n";
        
        $message .= "*🚀 Rotation Plan 30 Days - $1300*\n";
        $message .= "• 3,000 emails per hour\n";
        $message .= "• 15,000 emails per day\n";
        $message .= "• Premium SMTP rotation\n";
        $message .= "• Ultimate sending power\n\n";
        
        $message .= "*💼 Sniper Custom SMTP - 30 Days*\n";
        $message .= "• Use your own SMTP servers\n";
        $message .= "• Unlimited sending (SMTP dependent)\n";
        $message .= "• Custom sender names\n";
        $message .= "• Full control over delivery\n\n";
        
        $message .= "Select a plan to upgrade:";
        
        $keyboard = [
            [
                ['text' => '3️⃣ 3 Days ($130)', 'callback_data' => 'plan_select_rotation_3'],
                ['text' => '7️⃣ 7 Days ($300)', 'callback_data' => 'plan_select_rotation_7']
            ],
            [
                ['text' => '🔥 14 Days ($650)', 'callback_data' => 'plan_select_rotation_14'],
                ['text' => '⭐ 30 Days ($1300)', 'callback_data' => 'plan_select_rotation_30']
            ],
            [
                ['text' => '💼 Custom SMTP (30 Days)', 'callback_data' => 'plan_select_custom_smtp']
            ],
            [
                ['text' => '💰 Crypto Payment', 'callback_data' => 'crypto_payment']
            ],
            [
                ['text' => '🔙 Back', 'callback_data' => 'email_settings']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Clean up old files in the uploads directory
     * Keeps files that are no more than 24 hours old
     */
    /**
     * Clean up campaign data after completion or error
     * 
     * @param int $chatId User's chat ID
     */
    /**
     * Clean up campaign data with notification
     * 
     * @param int $chatId User's chat ID
     */
    private function cleanupCampaign($chatId) {
        if (!isset($this->userData[$chatId])) {
            return;
        }

        $this->cleanupCampaignData($chatId);
        $this->sendTelegramMessage($chatId, "Campaign data has been cleaned up. You can start a new campaign.");
    }

    /**
     * Clean up campaign data without notification
     * 
     * @param int $chatId User's chat ID
     */
    private function cleanupCampaignQuietly($chatId) {
        if (!isset($this->userData[$chatId])) {
            return;
        }

        $this->cleanupCampaignData($chatId);
    }

    /**
     * Internal method to clean up campaign data
     * 
     * @param int $chatId User's chat ID
     */
    private function cleanupCampaignData($chatId) {
        // Remove sending state but keep a minimal snapshot for status reconciliation
        if (isset($this->userData[$chatId]['sending_state']) && is_array($this->userData[$chatId]['sending_state'])) {
            try {
                $st = $this->userData[$chatId]['sending_state'];
                $this->userData[$chatId]['last_campaign'] = [
                    'total_emails' => $st['total_emails'] ?? 0,
                    'sent_count' => $st['sent_count'] ?? 0,
                    'error_count' => $st['error_count'] ?? 0,
                    'completed' => true,
                    'start_time' => $st['start_time'] ?? null,
                    'end_time' => time(),
                ];
            } catch (\Throwable $t) { /* ignore */ }
            unset($this->userData[$chatId]['sending_state']);
        }

    // Keep uploaded files so users can reuse them next time.
    // If you need to clear, provide an explicit UI action to remove these paths.

        $this->userData[$chatId]['state'] = 'idle';
        $this->saveUserData();
        $this->log("Cleaned up campaign data for chat ID: {$chatId}");
    }

    private function cleanupUploads() {
        $uploadsDir = __DIR__ . '/../uploads';
        if (!is_dir($uploadsDir)) {
            if (!@mkdir($uploadsDir, 0755, true)) {
                $error = error_get_last();
                $this->log("Failed to create uploads directory during cleanup: " . ($error ? $error['message'] : 'Unknown error'));
                return;
            }
            return;
        }

        // Get list of files
        $files = @glob($uploadsDir . '/*');
        if ($files === false) {
            $this->log("Failed to read uploads directory during cleanup");
            return;
        }

        $now = time();
        $dayAgo = $now - 86400; // 24 hours ago
        $deleted = 0;
        $errors = 0;

        foreach ($files as $file) {
            // Skip if not a regular file
            if (!is_file($file)) {
                continue;
            }
            
            // Check modification time
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime < $dayAgo) {
                // Try to delete old file
                $this->log("Attempting to clean up old file: " . basename($file));
                if (@unlink($file) === true) {
                    $deleted++;
                } else {
                    $error = error_get_last();
                    $this->log("Failed to delete old file {$file}: " . ($error ? $error['message'] : 'Unknown error'));
                    $errors++;
                }
            }
        }
        
        if ($deleted > 0 || $errors > 0) {
            $this->log("Cleanup complete - Deleted: {$deleted}, Errors: {$errors}");
        }
    }

    /**
     * Handle start sending command
     * 
     * @param int $chatId The user's chat ID
     */
    private function handleStartSending($chatId) {
        try {
            // Reload user data to ensure we have the latest
            $this->loadUserData();
            $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
            
            // Minimal log
            $this->log("handleStartSending for {$chatId}");
            $planId = isset($userData['plan']) ? $userData['plan'] : 'free';
            
            // Load email list and count
            $emails = file($userData['email_list_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $emailCount = count($emails);
        
        // (Removed verbose debug logs)
        
        // Check if HTML template is uploaded - only check if path is set
        if (!isset($userData['html_template_path'])) {
            $message = "⚠️ *Missing HTML Template*\n\n";
            $message .= "Please upload an HTML template before starting the email sending process.";
            
            $keyboard = [
                [
                    ['text' => '📤 Upload Template', 'callback_data' => 'upload_html']
                ],
                [
                    ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
                ]
            ];
            
            $this->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            return;
        }
        
        // Check if email list is uploaded - only check if path is set
        if (!isset($userData['email_list_path'])) {
            $message = "⚠️ *Missing Email List*\n\n";
            $message .= "Please upload a list of email recipients before starting the email sending process.";
            
            $keyboard = [
                [
                    ['text' => '📤 Upload Email List', 'callback_data' => 'upload_emails']
                ],
                [
                    ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
                ]
            ];
            
            $this->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            return;
        }
        
        // Check SMTP configuration based on plan
        if ($planId === 'custom_smtp') {
            if (!isset($userData['smtp'])) {
                $message = "⚠️ *SMTP Configuration Required*\n\n";
                $message .= "Your plan requires custom SMTP configuration.\n";
                $message .= "Please set up your SMTP details before starting the email sending process.";
                
                $keyboard = [
                    [
                        ['text' => '📧 Configure SMTP', 'callback_data' => 'smtp_setup']
                    ],
                    [
                        ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
                    ]
                ];
                
                $this->sendTelegramMessage($chatId, $message, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
                return;
            }
        } else if (empty($this->smtps)) {
            // For all other plans, we need system SMTPs
            $message = "⚠️ *System SMTP Unavailable*\n\n";
            $message .= "Our system SMTP servers are currently unavailable.\n";
            $message .= "Please try again later or contact support for assistance.";
            
            $keyboard = [
                [
                    ['text' => '💬 Contact Support', 'callback_data' => 'contact_support']
                ],
                [
                    ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
                ]
            ];
            
            $this->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            return;
        }
        
        // Validate HTML template path
        if (!isset($userData['html_template_path'])) {
            $message = "⚠️ *Missing HTML Template*\n\n";
            $message .= "Please upload an HTML template first.";
            $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
            return;
        }
        
        // Validate email list path
        if (!isset($userData['email_list_path'])) {
            $message = "⚠️ *Missing Email List*\n\n";
            $message .= "Please upload a list of email recipients first.";
            $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
            return;
        }
        
        // Validate file existence and read email count
        $emailCount = 0;
        $templateFile = $userData['html_template_path'];
        $emailListFile = $userData['email_list_path'];
        
        if (!file_exists($templateFile)) {
            $this->log("HTML template not found at: {$templateFile}");
            $message = "⚠️ *HTML Template Not Found*\n\n";
            $message .= "Please upload your HTML template again.";
            $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
            return;
        }
        
        if (!file_exists($emailListFile)) {
            $this->log("Email list not found at: {$emailListFile}");
            $message = "⚠️ *Email List Not Found*\n\n";
            $message .= "Please upload your email list again.";
            $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
            return;
        }
        
        // Read and validate email count
        $emails = file($emailListFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $emailCount = count($emails);
        $this->log("Successfully read {$emailCount} emails from list");
        
        if ($emailCount === 0) {
            $message = "⚠️ *Empty Email List*\n\n";
            $message .= "Your email list appears to be empty.\n";
            $message .= "Please upload a list containing at least one email address.";
            $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
            return;
        }
        
        // Check email count against plan limits
        $dailyLimit = $this->getPlanDailyLimit($planId);
        if ($planId !== 'custom_smtp' && $emailCount > $dailyLimit) {
            $message = "⚠️ *Email Limit Exceeded*\n\n";
            $message .= "Your current plan ({$planId}) allows sending up to {$dailyLimit} emails per day.\n";
            $message .= "Your email list contains {$emailCount} recipients.\n\n";
            $message .= "Please upgrade your plan or reduce your recipient list.";
            
            $keyboard = [
                [
                    ['text' => '🌟 Upgrade Plan', 'callback_data' => 'show_plans']
                ],
                [
                    ['text' => '📤 Upload Smaller List', 'callback_data' => 'upload_emails']
                ],
                [
                    ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
                ]
            ];
            
            $this->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            return;
        }
        
    // Direct start: simply invoke startEmailSending (it re-validates and sets state)
    $this->log("Directly invoking startEmailSending from handleStartSending");
    $this->startEmailSending($chatId);
        
        } catch (Exception $e) {
            $this->log("Error in handleStartSending: " . $e->getMessage());
            $this->sendTelegramMessage($chatId, "⚠️ An error occurred while preparing to send emails. Please try again or contact support.");
        }
    }

    /**
     * Edit a Telegram message text
     * 
     * @param int $chatId The chat ID where the message is
     * @param int $messageId The message ID to edit
     * @param string $text The new text for the message
     * @param array|null $replyMarkup Optional reply markup (inline keyboard)
     * @return bool Success status
     */
    private function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
        $data = [
            'chat_id' => (string)$chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];

        // Allow caller to control parse_mode and reply markup
        if (is_array($replyMarkup)) {
            if (isset($replyMarkup['parse_mode'])) {
                $data['parse_mode'] = $replyMarkup['parse_mode'];
                unset($replyMarkup['parse_mode']);
            } else {
                $data['parse_mode'] = 'Markdown';
            }
            if (isset($replyMarkup['reply_markup'])) {
                // If caller already provided encoded reply_markup, pass it through
                $data['reply_markup'] = $replyMarkup['reply_markup'];
            } elseif (isset($replyMarkup['inline_keyboard'])) {
                $data['reply_markup'] = json_encode(['inline_keyboard' => $replyMarkup['inline_keyboard']]);
            }
        } else {
            // Default parse mode
            $data['parse_mode'] = 'Markdown';
        }

        $response = $this->sendTelegramRequest('editMessageText', $data);
        return isset($response['ok']) && $response['ok'] === true;
    }

    /**
     * Get daily email limit based on plan
     * 
     * @param string $planId The plan ID
     * @return int Daily email limit
     */
    private function getPlanDailyLimit($planId) {
        if (defined('PLANS') && isset(PLANS[$planId])) {
            $d = PLANS[$planId]['emails_per_day'] ?? 0;
            return ($d === -1) ? PHP_INT_MAX : (int)$d;
        }
        // Fallback: trial/free conservative default
        return ($planId === 'custom_smtp') ? PHP_INT_MAX : 500;
    }

    /**
     * Get hourly email limit based on plan
     *
     * @param string $planId The plan ID
     * @return int Hourly email limit
     */
    private function getPlanHourlyLimit($planId) {
        if (defined('PLANS') && isset(PLANS[$planId])) {
            $h = PLANS[$planId]['emails_per_hour'] ?? 0;
            return ($h === -1) ? PHP_INT_MAX : (int)$h;
        }
        return ($planId === 'custom_smtp') ? PHP_INT_MAX : 50;
    }

    /**
     * Start email sending process
     * 
     * @param int $chatId The user's chat ID
     */
    /**
     * Verify all required data is present for sending emails
     * 
     * @param array $userData User data array
     * @return array [bool $isValid, string $errorMessage]
     */
    private function verifyEmailSendingRequirements($userData) {
        if (!isset($userData['html_template_path']) || !file_exists($userData['html_template_path'])) {
            return [false, "HTML template not found or invalid"];
        }
        
        if (!isset($userData['email_list_path']) || !file_exists($userData['email_list_path'])) {
            return [false, "Email list not found or invalid"];
        }
        
        // Check SMTP configuration
        $planId = isset($userData['plan']) ? $userData['plan'] : 'free';
        if ($planId === 'custom_smtp' && (!isset($userData['smtp']) || empty($userData['smtp']))) {
            return [false, "SMTP configuration required for custom SMTP plan"];
        } elseif (empty($this->smtps)) {
            return [false, "System SMTP servers are not available"];
        }
        
        return [true, ""];
    }

    private function startEmailSending($chatId) {
        try {
            $this->log("startEmailSending called for chat ID: {$chatId}");
            
            // Reload user data to ensure we have the latest
            $this->loadUserData();
            $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];

            // Renewal gating: block sending if plan expired
            if (method_exists($this, 'isPlanExpired') && $this->isPlanExpired($chatId)) {
                $this->sendTelegramMessage($chatId, "❌ Your plan has expired. Please renew to continue.", [
                    'reply_markup' => json_encode(['inline_keyboard' => [ [ ['text' => '💳 Renew Plan', 'callback_data' => 'plans'] ] ]])
                ]);
                return;
            }
            
            // Validate requirements first
            if (!isset($userData['html_template_path']) || !file_exists($userData['html_template_path'])) {
                $this->sendTelegramMessage($chatId, "⚠️ HTML template not found. Please upload it again.");
                return;
            }
            
            if (!isset($userData['email_list_path']) || !file_exists($userData['email_list_path'])) {
                $this->sendTelegramMessage($chatId, "⚠️ Email list not found. Please upload it again.");
                return;
            }
            
            // Read and validate data
            $emails = file($userData['email_list_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $htmlTemplate = file_get_contents($userData['html_template_path']);
            $totalEmails = count($emails);
            
            if ($totalEmails === 0) {
                $this->sendTelegramMessage($chatId, "⚠️ Your email list is empty.");
                return;
            }
            
            if (empty($htmlTemplate)) {
                $this->sendTelegramMessage($chatId, "⚠️ Your HTML template is empty.");
                return;
            }
            
            // Get plan and validate limits
            $planId = isset($userData['plan']) ? $userData['plan'] : 'free';
            $dailyLimit = $this->getPlanDailyLimit($planId);
            // Refresh counters (auto reset hour/day/month) so checks are accurate
            if (method_exists($this, 'checkHourlyLimit')) { $this->checkHourlyLimit($chatId); }
            if (method_exists($this, 'checkDailyLimit')) { $this->checkDailyLimit($chatId); }
            if (method_exists($this, 'checkMonthlyReset')) { $this->checkMonthlyReset($chatId); }
            
            // Initialize or get current daily sent count
            if (!isset($userData['emails_sent_today'])) {
                $userData['emails_sent_today'] = 0;
            }
            
            // Check remaining daily limit
            $remainingDaily = $dailyLimit - $userData['emails_sent_today'];
            if ($planId !== 'custom_smtp' && ($remainingDaily <= 0 || $totalEmails > $remainingDaily)) {
                $this->sendTelegramMessage($chatId, 
                    "⚠️ Daily limit reached or exceeded!\n\n" .
                    "• Daily Limit: {$dailyLimit} emails\n" .
                    "• Sent Today: {$userData['emails_sent_today']} emails\n" .
                    "• Remaining: {$remainingDaily} emails\n\n" .
                    "Please try again tomorrow or upgrade your plan.");
                return;
            }

            // Also block immediately if current hour/day limits are already reached
            if (method_exists($this, 'canSendEmails')) {
                $check = $this->canSendEmails($chatId);
                if (!($check['can_send'] ?? false)) {
                    $msg = $check['message'] ?? "Limit reached. Try again later.";
                    $this->sendTelegramMessage($chatId, $msg, [
                        'parse_mode' => 'Markdown'
                    ]);
                    return;
                }
            }
            
            // For free plan, enforce default subject and sender name
            if ($planId === 'free' || $planId === 'trial') {
                $userData['email_subject'] = "Test email from @Cheto_inboxing_bot";
                $userData['sender_name'] = "@Cheto_inboxing_bot";
            }
            
            // Initialize sending state
            $userData['sending_state'] = [
                'total_emails' => $totalEmails,
                'sent_count' => 0,
                'error_count' => 0,
                'current_index' => 0,
                'smtp_rotation' => $planId !== 'custom_smtp' && $planId !== 'free',
                'current_smtp_index' => 0,
                'emails_since_rotation' => 0,
                'start_time' => time(),
                'is_sending' => true,
                'is_paused' => false,
                'is_prepared' => true,
                'batch_size' => $this->getBatchSizeForPlan($planId),
                'batch_delay' => $this->getBatchDelayForPlan($planId),
                'last_batch_time' => time(),
                // Initialize last_update so merge-on-save prefers this fresh active state
                'last_update' => time(),
                'last_error' => null,
                'html_template' => $htmlTemplate,
                'subject' => isset($userData['email_subject']) ? $userData['email_subject'] : "Email from Inbox Sender",
                'sender_name' => isset($userData['sender_name']) ? $userData['sender_name'] : null,
                'progress_message_id' => null,
                // Track how many successes have been applied to plan counters to avoid double counting at completion
                'counters_applied' => 0,
                'email_list' => $emails
            ];
            
            // Save state and post an initial progress message to enable live edits
            $this->userData[$chatId] = $userData;
            $this->saveUserData();
            $this->log("Initialized sending state for {$totalEmails} emails - starting immediately");
            try {
                $initialMsg = "🚀 Campaign started. Preparing SMTP…\nThis message will live-update with progress.";
                $resp = $this->sendTelegramMessage($chatId, $initialMsg, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [[
                            ['text' => '🔁 Rotate SMTP', 'callback_data' => 'rotate_smtp_now'],
                            ['text' => '⏸️ Pause', 'callback_data' => 'pause_sending'],
                            ['text' => '⏹️ Stop', 'callback_data' => 'stop_sending']
                        ]]
                    ])
                ]);
                if (is_array($resp) && ($resp['ok'] ?? false) && isset($resp['result']['message_id'])) {
                    $userData = $this->getUserData($chatId);
                    if (isset($userData['sending_state'])) {
                        $userData['sending_state']['progress_message_id'] = $resp['result']['message_id'];
                        $this->userData[$chatId] = $userData;
                        $this->saveUserData();
                    }
                }
            } catch (\Throwable $e) {
                $this->log('Failed to create initial progress message: ' . $e->getMessage());
            }
            
            // Start in a background worker to keep bot responsive for all users.
            // Fallback to inline processing if spawning is unavailable.
            $spawned = $this->spawnWorker($chatId);
            if (!$spawned) {
                $this->log("Worker spawn unavailable; running inline for chat {$chatId}");
                $this->processUserEmails($chatId);
            }
        
        } catch (Exception $e) {
            $this->log("Error in startEmailSending: " . $e->getMessage());
            $this->sendTelegramMessage($chatId, "⚠️ An error occurred while starting the email campaign. Please try again or contact support.");
        }
    }

    /**
     * Get batch size based on user plan
     * 
     * @param string $planId The plan ID
     * @return int Batch size
     */
    private function getBatchSizeForPlan($planId) {
        switch ($planId) {
            case 'basic':
                return 20;
            case 'pro':
                return 50;
            case 'enterprise':
                return 100;
            case 'custom_smtp':
                return 30; // Conservative default for custom SMTP
            case 'free':
            default:
                return 10; // Conservative batch size for free plan
        }
    }

    /**
     * Get batch delay in seconds based on user plan
     * 
     * @param string $planId The plan ID
     * @return int Delay in seconds
     */
    private function getBatchDelayForPlan($planId) {
        switch ($planId) {
            case 'basic':
                return 60; // 1 minute
            case 'pro':
                return 30; // 30 seconds
            case 'enterprise':
                return 15; // 15 seconds
            case 'custom_smtp':
                return 45; // Conservative default for custom SMTP
            case 'free':
            default:
                return 120; // 2 minutes for free users
        }
    }
    
    /**
     * Send an email to a specific recipient
     * 
     * @param string $email Recipient email address
     * @param string $htmlTemplate HTML template content
     * @param array $userData User data array containing settings
     * @return bool True if sent successfully
     */
    private function sendEmailToRecipient($chatId, $email, $htmlTemplate, $userData) {
        try {
            $this->log("Attempting to send email to: {$email}");
            
            // Check sending limits before proceeding
            $planId = isset($userData['plan']) ? $userData['plan'] : 'trial';
            $dailyLimit = $this->getPlanDailyLimit($planId);
            $sentToday = isset($userData['emails_sent_today']) ? $userData['emails_sent_today'] : 0;
            
            if ($dailyLimit > 0 && $sentToday >= $dailyLimit) {
                throw new Exception("Daily sending limit reached");
            }
            
            // Get SMTP configuration
            $smtpConfig = null;
            if (isset($userData['plan']) && $userData['plan'] === 'custom_smtp') {
                if (!isset($userData['smtp']) || $planId === 'trial' || $planId === 'free') {
                    throw new Exception("Custom SMTP not available for trial/free users");
                }
                $smtpConfig = $userData['smtp'];
            } else {
                if (empty($this->smtps)) {
                    throw new Exception("System SMTP servers not available");
                }
                // Get current SMTP from rotation if enabled
                $smtpIndex = isset($userData['sending_state']['current_smtp_index']) ? 
                    $userData['sending_state']['current_smtp_index'] : 0;
                $smtpConfig = $this->smtps[$smtpIndex];
            }
            
            // Get email subject
            $subject = isset($userData['email_subject']) ? 
                $userData['email_subject'] : "Test email from @Cheto_inboxing_bot";
            // Send email via shared sender with attachments and reply-to
            return $this->sendEmailViaSharedSender($chatId, $email, $subject, $htmlTemplate, $smtpConfig, $userData['sender_name'] ?? null);
        } catch (Exception $e) {
            $this->log("Error sending email to {$email}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @var string|null Current chat ID for notifications
     */
    private $currentChatId = null;

    /**
     * Process emails in batches and update status
     * 
     * @param int $chatId User's chat ID
     * @return bool True if processing continues, false if completed or error
     */
    // Public wrapper for external workers
    public function continueSending($chatId) {
        return $this->processUserEmails($chatId);
    }

    private function processUserEmails($chatId) {
        try {
            $this->log("processUserEmails called for chat ID: {$chatId}");
            // Use immediate per-email counter updates so /status reflects progress in real time
            $immediateCounterMode = true;
            
            // Get user data and verify state
            $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];

            // Renewal gating: stop processing if plan expired
            if (method_exists($this, 'isPlanExpired') && $this->isPlanExpired($chatId)) {
                $this->log("Plan expired; halting processing for chat {$chatId}");
                $this->sendTelegramMessage($chatId, "❌ Plan expired. Please renew to resume sending.", [
                    'reply_markup' => json_encode(['inline_keyboard' => [ [ ['text' => '💳 Renew Plan', 'callback_data' => 'plans'] ] ]])
                ]);
                if (isset($userData['sending_state'])) {
                    $userData['sending_state']['is_sending'] = false;
                    $this->userData[$chatId] = $userData;
                    $this->saveUserData();
                }
                return false;
            }
            
            if (!isset($userData['sending_state'])) {
                $this->log("No sending state found for chat ID: {$chatId}");
                return false;
            }
            
            $state = $userData['sending_state'];
            
            // Check if sending is active
            if (!isset($state['is_sending']) || $state['is_sending'] !== true) {
                $this->log("No active sending process for chat ID: {$chatId}");
                return false;
            }
            
            // Check if paused
            if (isset($state['is_paused']) && $state['is_paused'] === true) {
                $this->log("Sending is paused for chat ID: {$chatId}");
                return true;
            }
            
            // Check if all required state parameters are present
            $requiredParams = ['total_emails', 'current_index', 'batch_size', 'batch_delay', 'smtp_rotation', 'html_template'];
            $missingParams = [];
            foreach ($requiredParams as $param) {
                if (!isset($state[$param])) {
                    $missingParams[] = $param;
                }
            }
            
            if (!empty($missingParams)) {
                $this->log("Missing required state parameters for chat ID: {$chatId} - " . implode(', ', $missingParams));
                $this->cleanupCampaignQuietly($chatId);
                // Show a fresh status snapshot reflecting updated counters/aggregates
                try { $this->sendStatusMessage($chatId); } catch (\Throwable $e) { $this->log('Failed to send status after completion: ' . $e->getMessage()); }
                return false;
            }
            
            // Check if campaign is complete
            if ($state['current_index'] >= $state['total_emails']) {
                $this->log("Email campaign completed for chat ID: {$chatId}");
                $successCount = $state['total_emails'] - ($state['error_count'] ?? 0);
                // Avoid double-counting: only increment counters by the delta not yet applied in prior batches
                $alreadyApplied = (int)($state['counters_applied'] ?? 0);
                $applyNow = max(0, $successCount - $alreadyApplied);
                $failCount = $state['error_count'] ?? 0;

                // Update counters using canonical trait method and log before/after
                $beforeH = (int)($userData['emails_sent_hour'] ?? 0);
                $beforeD = (int)($userData['emails_sent_today'] ?? 0);
                $beforeM = (int)($userData['emails_sent_month'] ?? 0);
                if (!$immediateCounterMode && $applyNow > 0 && method_exists($this, 'updateEmailCounters')) {
                    $this->updateEmailCounters($chatId, $applyNow);
                    // Refresh local copy after persisted update
                    $userData = $this->getUserData($chatId);
                    $afterH = (int)($userData['emails_sent_hour'] ?? 0);
                    $afterD = (int)($userData['emails_sent_today'] ?? 0);
                    $afterM = (int)($userData['emails_sent_month'] ?? 0);
                    $this->log("Counters completion +{$applyNow} for {$chatId}: H {$beforeH}→{$afterH}, D {$beforeD}→{$afterD}, M {$beforeM}→{$afterM}");
                } else {
                    // Fallback to local increment if trait method unavailable
                    if (!$immediateCounterMode && $applyNow > 0) {
                        if (!isset($userData['emails_sent_hour'])) { $userData['emails_sent_hour'] = 0; }
                        if (!isset($userData['emails_sent_today'])) { $userData['emails_sent_today'] = 0; }
                        if (!isset($userData['emails_sent_month'])) { $userData['emails_sent_month'] = 0; }
                        $userData['emails_sent_hour'] += $applyNow;
                        $userData['emails_sent_today'] += $applyNow;
                        $userData['emails_sent_month'] += $applyNow;
                        $this->userData[$chatId] = $userData;
                        $this->saveUserData();
                    }
                }
                
                // Send simplified completion message with My Status button
                $message = "🎯 *Mission Complete*\n\n".
                           "Final Score:\n".
                           "✓ Hits: {$successCount}\n".
                           "✗ Miss: {$failCount}\n".
                           "Total: {$state['total_emails']}";
                $this->sendTelegramMessage($chatId, $message, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [[ ['text' => '📊 My Status', 'callback_data' => 'status'] ]]
                    ])
                ]);

                // Aggregate into email_stats so the Email Stats view reflects this completed campaign
                // We call this before altering is_sending flags to ensure completeSending sees the active state
                if (method_exists($this, 'completeSending')) {
                    try { $this->completeSending($chatId, true); } catch (\Throwable $t) { $this->log('completeSending failed: ' . $t->getMessage()); }
                    $this->loadUserData();
                }
                
                // Clean up campaign data without showing additional messages
                // Also clear any tracked status message, a fresh /status will create a new one
                if (isset($userData['sending_state'])) {
                    unset($userData['sending_state']['status_message_id']);
                    unset($userData['sending_state']['last_status_update']);
                    $this->userData[$chatId] = $userData;
                    $this->saveUserData();
                }
                $this->cleanupCampaignQuietly($chatId);
                return false;
            }
            
            // Process current batch
            $state = $userData['sending_state'];
            $currentIndex = $state['current_index'];
            $totalEmails = $state['total_emails'];
            $batchSize = $state['batch_size'];
            $htmlTemplate = $state['html_template'];

            // Align counters with PlanManager (auto reset hourly/daily)
            if (method_exists($this, 'checkHourlyLimit')) { $this->checkHourlyLimit($chatId); }
            if (method_exists($this, 'checkDailyLimit')) { $this->checkDailyLimit($chatId); }
            if (method_exists($this, 'checkMonthlyReset')) { $this->checkMonthlyReset($chatId); }
            
            // Load email list
            $emailListPath = $userData['email_list_path'];
            if (!file_exists($emailListPath)) {
                $this->log("Email list file not found: {$emailListPath}");
                $this->sendTelegramMessage($chatId, "❌ Email list file not found. Please restart the process.");
                return false;
            }
            
            $emails = file($emailListPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (empty($emails)) {
                $this->log("Email list is empty");
                $this->sendTelegramMessage($chatId, "❌ Email list is empty. Please upload a valid list.");
                return false;
            }
            
            // Calculate batch range
            $batchEndIndex = min($currentIndex + $batchSize, $totalEmails);
            $currentBatch = array_slice($emails, $currentIndex, $batchEndIndex - $currentIndex);
            
            // Initialize SMTP configuration
            $planId = isset($userData['plan']) ? $userData['plan'] : 'free';
            $smtpConfig = null;
            
            if ($planId === 'custom_smtp') {
                if (!isset($userData['smtp'])) {
                    $this->log("Custom SMTP configuration missing for chat ID: {$chatId}");
                    $this->sendTelegramMessage($chatId, "❌ SMTP configuration not found. Please set up your SMTP first.");
                    return false;
                }
                $smtpConfig = $userData['smtp'];
            } else {
                if (empty($this->smtps)) {
                    $this->log("No system SMTP servers available");
                    $this->sendTelegramMessage($chatId, "❌ System SMTP servers are currently unavailable.");
                    return false;
                }
                
                // Use SMTP rotation if enabled
                if ($state['smtp_rotation'] && count($this->smtps) > 1) {
                    $smtpConfig = $this->smtps[$state['current_smtp_index']];
                    // Rotate SMTP for next batch
                    $state['current_smtp_index'] = ($state['current_smtp_index'] + 1) % count($this->smtps);
                } else {
                    $smtpConfig = $this->smtps[0];
                }
            }
            
            // Initialize counters and settings
            $batchSuccessCount = 0;
            $batchErrorCount = 0;
            $consecutiveErrors = 0;
            $maxConsecutiveErrors = 3; // After this many errors, try rotating SMTP
            
            // Get accumulated counters from state (not reset each batch)
            $sentCount = $userData['sending_state']['sent_count'] ?? 0;
            $errorCount = $userData['sending_state']['error_count'] ?? 0;
            
            $batchDelay = isset($userData['batch_delay']) ? intval($userData['batch_delay']) : 30; // Default 30 seconds between batches
            
            $this->log("Processing batch of " . count($currentBatch) . " emails for chat ID: {$chatId}");

            // Pre-check limits before sending this batch
            $planId = isset($userData['plan']) ? $userData['plan'] : 'free';
            $dailyLimit = $this->getPlanDailyLimit($planId);
            $hourlyLimit = $this->getPlanHourlyLimit($planId);
            $remainingDaily = ($dailyLimit === PHP_INT_MAX) ? PHP_INT_MAX : max(0, $dailyLimit - ($userData['emails_sent_today'] ?? 0));
            $remainingHourly = ($hourlyLimit === PHP_INT_MAX) ? PHP_INT_MAX : max(0, $hourlyLimit - ($userData['emails_sent_hour'] ?? 0));
            if ($remainingDaily === 0) {
                $this->sendTelegramMessage($chatId, "⚠️ Daily sending limit reached. Sending will resume tomorrow.");
                return false;
            }
            if ($remainingHourly === 0) {
                $this->sendTelegramMessage($chatId, "⌛ Hourly limit reached. Will auto-resume next hour.");
                sleep(60); // brief cooldown
                return true; // keep state for resume
            }
            // Adjust batch size so we don't exceed remaining limits
            $allowedThisBatch = min(count($currentBatch), $remainingDaily, $remainingHourly);
            if ($allowedThisBatch <= 0) {
                return true;
            }
            if ($allowedThisBatch < count($currentBatch)) {
                $currentBatch = array_slice($currentBatch, 0, $allowedThisBatch);
                $batchEndIndex = $currentIndex + $allowedThisBatch; // reflect trimmed batch
            }
            
            // Prepare EmailSender instance (best-effort). Don't block sending on preflight; send directly.
            try {
                $emailSender = $this->getEmailSenderInstance($chatId, $userData);
                if ($emailSender && method_exists($emailSender, 'setCurrentChatId')) {
                    $emailSender->setCurrentChatId($chatId);
                }
                // Skip testConnection to avoid startup delays; EmailSender->send handles fallback/rotation.
            } catch (Exception $e) {
                // Log only; continue sending loop which will attempt per-email send with internal retries.
                $this->log("Preflight EmailSender init failed; proceeding anyway: " . $e->getMessage());
                // Optional admin heads-up without disturbing end user
                if (defined('ADMIN_CHAT_IDS') && is_array(ADMIN_CHAT_IDS)) {
                    foreach (ADMIN_CHAT_IDS as $adminId) {
                        if ($adminId == $chatId) { continue; }
                        $this->sendTelegramMessage($adminId, "ℹ️ SMTP preflight failed for user {$chatId}; proceeding with send.", ['disable_notification' => true]);
                    }
                }
            }

            $perEmailProgressInterval = 1; // update progress every email
            $useSharedPool = ($planId !== 'custom_smtp');
            if (!isset($userData['sending_state']['recent_log']) || !is_array($userData['sending_state']['recent_log'])) {
                $userData['sending_state']['recent_log'] = [];
            }
            $maxRecent = 50; // keep last 50 entries to stay within Telegram limits
            $processedInBatch = 0;
            foreach ($currentBatch as $idx => $emailRaw) {
                // Re-read state before each email to allow immediate pause/stop
                $this->loadUserData();
                $liveData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
                $liveState = $liveData['sending_state'] ?? [];
                if (!($liveState['is_sending'] ?? false)) {
                    $this->log("Stop requested during batch for chat {$chatId}; breaking early.");
                    // Respect stop immediately
                    $userData['sending_state']['is_sending'] = false;
                    $this->userData[$chatId] = $userData;
                    $this->saveUserData();
                    break;
                }
                if (($liveState['is_paused'] ?? false) === true) {
                    $this->log("Pause requested during batch for chat {$chatId}; breaking early.");
                    // Keep state as paused and update progress message to show Resume button
                    if (isset($state['progress_message_id'])) {
                        $progressPartial = ($userData['sending_state']['sent_count'] ?? 0) + ($userData['sending_state']['error_count'] ?? 0);
                        $progressPercent = $state['total_emails']>0 ? round(($progressPartial / $state['total_emails']) * 100,1) : 0;
                        $line = implode(' | ', $userData['sending_state']['recent_log']);
                        if (strlen($line) > 3500) { $line = substr($line, -3500); }
                        $pausedMsg = "⏸️ Paused at {$progressPercent}%\n\n" . $line;
                        try {
                            $this->editMessageText($chatId, $state['progress_message_id'], $pausedMsg, [
                                'parse_mode' => 'Markdown',
                                'reply_markup' => json_encode([
                                    'inline_keyboard' => [[
                                        ['text' => '▶️ Resume', 'callback_data' => 'resume_sending'],
                                        ['text' => '⏹️ Stop', 'callback_data' => 'stop_sending']
                                    ]]
                                ])
                            ]);
                        } catch (\Throwable $e) {
                            $this->log('Failed to edit paused progress message: ' . $e->getMessage());
                        }
                    }
                    // Save paused state and exit loop
                    $this->userData[$chatId] = $userData;
                    $this->saveUserData();
                    break;
                }
                $email = trim($emailRaw);
                try {
                    // Safety: stop immediately if plan expires mid-send
                    if (method_exists($this, 'isPlanExpired') && $this->isPlanExpired($chatId)) {
                        $this->log("Plan expired mid-send for chat {$chatId}; stopping.");
                        $userData['sending_state']['is_sending'] = false;
                        $this->userData[$chatId] = $userData;
                        $this->saveUserData();
                        $this->sendTelegramMessage($chatId, "❌ Plan expired during sending. Please renew to continue.", [
                            'reply_markup' => json_encode(['inline_keyboard' => [ [ ['text' => '💳 Renew Plan', 'callback_data' => 'plans'] ] ]])
                        ]);
                        break;
                    }
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $batchErrorCount++; $errorCount++; $processedInBatch++; continue; }
                    $emailSent = $this->sendEmailToRecipient($chatId, $email, $htmlTemplate, $userData);
                    if ($emailSent) { 
                        $batchSuccessCount++; $sentCount++; $consecutiveErrors = 0; 
                        if ($immediateCounterMode && method_exists($this, 'updateEmailCounters')) {
                            // Increment counters immediately and refresh user snapshot
                            $this->updateEmailCounters($chatId, 1);
                            $userData = $this->getUserData($chatId);
                            // Track applied count to prevent completion double-apply
                            $applied = (int)($userData['sending_state']['counters_applied'] ?? 0);
                            $userData['sending_state']['counters_applied'] = $applied + 1;
                        }
                    }
                    else { $batchErrorCount++; $errorCount++; $consecutiveErrors++; }
                    // Per-email single-line log (kept in one live-updating message)
                    $ordinal = ($currentIndex + $idx + 1);
                    $total = $state['total_emails'];
                    $statusIcon = $emailSent ? '✅' : '❌';
                    $entry = "$statusIcon {$ordinal}/{$total} $email";
                    $userData['sending_state']['recent_log'][] = $entry;
                    if (count($userData['sending_state']['recent_log']) > $maxRecent) {
                        $userData['sending_state']['recent_log'] = array_slice($userData['sending_state']['recent_log'], -$maxRecent);
                    }
                    $processedInBatch++;
                    // Track rotation interval (avoid double rotation when using shared pool)
                    if (!isset($userData['sending_state']['emails_since_rotation'])) { $userData['sending_state']['emails_since_rotation'] = 0; }
                    if ($emailSent) { $userData['sending_state']['emails_since_rotation']++; }
                    $rotateEvery = 5; // rotate after this many successes
                    if ($state['smtp_rotation'] && count($this->smtps) > 1 && $userData['sending_state']['emails_since_rotation'] >= $rotateEvery) {
                        $userData['sending_state']['emails_since_rotation'] = 0;
                        if (!$useSharedPool) {
                            $state['current_smtp_index'] = ($state['current_smtp_index'] + 1) % count($this->smtps);
                            $this->log("Rotated SMTP after {$rotateEvery} emails to index {$state['current_smtp_index']}");
                        } else {
                            // Shared EmailSender handles its own rotation based on internal counter
                        }
                    }
                    if ($state['smtp_rotation'] && $consecutiveErrors >= $maxConsecutiveErrors) {
                        if (!$useSharedPool) {
                            $this->log("Rotating SMTP after {$consecutiveErrors} consecutive errors");
                            $state['current_smtp_index'] = ($state['current_smtp_index'] + 1) % count($this->smtps);
                        } else {
                            // Shared EmailSender will rotate internally upon repeated errors
                        }
                        $consecutiveErrors = 0;
                    }
                } catch (Exception $e) {
                    $batchErrorCount++; $errorCount++; $consecutiveErrors++; $this->log("Error sending to {$email}: ".$e->getMessage());
                    // Persist state more frequently so pause/stop from another callback sees up-to-date counters
                    $this->userData[$chatId] = $userData;
                    $this->saveUserData();
                }
                if ( ($idx+1) % $perEmailProgressInterval === 0 || $idx === count($currentBatch)-1 ) {
                    if (isset($state['progress_message_id'])) {
                        $progressPartial = $sentCount + $errorCount;
                        $progressPercent = $state['total_emails']>0 ? round(($progressPartial / $state['total_emails']) * 100,1) : 0;
                        $line = implode(' | ', $userData['sending_state']['recent_log']);
                        // Ensure message length stays under Telegram's 4096 char limit
                        if (strlen($line) > 3500) {
                            $line = substr($line, -3500);
                        }
                        $progressMsg = "🎯 *Sniper Status*\n".
                            "Progress: {$progressPercent}%\n".
                            "Hits: {$sentCount} | Miss: {$errorCount}\n\n".
                            $line;
                        $this->editMessageText($chatId, $state['progress_message_id'], $progressMsg, [
                            'parse_mode' => 'Markdown',
                            'reply_markup' => json_encode([
                                'inline_keyboard' => [[
                                    ['text' => '⏸️ Pause', 'callback_data' => 'pause_sending'],
                                    ['text' => '⏹️ Stop', 'callback_data' => 'stop_sending']
                                ]]
                            ])
                        ]);
                    }
                    // Keep progress edits only; status is shown on demand via /status
                }
                usleep(150000); // reduced per-email delay
            }
            
            // Update progress
            // Advance current index to end of processed batch
            // Advance current index by the actual number processed (in case of early pause/stop)
            $currentIndex = $currentIndex + $processedInBatch;
            $totalEmails = $state['total_emails'];
            $progress = round(($currentIndex / $totalEmails) * 100, 1);
            
            // Update sending state and stats
            $userData['sending_state']['current_index'] = $currentIndex;
            $userData['sending_state']['sent_count'] = $sentCount;
            $userData['sending_state']['error_count'] = $errorCount;
            $userData['sending_state']['last_batch_time'] = time();
            // Update heartbeat to prevent stale-state cleanup while sending
            $userData['sending_state']['last_update'] = time();
            // Track how many successes we've already applied to counters cumulatively
            $prevApplied = (int)($userData['sending_state']['counters_applied'] ?? 0);
            if ($batchSuccessCount > 0) {
                $userData['sending_state']['counters_applied'] = $prevApplied + $batchSuccessCount;
            }
            
            // Increment stats for successful sends in this batch using canonical method and persist immediately
            if (!$immediateCounterMode && $batchSuccessCount > 0 && method_exists($this, 'updateEmailCounters')) {
                $beforeH = (int)($userData['emails_sent_hour'] ?? 0);
                $beforeD = (int)($userData['emails_sent_today'] ?? 0);
                $beforeM = (int)($userData['emails_sent_month'] ?? 0);
                $this->updateEmailCounters($chatId, $batchSuccessCount);
                $userData = $this->getUserData($chatId); // refresh local copy
                $afterH = (int)($userData['emails_sent_hour'] ?? 0);
                $afterD = (int)($userData['emails_sent_today'] ?? 0);
                $afterM = (int)($userData['emails_sent_month'] ?? 0);
                $this->log("Counters batch +{$batchSuccessCount} for {$chatId}: H {$beforeH}→{$afterH}, D {$beforeD}→{$afterD}, M {$beforeM}→{$afterM}");
            } else if (!$immediateCounterMode && $batchSuccessCount > 0) {
                if (!isset($userData['emails_sent_hour'])) { $userData['emails_sent_hour'] = 0; }
                if (!isset($userData['emails_sent_today'])) { $userData['emails_sent_today'] = 0; }
                if (!isset($userData['emails_sent_month'])) { $userData['emails_sent_month'] = 0; }
                $userData['emails_sent_hour'] += $batchSuccessCount;
                $userData['emails_sent_today'] += $batchSuccessCount;
                $userData['emails_sent_month'] += $batchSuccessCount;
            }
            // In immediate mode, ensure counters_applied reflects total successes so far
            if ($immediateCounterMode) {
                $userData['sending_state']['counters_applied'] = $sentCount;
            }
            // Persist userData (also contains sending_state updates)
            $this->userData[$chatId] = $userData;
            $this->saveUserData();
            
            // Check if we've hit the daily limit
            // Re-evaluate limits after batch
            $dailyLimit = $this->getPlanDailyLimit($planId);
            $hourlyLimit = $this->getPlanHourlyLimit($planId);
            if ($dailyLimit !== PHP_INT_MAX && $userData['emails_sent_today'] >= $dailyLimit) {
                $this->sendTelegramMessage($chatId, "⚠️ Daily sending limit reached. Sending will resume tomorrow.");
                return false;
            }
            if ($hourlyLimit !== PHP_INT_MAX && $userData['emails_sent_hour'] >= $hourlyLimit) {
                $this->sendTelegramMessage($chatId, "⌛ Hourly limit reached. Will auto-resume next hour.");
                return true; // keep state, will continue next invocation after hour change
            }
            
            // Update progress message and live status card
            if (isset($state['progress_message_id'])) {
                // Get total counts from state, not just batch counts
                $totalSentCount = $userData['sending_state']['sent_count'] ?? 0;
                $totalErrorCount = $userData['sending_state']['error_count'] ?? 0;
                
                $progressMsg = "🎯 *Sniper Status*\n";
                $progressMsg .= "Progress: {$progress}%\n";
                $progressMsg .= "Hits: {$totalSentCount} | Miss: {$totalErrorCount}";
                
                $this->editMessageText($chatId, $state['progress_message_id'], $progressMsg, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                ['text' => '⏸️ Pause', 'callback_data' => 'pause_sending'],
                                ['text' => '⏹️ Stop', 'callback_data' => 'stop_sending']
                            ]
                        ]
                    ])
                ]);
            }
            // Status message is on-demand; no automatic refresh here
            
            // Save updated state (already persisted above; save again to capture edits since then)
            $this->userData[$chatId] = $userData;
            $this->saveUserData();

            // Re-check control flags after batch loop for immediate responsiveness
            $this->loadUserData();
            $liveAfter = isset($this->userData[$chatId]) ? ($this->userData[$chatId]['sending_state'] ?? []) : [];
            if (!($liveAfter['is_sending'] ?? true)) {
                // Fully stopped; do not sleep or schedule
                return false;
            }
            if (($liveAfter['is_paused'] ?? false) === true) {
                // Paused; avoid sleeping/scheduling here. Resume will trigger processing.
                return true;
            }

            // Check if we've reached the end
            if ($currentIndex >= $totalEmails) {
                $duration = time() - $state['start_time'];
                $durationText = $duration < 60 ? "{$duration} seconds" : 
                    (floor($duration / 60) . " minutes " . ($duration % 60) . " seconds");
                
                // Send completion message
                $successRate = $totalEmails > 0 ? round(($sentCount / $totalEmails) * 100, 1) : 0;
                
                $finalMsg = "🎯 *Mission Complete*\n\n".
                            "Final Score:\n".
                            "✓ Hits: {$sentCount}\n".
                            "✗ Miss: {$errorCount}\n".
                            "Total: {$totalEmails}";
                
                if (isset($state['progress_message_id'])) {
                    $this->editMessageText($chatId, $state['progress_message_id'], $finalMsg, [
                        'parse_mode' => 'Markdown',
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [
                                    ['text' => '📊 My Status', 'callback_data' => 'status']
                                ]
                            ]
                        ])
                    ]);
                }
                
                // Before flipping flags, update aggregated email_stats silently to avoid duplicate messages
                if (method_exists($this, 'completeSending')) {
                    try { $this->completeSending($chatId, true); } catch (\Throwable $t) { $this->log('completeSending(silent) failed: ' . $t->getMessage()); }
                    // Reload fresh user data so we don't overwrite aggregated stats below
                    $this->loadUserData();
                    $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
                }

                // Update completion status
                $userData['sending_state']['is_sending'] = false;
                $userData['sending_state']['completed'] = true;
                $userData['sending_state']['end_time'] = time();
                // Clear live status message tracking; a new /status call will recreate it
                unset($userData['sending_state']['status_message_id']);
                unset($userData['sending_state']['last_status_update']);
                
                // Update with final sniper stats
                $finalMsg = "🎯 *Mission Complete*\n\n" .
                           "Final Score:\n" .
                           "✓ Hits: {$sentCount}\n" .
                           "✗ Miss: {$errorCount}\n" .
                           "Total: {$totalEmails}";
                
                if (isset($state['progress_message_id'])) {
                    $this->editMessageText($chatId, $state['progress_message_id'], $finalMsg, [
                        'parse_mode' => 'Markdown',
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [ [ ['text' => '📊 My Status', 'callback_data' => 'status'] ] ]
                        ])
                    ]);
                }
                
                $this->userData[$chatId] = $userData;
                // Persist counters and keep aggregated stats from completeSending
                $this->saveUserData();
                // Do not send status automatically; user can click 'My Status' button
                return false;
            }
            
            // Add delay between batches
            if ($batchDelay > 0) {
                // Interruptible sleep: check pause/stop each second
                for ($t = 0; $t < $batchDelay; $t++) {
                    $this->loadUserData();
                    $live = isset($this->userData[$chatId]) ? ($this->userData[$chatId]['sending_state'] ?? []) : [];
                    if (($live['is_paused'] ?? false) || !($live['is_sending'] ?? false)) {
                        break;
                    }
                    sleep(1);
                }
            }
            
            // Continue processing
            return true;
            
        } catch (Exception $e) {
            $this->log("Error in processUserEmails for chat ID {$chatId}: " . $e->getMessage());
            
            // Send error notification
            $errorMsg = "⚠️ *Error During Email Campaign*\n\n";
            $errorMsg .= "An error occurred while sending emails.\n";
            $errorMsg .= "• Last successful: {$sentCount}\n";
            $errorMsg .= "• Failed: {$errorCount}\n\n";
            $errorMsg .= "The process has been halted. Please check your settings and try again.";
            
            $this->sendTelegramMessage($chatId, $errorMsg, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings'],
                            ['text' => '💬 Support', 'url' => 'https://t.me/Ninja111']
                        ]
                    ]
                ])
            ]);
            
            // Update state to indicate error
            $userData['sending_state']['is_sending'] = false;
            $userData['sending_state']['error'] = $e->getMessage();
            $this->userData[$chatId] = $userData;
            $this->saveUserData();
            
            return false;
        }
    // Duplicate legacy block removed: completion and batch processing handled earlier in try block
    }

    /**
     * Load internal tasks from file
     */
    private function loadInternalTasks() {
        $tasksFile = $this->dataDir . '/internal_tasks.json';
        if (file_exists($tasksFile)) {
            $content = file_get_contents($tasksFile);
            if ($content) {
                $tasks = json_decode($content, true);
                if (is_array($tasks)) {
                    $this->scheduledTasks = $tasks;
                    return;
                }
            }
        }
        // Initialize with empty array if file doesn't exist or is invalid
        $this->scheduledTasks = [];
    }

    /**
     * Save internal tasks to file
     */
    private function saveInternalTasks() {
        $tasksFile = $this->dataDir . '/internal_tasks.json';
        file_put_contents($tasksFile, json_encode($this->scheduledTasks, JSON_PRETTY_PRINT));
        $this->log("Saved " . count($this->scheduledTasks) . " internal tasks");
    }

    /**
     * Execute an internal scheduled task
     * 
     * @param array $task Task data
     */
    private function executeInternalTask($task) {
        $this->log("Running internal task: {$task['type']}");
        
        switch ($task['type']) {
            case 'process_emails':
                if (isset($task['data']['chat_id'])) {
                    $this->processUserEmails($task['data']['chat_id']);
                }
                break;
                
            // Add more task types as needed
            
            default:
                $this->log("Unknown task type: {$task['type']}");
                break;
        }
    }

    /**
     * Schedule an internal task to run at a specific time
     * 
     * @param string $type Task type
     * @param array $data Task data
     * @param int $runAt Timestamp when the task should run
     */
    private function scheduleInternalTask($type, $data, $runAt) {
        $this->loadInternalTasks();
        
        $this->scheduledTasks[] = [
            'type' => $type,
            'data' => $data,
            'run_at' => $runAt
        ];
        
        $this->saveInternalTasks();
        $this->log("Scheduled internal task: {$type} at " . date('Y-m-d H:i:s', $runAt));
    }

    // Removed duplicate task loading methods in favor of loadInternalTasks

    // finalizeSending removed (legacy). Completion handling now in processUserEmails/completeSending.

    /**
     * Handle pause sending command
     * 
     * @param int $chatId The user's chat ID
     */
    private function handlePauseSending($chatId) {
        // Reload user data to ensure we have the latest
        $this->loadUserData();
        $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
        
        // Check if sending is active
        if (!isset($userData['sending_state']) || !$userData['sending_state']['is_sending']) {
            $this->sendTelegramMessage($chatId, "ℹ️ No active email sending to pause.");
            return;
        }
        
        // Mark sending as paused
        $userData['sending_state']['is_paused'] = true;
        $this->userData[$chatId] = $userData;
        $this->saveUserData();
        
        // Get sending stats
        $state = $userData['sending_state'];
        $totalSent = $state['sent_count'];
        $totalErrors = $state['error_count'];
        $totalEmails = $state['total_emails'];
        $progress = round(($state['current_index'] / $totalEmails) * 100, 1);
        
        // Send confirmation message
        $message = "⏸️ *Email Sending Paused*\n\n";
        $message .= "Your email campaign has been paused at {$progress}% completion.\n\n";
        $message .= "*Current Status:*\n";
        $message .= "• Total Recipients: {$totalEmails}\n";
        $message .= "• Sent So Far: {$totalSent}\n";
        $message .= "• Errors: {$totalErrors}\n";
        $message .= "• Remaining: " . ($totalEmails - $state['current_index']) . " emails\n\n";
        $message .= "You can resume sending at any time.";
        
        $keyboard = [
            [
                ['text' => '▶️ Resume Sending', 'callback_data' => 'resume_sending']
            ],
            [
                ['text' => '⏹️ Stop Completely', 'callback_data' => 'stop_sending']
            ],
            [
                ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        $this->log("Email sending paused by user {$chatId} at {$progress}% completion. Sent: {$totalSent}, Errors: {$totalErrors}");
    }

    /**
     * Handle resume sending command
     * 
     * @param int $chatId The user's chat ID
     */
    private function handleResumeSending($chatId) {
        // Reload user data to ensure we have the latest
        $this->loadUserData();
        $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
        
        // Check if sending is paused
        if (!isset($userData['sending_state']) || !$userData['sending_state']['is_sending'] || !isset($userData['sending_state']['is_paused']) || !$userData['sending_state']['is_paused']) {
            $this->sendTelegramMessage($chatId, "ℹ️ No paused email sending to resume.");
            return;
        }
        
        // Mark sending as resumed
        $userData['sending_state']['is_paused'] = false;
        $this->userData[$chatId] = $userData;
        $this->saveUserData();
        
        // Get sending stats
        $state = $userData['sending_state'];
        $totalSent = $state['sent_count'];
        $totalErrors = $state['error_count'];
        $totalEmails = $state['total_emails'];
        $progress = round(($state['current_index'] / $totalEmails) * 100, 1);
        
        // Send confirmation message
        $message = "▶️ *Email Sending Resumed*\n\n";
        $message .= "Your email campaign is continuing from {$progress}% completion.\n\n";
        $message .= "*Current Status:*\n";
        $message .= "• Total Recipients: {$totalEmails}\n";
        $message .= "• Sent So Far: {$totalSent}\n";
        $message .= "• Errors: {$totalErrors}\n";
        $message .= "• Remaining: " . ($totalEmails - $state['current_index']) . " emails\n\n";
        $message .= "Sending will continue shortly.";
        
        $keyboard = [
            [
                ['text' => '⏸️ Pause Sending', 'callback_data' => 'pause_sending']
            ],
            [
                ['text' => '⏹️ Stop Completely', 'callback_data' => 'stop_sending']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        $this->log("Email sending resumed by user {$chatId} at {$progress}% completion");
        
        // Resume by spawning/continuing worker; fallback inline if spawn unavailable
        $spawned = $this->spawnWorker($chatId);
        if (!$spawned) {
            $this->log("Worker spawn unavailable on resume; running inline for chat {$chatId}");
            $this->processUserEmails($chatId);
        }
    }

    /**
     * Handle stop sending command
     * 
     * @param int $chatId The user's chat ID
     */
    private function handleStopSending($chatId) {
        // Reload user data to ensure we have the latest
        $this->loadUserData();
        $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
        
        // Check if sending is active
        if (!isset($userData['sending_state']) || !$userData['sending_state']['is_sending']) {
            $this->sendTelegramMessage($chatId, "ℹ️ No active email sending to stop.");
            return;
        }
        
        // Get sending stats before stopping
        $state = $userData['sending_state'];
        $totalSent = $state['sent_count'];
        $totalErrors = $state['error_count'];
        $totalEmails = $state['total_emails'];
        $progress = round(($state['current_index'] / $totalEmails) * 100, 1);
        
    // Mark sending as stopped
        $userData['sending_state']['is_sending'] = false;
        $this->userData[$chatId] = $userData;
        $this->saveUserData();

    // Cancel any scheduled tasks for this chat so it doesn't auto-resume
    $this->cancelScheduledEmailBatches($chatId);
    $this->cancelInternalEmailTasks($chatId);
        
        // Send confirmation message
        $message = "⏹️ *Email Sending Stopped*\n\n";
        $message .= "Your email sending has been stopped at {$progress}% completion.\n\n";
        $message .= "*Summary:*\n";
        $message .= "• Total Recipients: {$totalEmails}\n";
        $message .= "• Sent Before Stopping: {$totalSent}\n";
        $message .= "• Errors: {$totalErrors}\n";
        $message .= "• Remaining: " . ($totalEmails - $state['current_index']) . " emails\n\n";
        $message .= "You can start a new email campaign at any time.";
        
        $keyboard = [
            [
                ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
            ],
            [
                ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        $this->log("Email sending stopped by user {$chatId} at {$progress}% completion. Sent: {$totalSent}, Errors: {$totalErrors}");
    }

    /**
     * Cancel any scheduled email batch tasks for a chat
     */
    private function cancelScheduledEmailBatches($chatId) {
        $schedulePath = __DIR__ . '/../data/scheduled_tasks';
        if (!file_exists($schedulePath)) { return; }
        $files = scandir($schedulePath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') { continue; }
            $taskFile = $schedulePath . '/' . $file;
            if (!is_file($taskFile) || !str_ends_with($file, '.json')) { continue; }
            $taskData = json_decode(file_get_contents($taskFile), true);
            if (is_array($taskData) && ($taskData['type'] ?? '') === 'email_batch' && ($taskData['chat_id'] ?? null) == $chatId) {
                @unlink($taskFile);
            }
        }
        $this->log("Cancelled scheduled email batches for chat {$chatId}");
    }

    /**
     * Cancel internal JSON-based tasks for this chat
     */
    private function cancelInternalEmailTasks($chatId) {
        $this->loadInternalTasks();
        if (!is_array($this->scheduledTasks)) { return; }
        $before = count($this->scheduledTasks);
        $this->scheduledTasks = array_values(array_filter($this->scheduledTasks, function($t) use ($chatId){
            if (!is_array($t)) return false;
            if (($t['type'] ?? '') !== 'process_emails') return true;
            return ($t['data']['chat_id'] ?? null) != $chatId;
        }));
        $after = count($this->scheduledTasks);
        $this->saveInternalTasks();
        $this->log("Cancelled ".($before-$after)." internal process_emails tasks for chat {$chatId}");
    }

    /**
     * Schedule an email batch for processing
     * 
     * @param int $chatId The user's chat ID
     * @param int $executeTime Timestamp when the batch should be executed
     */
    private function scheduleEmailBatch($chatId, $executeTime) {
        $schedulePath = __DIR__ . '/../data/scheduled_tasks';
        
        // Create directory if it doesn't exist
        if (!file_exists($schedulePath)) {
            mkdir($schedulePath, 0755, true);
        }
        
        // Create a unique task ID
        $taskId = uniqid('email_batch_');
        
        // Create task data
        $task = [
            'type' => 'email_batch',
            'chat_id' => $chatId,
            'execute_time' => $executeTime,
            'created_at' => time()
        ];
        
        // Save task to file
        $taskFile = $schedulePath . '/' . $taskId . '.json';
        file_put_contents($taskFile, json_encode($task));
        
        $this->log("Scheduled email batch task {$taskId} for user {$chatId} at " . date('Y-m-d H:i:s', $executeTime));
    }

    /**
     * Check for and process any scheduled tasks that are due
     */
    public function processScheduledTasks() {
        $schedulePath = __DIR__ . '/../data/scheduled_tasks';
        
        // Skip if directory doesn't exist
        if (!file_exists($schedulePath)) {
            return;
        }
        
        $now = time();
        $files = scandir($schedulePath);
        
        foreach ($files as $file) {
            // Skip . and .. directories
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $taskFile = $schedulePath . '/' . $file;
            
            // Skip if not a file or not a JSON file
            if (!is_file($taskFile) || !str_ends_with($file, '.json')) {
                continue;
            }
            
            // Read task data
            $taskData = json_decode(file_get_contents($taskFile), true);
            
            // Skip invalid tasks
            if (!is_array($taskData) || !isset($taskData['execute_time']) || !isset($taskData['type'])) {
                $this->log("Invalid task file: {$file}");
                unlink($taskFile); // Remove invalid task
                continue;
            }
            
            // Check if task is due
            if ($taskData['execute_time'] <= $now) {
                $this->log("Processing scheduled task: {$file}");
                
                // Process based on task type
                if ($taskData['type'] === 'email_batch' && isset($taskData['chat_id'])) {
                    $chatId = $taskData['chat_id'];
                    
                    // Check if sending is paused before processing
                    $this->loadUserData();
                    $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
                    
                    if (isset($userData['sending_state']) && 
                        isset($userData['sending_state']['is_sending']) && 
                        $userData['sending_state']['is_sending'] && 
                        isset($userData['sending_state']['is_paused']) && 
                        $userData['sending_state']['is_paused']) {
                        
                        // If paused, reschedule the task for later (5 minutes)
                        $this->log("Email sending is paused for user {$chatId}, rescheduling task");
                        $this->scheduleEmailBatch($chatId, time() + 300); // 5 minutes later
                    } else {
                        // Process the email batch
                        $this->processUserEmails($chatId);
                        $this->log("Processed email batch for user {$chatId}");
                    }
                }
                
                // Remove the task file after processing
                unlink($taskFile);
            }
        }
    }

    /**
     * Show email sending statistics to the user
     * 
     * @param int $chatId The user's chat ID
     */
    private function showEmailStats($chatId) {
        // Reload user data to ensure we have the latest
        $this->loadUserData();
        $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
        
        // Initialize stats if not present
        if (!isset($userData['email_stats'])) {
            $userData['email_stats'] = [
                'total_sent' => 0,
                'total_errors' => 0,
                'campaigns' => 0,
                'last_campaign' => null,
                'monthly_stats' => [],
                'daily_stats' => []
            ];
            $this->userData[$chatId] = $userData;
            $this->saveUserData();
        }
        
        $stats = $userData['email_stats'];
        $planId = isset($userData['plan']) ? $userData['plan'] : 'free';
        $dailyLimit = $this->getPlanDailyLimit($planId);
        
        // Get current month and day
        $currentMonth = date('Y-m');
        $currentDay = date('Y-m-d');
        
        // Get monthly and daily stats
        $monthlySent = isset($stats['monthly_stats'][$currentMonth]) ? $stats['monthly_stats'][$currentMonth] : 0;
        $dailySent = isset($stats['daily_stats'][$currentDay]) ? $stats['daily_stats'][$currentDay] : 0;
        
        // Calculate remaining daily limit
        $remainingDaily = $dailyLimit - $dailySent;
        if ($remainingDaily < 0) $remainingDaily = 0;
        
        // Calculate success rate
        $successRate = 0;
        if ($stats['total_sent'] + $stats['total_errors'] > 0) {
            $successRate = round(($stats['total_sent'] / ($stats['total_sent'] + $stats['total_errors'])) * 100, 1);
        }
        
        // Format last campaign date
        $lastCampaign = 'Never';
        if ($stats['last_campaign']) {
            $lastCampaign = date('Y-m-d H:i', $stats['last_campaign']);
        }
        
        // Build message
        $message = "📊 *Email Sending Statistics*\n\n";
        
        $message .= "*Overall Performance:*\n";
        $message .= "• Total Emails Sent: {$stats['total_sent']}\n";
        $message .= "• Success Rate: {$successRate}%\n";
        $message .= "• Total Campaigns: {$stats['campaigns']}\n";
        $message .= "• Last Campaign: {$lastCampaign}\n\n";
        
        $message .= "*Current Usage:*\n";
        $message .= "• Sent Today: {$dailySent}\n";
        $message .= "• Daily Limit: {$dailyLimit}\n";
        $message .= "• Remaining Today: {$remainingDaily}\n";
        $message .= "• Sent This Month: {$monthlySent}\n\n";
        
        // Plan-specific information
        if ($planId === 'free') {
            $message .= "*Plan Limitations:*\n";
            $message .= "• You're on the Free Plan with limited sending capacity\n";
            $message .= "• Upgrade to send more emails and access premium features\n\n";
            
            $keyboard = [
                [
                    ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings'],
                    ['text' => '🚀 Upgrade Plan', 'callback_data' => 'show_plans']
                ],
                [
                    ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
                ]
            ];
        } else if ($planId === 'custom_smtp') {
            $message .= "*Custom SMTP Plan:*\n";
            $message .= "• Using your own SMTP configuration\n";
            $message .= "• No system-imposed sending limits\n\n";
            
            $keyboard = [
                [
                    ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
                ],
                [
                    ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
                ]
            ];
        } else {
            $message .= "*Premium Plan Benefits:*\n";
            $message .= "• Automatic SMTP rotation for better deliverability\n";
            $message .= "• Higher daily limits and sending capacity\n";
            $message .= "• Priority support and advanced features\n\n";
            
            $keyboard = [
                [
                    ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
                ],
                [
                    ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
                ]
            ];
        }
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Complete the email sending process
     * 
     * @param int $chatId The user's chat ID
     */
    private function completeSending($chatId, $silent = false) {
        // Reload user data to ensure we have the latest
        $this->loadUserData();
        $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
        
        // Prefer current sending_state if available; otherwise fallback to last_campaign snapshot
        $state = $userData['sending_state'] ?? null;
        if (!$state && isset($userData['last_campaign'])) {
            $state = $userData['last_campaign'];
        }
        if (!$state || !is_array($state)) {
            return; // nothing to aggregate
        }

        // Get sending stats
        $totalSent = $state['sent_count'] ?? ($state['total_sent'] ?? 0);
        $totalErrors = $state['error_count'] ?? 0;
        $totalEmails = $state['total_emails'] ?? ($totalSent + $totalErrors);
        $startTime = $state['start_time'] ?? (time() - 1);
        $endTime = time();
        $duration = $endTime - $startTime;
        
        // Format duration
        $hours = floor($duration / 3600);
        $minutes = floor(($duration % 3600) / 60);
        $seconds = $duration % 60;
        $durationFormatted = '';
        if ($hours > 0) {
            $durationFormatted .= "{$hours}h ";
        }
        if ($minutes > 0 || $hours > 0) {
            $durationFormatted .= "{$minutes}m ";
        }
        $durationFormatted .= "{$seconds}s";
        
        // Mark sending as complete if state exists
        if (isset($userData['sending_state'])) {
            $userData['sending_state']['is_sending'] = false;
        }
        
        // Update email statistics
        if (!isset($userData['email_stats'])) {
            $userData['email_stats'] = [
                'total_sent' => 0,
                'total_errors' => 0,
                'campaigns' => 0,
                'last_campaign' => null,
                'monthly_stats' => [],
                'daily_stats' => []
            ];
        }
        
        // Update overall stats
        $userData['email_stats']['total_sent'] += $totalSent;
        $userData['email_stats']['total_errors'] += $totalErrors;
        $userData['email_stats']['campaigns'] += 1;
        $userData['email_stats']['last_campaign'] = time();
        
        // Update monthly stats
        $currentMonth = date('Y-m');
        if (!isset($userData['email_stats']['monthly_stats'][$currentMonth])) {
            $userData['email_stats']['monthly_stats'][$currentMonth] = 0;
        }
        $userData['email_stats']['monthly_stats'][$currentMonth] += $totalSent;
        
        // Update daily stats
        $currentDay = date('Y-m-d');
        if (!isset($userData['email_stats']['daily_stats'][$currentDay])) {
            $userData['email_stats']['daily_stats'][$currentDay] = 0;
        }
        $userData['email_stats']['daily_stats'][$currentDay] += $totalSent;

        // Best-effort: ensure live counters reflect this campaign if not already applied
        $alreadyApplied = (int)($userData['sending_state']['counters_applied'] ?? 0);
        $delta = max(0, (int)$totalSent - $alreadyApplied);
        if ($delta > 0) {
            if (!isset($userData['emails_sent_hour'])) { $userData['emails_sent_hour'] = 0; }
            if (!isset($userData['emails_sent_today'])) { $userData['emails_sent_today'] = 0; }
            if (!isset($userData['emails_sent_month'])) { $userData['emails_sent_month'] = 0; }
            $userData['emails_sent_hour'] += $delta;
            $userData['emails_sent_today'] += $delta;
            $userData['emails_sent_month'] += $delta;
        }
    // Keep derived fields in sync for UI persistence
    if (method_exists($this, 'refreshDerivedQuotaFields')) { $this->refreshDerivedQuotaFields($chatId); }
        
        // Save updated user data
        $this->userData[$chatId] = $userData;
        $this->saveUserData();
        
        // Optionally send completion message (skip when $silent)
        if (!$silent) {
            $message = "📢 *Email Sending Complete*\n\n";
            $message .= "Your email campaign has finished.\n\n";
            $message .= "*Summary:*\n";
            $message .= "• Total Recipients: {$totalEmails}\n";
            $message .= "• Successfully Sent: {$totalSent}\n";
            $message .= "• Errors: {$totalErrors}\n";
            $message .= "• Duration: {$durationFormatted}\n";

            if ($totalSent > 0) {
                $rate = round($totalSent / ($duration / 60), 1);
                $message .= "• Sending Rate: {$rate} emails/minute\n";
            }

            $keyboard = [
                [
                    ['text' => '📊 My Status', 'callback_data' => 'status'],
                    ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
                ],
                [
                    ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
                ]
            ];

            $this->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
        }
        
        $this->log("Email sending completed for user {$chatId}. Sent: {$totalSent}, Errors: {$totalErrors}, Duration: {$durationFormatted}");
    }

    /**
     * Show contact support information
     * 
     * @param int $chatId The user's chat ID
     */
    private function showContactSupport($chatId) {
        $message = "💬 *Contact Support*\n\n";
        $message .= "Need help with your email sending or premium features? Our support team is here to assist you!\n\n";
        
        $message .= "*Contact Options:*\n";
        $message .= "• Email: support@chetobot.com\n";
        $message .= "• Telegram: @Cheto_support\n";
        $message .= "• Support Hours: 24/7\n\n";
        
        $message .= "For faster assistance, please include your Chat ID: `{$chatId}` when contacting support.";
        
        $keyboard = [
            [
                ['text' => '📨 Email Support', 'url' => 'mailto:support@chetobot.com']
            ],
            [
                ['text' => '💬 Telegram Support', 'url' => 'https://t.me/Cheto_support']
            ],
            [
                ['text' => '❓ FAQ', 'callback_data' => 'show_faq']
            ],
            [
                ['text' => '🔙 Back', 'callback_data' => 'email_settings']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Process sender name input from user
     * 
     * @param int $chatId The user's chat ID
     * @param string $text The sender name text
     */
    private function processSenderNameInput($chatId, $text) {
        // Check if user has premium plan
        $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
        $userPlan = isset($userData['plan']) ? $userData['plan'] : 'free';
        
        // Only premium users can customize sender name
        if ($userPlan === 'free') {
            $message = "⛔ *Sender Name Customization Locked*\n\n";
            $message .= "Custom sender names are only available for premium users.\n";
            $message .= "Please upgrade to any premium plan to unlock this feature.";
            
            $keyboard = [
                [
                    ['text' => '🔓 Upgrade Plan', 'callback_data' => 'show_plans'],
                    ['text' => '💬 Contact Support', 'callback_data' => 'contact_support']
                ],
                [
                    ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
                ],
                [
                    ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
                ]
            ];
            
            $this->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            return;
        }
        
        // For premium users, process the sender name
        $senderName = trim($text);
        
        if (empty($senderName)) {
            $this->sendTelegramMessage($chatId, "❌ Sender name cannot be empty. Please enter a valid name.");
            return;
        }
        
        // Save the sender name to user data
        $userData['sender_name'] = $senderName;
        $userData['state'] = 'idle'; // Reset state
        $this->userData[$chatId] = $userData;
        $this->saveUserData();
        
        // Confirm to user
        $message = "✅ Sender name has been set to: *{$senderName}*\n\nYou can now continue with your email setup."; 
        
        $keyboard = [
            [
                ['text' => '⚙️ Email Settings', 'callback_data' => 'email_settings']
            ],
            [
                ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Show attachment menu
     * 
     * @param string $chatId Chat ID
     */
    private function showAttachmentMenu($chatId) {
        $message = "📎 *Attachment Management*\n\n";
        $message .= "Upload your attachments here:\n\n";
        $message .= "Supported formats:\n";
        $message .= "• PDF Documents\n";
        $message .= "• Images (JPG, PNG)\n";
        $message .= "• HTML Files\n";
        $message .= "• Other Documents\n\n";
        $message .= "Current attachments:";
        
        $userData = $this->getUserData($chatId);
        $attachments = isset($userData['attachments']) ? $userData['attachments'] : [];
        
        if (!empty($attachments)) {
            foreach ($attachments as $file) {
                $message .= "\n• " . basename($file);
            }
        } else {
            $message .= "\nNo attachments uploaded";
        }
        
        $keyboard = [
            [
                ['text' => '🗑️ Clear All', 'callback_data' => 'clear_attachments']
            ],
            [
                ['text' => '🔙 Back to Setup', 'callback_data' => 'setup_menu']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Show reply-to setup menu
     * 
     * @param string $chatId Chat ID
     */
    private function showReplyToSetup($chatId) {
        $userData = $this->getUserData($chatId);
        $replyTo = isset($userData['email_settings']['reply_to']) ? $userData['email_settings']['reply_to'] : null;
        
        $message = "↩️ *Reply-To Email Setup*\n\n";
        $message .= "Current reply-to email:\n";
        $message .= $replyTo ? "`$replyTo`" : "_Not set_\n\n";
        $message .= "To set a new reply-to email, use:\n";
        $message .= "`/reply your@email.com`";
        
        $keyboard = [
            [
                ['text' => '❌ Clear Reply-To', 'callback_data' => 'clear_reply']
            ],
            [
                ['text' => '🔙 Back to Setup', 'callback_data' => 'setup_menu']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Handle plan selection
     * 
     * @param string $chatId Chat ID
     * @param string $planId Selected plan ID
     */
    private function handlePlanSelection($chatId, $planId) {
        $plans = defined('PLANS') ? PLANS : [];
        if (!isset($plans[$planId])) {
            $this->sendTelegramMessage($chatId, "❌ Invalid plan selected.");
            return;
        }
        
        $plan = $plans[$planId];
        $message = "💳 *Plan Selected:* {$plan['name']}\n\n";
        $message .= "💰 Price: \${$plan['price']}\n";
        $message .= "⏳ Duration: {$plan['duration']} days\n";
        $message .= "📧 *Sending Limits:*\n";
        $message .= "• Per Hour: " . ((isset($plan['emails_per_hour']) ? $plan['emails_per_hour'] : -1) == -1 ? "Unlimited" : number_format($plan['emails_per_hour'])) . "\n";
        $message .= "• Per Day: " . ((isset($plan['emails_per_day']) ? $plan['emails_per_day'] : -1) == -1 ? "Unlimited" : number_format($plan['emails_per_day'])) . "\n";
        $message .= "• Per Month: " . ((isset($plan['emails_per_month']) ? $plan['emails_per_month'] : -1) == -1 ? "Unlimited" : number_format(isset($plan['emails_per_month']) ? $plan['emails_per_month'] : ($plan['emails_per_day'] * 30))) . "\n\n";
        
        // Generate unique order ID
        $orderId = uniqid($chatId . '_');
        
        // Save order info in user data
        $userData = $this->getUserData($chatId);
        $userData['pending_order'] = [
            'order_id' => $orderId,
            'plan_id' => $planId,
            'timestamp' => time(),
            'status' => 'pending'
        ];
        $this->userData[$chatId] = $userData;
        $this->saveUserData();
        
        $message .= "*How to Purchase:*\n";
        $message .= "1. Contact support with your Order ID\n";
        $message .= "2. Complete the payment\n";
        $message .= "3. Admin will activate your plan\n\n";
        $message .= "🔖 *Your Order ID:* `{$orderId}`\n";
        $message .= "\nKeep this Order ID for reference!";
        
        $keyboard = [
            [
                ['text' => '💬 Contact Support', 'url' => 'https://t.me/Ninja111']
            ],
            [
                ['text' => '✅ I Have Paid', 'callback_data' => 'confirm_payment_' . $orderId]
            ],
            [
                ['text' => '🔙 Back to Plans', 'callback_data' => 'plans']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    private function sendAdminMenu($chatId) {
        $keyboard = [
            [
                ['text' => '📊 Show Analytics', 'callback_data' => 'admin_analytics'],
                ['text' => '📧 SMTP Status', 'callback_data' => 'admin_smtp_status']
            ],
            [
                ['text' => '👥 User Management', 'callback_data' => 'admin_users'],
                ['text' => '📢 Broadcast', 'callback_data' => 'admin_broadcast']
            ],
            [
                ['text' => '📋 Pending Orders', 'callback_data' => 'admin_pending_orders'],
                ['text' => '⚙️ System Settings', 'callback_data' => 'admin_settings']
            ],
            [
                ['text' => '🔙 Back to Main Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $message = "👑 *Admin Control Panel*\n\n";
        $message .= "Welcome to the administrative dashboard!\n";
        $message .= "Use the buttons below to manage the system:";

        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ])
        ]);
    }

    /**
     * Check if a user is an admin
     * 
     * @param string $chatId Chat ID
     * @return bool True if user is admin
     */
    /**
     * Show main menu
     * 
     * @param string $chatId Chat ID
     * @param int|null $messageId Optional message ID to edit
     */
    private function showMainMenu($chatId, $messageId = null) {
        $message = "🔥 *CHETO INBOX SENDER - SNIPER VERSION* 🔥\n\n";
        $message .= "Welcome to your email sending dashboard!\n\n";
        $message .= "Select an option below to get started:";
        
        $keyboard = [
            [
                ['text' => '📊 My Status', 'callback_data' => 'status'],
                ['text' => '💳 Plans & Pricing', 'callback_data' => 'plans']
            ],
            [
                ['text' => '📧 Email Setup', 'callback_data' => 'setup_menu'],
                ['text' => '📝 Help Guide', 'callback_data' => 'help']
            ]
        ];
        
        if ($this->isAdmin($chatId)) {
            $keyboard[] = [
                ['text' => '👑 Admin Panel', 'callback_data' => 'admin_panel']
            ];
        }
        
        $keyboard[] = [
            ['text' => '💬 Support', 'url' => 'https://t.me/Ninja111']
        ];
        
        $params = [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ];
        
        if ($messageId) {
            $params['chat_id'] = $chatId;
            $params['message_id'] = $messageId;
            $this->callTelegramApi('editMessageText', array_merge(['text' => $message], $params));
        } else {
            $this->sendTelegramMessage($chatId, $message, $params);
        }
    }

    private function isAdmin($chatId) {
        return in_array($chatId, $this->adminIds);
    }

    /**
     * Send message to Telegram
     * 
     * @param string $chatId Chat ID
     * @param string $text Message text
     * @param array $options Additional options
     * @return array Response from Telegram API
     */
    public function sendTelegramMessage($chatId, $text, $options = []) {
        if (empty($chatId)) {
            $this->log("Error: Cannot send message - chat ID is empty");
            return false;
        }

        if (empty($this->token)) {
            $this->log("Error: Cannot send message - bot token not configured");
            return false;
        }

        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => isset($options['parse_mode']) ? $options['parse_mode'] : 'Markdown'
        ];
        
        // Merge additional options
        if (!empty($options)) {
            unset($options['parse_mode']); // Already handled above
            $params = array_merge($params, $options);
        }
        
        try {
            return $this->callTelegramApi('sendMessage', $params);
        } catch (Exception $e) {
            $this->log("Error sending message: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Call Telegram API
     * 
     * @param string $method API method
     * @param array $params Parameters
     * @return array Response
     */
    private function callTelegramApi($method, $params = []) {
        $url = $this->apiUrl . '/' . $method;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        
        // Disable SSL verification for development environment
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('Curl error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if (!$result) {
            throw new Exception('Invalid response from Telegram API');
        }
        
        return $result;
    }

    /**
     * Log a message
     * 
     * @param string $message Message to log
     */
    public function log($message) {
        // Mask passwords in plain strings and JSON blobs
        $safe = $message;
        $safe = preg_replace('/(password\s*[:=]\s*)([^\s,]+)/i', '$1****', $safe);
        $safe = preg_replace('/("password"\s*:\s*")([^"]+)(")/i', '$1****$3', $safe);
        $logMessage = date('[Y-m-d H:i:s] ') . $safe . "\n";
        file_put_contents(self::LOG_FILE, $logMessage, FILE_APPEND);
    }

    /**
     * Log an exception with context and optionally notify admins silently
     */
    private function logException($context, \Throwable $e, $notifyAdmins = false) {
        $msg = $context . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
        $this->log($msg);
        if ($notifyAdmins && defined('ADMIN_CHAT_IDS') && is_array(ADMIN_CHAT_IDS)) {
            foreach (ADMIN_CHAT_IDS as $adminId) {
                try { $this->sendTelegramMessage($adminId, '⚠️ ' . $msg, ['disable_notification' => true]); } catch (\Throwable $t) {}
            }
        }
    }

    /**
     * Spawn a background worker process for a specific chat
     */
    private function spawnWorker($chatId) {
        // Allow disabling worker via config/ENV
        if (defined('DISABLE_WORKER') && DISABLE_WORKER) { return false; }
        $php = PHP_BINARY ?: 'php';
        $scriptPath = __DIR__ . '/../queue_worker.php';
        if (!file_exists($scriptPath)) { $this->log('Worker script not found'); return false; }
        $script = escapeshellarg($scriptPath);
        $arg = '--chat-id=' . escapeshellarg((string)$chatId);
        $ok = false;
        // Try proc_open
        if (function_exists('proc_open')) {
            $proc = @proc_open($php . ' ' . escapeshellarg($scriptPath) . ' ' . $arg . ' >/dev/null 2>&1 &', [], $pipes);
            if (is_resource($proc)) { $ok = true; @proc_close($proc); }
        }
        // Fallback to exec if not started
        if (!$ok) {
            $cmd = $php . ' ' . $script . ' ' . $arg . ' > /dev/null 2>&1 &';
            $ret = @exec($cmd);
            $ok = true; // exec has no reliable return; assume best-effort
        }
        $this->log(($ok? 'Spawned' : 'Failed to spawn') . " worker for chat {$chatId}");
        return $ok;
    }

    /**
     * Get an EmailSender instance for a user. Uses shared pool for system SMTPs,
     * and a per-user instance for custom SMTP plans.
     */
    private function getEmailSenderInstance($chatId, $userData) {
        $planId = isset($userData['plan']) ? $userData['plan'] : 'trial';
        if ($planId === 'custom_smtp' && isset($userData['smtp'])) {
            // Cache per-user custom sender
            if (!isset($this->customEmailSenders[$chatId])) {
                try {
                    $sender = new \App\EmailSender($userData['smtp'], $this, $planId);
                    if (method_exists($sender, 'setRotationStrategy')) {
                        $sender->setRotationStrategy('weighted_random');
                    }
                    if (method_exists($sender, 'setEmailsPerRotation')) {
                        $sender->setEmailsPerRotation(1);
                    }
                    if (method_exists($sender, 'selectRandomSmtp')) {
                        $sender->selectRandomSmtp();
                    }
                    if (method_exists($sender, 'enableDebugLogging') && getenv('DEBUG_SMTP')) {
                        $sender->enableDebugLogging(true);
                    }
                    $this->customEmailSenders[$chatId] = $sender;
                } catch (\Throwable $e) {
                    $this->log('Failed to create custom EmailSender: ' . $e->getMessage());
                    return null;
                }
            }
            $this->customEmailSenders[$chatId]->setCurrentChatId($chatId);
            return $this->customEmailSenders[$chatId];
        }
        // Use shared sender with pool
        if ($this->sharedEmailSender === null && !empty($this->smtps)) {
            try {
                $this->sharedEmailSender = new \App\EmailSender($this->smtps, $this, 'trial');
                if (method_exists($this->sharedEmailSender, 'setRotationStrategy')) {
                    $this->sharedEmailSender->setRotationStrategy('weighted_random');
                }
                if (method_exists($this->sharedEmailSender, 'setEmailsPerRotation')) {
                    $this->sharedEmailSender->setEmailsPerRotation(1);
                }
                if (method_exists($this->sharedEmailSender, 'enableDebugLogging') && getenv('DEBUG_SMTP')) {
                    $this->sharedEmailSender->enableDebugLogging(true);
                }
            } catch (\Throwable $e) {
                $this->log('Failed to create shared EmailSender: ' . $e->getMessage());
                return null;
            }
        }
        if ($this->sharedEmailSender) {
            $this->sharedEmailSender->setCurrentChatId($chatId);
        }
        return $this->sharedEmailSender;
    }

    /**
     * Public accessor for a shared EmailSender (used by queue processor)
     */
    public function getEmailSender() {
        if ($this->sharedEmailSender === null && !empty($this->smtps)) {
            try {
                $this->sharedEmailSender = new \App\EmailSender($this->smtps, $this, 'trial');
                if (method_exists($this->sharedEmailSender, 'setRotationStrategy')) {
                    $this->sharedEmailSender->setRotationStrategy('weighted_random');
                }
                if (method_exists($this->sharedEmailSender, 'setEmailsPerRotation')) {
                    $this->sharedEmailSender->setEmailsPerRotation(1);
                }
                if (method_exists($this->sharedEmailSender, 'selectRandomSmtp')) {
                    $this->sharedEmailSender->selectRandomSmtp();
                }
            } catch (\Throwable $e) {
                $this->log('Failed to create shared EmailSender: ' . $e->getMessage());
                return null;
            }
        }
        return $this->sharedEmailSender;
    }

    /**
     * Public accessor: get the current sending_state for a chat (safe for workers)
     */
    public function getSendingState($chatId) {
        $this->loadUserData();
        return isset($this->userData[$chatId]['sending_state']) ? $this->userData[$chatId]['sending_state'] : [];
    }

    /**
     * Public helper: whether a chat has active sending
     */
    public function hasActiveSending($chatId) {
        $s = $this->getSendingState($chatId);
        return isset($s['is_sending']) && $s['is_sending'];
    }

    // Unused placeholder handlers removed: processEmailList/processHtmlTemplate.

    /**
     * Rotate SMTP
     * 
     * @param bool $silent Whether to notify about rotation
     * @return bool True if rotation was successful
     */
    private function rotateSmtp($silent = false) {
        if (count($this->smtps) <= 1) {
            if (!$silent) {
                $this->log("Cannot rotate SMTP: No alternative SMTPs available");
            }
            return false;
        }
        
        // Find current SMTP index
        $currentIndex = -1;
        foreach ($this->smtps as $index => $smtp) {
            if ($smtp['username'] === $this->currentSmtp['username']) {
                $currentIndex = $index;
                break;
            }
        }
        
        // Get next SMTP
        $nextIndex = ($currentIndex + 1) % count($this->smtps);
        $this->currentSmtp = $this->smtps[$nextIndex];
        
        // Update rotation stats
        $this->smtpRotationStats['rotation_count']++;
        $this->smtpRotationStats['current_error_count'] = 0;
        
        if (!$silent) {
            $masked = $this->maskSensitive($this->currentSmtp['username'] ?? 'unknown');
            $this->log("Rotated SMTP to: " . $masked);
        }
        if ($this->sharedEmailSender && method_exists($this->sharedEmailSender, 'rotateToNextSmtp')) {
            $this->sharedEmailSender->rotateToNextSmtp();
        }
        
        return true;
    }

    /**
     * Update SMTP performance metrics
     * 
     * @param array $opt Options
     * @param float $successRate Success rate
     * @param int $now Current timestamp
     */
    private function updateSmtpPerformanceMetrics($opt, $successRate, $now) {
        $performanceEntry = [
            'time' => $now,
            'success_rate' => $successRate,
            'emails_sent' => isset($opt['sent']) ? $opt['sent'] : 0,
            'emails_failed' => isset($opt['failed']) ? $opt['failed'] : 0,
            'smtp' => $this->currentSmtp['username'] ?? 'unknown'
        ];
        
        // Implementation for updating performance metrics
    }

    /**
     * Mask sensitive values for logs
     */
    private function maskSensitive($value) {
        if (!is_string($value) || $value === '') return '****';
        if (strlen($value) <= 4) return '****';
        return substr($value, 0, 2) . '****' . substr($value, -2);
    }

    /**
     * Provide SMTP rotation threshold used by queue processor
     */
    public function getSmtpRotationThreshold() {
        if (defined('SMTP_ROTATION_THRESHOLD')) {
            return SMTP_ROTATION_THRESHOLD;
        }
        return 3;
    }

    /**
     * Handle SMTP error
     * 
     * @param string $chatId Chat ID
     * @return bool True if error was handled
     */
    private function handleSmtpError($chatId) {
        $this->smtpRotationStats['current_error_count']++;
        $this->smtpRotationStats['failure_count']++;
        
        if (isset($this->currentSmtpError) && $this->currentSmtpError['type'] === 'sending_limit') {
            // Special handling for sending limit errors - silently rotate SMTP
            $username = isset($this->currentSmtp['username']) ? $this->currentSmtp['username'] : 'Unknown';
            $resetTime = isset($this->smtpRotationStats['error_tracking']['limit_reset_time'][$username]) ? $this->smtpRotationStats['error_tracking']['limit_reset_time'][$username] : (time() + 86400);
            $this->rotateSmtp(true);
            return true;
        }
        
        // Check if we need to rotate SMTP due to too many errors
        if (($this->smtpRotationStats['current_error_count'] ?? 0) >= 3) {
            $this->sendTelegramMessage($chatId, "Too many errors with current SMTP. Rotating to next SMTP...");
            return $this->rotateSmtp();
        }
        
        return false;
    }

    /**
     * Show system settings menu
     */
    /**
     * Show broadcast menu to admin
     * 
     * @param string $chatId Chat ID
     */
    private function showBroadcastMenu($chatId) {
        $message = "📢 *Broadcast Message*\n\n";
        $message .= "Send a message to all users of the bot.\n\n";
        $message .= "*Instructions:*\n";
        $message .= "1. Use /broadcast followed by your message\n";
        $message .= "2. Message can include Markdown formatting\n";
        $message .= "3. You can include buttons using format:\n";
        $message .= "`text|callback_data` or `text|url`\n\n";
        $message .= "*Example:*\n";
        $message .= "/broadcast 🔔 *New Update*\n";
        $message .= "Version 2.0 is now available!\n\n";
        $message .= "Update Now|admin_update\n";
        $message .= "Learn More|https://example.com";
        
        $keyboard = [
            [
                ['text' => '📝 New Broadcast', 'callback_data' => 'admin_new_broadcast'],
                ['text' => '📋 History', 'callback_data' => 'admin_broadcast_history']
            ],
            [
                ['text' => '🔙 Back to Admin', 'callback_data' => 'admin_panel']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    private function showSystemSettings($chatId) {
        $message = "⚙️ *System Settings*\n\n";
        $message .= "*Current Configuration:*\n";
        $message .= "• Max Daily Emails: " . MAX_EMAILS_PER_DAY . "\n";
        $message .= "• Max Monthly Emails: " . MAX_EMAILS_PER_MONTH . "\n";
        $message .= "• SMTP Timeout: " . SMTP_TIMEOUT . "s\n";
        $message .= "• SMTP Rotation Threshold: " . SMTP_ROTATION_THRESHOLD . "\n";
        
        $keyboard = [
            [
                ['text' => '📧 Email Limits', 'callback_data' => 'admin_email_limits'],
                ['text' => '⚡️ SMTP Settings', 'callback_data' => 'admin_smtp_settings']
            ],
            [
                ['text' => '🔒 Security', 'callback_data' => 'admin_security'],
                ['text' => '📝 Logs', 'callback_data' => 'admin_logs']
            ],
            [
                ['text' => '🔙 Back to Admin', 'callback_data' => 'admin_panel']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Check if a message is an SMTP error
     * 
     * @param string $errorMessage Error message
     * @return bool True if it's an SMTP error
     */
    private function isSmtpError($errorMessage) {
        $smtpErrors = [
            'exceeded the rate limit' => ['type' => 'sending_limit', 'severity' => 'high'],
            'sending limit exceeded' => ['type' => 'sending_limit', 'severity' => 'high'],
            'Daily user sending quota exceeded' => ['type' => 'sending_limit', 'severity' => 'high'],
            'authentication failed' => ['type' => 'auth', 'severity' => 'high'],
            'Connection could not be established' => ['type' => 'connection', 'severity' => 'medium'],
            'Connection refused' => ['type' => 'connection', 'severity' => 'medium'],
            'Connection timed out' => ['type' => 'connection', 'severity' => 'medium'],
            'Failed to connect' => ['type' => 'connection', 'severity' => 'medium'],
            'Error while sending' => ['type' => 'sending', 'severity' => 'medium'],
            'Invalid address' => ['type' => 'address', 'severity' => 'low']
        ];
        
        $this->currentSmtpError = ['type' => 'unknown', 'severity' => 'low'];
        
        foreach ($smtpErrors as $errorCode => $details) {
            if (strpos($errorMessage, $errorCode) !== false) {
                $this->currentSmtpError = array_merge($this->currentSmtpError, $details);
                return true;
            }
        }
        
        return false;
    }

    /**
     * Show SMTP status information
     * 
     * @param string $chatId Chat ID
     */
    private function showSmtpStatus($chatId) {
        $message = "📧 *SMTP Status Overview*\n\n";
        
        // Current SMTP information
        if ($this->currentSmtp) {
            $message .= "🟢 *Active SMTP:*\n";
            $message .= "• Server: {$this->currentSmtp['host']}:{$this->currentSmtp['port']}\n";
            $message .= "• Username: {$this->currentSmtp['username']}\n";
            $message .= "• From: " . ($this->currentSmtp['from_email'] ?? $this->currentSmtp['username']) . "\n";
            $message .= "• Name: " . ($this->currentSmtp['from_name'] ?? 'Default') . "\n\n";
        } else {
            $message .= "🔴 *No Active SMTP Configured*\n\n";
        }
        
        // SMTP Pool Statistics
        $message .= "📊 *SMTP Pool Stats:*\n";
        $message .= "• Total SMTPs: " . count($this->smtps) . "\n";
        $message .= "• Verified: " . (isset($this->verifiedSmtps) ? count($this->verifiedSmtps) : 0) . "\n";
    $message .= "• Rotations Today: " . ($this->smtpRotationStats['rotation_count'] ?? 0) . "\n";
    $message .= "• Total Errors: " . ($this->smtpRotationStats['failure_count'] ?? 0) . "\n";
    $message .= "• Current Errors: " . ($this->smtpRotationStats['current_error_count'] ?? 0) . "\n\n";
        
        // List all SMTPs (showing active/verified markers)
        if (!empty($this->smtps)) {
            $hash = static function($c){ return ($c['host']??'')."|".($c['username']??''); };
            $verifiedKeys = isset($this->verifiedSmtps) ? array_flip(array_map($hash, $this->verifiedSmtps)) : [];
            $message .= "📋 *Configured SMTPs*\n\n";
            foreach ($this->smtps as $i => $smtp) {
                $isActive = ($this->currentSmtp && $smtp['username'] === ($this->currentSmtp['username'] ?? ''));
                $isVerified = isset($verifiedKeys[$hash($smtp)]);
                $badge = ($isActive ? ' (active)' : '') . ($isVerified ? ' ✅' : '');
                $message .= ($i+1) . ". {$smtp['host']}:" . ($smtp['port'] ?? 587) . " — " . ($smtp['username'] ?? 'user') . $badge . "\n";
            }
            $message .= "\n";
        }
        
        // Sending Statistics
        $message .= "📈 *Sending Statistics:*\n";
    $message .= "• Total Sent: " . ($this->smtpRotationStats['total_sent'] ?? 0) . "\n";
        
        $keyboard = [
            [
                ['text' => '🔄 Rotate SMTP', 'callback_data' => 'admin_rotate_smtp']
            ],
            [
                ['text' => '🔙 Back to Admin', 'callback_data' => 'admin_panel']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Show plans information
     * 
     * @param string $chatId Chat ID
     */
    /**
     * Show email setup menu
     * 
     * @param string $chatId Chat ID
     */
    private function showEmailSetupMenu($chatId) {
        // Get user data to check what's been uploaded
        $userData = $this->getUserData($chatId);
        
        $message = "� *Email Campaign Setup*\n\n";
        
        // Show campaign status
        $hasTemplate = isset($userData['html_template_path']);
        $hasList = isset($userData['email_list_path']);
        $hasSmtp = isset($userData['smtp']) || !empty($this->smtps);
        $hasSubject = isset($userData['email_subject']);
        
        // Quick status overview
        $message .= "*Campaign Status:*\n";
        $message .= $hasTemplate && $hasList ? "✅" : "❌";
        $message .= " Ready to Send\n\n";
        
        // Required items with status
        $message .= "*Required Steps:*\n";
        $message .= ($hasTemplate ? "✅" : "1️⃣") . " Upload HTML Template\n";
        $message .= ($hasList ? "✅" : "2️⃣") . " Upload Email List\n";
        
        // Optional settings status
        if ($hasTemplate && $hasList) {
            $message .= "\n*Settings:*\n";
            $message .= ($hasSubject ? "✅" : "⚪️") . " Subject: " . 
                (isset($userData['email_subject']) ? "`" . $userData['email_subject'] . "`" : "Default") . "\n";
            $message .= ($hasSmtp ? "✅" : "⚪️") . " SMTP: " . 
                (isset($userData['smtp']) ? "Custom" : "System") . "\n";
        }
        
        // Create keyboard based on status
        $keyboard = [];
        
        // Primary actions (always visible)
        $keyboard[] = [
            ['text' => '1️⃣ Upload HTML', 'callback_data' => 'upload_html'],
            ['text' => '2️⃣ Upload List', 'callback_data' => 'upload_txt']
        ];
        
        // Settings (shown after required files are uploaded)
        if ($hasTemplate && $hasList) {
            $keyboard[] = [
                ['text' => '📝 Edit Subject', 'callback_data' => 'subject_setup'],
                ['text' => '⚙️ SMTP Setup', 'callback_data' => 'smtp_setup']
            ];
        }
        
        // Start sending button (only if required files are uploaded)
        if ($hasTemplate && $hasList) {
            // Single, consistent button
            $keyboard[] = [
                ['text' => '🚀 Start Sending Campaign', 'callback_data' => 'start_sending']
            ];
        }
        
        $keyboard[] = [
            ['text' => '🔙 Back to Main Menu', 'callback_data' => 'main_menu']
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Show subject and sender setup menu
     * 
     * @param string $chatId Chat ID
     */
    private function showSubjectSetupMenu($chatId) {
        $message = "📝 *Subject & Sender Setup*\n\n";
        $message .= "Configure your email subject and sender name:";
        
        $keyboard = [
            [
                ['text' => '✏️ Set Subject', 'callback_data' => 'set_subject'],
                ['text' => '👤 Set Sender Name', 'callback_data' => 'set_sender']
            ],
            [
                ['text' => '🔙 Back to Setup', 'callback_data' => 'setup_menu']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    private function showPlans($chatId) {
        // Get plans from configuration
        $plans = defined('PLANS') ? PLANS : [];
        
        if (empty($plans)) {
            $this->sendTelegramMessage($chatId, "No plans are currently available.");
            return;
        }
        
        // Get user data
        $userData = $this->getUserData($chatId);
        $currentPlanId = isset($userData['plan']) ? $userData['plan'] : 'trial';
        
        // Build plans message
        $message = "📋 Available Plans\n\n";
        
        foreach ($plans as $planId => $plan) {
            $isCurrent = ($planId === $currentPlanId) ? "✅ " : "";
            
            $message .= "{$isCurrent}*{$plan['name']}*\n";
            $message .= "- Duration: " . ($plan['duration'] == -1 ? "Unlimited" : "{$plan['duration']} days") . "\n";
            $message .= "- Emails per hour: " . ($plan['emails_per_hour'] == -1 ? "Unlimited" : number_format($plan['emails_per_hour'])) . "\n";
            $message .= "- Emails per day: " . ($plan['emails_per_day'] == -1 ? "Unlimited" : number_format($plan['emails_per_day'])) . "\n";
            // Calculate monthly limit based on daily limit * 30 if not specified
            $monthlyLimit = isset($plan['emails_per_month']) ? $plan['emails_per_month'] : ($plan['emails_per_day'] * 30);
            $message .= "- Emails per month: " . ($monthlyLimit == -1 ? "Unlimited" : number_format($monthlyLimit)) . "\n\n";
        }
        
        $message .= "\nYour current plan: *{$plans[$currentPlanId]['name']}*\n";
        
        // If plan has expiration
        if (isset($userData['plan_expires']) && $userData['plan_expires'] > time()) {
            $daysLeft = ceil(($userData['plan_expires'] - time()) / 86400);
            $message .= "Expires in: {$daysLeft} days\n";
        }
        
        // Create keyboard with plan selection buttons
        $keyboard = [];
        $row = [];
        $count = 0;
        foreach ($plans as $planId => $plan) {
            if ($planId === 'trial') continue; // Skip trial plan from buttons
            
            $button = [
                'text' => $plan['name'] . " ($" . $plan['price'] . ")",
                'callback_data' => "plan_select_" . $planId
            ];
            $row[] = $button;
            $count++;
            
            if ($count % 2 === 0) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        
        if (!empty($row)) {
            $keyboard[] = $row;
        }
        
        // Add contact support button
        $keyboard[] = [
            ['text' => '💬 Contact Support', 'url' => 'https://t.me/Ninja111']
        ];
        
        // Add back button
        $keyboard[] = [
            ['text' => '🔙 Back to Main Menu', 'callback_data' => 'main_menu']
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Handle setting SMTP configuration
     * 
     * @param string $chatId Chat ID
     * @param array $parts Command parts
     */
    private function handleSetSmtp($chatId, $parts) {
        // Remove command part
        array_shift($parts);
        $smtpString = trim(implode(' ', $parts));
        
        if (empty($smtpString)) {
            $this->sendTelegramMessage($chatId, "Please provide SMTP details in format:\n/setsmtp smtp.example.com:587,username,SECRET,from_email,from_name,encryption,daily_limit,hourly_limit\n\nExample:\n/setsmtp smtp.gmail.com:587,user@gmail.com,SECRET,user@gmail.com,John Doe,tls,500,50");
            return;
        }
        
        try {
            // Parse SMTP string
            $smtp = $this->parseSmtpString($smtpString);
            
            // Get user data
            $userData = $this->getUserData($chatId);
            
            // Save SMTP to user data
            $userData['smtp'] = $smtp;
            $this->userData[$chatId] = $userData;
            
            // Save user data
            $this->saveUserData();
            
            $this->sendTelegramMessage($chatId, "✅ SMTP configuration saved successfully!\n\nHost: {$smtp['host']}:{$smtp['port']}\nUsername: {$smtp['username']}\nFrom: {$smtp['from_email']}\nName: {$smtp['from_name']}");
        } catch (Exception $e) {
            $this->sendTelegramMessage($chatId, "❌ Error: " . $e->getMessage() . "\n\nPlease use the correct format:\n/setsmtp smtp.example.com:587,username,SECRET,from_email,from_name,encryption,daily_limit,hourly_limit");
        }
    }

    // Removed legacy string-based pause/resume/stop handlers to avoid conflicts

    /**
     * Handle reply-to command
     * 
     * @param string $chatId Chat ID
     * @param array $parts Command parts
     */
    private function handleReplyToCommand($chatId, $parts) {
        // Check if reply-to email is provided
        if (count($parts) < 2) {
            $this->sendTelegramMessage($chatId, "❌ Please provide a reply-to email address:\n/reply example@domain.com");
            return;
        }
        
        $replyTo = trim($parts[1]);
        
        // Validate email format
        if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $this->sendTelegramMessage($chatId, "❌ Invalid email format. Please provide a valid email address.");
            return;
        }
        
        // Get user data
        $userData = $this->getUserData($chatId);
        
        // Save reply-to email
        $userData['email_settings']['reply_to'] = $replyTo;
        $this->userData[$chatId] = $userData;
        $this->saveUserData();
        
        $this->sendTelegramMessage($chatId, "✅ Reply-To email has been set to: *{$replyTo}*", ['parse_mode' => 'Markdown']);
    }

    /**
     * Handle subject command
     * 
     * @param string $chatId Chat ID
     * @param array $parts Command parts
     */
    private function handleSubjectCommand($chatId, $parts) {
        // Remove the command and get the subject
        array_shift($parts);
        $subject = trim(implode(' ', $parts));
        
        if (empty($subject)) {
            $this->sendTelegramMessage($chatId, "❌ Please provide an email subject:\n/subject Your Email Subject");
            return;
        }
        
        // Get user data
        $userData = $this->getUserData($chatId);
        
        // Save subject
        $userData['email_settings']['subject'] = $subject;
        $this->userData[$chatId] = $userData;
        $this->saveUserData();
        
        $this->sendTelegramMessage($chatId, "✅ Email subject has been set to: *{$subject}*", ['parse_mode' => 'Markdown']);
    }

    /**
     * Handle send command
     * 
     * @param string $chatId Chat ID
     */
    private function handleSendCommand($chatId) {
        // Get user data - make sure we have the most up-to-date data
        $this->loadUserData(); // Ensure we have the latest data from storage
        $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
        
        // Set default subject if not set
        if (!isset($userData['email_subject'])) {
            $userData['email_subject'] = "Test email from @Cheto_inboxing_bot";
            $this->userData[$chatId] = $userData;
            $this->saveUserData();
        }
        
        // Check if user is already sending emails
        if (isset($userData['sending_state']) && $userData['sending_state'] === 'sending') {
            $sent = isset($userData['sending_progress']) ? $userData['sending_progress'] : 0;
            $total = isset($userData['sending_total']) ? $userData['sending_total'] : 0;
            $remaining = $total - $sent;
            
            $message = "⚠️ You already have an active email sending process!\n\n";
            $message .= "📊 *Current Progress:*\n";
            $message .= "• Total emails: {$total}\n";
            $message .= "• Sent: {$sent}\n";
            $message .= "• Remaining: {$remaining}\n\n";
            $message .= "Use /pause to pause or /stop to stop the current process.";
            
            $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
            return;
        }
        
        // Check if user has a paused sending process
        if (isset($userData['sending_state']) && $userData['sending_state'] === 'paused') {
            $sent = isset($userData['sending_progress']) ? $userData['sending_progress'] : 0;
            $total = isset($userData['sending_total']) ? $userData['sending_total'] : 0;
            $remaining = $total - $sent;
            
            $message = "⚠️ You have a paused email sending process!\n\n";
            $message .= "📊 *Current Progress:*\n";
            $message .= "• Total emails: {$total}\n";
            $message .= "• Sent: {$sent}\n";
            $message .= "• Remaining: {$remaining}\n\n";
            $message .= "Use /resume to continue or /stop to cancel.";
            
            $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
            return;
        }
        
        // Debug the user data structure
        $this->log("User data for chat ID {$chatId}: " . json_encode($userData));
        
        // Get HTML template and email list directly from userData
        $htmlTemplate = isset($userData['html_template']) ? $userData['html_template'] : null;
        $htmlTemplateName = isset($userData['html_template_name']) ? $userData['html_template_name'] : 'Unknown';
        $emailList = isset($userData['email_list']) ? $userData['email_list'] : null;
        $emailListName = isset($userData['email_list_name']) ? $userData['email_list_name'] : 'Unknown';
        
        // Check if both files are available
        $hasHtmlTemplate = !empty($htmlTemplate);
        $hasEmailList = !empty($emailList) && is_array($emailList) && count($emailList) > 0;
        
        $this->log("Has HTML template: " . ($hasHtmlTemplate ? 'yes' : 'no'));
        $this->log("Has email list: " . ($hasEmailList ? 'yes' : 'no'));
        
        if (!$hasHtmlTemplate || !$hasEmailList) {
            // Missing required files
            $message = "❌ Required files are missing.\n\n";
            
            if (!$hasHtmlTemplate) {
                $message .= "• HTML Template: *Missing*\n";
            } else {
                $message .= "• HTML Template: ✅ `{$htmlTemplateName}`\n";
            }
            
            if (!$hasEmailList) {
                $message .= "• Email List: *Missing*\n";
            } else {
                $message .= "• Email List: ✅ `{$emailListName}` (" . count($emailList) . " emails)\n";
            }
            
            $message .= "\nPlease upload the missing files before sending.";
            
            $keyboard = [
                [
                    ['text' => '📄 Upload HTML Letter', 'callback_data' => 'upload_html'],
                    ['text' => '📋 Upload Email List', 'callback_data' => 'upload_txt']
                ],
                [
                    ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
                ]
            ];
            
            $this->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            return;
        }
        
        // Check if SMTP is configured based on user plan
        $planId = isset($userData['plan']) ? $userData['plan'] : 'free';
        $userHasCustomSmtp = isset($userData['smtp']);
        $systemHasSmtp = !empty($this->smtps);
        
        // Determine SMTP status based on plan
        $smtpRequired = false;
        $smtpStatus = "";
        
        if ($planId === 'custom_smtp') {
            // Custom SMTP plan - user must configure their own SMTP
            $smtpRequired = true;
            $smtpStatus = $userHasCustomSmtp ? "✅ Custom SMTP Configured" : "❌ Custom SMTP Required";
            
            if (!$userHasCustomSmtp) {
                $message = "❌ You have the Custom SMTP plan which requires you to configure your own SMTP server.\n\n";
                $message .= "Please set up your SMTP configuration using the button below.";
                
                $keyboard = [
                    [
                        ['text' => '⚙️ Setup SMTP', 'callback_data' => 'smtp_setup']
                    ],
                    [
                        ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
                    ]
                ];
                
                $this->sendTelegramMessage($chatId, $message, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
                return;
            }
        } else if ($planId === 'free') {
            // Free plan - system SMTP with limitations
            $smtpStatus = $systemHasSmtp ? "✅ System SMTP (Limited)" : "❌ System SMTP Unavailable";
            
            if (!$systemHasSmtp) {
                $message = "❌ System SMTP is currently unavailable.\n\n";
                $message .= "Please try again later or upgrade to a premium plan.";
                
                $keyboard = [
                    [
                        ['text' => '🔓 Upgrade Plan', 'callback_data' => 'show_plans']
                    ],
                    [
                        ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
                    ]
                ];
                
                $this->sendTelegramMessage($chatId, $message, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
                return;
            }
        } else {
            // Premium plans - system SMTP with rotation
            $smtpStatus = $systemHasSmtp ? "✅ Premium SMTP Rotation" : "❌ System SMTP Unavailable";
            
            if (!$systemHasSmtp) {
                $message = "❌ Premium SMTP rotation is currently unavailable.\n\n";
                $message .= "Please contact support for assistance.";
                
                $keyboard = [
                    [
                        ['text' => '💬 Contact Support', 'callback_data' => 'contact_support']
                    ],
                    [
                        ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
                    ]
                ];
                
                $this->sendTelegramMessage($chatId, $message, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
                return;
            }
        }
        
        // Final SMTP check
        $hasSmtp = ($planId === 'custom_smtp' && $userHasCustomSmtp) || ($planId !== 'custom_smtp' && $systemHasSmtp);        
        if (!$hasSmtp) {
            $message = "❌ SMTP configuration issue detected.\n\n";
            $message .= "Please contact support for assistance.";
            
            $keyboard = [
                [
                    ['text' => '💬 Contact Support', 'callback_data' => 'contact_support']
                ],
                [
                    ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
                ]
            ];
            
            $this->sendTelegramMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            return;
        }
        
        // Check user plan limits
        $planId = isset($userData['plan']) ? $userData['plan'] : 'trial';
        $plans = defined('PLANS') ? PLANS : [];
        $plan = isset($plans[$planId]) ? $plans[$planId] : ['emails_per_hour' => 10, 'emails_per_day' => 50, 'emails_per_month' => 500];
        
        // Check if user has reached daily limit
        $emailsSentToday = isset($userData['emails_sent_today']) ? $userData['emails_sent_today'] : 0;
        $dailyLimit = $plan['emails_per_day'];
        $dailyRemaining = $dailyLimit == -1 ? 'Unlimited' : ($dailyLimit - $emailsSentToday);
        
        if ($plan['emails_per_day'] != -1 && $emailsSentToday >= $plan['emails_per_day']) {
            $this->sendTelegramMessage($chatId, "❌ You have reached your daily email limit.\n\nYour plan allows {$plan['emails_per_day']} emails per day. Please try again tomorrow or upgrade your plan.");
            return;
        }
        
        // START SENDING DIRECTLY (no confirmation needed)
        $this->log("Starting email sending directly from handleSendCommand");
        
        // Start sending immediately
        $this->startEmailSending($chatId);
    }
   
    
    /**
     * Send an email using the specified SMTP configuration
     * 
     * @param string $recipient Recipient email address
     * @param string $subject Email subject
     * @param string $htmlContent HTML content of the email
     * @param array $smtpConfig SMTP configuration
     * @param string $senderName Sender name
     * @return bool Success or failure
     */
    private function sendEmail($recipient, $subject, $htmlContent, $smtpConfig, $senderName = null) {
        // Keep this method to avoid breaking calls, but delegate to shared EmailSender for consistency
        $chatId = null; // not always available here; attachments are not used in this legacy path
        return $this->sendEmailViaSharedSender($chatId, $recipient, $subject, $htmlContent, $smtpConfig, $senderName);
    }

    private function sendEmailViaSharedSender($chatId, $recipient, $subject, $htmlContent, $smtpConfig, $senderName = null) {
        if (empty($recipient) || empty($htmlContent)) { $this->log("Missing parameters"); return false; }
        try {
            $userData = $chatId ? ($this->userData[$chatId] ?? []) : [];
            $sender = $this->getEmailSenderInstance($chatId ?? '', $userData);
            if (!$sender) { throw new \Exception('No sender'); }
            if ($chatId) { $sender->setCurrentChatId($chatId); }
            $replyTo = $userData['email_settings']['reply_to'] ?? null;
            $attachments = $userData['attachments'] ?? [];
            $options = [];
            if (!empty($attachments)) { $options['attachments'] = $attachments; }
            return $sender->send($recipient, $subject, $htmlContent, $replyTo, null, $options);
        } catch (\Throwable $e) {
            $this->log('sendEmailViaSharedSender error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Show email settings configuration menu
     * 
     * @param int $chatId The user's chat ID
     */
    private function showEmailSettings($chatId) {
        $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
        $planId = isset($userData['plan']) ? $userData['plan'] : 'free';
        
        // Get current settings
        $subject = isset($userData['email_subject']) ? $userData['email_subject'] : "Test email from @Cheto_inboxing_bot";
        $senderName = isset($userData['sender_name']) ? $userData['sender_name'] : "Not set";
        $hasSmtp = isset($userData['smtp']) || !empty($this->smtps);
        $smtpStatus = $hasSmtp ? "✅" : "❌";
        
        // Check if user has custom SMTP
        $hasCustomSmtp = isset($userData['smtp']);
        
        // Determine SMTP display text based on plan
        $smtpText = "SMTP Setup";
        if ($planId === 'custom_smtp') {
            $smtpText = $hasCustomSmtp ? "SMTP (✅ Custom)" : "SMTP (❌ Required)";
        } else if ($planId === 'free') {
            $smtpText = "SMTP (System)";
        } else {
            $smtpText = "SMTP (Premium)";
        }
        
        // Determine subject display text based on plan
        $subjectText = "Subject Line";
        if ($planId === 'free') {
            $subjectText = "Subject (🔒 Premium)";
        }
        
        $message = "⚙️ *Email Sending Settings*\n\n";
        $message .= "*Current Configuration:*\n";
        $message .= "• Subject: `{$subject}`\n";
        $message .= "• Sender Name: `{$senderName}`\n";
        $message .= "• SMTP: {$smtpStatus} " . ($planId === 'custom_smtp' ? "Custom" : ($planId === 'free' ? "System" : "Premium Rotation")) . "\n";
        $message .= "• Plan: {$planId}\n\n";
        $message .= "Configure your email sending parameters below:";
        
        $keyboard = [
            [
                ['text' => "📧 {$smtpText}", 'callback_data' => 'smtp_setup'],
                ['text' => "📝 {$subjectText}", 'callback_data' => 'subject_setup']
            ],
            [
                ['text' => '👤 Sender Name', 'callback_data' => 'sender_name_setup'],
                ['text' => '📎 Attachments', 'callback_data' => 'attachment_setup']
            ],
            [
                ['text' => '↩️ Reply-To', 'callback_data' => 'reply_setup']
            ],
            [
                ['text' => '▶️ Start Sending', 'callback_data' => 'start_sending']
            ],
            [
                ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }
    

    
    /**
     * Update user stats at end of sending
     * 
     * @param int $chatId The chat ID to update stats for
     * @param int $sent Number of emails sent
     * @param int $failed Number of emails failed
     * @param int $startTime Start timestamp
     * @param int $endTime End timestamp
     */
    private function updateUserStats($chatId, $sent, $failed, $startTime, $endTime) {
        $userData = $this->getUserData($chatId);
        $userData['sending_state'] = 'completed';
        $userData['sending_completed'] = $endTime;
        $userData['last_sent'] = $endTime;
        $userData['sending_stats'] = [
            'total_sent' => $sent,
            'failed' => $failed,
            'runtime' => $endTime - $startTime,
            'success_rate' => $sent > 0 ? round((($sent - $failed) / $sent) * 100) : 0
        ];
        $this->userData[$chatId] = $userData;
        $this->saveUserData();
    }

    /**
     * Initialize sending stats for a user
     * 
     * @param string $chatId Chat ID
     * @return array User data
     */
    private function initSendingStats($chatId) {
        if (!isset($this->userData[$chatId]['sending_stats'])) {
            $this->userData[$chatId]['sending_stats'] = [
                'emails_sent_today' => 0,
                'emails_sent_hour' => 0,
                'emails_sent_month' => 0,
                'last_sent' => 0,
                'sending_state' => null,
                'sending_progress' => 0
            ];
        }
        return $this->userData[$chatId];
    }

    /**
     * Handle broadcast message
     * 
     * @param string $chatId Chat ID
     * @param string $text Full command text
     */
    private function handleBroadcast($chatId, $text) {
        // Extract message from command
        $message = trim(substr($text, strlen('/broadcast')));
        
        if (empty($message)) {
            $this->sendTelegramMessage($chatId, "Please provide a message to broadcast:\n/broadcast Your message here");
            return;
        }
        
        // Count users
        $userCount = count($this->userData);
        
        if ($userCount === 0) {
            $this->sendTelegramMessage($chatId, "No users to broadcast to.");
            return;
        }
        
        // Confirm broadcast
        $this->sendTelegramMessage($chatId, "Broadcasting message to {$userCount} users...");
        
        // Send to all users
        $sent = 0;
        foreach ($this->userData as $userId => $data) {
            try {
                $this->sendTelegramMessage($userId, "📢 *BROADCAST*\n\n{$message}", ['parse_mode' => 'Markdown']);
                $sent++;
            } catch (Exception $e) {
                // Ignore errors
            }
            
            // Add a small delay
            usleep(100000); // 0.1 seconds
        }
        
        // Send completion message
        $this->sendTelegramMessage($chatId, "✅ Broadcast completed!\n\nMessage sent to {$sent} out of {$userCount} users.");
    }

    /**
     * Show analytics
     * 
     * @param string $chatId Chat ID
     */
    /**
     * Handle SMTP management for admins
     * 
     * @param string $chatId Chat ID
     */
    private function handleSmtpManagement($chatId) {
        // Count total SMTPs
        $totalSmtps = count($this->smtps);
        
        $message = "📧 *SMTP Management*\n\n";
        $message .= "Total SMTPs: {$totalSmtps}\n\n";
        
        if ($this->currentSmtp) {
            $message .= "*Current SMTP:*\n";
            $message .= "• Server: {$this->currentSmtp['host']}:{$this->currentSmtp['port']}\n";
            $message .= "• Username: {$this->currentSmtp['username']}\n";
            $message .= "• From: {$this->currentSmtp['from_email']}\n\n";
        }
        
        $message .= "*Performance:*\n";
        $message .= "• Rotations today: " . ($this->smtpRotationStats['rotation_count'] ?? 0) . "\n";
        $message .= "• Current errors: " . ($this->smtpRotationStats['current_error_count'] ?? 0) . "\n";
        $message .= "• Total failures: " . ($this->smtpRotationStats['failure_count'] ?? 0) . "\n";
        
        $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
    }

    /**
     * Handle users management for admins
     * 
     * @param string $chatId Chat ID
     */
    private function handleUsers($chatId) {
        $totalUsers = count($this->userData);
        $activeUsers = 0;
        $trialUsers = 0;
        $premiumUsers = 0;
        
        foreach ($this->userData as $userId => $data) {
            if (isset($data['last_active']) && $data['last_active'] > (time() - 86400)) {
                $activeUsers++;
            }
            if ((isset($data['plan']) ? $data['plan'] : 'trial') === 'trial') {
                $trialUsers++;
            } else {
                $premiumUsers++;
            }
        }
        
        $message = "👥 *User Management*\n\n";
        $message .= "*Statistics:*\n";
        $message .= "• Total users: {$totalUsers}\n";
        $message .= "• Active (24h): {$activeUsers}\n";
        $message .= "• Trial users: {$trialUsers}\n";
        $message .= "• Premium users: {$premiumUsers}\n\n";
        
        $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);
    }

    /**
     * Show plan management interface for admins
     * 
     * @param string $chatId Chat ID
     */
    private function showSetPlanMenu($chatId) {
        if (!$this->isAdmin($chatId)) {
            $this->sendTelegramMessage($chatId, "❌ You are not authorized to manage plans.");
            return;
        }

        $message = "🎯 *Set User Plan*\n\n";
        $message .= "To set a user's plan, use the command:\n";
        $message .= "`/setplan <chat_id> <plan_id> [duration_days]`\n\n";
        $message .= "*Available Plans:*\n";

        $plans = defined('PLANS') ? PLANS : [];
        foreach ($plans as $planId => $plan) {
            $message .= "\n`{$planId}`:\n";
            $message .= "• Name: {$plan['name']}\n";
            $message .= "• Duration: " . ($plan['duration'] == -1 ? "Unlimited" : "{$plan['duration']} days") . "\n";
            $message .= "• Daily Limit: " . ($plan['emails_per_day'] == -1 ? "Unlimited" : $plan['emails_per_day']) . "\n";
        }

        $message .= "\n*Example:*\n";
        $message .= "`/setplan 123456789 premium 30`";

        $keyboard = [
            [
                ['text' => '👥 List Users', 'callback_data' => 'admin_list_users'],
                ['text' => '📊 Plan Stats', 'callback_data' => 'admin_plan_stats']
            ],
            [
                ['text' => '🔙 Back to Admin', 'callback_data' => 'admin_panel']
            ]
        ];

        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Handle setting a user's plan
     * 
     * @param string $chatId Admin's chat ID
     * @param array $params Command parameters
     */
    private function handleSetPlan($chatId, $params) {
        if (!$this->isAdmin($chatId)) {
            $this->sendTelegramMessage($chatId, "❌ You are not authorized to manage plans.");
            return;
        }

        if (count($params) < 3) {
            $this->sendTelegramMessage($chatId, "❌ Incorrect format. Use:\n/setplan <chat_id> <plan_id> [duration_days]");
            return;
        }

        $targetChatId = $params[1];
        $planId = $params[2];
        $duration = isset($params[3]) ? intval($params[3]) : null;

        // Validate plan
        $plans = defined('PLANS') ? PLANS : [];
        if (!isset($plans[$planId])) {
            $this->sendTelegramMessage($chatId, "❌ Invalid plan ID. Available plans: " . implode(', ', array_keys($plans)));
            return;
        }

        // Get user data
        if (!isset($this->userData[$targetChatId])) {
            $this->sendTelegramMessage($chatId, "❌ User not found with chat ID: {$targetChatId}");
            return;
        }

        // Update user's plan
        $plan = $plans[$planId];
        $this->userData[$targetChatId]['plan'] = $planId;
        $this->userData[$targetChatId]['plan_expires'] = time() + (isset($duration) ? $duration : $plan['duration']) * 86400;
        $this->saveUserData();

        // Send confirmation messages
        $message = "✅ Plan updated successfully!\n\n";
        $message .= "User: {$targetChatId}\n";
        $message .= "Plan: {$plan['name']}\n";
        $message .= "Duration: " . (isset($duration) ? $duration : $plan['duration']) . " days\n";
        $message .= "Expires: " . date('Y-m-d H:i:s', $this->userData[$targetChatId]['plan_expires']);

        $this->sendTelegramMessage($chatId, $message);

        // Notify user
        $userMessage = "🎉 *Your Plan Has Been Updated!*\n\n";
        $userMessage .= "New Plan: *{$plan['name']}*\n";
        $userMessage .= "Duration: " . (isset($duration) ? $duration : $plan['duration']) . " days\n";
        $userMessage .= "Expires: " . date('Y-m-d H:i:s', $this->userData[$targetChatId]['plan_expires']) . "\n\n";
        $userMessage .= "Enjoy your new features! Type /status to see your updated limits.";

        $this->sendTelegramMessage($targetChatId, $userMessage, ['parse_mode' => 'Markdown']);
    }

    /**
     * Show list of users with their plans
     * 
     * @param string $chatId Admin's chat ID
     */
    private function showUserList($chatId) {
        if (!$this->isAdmin($chatId)) {
            $this->sendTelegramMessage($chatId, "❌ You are not authorized to view user list.");
            return;
        }

        $message = "👥 *User List*\n\n";
        $count = 0;

        foreach ($this->userData as $userId => $data) {
            $planId = isset($data['plan']) ? $data['plan'] : 'trial';
            $expires = isset($data['plan_expires']) ? date('Y-m-d', $data['plan_expires']) : 'N/A';
            $active = isset($data['last_active']) && $data['last_active'] > (time() - 86400);

            $message .= "`{$userId}`:\n";
            $message .= "• Plan: " . ucfirst($planId) . "\n";
            $message .= "• Expires: {$expires}\n";
            $message .= "• Status: " . ($active ? "🟢 Active" : "⚪ Inactive") . "\n\n";

            $count++;
            if ($count >= 10) {
                $message .= "... and " . (count($this->userData) - 10) . " more users";
                break;
            }
        }

        $keyboard = [
            [
                ['text' => '🔄 Refresh', 'callback_data' => 'admin_list_users'],
                ['text' => '🎯 Set Plan', 'callback_data' => 'admin_set_plan']
            ],
            [
                ['text' => '🔙 Back to Admin', 'callback_data' => 'admin_panel']
            ]
        ];

        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Show plan statistics for admins
     * 
     * @param string $chatId Admin's chat ID
     */
    private function showPlanStats($chatId) {
        if (!$this->isAdmin($chatId)) {
            $this->sendTelegramMessage($chatId, "❌ You are not authorized to view plan statistics.");
            return;
        }

        $plans = defined('PLANS') ? PLANS : [];
        $stats = [];
        $totalUsers = 0;
        $activeUsers = 0;

        // Initialize stats array
        foreach ($plans as $planId => $plan) {
            $stats[$planId] = [
                'total' => 0,
                'active' => 0,
                'expired' => 0
            ];
        }
        $stats['trial'] = ['total' => 0, 'active' => 0, 'expired' => 0];

        // Calculate statistics
        foreach ($this->userData as $userId => $data) {
            $totalUsers++;
            $planId = isset($data['plan']) ? $data['plan'] : 'trial';
            $isActive = isset($data['last_active']) && $data['last_active'] > (time() - 86400);
            $isExpired = isset($data['plan_expires']) && $data['plan_expires'] < time();

            if ($isActive) {
                $activeUsers++;
                $stats[$planId]['active']++;
            }

            $stats[$planId]['total']++;
            if ($isExpired) {
                $stats[$planId]['expired']++;
            }
        }

        // Build message
        $message = "📊 *Plan Statistics*\n\n";
        $message .= "*Overall:*\n";
        $message .= "• Total Users: {$totalUsers}\n";
        $message .= "• Active Users: {$activeUsers}\n\n";
        $message .= "*Plan Distribution:*\n";

        foreach ($stats as $planId => $planStats) {
            if ($planStats['total'] > 0) {
                $planName = isset($plans[$planId]) ? $plans[$planId]['name'] : ucfirst($planId);
                $message .= "\n`{$planName}:`\n";
                $message .= "• Total: {$planStats['total']}\n";
                $message .= "• Active: {$planStats['active']}\n";
                $message .= "• Expired: {$planStats['expired']}\n";
            }
        }

        $keyboard = [
            [
                ['text' => '👥 List Users', 'callback_data' => 'admin_list_users'],
                ['text' => '🎯 Set Plan', 'callback_data' => 'admin_set_plan']
            ],
            [
                ['text' => '🔙 Back to Admin', 'callback_data' => 'admin_panel']
            ]
        ];

        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Show pending plan orders for admin
     * 
     * @param string $chatId Admin's chat ID
     */
    private function showPendingOrders($chatId) {
        if (!$this->isAdmin($chatId)) {
            $this->sendTelegramMessage($chatId, "❌ You are not authorized to view pending orders.");
            return;
        }

        $pendingOrders = [];
        foreach ($this->userData as $userId => $data) {
            if (isset($data['pending_order']) && $data['pending_order']['status'] === 'pending') {
                $pendingOrders[$userId] = $data['pending_order'];
            }
        }

        if (empty($pendingOrders)) {
            $this->sendTelegramMessage($chatId, "📝 No pending orders at the moment.");
            return;
        }

        $message = "📋 *Pending Plan Orders*\n\n";
        foreach ($pendingOrders as $userId => $order) {
            $plans = defined('PLANS') ? PLANS : [];
            $plan = isset($plans[$order['plan_id']]) ? $plans[$order['plan_id']] : ['name' => 'Unknown Plan'];
            
            $message .= "*Order ID:* `{$order['order_id']}`\n";
            $message .= "User ID: `{$userId}`\n";
            $message .= "Plan: {$plan['name']}\n";
            $message .= "Requested: " . date('Y-m-d H:i:s', $order['timestamp']) . "\n\n";
        }

        $keyboard = [];
        foreach ($pendingOrders as $userId => $order) {
            $keyboard[] = [
                ['text' => '✅ Approve #' . substr($order['order_id'], -6), 'callback_data' => 'approve_order_' . $order['order_id']],
                ['text' => '❌ Reject #' . substr($order['order_id'], -6), 'callback_data' => 'reject_order_' . $order['order_id']]
            ];
        }
        $keyboard[] = [['text' => '🔙 Back to Admin', 'callback_data' => 'admin_panel']];

        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Handle payment confirmation from user
     * 
     * @param string $chatId User's chat ID
     * @param string $orderId Order ID
     */
    private function handlePaymentConfirmation($chatId, $orderId) {
        $userData = $this->getUserData($chatId);
        
        if (!isset($userData['pending_order']) || 
            $userData['pending_order']['order_id'] !== $orderId || 
            $userData['pending_order']['status'] !== 'pending') {
            $this->sendTelegramMessage($chatId, "❌ Invalid or expired order.");
            return;
        }

        // Update order status
        $userData['pending_order']['status'] = 'payment_confirmed';
        $this->userData[$chatId] = $userData;
        $this->saveUserData(); // Save immediately after updating

        // Notify all admins
        foreach ($this->adminIds as $adminId) {
            $message = "💰 *New Payment Confirmation*\n\n";
            $message .= "Order ID: `{$orderId}`\n";
            $message .= "User ID: `{$chatId}`\n";
            $message .= "Plan: {$userData['pending_order']['plan_id']}\n\n";
            $message .= "Please verify the payment and approve/reject the order.";

            $keyboard = [
                [
                    ['text' => '✅ Approve', 'callback_data' => 'approve_order_' . $orderId],
                    ['text' => '❌ Reject', 'callback_data' => 'reject_order_' . $orderId]
                ],
                [
                    ['text' => '👥 View All Orders', 'callback_data' => 'admin_pending_orders']
                ]
            ];

            $this->sendTelegramMessage($adminId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
        }

        $this->sendTelegramMessage($chatId, "✅ Thank you! Your payment confirmation has been sent to the administrators.\nThey will verify and activate your plan soon.");
    }

    /**
     * Handle order approval by admin
     * 
     * @param string $chatId Admin's chat ID
     * @param string $orderId Order ID
     */
    private function handleOrderApproval($chatId, $orderId) {
        if (!$this->isAdmin($chatId)) {
            return;
        }

        // Find user with this order
        $targetUserId = null;
        $orderData = null;
        foreach ($this->userData as $userId => $data) {
            if (isset($data['pending_order']) && $data['pending_order']['order_id'] === $orderId) {
                $targetUserId = $userId;
                $orderData = $data['pending_order'];
                break;
            }
        }

        if (!$targetUserId || !$orderData) {
            $this->sendTelegramMessage($chatId, "❌ Order not found.");
            return;
        }

        // Get plan details
        $plans = defined('PLANS') ? PLANS : [];
        if (!isset($plans[$orderData['plan_id']])) {
            $this->sendTelegramMessage($chatId, "❌ Invalid plan ID in order.");
            return;
        }

        $plan = $plans[$orderData['plan_id']];
        
    // Update user's plan
    $this->userData[$targetUserId]['plan'] = $orderData['plan_id'];
    $this->userData[$targetUserId]['plan_expires'] = time() + ($plan['duration'] * 86400);
    // Clear expiration flags
    unset($this->userData[$targetUserId]['is_expired']);
    unset($this->userData[$targetUserId]['expired_notified_at']);
    $this->userData[$targetUserId]['settings']['premium'] = true;
    // Reset counters and set reset anchors for new cycle
    $this->userData[$targetUserId]['emails_sent_hour'] = 0;
    $this->userData[$targetUserId]['emails_sent_today'] = 0;
    $this->userData[$targetUserId]['emails_sent_month'] = 0;
    $this->userData[$targetUserId]['last_hour_reset'] = strtotime(date('Y-m-d H:00:00'));
    $this->userData[$targetUserId]['last_day_reset'] = strtotime(date('Y-m-d'));
    $this->userData[$targetUserId]['last_month_reset'] = strtotime(gmdate('Y-m-01 00:00:00'));
        unset($this->userData[$targetUserId]['pending_order']);
        $this->saveUserData();

        // Notify admin
        $this->sendTelegramMessage($chatId, "✅ Order {$orderId} has been approved and plan activated.");

        // Notify user
        $message = "🎉 *Congratulations! Your Plan is Active*\n\n";
        $message .= "Your payment has been confirmed and your new plan is now active!\n\n";
        $message .= "*Plan Details:*\n";
        $message .= "• Name: {$plan['name']}\n";
        $message .= "• Duration: {$plan['duration']} days\n";
        $message .= "• Expires: " . date('Y-m-d H:i:s', $this->userData[$targetUserId]['plan_expires']) . "\n\n";
        $message .= "Type /status to see your new limits.";

        $this->sendTelegramMessage($targetUserId, $message, ['parse_mode' => 'Markdown']);
    }

    /**
     * Handle order rejection by admin
     * 
     * @param string $chatId Admin's chat ID
     * @param string $orderId Order ID
     */
    private function handleOrderRejection($chatId, $orderId) {
        if (!$this->isAdmin($chatId)) {
            return;
        }

        // Find user with this order
        $targetUserId = null;
        foreach ($this->userData as $userId => $data) {
            if (isset($data['pending_order']) && $data['pending_order']['order_id'] === $orderId) {
                $targetUserId = $userId;
                unset($this->userData[$userId]['pending_order']);
                break;
            }
        }

        if (!$targetUserId) {
            $this->sendTelegramMessage($chatId, "❌ Order not found.");
            return;
        }

        $this->saveUserData();

        // Notify admin
        $this->sendTelegramMessage($chatId, "❌ Order {$orderId} has been rejected.");

        // Notify user
        $message = "❌ *Plan Order Update*\n\n";
        $message .= "Your plan order has been rejected.\n";
        $message .= "Please contact support for more information.\n\n";
        $message .= "Order ID: `{$orderId}`";

        $keyboard = [
            [
                ['text' => '💬 Contact Support', 'url' => 'https://t.me/Ninja111'],
                ['text' => '🔄 Try Again', 'callback_data' => 'plans']
            ]
        ];

        $this->sendTelegramMessage($targetUserId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    // This function has been moved to line 2659 to fix duplicate declaration
    
    // This function has been moved to line 2694 to fix duplicate declaration
    
    /**
     * Handle HTML letter upload button click
     * 
     * @param string $chatId Chat ID
     */
    private function handleHtmlUpload($chatId) {
        // Set user state to waiting for HTML file
        $this->userData[$chatId]['state'] = 'waiting_for_html';
        $this->saveUserData();
        
        $message = "📄 *Upload HTML Letter*\n\n";
        $message .= "Please send your HTML email template file (.html)\n\n";
        $message .= "*Supported Placeholders:*\n";
        $message .= "• {{EMAIL}} - Recipient's email\n";
        $message .= "• {{NAME}} - Recipient's name\n";
        $message .= "• {{COMPANY}} - Recipient's company\n";
        $message .= "• {{DOMAIN}} - Recipient's domain\n";
        $message .= "• {{DATE}} - Current date\n";
        $message .= "• {{TIME}} - Current time\n";
        
        $keyboard = [
            [
                ['text' => '🔄 View All Placeholders', 'callback_data' => 'placeholders']
            ],
            [
                ['text' => '🔙 Cancel', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Handle TXT list upload button click
     * 
     * @param string $chatId Chat ID
     */
    private function handleTxtUpload($chatId) {
        // Set user state to waiting for TXT file
        $this->userData[$chatId]['state'] = 'waiting_for_txt';
        $this->saveUserData();
        
        $message = "📋 *Upload Email List*\n\n";
        $message .= "Please send your email list file (.txt)\n\n";
        $message .= "*Supported Formats:*\n";
        $message .= "• email@example.com\n";
        $message .= "• name,email@example.com\n";
        $message .= "• name,email@example.com,company\n";
        $message .= "• email@example.com|name|company\n";
        
        $keyboard = [
            [
                ['text' => '🔙 Cancel', 'callback_data' => 'email_setup']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }
    
    /**
     * Handle document/file messages from users
     * 
     * @param array $message The message data from Telegram
     */
    private function handleDocumentMessage($message) {
        $chatId = $message['chat']['id'];
        $document = $message['document'];
        $fileName = $document['file_name'];
        $fileId = $document['file_id'];
        
        // Check user's current state
        $state = $this->userData[$chatId]['state'] ?? '';
        
        if ($state === 'waiting_for_html') {
            // Check file extension
            if (!preg_match('/\.html?$/i', $fileName)) {
                $this->sendTelegramMessage($chatId, "⚠️ Please upload an HTML file (.html or .htm)");
                return;
            }
            
            try {
                // Get file path
                $filePath = $this->downloadTelegramFile($fileId, $fileName);
                
                // Store the file path in user data
                $this->userData[$chatId]['html_template_path'] = $filePath;
                $this->userData[$chatId]['state'] = '';
                $this->saveUserData();
                
                $this->sendTelegramMessage($chatId, "✅ HTML template uploaded successfully!");
                $this->showEmailSetupMenu($chatId);
                
            } catch (Exception $e) {
                $this->log("Error processing HTML upload: " . $e->getMessage());
                $this->sendTelegramMessage($chatId, "⚠️ Failed to process HTML file. Please try again.");
            }
            
        } elseif ($state === 'waiting_for_txt') {
            // Check file extension
            if (!preg_match('/\.txt$/i', $fileName)) {
                $this->sendTelegramMessage($chatId, "⚠️ Please upload a text file (.txt)");
                return;
            }
            
            try {
                // Get file path
                $filePath = $this->downloadTelegramFile($fileId, $fileName);
                
                // Store the file path in user data
                $this->userData[$chatId]['email_list_path'] = $filePath;
                $this->userData[$chatId]['state'] = '';
                $this->saveUserData();
                
                $this->sendTelegramMessage($chatId, "✅ Email list uploaded successfully!");
                $this->showEmailSetupMenu($chatId);
                
            } catch (Exception $e) {
                $this->log("Error processing email list upload: " . $e->getMessage());
                $this->sendTelegramMessage($chatId, "⚠️ Failed to process email list. Please try again.");
            }
        }
    }
    
    /**
     * Download a file from Telegram
     * 
     * @param string $fileId The file ID from Telegram
     * @param string $fileName The original file name
     * @return string The path to the downloaded file
     * @throws Exception If file download fails
     */
    private function downloadTelegramFile($fileId, $fileName) {
        try {
            // Get file path from Telegram
            $response = file_get_contents("https://api.telegram.org/bot{$this->botToken}/getFile?file_id={$fileId}");
            $data = json_decode($response, true);
            
            if (!$data['ok']) {
                throw new Exception("Failed to get file path from Telegram");
            }
            
            $filePath = $data['result']['file_path'];
            
            // Create unique filename
            $uniqueName = uniqid() . '_' . bin2hex(random_bytes(4));
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $newFileName = "{$extension}_{$uniqueName}.{$extension}";
            
            // Download file
            $telegramFile = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
            $localPath = __DIR__ . "/../uploads/{$newFileName}";
            
            if (!copy($telegramFile, $localPath)) {
                throw new Exception("Failed to save file locally");
            }
            
            return $localPath;
            
        } catch (Exception $e) {
            $this->log("Error downloading file from Telegram: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process HTML letter upload
     * 
     * @param string $chatId Chat ID
     * @param array $message Message data
     */
    private function processHtmlLetterUpload($chatId, $message) {
        // Check if message contains a document
        if (!isset($message['document'])) {
            $this->sendTelegramMessage($chatId, "❌ Please send an HTML file (.html).");
            return;
        }
        
        $document = $message['document'];
        $fileName = isset($document['file_name']) ? $document['file_name'] : '';
        $fileId = $document['file_id'];
        
        // Check if it's an HTML file
        if (!preg_match('/\.html?$/i', $fileName)) {
            $this->sendTelegramMessage($chatId, "❌ Please send an HTML file (.html).");
            return;
        }
        
        // Get file path
        $fileInfo = $this->getFile($fileId);
        if (!$fileInfo || !is_array($fileInfo) || empty($fileInfo['result']) || empty($fileInfo['result']['file_path'])) {
            $this->log("Failed to get file info for HTML upload: " . json_encode($fileInfo));
            $this->sendTelegramMessage($chatId, "❌ Failed to retrieve the file. Please try again.");
            return;
        }
        
        $filePath = $fileInfo['result']['file_path'];
        $fileUrl = $this->fileApiUrl . '/' . $filePath;
        
        // Download the file
        $this->log("Downloading HTML from URL: {$fileUrl}");
        $htmlContent = @file_get_contents($fileUrl);
        if ($htmlContent === false) {
            $error = error_get_last();
            $this->log("Failed to download HTML: " . ($error ? $error['message'] : 'Unknown error'));
            $this->sendTelegramMessage($chatId, "❌ Failed to download the file. Please try again.");
            return;
        }
        
        // Save the HTML template to user data
        $this->userData[$chatId]['html_template'] = $htmlContent;
        $this->userData[$chatId]['html_template_name'] = $fileName;
        
        // Save HTML template to uploads directory
        $uploadsDir = __DIR__ . '/../uploads';
        if (!is_dir($uploadsDir)) {
            if (!@mkdir($uploadsDir, 0755, true)) {
                $error = error_get_last();
                $this->log("Failed to create uploads directory: " . ($error ? $error['message'] : 'Unknown error'));
                $this->sendTelegramMessage($chatId, "❌ Failed to save the template. Please try again.");
                return;
            }
        }
        
        // Generate unique filename
        $timestamp = time();
        $randStr = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
        $filePath = $uploadsDir . '/html_template_' . $chatId . '_' . $timestamp . '_' . $randStr . '.html';
        
        // Save file with proper permissions
        if (@file_put_contents($filePath, $htmlContent) === false) {
            $error = error_get_last();
            $this->log("Failed to save HTML template: " . ($error ? $error['message'] : 'Unknown error'));
            $this->sendTelegramMessage($chatId, "❌ Failed to save the template. Please try again.");
            return;
        }
        
        // Set secure permissions
        chmod($filePath, 0644);
        
        // Update user data
        $this->userData[$chatId]['html_template_path'] = $filePath;
        $this->log("Saved HTML template to path: {$filePath}");
        
        $this->userData[$chatId]['state'] = 'idle';
        $this->saveUserData();
        
        // Check for placeholders in the HTML
        $placeholders = [];
        if (preg_match_all('/\{\{([A-Z_]+)\}\}/', $htmlContent, $matches)) {
            $placeholders = array_unique($matches[1]);
        }
        
        // Prepare response message
        $message = "✅ *HTML Letter Uploaded Successfully*\n\n";
        $message .= "Filename: `{$fileName}`\n";
        $message .= "Size: " . strlen($htmlContent) . " bytes\n\n";
        
        if (!empty($placeholders)) {
            $message .= "*Detected Placeholders:*\n";
            foreach ($placeholders as $placeholder) {
                $message .= "• {{$placeholder}}\n";
            }
            $message .= "\n";
        }
        
        $message .= "Your HTML letter has been saved and is ready to use.";
        
        $keyboard = [
            [
                ['text' => '📋 Upload Email List', 'callback_data' => 'upload_txt'],
                ['text' => '🔄 Placeholders', 'callback_data' => 'placeholders']
            ],
            [
                ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Process TXT list upload
     * 
     * @param string $chatId Chat ID
     * @param array $message Message data
     */
    private function processTxtListUpload($chatId, $message) {
        // Check if message contains a document
        if (!isset($message['document'])) {
            $this->sendTelegramMessage($chatId, "❌ Please send a TXT file (.txt).");
            return;
        }
        
        $document = $message['document'];
        $fileName = isset($document['file_name']) ? $document['file_name'] : '';
        $fileId = $document['file_id'];
        
        // Check if it's a TXT file
        if (!preg_match('/\.txt$/i', $fileName)) {
            $this->sendTelegramMessage($chatId, "❌ Please send a TXT file (.txt).");
            return;
        }
        
        // Get file path
        $fileInfo = $this->getFile($fileId);
        if (!$fileInfo || !is_array($fileInfo) || empty($fileInfo['result']) || empty($fileInfo['result']['file_path'])) {
            $this->log("Failed to get file info for TXT upload: " . json_encode($fileInfo));
            $this->sendTelegramMessage($chatId, "❌ Failed to retrieve the file. Please try again.");
            return;
        }
        
        $filePath = $fileInfo['result']['file_path'];
        $fileUrl = $this->fileApiUrl . '/' . $filePath;
        
        // Download the file
        $this->log("Downloading TXT from URL: {$fileUrl}");
        $txtContent = @file_get_contents($fileUrl);
        if ($txtContent === false) {
            $error = error_get_last();
            $this->log("Failed to download TXT: " . ($error ? $error['message'] : 'Unknown error'));
            $this->sendTelegramMessage($chatId, "❌ Failed to download the file. Please try again.");
            return;
        }
        
        // Parse the email list
        $lines = explode("\n", $txtContent);
        $validLines = 0;
        $emailList = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Try different formats
            if (strpos($line, ',') !== false) {
                // CSV format
                $parts = explode(',', $line);
                $email = trim($parts[0]);
                $name = isset($parts[1]) ? trim($parts[1]) : '';
                $company = isset($parts[2]) ? trim($parts[2]) : '';
            } elseif (strpos($line, '|') !== false) {
                // Pipe-separated format
                $parts = explode('|', $line);
                $email = trim($parts[0]);
                $name = isset($parts[1]) ? trim($parts[1]) : '';
                $company = isset($parts[2]) ? trim($parts[2]) : '';
            } else {
                // Just email
                $email = trim($line);
                $name = '';
                $company = '';
            }
            
            // Validate email
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emailList[] = [
                    'email' => $email,
                    'name' => $name,
                    'company' => $company,
                    'domain' => substr(strrchr($email, "@"), 1)
                ];
                $validLines++;
            }
        }
        
        // Save the email list to user data
        $this->userData[$chatId]['email_list'] = $emailList;
        $this->userData[$chatId]['email_list_name'] = $fileName;
        
        // Save email list to uploads directory
        $uploadsDir = __DIR__ . '/../uploads';
        if (!is_dir($uploadsDir)) {
            if (!@mkdir($uploadsDir, 0755, true)) {
                $error = error_get_last();
                $this->log("Failed to create uploads directory: " . ($error ? $error['message'] : 'Unknown error'));
                $this->sendTelegramMessage($chatId, "❌ Failed to save the email list. Please try again.");
                return;
            }
        }
        
        // Generate unique filename
        $timestamp = time();
        $randStr = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
        $filePath = $uploadsDir . '/email_list_' . $chatId . '_' . $timestamp . '_' . $randStr . '.txt';
        
        // Save file with proper permissions
        if (@file_put_contents($filePath, $txtContent) === false) {
            $error = error_get_last();
            $this->log("Failed to save email list: " . ($error ? $error['message'] : 'Unknown error'));
            $this->sendTelegramMessage($chatId, "❌ Failed to save the email list. Please try again.");
            return;
        }
        
        // Set secure permissions
        chmod($filePath, 0644);
        
        // Update user data
        $this->userData[$chatId]['email_list_path'] = $filePath;
        $this->log("Saved email list to path: {$filePath}");
        
        $this->userData[$chatId]['state'] = 'idle';
        $this->saveUserData();
        
        // Prepare response message
        $message = "✅ *Email List Uploaded Successfully*\n\n";
        $message .= "Filename: `{$fileName}`\n";
        $message .= "Total lines: " . count($lines) . "\n";
        $message .= "Valid emails: {$validLines}\n\n";
        
        if ($validLines > 0) {
            $message .= "Your email list has been saved and is ready to use.";
            
            $keyboard = [
                [
                    ['text' => '📄 Upload HTML Letter', 'callback_data' => 'upload_html'],
                    ['text' => '🔄 Placeholders', 'callback_data' => 'placeholders']
                ],
                [
                    ['text' => '📧 Start Sending', 'callback_data' => 'start_sending']
                ],
                [
                    ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
                ]
            ];
        } else {
            $message .= "❌ No valid emails found in the file. Please check your file format and try again.";
            
            $keyboard = [
                [
                    ['text' => '📋 Try Again', 'callback_data' => 'upload_txt'],
                    ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
                ]
            ];
        }
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    /**
     * Get file information from Telegram
     * 
     * @param string $fileId File ID
     * @return array|false File information or false on failure
     */
    private function getFile($fileId) {
        $url = $this->apiUrl . '/getFile';
        $data = [
            'file_id' => $fileId
        ];
        
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($data),
                'ignore_errors' => true // Get response even on error
            ]
        ];
        
        $context = stream_context_create($options);
        $this->log("Requesting file info from Telegram API: {$url}");
        
        // Suppress warnings to handle them manually
        $result = @file_get_contents($url, false, $context);
        
        if ($result === false) {
            $error = error_get_last();
            $this->log("Failed to get file from Telegram: " . ($error ? $error['message'] : 'Unknown error'));
            return false;
        }
        
        // Get response headers to check status
        $statusLine = $http_response_header[0];
        if (strpos($statusLine, '200') === false) {
            $this->log("Telegram API error: {$statusLine}");
            $this->log("Response: {$result}");
            return false;
        }
        
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            $this->log("Failed to decode Telegram response: " . json_last_error_msg());
            return false;
        }
        
        if (!isset($decoded['ok']) || $decoded['ok'] !== true) {
            $error = isset($decoded['description']) ? $decoded['description'] : 'Unknown error';
            $this->log("Telegram API returned error: {$error}");
            return false;
        }
        
        return $decoded;
    }

    private function showPlaceholders($chatId) {
        $message = "🔄 *Available Placeholders*\n\n";
        $message .= "Use these placeholders in your email subject and body:\n\n";
        $message .= "*Basic Placeholders:*\n";
        $message .= "• {{EMAIL}} - Recipient's email address\n";
        $message .= "• {{NAME}} - Recipient's name\n";
        $message .= "• {{COMPANY}} - Recipient's company\n";
        $message .= "• {{DOMAIN}} - Recipient's domain\n\n";
        
        $message .= "*Time & Date Placeholders:*\n";
        $message .= "• {{DATE}} - Current date (format: YYYY-MM-DD)\n";
        $message .= "• {{TIME}} - Current time (format: HH:MM)\n";
        $message .= "• {{DATETIME}} - Current date and time\n\n";
        
        $message .= "*Random Placeholders:*\n";
        $message .= "• {{RANDOM_STRING}} - Random alphanumeric string\n";
        $message .= "• {{RANDOM_NUMBER}} - Random number (1000-9999)\n";
        $message .= "• {{UUID}} - Unique identifier\n\n";
        
        $message .= "*Custom Placeholders:*\n";
        $message .= "You can also define custom placeholders in your email list.";
        
        $keyboard = [
            [
                ['text' => '📧 Email Setup', 'callback_data' => 'setup_menu'],
                ['text' => '📄 Upload HTML', 'callback_data' => 'upload_html']
            ],
            [
                ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    // showAnalytics method is defined earlier in the file

        // Format duration function is implemented elsewhere in the class
    // Process scheduled tasks function is implemented elsewhere in the class as processInternalTasks
    
    /**
     * Run a scheduled task
     * 
     * @param array $task The task to run
     * @deprecated Use executeInternalTask instead
     */
    private function runScheduledTask($task) {
        $this->log("Running scheduled task: " . $task['type']);
        
        switch ($task['type']) {
            case 'process_emails':
                if (isset($task['data']['chat_id'])) {
                    $chatId = $task['data']['chat_id'];
                    
                    // Check if user has paused sending
                    $userData = isset($this->userData[$chatId]) ? $this->userData[$chatId] : [];
                    if (isset($userData['is_paused']) && $userData['is_paused'] === true) {
                        $this->log("Email sending is paused for user {$chatId}, rescheduling task");
                        
                        // Reschedule the task for later
                        $this->scheduleInternalTask('process_emails', [
                            'chat_id' => $chatId
                        ], time() + 60); // Check again in 1 minute
                        
                        return;
                    }
                    
                    $this->processUserEmails($chatId);
                }
                break;
                
            // Add other task types here
                
            default:
                $this->log("Unknown task type: " . $task['type']);
                break;
        }
    }

    /**
     * Schedule a task for later execution
     * 
     * @param string $type The task type
     * @param array $data The task data
     * @param int $runAt Timestamp when to run the task
     * @deprecated Use scheduleInternalTask instead
     */
    private function scheduleTask($type, $data, $runAt) {
        // Forward to the new implementation
        $this->scheduleInternalTask($type, $data, $runAt);
        
        // Log the usage of deprecated method
        $this->log("Warning: Using deprecated scheduleTask method. Use scheduleInternalTask instead.");
    }

    /**
     * Duplicate function removed - The original saveInternalTasks is defined earlier in the file
     */
    // private function saveInternalTasks() { ... }
    
    /**
     * Save external scheduled tasks to file
     */
    private function saveExternalTasks() {
        $tasksFile = __DIR__ . '/data/scheduled_tasks.json';
        $tasksDir = dirname($tasksFile);
        
        if (!is_dir($tasksDir)) {
            mkdir($tasksDir, 0777, true);
        }
        
        file_put_contents($tasksFile, json_encode($this->scheduledTasks, JSON_PRETTY_PRINT));
    }

    /**
     * Load external scheduled tasks from file
     */
    private function loadExternalTasks() {
        $tasksFile = __DIR__ . '/data/scheduled_tasks.json';
        
        if (file_exists($tasksFile)) {
            $content = file_get_contents($tasksFile);
            $tasks = json_decode($content, true);
            
            if (is_array($tasks)) {
                $this->scheduledTasks = $tasks;
            }
        }
    }

    /**
     * Process internal scheduled tasks
     */
    private function processInternalTasks() {
        $this->loadInternalTasks();
        
        $now = time();
        $updatedTasks = [];
        
        foreach ($this->scheduledTasks as $task) {
            if ($task['run_at'] <= $now) {
                // Task is due to run
                $this->executeInternalTask($task);
            } else {
                // Task is not yet due, keep it for later
                $updatedTasks[] = $task;
            }
        }
        
        // Update the task list with only the remaining tasks
        $this->scheduledTasks = $updatedTasks;
        $this->saveInternalTasks();
    }
}
