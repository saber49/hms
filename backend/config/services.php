<?php

return [

    // Public demo: tenant slug whose dashboard is embedded on the landing
    // page. When unset (production default) the demo login is disabled.
    'demo' => [
        'tenant_slug' => env('DEMO_TENANT_SLUG'),
    ],

    // Web-Push (VAPID) keys for worker-app push notifications.
    'webpush' => [
        'public_key'  => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject'     => env('VAPID_SUBJECT', 'mailto:info@clood-x.com'),
    ],

    // Worker-app GPS geofence: when true, clock-in/out is rejected unless
    // the worker is inside the object's radius (geo_radius, default 100 m).
    // Set WORKER_GEO_ENFORCE=false to allow stamping from anywhere while
    // still recording geo_in/geo_out for the audit trail.
    'worker_geo' => [
        'enforce' => env('WORKER_GEO_ENFORCE', true),
    ],

    // Firebase Cloud Messaging (native Android worker app). Absolute path
    // to the Firebase service-account JSON on the server.
    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS_JSON'),
    ],

    // Direct APNs (native iOS worker app) — no Firebase needed on iOS.
    'apns' => [
        'key_path'  => env('APNS_KEY_PATH'),
        'key_id'    => env('APNS_KEY_ID'),
        'team_id'   => env('APNS_TEAM_ID'),
        'bundle_id' => env('APNS_BUNDLE_ID', 'com.cloodx.worker'),
    ],

    // Self-hosted coturn (same VPS) — TURN REST credentials are signed with
    // the shared secret: username "<expiry>:<label>", base64(hmac_sha1).
    'turn' => [
        'host'   => env('TURN_HOST'),
        'port'   => (int) env('TURN_PORT', 3478),
        'secret' => env('TURN_SECRET'),
    ],

    // E-Mail section: OAuth apps are owned by the platform (one app per
    // provider for ALL tenants) — tenants never enter client ids. A missing
    // client id simply hides that provider's sign-in button.
    'mail_oauth' => [
        'microsoft' => [
            'client_id'     => env('MICROSOFT_MAIL_CLIENT_ID'),
            'client_secret' => env('MICROSOFT_MAIL_CLIENT_SECRET'),
            'tenant'        => env('MICROSOFT_MAIL_TENANT', 'common'),
        ],
        'google' => [
            'client_id'     => env('GOOGLE_MAIL_CLIENT_ID'),
            'client_secret' => env('GOOGLE_MAIL_CLIENT_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
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

];
