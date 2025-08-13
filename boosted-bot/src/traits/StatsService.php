<?php

namespace App\Traits;

trait StatsService {
    /**
     * Compute average sending speed in emails per minute.
     * Expects a sending_state-like array with start_time, sent_count, error_count.
     */
    private function getAverageSpeed(array $state): int {
        $start = isset($state['start_time']) ? (int)$state['start_time'] : 0;
        if ($start <= 0) { return 0; }
        $elapsed = max(1, time() - $start); // seconds
        $processed = (int)($state['sent_count'] ?? 0) + (int)($state['error_count'] ?? 0);
        // per-minute
        return (int)floor(($processed / $elapsed) * 60);
    }

    /**
     * Format a duration in seconds into a human-friendly string.
     */
    private function formatTimeRemaining(int $seconds): string {
        if ($seconds < 0) { $seconds = 0; }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        $parts = [];
        if ($h > 0) { $parts[] = $h . 'h'; }
        if ($m > 0 || $h > 0) { $parts[] = $m . 'm'; }
        $parts[] = $s . 's';
        return implode(' ', $parts);
    }
}
