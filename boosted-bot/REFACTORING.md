# ChetoBot Refactored

This is a refactored version of the original ChetoBot Telegram email sending bot. The codebase has been completely restructured to follow modern PHP development practices and design patterns.

## Key Improvements

1. **Modular Architecture**: The code has been refactored from a monolithic class into a service-oriented architecture with clear separation of concerns.

2. **Command Pattern**: All bot commands are now handled via dedicated command handler classes, making it easy to add new commands.

3. **Service Layer**: Core functionality has been moved into service classes (SMTP management, user management, etc.)

4. **Enhanced Error Handling**: Comprehensive error handling and logging throughout the codebase.

5. **Code Maintainability**: Better organization, documentation, and adherence to coding standards.

## Directory Structure

```
boosted-bot/
├── config/               # Configuration files
│   ├── config.php        # Main configuration
│   ├── smtp_endpoints.json
│   ├── smtps.txt         # SMTP configuration
│   └── users.json        # User data
├── data/                 # Application data storage
│   ├── internal_tasks.json
│   ├── scheduled_tasks.json
│   └── ...
├── logs/                 # Log files
│   ├── bot.log           # Bot activity log
│   └── error.log         # PHP errors log
├── scripts/              # Utility scripts
├── src/                  # Source code
│   ├── Core/             # Core framework classes
│   │   ├── CommandRegistry.php
│   │   └── TelegramBotCore.php
│   ├── Handlers/         # Command handlers
│   │   ├── AdminCommandHandler.php
│   │   ├── CampaignCommandHandler.php
│   │   ├── CommandHandler.php
│   │   ├── HelpCommandHandler.php
│   │   ├── StartCommandHandler.php
│   │   ├── StatsCommandHandler.php
│   │   └── StatusCommandHandler.php
│   ├── Services/         # Service classes
│   │   ├── ConfigManager.php
│   │   ├── Logger.php
│   │   ├── SmtpManager.php
│   │   ├── TelegramAPI.php
│   │   └── UserService.php
│   ├── EmailSender.php   # Legacy code (to be refactored)
│   └── TelegramBot.php   # Legacy code (to be refactored)
├── uploads/              # User uploads (templates, lists)
├── vendor/               # Dependencies
├── composer.json         # Composer configuration
├── composer.lock         # Composer lock file
├── new_bot.php           # New bot entry point (refactored)
└── telegram_bot.php      # Legacy entry point
```

## How to Run

1. **Install Dependencies**:
   ```
   composer install
   ```

2. **Configure Bot**:
   Edit `config/config.php` to set your Telegram bot token and admin IDs.

3. **Run the Bot**:
   ```
   php new_bot.php
   ```

## Migration Plan

1. **Test the Refactored Bot**: Run the refactored bot alongside the original bot for testing.

2. **Gradually Migrate Users**: Migrate users to the new bot implementation.

3. **Complete Legacy Refactoring**: Refactor any remaining legacy functionality.

## Developer Notes

- The `CommandRegistry` handles registration and routing of bot commands
- Add new commands by creating classes that extend `CommandHandler`
- Service classes provide reusable functionality for different parts of the application
- The core architecture follows a service-oriented design pattern
