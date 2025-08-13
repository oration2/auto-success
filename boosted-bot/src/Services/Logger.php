<?php

namespace App\Services;

/**
 * Logger Service
 * 
 * Handles log messages with different severity levels
 */
class Logger
{
    // Log file path
    private $logFile;
    
    // Log levels
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_CRITICAL = 'CRITICAL';
    
    // Current minimum log level
    private $minLevel = self::LEVEL_INFO;
    
    // Level mapping for severity
    private $levelOrder = [
        self::LEVEL_DEBUG => 0,
        self::LEVEL_INFO => 1,
        self::LEVEL_WARNING => 2,
        self::LEVEL_ERROR => 3,
        self::LEVEL_CRITICAL => 4
    ];
    
    /**
     * Constructor
     * 
     * @param string $logFile Path to log file
     * @param string $minLevel Minimum log level
     */
    public function __construct($logFile, $minLevel = self::LEVEL_INFO)
    {
        $this->logFile = $logFile;
        $this->setMinLevel($minLevel);
        
        // Create log directory if it doesn't exist
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Set minimum log level
     * 
     * @param string $level Log level
     * @return bool Success status
     */
    public function setMinLevel($level)
    {
        if (isset($this->levelOrder[$level])) {
            $this->minLevel = $level;
            return true;
        }
        return false;
    }
    
    /**
     * Log a message
     * 
     * @param string $message Log message
     * @param string $level Log level
     * @param array $context Additional context data
     * @return bool Success status
     */
    public function log($message, $level = self::LEVEL_INFO, array $context = [])
    {
        // Check if level meets minimum threshold
        if (!isset($this->levelOrder[$level]) || $this->levelOrder[$level] < $this->levelOrder[$this->minLevel]) {
            return false;
        }
        
        // Format log entry
        $timestamp = date('[Y-m-d H:i:s]');
        $formattedMessage = $timestamp . ' [' . $level . '] ' . $message;
        
        // Add context if provided
        if (!empty($context)) {
            $contextStr = json_encode($context);
            $formattedMessage .= ' ' . $contextStr;
        }
        
        $formattedMessage .= PHP_EOL;
        
        // Write to log file
        return file_put_contents($this->logFile, $formattedMessage, FILE_APPEND) !== false;
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function debug($message, array $context = [])
    {
        return $this->log($message, self::LEVEL_DEBUG, $context);
    }
    
    /**
     * Log info message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function info($message, array $context = [])
    {
        return $this->log($message, self::LEVEL_INFO, $context);
    }
    
    /**
     * Log warning message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function warning($message, array $context = [])
    {
        return $this->log($message, self::LEVEL_WARNING, $context);
    }
    
    /**
     * Log error message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function error($message, array $context = [])
    {
        return $this->log($message, self::LEVEL_ERROR, $context);
    }
    
    /**
     * Log critical message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function critical($message, array $context = [])
    {
        return $this->log($message, self::LEVEL_CRITICAL, $context);
    }
    
    /**
     * Rotate log file if it exceeds size limit
     * 
     * @param int $maxSize Maximum log file size in bytes
     * @param int $keepLogs Number of archived logs to keep
     */
    public function rotateLogIfNeeded($maxSize = 10485760, $keepLogs = 5)
    {
        if (!file_exists($this->logFile)) {
            return;
        }
        
        if (filesize($this->logFile) < $maxSize) {
            return;
        }
        
        // Archive the current log
        $timestamp = date('YmdHis');
        $archiveFile = $this->logFile . '.' . $timestamp;
        rename($this->logFile, $archiveFile);
        
        // Clean up old archives
        $logDir = dirname($this->logFile);
        $baseFilename = basename($this->logFile);
        $archives = glob($logDir . '/' . $baseFilename . '.*');
        
        if (count($archives) > $keepLogs) {
            // Sort by modification time (oldest first)
            usort($archives, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Delete oldest archives
            $toDelete = count($archives) - $keepLogs;
            for ($i = 0; $i < $toDelete; $i++) {
                unlink($archives[$i]);
            }
        }
    }
}
