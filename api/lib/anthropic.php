<?php
/**
 * Claude Messages API over plain cURL: builds the request, sends it, and turns the reply into
 * a clean listing or a short error code. The key, raw API errors and stack traces never reach the browser.
 */
declare(strict_types=1);

if (!defined('LISTING_APP')) {
    http_response_code(404);
    exit;
}

const ANTHROPIC_VERSION = '2026-10-21';
/** Thinking effort. "low" suits short copywriting and leaves the 1500-token budget for the listing. */
const LISTING_EFFORT = 'low';
/** Total time for the Claude call, including the one retry. */
const CLAUDE_TIMEOUT = 90;
/**
 * Thinking off for the default models, so the whole max_tokens budget goes to the listing
 * (a long Arabic listing with every section is close to 1,500 tokens on its own).
 * Each model has its own setting for this; other models keep adaptive thinking at low effort.
 */
const THINKING_OFF = [
    'claude-sonnet-5-5' => ['type' => 'between_tools'],
    'claude-haiku-5-5' => ['type' => 'disabled'],
];

/** One photo and one language. JSON comes back through structured outputs (output_config.format). */
function anthropic_payload(array $cfg, array $o, array $img, string $visitor): array
{
    $model = $cfg['models'][$o['quality']];
    $payload = [
        'model' => $model,
        'max_tokens' => $cfg['max_tokens'],
        'metadata' => ['user_id' => $visitor],
        'output_config' => [
            'effort' => LISTING_EFFORT,
            'format' => ['type' => 'json_schema', 'schema' => listing_schema($o)],
        ],
        'messages' => [[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'image',
                    'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => base64_encode($img['data'])],
                ],
                ['type' => 'text', 'text' => build_prompt($o)],
            ],
        ]],
    ];
    if (isset(THINKING_OFF[$model])) {
        $payload['thinking'] = THINKING_OFF[$model];
    }
    return $payload;
}

/** Sends the request. Retries once on 529 (overloaded) or another 5xx while enough time is left. */
function anthropic_send(array $cfg, array $payload): array
{
    $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headers = [
        'content-type: application/json',
        'accept: application/json',
        'x-api-key: ' . $cfg['anthropic_api_key'],
        'anthropic-version: ' . ANTHROPIC_VERSION,
    ];
    if ($cfg['workspace_id'] !== '') {
        $headers[] = 'anthropic-workspace-id: ' . $cfg['workspace_id'];
    }
    $url = $cfg['anthropic_base_url'] . '/v1/messages';
    $start = microtime(true);
    for ($attempt = 1; ; $attempt++) {
        $left = CLAUDE_TIMEOUT - (microtime(true) - $start);
        $res = http_post($url, $body, $headers, max(5, (int)floor($left)));
        $retryable = $res['status'] === 529 || ($res['status'] >= 500 && $res['status'] <= 599);
        if ($attempt >= 2 || !$retryable) {
            return $res;
        }
        $pause = min(3, max(1, $res['retry_after']));
        if (CLAUDE_TIMEOUT - (microtime(true) - $start) - $pause < 15) {
            return $res;
        }
        sleep($pause);
    }
}

function http_post(string $url, string $body, array $headers, int $timeout): array
{
    $retryAfter = 0;
    $requestId = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter, &$requestId): int {
            if (stripos($line, 'retry-after:') === 0) {
                $retryAfter = max(0, (int)trim(substr($line, 12)));
            } elseif (stripos($line, 'request-id:') === 0) {
                $requestId = trim(substr($line, 11));
            }
            return strlen($line);
        },
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);
    if (!is_string($raw) || $errno !== 0) {
        return ['status' => 0, 'body' => null, 'retry_after' => 0, 'request_id' => '', 'net' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'network'];
    }
    $data = json_decode($raw, true);
    return ['status' => $status, 'body' => is_array($data) ? $data : null, 'retry_after' => $retryAfter, 'request_id' => $requestId, 'net' => ''];
}

/**
 * Turns the API reply into the browser response.
 * Returns ['ok', 'http', 'body', 'retry_after', 'log', 'model', 'in', 'out'].
 */
function anthropic_outcome(array $res, array $o): array
{
    $b = $res['body'] ?? null;
    $usage = is_array($b) && is_array($b['usage'] ?? null) ? $b['usage'] : [];
    $base = [
        'ok' => false,
        'retry_after' => 0,
        'model' => is_array($b) && is_string($b['model'] ?? null) ? $b['model'] : '',
        'in' => (int)($usage['input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0) + (int)($usage['cache_read_input_tokens'] ?? 0),
        'out' => (int)($usage['output_tokens'] ?? 0),
    ];
    $fail = static function (int $http, string $code, string $log, int $retry = 0) use ($base, $res, $b): array {
        if ($code !== 'refused') {
            $type = is_array($b) ? (string)($b['error']['type'] ?? '') : '';
            $msg = is_array($b) ? substr((string)($b['error']['message'] ?? ''), 0, 300) : '';
            error_log(sprintf('listing-writer: Claude call failed: %s (HTTP %d %s %s) request-id %s', $log, $res['status'], $type, $msg, $res['request_id'] ?: '-'));
        }
        return array_merge($base, ['http' => $http, 'body' => ['error' => $code], 'retry_after' => $retry, 'log' => $log]);
    };

    if ($res['net'] !== '') {
        return $fail(503, 'busy', 'busy_' . $res['net']);
    }
    $s = $res['status'];
    if ($s === 200 && is_array($b)) {
        $stop = (string)($b['stop_reason'] ?? '');
        if ($stop === 'refusal') {
            return $fail(422, 'refused', 'refused');
        }
        $text = '';
        foreach (is_array($b['content'] ?? null) ? $b['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        $data = json_decode(trim($text), true);
        $cut = false;
        if (!is_array($data) && $stop === 'max_tokens') {
            // Cut off by max_tokens: keep the fields that were finished.
            $data = json_salvage(trim($text));
            $cut = is_array($data);
        }
        $clean = is_array($data) ? sanitize_result($data, $o) : null;
        if ($clean === null) {
            return $fail(502, 'format', $stop === 'max_tokens' ? 'max_tokens' : 'bad_output');
        }
        return array_merge($base, ['ok' => true, 'http' => 200, 'body' => $clean, 'log' => $cut ? 'ok_cut' : 'ok']);
    }

    $type = is_array($b) ? (string)($b['error']['type'] ?? '') : '';
    $msg = is_array($b) ? strtolower((string)($b['error']['message'] ?? '')) : '';
    $code = is_array($b) ? (string)($b['error']['details']['error_code'] ?? '') : '';
    $billing = $s === 402 || $type === 'billing_error' || $code === 'enforced_spend_limit_reached'
        || str_contains($msg, 'credit balance') || str_contains($msg, 'spend limit') || str_contains($msg, 'usage limit');
    if ($billing) {
        return $fail(503, 'paused', 'paused_billing');
    }
    if (in_array($s, [401, 403, 404], true)) {
        return $fail(503, 'paused', 'paused_' . $s);
    }
    if ($s === 429) {
        return $fail(503, 'busy', 'busy_429', min(3600, max(30, $res['retry_after'])));
    }
    if ($s === 413) {
        return $fail(413, 'too_large', 'too_large');
    }
    if ($s === 400 && str_contains($msg, 'image')) {
        return $fail(422, 'photo_rejected', 'photo_rejected');
    }
    if ($s >= 500 || $s === 0) {
        return $fail(503, 'busy', 'busy_' . $s);
    }
    return $fail(502, 'server', 'error_' . $s);
}
