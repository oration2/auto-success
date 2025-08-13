<?php

namespace App\Handlers;

use App\Core\TelegramBotCore;

/**
 * Stats Command Handler
 * 
 * Handles the /stats command
 */
class StatsCommandHandler extends CommandHandler
{
    /**
     * @inheritdoc
     */
    protected $commands = ['stats'];
    
    /**
     * @inheritdoc
     */
    protected $description = 'View campaign statistics';
    
    /**
     * @inheritdoc
     */
    protected $usage = '/stats [period]';
    
    /**
     * @inheritdoc
     */
    public function handle($chatId, $command, $args = [], $meta = [])
    {
        // Check for period parameter
        $period = isset($args[0]) ? strtolower($args[0]) : 'summary';
        
        switch ($period) {
            case 'today':
                return $this->showTodayStats($chatId);
                
            case 'week':
                return $this->showWeekStats($chatId);
                
            case 'month':
                return $this->showMonthStats($chatId);
                
            case 'all':
                return $this->showAllTimeStats($chatId);
                
            default:
                return $this->showStatsSummary($chatId);
        }
    }
    
    /**
     * Show statistics summary
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    private function showStatsSummary($chatId)
    {
        // Get user data
        $userData = $this->bot->getUserData($chatId);
        
        // Get email stats
        $stats = $userData['email_stats'] ?? [
            'total_sent' => 0,
            'total_errors' => 0,
            'campaigns' => 0
        ];
        
        // Calculate success rate
        $totalAttempted = $stats['total_sent'] + ($stats['total_errors'] ?? 0);
        $successRate = ($totalAttempted > 0) ? ($stats['total_sent'] / $totalAttempted) * 100 : 0;
        
        // Get period data
        $today = date('Y-m-d');
        $month = date('Y-m');
        $todayStats = $userData['daily_stats'][$today] ?? ['sent' => 0, 'errors' => 0];
        $monthStats = $userData['monthly_stats'][$month] ?? ['sent' => 0, 'errors' => 0];
        
        // Build message
        $message = "📊 *Email Campaign Statistics*\n\n";
        
        // Overall stats
        $message .= "*All Time:*\n";
        $message .= "• Total Sent: " . number_format($stats['total_sent']) . "\n";
        $message .= "• Success Rate: " . $this->formatPercent($successRate) . "\n";
        $message .= "• Campaigns: " . number_format($stats['campaigns']) . "\n\n";
        
        // Today stats
        $message .= "*Today:*\n";
        $message .= "• Sent: " . number_format($todayStats['sent']) . "\n";
        $message .= "• Errors: " . number_format($todayStats['errors'] ?? 0) . "\n";
        
        // Today success rate
        $todayAttempted = $todayStats['sent'] + ($todayStats['errors'] ?? 0);
        $todayRate = ($todayAttempted > 0) ? ($todayStats['sent'] / $todayAttempted) * 100 : 0;
        $message .= "• Success Rate: " . $this->formatPercent($todayRate) . "\n\n";
        
        // Month stats
        $message .= "*This Month:*\n";
        $message .= "• Sent: " . number_format($monthStats['sent']) . "\n";
        $message .= "• Errors: " . number_format($monthStats['errors'] ?? 0) . "\n";
        
        // Month success rate
        $monthAttempted = $monthStats['sent'] + ($monthStats['errors'] ?? 0);
        $monthRate = ($monthAttempted > 0) ? ($monthStats['sent'] / $monthAttempted) * 100 : 0;
        $message .= "• Success Rate: " . $this->formatPercent($monthRate) . "\n";
        
        // Add action buttons
        $keyboard = [
            [
                ['text' => '📅 Today', 'callback_data' => 'stats_today'],
                ['text' => '📆 Week', 'callback_data' => 'stats_week']
            ],
            [
                ['text' => '📊 Month', 'callback_data' => 'stats_month'],
                ['text' => '📈 All Time', 'callback_data' => 'stats_all']
            ],
            [
                ['text' => '📋 Campaign List', 'callback_data' => 'campaign_list']
            ],
            [
                ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Show today's statistics
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    private function showTodayStats($chatId)
    {
        // Get user data
        $userData = $this->bot->getUserData($chatId);
        
        // Get today's date
        $today = date('Y-m-d');
        $todayStats = $userData['daily_stats'][$today] ?? ['sent' => 0, 'errors' => 0];
        
        // Get hourly breakdown
        $hourlyData = $userData['hourly_stats'][$today] ?? [];
        
        // Build message
        $message = "📅 *Today's Statistics*\n\n";
        
        // Today overview
        $message .= "*Overview:*\n";
        $message .= "• Sent: " . number_format($todayStats['sent']) . "\n";
        $message .= "• Errors: " . number_format($todayStats['errors'] ?? 0) . "\n";
        
        // Calculate success rate
        $totalAttempted = $todayStats['sent'] + ($todayStats['errors'] ?? 0);
        $successRate = ($totalAttempted > 0) ? ($todayStats['sent'] / $totalAttempted) * 100 : 0;
        $message .= "• Success Rate: " . $this->formatPercent($successRate) . "\n\n";
        
        // Hourly breakdown (if available)
        if (!empty($hourlyData)) {
            $message .= "*Hourly Breakdown:*\n";
            
            // Get current hour
            $currentHour = (int)date('H');
            
            // Show last 6 hours or less if not enough data
            $hoursToShow = min(6, $currentHour + 1);
            $startHour = max(0, $currentHour - $hoursToShow + 1);
            
            for ($hour = $startHour; $hour <= $currentHour; $hour++) {
                $hourKey = sprintf('%02d', $hour);
                $hourData = $hourlyData[$hourKey] ?? ['sent' => 0, 'errors' => 0];
                
                $hourSent = $hourData['sent'] ?? 0;
                $hourTime = sprintf('%02d:00-%02d:00', $hour, $hour + 1);
                
                $message .= "• {$hourTime}: " . number_format($hourSent) . "\n";
            }
        }
        
        // Add action buttons
        $keyboard = [
            [
                ['text' => '📊 Summary', 'callback_data' => 'stats_summary']
            ],
            [
                ['text' => '📆 Week', 'callback_data' => 'stats_week'],
                ['text' => '📈 Month', 'callback_data' => 'stats_month']
            ],
            [
                ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Show weekly statistics
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    private function showWeekStats($chatId)
    {
        // Get user data
        $userData = $this->bot->getUserData($chatId);
        
        // Get daily stats
        $dailyStats = $userData['daily_stats'] ?? [];
        
        // Get dates for the past week
        $dates = [];
        $totalSent = 0;
        $totalErrors = 0;
        
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $dates[] = $date;
            
            $dayStats = $dailyStats[$date] ?? ['sent' => 0, 'errors' => 0];
            $totalSent += $dayStats['sent'];
            $totalErrors += $dayStats['errors'] ?? 0;
        }
        
        // Calculate success rate
        $totalAttempted = $totalSent + $totalErrors;
        $successRate = ($totalAttempted > 0) ? ($totalSent / $totalAttempted) * 100 : 0;
        
        // Build message
        $message = "📆 *Weekly Statistics*\n\n";
        
        // Week overview
        $message .= "*Overview (Last 7 Days):*\n";
        $message .= "• Total Sent: " . number_format($totalSent) . "\n";
        $message .= "• Errors: " . number_format($totalErrors) . "\n";
        $message .= "• Success Rate: " . $this->formatPercent($successRate) . "\n\n";
        
        // Daily breakdown
        $message .= "*Daily Breakdown:*\n";
        
        foreach ($dates as $date) {
            $dayStats = $dailyStats[$date] ?? ['sent' => 0, 'errors' => 0];
            $daySent = $dayStats['sent'];
            
            // Format date as "Mon 01" etc.
            $dayName = date('D', strtotime($date));
            $dayNum = date('d', strtotime($date));
            $displayDate = "{$dayName} {$dayNum}";
            
            // Highlight today
            if ($date === date('Y-m-d')) {
                $displayDate = "📍 {$displayDate}";
            }
            
            $message .= "• {$displayDate}: " . number_format($daySent) . "\n";
        }
        
        // Add action buttons
        $keyboard = [
            [
                ['text' => '📊 Summary', 'callback_data' => 'stats_summary']
            ],
            [
                ['text' => '📅 Today', 'callback_data' => 'stats_today'],
                ['text' => '📈 Month', 'callback_data' => 'stats_month']
            ],
            [
                ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Show monthly statistics
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    private function showMonthStats($chatId)
    {
        // Get user data
        $userData = $this->bot->getUserData($chatId);
        
        // Get current month
        $currentMonth = date('Y-m');
        $monthStats = $userData['monthly_stats'][$currentMonth] ?? ['sent' => 0, 'errors' => 0];
        
        // Get daily stats for current month
        $dailyStats = $userData['daily_stats'] ?? [];
        $monthDays = [];
        
        // Collect days in current month
        $daysInMonth = date('t');
        for ($day = 1; $day <= min($daysInMonth, (int)date('d')); $day++) {
            $date = date('Y-m-' . sprintf('%02d', $day));
            $dayStats = $dailyStats[$date] ?? ['sent' => 0, 'errors' => 0];
            
            $monthDays[$date] = $dayStats;
        }
        
        // Calculate success rate
        $totalSent = $monthStats['sent'];
        $totalErrors = $monthStats['errors'] ?? 0;
        $totalAttempted = $totalSent + $totalErrors;
        $successRate = ($totalAttempted > 0) ? ($totalSent / $totalAttempted) * 100 : 0;
        
        // Build message
        $message = "📈 *Monthly Statistics*\n\n";
        
        // Month overview
        $message .= "*Overview (" . date('F Y') . "):*\n";
        $message .= "• Total Sent: " . number_format($totalSent) . "\n";
        $message .= "• Errors: " . number_format($totalErrors) . "\n";
        $message .= "• Success Rate: " . $this->formatPercent($successRate) . "\n\n";
        
        // Show weekly totals
        $message .= "*Weekly Totals:*\n";
        
        // Group by week
        $weeklyTotals = [];
        foreach ($monthDays as $date => $stats) {
            $weekNumber = (int)date('W', strtotime($date));
            if (!isset($weeklyTotals[$weekNumber])) {
                $weeklyTotals[$weekNumber] = 0;
            }
            $weeklyTotals[$weekNumber] += $stats['sent'];
        }
        
        // Display weekly totals
        foreach ($weeklyTotals as $weekNumber => $sent) {
            $weekText = "Week {$weekNumber}";
            $message .= "• {$weekText}: " . number_format($sent) . "\n";
        }
        
        // Add action buttons
        $keyboard = [
            [
                ['text' => '📊 Summary', 'callback_data' => 'stats_summary']
            ],
            [
                ['text' => '📅 Today', 'callback_data' => 'stats_today'],
                ['text' => '📆 Week', 'callback_data' => 'stats_week']
            ],
            [
                ['text' => '📈 All Time', 'callback_data' => 'stats_all']
            ],
            [
                ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Show all-time statistics
     * 
     * @param string $chatId Chat ID
     * @return bool Success status
     */
    private function showAllTimeStats($chatId)
    {
        // Get user data
        $userData = $this->bot->getUserData($chatId);
        
        // Get email stats
        $stats = $userData['email_stats'] ?? [
            'total_sent' => 0,
            'total_errors' => 0,
            'campaigns' => 0,
            'first_email_date' => time()
        ];
        
        // Calculate success rate
        $totalSent = $stats['total_sent'];
        $totalErrors = $stats['total_errors'] ?? 0;
        $totalAttempted = $totalSent + $totalErrors;
        $successRate = ($totalAttempted > 0) ? ($totalSent / $totalAttempted) * 100 : 0;
        
        // Get monthly stats for chart
        $monthlyStats = $userData['monthly_stats'] ?? [];
        
        // Sort by month
        ksort($monthlyStats);
        
        // Keep only the last 6 months
        $recentMonths = array_slice($monthlyStats, -6, 6, true);
        
        // Calculate account age
        $firstEmailDate = $stats['first_email_date'] ?? time();
        $accountAgeInDays = max(1, ceil((time() - $firstEmailDate) / 86400));
        
        // Calculate averages
        $dailyAverage = round($totalSent / $accountAgeInDays);
        $monthlyAverage = round($dailyAverage * 30);
        
        // Build message
        $message = "📈 *All-Time Statistics*\n\n";
        
        // Overall stats
        $message .= "*Account Overview:*\n";
        $message .= "• Account Age: " . $this->formatAccountAge($firstEmailDate) . "\n";
        $message .= "• Total Sent: " . number_format($totalSent) . "\n";
        $message .= "• Total Campaigns: " . number_format($stats['campaigns']) . "\n";
        $message .= "• Success Rate: " . $this->formatPercent($successRate) . "\n";
        $message .= "• Daily Average: " . number_format($dailyAverage) . "\n";
        $message .= "• Monthly Average: " . number_format($monthlyAverage) . "\n\n";
        
        // Monthly breakdown (if available)
        if (!empty($recentMonths)) {
            $message .= "*Monthly Breakdown:*\n";
            
            foreach ($recentMonths as $month => $monthData) {
                // Format as "Jan 2023" etc.
                $displayMonth = date('M Y', strtotime($month . '-01'));
                $monthlySent = $monthData['sent'] ?? 0;
                
                $message .= "• {$displayMonth}: " . number_format($monthlySent) . "\n";
            }
        }
        
        // Add action buttons
        $keyboard = [
            [
                ['text' => '📊 Summary', 'callback_data' => 'stats_summary']
            ],
            [
                ['text' => '📅 Today', 'callback_data' => 'stats_today'],
                ['text' => '📆 Week', 'callback_data' => 'stats_week']
            ],
            [
                ['text' => '📈 Month', 'callback_data' => 'stats_month']
            ],
            [
                ['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']
            ]
        ];
        
        $this->bot->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        return true;
    }
    
    /**
     * Format account age
     * 
     * @param int $timestamp First email timestamp
     * @return string Formatted account age
     */
    private function formatAccountAge($timestamp)
    {
        $diff = time() - $timestamp;
        
        $days = floor($diff / 86400);
        
        if ($days < 30) {
            return "{$days} days";
        }
        
        $months = floor($days / 30);
        $remainingDays = $days % 30;
        
        if ($months < 12) {
            return "{$months} months, {$remainingDays} days";
        }
        
        $years = floor($months / 12);
        $remainingMonths = $months % 12;
        
        return "{$years} years, {$remainingMonths} months";
    }
    
    /**
     * Format percentage
     * 
     * @param float $value Percentage value
     * @param int $precision Decimal precision
     * @return string Formatted percentage
     */
    private function formatPercent($value, $precision = 1)
    {
        return round($value, $precision) . '%';
    }
}
