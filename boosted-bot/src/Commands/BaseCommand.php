<?php

namespace App\Commands;

use App\Interfaces\CommandInterface;
use App\Services\ConfigManager;
use App\Services\Logger;
use App\Services\TelegramAPI;
use App\Services\UserService;

/**
 * Base Command Class
 * 
 * Base class for all bot commands
 */
abstract class BaseCommand implements CommandInterface
{
    protected $config;
    protected $logger;
    protected $telegramApi;
    protected $userService;
    protected $name;
    protected $description;
    protected $adminOnly = false;
    protected $requiresAuth = true;

    public function __construct(
        ConfigManager $config = null,
        Logger $logger = null,
        TelegramAPI $telegramApi = null,
        UserService $userService = null
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->telegramApi = $telegramApi;
        $this->userService = $userService;
    }

    /**
     * Get command name
     * 
     * @return string Command name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get command description
     * 
     * @return string Command description
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Check if user is authorized to use this command
     * 
     * @param string $chatId User chat ID
     * @return bool Is authorized
     */
    public function isAuthorized($chatId)
    {
        if (!$this->requiresAuth) {
            return true;
        }

        if ($this->adminOnly) {
            return $this->isAdmin($chatId);
        }

        // Check if user exists
        $userData = $this->userService?->getUserData($chatId);
        return $userData !== null;
    }

    /**
     * Check if user is admin
     * 
     * @param string $chatId User chat ID
     * @return bool Is admin
     */
    protected function isAdmin($chatId)
    {
        $adminIds = $this->config?->get('admin_chat_ids', []);
        return in_array($chatId, $adminIds);
    }

    /**
     * Send message to user
     * 
     * @param string $chatId Chat ID
     * @param string $message Message text
     * @param array $options Additional options
     * @return array Result
     */
    protected function sendMessage($chatId, $message, $options = [])
    {
        try {
            return $this->telegramApi?->sendMessage($chatId, $message, $options) ?? ['success' => false];
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send message', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send typing action
     * 
     * @param string $chatId Chat ID
     */
    protected function sendTyping($chatId)
    {
        try {
            $this->telegramApi?->sendChatAction($chatId, 'typing');
        } catch (\Exception $e) {
            // Ignore typing errors
        }
    }

    /**
     * Get user friendly error message
     * 
     * @param string $error Error message
     * @return string User friendly message
     */
    protected function getUserFriendlyError($error)
    {
        $errorMessages = $this->config?->get('error_messages', []);
        
        // Map common errors to user-friendly messages
        $errorMap = [
            'unauthorized' => $errorMessages['unauthorized'] ?? 'You are not authorized to use this command.',
            'invalid_plan' => $errorMessages['invalid_plan'] ?? 'Your current plan does not support this feature.',
            'rate_limit' => $errorMessages['rate_limit'] ?? 'You have reached your rate limit. Please try again later.',
            'internal_error' => $errorMessages['internal_error'] ?? 'An internal error occurred. Please try again later.'
        ];

        return $errorMap[$error] ?? $error;
    }

    /**
     * Format plan information for display
     * 
     * @param array $plan Plan data
     * @return string Formatted plan info
     */
    protected function formatPlanInfo($plan)
    {
        if (!$plan) {
            return "❌ No plan information available";
        }

        $status = $plan['expired'] ? "❌ Expired" : "✅ Active";
        $expiry = $plan['expiry_time'] > 0 ? date('Y-m-d H:i:s', $plan['expiry_time']) : 'No expiry';

        return sprintf(
            "📋 *Plan Information*\n\n" .
            "Plan: %s\n" .
            "Status: %s\n" .
            "Expires: %s\n" .
            "Emails per hour: %d\n" .
            "Emails per day: %d\n" .
            "Max campaigns: %d",
            $plan['display_name'],
            $status,
            $expiry,
            $plan['emails_per_hour'],
            $plan['emails_per_day'],
            $plan['max_campaigns']
        );
    }

    /**
     * Validate required parameters
     * 
     * @param array $params Parameters to validate
     * @param array $required Required parameter names
     * @return array Validation result
     */
    protected function validateParams($params, $required)
    {
        $missing = [];
        
        foreach ($required as $param) {
            if (!isset($params[$param]) || empty($params[$param])) {
                $missing[] = $param;
            }
        }

        if (!empty($missing)) {
            return [
                'valid' => false,
                'missing' => $missing,
                'message' => 'Missing required parameters: ' . implode(', ', $missing)
            ];
        }

        return ['valid' => true];
    }

    /**
     * Parse command arguments from message text
     * 
     * @param string $text Message text
     * @return array Parsed arguments
     */
    protected function parseArgs($text)
    {
        // Remove command from start of text
        $text = preg_replace('/^\/\w+\s*/', '', $text);
        
        // Split by whitespace, preserving quoted strings
        preg_match_all('/(?:"([^"]*)")|(\S+)/', $text, $matches);
        
        $args = [];
        foreach ($matches[0] as $match) {
            $args[] = trim($match, '"');
        }

        return array_filter($args);
    }

    /**
     * Log command execution
     * 
     * @param string $chatId Chat ID
     * @param array $context Command context
     */
    protected function logExecution($chatId, $context = [])
    {
        $this->logger?->info('Command executed', [
            'command' => $this->getName(),
            'chat_id' => $chatId,
            'context' => $context
        ]);
    }
}
