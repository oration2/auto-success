<?php
// Bot Configuration
define('BOT_TOKEN', '7247356713:AAHa0opyNN9PURtFg0UhK_jw-Jip7wDKVbE'); // Replace with your actual bot token from BotFather
define('ADMIN_CHAT_IDS', ['7729743415']); // Add admin chat IDs here

// File paths
define('LOG_FILE', __DIR__ . '/bot.log');
define('USER_DATA_FILE', __DIR__ . '/users.json');
define('UPLOADS_DIR', __DIR__ . '/../uploads/');

// User limits
define('MAX_EMAILS_PER_DAY', 10);
define('MAX_EMAILS_PER_MONTH', 100);
define('DEFAULT_TRIAL_SUBJECT', 'Test from @{Cheto_inboxing_bot}');

// Plan configurations
define('PLANS', [
    'trial' => [
        'name' => 'Demo Trial',
        'duration' => 3,
        'price' => 0,
    'emails_per_hour' => 30,
    'emails_per_day' => 100,
    'description' => "🆓 Demo plan with reduced limits\n• Duration: 3 days\n• Emails/hour: 30\n• Emails/day: 100\nNote: Sending is blocked after trial expiry"
    ],
    'rotation_30' => [
        'name' => 'Rotation Plan 30 Days',
        'duration' => 30,
        'price' => 1300,
        'emails_per_hour' => 3000,
        'emails_per_day' => 15000,
        'description' => "📅 30 days: $1300\nEmails per hour: 3,000\nEmails per day: 15,000"
    ],
    'rotation_3' => [
        'name' => 'Rotation Plan 3 Days',
        'duration' => 3,
        'price' => 130,
        'emails_per_hour' => 1000,
        'emails_per_day' => 7000,
        'description' => "📅 3 days: $130\nEmails per hour: 1,000\nEmails per day: 7,000"
    ],
    'rotation_7' => [
        'name' => 'Rotation Plan 7 Days',
        'duration' => 7,
        'price' => 300,
        'emails_per_hour' => 1500,
        'emails_per_day' => 8000,
        'description' => "📅 7 days: $300\nEmails per hour: 1,500\nEmails per day: 8,000"
    ],
    'rotation_14' => [
        'name' => 'Rotation Plan 14 Days',
        'duration' => 14,
        'price' => 650,
        'emails_per_hour' => 2000,
        'emails_per_day' => 10000,
        'description' => "📅 14 days: $650\nEmails per hour: 2,000\nEmails per day: 10,000"
    ],
    'custom_smtp' => [
        'name' => 'Sniper Custom SMTP Plan',
        'duration' => 30,
        'price' => 650,
        'emails_per_hour' => -1, // Unlimited
        'emails_per_day' => -1,  // Unlimited
        'description' => "📅 1 month: $650\nUnlimited emails per hour and per day."
    ]
]);

// Additional Services
define('ADDITIONAL_SERVICES', [
    'inbox_letter' => [
        'name' => 'Buy Inbox Letter',
        'description' => '✉️ Designed Letter of Your Choice\nPrice: $50',
        'price' => 50,
        'contact' => '@Ninja111'
    ],
    'autograb_inbox' => [
        'name' => 'Open Redirect Autograb Inbox',
        'description' => '🔄 Service: Autograb inbox for one month\nPrice: $70',
        'price' => 70,
        'duration' => 30, // d
        'contact' => '@Ninja111'
    ]
]);

// Marketing Settings
define('MARKETING_SETTINGS', [
    'broadcast_intervals' => [
        '10:00', '15:00', '19:00', '22:00' // Daily broadcast times
    ],
    'offers_rotation' => true,
    'admin_contact' => '@Ninja111'
]);

// SMTP validation settings
define('SMTP_TIMEOUT', 10);
define('SMTP_ROTATION_THRESHOLD', 3); // Number of errors before rotating SMTP
define('SMTP_ERROR_COOLDOWN', 3600); // Cooldown period for SMTP errors in seconds

// Error messages
define('ERR_NOT_AUTHORIZED', 'You are not authorized to use this command.');
define('ERR_INVALID_SMTP', 'Invalid SMTP settings. Please use format: smtp.example.com:587,username,password');
define('ERR_DAILY_LIMIT', 'You have reached your daily email limit.');
define('ERR_MONTHLY_LIMIT', 'You have reached your monthly email limit.');
define('ERR_MISSING_FILES', 'Please upload both list.txt and letter.html files before sending emails.');
define('ERR_MISSING_SMTP', 'Please set your SMTP details before sending emails.');
