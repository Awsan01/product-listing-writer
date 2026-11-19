<?php
/**
 * GET: {ok: true} when the config is found, has a key and the demo is enabled. Never calls Claude.
 * The page uses it for the status dot.
 */
declare(strict_types=1);

define('LISTING_APP', true);
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/config.php';

api_init();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    send_json(405, ['ok' => false, 'error' => 'method'], ['Allow: GET, HEAD']);
}

$app = load_app();
if (!app_ready($app) || storage_dir($app) === null) {
    send_json(503, ['ok' => false, 'error' => 'paused']);
}
send_json(200, ['ok' => true]);
