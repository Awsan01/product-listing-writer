<?php
/**
 * Product listing writer: server config.
 *
 * Copy this file OUTSIDE the web root, rename it and fill in your key:
 *   Hostinger:      domains/<your-domain>/private/listing-config.php (one level above public_html)
 *   Anywhere else:  any path, then point the LISTING_CONFIG environment variable at it
 *   Local php -S:   config.local.php in the repo root (gitignored, only read by the built-in server)
 *
 * Never commit the real file.
 */
return [
    // Your Claude API key, from https://platform.claude.com/settings/keys
    'anthropic_api_key' => '',

    // Optional. Sent as the anthropic-workspace-id header when set.
    'workspace_id' => '',

    // The browser asks for "best" or "fast"; it can't pick any other model.
    'models' => [
        'best' => 'claude-sonnet-5-5',
        'fast' => 'claude-haiku-5-5',
    ],
    'max_tokens' => 1500,

    // false = every API call returns 503 "demo paused" and the page shows a friendly message.
    'enabled' => true,

    // One request = one language of one product. Set a limit to 0 to turn it off.
    'limits' => [
        'ip_per_hour'  => 30,   // per visitor IP
        'ip_per_day'   => 60,   // per visitor IP
        'site_per_day' => 600,  // whole site, resets at midnight UTC
    ],

    // Optional. Secret used to hash visitor IPs. Empty = derived from the API key.
    'ip_salt' => '',

    // Optional. Folder for rate-limit counters and usage logs.
    // Empty = a listing-storage folder next to this config file (created with 0700).
    'storage_dir' => '',

    // Optional. Only change this for tests (tests/mock-anthropic.php).
    'anthropic_base_url' => 'https://api.anthropic.com',
];
