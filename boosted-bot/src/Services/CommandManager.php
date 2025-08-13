<?php

namespace App\Services;

use App\Interfaces\ServiceInterface;
use App\Interfaces\CommandInterface;

/**
 * Command Manager Service
 * 
 * Manages bot commands and routing
 */
class CommandManager implements ServiceInterface
{
    private $commands = [];
    private $config;
    private $logger;
    private $telegramApi;
    private $userService;
    private $services = [];
    
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
        
        // Store core services
        $this->services = [
            'config' => $config,
            'logger' => $logger,
            'telegramApi' => $telegramApi,
            'userService' => $userService
        ];
    }
    
    /**
     * Add a service to be injected into commands
     * 
     * @param string $name Service name
     * @param object $service Service instance
     */
    public function addService($name, $service)
    {
        $this->services[$name] = $service;
        $this->logger?->debug("Added service to CommandManager: $name");
    }

    /**
     * Initialize the service
     */
    public function initialize()
    {
        try {
            $this->registerCommands();
            $this->logger?->info('CommandManager initialized with ' . count($this->commands) . ' commands');
            return true;
        } catch (\Exception $e) {
            $this->logger?->error('CommandManager initialization failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Register all available commands
     */
    private function registerCommands()
    {
        // Use all available services for dependency injection
        $services = $this->services;

        // Register core commands
        $this->registerCommand('start', \App\Commands\StartCommand::class, $services);
        $this->registerCommand('status', \App\Commands\StatusCommand::class, $services);
        $this->registerCommand('help', \App\Commands\HelpCommand::class, $services);
        $this->registerCommand('plans', \App\Commands\PlansCommand::class, $services);
        $this->registerCommand('upload', \App\Commands\UploadCommand::class, $services);
        $this->registerCommand('smtp', \App\Commands\SmtpCommand::class, $services);
        $this->registerCommand('send', \App\Commands\SendCommand::class, $services);
        $this->registerCommand('template', \App\Commands\TemplateCommand::class, $services);
        
        // Admin commands
        $this->registerCommand('admin', \App\Commands\AdminCommand::class, $services);
        $this->registerCommand('stats', \App\Commands\StatsCommand::class, $services);
        $this->registerCommand('broadcast', \App\Commands\BroadcastCommand::class, $services);
    }

    /**
     * Register a command
     * 
     * @param string $name Command name
     * @param string $className Command class name
     * @param array $services Service dependencies
     */
    private function registerCommand($name, $className, $services)
    {
        try {
            if (!class_exists($className)) {
                $this->logger?->warning("Command class not found: $className");
                return;
            }

            // Create command instance with dependency injection
            $reflectionClass = new \ReflectionClass($className);
            $constructor = $reflectionClass->getConstructor();
            
            if ($constructor) {
                $parameters = $constructor->getParameters();
                $args = [];
                
                foreach ($parameters as $param) {
                    $paramName = $param->getName();
                    $args[] = $services[$paramName] ?? null;
                }
                
                $command = $reflectionClass->newInstanceArgs($args);
            } else {
                $command = new $className();
            }

            if ($command instanceof CommandInterface) {
                $this->commands[$name] = $command;
                $this->logger?->debug("Registered command: $name");
            } else {
                $this->logger?->warning("Command does not implement CommandInterface: $className");
            }

        } catch (\Exception $e) {
            $this->logger?->error("Failed to register command: $name", [
                'class' => $className,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle incoming message and route to appropriate command
     * 
     * @param array $message Telegram message
     * @return array Processing result
     */
    public function handleMessage($message)
    {
        try {
            $chatId = $message['chat']['id'] ?? '';
            $text = $message['text'] ?? '';
            
            if (empty($chatId)) {
                return ['success' => false, 'error' => 'Invalid chat ID'];
            }

            // Update user activity
            $this->updateUserActivity($chatId);

            // Check if it's a command
            $command = $this->extractCommand($text);
            
            if ($command) {
                return $this->executeCommand($command, $message);
            }

            // Handle non-command messages (conversation flow)
            return $this->handleConversation($message);

        } catch (\Exception $e) {
            $this->logger?->error('Message handling failed', [
                'message' => $message,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => 'Internal error'];
        }
    }

    /**
     * Extract command from text
     * 
     * @param string $text Message text
     * @return string|null Command name
     */
    private function extractCommand($text)
    {
        if (!str_starts_with($text, '/')) {
            return null;
        }

        // Extract command (remove / and @botname if present)
        $command = substr($text, 1);
        $command = explode(' ', $command)[0];
        $command = explode('@', $command)[0];
        
        return strtolower($command);
    }

    /**
     * Execute a command
     * 
     * @param string $commandName Command name
     * @param array $message Telegram message
     * @return array Execution result
     */
    public function executeCommand($commandName, $message)
    {
        $chatId = $message['chat']['id'] ?? '';

        try {
            if (!isset($this->commands[$commandName])) {
                // Try to handle unknown command
                return $this->handleUnknownCommand($chatId, $commandName);
            }

            $command = $this->commands[$commandName];

            // Check authorization
            if (!$command->isAuthorized($chatId)) {
                $this->telegramApi?->sendMessage($chatId, 
                    "❌ You are not authorized to use this command."
                );
                return ['success' => false, 'error' => 'Unauthorized'];
            }

            // Execute command
            $this->logger?->info('Executing command', [
                'command' => $commandName,
                'chat_id' => $chatId
            ]);

            $result = $command->execute($message, [
                'timestamp' => time(),
                'command_name' => $commandName
            ]);

            $this->logger?->info('Command executed', [
                'command' => $commandName,
                'chat_id' => $chatId,
                'success' => $result['success'] ?? false
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger?->error('Command execution failed', [
                'command' => $commandName,
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);

            $this->telegramApi?->sendMessage($chatId, 
                "❌ An error occurred while processing your command. Please try again."
            );

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Handle unknown command
     * 
     * @param string $chatId Chat ID
     * @param string $commandName Command name
     * @return array Result
     */
    private function handleUnknownCommand($chatId, $commandName)
    {
        $availableCommands = array_keys($this->commands);
        $suggestions = $this->findSimilarCommands($commandName, $availableCommands);

        $message = "❓ Unknown command: /$commandName\n\n";
        
        if (!empty($suggestions)) {
            $message .= "Did you mean:\n";
            foreach ($suggestions as $suggestion) {
                $message .= "• /$suggestion\n";
            }
            $message .= "\n";
        }
        
        $message .= "Use /help to see all available commands.";

        $this->telegramApi?->sendMessage($chatId, $message);
        
        return ['success' => false, 'error' => 'Unknown command'];
    }

    /**
     * Find similar commands using basic string similarity
     * 
     * @param string $input Input command
     * @param array $commands Available commands
     * @return array Similar commands
     */
    private function findSimilarCommands($input, $commands)
    {
        $suggestions = [];
        
        foreach ($commands as $command) {
            $similarity = 0;
            similar_text($input, $command, $similarity);
            
            if ($similarity > 60) { // 60% similarity threshold
                $suggestions[] = $command;
            }
        }

        // Sort by similarity
        usort($suggestions, function($a, $b) use ($input) {
            $simA = 0;
            $simB = 0;
            similar_text($input, $a, $simA);
            similar_text($input, $b, $simB);
            return $simB <=> $simA;
        });

        return array_slice($suggestions, 0, 3); // Return top 3 suggestions
    }

    /**
     * Handle conversation flow (non-command messages)
     * 
     * @param array $message Telegram message
     * @return array Result
     */
    private function handleConversation($message)
    {
        $chatId = $message['chat']['id'] ?? '';
        $text = $message['text'] ?? '';

        // Check for button/keyboard responses
        if ($this->handleKeyboardResponse($chatId, $text)) {
            return ['success' => true, 'type' => 'keyboard_response'];
        }

        // Handle file uploads
        if (isset($message['document']) || isset($message['photo'])) {
            return $this->handleFileUpload($message);
        }

        // Default response for unrecognized input
        $this->telegramApi?->sendMessage($chatId, 
            "🤔 I don't understand. Use /help to see available commands or choose from the menu."
        );

        return ['success' => true, 'type' => 'unrecognized'];
    }

    /**
     * Handle keyboard button responses
     * 
     * @param string $chatId Chat ID
     * @param string $text Button text
     * @return bool Was handled
     */
    private function handleKeyboardResponse($chatId, $text)
    {
        $buttonCommands = [
            '📤 Send Campaign' => 'send',
            '📊 My Status' => 'status',
            '📁 Upload Files' => 'upload',
            '⚙️ SMTP Config' => 'smtp',
            '💎 Plans & Pricing' => 'plans',
            '❓ Help' => 'help'
        ];

        if (isset($buttonCommands[$text])) {
            $command = $buttonCommands[$text];
            $this->executeCommand($command, [
                'chat' => ['id' => $chatId],
                'text' => "/$command",
                'from' => []
            ]);
            return true;
        }

        return false;
    }

    /**
     * Handle file uploads
     * 
     * @param array $message Telegram message
     * @return array Result
     */
    private function handleFileUpload($message)
    {
        // Redirect to upload command
        return $this->executeCommand('upload', $message);
    }

    /**
     * Update user last activity
     * 
     * @param string $chatId Chat ID
     */
    private function updateUserActivity($chatId)
    {
        try {
            $userData = $this->userService?->getUserData($chatId);
            if ($userData) {
                $userData['last_activity'] = time();
                $this->userService?->updateUser($chatId, $userData);
            }
        } catch (\Exception $e) {
            // Ignore activity update errors
        }
    }

    /**
     * Get list of available commands
     * 
     * @param string $chatId Chat ID (for authorization check)
     * @return array Commands list
     */
    public function getAvailableCommands($chatId)
    {
        $availableCommands = [];
        
        foreach ($this->commands as $name => $command) {
            if ($command->isAuthorized($chatId)) {
                $availableCommands[$name] = [
                    'name' => $name,
                    'description' => $command->getDescription()
                ];
            }
        }

        return $availableCommands;
    }

    /**
     * Handle callback queries (inline keyboard buttons)
     * 
     * @param array $callbackQuery Callback query data
     * @return array Result
     */
    public function handleCallbackQuery($callbackQuery)
    {
        try {
            $chatId = $callbackQuery['message']['chat']['id'] ?? '';
            $data = $callbackQuery['data'] ?? '';
            $queryId = $callbackQuery['id'] ?? '';

            if (empty($chatId) || empty($data)) {
                return ['success' => false, 'error' => 'Invalid callback data'];
            }

            // Answer the callback query to remove loading state
            $this->telegramApi?->answerCallbackQuery($queryId);

            // Handle different callback data
            return $this->processCallbackData($chatId, $data, $callbackQuery);

        } catch (\Exception $e) {
            $this->logger?->error('Callback query handling failed', [
                'callback_query' => $callbackQuery,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => 'Internal error'];
        }
    }

    /**
     * Process callback data
     * 
     * @param string $chatId Chat ID
     * @param string $data Callback data
     * @param array $callbackQuery Full callback query
     * @return array Result
     */
    private function processCallbackData($chatId, $data, $callbackQuery)
    {
        // Parse callback data (format: command_action or command_action_param)
        $parts = explode('_', $data);
        $command = $parts[0] ?? '';
        $action = $parts[1] ?? '';

        // Route to appropriate command with callback context
        if (isset($this->commands[$command])) {
            $message = [
                'chat' => ['id' => $chatId],
                'text' => "/$command",
                'from' => $callbackQuery['from'] ?? []
            ];

            $context = [
                'callback_query' => $callbackQuery,
                'action' => $action,
                'data' => $data
            ];

            return $this->commands[$command]->execute($message, $context);
        }

        // Handle generic callback data
        switch ($data) {
            case 'main_menu':
                return $this->executeCommand('start', [
                    'chat' => ['id' => $chatId],
                    'text' => '/start',
                    'from' => $callbackQuery['from'] ?? []
                ]);

            case 'refresh':
                // Refresh current view (could be more specific)
                $this->telegramApi?->sendMessage($chatId, "🔄 Refreshed!");
                return ['success' => true];

            default:
                $this->telegramApi?->sendMessage($chatId, "❓ Unknown action: $data");
                return ['success' => false, 'error' => 'Unknown callback data'];
        }
    }

    /**
     * Check if the service is healthy and operational
     */
    public function isHealthy(): bool
    {
        // Service is healthy if properly initialized, even without commands loaded yet
        return $this->config !== null && $this->logger !== null;
    }

    /**
     * Get the current status of the service
     */
    public function getStatus(): array
    {
        return [
            'service' => 'CommandManager',
            'status' => 'operational',
            'commands_loaded' => count($this->commands),
            'available_commands' => array_keys($this->commands)
        ];
    }
}
