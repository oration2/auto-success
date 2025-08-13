<?php

namespace App\Core;

/**
 * Command Registry
 * 
 * Manages command handlers and routes commands to appropriate handlers
 */
class CommandRegistry
{
    // Command handlers
    private $commandHandlers = [];
    
    // Bot reference
    private $bot;
    
    // Admin IDs
    private $adminIds = [];
    
    // Logger reference
    private $logger;
    
    /**
     * Constructor
     * 
     * @param object $bot TelegramBot instance
     * @param array $adminIds Admin chat IDs
     * @param object|null $logger Logger instance
     */
    public function __construct($bot, $adminIds = [], $logger = null)
    {
        $this->bot = $bot;
        $this->adminIds = $adminIds;
        $this->logger = $logger;
    }
    
    /**
     * Register command handler
     * 
     * @param string $handlerClass Command handler class name
     * @return bool Success status
     */
    public function registerHandler($handlerClass)
    {
        // Check if class exists
        if (!class_exists($handlerClass)) {
            if ($this->logger) {
                $this->logger->error("Command handler class not found: {$handlerClass}");
            }
            return false;
        }
        
        // Instantiate handler
        try {
            $handler = new $handlerClass($this->bot);
            
            // Get command name
            $command = $handler->getCommand();
            
            // Register handler
            $this->commandHandlers[$command] = $handler;
            
            return true;
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to register command handler: " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Register multiple handlers
     * 
     * @param array $handlerClasses Array of handler class names
     * @return int Number of successfully registered handlers
     */
    public function registerHandlers($handlerClasses)
    {
        $count = 0;
        
        foreach ($handlerClasses as $handlerClass) {
            if ($this->registerHandler($handlerClass)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Handle command
     * 
     * @param string $command Command name
     * @param string $chatId Chat ID
     * @param string $message Full message text
     * @param array $params Additional parameters
     * @return bool Success status
     */
    public function handleCommand($command, $chatId, $message, $params = [])
    {
        // Check if command exists
        if (!isset($this->commandHandlers[$command])) {
            if ($this->logger) {
                $this->logger->warning("Unknown command: {$command}");
            }
            return false;
        }
        
        $handler = $this->commandHandlers[$command];
        
        // Check admin permission
        if ($handler->isAdminOnly() && !$this->isAdmin($chatId)) {
            if ($this->logger) {
                $this->logger->warning("Unauthorized access to admin command: {$command} by {$chatId}");
            }
            
            // Send unauthorized message
            $this->bot->sendTelegramMessage($chatId, "⛔ You are not authorized to use this command.");
            return false;
        }
        
        // Handle command
        try {
            return $handler->handle($chatId, $message, $params);
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error("Command handler error: " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Check if user is admin
     * 
     * @param string $chatId Chat ID
     * @return bool Admin status
     */
    public function isAdmin($chatId)
    {
        return in_array($chatId, $this->adminIds);
    }
    
    /**
     * Get all registered commands
     * 
     * @param bool $adminOnly Get only admin commands
     * @return array Commands list
     */
    public function getCommands($adminOnly = false)
    {
        $commands = [];
        
        foreach ($this->commandHandlers as $command => $handler) {
            if ($adminOnly && !$handler->isAdminOnly()) {
                continue;
            }
            
            $commands[$command] = [
                'description' => $handler->getDescription(),
                'usage' => $handler->getUsage(),
                'adminOnly' => $handler->isAdminOnly()
            ];
        }
        
        return $commands;
    }
    
    /**
     * Get command handler
     * 
     * @param string $command Command name
     * @return object|null Command handler
     */
    public function getHandler($command)
    {
        return $this->commandHandlers[$command] ?? null;
    }
    
    /**
     * Parse command from message
     * 
     * @param string $message Message text
     * @param string $botUsername Bot username
     * @return array|null Parsed command data
     */
    public function parseCommand($message, $botUsername = '')
    {
        // Check if message starts with /
        if (empty($message) || $message[0] !== '/') {
            return null;
        }
        
        // Extract command
        $parts = explode(' ', $message, 2);
        $commandPart = $parts[0];
        $args = isset($parts[1]) ? trim($parts[1]) : '';
        
        // Extract command name (remove @ part if present)
        $atPos = strpos($commandPart, '@');
        if ($atPos !== false) {
            $targetUsername = substr($commandPart, $atPos + 1);
            // Check if command is for this bot
            if ($botUsername && $targetUsername !== $botUsername) {
                return null;
            }
            $command = substr($commandPart, 1, $atPos - 1);
        } else {
            $command = substr($commandPart, 1);
        }
        
        return [
            'command' => $command,
            'args' => $args,
            'full' => $message
        ];
    }
}
