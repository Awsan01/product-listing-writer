<?php
/**
 * JSON responses, error handling, Origin check and visitor key.
 */
declare(strict_types=1);

if (!defined('LISTING_APP')) {
    http_response_code(404);
    exit;
}

/** Keeps PHP notices, stack traces and paths out of the response; errors go to the server log only. */
function api_init(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    set_exception_handler(static function (Throwable $e): void {
        error_log('listing-writer: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        send_json(500, ['error' => 'server']);
    });

    register_shutdown_function(static function (): void {
        $e = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if ($e !== null && in_array($e['type'], $fatal, true) && !headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
            echo '{"error":"server"}';
        }
    });
}

/** Sends a JSON response and stops. */
function send_json(int $status, array $data, array $headers = []): never
{
    if (!headers_sent()) {
        header_remove('X-Powered-By');
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $h) {
            header($h);
        }
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** True when the Origin header names this same host (blocks other sites from posting to the API). */
function origin_ok(): bool
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($origin === '' || $host === '') {
        return false;
    }
    $p = parse_url($origin);
    if (!is_array($p) || !isset($p['scheme'], $p['host']) || isset($p['path']) || isset($p['query'])) {
        return false;
    }
    if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
        return false;
    }
    $from = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    $plain = static fn(string $h): string => (string)preg_replace('/:(80|443)$/', '', $h);
    return $plain($from) === $plain($host);
}

/**
 * Anonymous visitor key: a salted hash of REMOTE_ADDR, never the IP itself.
 * One IPv6 /64 counts as one visitor, because a single device can rotate inside it.
 */
function client_key(string $salt): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $bin = @inet_pton($ip);
    if (is_string($bin) && strlen($bin) === 16) {
        $mapped = str_repeat("\0", 10) . "\xff\xff";
        $bin = strncmp($bin, $mapped, 12) === 0 ? substr($bin, 12) : substr($bin, 0, 8);
    }
    return substr(hash_hmac('sha256', is_string($bin) ? $bin : $ip, $salt), 0, 32);
}
