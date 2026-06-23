<?php

$explicitEnabled = env('STAGING_TEST_TOOLS_ENABLED');

return [
    /*
    |--------------------------------------------------------------------------
    | Staging Test Tools
    |--------------------------------------------------------------------------
    |
    | These tools are meant for controlled staging/testing sessions only.
    | They are enabled automatically outside production, and can be enabled
    | explicitly in production-like staging with STAGING_TEST_TOOLS_ENABLED.
    |
    */

    'enabled' => $explicitEnabled === null
        ? env('APP_ENV', 'production') !== 'production'
        : (bool) $explicitEnabled,

    'max_requests' => 20,

    'score_confirmation' => 'SAYA PAHAM INI DATA DUMMY',

    'dummy_markers' => [
        'dummy',
        'test',
        'testing',
        'simulasi',
        'contoh',
    ],
];
