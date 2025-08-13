<?php

namespace App;

use \Exception;
use \PHPMailer\PHPMailer\PHPMailer;
use \PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Email Sender Class
 * 
 * Handles sending emails with PHPMailer
 */
class EmailSender {
    // SMTP Configuration
    private $smtpConfigs = [];      // Array of SMTP configurations
    private $currentSmtpIndex = 0;  // Current SMTP configuration index
    private $emailsPerRotation = 5; // Number of emails before rotating SMTP
    private $emailsSentWithCurrentSmtp = 0;
    private $mailer;
    private $replyTo;
    private $bot;
    private $currentChatId;
    private $mailerPool = []; // Cached PHPMailer instances per SMTP key

    // Basic tracking
    private $campaignStartTime = null;
    private $lastProgressUpdate = 0;
    private $batchSize = 0;
    private $batchSuccessCount = 0;
    
    // Performance metrics
    private $smtpStats = [];
    private $currentSmtpStartTime = 0;
    private $lastSmtpReport = 0;
    private $smtpReportInterval = 300;
    private $retryCount = 0;
    private $maxRetries = 3;
    private $backoffTime = 1;
    private $notificationRetries = 2;
    private $errorCount = 0;           // Track consecutive errors
    private $relaxTlsVerification = false; // If true, disable strict SSL checks on next connect
    // Connection failure handling
    private $smtpFailureStreaks = []; // smtpKey => consecutive connection-like failures
    private $connectionAdvisoryShown = []; // smtpKey => bool to avoid spamming advice
    
    // Rotation strategy and health
    private $rotationStrategy = 'weighted_random'; // 'round_robin' | 'random' | 'weighted_random'
    private $smtpCooldowns = []; // smtpKey => unix timestamp until usable
    private $minCooldownSeconds = 300; // 5 minutes
    private $maxCooldownSeconds = 1800; // 30 minutes
    private $debugLogging = false;
    private $smtpSuspicion = []; // smtpKey => suspicion score
    private $endpointOverrides = []; // smtpKey => ['port'=>int,'encryption'=>string]
    private $alternateEndpointCache = []; // smtpKey => array of endpoints
    private $endpointCursor = []; // smtpKey => index of next endpoint to try
    private $endpointPreferences = []; // host:port => ['encryption' => 'tls|ssl|'] persisted
    private $endpointPrefsLoaded = false;

    // Health monitoring and configurations
    private $smtpHealthMetrics = [];
    private $securityConfig;
    private $recoveryConfig;
    private $performanceConfig;
    private $campaignConfig;
    private $monitoringConfig;
    private $antispamConfig;
    private $queueConfig;
    private $complianceConfig;

    // Rate limiting
    private $userEmailCounts = [];      // Track emails per user
    private $dailyEmailCount = 0;       // Total emails sent today
    private $hourlyEmailCount = 0;      // Emails sent in current hour
    private $lastDailyReset = 0;        // Last time daily count was reset
    private $lastHourlyReset = 0;       // Last time hourly count was reset

    // Plan configuration
    private $plans = [
        'trial' => [
            'daily_limit' => 500,
            'hourly_limit' => 100,
            'concurrent_limit' => 2,
            'smtp_rotation' => false,
            'attachments' => true,
            'max_size' => 5242880,  // 5MB
            'duration' => 3,
            'price' => 0
        ],
        'rotation_30' => [
            'daily_limit' => 15000,
            'hourly_limit' => 3000,
            'concurrent_limit' => 25,
            'smtp_rotation' => true,
            'attachments' => true,
            'max_size' => 52428800,  // 50MB
            'duration' => 30,
            'price' => 1300
        ],
        'rotation_3' => [
            'daily_limit' => 7000,
            'hourly_limit' => 1000,
            'concurrent_limit' => 10,
            'smtp_rotation' => true,
            'attachments' => true,
            'max_size' => 20971520,  // 20MB
            'duration' => 3,
            'price' => 130
        ],
        'rotation_7' => [
            'daily_limit' => 8000,
            'hourly_limit' => 1500,
            'concurrent_limit' => 15,
            'smtp_rotation' => true,
            'attachments' => true,
            'max_size' => 31457280,  // 30MB
            'duration' => 7,
            'price' => 300
        ],
        'rotation_14' => [
            'daily_limit' => 10000,
            'hourly_limit' => 2000,
            'concurrent_limit' => 20,
            'smtp_rotation' => true,
            'attachments' => true,
            'max_size' => 41943040,  // 40MB
            'duration' => 14,
            'price' => 650
        ],
        'custom_smtp' => [
            'daily_limit' => -1,     // Unlimited
            'hourly_limit' => -1,    // Unlimited
            'concurrent_limit' => 50,
            'smtp_rotation' => true,
            'attachments' => true,
            'max_size' => 104857600, // 100MB
            'duration' => 30,
            'price' => 650
        ]
    ];
    
    private $currentPlan = 'trial';     // Default plan
    private $lastConnectionTime = 0;
    private $connectionTimeout = 300;    // 5 minutes timeout
    
    // Concurrent sending tracking
    private $activeConnections = [];     // Track concurrent email sending
    private $maxConcurrent = 5;         // Will be set based on plan
    
    // Campaign tracking and management
    private $notificationBuffer = [];
    private $lastNotificationTime = 0;
    private $notificationInterval = 0.5; // 500ms minimum between notifications
    private $progressInterval = 2;       // Update progress every 2 seconds

    /**
     * Constructor
     * 
     * @param array $smtp SMTP configuration array containing host, username, password, from_email, from_name
     * @param object|null $bot Telegram bot instance. If null, notifications will be disabled
     * @throws \InvalidArgumentException when SMTP configuration is invalid
     */
    public function __construct($smtp, $bot = null, $plan = 'trial') {
        // Initialize configurations first
        $this->initializeConfigurations();
    // Load endpoint preferences early
    $this->loadEndpointPreferences();
        
        if (!is_array($smtp)) {
            throw new \InvalidArgumentException("SMTP configuration must be an array");
        }

    // Bot instance is optional; if null, notifications are simply disabled.

        // Setup SMTP configs
        if (isset($smtp['host'])) {
            $this->smtpConfigs = [$smtp];
        } else {
            $this->smtpConfigs = array_values($smtp);
        }

        // Validate SMTP configurations
        $requiredKeys = ['host', 'username', 'password', 'from_email', 'from_name'];
        foreach ($this->smtpConfigs as $index => $config) {
            foreach ($requiredKeys as $key) {
                if (!isset($config[$key])) {
                    throw new \InvalidArgumentException("Missing required SMTP setting: {$key} in SMTP config #{$index}");
                }
            }
        }

        $this->bot = $bot;
        $this->setPlan($plan);
        
        // Initialize system
        $this->initMailer();
        register_shutdown_function([$this, 'cleanup']);
    }

    /**
     * Try to locate a DKIM private key file on disk under config/dkim.
     * Looks for:
     *  - config/dkim/{domain}/{selector}.private.key
     *  - config/dkim/{domain}/default.private.key
     *  - config/dkim/{domain}/private.key
     * Optionally a passphrase file alongside: *.pass
     * @param string $domain
     * @param string|null $selector
     * @return array|null { key: path, selector: string, pass: string|null }
     */
    private function autoLocateDkimKey($domain, $selector = null) {
        $base = dirname(__DIR__) . '/config/dkim';
        $paths = [];
        if ($selector) {
            $paths[] = "$base/$domain/{$selector}.private.key";
        }
        $paths[] = "$base/$domain/default.private.key";
        $paths[] = "$base/$domain/private.key";
        foreach ($paths as $keyPath) {
            if (is_readable($keyPath)) {
                $sel = $selector ?: (basename($keyPath) === 'private.key' ? 'default' : preg_replace('/\.private\.key$/', '', basename($keyPath)));
                $pass = null;
                $passPath = preg_replace('/\.private\.key$/', '.pass', $keyPath);
                if ($passPath && is_readable($passPath)) {
                    $pass = trim(@file_get_contents($passPath));
                }
                return [ 'key' => $keyPath, 'selector' => $sel, 'pass' => $pass ];
            }
        }
        return null;
    }

    /**
     * Enable or disable verbose SMTP debug logging at runtime.
     */
    public function enableDebugLogging($enable = true) {
        $this->debugLogging = (bool)$enable;
        if ($this->mailer) {
            $this->mailer->SMTPDebug = $this->debugLogging ? 2 : 0;
            if ($this->debugLogging) {
                $this->mailer->Debugoutput = function($str, $level) {
                    error_log('[SMTP]['.$level.'] ' . $str);
                };
            } else {
                $this->mailer->Debugoutput = null;
            }
        }
    }

    /**
     * Manually flag current SMTP as suspicious/unhealthy to encourage rotation.
     */
    public function flagCurrentSmtpSuspicious() {
        $current = $this->smtpConfigs[$this->currentSmtpIndex] ?? null;
        if (!$current) { return; }
        $smtpKey = ($current['host'] ?? 'host') . ':' . ($current['username'] ?? 'user');
        $this->smtpSuspicion[$smtpKey] = ($this->smtpSuspicion[$smtpKey] ?? 0) + 5;
        $this->applyCooldownToCurrentSmtp('manual_suspicious');
        $this->forceSmtpRotation();
    }

    /**
     * Initialize configurations
     */
    private function initializeConfigurations() {
        // Initialize all configuration arrays
        $this->smtpHealthMetrics = [
            'consecutive_failures' => [],
            'delivery_rates' => [],
            'response_times' => [],
            'bounce_tracking' => [],
            'spam_score_history' => [],
            'ip_reputation' => [],
            'last_health_check' => []
        ];

        $this->securityConfig = [
            'ip_whitelist' => [],
            'rate_limiting' => true,
            'max_failed_attempts' => 5,
            'lockout_duration' => 3600,
            'require_2fa' => false,
            'encryption_enabled' => true,
            'session_timeout' => 1800
        ];

        $this->campaignConfig = [
            'ab_testing' => false,
            'engagement_tracking' => true,
            'active_campaigns' => [],
            'templates' => [],
            'ab_test_variants' => [
                'subject_lines' => [],
                'content_versions' => [],
                'send_times' => []
            ],
            'auto_schedule' => false,
            'optimal_send_times' => [],
            'analytics_enabled' => true,
            'template_versioning' => true
        ];

        $this->recoveryConfig = [
            'auto_retry_count' => 3,
            'exponential_backoff' => true,
            'min_backoff_delay' => 1,
            'max_backoff_delay' => 300,
            'retry_on_errors' => ['timeout', 'connection_lost', 'rate_limit'],
            'circuit_breaker' => [
                'failure_threshold' => 5,
                'reset_timeout' => 60
            ]
        ];

        $this->performanceConfig = [
            'connection_pooling' => true,
            'keepalive_timeout' => 300,
            'batch_size_auto_adjust' => true,
            'min_batch_size' => 10,
            'max_batch_size' => 100,
            'adaptive_timing' => true
        ];

        $this->monitoringConfig = [
            'metrics_enabled' => true,
            'alert_thresholds' => [
                'error_rate' => 0.1,
                'latency' => 5.0,
                'bounce_rate' => 0.05
            ],
            'report_schedule' => '0 0 * * *',
            'retention_days' => 30,
            'detailed_logging' => true
        ];

        $this->queueConfig = [
            'priority_levels' => 3,
            'queue_persistence' => true,
            'dead_letter_queue' => true,
            'retry_strategy' => 'exponential',
            'queue_monitoring' => true,
            'max_queue_size' => 10000
        ];

        $this->complianceConfig = [
            'gdpr_compliance' => true,
            'data_retention' => 90,
            'audit_logging' => true,
            'consent_tracking' => true,
            'privacy_controls' => true,
            'data_encryption' => true
        ];
    }

    /**
     * Initialize campaign configuration
     * 
     * @param array $config Campaign configuration options
     */
    public function initCampaignConfig($config = []) {
        $this->campaignConfig = array_merge($this->campaignConfig, $config);
        
        // Validate configuration
        if (isset($config['ab_testing']) && $config['ab_testing']) {
            if (empty($config['ab_test_variants'])) {
                throw new Exception('A/B testing enabled but no variants defined');
            }
            
            // Validate required variant types
            foreach (['subject_lines', 'content_versions', 'send_times'] as $variantType) {
                if (isset($config['ab_test_variants'][$variantType]) && 
                    !empty($config['ab_test_variants'][$variantType])) {
                    $this->campaignConfig['ab_test_variants'][$variantType] = 
                        $config['ab_test_variants'][$variantType];
                }
            }
        }
        
        // Initialize templates if provided
        if (isset($config['templates']) && is_array($config['templates'])) {
            foreach ($config['templates'] as $templateId => $template) {
                $this->addTemplate($templateId, $template);
            }
        }
    }
    
    /**
     * Add email template
     * 
     * @param string $templateId Template identifier
     * @param array $template Template configuration
     */
    public function addTemplate($templateId, $template) {
        if (!isset($template['subject']) || !isset($template['body'])) {
            throw new Exception('Template must include subject and body');
        }
        
        $this->campaignConfig['templates'][$templateId] = array_merge([
            'version' => '1.0',
            'variables' => [],
            'attachments' => [],
            'tracking' => true
        ], $template);
    }
    
    /**
     * Get A/B test variant
     * 
     * @param string $type Variant type (subject_lines, content_versions, send_times)
     * @return mixed Selected variant
     */
    private function getABTestVariant($type) {
        if (!$this->campaignConfig['ab_testing'] || 
            empty($this->campaignConfig['ab_test_variants'][$type])) {
            return null;
        }
        
        $variants = $this->campaignConfig['ab_test_variants'][$type];
        return $variants[array_rand($variants)];
    }

    /**
     * Set user plan and configure limits
     * 
     * @param string $plan Plan identifier
     */
    public function setPlan($plan) {
        if (!isset($this->plans[$plan])) {
            throw new \InvalidArgumentException("Invalid plan: {$plan}");
        }
        
        $this->currentPlan = $plan;
        $planConfig = $this->plans[$plan];
        
        // Set concurrent limits
        $this->maxConcurrent = $planConfig['concurrent_limit'];
        
        // Log plan change (quiet unless debugLogging)
        if ($this->debugLogging) {
            error_log("EmailSender: Plan set to {$plan} with {$this->maxConcurrent} concurrent connections");
        }
    }

    /**
     * Initialize PHPMailer instance
     */
    private function initMailer() {
        // Prepare current SMTP and key used for connection + cache
        $currentSmtp = $this->smtpConfigs[$this->currentSmtpIndex];
        $smtpKey = ($currentSmtp['host'] ?? 'host') . ':' . ($currentSmtp['username'] ?? 'user');

        // If a mailer exists and is intended for this smtpKey, reuse but always re-apply endpoint overrides and TLS options
        if (isset($this->mailerPool[$smtpKey]) && $this->mailerPool[$smtpKey] instanceof \PHPMailer\PHPMailer\PHPMailer) {
            $this->mailer = $this->mailerPool[$smtpKey];
        } else {
            // Create a new mailer for this smtpKey
            $this->mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            // Basic SMTP setup that does not change per endpoint override
            $this->mailer->isSMTP();
            $this->mailer->Host = $currentSmtp['host'];
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $currentSmtp['username'];
            $this->mailer->Password = $currentSmtp['password'];
            // Cache mailer for this SMTP for reuse
            $this->mailerPool[$smtpKey] = $this->mailer;
        }

        // Determine effective endpoint: start from config, then apply persisted preference for same host:port,
        // then apply any in-session override (overrides have highest precedence).
        $basePort = $currentSmtp['port'] ?? 587;
        $baseEnc  = $currentSmtp['encryption'] ?? '';
        // If we have a saved preference for this host:port, apply its encryption (same port only to honor user config)
        $prefEnc = $this->getPreferredEncryption($currentSmtp['host'] ?? '', (int)$basePort);
        if ($prefEnc !== null) {
            $baseEnc = $prefEnc;
        }
        // Apply in-session override last
        $override = $this->endpointOverrides[$smtpKey] ?? null;
        $port = $override['port'] ?? $basePort;
        $enc  = $override['encryption'] ?? $baseEnc;
        $this->mailer->Port = (int)$port;

        // Security settings (re-apply each time so alternates take effect)
    $this->mailer->SMTPSecure = $enc;
    // PHPMailer by default may try opportunistic STARTTLS. Disable when no enc requested.
    $this->mailer->SMTPAutoTLS = !empty($enc);

        // Optionally relax TLS verification (used as a fallback when cert mismatch occurs)
        if (property_exists($this->mailer, 'SMTPOptions')) {
            if ($this->relaxTlsVerification) {
                $this->mailer->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                        'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                    ],
                    'tls' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                        'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                    ],
                ];
            } else {
                // Reset to defaults when not relaxing, to avoid persisting relaxed options across attempts
                $this->mailer->SMTPOptions = [];
            }
        }
        
    // Performance settings
    $this->mailer->SMTPKeepAlive = true;
    // Respect global SMTP timeout when defined
    $this->mailer->Timeout = defined('SMTP_TIMEOUT') ? (int)SMTP_TIMEOUT : 10;
        
        // Set sender
        $this->mailer->setFrom($currentSmtp['from_email'], $currentSmtp['from_name']);
        // Set HELO/EHLO hostname to sender domain if possible
        $fromDomain = null;
        if (!empty($currentSmtp['from_email']) && strpos($currentSmtp['from_email'], '@') !== false) {
            $fromDomain = substr(strrchr($currentSmtp['from_email'], '@'), 1);
        }
        if ($fromDomain) {
            $this->mailer->Hostname = $fromDomain;
            if (property_exists($this->mailer, 'Helo')) { $this->mailer->Helo = $fromDomain; }
        } else if (!empty($currentSmtp['host'])) {
            $this->mailer->Hostname = $currentSmtp['host'];
        }
        $this->mailer->CharSet = 'UTF-8';
        $this->mailer->isHTML(true);

        // DKIM signing (auto): enable if configured or auto-discover a key file under config/dkim
        $dkimSelector = $currentSmtp['dkim_selector'] ?? (getenv('DKIM_SELECTOR') ?: null);
        $dkimDomain   = $currentSmtp['dkim_domain'] ?? ($fromDomain ?: getenv('DKIM_DOMAIN'));
        $dkimKeyPath  = $currentSmtp['dkim_private_key'] ?? (getenv('DKIM_PRIVATE_KEY') ?: null);
        $dkimPass     = $currentSmtp['dkim_private_key_pass'] ?? (getenv('DKIM_PRIVATE_KEY_PASS') ?: null);
        if ((!$dkimKeyPath || !is_readable($dkimKeyPath)) && $dkimDomain) {
            $auto = $this->autoLocateDkimKey($dkimDomain, $dkimSelector);
            if ($auto && isset($auto['key'])) {
                $dkimKeyPath = $auto['key'];
                if (!$dkimSelector && isset($auto['selector'])) { $dkimSelector = $auto['selector']; }
                if (!$dkimPass && isset($auto['pass'])) { $dkimPass = $auto['pass']; }
            }
        }
        if ($dkimSelector && $dkimDomain && $dkimKeyPath && is_readable($dkimKeyPath)) {
            $this->mailer->DKIM_selector = $dkimSelector;
            $this->mailer->DKIM_domain = $dkimDomain;
            $this->mailer->DKIM_private = $dkimKeyPath;
            if (!empty($dkimPass)) {
                $this->mailer->DKIM_passphrase = $dkimPass;
            }
            $this->mailer->DKIM_identity = $currentSmtp['from_email'] ?? null;
        }
        
    // Debugging: capture SMTP debug output when enabled
    $this->mailer->SMTPDebug = $this->debugLogging ? 2 : 0;
    if ($this->debugLogging) {
        $this->mailer->Debugoutput = function($str, $level) {
            error_log('[SMTP]['.$level.'] ' . $str);
        };
    }
    }

    /**
     * Lightweight SMTP connection test used by TelegramBot
     * Tries to initialize the mailer and open an SMTP connection.
     * Returns true on success, false on failure (does not throw).
     */
    public function testConnection() {
        // Probe connectivity quickly, and cache a working encryption for this host:port to avoid delays.
        try {
            $conf = $this->smtpConfigs[$this->currentSmtpIndex] ?? [];
            $host = $conf['host'] ?? '';
            $port = (int)($conf['port'] ?? 587);
            $enc  = $conf['encryption'] ?? '';

            $this->mailer = null;
            $this->initMailer();
            // Relax SSL validation for test to avoid blocking on cert mismatch
            if (property_exists($this->mailer, 'SMTPOptions')) {
                $this->mailer->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                        'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                    ],
                    'tls' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                        'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                    ],
                ];
            }
            $this->mailer->clearAddresses();
            $this->mailer->addAddress('test@example.com');
            $this->mailer->Subject = 'Connection Test';
            $this->mailer->Body = 'Connection test';
            if (method_exists($this->mailer, 'preSend')) { $this->mailer->preSend(); }
            $ok = false;
            if (method_exists($this->mailer, 'smtpConnect')) {
                $ok = @$this->mailer->smtpConnect();
            }
            if ($ok) {
                // Cache working encryption for this host:port
                $effEnc = $this->mailer->SMTPSecure ?: '';
                $this->savePreferredEncryption($host, $port, $effEnc);
                try { $this->mailer->smtpClose(); } catch (\Throwable $t) {}
                return true;
            }
            // On failure, inspect error. If it's STARTTLS-not-supported on port 587, retry with no TLS.
            $err = (string)($this->mailer->ErrorInfo ?? '');
            $isStarttlsProblem = ($port === 587) && (stripos($err, 'starttls') !== false || stripos($err, 'not implemented') !== false || stripos($err, 'does not support') !== false);
            if ($isStarttlsProblem) {
                // Persist preference to disable TLS for 587 on this host
                $this->savePreferredEncryption($host, 587, '');
                try { $this->mailer->smtpClose(); } catch (\Throwable $t) {}
                // Re-init with no TLS on 587 and try again quickly
                $this->mailer = null;
                $this->endpointOverrides[$host . ':' . ($conf['username'] ?? 'user')] = ['port' => 587, 'encryption' => ''];
                $this->initMailer();
                if (property_exists($this->mailer, 'SMTPOptions')) {
                    $this->mailer->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                        ],
                        'tls' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                        ],
                    ];
                }
                $this->mailer->clearAddresses();
                $this->mailer->addAddress('test@example.com');
                $this->mailer->Subject = 'Connection Test';
                $this->mailer->Body = 'Connection test';
                if (method_exists($this->mailer, 'preSend')) { $this->mailer->preSend(); }
                $ok2 = method_exists($this->mailer, 'smtpConnect') ? (@$this->mailer->smtpConnect()) : false;
                if ($ok2) {
                    $this->savePreferredEncryption($host, 587, '');
                    try { $this->mailer->smtpClose(); } catch (\Throwable $t) {}
                    return true;
                }
            }
            // As a final quick probe: if configured port is 465, ensure we try implicit SSL directly (we already do in init).
            // If not 465 and not 587 STARTTLS case, just report failure.
            try { if ($this->mailer) { $this->mailer->smtpClose(); } } catch (\Throwable $t) {}
            return false;
        } catch (\Throwable $e) {
            try { if ($this->mailer) { $this->mailer->smtpClose(); } } catch (\Throwable $t) {}
            return false;
        }
    }

    /**
     * Send email with retry logic and SMTP rotation
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body Email body (HTML or plain text)
     * @param string|null $replyTo Optional reply-to address
     * @param string|null $campaignId Optional campaign identifier
     * @param array $options Additional options
     * @return bool Success status
     */
    public function send($to, $subject, $body, $replyTo = null, $campaignId = null, $options = []) {
        // DRY mode for tests: skip real SMTP and pretend success
        if (getenv('DRY_SEND')) {
            if ($this->bot && method_exists($this->bot, 'log')) {
                $this->bot->log("DRY_SEND enabled: pretending to send to {$to}");
            }
            $this->notifyEmailStatus($to, true);
            if ($campaignId) {
                $this->trackCampaignEngagement($campaignId, 'total_sent');
            }
            return true;
        }

    $startTime = microtime(true);
        $attempts = 0;
    $smtpCount = count($this->smtpConfigs);
    $maxAttempts = max($this->maxRetries, $smtpCount); // ensure we can try each SMTP at least once
        $smtpKey = '';
        $endpointsForThisSmtp = [];
    $triedSmtpIndices = [];
    $relaxedTlsTriedForThisEmail = false;
        
    while ($attempts < $maxAttempts) {
            try {
                $attempts++;
                
                // Initialize mailer if needed
                $this->initMailer();
                $smtpKey = ($this->smtpConfigs[$this->currentSmtpIndex]['host'] ?? 'host') . ':' . ($this->smtpConfigs[$this->currentSmtpIndex]['username'] ?? 'user');
        // Build alternate endpoints for this SMTP (used only after we try all SMTPs first)
        if (!isset($this->alternateEndpointCache[$smtpKey])) {
                    $this->alternateEndpointCache[$smtpKey] = $this->buildAlternateEndpoints($this->smtpConfigs[$this->currentSmtpIndex]);
                    $this->endpointCursor[$smtpKey] = 0;
                }
                $endpointsForThisSmtp = $this->alternateEndpointCache[$smtpKey];
                
                // Clear any previous recipients
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                
                // Set recipient
                $this->mailer->addAddress($to);
                
                // Set reply-to if provided
                if ($replyTo) {
                    $this->mailer->clearReplyTos();
                    $this->mailer->addReplyTo($replyTo);
                }
                
                // Process template placeholders
                $processedSubject = $this->processTemplate($subject, $to);
                $processedBody = $this->processTemplate($body, $to);
                
                // Set email content
                $this->mailer->Subject = $processedSubject;
                $this->mailer->Body = $processedBody;
                // Provide a plain-text alternative for better deliverability
                $this->mailer->AltBody = trim(strip_tags(preg_replace('/<br\s*\/?\s*>/i', "\n", $processedBody)));

                // Apply attachments if provided
                if (!empty($options['attachments']) && is_array($options['attachments'])) {
                    foreach ($options['attachments'] as $filePath) {
                        if (is_string($filePath) && $filePath !== '' && is_file($filePath)) {
                            try { $this->mailer->addAttachment($filePath); } catch (\Throwable $t) {
                                if ($this->bot && method_exists($this->bot, 'log')) {
                                    $this->bot->log('Failed to attach file: ' . basename($filePath));
                                }
                            }
                        }
                    }
                }

                // Ensure bounces go somewhere we can monitor
                $fromAddress = $this->smtpConfigs[$this->currentSmtpIndex]['from_email'] ?? null;
                if (!empty($fromAddress)) {
                    $this->mailer->Sender = $fromAddress; // Envelope sender (Return-Path)
                }

                // Add List-Unsubscribe header when we have a reply-to or from address
                $unsubscribe = [];
                if (!empty($replyTo)) { $unsubscribe[] = '<mailto:' . $replyTo . '>'; }
                if (!empty($fromAddress)) { $unsubscribe[] = '<mailto:' . $fromAddress . '>'; }
                if (!empty($unsubscribe)) {
                    $this->mailer->addCustomHeader('List-Unsubscribe', implode(', ', $unsubscribe));
                }
                
                // Send the email
                $result = $this->mailer->send();
                // Capture for diagnostics
                $lastError = $this->mailer->ErrorInfo;
                
                if ($result) {
                    // Track success
                    $this->trackSmtpPerformance(true, $startTime);
                    // Reset failure streak for this SMTP
                    $key = $this->getSmtpKey();
                    if ($key) { $this->smtpFailureStreaks[$key] = 0; }
                    // Persist last-known-good encryption for this host:port to avoid future delays
                    $conf = $this->smtpConfigs[$this->currentSmtpIndex] ?? [];
                    $host = $conf['host'] ?? '';
                    $effPort = (int)($this->mailer->Port ?? ($conf['port'] ?? 587));
                    $effEnc  = $this->mailer->SMTPSecure ?: '';
                    $this->savePreferredEncryption($host, $effPort, $effEnc);
                    // Log a concise success line for diagnostics
                    if ($this->bot && method_exists($this->bot, 'log')) {
                        $u = $conf['username'] ?? '';
                        $encLog = $effEnc === '' ? 'none' : $effEnc;
                        $this->bot->log("Send success for {$to} via {$host}:{$effPort} enc={$encLog} user={$u}");
                    }
                    // Decay suspicion for this SMTP on success
                    if (isset($this->smtpSuspicion[$smtpKey]) && $this->smtpSuspicion[$smtpKey] > 0) {
                        $this->smtpSuspicion[$smtpKey] = max(0, $this->smtpSuspicion[$smtpKey]-1);
                    }
                    $this->errorCount = 0; // Reset on success
                    $this->relaxTlsVerification = false; // Restore strict mode after success
                    $this->notifyEmailStatus($to, true);
                    
                    // Track campaign if provided
                    if ($campaignId) {
                        $this->trackCampaignEngagement($campaignId, 'total_sent');
                    }
                    
                    // If we saw suspicious acceptance patterns recently, still rotate to distribute
                    $this->rotateSmtpIfNeeded();
                    
                    return true;
                } else {
                    // Treat a false send() as a failure path similar to exception. Rotate-first policy.
                    $this->trackSmtpPerformance(false, $startTime);
                    $this->errorCount++;
                    $errorMsg = $lastError ?: 'Unknown SMTP error';
                    // For connection-like errors, apply cooldown and avoid per-attempt log spam
                    $isConn = $this->isConnectionError($errorMsg);
                    if ($isConn) {
                        $this->applyCooldownToCurrentSmtp('connection_error');
                        // Track streak and optionally advise user once
                        $key = $this->getSmtpKey();
                        if ($key) {
                            $this->smtpFailureStreaks[$key] = ($this->smtpFailureStreaks[$key] ?? 0) + 1;
                            if (($this->smtpFailureStreaks[$key] >= 3) && $this->currentChatId && empty($this->connectionAdvisoryShown[$key])) {
                                $this->connectionAdvisoryShown[$key] = true;
                                if ($this->bot && method_exists($this->bot, 'sendTelegramMessage')) {
                                    $advice = "⚠️ SMTP connection problem. Please verify host, port, and encryption settings (try 465/SSL or 587 with/without TLS).";
                                    try { $this->bot->sendTelegramMessage($this->currentChatId, $advice, ['disable_notification' => true]); } catch (\Throwable $t) {}
                                }
                            }
                        }
                    } else {
                        // Log non-connection errors immediately (useful for auth/blacklist etc.)
                        if ($this->bot && method_exists($this->bot, 'log')) {
                            $conf = $this->smtpConfigs[$this->currentSmtpIndex] ?? [];
                            $h = $conf['host'] ?? '';
                            $u = $conf['username'] ?? '';
                            $smtpKeyEff = ($conf['host'] ?? 'host') . ':' . ($conf['username'] ?? 'user');
                            $overrideEff = $this->endpointOverrides[$smtpKeyEff] ?? null;
                            $pEff = $overrideEff['port'] ?? ($conf['port'] ?? '');
                            $eEff = $overrideEff['encryption'] ?? ($conf['encryption'] ?? '');
                            if ($eEff === '') { $eEff = 'none'; }
                            $this->bot->log("Send failed (false) for {$to} via {$h}:{$pEff} enc={$eEff} user={$u} | {$errorMsg}");
                        }
                    }
                    $triedSmtpIndices[$this->currentSmtpIndex] = true;

                    // If authentication is rejected, permanently remove this SMTP
                    if ($this->isAuthRejectedError($errorMsg)) {
                        $this->removeCurrentSmtpPermanently('auth_rejected');
                        if ($this->bot && method_exists($this->bot, 'log')) {
                            $this->bot->log('Auto-removed SMTP due to auth rejection');
                        }
                        // Update counts after removal
                        $smtpCount = count($this->smtpConfigs);
                        if ($smtpCount === 0) {
                            break;
                        }
                        if ($attempts < $maxAttempts) {
                            usleep(150000);
                            continue;
                        }
                    }

                    // If there are untried SMTPs, rotate immediately to a different one
                    if (count($triedSmtpIndices) < $smtpCount && $smtpCount > 1) {
                        if ($this->isBlacklistedError($errorMsg) || $this->shouldRotateOnError($errorMsg)) {
                            $this->applyCooldownToCurrentSmtp($this->isBlacklistedError($errorMsg) ? 'blacklisted' : $errorMsg);
                        }
                        $prevKey = $smtpKey;
                        $this->forceSmtpRotation();
                        // Ensure we pick an untried SMTP
                        $guard = 0;
                        while (isset($triedSmtpIndices[$this->currentSmtpIndex]) && $guard < $smtpCount) {
                            $this->forceSmtpRotation();
                            $guard++;
                        }
                        // Reset mailer and endpoint overrides for previous smtp
                        if ($prevKey) { unset($this->endpointOverrides[$prevKey], $this->endpointCursor[$prevKey]); }
                        $this->mailer = null;
                        if ($attempts < $maxAttempts) {
                            usleep(150000);
                            continue;
                        }
                    }

                    // All SMTPs have been tried; now consider TLS relax or alternate endpoints
                    // Expand maxAttempts dynamically to allow endpoint permutations
                    $remainingEndpoints = 0;
                    if (!empty($endpointsForThisSmtp)) {
                        $cursor = $this->endpointCursor[$smtpKey] ?? 0;
                        $remainingEndpoints = max(0, count($endpointsForThisSmtp) - $cursor);
                    }
                    $maxAttempts = max($maxAttempts, $attempts + $remainingEndpoints + $smtpCount);
                    $isCertError = stripos($errorMsg, 'certificate') !== false || stripos($errorMsg, 'verify_peer_name') !== false || stripos($errorMsg, 'stream_socket_enable_crypto') !== false;
                    if ($isCertError && !$this->relaxTlsVerification && !$relaxedTlsTriedForThisEmail) {
                        try { if ($this->mailer) { $this->mailer->smtpClose(); } } catch (\Throwable $t) {}
                        $this->mailer = null;
                        $this->relaxTlsVerification = true;
                        $relaxedTlsTriedForThisEmail = true;
                        if ($attempts < $maxAttempts) {
                            usleep(200000);
                            continue;
                        }
                    }

                    // Try alternate endpoints (ports/encryption) for connection-like failures
                    $connErrorHints = ['connect', 'connection', 'refused', 'timed out', 'timeout', 'stream_socket_client', 'smtp error: could not connect', 'starttls'];
                    $shouldTryAlternate = false;
                    foreach ($connErrorHints as $hint) {
                        if (stripos($errorMsg, $hint) !== false) { $shouldTryAlternate = true; break; }
                    }
                    if ($shouldTryAlternate && !empty($endpointsForThisSmtp)) {
                        // If this is a STARTTLS-related failure, prefer jumping directly to 465/ssl or 587/no TLS before rotating SMTPs
                        $isStarttlsProblem = (stripos($errorMsg, 'starttls') !== false) || (stripos($errorMsg, 'command not implemented') !== false) || (stripos($errorMsg, 'does not support') !== false);
                        // If STARTTLS unsupported on port 587, cache preference to disable TLS on 587 for this host to avoid future delays
                        if ($isStarttlsProblem) {
                            $c = $this->smtpConfigs[$this->currentSmtpIndex] ?? [];
                            if (($c['port'] ?? 0) == 587) {
                                $this->savePreferredEncryption($c['host'] ?? '', 587, '');
                            }
                        }
                        $cursor = $this->endpointCursor[$smtpKey] ?? 0;
                        if ($cursor < count($endpointsForThisSmtp)) {
                            $targetIndex = $cursor;
                            if ($isStarttlsProblem) {
                                $c = $this->smtpConfigs[$this->currentSmtpIndex] ?? [];
                                $origPort = (int)($c['port'] ?? 587);
                                $prefList = [];
                                if ($origPort === 587) { $prefList = [[587, ''], [465, 'ssl']]; }
                                elseif ($origPort === 465) { $prefList = [[465, 'ssl'], [587, '']]; }
                                else { $prefList = [[587, ''], [465, 'ssl']]; }
                                $found = false;
                                foreach ($prefList as $pref) {
                                    for ($i = $cursor; $i < count($endpointsForThisSmtp); $i++) {
                                        $ep = $endpointsForThisSmtp[$i];
                                        if ($ep['port'] === $pref[0] && ($ep['encryption'] === $pref[1])) { $targetIndex = $i; $found = true; break; }
                                    }
                                    if ($found) break;
                                }
                            }
                            $endpoint = $endpointsForThisSmtp[$targetIndex];
                            $this->endpointOverrides[$smtpKey] = $endpoint;
                            if ($this->bot && method_exists($this->bot, 'log')) {
                                $this->bot->log("Applying alternate endpoint for {$smtpKey}: port={$endpoint['port']} enc={$endpoint['encryption']}");
                            }
                            $this->endpointCursor[$smtpKey] = $targetIndex + 1;
                            try { if ($this->mailer) { $this->mailer->smtpClose(); } } catch (\Throwable $t) {}
                            $this->mailer = null;
                            if ($attempts < $maxAttempts) {
                                usleep(200000); // 200ms
                                continue;
                            }
                        }
                    }

                    // As a final measure, rotate anyway to spread attempts
                    if ($smtpCount > 1) {
                        $this->forceSmtpRotation();
                        $this->mailer = null;
                    }

                    // Short backoff and retry
                    if ($attempts < $maxAttempts) {
                        $delayUs = min((int)(pow(2, $attempts) * 100000), 1000000);
                        $delayUs = max(0, (int)$delayUs);
                        usleep($delayUs);
                        continue;
                    }
                }
                
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                if ($this->debugLogging) {
                    error_log("EmailSender: Attempt {$attempts} failed for {$to}: {$errorMsg}");
                }
                
                // Track failure
                $this->trackSmtpPerformance(false, $startTime);
                $this->errorCount++;
                
                $triedSmtpIndices[$this->currentSmtpIndex] = true;
                // For connection-like errors, apply cooldown and suppress per-attempt logs; otherwise log immediately
                $isConn = $this->isConnectionError($errorMsg);
                if ($isConn) {
                    $this->applyCooldownToCurrentSmtp('connection_error');
                    $key = $this->getSmtpKey();
                    if ($key) {
                        $this->smtpFailureStreaks[$key] = ($this->smtpFailureStreaks[$key] ?? 0) + 1;
                        if (($this->smtpFailureStreaks[$key] >= 3) && $this->currentChatId && empty($this->connectionAdvisoryShown[$key])) {
                            $this->connectionAdvisoryShown[$key] = true;
                            if ($this->bot && method_exists($this->bot, 'sendTelegramMessage')) {
                                $advice = "⚠️ SMTP connection problem. Please verify host, port, and encryption settings (try 465/SSL or 587 with/without TLS).";
                                try { $this->bot->sendTelegramMessage($this->currentChatId, $advice, ['disable_notification' => true]); } catch (\Throwable $t) {}
                            }
                        }
                    }
                } else {
                    if ($this->bot && method_exists($this->bot, 'log')) {
                        $conf = $this->smtpConfigs[$this->currentSmtpIndex] ?? [];
                        $h = $conf['host'] ?? '';
                        $u = $conf['username'] ?? '';
                        $smtpKeyEff = ($conf['host'] ?? 'host') . ':' . ($conf['username'] ?? 'user');
                        $overrideEff = $this->endpointOverrides[$smtpKeyEff] ?? null;
                        $pEff = $overrideEff['port'] ?? ($conf['port'] ?? '');
                        $eEff = $overrideEff['encryption'] ?? ($conf['encryption'] ?? '');
                        if ($eEff === '') { $eEff = 'none'; }
                        $this->bot->log("Send exception for {$to} via {$h}:{$pEff} enc={$eEff} user={$u} | {$errorMsg}");
                    }
                }

                // If authentication is rejected, permanently remove this SMTP and continue
                if ($this->isAuthRejectedError($errorMsg)) {
                    $this->removeCurrentSmtpPermanently('auth_rejected');
                    if ($this->bot && method_exists($this->bot, 'log')) {
                        $this->bot->log('Auto-removed SMTP due to auth rejection');
                    }
                    $smtpCount = count($this->smtpConfigs);
                    if ($smtpCount === 0) {
                        break;
                    }
                    if ($attempts < $maxAttempts) {
                        usleep(150000);
                        continue;
                    }
                }

                // Before rotate-first, handle immediate STARTTLS-not-supported by trying alternate endpoints first
                $starttlsHints = ['starttls', 'command not implemented', 'does not support'];
                $isStarttlsProblem = false;
                foreach ($starttlsHints as $h) { if (stripos($errorMsg, $h) !== false) { $isStarttlsProblem = true; break; } }
                if ($isStarttlsProblem && !empty($endpointsForThisSmtp)) {
                    // Persist preference to disable TLS on 587 for this host to avoid repeated delays
                    $c = $this->smtpConfigs[$this->currentSmtpIndex] ?? [];
                    if (($c['port'] ?? 0) == 587) {
                        $this->savePreferredEncryption($c['host'] ?? '', 587, '');
                    }
                    $cursor = $this->endpointCursor[$smtpKey] ?? 0;
                    if ($cursor < count($endpointsForThisSmtp)) {
                        $targetIndex = $cursor;
                        $c = $this->smtpConfigs[$this->currentSmtpIndex] ?? [];
                        $origPort = (int)($c['port'] ?? 587);
                        $prefList = [];
                        if ($origPort === 587) { $prefList = [[587, ''], [465, 'ssl']]; }
                        elseif ($origPort === 465) { $prefList = [[465, 'ssl'], [587, '']]; }
                        else { $prefList = [[587, ''], [465, 'ssl']]; }
                        $found = false;
                        foreach ($prefList as $pref) {
                            for ($i = $cursor; $i < count($endpointsForThisSmtp); $i++) {
                                $ep = $endpointsForThisSmtp[$i];
                                if ($ep['port'] === $pref[0] && ($ep['encryption'] === $pref[1])) { $targetIndex = $i; $found = true; break; }
                            }
                            if ($found) break;
                        }
                        $endpoint = $endpointsForThisSmtp[$targetIndex];
                        $this->endpointOverrides[$smtpKey] = $endpoint; // Apply override now
                        if ($this->bot && method_exists($this->bot, 'log')) {
                            $this->bot->log("Applying STARTTLS alternate endpoint for {$smtpKey}: port={$endpoint['port']} enc={$endpoint['encryption']}");
                        }
                        $this->endpointCursor[$smtpKey] = $targetIndex + 1;
                        try { if ($this->mailer) { $this->mailer->smtpClose(); } } catch (\Throwable $t) {}
                        $this->mailer = null;
                        if ($attempts < $maxAttempts) {
                            usleep(200000);
                            continue;
                        }
                    }
                }

                // Rotate-first: if there are untried SMTPs, rotate immediately
                if (count($triedSmtpIndices) < $smtpCount && $smtpCount > 1) {
                    if ($this->isBlacklistedError($errorMsg) || $this->shouldRotateOnError($errorMsg)) {
                        $this->applyCooldownToCurrentSmtp($this->isBlacklistedError($errorMsg) ? 'blacklisted' : $errorMsg);
                    }
                    $prevKey = $smtpKey;
                    $this->forceSmtpRotation();
                    $guard = 0;
                    while (isset($triedSmtpIndices[$this->currentSmtpIndex]) && $guard < $smtpCount) {
                        $this->forceSmtpRotation();
                        $guard++;
                    }
                    if ($prevKey) { unset($this->endpointOverrides[$prevKey], $this->endpointCursor[$prevKey]); }
                    $this->mailer = null;
                    if ($attempts < $maxAttempts) {
                        usleep(150000);
                        continue;
                    }
                }

                // All SMTPs have been tried; now consider TLS relax and alternate endpoints
                // Expand maxAttempts dynamically to allow endpoint permutations
                $remainingEndpoints = 0;
                if (!empty($endpointsForThisSmtp)) {
                    $cursor = $this->endpointCursor[$smtpKey] ?? 0;
                    $remainingEndpoints = max(0, count($endpointsForThisSmtp) - $cursor);
                }
                $maxAttempts = max($maxAttempts, $attempts + $remainingEndpoints + $smtpCount);
                $isCertError = stripos($errorMsg, 'certificate') !== false || stripos($errorMsg, 'verify_peer_name') !== false || stripos($errorMsg, 'stream_socket_enable_crypto') !== false;
                if ($isCertError && !$this->relaxTlsVerification && !$relaxedTlsTriedForThisEmail) {
                    if ($this->debugLogging) { error_log('EmailSender: SSL cert mismatch; trying relaxed TLS after exhausting SMTPs.'); }
                    try { if ($this->mailer) { $this->mailer->smtpClose(); } } catch (\Throwable $t) {}
                    $this->mailer = null;
                    $this->relaxTlsVerification = true;
                    $relaxedTlsTriedForThisEmail = true;
                    if ($attempts < $maxAttempts) {
                        usleep(200000);
                        continue;
                    }
                }

                $connErrorHints = ['connect', 'connection', 'refused', 'timed out', 'timeout', 'stream_socket_client', 'smtp error: could not connect', 'starttls'];
                $shouldTryAlternate = false;
                foreach ($connErrorHints as $hint) {
                    if (stripos($errorMsg, $hint) !== false) { $shouldTryAlternate = true; break; }
                }
                if ($shouldTryAlternate && !empty($endpointsForThisSmtp)) {
                    $cursor = $this->endpointCursor[$smtpKey] ?? 0;
                    if ($cursor < count($endpointsForThisSmtp)) {
                        $endpoint = $endpointsForThisSmtp[$cursor];
                        $this->endpointOverrides[$smtpKey] = $endpoint; // Apply override
                        if ($this->bot && method_exists($this->bot, 'log')) {
                            $this->bot->log("Applying alternate endpoint for {$smtpKey}: port={$endpoint['port']} enc={$endpoint['encryption']}");
                        }
                        $this->endpointCursor[$smtpKey] = $cursor + 1;
                        try { if ($this->mailer) { $this->mailer->smtpClose(); } } catch (\Throwable $t) {}
                        $this->mailer = null;
                        if ($attempts < $maxAttempts) {
                            usleep(200000);
                            continue;
                        }
                    }
                }

                // Record suspicion for this SMTP and rotate to distribute further attempts
                if (!isset($this->smtpSuspicion[$smtpKey])) { $this->smtpSuspicion[$smtpKey] = 0; }
                $this->smtpSuspicion[$smtpKey] = min(10, $this->smtpSuspicion[$smtpKey] + 1);
                if ($this->smtpSuspicion[$smtpKey] >= 5) {
                    $this->applyCooldownToCurrentSmtp('suspicious_rejects');
                }
                if ($smtpCount > 1) {
                    $this->forceSmtpRotation();
                    $this->mailer = null;
                }
                // Short backoff before retry (microseconds), keep UX snappy
                if ($attempts < $maxAttempts) {
                    $delayUs = min((int)(pow(2, $attempts) * 100000), 1000000); // up to 1s
                    $delayUs = max(0, (int)$delayUs);
                    usleep($delayUs);
                }
            }
        }
        
        // All attempts failed - log a single concise summary
        if ($this->bot && method_exists($this->bot, 'log')) {
            try { $conf = $this->smtpConfigs[$this->currentSmtpIndex] ?? []; } catch (\Throwable $t) { $conf = []; }
            $h = $conf['host'] ?? '';
            $u = $conf['username'] ?? '';
            $this->bot->log("All attempts failed for {$to}; last SMTP {$h} user={$u}");
        }
        $this->notifyEmailStatus($to, false);
        return false;
    }

    // Determine if an error is connection-related (timeouts, refused, cannot connect, STARTTLS issues)
    private function isConnectionError($error) {
        if (!$error) return false;
        $err = strtolower($error);
        $hints = ['could not connect', 'connect', 'connection', 'refused', 'timed out', 'timeout', 'stream_socket_client', 'starttls', 'quit command failed'];
        foreach ($hints as $h) { if (strpos($err, $h) !== false) return true; }
        return false;
    }

    /**
     * Process template placeholders
     * 
     * @param string $template Template string
     * @param string $email Recipient email
     * @return string Processed template
     */
    private function processTemplate($template, $email) {
        $replacements = [
            '{email}' => $email,
            '{{email}}' => $email,
            '{date}' => date('Y-m-d'),
            '{{date}}' => date('Y-m-d'),
            '{time}' => date('H:i:s'),
            '{{time}}' => date('H:i:s'),
            '{random_string}' => bin2hex(random_bytes(8)),
            '{{random_string}}' => bin2hex(random_bytes(8))
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Check if SMTP should be rotated based on error
     * 
     * @param string $error Error message
     * @return bool Whether to rotate
     */
    private function shouldRotateOnError($error) {
        $rotateErrors = [
            'quota', 'limit', 'rate', 'suspended', 'blocked', 'denied',
            'ssl', 'certificate', 'timeout', 'connection', 'refused', 'rbl', 'blacklist', 'listed', 'spamhaus'
        ];
        
        foreach ($rotateErrors as $errorType) {
            if (stripos($error, $errorType) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function isBlacklistedError($error) {
        $signals = ['rbl', 'blacklist', 'listed', 'spamhaus', 'spamcop', 'barracuda'];
        foreach ($signals as $s) {
            if (stripos($error, $s) !== false) return true;
        }
        return false;
    }

    private function isAuthRejectedError($error) {
        if (!$error) return false;
        $signals = [
            'authentication failed',
            'invalid login',
            'username and password not accepted',
            '535', // 535 5.7.8 Authentication credentials invalid
            '5.7.8',
            'auth failed',
            'login denied',
            'bad credentials',
            'could not authenticate',
            '5.7.3'
        ];
        $err = strtolower($error);
        foreach ($signals as $s) {
            if (strpos($err, $s) !== false) return true;
        }
        return false;
    }

    private function prefsFilePath() {
        // Persist under boosted-bot/config/smtp_endpoints.json
        return dirname(__DIR__) . '/config/smtp_endpoints.json';
    }

    private function loadEndpointPreferences() {
        if ($this->endpointPrefsLoaded) { return; }
        $this->endpointPrefsLoaded = true;
        $f = $this->prefsFilePath();
        if (is_file($f) && is_readable($f)) {
            $json = @file_get_contents($f);
            if ($json !== false) {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    $this->endpointPreferences = $data;
                }
            }
        }
    }

    private function saveEndpointPreferences() {
        $f = $this->prefsFilePath();
        $dir = dirname($f);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        // Keep it small and simple
        $json = json_encode($this->endpointPreferences, JSON_PRETTY_PRINT);
        if ($json !== false) {
            @file_put_contents($f, $json . "\n");
            @chmod($f, 0644);
        }
    }

    private function prefKey($host, $port) { return strtolower($host) . ':' . (int)$port; }

    private function getPreferredEncryption($host, $port) {
        $this->loadEndpointPreferences();
        $key = $this->prefKey($host, $port);
        if (isset($this->endpointPreferences[$key]) && isset($this->endpointPreferences[$key]['encryption'])) {
            return $this->endpointPreferences[$key]['encryption'];
        }
        return null;
    }

    private function savePreferredEncryption($host, $port, $enc) {
        if (empty($host) || $port <= 0) { return; }
        $this->loadEndpointPreferences();
        $key = $this->prefKey($host, $port);
        $enc = strtolower((string)$enc);
        if (!in_array($enc, ['', 'tls', 'ssl'], true)) { $enc = ''; }
        $existing = $this->endpointPreferences[$key]['encryption'] ?? null;
        if ($existing !== $enc) {
            $this->endpointPreferences[$key] = ['encryption' => $enc, 'updated_at' => time()];
            $this->saveEndpointPreferences();
        }
    }

    private function buildAlternateEndpoints($config) {
        $candidates = [];
        $add = function($port, $enc) use (&$candidates) {
            $key = $port . '|' . strtolower($enc);
            $candidates[$key] = ['port' => (int)$port, 'encryption' => strtolower($enc)];
        };
        $origPort = $config['port'] ?? 587;
        $origEnc  = strtolower($config['encryption'] ?? '');
        // Start with the original
        $add($origPort, $origEnc);
    // Common SMTP endpoints (prefer implicit SSL 465 early)
    $add(465, 'ssl');
    $add(587, 'tls');
    $add(587, ''); // opportunistic TLS disabled
    $add(25, '');
    $add(2525, 'tls');
    $add(2525, '');
    $add(26, '');
    $add(8025, '');
        // De-duplicate while preserving order
        return array_values($candidates);
    }

    private function getSmtpKey($config = null) {
        $conf = $config ?? ($this->smtpConfigs[$this->currentSmtpIndex] ?? []);
        if (empty($conf)) return '';
        return ($conf['host'] ?? 'host') . ':' . ($conf['username'] ?? 'user');
    }

    private function removeCurrentSmtpPermanently($reason = '') {
        $idx = $this->currentSmtpIndex;
        $conf = $this->smtpConfigs[$idx] ?? null;
        if ($conf === null) return;
        $key = $this->getSmtpKey($conf);
        // Remove from internal pools
        if (isset($this->mailerPool[$key])) { unset($this->mailerPool[$key]); }
        if (isset($this->endpointOverrides[$key])) { unset($this->endpointOverrides[$key]); }
        if (isset($this->endpointCursor[$key])) { unset($this->endpointCursor[$key]); }
        if ($this->mailer) {
            try { $this->mailer->smtpClose(); } catch (\Throwable $t) {}
            $this->mailer = null;
        }
        array_splice($this->smtpConfigs, $idx, 1);
        // Adjust index
        if (empty($this->smtpConfigs)) {
            $this->currentSmtpIndex = 0;
        } else {
            $this->currentSmtpIndex = $idx % count($this->smtpConfigs);
        }
        // Notify bot to persist removal
        if ($this->bot && method_exists($this->bot, 'onSmtpAuthRejected')) {
            $host = $conf['host'] ?? '';
            $user = $conf['username'] ?? '';
            try { $this->bot->onSmtpAuthRejected($host, $user, $reason); } catch (\Throwable $t) {}
        }
    }

    private function isOnCooldown($index) {
        $key = $this->getSmtpKey($this->smtpConfigs[$index] ?? null);
        if ($key === '') return false;
        $until = $this->smtpCooldowns[$key] ?? 0;
        return $until > time();
    }

    private function applyCooldownToCurrentSmtp($reason = '') {
        $key = $this->getSmtpKey();
        if ($key === '') return;
        // Increase cooldown modestly on repeated errors
        $current = $this->smtpCooldowns[$key] ?? 0;
        $base = $this->minCooldownSeconds;
        $next = time() + ($current > time() ? min($this->maxCooldownSeconds, ($current - time()) * 2) : $base);
        $this->smtpCooldowns[$key] = $next;
        if ($this->debugLogging) {
            error_log("EmailSender: Applied cooldown to {$key} until " . date('H:i:s', $next));
        }
    }

    private function pickNextSmtpIndex() {
        $count = count($this->smtpConfigs);
        if ($count === 0) return 0;
        if ($count === 1) return 0;

        $now = time();
        $candidates = [];
        for ($i = 0; $i < $count; $i++) {
            if ($i === $this->currentSmtpIndex && $count > 1) {
                // allow switching away from current unless it's the only one
            }
            if (!$this->isOnCooldown($i)) {
                $conf = $this->smtpConfigs[$i];
                $key = $this->getSmtpKey($conf);
                $stats = $this->smtpStats[$key] ?? ['total' => 0, 'success' => 0, 'failed' => 0];
                $total = max(1, (int)$stats['total']);
                $successRate = ($stats['success'] / $total);
                // Weight bias: 1.0 base + successRate; ensure minimum weight
                $weight = max(0.5, 1.0 + $successRate);
                $candidates[] = ['i' => $i, 'w' => $weight];
            }
        }
        // If no candidates (all in cooldown), ignore cooldowns and pick round robin
        if (empty($candidates)) {
            return ($this->currentSmtpIndex + 1) % $count;
        }
        if ($this->rotationStrategy === 'round_robin') {
            return ($this->currentSmtpIndex + 1) % $count;
        }
        if ($this->rotationStrategy === 'random') {
            $idx = array_rand($candidates);
            $next = $candidates[$idx]['i'];
            if ($next === $this->currentSmtpIndex && count($candidates) > 1) {
                // try a different one
                $idx = ($idx + 1) % count($candidates);
                $next = $candidates[$idx]['i'];
            }
            return $next;
        }
        // weighted_random
        $sum = array_sum(array_column($candidates, 'w'));
        $r = mt_rand() / mt_getrandmax() * $sum;
        $acc = 0.0;
        foreach ($candidates as $cand) {
            $acc += $cand['w'];
            if ($r <= $acc) {
                $next = $cand['i'];
                if ($next === $this->currentSmtpIndex && count($candidates) > 1) {
                    // pick another to avoid sticking
                    continue;
                }
                return $next;
            }
        }
        // Fallback
        return ($this->currentSmtpIndex + 1) % $count;
    }

    public function setRotationStrategy($strategy) {
        $allowed = ['round_robin', 'random', 'weighted_random'];
        if (in_array($strategy, $allowed, true)) {
            $this->rotationStrategy = $strategy;
        }
    }

    /**
     * Force SMTP rotation
     */
    private function forceSmtpRotation() {
        $this->currentSmtpIndex = $this->pickNextSmtpIndex();
        $this->emailsSentWithCurrentSmtp = 0;
        
        // Close current connection
        if ($this->mailer) {
            $this->mailer->smtpClose();
            $this->mailer = null; // Force re-initialization
        }
        if ($this->debugLogging) {
            error_log("EmailSender: Forced SMTP rotation to index {$this->currentSmtpIndex}");
        }
    }

    /**
     * Rotate SMTP if needed based on email count
     */
    private function rotateSmtpIfNeeded() {
        $this->emailsSentWithCurrentSmtp++;
        
        if ($this->emailsSentWithCurrentSmtp >= $this->emailsPerRotation) {
            $this->forceSmtpRotation();
            
            // Suppress rotation notifications to avoid chat spam
        }
    }

    /**
     * Randomly select a starting SMTP to distribute load across servers.
     */
    public function selectRandomSmtp() {
        $count = count($this->smtpConfigs);
        if ($count > 1) {
            $this->currentSmtpIndex = mt_rand(0, $count - 1);
            if ($this->mailer) {
                try { $this->mailer->smtpClose(); } catch (\Throwable $t) {}
                $this->mailer = null;
            }
            $this->emailsSentWithCurrentSmtp = 0;
        }
    }

    /**
     * Public API to rotate to next SMTP immediately
     */
    public function rotateToNextSmtp() {
        $this->forceSmtpRotation();
    }

    /**
     * Configure how many emails to send before rotating SMTP
     */
    public function setEmailsPerRotation($count) {
        $count = (int)$count;
        if ($count >= 1) {
            $this->emailsPerRotation = $count;
        }
    }

    /**
     * Track SMTP performance
     * 
     * @param bool $success Whether operation was successful
     * @param float $startTime Operation start time
     */
    private function trackSmtpPerformance($success, $startTime) {
        $currentSmtp = $this->smtpConfigs[$this->currentSmtpIndex];
        $smtpKey = $currentSmtp['host'] . ':' . $currentSmtp['username'];
        
        if (!isset($this->smtpStats[$smtpKey])) {
            $this->smtpStats[$smtpKey] = [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'total_time' => 0
            ];
        }
        
        $duration = microtime(true) - $startTime;
        $this->smtpStats[$smtpKey]['total']++;
        $this->smtpStats[$smtpKey]['total_time'] += $duration;
        
        if ($success) {
            $this->smtpStats[$smtpKey]['success']++;
        } else {
            $this->smtpStats[$smtpKey]['failed']++;
        }
    }

    /**
     * Notify about email status
     * 
     * @param string $to Recipient email
     * @param bool $success Whether email was sent successfully
     */
    private function notifyEmailStatus($to, $success) {
        if (!$this->bot || !$this->currentChatId) {
            return;
        }

        if ($this->campaignStartTime === null) {
            $this->campaignStartTime = time();
        }

        $this->batchSize++;
        if ($success) {
            $this->batchSuccessCount++;
        }

        // DISABLED individual email notifications to prevent spam
        // Progress is handled by TelegramBot's campaign progress updates
        // This prevents duplicate notifications
        
        // Only send notifications for errors
        $now = time();
        if (!$success && ($now - $this->lastProgressUpdate >= $this->progressInterval)) {
            $hiddenEmail = preg_replace('/(?<=.).(?=.*@)/u', '*', $to);
            
            $message = "❌ Failed to send to `{$hiddenEmail}`";
            
            try {
                $this->bot->sendTelegramMessage($this->currentChatId, $message, [
                    'parse_mode' => 'Markdown',
                    'disable_notification' => true
                ]);
                $this->lastProgressUpdate = $now;
            } catch (\Exception $e) {
                // Ignore notification errors
            }
        }
    }

    /**
     * Mask sensitive string for display
     * 
     * @param string $str String to mask
     * @return string Masked string
     */
    private function maskString($str) {
        if (strlen($str) <= 4) {
            return $str;
        }
        return substr($str, 0, 2) . str_repeat('*', strlen($str) - 4) . substr($str, -2);
    }

    /**
     * Set current chat ID for notifications
     * 
     * @param string $chatId Chat ID
     * @return self
     */
    public function setCurrentChatId($chatId) {
        $this->currentChatId = $chatId;
        return $this;
    }

    /**
     * Reset campaign statistics
     */
    public function resetCampaignStats() {
        $this->batchSize = 0;
        $this->batchSuccessCount = 0;
        $this->lastProgressUpdate = 0;
        $this->campaignStartTime = time();
    }

    /**
     * Cleanup resources
     */
    public function cleanup() {
        if ($this->mailer) {
            try {
                $this->mailer->smtpClose();
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }
    }

    /**
     * Get current statistics
     * 
     * @return array Statistics
     */
    public function getStats() {
        return [
            'batch_size' => $this->batchSize,
            'batch_success' => $this->batchSuccessCount,
            'success_rate' => $this->batchSize > 0 ? round(($this->batchSuccessCount / $this->batchSize) * 100, 1) : 0,
            'current_smtp_index' => $this->currentSmtpIndex,
            'emails_with_current_smtp' => $this->emailsSentWithCurrentSmtp,
            'smtp_stats' => $this->smtpStats
        ];
    }

    /**
     * Get current SMTP configuration
     * 
     * @return array Current SMTP config
     */
    public function getCurrentSmtp() {
        return $this->smtpConfigs[$this->currentSmtpIndex] ?? [];
    }

    /**
     * Get current consecutive error count
     */
    public function getErrorCount() {
        return $this->errorCount;
    }

    /**
     * Reset consecutive error count
     */
    public function resetErrorCount() {
        $this->errorCount = 0;
    }
}
