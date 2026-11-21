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

// Canned listing for the brown leather travel bag in tests/fixtures/leather-bag.jpg. A few values are messy
// on purpose (spaces, a hashtag without #, a sixth feature, a literal "\n") so the tests can see the server sanitize them.
$seen = [
    'ar' => ['product' => 'حقيبة سفر جلدية بنية', 'items' => ['حقيبة جلد بني', 'مقبضان علويان', 'حزام كتف طويل', 'جيبان أماميان', 'أبازيم معدنية']],
    'en' => ['product' => 'Brown leather travel bag', 'items' => ['Brown leather bag', 'Two top handles', 'Long shoulder strap', 'Two front pockets', 'Metal buckles']],
];
$listings = [
    'ar' => [
        'title' => '  حقيبة سفر جلدية بنية بحزام كتف وجيبين أماميين  ',
        'short' => 'حقيبة جلدية بنية بمقبضين علويين وحزام كتف طويل وجيبين أماميين بأغطية.',
        'description' => "احمل أغراضك بأناقة مع هذه الحقيبة الجلدية البنية بلونها الدافئ وطابعها الكلاسيكي، إذ تمنحها الخياطة الظاهرة والمسامير النحاسية والأبازيم المعدنية مظهراً متيناً وجذاباً.\\n\\nيسهّل المقبضان العلويان حملها باليد، ويترك حزام الكتف الطويل يديك حرتين أثناء التنقل، فيما يحفظ الجيبان الأماميان أغراضك الصغيرة في متناولك، لتكون خياراً مناسباً لرحلات نهاية الأسبوع والنادي والسفر.",
        'features' => ['• جلد بني بخياطة ظاهرة', 'مقبضان علويان للحمل باليد', 'حزام كتف طويل بأبازيم معدنية', 'جيبان أماميان بأغطية', 'تفاصيل من المسامير النحاسية', 'ميزة سادسة للاختبار'],
        'keywords' => ['حقيبة جلد', 'حقيبة سفر جلد', 'حقيبة جلد بني', 'شنطة سفر', 'حقيبة رياضية جلد', 'حقيبة نهاية الأسبوع', 'حقيبة بحزام كتف', 'حقيبة جلد كلاسيكية', 'حقيبة جلد'],
        'meta' => 'حقيبة سفر جلدية بنية بمقبضين وحزام كتف وجيبين أماميين، بتصميم كلاسيكي يناسب رحلات نهاية الأسبوع والسفر.',
        'caption' => "جاهز لرحلة نهاية الأسبوع؟ 🧳\nحقيبتك الجلدية البنية بانتظارك، اطلبها الآن.",
        'hashtags' => ['#حقيبة_جلد', 'حقائب سفر', '#شنط_جلد', '#سفر', '#هدايا'],
    ],
    'en' => [
        'title' => 'Brown Leather Duffel Bag with Front Pockets',
        'short' => '  A brown leather duffel bag with two top handles, a long shoulder strap and two front pockets.',
        'description' => "Carry your essentials in style with this brown leather duffel bag. The warm, lived-in leather, visible stitching, brass studs and metal buckles give it a classic, rugged look.\n\nTwo rolled top handles make it easy to carry by hand, and the long shoulder strap keeps your hands free on the move. Two front pockets with snap flaps keep small things within reach, which makes it a natural pick for weekend trips, the gym or travel.",
        'features' => ['Brown leather with visible stitching', 'Two rolled top handles', 'Long shoulder strap with metal buckles', 'Two front pockets with snap flaps', 'Brass stud and buckle details', 'A sixth feature for testing'],
        'keywords' => ['leather duffel bag', 'brown leather bag', 'leather travel bag', 'weekender bag', 'leather gym bag', 'duffel bag with shoulder strap', 'vintage leather bag', 'leather holdall'],
        'meta' => 'Brown leather duffel bag with two top handles, a long shoulder strap and two front pockets. A classic bag for weekend trips and travel.',
        'caption' => "Packed and ready for the weekend 🧳\nBrown leather, two handles, one strap. Order yours today.",
        'hashtags' => ['#leatherbag', 'duffel bag', '#weekenderbag', '#travelbag', '#leathergoods'],
    ],
    'tr' => [
        'title' => 'Ön Cepli Kahverengi Deri Seyahat Çantası',
        'short' => 'İki üst sapı, uzun omuz askısı ve kapaklı iki ön cebi olan kahverengi deri seyahat çantası.',
        'description' => "Bu kahverengi deri seyahat çantasıyla eşyalarınızı şıklıkla taşıyın. Sıcak tonlu deri, görünür dikişler, pirinç perçinler ve metal tokalar çantaya klasik ve sağlam bir görünüm kazandırıyor.\n\nİki üst sap elde taşımayı kolaylaştırır, uzun omuz askısı ise yolda ellerinizi serbest bırakır. Kapaklı iki ön cep küçük eşyaları el altında tutar; hafta sonu kaçamakları, spor salonu ve seyahatler için doğal bir seçim.",
        'features' => ['Görünür dikişli kahverengi deri', 'İki üst taşıma sapı', 'Metal tokalı uzun omuz askısı', 'Kapaklı iki ön cep', 'Pirinç perçin ve toka detayları', 'Test için altıncı özellik'],
        'keywords' => ['deri seyahat çantası', 'kahverengi deri çanta', 'deri spor çantası', 'hafta sonu çantası', 'omuz askılı çanta', 'vintage deri çanta', 'deri valiz çantası', 'deri el çantası'],
        'meta' => 'İki saplı, omuz askılı ve iki ön cepli kahverengi deri seyahat çantası; hafta sonu kaçamakları ve seyahatler için klasik bir tasarım.',
        'caption' => "Hafta sonuna hazır mısınız? 🧳\nKahverengi deri çantanız sizi bekliyor, hemen sipariş verin.",
        'hashtags' => ['#deriçanta', 'seyahat çantası', '#haftasonu', '#seyahat', '#deri'],
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
