<?php

return [
    'pdf_auto_prepare' => [
        'enabled' => (bool) env('REPORT_PDF_AUTO_PREPARE_ENABLED', false),
        'delay_seconds' => (int) env('REPORT_PDF_AUTO_PREPARE_DELAY_SECONDS', 60),
        'late_stage_delay_seconds' => (int) env('REPORT_PDF_LATE_STAGE_WARMUP_DELAY_SECONDS', 10),
        'queue' => env('REPORT_PDF_AUTO_PREPARE_QUEUE', 'pdf-warm'),
    ],

    'pdf_dashboard_warmup' => [
        'enabled' => (bool) env('REPORT_PDF_DASHBOARD_WARMUP_ENABLED', false),
        'cooldown_seconds' => (int) env('REPORT_PDF_DASHBOARD_WARMUP_COOLDOWN_SECONDS', 900),
    ],

    'pdf_libreoffice' => [
        'max_concurrent' => (int) env('REPORT_PDF_LIBREOFFICE_MAX_CONCURRENT', 0),
        'lock_seconds' => (int) env('REPORT_PDF_LIBREOFFICE_LOCK_SECONDS', 180),
        'lock_wait_seconds' => (int) env('REPORT_PDF_LIBREOFFICE_LOCK_WAIT_SECONDS', 120),
    ],

    'score_save_profiling' => [
        'enabled' => (bool) env('REPORT_SCORE_SAVE_PROFILING_ENABLED', false),
    ],
];
