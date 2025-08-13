# Boosted Telegram Bot

An enhanced version of the Telegram bot with improved structure, fixed issues, and better organization.

## Features

- **Improved Code Structure**: Organized code with proper separation of concerns
- **Fixed Redeclaration Issues**: No more PHP parse errors from duplicate function declarations
- **Enhanced Error Handling**: Better error handling and logging
- **SMTP Rotation**: Automatic SMTP rotation when errors occur
- **Premium Services**: Support for premium services with cyberpunk-styled UI
- **Cryptocurrency Payments**: Support for multiple cryptocurrencies including BTC, ETH, USDT (with network selection), LTC, and BCH

## Directory Structure

```
boosted-bot/
├── config/               # Configuration files
│   ├── config.php        # Main configuration
│   └── smtps.txt         # SMTP configurations
├── logs/                 # Log files
├── src/                  # Source code
│   ├── handlers/         # Command handlers
│   ├── utils/            # Utility classes
│   └── TelegramBot.php   # Main bot class
├── send.php              # Standalone email sending script
└── telegram_bot.php      # Main bot script
```

## Installation

1. Clone this repository
2. Install dependencies:
   ```
   composer require guzzlehttp/guzzle phpmailer/phpmailer
   ```
3. Configure the bot:
   - Edit `config/config.php` to set your bot token and admin IDs
   - Edit `config/smtps.txt` to add your SMTP configurations

## SMTP Configuration Format

The SMTP configuration file (`config/smtps.txt`) uses the following format:

```
host:port,username,password,from_email,from_name,encryption,daily_limit,hourly_limit
```

Example:
```
smtp.example.com:587,user@example.com,password,sender@example.com,Sender Name,tls,500,50
```

## Usage

### Starting the Bot

```
php telegram_bot.php
```

### Sending Emails Manually

```
php send.php recipient@example.com "Email Subject" path/to/body.html [smtp_index]
```

## Premium Services

The bot includes several premium services:

1. **Domain Scanner Pro**: Advanced domain scanning with SMTP credential extraction
2. **Email Validator Pro**: Advanced email validation service
3. **SMTP Config Generator**: Configuration tool for email platforms
4. **NeuroSpectra Advanced Threat Detection**: Advanced threat detection using neural networks
5. **NexusVerify Office365 Matrix**: Advanced Office365 verification and configuration
6. **NeuroEye Breach Intelligence**: Advanced breach detection and analysis

## Cryptocurrency Payment Support

The bot supports payments in multiple cryptocurrencies:

- Bitcoin (BTC)
- Ethereum (ETH)
- USDT with network selection:
  - TRC20 (TRON) - Recommended for low fees
  - ERC20 (Ethereum)
  - BEP20 (Binance Smart Chain)
- Litecoin (LTC)
- Bitcoin Cash (BCH)

## Admin Dashboard

The admin dashboard provides comprehensive visualization features:

- User growth chart
- Service usage chart
- Revenue chart
- Premium services chart
- Premium services trend chart
- Premium service usage heatmap

## License

This project is licensed under the MIT License - see the LICENSE file for details.
