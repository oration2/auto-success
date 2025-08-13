<?php

namespace App\Services;

/**
 * Configuration Manager
 * 
 * Handles loading and managing configuration settings
 */
class ConfigManager
{
    // Configuration data
    private $config = [];
    
    // Default configuration paths
    private $configFile;
    private $userDataFile;
    
    // Configuration update callbacks
    private $updateCallbacks = [];
    
    /**
     * Constructor
     * 
     * @param string $configFile Configuration file path
     * @param string $userFile User data file path
     */
    public function __construct($configFile, $userFile)
    {
        $this->configFile = $configFile;
        $this->userDataFile = $userFile;
        
        $this->loadConfig();
    }
    
    /**
     * Load configuration from file
     * 
     * @return bool Success status
     */
    private function loadConfig()
    {
        if (!$this->configFile || !file_exists($this->configFile)) {
            return false;
        }
        
        // Include config file to get defined constants (only if not already loaded)
        if (!defined('BOT_TOKEN')) {
            include $this->configFile;
        }
        
        // Initialize config structure
        $this->config = [
            'bot' => [
                'token' => defined('BOT_TOKEN') ? BOT_TOKEN : null,
                'admins' => defined('ADMIN_CHAT_IDS') ? ADMIN_CHAT_IDS : [],
                'username' => 'Cheto_inboxing_bot'
            ],
            'smtp' => [
                'timeout' => defined('SMTP_TIMEOUT') ? SMTP_TIMEOUT : 10,
                'rotation_threshold' => defined('SMTP_ROTATION_THRESHOLD') ? SMTP_ROTATION_THRESHOLD : 3,
                'error_cooldown' => defined('SMTP_ERROR_COOLDOWN') ? SMTP_ERROR_COOLDOWN : 3600,
                'file' => dirname($this->configFile) . '/smtps.txt'
            ],
            'paths' => [
                'logs' => defined('LOG_FILE') ? LOG_FILE : dirname($this->configFile) . '/../logs/bot.log',
                'uploads' => defined('UPLOADS_DIR') ? UPLOADS_DIR : dirname($this->configFile) . '/../uploads/',
                'user_data' => defined('USER_DATA_FILE') ? USER_DATA_FILE : dirname($this->configFile) . '/users.json'
            ],
            'limits' => [
                'emails_per_day' => defined('MAX_EMAILS_PER_DAY') ? MAX_EMAILS_PER_DAY : 10,
                'emails_per_month' => defined('MAX_EMAILS_PER_MONTH') ? MAX_EMAILS_PER_MONTH : 100
            ],
            'plans' => defined('PLANS') ? PLANS : [],
            'additional_services' => defined('ADDITIONAL_SERVICES') ? ADDITIONAL_SERVICES : [],
            'marketing' => defined('MARKETING_SETTINGS') ? MARKETING_SETTINGS : [],
            'trial' => [
                'subject' => defined('DEFAULT_TRIAL_SUBJECT') ? DEFAULT_TRIAL_SUBJECT : 'Test from ChetoBot'
            ],
            'errors' => [
                'not_authorized' => defined('ERR_NOT_AUTHORIZED') ? ERR_NOT_AUTHORIZED : 'You are not authorized to use this command.',
                'invalid_smtp' => defined('ERR_INVALID_SMTP') ? ERR_INVALID_SMTP : 'Invalid SMTP settings.',
                'daily_limit' => defined('ERR_DAILY_LIMIT') ? ERR_DAILY_LIMIT : 'You have reached your daily email limit.',
                'monthly_limit' => defined('ERR_MONTHLY_LIMIT') ? ERR_MONTHLY_LIMIT : 'You have reached your monthly email limit.',
                'missing_files' => defined('ERR_MISSING_FILES') ? ERR_MISSING_FILES : 'Please upload required files.',
                'missing_smtp' => defined('ERR_MISSING_SMTP') ? ERR_MISSING_SMTP : 'Please set your SMTP details.'
            ],
            'log_level' => 'INFO'
        ];
        
        // Trigger update callbacks
        $this->triggerUpdateCallbacks();
        
        return true;
    }
    
    /**
     * Get configuration value
     * 
     * @param string $key Configuration key using dot notation (e.g. 'bot.token')
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public function get($key, $default = null)
    {
        $parts = explode('.', $key);
        $config = $this->config;
        
        foreach ($parts as $part) {
            if (!isset($config[$part])) {
                return $default;
            }
            $config = $config[$part];
        }
        
        return $config;
    }
    
    /**
     * Set configuration value
     * 
     * @param string $key Configuration key using dot notation
     * @param mixed $value Configuration value
     * @param bool $persist Whether to persist change to file
     * @return bool Success status
     */
    public function set($key, $value, $persist = false)
    {
        $parts = explode('.', $key);
        $config = &$this->config;
        
        $lastIndex = count($parts) - 1;
        
        for ($i = 0; $i < $lastIndex; $i++) {
            $part = $parts[$i];
            
            if (!isset($config[$part]) || !is_array($config[$part])) {
                $config[$part] = [];
            }
            
            $config = &$config[$part];
        }
        
        $config[$parts[$lastIndex]] = $value;
        
        // Persist changes if requested
        if ($persist) {
            return $this->saveConfig();
        }
        
        // Trigger update callbacks
        $this->triggerUpdateCallbacks($key);
        
        return true;
    }
    
    /**
     * Save configuration to file
     * 
     * @return bool Success status
     */
    public function saveConfig()
    {
        // Currently not implemented - would require generating PHP code
        // or saving to a separate JSON/YAML file
        return false;
    }
    
    /**
     * Load user data from file
     * 
     * @return array User data
     */
    public function loadUserData()
    {
        if (!$this->userDataFile || !file_exists($this->userDataFile)) {
            return [];
        }
        
        $json = file_get_contents($this->userDataFile);
        if (!$json) {
            return [];
        }
        
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        
        return $data;
    }
    
    /**
     * Save user data to file
     * 
     * @param array $userData User data to save
     * @return bool Success status
     */
    public function saveUserData($userData)
    {
        if (!$this->userDataFile) {
            return false;
        }
        
        $json = json_encode($userData, JSON_PRETTY_PRINT);
        if (!$json) {
            return false;
        }
        
        $dir = dirname($this->userDataFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return file_put_contents($this->userDataFile, $json) !== false;
    }
    
    /**
     * Register update callback
     * 
     * @param string $key Configuration key to watch
     * @param callable $callback Callback function
     */
    public function onUpdate($key, callable $callback)
    {
        if (!isset($this->updateCallbacks[$key])) {
            $this->updateCallbacks[$key] = [];
        }
        
        $this->updateCallbacks[$key][] = $callback;
    }
    
    /**
     * Trigger update callbacks
     * 
     * @param string|null $key Updated configuration key
     */
    private function triggerUpdateCallbacks($key = null)
    {
        if ($key !== null) {
            // Trigger specific key callbacks
            if (isset($this->updateCallbacks[$key])) {
                $value = $this->get($key);
                foreach ($this->updateCallbacks[$key] as $callback) {
                    call_user_func($callback, $value);
                }
            }
        } else {
            // Trigger all callbacks
            foreach ($this->updateCallbacks as $key => $callbacks) {
                $value = $this->get($key);
                foreach ($callbacks as $callback) {
                    call_user_func($callback, $value);
                }
            }
        }
    }
    
    /**
     * Get all configuration
     * 
     * @return array Full configuration
     */
    public function getAll()
    {
        return $this->config;
    }
}
