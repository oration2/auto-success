# Refactored Telegram Email Bot Architecture

## Overview

This is a comprehensive refactoring of the Telegram email campaign bot into a modern, service-oriented architecture. The refactoring maintains compatibility with existing configuration and data while providing a clean, maintainable, and extensible codebase.

## Architecture

### Service-Oriented Design

The bot is built around independent services that handle specific responsibilities:

- **ConfigManager**: Configuration management with PHP defines integration
- **LoggerService**: Centralized logging with multiple levels
- **TelegramApiService**: Telegram API communication
- **UserService**: User data management and persistence
- **PlanService**: Plan validation, limits checking, upgrade recommendations
- **WorkflowService**: Email campaign orchestration and state management
- **EmailService**: Email sending with SMTP rotation and user limits
- **CommandManager**: Command routing and execution

### Core Components

#### 1. TelegramBotCore (`src/Core/TelegramBotCore.php`)
Main orchestrator that:
- Initializes all services with dependency injection
- Handles webhook requests and routes to appropriate handlers
- Provides health checking and monitoring
- Maintains backward compatibility with legacy code

#### 2. Service Layer (`src/Services/`)
Independent, testable services that can be used individually or together:

**ConfigManager** - Bridges PHP defines with modern configuration access
**LoggerService** - Structured logging with context and levels
**TelegramApiService** - Clean API for Telegram operations
**UserService** - User data CRUD operations with validation
**PlanService** - Plan management, validation, and limit checking
**WorkflowService** - Campaign lifecycle management
**EmailService** - High-level email operations wrapping EmailSender
**CommandManager** - Command registration, parsing, and execution

#### 3. Command System (`src/Commands/`)
Modern command pattern implementation:

**BaseCommand** - Common functionality for all commands
**StartCommand** - User onboarding and welcome flow
**StatusCommand** - Comprehensive user status and statistics
**Additional Commands** - Extensible command system for new features

#### 4. Interfaces (`src/Interfaces/`)
Clean contracts for extensibility:

**ServiceInterface** - Standard service initialization
**CommandInterface** - Command execution contract

## Configuration Integration

### Existing Config.php Compatibility

The refactored system maintains full compatibility with the existing `config/config.php`:

```php
// Bot configuration
define('BOT_TOKEN', 'your_bot_token');
define('ADMIN_CHAT_IDS', [123456789, 987654321]);

// Plans configuration
define('PLANS', [
    'trial' => [
        'name' => 'Trial Plan',
        'duration' => 3,
        'emails_per_hour' => 100,
        'emails_per_day' => 500,
        'price' => 0
    ],
    'rotation_30' => [
        'name' => '30-Day Rotation',
        'duration' => 30,
        'emails_per_hour' => 3000,
        'emails_per_day' => 15000,
        'price' => 1300
    ]
]);
```

### Data Persistence

Uses existing `config/users.json` structure:
- User plans and expiry dates
- SMTP configurations per user
- File uploads and templates
- Usage statistics and limits

## Key Features

### 1. Service Architecture Benefits
- **Separation of Concerns**: Each service handles one responsibility
- **Dependency Injection**: Clean service dependencies
- **Testability**: Services can be tested individually
- **Extensibility**: Easy to add new services or modify existing ones

### 2. Plan Management
```php
$planService = new PlanService($config, $logger, $userService);

// Validate email limits
$validation = $planService->validateEmailLimits($chatId, $emailCount);
if (!$validation['allowed']) {
    // Handle limit exceeded
}

// Get upgrade recommendations
$recommendations = $planService->getUpgradeRecommendations($chatId);
```

### 3. Workflow Management
```php
$workflowService = new WorkflowService($config, $logger, $userService, $planService);

// Prepare campaign
$workflowId = $workflowService->prepareCampaign($chatId, $campaignData);

// Track progress
$workflowService->updateCampaignProgress($workflowId, 75);
```

### 4. Email Service Integration
```php
$emailService = new EmailService($config, $logger, $planService, $userService, $workflowService);

// Send campaign with automatic validation and tracking
$result = $emailService->sendCampaign($chatId, $campaignData);
```

### 5. Command System
```php
class CustomCommand extends BaseCommand
{
    protected $name = 'custom';
    protected $description = 'Custom command functionality';
    
    public function execute($message, $context = [])
    {
        $chatId = $message['chat']['id'];
        // Command logic here
        return ['success' => true];
    }
}
```

## Usage

### Basic Setup

1. **Initialize the bot**:
```php
$bot = new TelegramBotCore();
$bot->initialize();
```

2. **Handle webhook requests**:
```php
$update = json_decode(file_get_contents('php://input'), true);
$result = $bot->handleWebhook($update);
```

3. **Use individual services**:
```php
$userService = $bot->getService('userService');
$userData = $userService->getUserData($chatId);
```

### Entry Points

- **bot_new.php**: Main entry point for webhook and CLI
- **webhook.php**: Can be created for webhook-only usage
- **cli.php**: Can be created for command-line operations

### Health Monitoring

```php
$health = $bot->healthCheck();
// Returns comprehensive health status for all services
```

## Migration from Legacy Code

### Backward Compatibility

The refactored system maintains compatibility with existing:
- Configuration files (`config/config.php`)
- User data (`config/users.json`)
- Upload directories and file structure
- SMTP configurations
- Plan definitions

### Migration Strategy

1. **Phase 1**: Run new architecture alongside existing bot
2. **Phase 2**: Gradually migrate commands to new system
3. **Phase 3**: Switch webhook to new entry point
4. **Phase 4**: Remove legacy code

### Legacy Method Support

The TelegramBotCore includes legacy compatibility methods:
```php
// Legacy methods still work
$bot->sendTelegramMessage($chatId, $message);
$bot->isAdmin($chatId);
$bot->getUserData($chatId);
```

## Testing

Each service can be tested independently:

```php
// Test plan service
$planService = new PlanService($mockConfig, $mockLogger, $mockUserService);
$result = $planService->validateEmailLimits('123', 100);
$this->assertTrue($result['allowed']);
```

## Logging

Comprehensive logging throughout the system:
```php
$logger->info('Campaign started', ['chat_id' => $chatId, 'email_count' => 100]);
$logger->error('SMTP connection failed', ['smtp_host' => $host, 'error' => $error]);
```

## Error Handling

Graceful error handling with user-friendly messages:
- Service-level error handling
- User-friendly error messages
- Detailed logging for debugging
- Fallback mechanisms

## Security

- Input validation at service level
- Authorization checks in commands
- Rate limiting through plan service
- Secure configuration access

## Extensibility

### Adding New Services

1. Create service class implementing `ServiceInterface`
2. Add to `TelegramBotCore` service initialization
3. Inject dependencies as needed

### Adding New Commands

1. Extend `BaseCommand`
2. Implement required methods
3. Register in `CommandManager`

### Adding New Features

- Create new services for complex functionality
- Use existing services for common operations
- Maintain clean separation of concerns

## Performance

- Lazy loading of services
- Efficient dependency injection
- Minimal memory footprint
- Optimized database/file operations

## Conclusion

This refactored architecture provides:
- **Maintainability**: Clean, organized code structure
- **Extensibility**: Easy to add new features
- **Testability**: Services can be tested independently
- **Reliability**: Better error handling and logging
- **Compatibility**: Works with existing configuration and data
- **Performance**: Optimized service architecture

The refactoring maintains full backward compatibility while providing a modern foundation for future development.
