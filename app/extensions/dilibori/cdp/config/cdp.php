<?php

declare(strict_types=1);

return [
    /*
    | When false, listeners and the JS snippet no-op.
    */
    'enabled' => filter_var(env('CDP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Backend event driver: rudderstack | null | structured-log
    | Future: segment | kafka | …
    */
    // Default to local Rudder so tenants send without waiting on env wiring.
    'driver' => env('EVENT_CLIENT_DRIVER', 'rudderstack'),

    /*
    | Cookie shared by JS + PHP so anonymous activity stitches across SDKs.
    */
    'anonymous_id_cookie' => env('CDP_ANONYMOUS_ID_COOKIE', 'dilibori_anonymous_id'),

    'drivers' => [
        'rudderstack' => [
            // Tenant containers are not on the CDP compose network; hit the host publish.
            'data_plane_url' => env('RUDDERSTACK_DATA_PLANE_URL', 'http://host.docker.internal:8080'),
            'js_data_plane_url' => env('RUDDERSTACK_JS_DATA_PLANE_URL', 'http://127.0.0.1:8080'),
            // File-based data planes do not serve /sourceConfig; local inspector does.
            'js_config_url' => env('RUDDERSTACK_JS_CONFIG_URL', 'http://127.0.0.1:8090'),
            'http_write_key' => env('RUDDERSTACK_HTTP_WRITE_KEY', 'dilibori_http_write_key_local'),
            'js_write_key' => env('RUDDERSTACK_JS_WRITE_KEY', 'dilibori_js_write_key_local'),
            'timeout' => (float) env('RUDDERSTACK_TIMEOUT', 2.0),
            'ssl' => filter_var(env('RUDDERSTACK_SSL', false), FILTER_VALIDATE_BOOLEAN),
        ],

        // Placeholders for future drivers — configure when implemented.
        'segment' => [
            'write_key' => env('SEGMENT_WRITE_KEY', ''),
            'host' => env('SEGMENT_HOST', 'https://api.segment.io'),
        ],
        'kafka' => [
            'brokers' => env('KAFKA_BROKERS', ''),
            'topic' => env('KAFKA_EVENTS_TOPIC', 'product_events'),
        ],
    ],
];
