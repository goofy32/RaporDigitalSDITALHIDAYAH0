<?php

$token = env('INITIAL_ADMIN_SETUP_TOKEN');

return [
    'token_hash' => is_string($token) && trim($token) !== ''
        ? hash('sha256', $token)
        : null,
];
