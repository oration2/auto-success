<?php

namespace App\Interfaces;

/**
 * Command Interface
 * 
 * Interface for bot commands
 */
interface CommandInterface
{
    /**
     * Execute the command
     * 
     * @param array $message Telegram message
     * @param array $context Command context
     * @return array Response data
     */
    public function execute($message, $context = []);

    /**
     * Get command name
     * 
     * @return string Command name
     */
    public function getName();

    /**
     * Get command description
     * 
     * @return string Command description
     */
    public function getDescription();

    /**
     * Check if user is authorized to use this command
     * 
     * @param string $chatId User chat ID
     * @return bool Is authorized
     */
    public function isAuthorized($chatId);
}
