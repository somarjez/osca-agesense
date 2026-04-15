<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'mailgun'  => [
        'domain'   => env('MAILGUN_DOMAIN'),
        'secret'   => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme'   => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OSCA Python ML Services
    |--------------------------------------------------------------------------
    */

    'python' => [
        'base_url'          => env('PYTHON_SERVICE_URL', 'http://127.0.0.1'),
        'preprocess_port'   => env('PYTHON_PREPROCESS_PORT', 5001),
        'inference_port'    => env('PYTHON_INFERENCE_PORT', 5002),
        'timeout'           => env('PYTHON_TIMEOUT', 120),
        'cold_start_timeout'=> env('PYTHON_COLD_START_TIMEOUT', 120),
    ],

];
