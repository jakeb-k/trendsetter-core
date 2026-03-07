<?php

return [
    'grace_hours' => (int) env('PARTNER_ALERT_GRACE_HOURS', 6),
    'rate_limit_hours' => (int) env('PARTNER_ALERT_RATE_LIMIT_HOURS', 24),
    'consecutive_misses_threshold' => (int) env('PARTNER_ALERT_CONSECUTIVE_MISSES', 1),
    'inactivity_days' => (int) env('PARTNER_ALERT_INACTIVITY_DAYS', 3),
    'behind_pace_points' => (float) env('PARTNER_ALERT_BEHIND_PACE_POINTS', 2),
    'min_expected_points' => (float) env('PARTNER_ALERT_MIN_EXPECTED_POINTS', 4),
    'scan_chunk_size' => (int) env('PARTNER_ALERT_SCAN_CHUNK_SIZE', 200),
    'suppressed_retention_days' => (int) env('PARTNER_ALERT_SUPPRESSED_RETENTION_DAYS', 7),
    'read_notification_retention_days' => (int) env('PARTNER_NOTIFICATION_READ_RETENTION_DAYS', 7),
    'encouragement_presets' => [
        'youve_got_this' => "You've got this.",
        'small_steps_count' => 'Small steps count.',
        'reset_and_continue' => 'Reset and continue.',
        'one_check_in_today' => 'One check-in today is enough.',
    ],
];
