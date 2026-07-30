<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OSCA Python ML Services
    |--------------------------------------------------------------------------
    */

    'python' => [
        'base_url' => env('PYTHON_SERVICE_URL', 'http://127.0.0.1'),
        'preprocess_port' => env('PYTHON_PREPROCESS_PORT', 5001),
        'inference_port' => env('PYTHON_INFERENCE_PORT', 5002),
        // For platforms that give each service its own hostname instead of
        // one host with multiple ports (e.g. Render: no way to "append a
        // port" to reach a second service). When set, these take priority
        // over base_url+port entirely. Leave unset for local dev, where
        // base_url+port (http://127.0.0.1:5001 / :5002) still applies.
        'preprocess_url' => env('PYTHON_PREPROCESS_URL'),
        'inference_url' => env('PYTHON_INFERENCE_URL'),
        'timeout' => env('PYTHON_TIMEOUT', 120),
        'cold_start_timeout' => env('PYTHON_COLD_START_TIMEOUT', 120),
        // /health does no model loading, so a live service answers in
        // milliseconds — this budget only matters for the down/slow case, and
        // a short one lets the ≤2s health-check / ≤10s fallback SLAs pass.
        'health_timeout' => env('PYTHON_HEALTH_TIMEOUT', 2),
        'health_connect_timeout' => env('PYTHON_HEALTH_CONNECT_TIMEOUT', 1),
        'token' => env('ML_SERVICE_TOKEN'),
    ],

    'overpass' => [
        'url' => env('OVERPASS_API_URL', 'https://overpass-api.de/api/interpreter'),
        'ca_bundle' => env('OVERPASS_CA_BUNDLE', ''),
        'verify_ssl' => env('OVERPASS_VERIFY_SSL', true),
    ],

    'openrouteservice' => [
        'api_key' => env('OPENROUTESERVICE_API_KEY'),
        'base_url' => env('OPENROUTESERVICE_BASE_URL', 'https://api.heigit.org/openrouteservice'),
        'ca_bundle' => env('OPENROUTESERVICE_CA_BUNDLE', ''),
        'verify_ssl' => env('OPENROUTESERVICE_VERIFY_SSL', true),
        'snap_radius_meters' => env('OPENROUTESERVICE_SNAP_RADIUS_METERS', -1),
        // Live popup routing: kept short so the UI never hangs on a cache miss.
        'connect_timeout' => env('OPENROUTESERVICE_CONNECT_TIMEOUT', 3),
        'timeout' => env('OPENROUTESERVICE_TIMEOUT', 5),
        'retry_times' => env('OPENROUTESERVICE_RETRY_TIMES', 0),
        'retry_sleep_ms' => env('OPENROUTESERVICE_RETRY_SLEEP_MS', 500),
        // Background precompute (gis:cache-route-distances): patient + retried so
        // the free tier's variable latency doesn't time out into the OSRM fallback.
        // The batch is throttled and offline, so longer waits are fine.
        'batch_connect_timeout' => env('OPENROUTESERVICE_BATCH_CONNECT_TIMEOUT', 15),
        'batch_timeout' => env('OPENROUTESERVICE_BATCH_TIMEOUT', 40),
        'batch_retry_times' => env('OPENROUTESERVICE_BATCH_RETRY_TIMES', 2),
        'batch_retry_sleep_ms' => env('OPENROUTESERVICE_BATCH_RETRY_SLEEP_MS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase E — internal cron trigger
    |--------------------------------------------------------------------------
    | Guards POST /api/internal/cron-tick (VerifyCronToken middleware). Hit
    | every 10 minutes during business hours by the scheduled GitHub Actions
    | workflow (.github/workflows/keep-alive.yml) — drains the ml/default
    | queues, runs due scheduled tasks, and keeps the Laravel service warm
    | as a side effect of the request itself. See docs/DEPLOYMENT.md.
    */
    'cron' => [
        'token' => env('CRON_TRIGGER_TOKEN'),
    ],

    'osrm' => [
        'base_url' => env('OSRM_BASE_URL', 'https://router.project-osrm.org'),
        'connect_timeout' => env('OSRM_CONNECT_TIMEOUT', 3),
        'timeout' => env('OSRM_TIMEOUT', 8),
        // Background backfill fallback (gis:cache-route-distances --osrm-on-quota):
        // the public OSRM demo is slow, so allow more time + a retry here.
        'batch_connect_timeout' => env('OSRM_BATCH_CONNECT_TIMEOUT', 15),
        'batch_timeout' => env('OSRM_BATCH_TIMEOUT', 45),
        'batch_retry_times' => env('OSRM_BATCH_RETRY_TIMES', 2),
        'batch_retry_sleep_ms' => env('OSRM_BATCH_RETRY_SLEEP_MS', 2000),
    ],

];
