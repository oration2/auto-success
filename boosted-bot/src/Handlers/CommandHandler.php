<?php

namespace App\Handlers;

/**
 * Command Handler
 * 
 * Base class for handling Telegram bot commands
 */
abstract class CommandHandler
{
    // Bot reference
    protected $bot;
    
    // Command information
    protected $command;
    protected $description;
    protected $usage;
    protected $adminOnly = false;
    
    /**
     * Constructor
     * 
     * @param object $bot TelegramBot instance
     */
    public function __construct($bot)
    {
        $this->bot = $bot;
    }
    
    /**
     * Get command
     * 
     * @return string Command
     */
    public function getCommand()
    {
        return $this->command;
    }
    
    /**
     * Get command description
     * 
     * @return string Description
     */
    public function getDescription()
    {
        return $this->description;
    }
    
    /**
     * Get command usage
     * 
     * @return string Usage
     */
    public function getUsage()
    {
        return $this->usage;
    }
    
    /**
     * Check if command is admin-only
     * 
     * @return bool Admin-only status
     */
    public function isAdminOnly()
    {
        return $this->adminOnly;
    }
    
    /**
     * Handle command
     * 
     * @param string $chatId Chat ID
     * @param string $message Message text
     * @param array $params Additional parameters
     * @return bool Success status
     */
    abstract public function handle($chatId, $message, $params = []);
}
