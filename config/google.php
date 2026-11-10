<?php
declare(strict_types=1);

return [
    'client_id' => env('GOOGLE_CLIENT_ID', ''),
    'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI', ''),
    'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN', ''),
    'api_version' => env('GOOGLE_ADS_API_VERSION', 'v22'),
    'writes_enabled' => filter_var(
        env('GOOGLE_ADS_WRITES_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN
    ),
    'scope' => 'https://www.googleapis.com/auth/adwords',
];