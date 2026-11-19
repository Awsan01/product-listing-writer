<?php
/**
 * Mock of the Claude Messages API for local tests. Run it with PHP's built-in server:
 *
 *   MOCK_STATE_DIR=/tmp/mock php -S 127.0.0.1:8792 tests/mock-anthropic.php
 *
 * and set 'anthropic_base_url' => 'http://127.0.0.1:8792' in the test config.
 * It checks the request shape, saves a summary of it to MOCK_STATE_DIR/last-request.json and
 * answers with a canned listing in the requested language (a structured-output text block).
 *
 * Markers in the seller's notes trigger error cases:
 *   mock:overloaded-once  first call 529, the retry succeeds      mock:overloaded  always 529
 *   mock:credit           400 "credit balance is too low"         mock:refusal     stop_reason refusal
 *   mock:badjson          text that isn't JSON                    mock:slow        waits 3 seconds
 *   mock:fail-tr          529 for Turkish, normal for the others  mock:image       400 image error
 *   mock:slow-but-ar      Arabic answers at once, the others wait 5 seconds
 *   mock:maxtokens        the reply is cut off in the caption (stop_reason max_tokens)
 */
declare(strict_types=1);

function reply(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('request-id: req_mock_' . bin2hex(random_bytes(4)));
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(int $status, string $type, string $message): never
{
    reply($status, ['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/v1/messages' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_error(404, 'not_found_error', 'Not found');
}

// Request checks, roughly what the real API enforces.
if (($_SERVER['HTTP_X_API_KEY'] ?? '') === '') {
    api_error(401, 'authentication_error', 'x-api-key header is required');
}
if (($_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '') === '') {
    api_error(400, 'invalid_request_error', 'anthropic-version header is required');
}
$req = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($req)) {
    api_error(400, 'invalid_request_error', 'Body must be JSON');
}
foreach (['temperature', 'top_p', 'top_k', 'tool_choice', 'tools', 'prompt'] as $banned) {
    if (array_key_exists($banned, $req)) {
        api_error(400, 'invalid_request_error', "$banned: not expected in this app's requests");
    }
}
// Thinking settings the real API rejects for these models.
$thinking = $req['thinking']['type'] ?? null;
if ($req['model'] === 'claude-sonnet-5-5' && $thinking === 'disabled') {
    api_error(400, 'invalid_request_error', 'To turn thinking off on this model, send "thinking": {"type": "between_tools"} instead of {"type": "disabled"}.');
}
if ($thinking === 'between_tools' && $req['model'] !== 'claude-sonnet-5-5') {
    api_error(400, 'invalid_request_error', '"thinking.type.between_tools" is not supported for this model.');
}
$content = $req['messages'][0]['content'] ?? null;
if (!is_string($req['model'] ?? null) || !is_int($req['max_tokens'] ?? null) || count($req['messages'] ?? []) !== 1
    || ($req['messages'][0]['role'] ?? '') !== 'user' || !is_array($content) || count($content) !== 2) {
    api_error(400, 'invalid_request_error', 'Expected model, max_tokens and one user message with an image and a text block');
}
[$imageBlock, $textBlock] = $content;
$imageData = base64_decode((string)($imageBlock['source']['data'] ?? ''), true);
$size = is_string($imageData) ? @getimagesizefromstring($imageData) : false;
if (($imageBlock['type'] ?? '') !== 'image' || ($imageBlock['source']['type'] ?? '') !== 'base64' || !is_array($size)
    || $size['mime'] !== ($imageBlock['source']['media_type'] ?? '')) {
    api_error(400, 'invalid_request_error', 'messages.0.content.0: invalid image');
}
$prompt = (string)($textBlock['text'] ?? '');
$schema = $req['output_config']['format']['schema'] ?? null;
if (($textBlock['type'] ?? '') !== 'text' || $prompt === '' || ($req['output_config']['format']['type'] ?? '') !== 'json_schema' || !is_array($schema)) {
    api_error(400, 'invalid_request_error', 'Expected a text block and output_config.format of type json_schema');
}

$lang = preg_match('/Write the listing in: (Arabic|English|Turkish)\./', $prompt, $m) ? ['Arabic' => 'ar', 'English' => 'en', 'Turkish' => 'tr'][$m[1]] : 'en';
$ui = preg_match('/seen\.product: what the product is, in (Arabic|English)\./', $prompt, $m) && $m[1] === 'Arabic' ? 'ar' : 'en';
$fields = $schema['properties']['listing']['required'] ?? [];

$state = getenv('MOCK_STATE_DIR') ?: '';
if ($state !== '') {
    @mkdir($state, 0700, true);
    $summary = [
        'model' => $req['model'],
        'max_tokens' => $req['max_tokens'],
        'effort' => $req['output_config']['effort'] ?? null,
        'thinking' => $req['thinking'] ?? null,
        'metadata_user_id' => $req['metadata']['user_id'] ?? null,
        'api_key' => $_SERVER['HTTP_X_API_KEY'],
        'anthropic_version' => $_SERVER['HTTP_ANTHROPIC_VERSION'],
        'workspace_id' => $_SERVER['HTTP_ANTHROPIC_WORKSPACE_ID'] ?? null,
        'image' => ['mime' => $size['mime'], 'width' => $size[0], 'height' => $size[1], 'bytes' => strlen($imageData)],
        'lang' => $lang,
        'ui' => $ui,
        'fields' => $fields,
        'prompt' => $prompt,
    ];
    file_put_contents("$state/last-request.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    file_put_contents("$state/requests.jsonl", json_encode(['model' => $req['model'], 'lang' => $lang]) . "\n", FILE_APPEND | LOCK_EX);
}

// Error cases, chosen by markers in the seller's notes.
if (str_contains($prompt, 'mock:overloaded-once')) {
    $flag = ($state !== '' ? $state : sys_get_temp_dir()) . '/overloaded-' . md5($prompt);
    if (!is_file($flag)) {
        touch($flag);
        api_error(529, 'overloaded_error', 'Overloaded');
    }
} elseif (str_contains($prompt, 'mock:overloaded') || (str_contains($prompt, 'mock:fail-tr') && $lang === 'tr')) {
    api_error(529, 'overloaded_error', 'Overloaded');
}
if (str_contains($prompt, 'mock:credit')) {
    api_error(400, 'invalid_request_error', 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.');
}
if (str_contains($prompt, 'mock:image')) {
    api_error(400, 'invalid_request_error', 'messages.0.content.0.image.source.base64: The image could not be processed.');
}
if (str_contains($prompt, 'mock:slow-but-ar')) {
    if ($lang !== 'ar') {
        sleep(5);
    }
} elseif (str_contains($prompt, 'mock:slow')) {
    sleep(3);
}
$usage = ['input_tokens' => 1650 + strlen($prompt) % 97, 'output_tokens' => 380 + count($fields) * 23];
if (str_contains($prompt, 'mock:refusal')) {
    reply(200, ['id' => 'msg_mock', 'type' => 'message', 'role' => 'assistant', 'model' => $req['model'], 'content' => [],
        'stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'general_harms', 'explanation' => null],
        'usage' => ['input_tokens' => $usage['input_tokens'], 'output_tokens' => 0]]);
}

// Canned listing for a ceramic mug. A few values are messy on purpose (spaces, a hashtag without #,
// a sixth feature, a literal "\n") so the tests can see the server sanitize them.
$seen = [
    'ar' => ['product' => 'كوب قهوة من السيراميك', 'items' => ['كوب سيراميك أخضر', 'مقبض دائري', 'لمسة لامعة', 'صحن خشبي']],
    'en' => ['product' => 'Ceramic coffee mug', 'items' => ['Green ceramic mug', 'Round handle', 'Glossy glaze', 'Wooden saucer']],
];
$listings = [
    'ar' => [
        'title' => '  كوب قهوة سيراميك أخضر بلمسة لامعة ومقبض مريح  ',
        'short' => 'كوب سيراميك أنيق بلون أخضر هادئ يضيف دفئاً إلى قهوتك اليومية.',
        'description' => "استمتع بقهوتك الصباحية في كوب سيراميك بلون أخضر هادئ ولمسة نهائية لامعة تعكس الضوء بنعومة.\\n\\nيأتي الكوب بمقبض دائري مريح يسهل الإمساك به، ويناسب القهوة والشاي والمشروبات الساخنة، كما يضيف لمسة جميلة إلى طاولة المطبخ أو المكتب.",
        'features' => ['• سيراميك بلمسة لامعة', 'لون أخضر هادئ', 'مقبض دائري مريح', 'مناسب للقهوة والشاي', 'تصميم بسيط وأنيق', 'ميزة سادسة للاختبار'],
        'keywords' => ['كوب قهوة', 'كوب سيراميك', 'كوب أخضر', 'أكواب قهوة', 'كوب شاي', 'هدية لمحبي القهوة', 'كوب قهوة سيراميك', 'أدوات القهوة', 'كوب قهوة'],
        'meta' => 'كوب قهوة سيراميك أخضر بلمسة لامعة ومقبض مريح، مناسب للقهوة والشاي ويضيف لمسة أنيقة إلى يومك.',
        'caption' => "قهوة الصباح أحلى في كوبك الأخضر ☕\nاطلبه الآن وأضف لمسة هدوء إلى يومك.",
        'hashtags' => ['#كوب_قهوة', 'أكواب سيراميك', '#قهوة', '#ديكور_المطبخ', '#هدايا'],
    ],
    'en' => [
        'title' => 'Green Ceramic Coffee Mug with Glossy Glaze',
        'short' => '  A calm green ceramic mug that brings warmth to your daily coffee.',
        'description' => "Enjoy your morning coffee in a ceramic mug finished in a calm green glaze that catches the light softly.\n\nIts round handle is comfortable to hold, and it suits coffee, tea and other hot drinks while adding a simple, stylish touch to your kitchen or desk.",
        'features' => ['Glossy ceramic finish', 'Calm green colour', 'Comfortable round handle', 'Suits coffee and tea', 'Simple, stylish design', 'A sixth feature for testing'],
        'keywords' => ['coffee mug', 'ceramic mug', 'green mug', 'ceramic coffee cup', 'tea mug', 'coffee lover gift', 'glossy mug', 'kitchen mug'],
        'meta' => 'Green ceramic coffee mug with a glossy glaze and a comfortable handle, made for coffee, tea and calm mornings.',
        'caption' => "Morning coffee tastes better in green ☕\nOrder yours today.",
        'hashtags' => ['#coffeemug', 'ceramic mug', '#greenmug', '#coffeetime', '#kitchendecor'],
    ],
    'tr' => [
        'title' => 'Parlak Sırlı Yeşil Seramik Kahve Kupası',
        'short' => 'Günlük kahvenize sıcaklık katan, sakin yeşil tonlu şık bir seramik kupa.',
        'description' => "Sabah kahvenizi, ışığı yumuşakça yansıtan parlak yeşil sırlı bu seramik kupada keyifle için.\n\nYuvarlak kulpu rahat bir tutuş sunar; kahve, çay ve diğer sıcak içecekler için idealdir ve mutfağınıza ya da masanıza sade bir şıklık katar.",
        'features' => ['Parlak seramik yüzey', 'Sakin yeşil renk', 'Rahat yuvarlak kulp', 'Kahve ve çay için uygun', 'Sade ve şık tasarım', 'Test için altıncı özellik'],
        'keywords' => ['kahve kupası', 'seramik kupa', 'yeşil kupa', 'seramik kahve fincanı', 'çay kupası', 'kahve sever hediyesi', 'parlak kupa', 'mutfak kupası'],
        'meta' => 'Parlak sırlı ve rahat kulplu yeşil seramik kahve kupası; kahve, çay ve huzurlu sabahlar için.',
        'caption' => "Sabah kahvesi yeşil kupada daha güzel ☕\nHemen sipariş verin.",
        'hashtags' => ['#kahvekupası', 'seramik kupa', '#yeşilkupa', '#kahvemolası', '#mutfakdekor'],
    ],
];
$listing = [];
foreach ($fields as $f) {
    $listing[$f] = $listings[$lang][$f] ?? '';
}
$text = json_encode(['seen' => $seen[$ui], 'listing' => $listing], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (str_contains($prompt, 'mock:badjson')) {
    $text = 'Here is your listing: ' . substr((string)$text, 0, 40);
}
$stop = 'end_turn';
if (str_contains($prompt, 'mock:maxtokens') && ($at = strpos((string)$text, '"caption":"')) !== false) {
    $text = mb_strcut((string)$text, 0, $at + 24, 'UTF-8');
    $stop = 'max_tokens';
}

reply(200, [
    'id' => 'msg_mock_' . bin2hex(random_bytes(6)),
    'type' => 'message',
    'role' => 'assistant',
    'model' => $req['model'],
    'content' => [
        ['type' => 'thinking', 'thinking' => '', 'signature' => 'mock-signature'],
        ['type' => 'text', 'text' => $text],
    ],
    'stop_reason' => $stop,
    'stop_sequence' => null,
    'usage' => $usage,
]);
