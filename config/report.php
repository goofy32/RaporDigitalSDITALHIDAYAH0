<?php

return [
    'pdf_auto_prepare' => [
        'enabled' => (bool) env('REPORT_PDF_AUTO_PREPARE_ENABLED', false),
        'delay_seconds' => (int) env('REPORT_PDF_AUTO_PREPARE_DELAY_SECONDS', 60),
        'queue' => env('REPORT_PDF_AUTO_PREPARE_QUEUE', 'pdf-warm'),
    ],

    'pdf_dashboard_warmup' => [
        'enabled' => (bool) env('REPORT_PDF_DASHBOARD_WARMUP_ENABLED', false),
        'cooldown_seconds' => (int) env('REPORT_PDF_DASHBOARD_WARMUP_COOLDOWN_SECONDS', 900),
    ],
];
