<?php
/**
 * Email Sending Script
 * 
 * Standalone script for sending emails using the configured SMTPs
 */

// Error reporting and timezone settings
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('UTC');

// Required files
require_once __DIR__ . '/src/EmailSender.php';
require_once __DIR__ . '/config/config.php';

// Constants
const SMTP_FILE = __DIR__ . '/config/smtps.txt';
const LOG_FILE = __DIR__ . '/logs/send.log';
const UPLOADS_DIR = __DIR__ . '/uploads';

// Create required directories if they don't exist
@mkdir(dirname(LOG_FILE), 0755, true);
@mkdir(UPLOADS_DIR, 0755, true);

/**
 * Log a message
 * 
 * @param string $message Message to log
 */
function log_message($message) {
    $logMessage = date('[Y-m-d H:i:s] ') . $message . "\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
    echo $logMessage;
}

/**
 * Parse SMTP string into structured array
 * 
 * @param string $smtpString SMTP configuration string
 * @return array Structured SMTP configuration
 */
function parse_smtp_string($smtpString) {
    $parts = explode(',', $smtpString);
    if (count($parts) < 3) {
        throw new Exception("Invalid SMTP format");
    }
    
    $hostPort = explode(':', $parts[0]);
    if (count($hostPort) != 2 || !is_numeric($hostPort[1])) {
        throw new Exception("Invalid host:port format");
    }
    
    return [
        'host' => $hostPort[0],
        'port' => (int)$hostPort[1],
        'username' => $parts[1],
        'password' => $parts[2],
        'from_email' => isset($parts[3]) ? $parts[3] : $parts[1],
        'from_name' => isset($parts[4]) ? $parts[4] : 'Notification',
        'encryption' => isset($parts[5]) ? $parts[5] : 'tls',
        'daily_limit' => isset($parts[6]) && is_numeric($parts[6]) ? (int)$parts[6] : 500,
        'hourly_limit' => isset($parts[7]) && is_numeric($parts[7]) ? (int)$parts[7] : 50
    ];
}

/**
 * Load SMTP configurations
 * 
 * @return array Array of SMTP configurations
 */
function load_smtps() {
    $smtps = [];
    $smtpsFile = SMTP_FILE;
    
    if (file_exists($smtpsFile)) {
        $lines = file($smtpsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            try {
                $smtps[] = parse_smtp_string($line);
            } catch (Exception $e) {
                log_message("Invalid SMTP format: " . $line . " - " . $e->getMessage());
            }
        }
    } else {
        log_message("SMTP file not found: " . $smtpsFile);
    }
    
    return $smtps;
}

/**
 * Main function
 */
function main() {
    // Check command line arguments
    if ($argc < 4) {
        echo "Usage: php send.php <recipient_email> <subject> <body_file> [smtp_index]\n";
        echo "  recipient_email: Email address to send to\n";
        echo "  subject: Email subject\n";
        echo "  body_file: Path to HTML body file\n";
        echo "  smtp_index: (Optional) Index of SMTP to use (0-based)\n";
        exit(1);
    }
    
    $recipient = $argv[1];
    $subject = $argv[2];
    $bodyFile = $argv[3];
    $smtpIndex = isset($argv[4]) ? (int)$argv[4] : 0;
    
    // Validate recipient email
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        log_message("Invalid recipient email address: " . $recipient);
        exit(1);
    }
    
    // Load body file
    if (!file_exists($bodyFile)) {
        log_message("Body file not found: " . $bodyFile);
        exit(1);
    }
    $body = file_get_contents($bodyFile);
    
    // Load SMTPs
    $smtps = load_smtps();
    if (empty($smtps)) {
        log_message("No SMTPs available");
        exit(1);
    }
    
    // Check SMTP index
    if ($smtpIndex < 0 || $smtpIndex >= count($smtps)) {
        log_message("Invalid SMTP index: " . $smtpIndex . ". Must be between 0 and " . (count($smtps) - 1));
        exit(1);
    }
    
    // Get SMTP
    $smtp = $smtps[$smtpIndex];
    log_message("Using SMTP: " . $smtp['username']);
    
    // Create email sender
    $emailSender = new \App\EmailSender($smtp);
    
    // Send email
    log_message("Sending email to: " . $recipient);
    $result = $emailSender->send($recipient, $subject, $body);
    
    if ($result) {
        log_message("Email sent successfully");
    } else {
        log_message("Failed to send email: " . $emailSender->getLastError());
        exit(1);
    }
}

// Run main function
main();
