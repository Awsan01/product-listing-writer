<?php
/**
 * Request validation, the prompt, the JSON schema and the output sanitizer.
 * To change tones, lengths or fields, edit the tables at the top (and the matching ones in index.html).
 */
declare(strict_types=1);

if (!defined('LISTING_APP')) {
    http_response_code(404);
    exit;
}

const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const MAX_IMAGE_SIDE = 4000;
const MAX_NOTES = 600;

const LANG_NAMES = ['ar' => 'Arabic', 'en' => 'English', 'tr' => 'Turkish'];
const UI_LANGS = ['ar', 'en'];
const TONES = [
    'lux' => 'elegant and premium',
    'friendly' => 'warm and friendly',
    'pro' => 'clear and professional',
    'bold' => 'energetic and persuasive',
];
const LENGTHS = ['short' => '40 to 60', 'medium' => '80 to 120', 'long' => '150 to 200'];

/** Server-side caps: [kind, max characters or items, max characters per item]. */
const FIELD_CAPS = [
    'title' => ['line', 150],
    'short' => ['line', 400],
    'description' => ['text', 3000],
    'features' => ['list', 5, 200],
    'keywords' => ['list', 12, 80],
    'meta' => ['line', 300],
    'caption' => ['text', 600],
    'hashtags' => ['tags', 8, 60],
];

/** Whitelists the form fields. Returns the options, or null when anything is missing or unknown. */
function read_options(array $post): ?array
{
    $pick = static function (string $key, array $allowed) use ($post): ?string {
        $v = $post[$key] ?? null;
        return is_string($v) && in_array($v, $allowed, true) ? $v : null;
    };
    $o = [
        'lang' => $pick('lang', array_keys(LANG_NAMES)),
        'ui' => $pick('ui', UI_LANGS),
        'tone' => $pick('tone', array_keys(TONES)),
        'len' => $pick('len', array_keys(LENGTHS)),
        'features' => $pick('features', ['0', '1']),
        'seo' => $pick('seo', ['0', '1']),
        'social' => $pick('social', ['0', '1']),
        'quality' => $pick('quality', ['best', 'fast']),
    ];
    if (in_array(null, $o, true)) {
        return null;
    }
    $notes = $post['notes'] ?? '';
    if (!is_string($notes) || !preg_match('//u', $notes)) {
        return null;
    }
    $notes = u_trim(strip_controls(str_replace(["\r\n", "\r"], "\n", $notes)));
    if (u_len($notes) > MAX_NOTES) {
        return null;
    }
    $o['notes'] = $notes;
    foreach (['features', 'seo', 'social'] as $k) {
        $o[$k] = $o[$k] === '1';
    }
    return $o;
}

/**
 * Checks the uploaded photo: JPEG, PNG or WebP, at most 5 MB and 4000 px per side.
 * Returns ['data', 'mime', 'w', 'h'] or ['status', 'error'].
 */
function read_image($file): array
{
    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
        return ['status' => 400, 'error' => 'bad_request'];
    }
    $err = (int)$file['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['status' => 413, 'error' => 'too_large'];
    }
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['status' => 400, 'error' => 'bad_request'];
    }
    if ($err === UPLOAD_ERR_NO_TMP_DIR || $err === UPLOAD_ERR_CANT_WRITE || $err === UPLOAD_ERR_EXTENSION) {
        error_log('listing-writer: PHP could not store the upload (error ' . $err . ')');
        return ['status' => 500, 'error' => 'server'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        return ['status' => 400, 'error' => 'photo_rejected'];
    }
    if ((int)($file['size'] ?? 0) > MAX_IMAGE_BYTES || (int)@filesize($tmp) > MAX_IMAGE_BYTES) {
        return ['status' => 413, 'error' => 'too_large'];
    }
    $data = @file_get_contents($tmp);
    $info = is_string($data) && $data !== '' ? @getimagesizefromstring($data) : false;
    $types = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
    if (!is_array($info) || !isset($types[$info[2]])) {
        return ['status' => 400, 'error' => 'photo_rejected'];
    }
    [$w, $h] = $info;
    if ($w < 1 || $h < 1 || $w > MAX_IMAGE_SIDE || $h > MAX_IMAGE_SIDE) {
        return ['status' => 400, 'error' => 'photo_rejected'];
    }
    return ['data' => $data, 'mime' => $types[$info[2]], 'w' => $w, 'h' => $h];
}

/** The prompt for one language. The rules are the same as in the original app. */
function build_prompt(array $o): string
{
    $lang = LANG_NAMES[$o['lang']];
    $ui = $o['ui'] === 'ar' ? 'Arabic' : 'English';
    $notes = $o['notes'] !== '' ? '«' . $o['notes'] . '»' : 'none';

    $p = "You write e-commerce product listings from a product photo.\n"
        . "The attached photo shows the product. Seller's notes: {$notes}.\n"
        . "Write the listing in: {$lang}. Tone: " . TONES[$o['tone']] . ".\n"
        . "\n"
        . "Rules\n"
        . "- Describe only what you can see in the photo or read in the notes. Never invent sizes, materials, ingredients, certifications, prices, brand names or performance claims such as how long something lasts. Leave out anything you are unsure about.\n"
        . "- Write each language natively, not as a word-for-word translation, with correct grammar and natural word order. Arabic: Modern Standard Arabic that Gulf shoppers find natural. Turkish: natural Turkish e-commerce style.\n"
        . "- title: up to 70 characters, with the product type and its main feature.\n"
        . "- short: one sentence.\n"
        . '- description: ' . LENGTHS[$o['len']] . ' words in two short paragraphs separated by \n\n.' . "\n";
    if ($o['features']) {
        $p .= "- features: 3 to 5 short points.\n";
    }
    if ($o['seo']) {
        $p .= "- keywords: 8 to 12 search phrases shoppers would type in that language, most important first.\n"
            . "- meta: up to 155 characters, for search results.\n";
    }
    if ($o['social']) {
        $p .= "- caption: an Instagram caption of 1 to 3 short lines with at most one emoji.\n"
            . "- hashtags: 5 to 8 hashtags in that language.\n";
    }
    $p .= "\n"
        . "Output: one JSON object that matches the schema.\n"
        . "- seen.product: what the product is, in {$ui}.\n"
        . "- seen.items: 3 to 6 short things you can see, in {$ui}.\n"
        . "- listing: the listing in {$lang}, following the rules above.";
    return $p;
}

/** Listing fields for these options, in display order. */
function listing_fields(array $o): array
{
    $f = ['title', 'short', 'description'];
    if ($o['features']) {
        $f[] = 'features';
    }
    if ($o['seo']) {
        array_push($f, 'keywords', 'meta');
    }
    if ($o['social']) {
        array_push($f, 'caption', 'hashtags');
    }
    return $f;
}

/** JSON schema for structured outputs: {seen: {product, items[]}, listing: {...}}. */
function listing_schema(array $o): array
{
    $text = ['type' => 'string'];
    $list = ['type' => 'array', 'items' => ['type' => 'string']];
    $props = [];
    foreach (listing_fields($o) as $k) {
        $props[$k] = in_array(FIELD_CAPS[$k][0], ['list', 'tags'], true) ? $list : $text;
    }
    return [
        'type' => 'object',
        'properties' => [
            'seen' => [
                'type' => 'object',
                'properties' => ['product' => $text, 'items' => $list],
                'required' => ['product', 'items'],
                'additionalProperties' => false,
            ],
            'listing' => [
                'type' => 'object',
                'properties' => $props,
                'required' => array_keys($props),
                'additionalProperties' => false,
            ],
        ],
        'required' => ['seen', 'listing'],
        'additionalProperties' => false,
    ];
}

/**
 * Trims and caps everything the model returned and keeps only the requested fields.
 * Returns null when there is no usable listing.
 */
function sanitize_result(array $r, array $o): ?array
{
    $seen = is_array($r['seen'] ?? null) ? $r['seen'] : [];
    $in = is_array($r['listing'] ?? null) ? $r['listing'] : [];
    $listing = [];
    foreach (listing_fields($o) as $k) {
        $cap = FIELD_CAPS[$k];
        $v = match ($cap[0]) {
            'line' => clean_text($in[$k] ?? '', $cap[1], true),
            'text' => clean_text($in[$k] ?? '', $cap[1], false),
            default => clean_list($in[$k] ?? [], $cap[1], $cap[2], $k),
        };
        if ($v !== '' && $v !== []) {
            $listing[$k] = $v;
        }
    }
    if (!isset($listing['title']) && !isset($listing['description'])) {
        return null;
    }
    return [
        'seen' => [
            'product' => clean_text($seen['product'] ?? '', 120, true),
            'items' => clean_list($seen['items'] ?? [], 6, 60, 'items'),
        ],
        'listing' => $listing,
    ];
}

function clean_text($v, int $max, bool $oneLine): string
{
    if (is_int($v) || is_float($v)) {
        $v = (string)$v;
    }
    if (!is_string($v) || !preg_match('//u', $v)) {
        return '';
    }
    // Literal "\n" sequences become real line breaks, as the original app did.
    $s = strip_controls(str_replace(["\r\n", "\r", '\n'], "\n", $v));
    if ($oneLine) {
        $s = (string)preg_replace('/\s+/u', ' ', $s);
    } else {
        $s = (string)preg_replace("/[ \t]+\n/u", "\n", $s);
        $s = (string)preg_replace("/\n{3,}/u", "\n\n", $s);
    }
    return u_cut(u_trim($s), $max);
}

/** Cleans a list: trims, drops empties and duplicates, caps sizes; hashtags always start with #. */
function clean_list($v, int $maxItems, int $maxLen, string $field): array
{
    if (is_string($v)) {
        $v = preg_split('/[,،\n]+/u', $v) ?: [];
    }
    if (!is_array($v)) {
        return [];
    }
    $out = [];
    foreach ($v as $item) {
        $t = clean_text($item, 400, true);
        if ($field === 'features') {
            $t = u_trim((string)preg_replace('/^[•●▪◦·*\-–—]+/u', '', $t));
        } elseif ($field === 'hashtags') {
            $t = (string)preg_replace('/^[#＃]+/u', '', $t);
            $t = (string)preg_replace('/\s+/u', '_', $t);
            $t = (string)preg_replace('/[^\p{L}\p{N}\p{M}_]/u', '', $t);
            $t = trim($t, '_');
            $t = $t === '' ? '' : '#' . $t;
        }
        $t = u_cut($t, $maxLen);
        if ($t === '' || in_array($t, $out, true)) {
            continue;
        }
        $out[] = $t;
        if (count($out) >= $maxItems) {
            break;
        }
    }
    return $out;
}

/**
 * Recovers JSON that was cut off mid-way: goes back to the end of the last complete value and closes
 * the objects and arrays still open, so a half-written field is dropped rather than shown.
 */
function json_salvage(string $text): ?array
{
    $stack = [];
    $keyNext = [];
    $inString = false;
    $escaped = false;
    $isKey = false;
    $safeAt = -1;
    $safeStack = [];
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $c = $text[$i];
        if ($inString) {
            if ($escaped) {
                $escaped = false;
            } elseif ($c === '\\') {
                $escaped = true;
            } elseif ($c === '"') {
                $inString = false;
                if (!$isKey) {
                    $safeAt = $i + 1;
                    $safeStack = $stack;
                }
            }
            continue;
        }
        $depth = count($stack) - 1;
        if ($c === '"') {
            $inString = true;
            $isKey = $depth >= 0 && $stack[$depth] === '{' && $keyNext[$depth];
        } elseif ($c === ':' && $depth >= 0) {
            $keyNext[$depth] = false;
        } elseif ($c === ',' && $depth >= 0 && $stack[$depth] === '{') {
            $keyNext[$depth] = true;
        } elseif ($c === '{' || $c === '[') {
            $stack[] = $c;
            $keyNext[] = $c === '{';
        } elseif (($c === '}' || $c === ']') && $depth >= 0) {
            array_pop($stack);
            array_pop($keyNext);
            $safeAt = $i + 1;
            $safeStack = $stack;
        }
    }
    if ($safeAt < 0) {
        return null;
    }
    $json = substr($text, 0, $safeAt);
    for ($j = count($safeStack) - 1; $j >= 0; $j--) {
        $json .= $safeStack[$j] === '{' ? '}' : ']';
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/** Removes control characters except line breaks and tabs. */
function strip_controls(string $s): string
{
    return (string)preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', $s);
}

function u_trim(string $s): string
{
    return (string)preg_replace('/^\s+|\s+$/u', '', $s);
}

function u_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int)preg_match_all('/./su', $s);
}

/** Cuts to $max characters without breaking UTF-8, at a word boundary when one is close. */
function u_cut(string $s, int $max): string
{
    if (u_len($s) <= $max) {
        return $s;
    }
    $cut = function_exists('mb_substr')
        ? mb_substr($s, 0, $max, 'UTF-8')
        : (preg_match('/^.{0,' . $max . '}/su', $s, $m) ? $m[0] : '');
    if (preg_match('/^(.*\S)\s+\S*$/su', $cut, $m) && u_len($m[1]) >= (int)($max * 0.7)) {
        $cut = $m[1];
    }
    return u_trim((string)preg_replace('/[\s,،;:\-–—]+$/u', '', $cut));
}
