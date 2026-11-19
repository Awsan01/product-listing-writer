<?php
/**
 * POST multipart/form-data: one photo and one language -> {seen, listing} or {error: code}.
 *
 * Fields: image (JPEG/PNG/WebP, max 5 MB, max 4000 px per side), lang (ar|en|tr), ui (ar|en),
 * tone (lux|friendly|pro|bold), len (short|medium|long), features|seo|social (0|1),
 * quality (best|fast), notes (max 600 characters).
 * The prompt is built here; the browser never sends prompt text or a model name.
 */
declare(strict_types=1);

define('LISTING_APP', true);
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/limits.php';
require __DIR__ . '/lib/listing.php';
require __DIR__ . '/lib/anthropic.php';

api_init();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    send_json(405, ['error' => 'method'], ['Allow: POST']);
}
if (!origin_ok()) {
    send_json(403, ['error' => 'forbidden']);
}

$app = load_app();
if (!app_ready($app)) {
    send_json(503, ['error' => 'paused']);
}
$cfg = $app['cfg'];
$store = storage_dir($app);
if ($store === null) {
    send_json(503, ['error' => 'paused']);
}

$langField = is_string($_POST['lang'] ?? null) && isset(LANG_NAMES[$_POST['lang']]) ? $_POST['lang'] : '';
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_IMAGE_BYTES + 256 * 1024) {
    usage_log($store, '', $langField, 0, 0, 'too_large');
    send_json(413, ['error' => 'too_large']);
}

$o = read_options($_POST);
if ($o === null) {
    usage_log($store, '', $langField, 0, 0, 'bad_request');
    send_json(400, ['error' => 'bad_request']);
}
$model = $cfg['models'][$o['quality']];

$img = read_image($_FILES['image'] ?? null);
if (isset($img['error'])) {
    usage_log($store, $model, $o['lang'], 0, 0, $img['error']);
    send_json($img['status'], ['error' => $img['error']]);
}

$visitor = client_key(ip_salt($cfg));
$wait = rate_take($store, $visitor, $cfg['limits']);
if ($wait < 0) {
    send_json(503, ['error' => 'paused']);
}
if ($wait > 0) {
    usage_log($store, $model, $o['lang'], 0, 0, 'rate_limited');
    send_json(429, ['error' => 'rate_limited', 'retry_after' => $wait], ['Retry-After: ' . $wait]);
}

@set_time_limit(CLAUDE_TIMEOUT + 60);
$res = anthropic_send($cfg, anthropic_payload($cfg, $o, $img, $visitor));
unset($img);
$out = anthropic_outcome($res, $o);
usage_log($store, $out['model'] !== '' ? $out['model'] : $model, $o['lang'], $out['in'], $out['out'], $out['log']);

$body = $out['body'];
$headers = [];
if ($out['retry_after'] > 0) {
    $body['retry_after'] = $out['retry_after'];
    $headers[] = 'Retry-After: ' . $out['retry_after'];
}
send_json($out['http'], $body, $headers);
