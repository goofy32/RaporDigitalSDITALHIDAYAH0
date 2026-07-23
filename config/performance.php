<?php

return [
    'slow_requests' => [
        'enabled' => (bool) env('PERFORMANCE_MONITOR_ENABLED', false),
        'log_channel' => env('PERFORMANCE_MONITOR_LOG_CHANNEL', 'performance'),
        'thresholds' => [
            'duration_ms' => (float) env('PERFORMANCE_SLOW_REQUEST_MS', 700),
            'query_count' => (int) env('PERFORMANCE_QUERY_COUNT_THRESHOLD', 75),
            'database_ms' => (float) env('PERFORMANCE_DATABASE_MS_THRESHOLD', 250),
            'max_query_ms' => (float) env('PERFORMANCE_MAX_QUERY_MS_THRESHOLD', 150),
        ],
    ],
];
