<?php

namespace App\Interfaces;

/**
 * Service Interface
 * 
 * Base interface for all services
 */
interface ServiceInterface
{
    /**
     * Initialize the service
     * 
     * @return bool Success status
     */
    public function initialize();

    /**
     * Check if the service is healthy and operational
     */
    public function isHealthy(): bool;

    /**
     * Get the current status of the service
     */
    public function getStatus(): array;
}
