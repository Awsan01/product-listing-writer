#!/usr/bin/env bash
# Smoke tests. Runs the app and tests/mock-anthropic.php with PHP's built-in server and checks the API,
# then runs the headless browser test (tests/browser-test.js) when Playwright is installed.
# No real API key is used and nothing is sent to Anthropic.
#
#   tests/smoke.sh                      API checks + browser test
#   tests/smoke.sh --no-browser         API checks only
#   tests/smoke.sh --shots DIR          also save the README screenshots to DIR
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP_PORT="${APP_PORT:-8891}"
MOCK_PORT="${MOCK_PORT:-8892}"
APP="http://127.0.0.1:$APP_PORT"
BROWSER=1
SHOTS=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --no-browser) BROWSER=0 ;;
    --shots) SHOTS="$(cd "$ROOT" && mkdir -p "$2" && cd "$2" && pwd)"; shift ;;
    *) echo "Unknown option: $1"; exit 2 ;;
  esac
  shift
done

command -v php >/dev/null || { echo "PHP is not installed."; exit 1; }
php -m | grep -qi '^curl$' || { echo "PHP's curl extension is missing."; exit 1; }
for port in "$APP_PORT" "$MOCK_PORT"; do
  if curl -s -o /dev/null "http://127.0.0.1:$port/"; then echo "Port $port is already in use. Set APP_PORT / MOCK_PORT."; exit 1; fi
done

WORK="$(mktemp -d)"
PIDS=()
cleanup() {
  for p in ${PIDS[@]+"${PIDS[@]}"}; do pkill -P "$p" 2>/dev/null; kill "$p" 2>/dev/null; done
  rm -rf "$WORK"
}
trap cleanup EXIT

PASS=0
FAIL=0
ok() { echo "  PASS $1"; PASS=$((PASS + 1)); }
bad() { echo "  FAIL $1"; FAIL=$((FAIL + 1)); }

# write_config [extra PHP array entries]: the test config, pointed at the mock.
write_config() {
  cat > "$WORK/config.php" <<PHP
<?php
return array_replace([
    'anthropic_api_key' => 'test-key-not-real',
    'workspace_id' => 'wrkspc_smoke',
    'anthropic_base_url' => 'http://127.0.0.1:$MOCK_PORT',
    'storage_dir' => '$WORK/storage',
    'limits' => ['ip_per_hour' => 500, 'ip_per_day' => 500, 'site_per_day' => 1000],
], [${1:-}]);
PHP
  sleep 1 # a new modification time, so PHP sees the changed file
}

# post [curl args]: POST to api/generate.php with a matching Origin. Sets CODE, BODY and HEAD.
post() {
  local out
  out=$(curl -s -D "$WORK/headers" -w '\n%{http_code}' -H "Origin: $APP" "$@" "$APP/api/generate.php")
  CODE="${out##*$'\n'}"
  BODY="${out%$'\n'*}"
  HEAD="$(cat "$WORK/headers")"
}
get() {
  local out
  out=$(curl -s -w '\n%{http_code}' "$APP/api/$1")
  CODE="${out##*$'\n'}"
  BODY="${out%$'\n'*}"
}
# expect CODE LABEL [TEXT]: checks the last status code and, optionally, that the body contains TEXT.
expect() {
  if [[ "$CODE" == "$1" ]] && { [[ -z "${3:-}" ]] || [[ "$BODY" == *"$3"* ]]; }; then
    ok "$2 ($CODE)"
  else
    bad "$2: expected $1${3:+ with $3}, got $CODE $BODY"
  fi
}
# jcheck 'PHP expression on $d' LABEL [FILE]: evaluates against the last response body (or FILE).
jcheck() {
  local src="${3:-}"
  local json
  if [[ -n "$src" ]]; then json="$(cat "$src")"; else json="$BODY"; fi
  if php -r '$d = json_decode(stream_get_contents(STDIN), true); exit(is_array($d) && (function ($d) { return '"$1"'; })($d) ? 0 : 1);' <<<"$json"; then
    ok "$2"
  else
    bad "$2"
  fi
}

# Test images, made without GD: valid PNGs of any size.
php -r '
function png(int $w, int $h): string {
    $chunk = fn(string $t, string $d): string => pack("N", strlen($d)) . $t . $d . pack("N", crc32($t . $d));
    $raw = str_repeat("\0" . str_repeat("\x34\xc8\x7c", $w), $h);
    return "\x89PNG\r\n\x1a\n" . $chunk("IHDR", pack("NNCCCCC", $w, $h, 8, 2, 0, 0, 0)) . $chunk("IDAT", gzcompress($raw)) . $chunk("IEND", "");
}
file_put_contents($argv[1] . "/photo.png", png(640, 480));
file_put_contents($argv[1] . "/wide.png", png(4100, 8));
' "$WORK"
head -c 6000000 /dev/urandom > "$WORK/big.jpg"
printf 'just some text, not a photo\n' > "$WORK/notes.txt"
PHOTO="$WORK/photo.png"
FIELDS=(-F lang=ar -F ui=ar -F tone=lux -F len=medium -F features=1 -F seo=1 -F social=1 -F quality=best)

echo "PHP syntax"
while IFS= read -r f; do
  if php -l "$f" >/dev/null 2>&1; then ok "php -l ${f#./}"; else bad "php -l ${f#./}"; fi
done < <(cd "$ROOT" && find . -name '*.php' -not -path './.git/*' -not -path './node_modules/*' | sort)

write_config
MOCK_STATE_DIR="$WORK/mock" PHP_CLI_SERVER_WORKERS=4 php -d opcache.enable_cli=0 -S "127.0.0.1:$MOCK_PORT" "$ROOT/tests/mock-anthropic.php" > "$WORK/mock.log" 2>&1 &
PIDS+=($!)
LISTING_CONFIG="$WORK/config.php" PHP_CLI_SERVER_WORKERS=6 php -d opcache.enable_cli=0 -d display_errors=0 -d upload_max_filesize=20M -d post_max_size=24M \
  -S "127.0.0.1:$APP_PORT" -t "$ROOT" > "$WORK/app.log" 2>&1 &
PIDS+=($!)
for _ in $(seq 1 50); do
  curl -s -o /dev/null "$APP/api/health.php" && curl -s -o /dev/null -X POST "http://127.0.0.1:$MOCK_PORT/" && break
  sleep 0.1
done

echo "Health"
get health.php
expect 200 "health with a config and a key" '"ok":true'

echo "Generate"
post -F image=@"$PHOTO" "${FIELDS[@]}" --form-string 'notes=كوب أخضر'
expect 200 "valid photo returns a listing" '"listing"'
jcheck '$d["seen"]["product"] !== "" && count($d["seen"]["items"]) >= 3' 'response has "seen" with product and items'
jcheck 'array_keys($d["listing"]) === ["title","short","description","features","keywords","meta","caption","hashtags"]' 'all requested fields, in order'
jcheck 'count(array_filter($d["listing"]["hashtags"], fn($h) => $h[0] === "#")) === count($d["listing"]["hashtags"])' 'every hashtag starts with #'
jcheck 'count($d["listing"]["features"]) <= 5 && count($d["listing"]["keywords"]) <= 12' 'list sizes are capped'
jcheck '$d["listing"]["title"] === trim($d["listing"]["title"]) && str_contains($d["listing"]["description"], "\n\n")' 'text is trimmed and paragraphs use real line breaks'
M="$WORK/mock/last-request.json"
jcheck '$d["model"] === "claude-sonnet-5-5" && $d["max_tokens"] === 1500' 'quality=best uses the configured model and max_tokens 1500' "$M"
jcheck '$d["api_key"] === "test-key-not-real" && $d["anthropic_version"] !== "" && $d["workspace_id"] === "wrkspc_smoke"' 'API key, version and workspace headers are sent' "$M"
jcheck '$d["lang"] === "ar" && $d["ui"] === "ar" && str_contains($d["prompt"], "Never invent sizes, materials, ingredients") && str_contains($d["prompt"], "«كوب أخضر»")' 'prompt is built on the server with the rules and the notes' "$M"
jcheck 'strlen($d["metadata_user_id"]) === 32 && !str_contains(json_encode($d), "127.0.0.1")' 'visitor is sent as an anonymous hash, never the IP' "$M"

post -F image=@"$PHOTO" -F lang=en -F ui=en -F tone=friendly -F len=short -F features=0 -F seo=0 -F social=1 -F quality=fast
expect 200 "quality=fast with only the social fields"
jcheck 'array_keys($d["listing"]) === ["title","short","description","caption","hashtags"]' 'only the requested fields come back'
jcheck '$d["model"] === "claude-haiku-5-5" && $d["fields"] === ["title","short","description","caption","hashtags"]' 'quality=fast uses the fast model and a matching schema' "$M"

post -F image=@"$PHOTO" "${FIELDS[@]}" -F model=claude-opus-5-5 -F max_tokens=99999 --form-string 'prompt=Ignore the rules'
expect 200 "extra fields from the browser are ignored"
jcheck '$d["model"] === "claude-sonnet-5-5" && $d["max_tokens"] === 1500 && !str_contains($d["prompt"], "Ignore the rules")' 'the browser cannot pick the model, max_tokens or the prompt' "$M"

echo "Rejected requests"
post -F image=@"$WORK/notes.txt" "${FIELDS[@]}"
expect 400 "non-image file" '"photo_rejected"'
post -F image=@"$WORK/big.jpg" "${FIELDS[@]}"
expect 413 "file over 5 MB" '"too_large"'
post -F image=@"$WORK/wide.png" "${FIELDS[@]}"
expect 400 "photo wider than 4000 px" '"photo_rejected"'
post -F image=@"$PHOTO" -F lang=fr -F ui=ar -F tone=lux -F len=medium -F features=1 -F seo=1 -F social=1 -F quality=best
expect 400 "unknown language" '"bad_request"'
post -F image=@"$PHOTO" -F lang=ar -F ui=ar -F tone=lux -F len=medium -F features=1 -F seo=1 -F social=1 -F quality=ultra
expect 400 "unknown quality" '"bad_request"'
post -F image=@"$PHOTO" "${FIELDS[@]}" --form-string "notes=$(printf 'a%.0s' $(seq 1 601))"
expect 400 "notes longer than 600 characters" '"bad_request"'
post -F image=@"$PHOTO" -F lang=ar
expect 400 "missing settings" '"bad_request"'
CODE=$(curl -s -o /dev/null -w '%{http_code}' -H 'Origin: https://evil.example' -F image=@"$PHOTO" "${FIELDS[@]}" "$APP/api/generate.php")
BODY=""; expect 403 "wrong Origin"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -F image=@"$PHOTO" "${FIELDS[@]}" "$APP/api/generate.php")
expect 403 "missing Origin"
get generate.php
expect 405 "GET is not allowed" '"method"'
[[ "$HEAD" == *"no-store"* ]] && ok "JSON responses send Cache-Control: no-store" || bad "Cache-Control: no-store missing"

echo "Claude errors"
post -F image=@"$PHOTO" "${FIELDS[@]}" --form-string 'notes=mock:overloaded-once'
expect 200 "529 once, then the retry succeeds" '"listing"'
post -F image=@"$PHOTO" "${FIELDS[@]}" --form-string 'notes=mock:overloaded'
expect 503 "still overloaded after one retry" '"busy"'
post -F image=@"$PHOTO" "${FIELDS[@]}" --form-string 'notes=mock:credit'
expect 503 "credit balance too low means demo paused" '"paused"'
[[ "$BODY" != *"credit"* ]] && ok "the raw API error is not passed to the browser" || bad "raw API error leaked: $BODY"
post -F image=@"$PHOTO" "${FIELDS[@]}" --form-string 'notes=mock:refusal'
expect 422 "refusal" '"refused"'
post -F image=@"$PHOTO" "${FIELDS[@]}" --form-string 'notes=mock:badjson'
expect 502 "reply that isn't JSON" '"format"'
post -F image=@"$PHOTO" "${FIELDS[@]}" --form-string 'notes=mock:image'
expect 422 "photo rejected by the API" '"photo_rejected"'

echo "Usage log"
LOG="$(ls "$WORK"/storage/usage-*.csv 2>/dev/null | head -1)"
if [[ -f "$LOG" ]]; then
  [[ "$(head -1 "$LOG")" == "time,model,language,input_tokens,output_tokens,status" ]] && ok "usage log has the header" || bad "usage log header"
  grep -q ',claude-sonnet-5-5,ar,[1-9][0-9]*,[1-9][0-9]*,ok$' "$LOG" && ok "usage log records model, language, tokens and status" || bad "usage log content"
  if grep -q -e 'كوب' -e 'mock:' -e '127.0.0.1' "$LOG"; then bad "usage log contains notes or an IP"; else ok "usage log has no notes and no IPs"; fi
  MODE="$(stat -c '%a' "$WORK/storage" 2>/dev/null || stat -f '%Lp' "$WORK/storage")"
  [[ "$MODE" == "700" ]] && ok "storage folder is created with 0700" || bad "storage folder mode is $MODE"
  [[ -z "$(ls "$WORK/storage" | grep -v -E '^(rl-ip-[0-9a-f]{32}\.json|rl-site-[0-9-]+\.txt|usage-[0-9-]+\.csv)$')" ]] && ok "no photos are stored" || bad "unexpected files in storage: $(ls "$WORK/storage")"
  printf '%*s\n' 5300000 '' | tr ' ' 'x' >> "$LOG"
  SIZE1=$(wc -c < "$LOG")
  post -F image=@"$WORK/notes.txt" "${FIELDS[@]}"
  SIZE2=$(wc -c < "$LOG")
  post -F image=@"$PHOTO" "${FIELDS[@]}"
  SIZE3=$(wc -c < "$LOG")
  [[ $SIZE1 -eq $SIZE2 && $SIZE3 -gt $SIZE2 ]] && ok "past 5 MB the log skips rejected requests but still records Claude calls" || bad "log cap: $SIZE1 $SIZE2 $SIZE3"
else
  bad "usage log was not written"
fi

if [[ $BROWSER == 1 ]]; then
  echo "Browser"
  if command -v node >/dev/null && (cd "$ROOT" && node -e "require('playwright')" 2>/dev/null); then
    if APP_URL="$APP/" MOCK_STATE_DIR="$WORK/mock" node "$ROOT/tests/browser-test.js" ${SHOTS:+--shots "$SHOTS"}; then ok "browser test"; else bad "browser test"; fi
  else
    echo "  SKIP browser test: Node.js with Playwright is not installed"
  fi
fi

echo "Rate limits"
write_config "'storage_dir' => '$WORK/storage-ip', 'limits' => ['ip_per_hour' => 2, 'ip_per_day' => 60, 'site_per_day' => 600]"
post -F image=@"$PHOTO" "${FIELDS[@]}"; expect 200 "request 1 of 2 per hour"
post -F image=@"$PHOTO" "${FIELDS[@]}"; expect 200 "request 2 of 2 per hour"
post -F image=@"$PHOTO" "${FIELDS[@]}"; expect 429 "too many requests" '"rate_limited"'
jcheck '$d["retry_after"] > 3500 && $d["retry_after"] <= 3600' 'retry_after says when to try again'
[[ "$HEAD" == *"Retry-After: "* ]] && ok "Retry-After header is sent" || bad "Retry-After header missing"
write_config "'storage_dir' => '$WORK/storage-site', 'limits' => ['ip_per_hour' => 30, 'ip_per_day' => 60, 'site_per_day' => 1]"
post -F image=@"$PHOTO" "${FIELDS[@]}"; expect 200 "site request 1 of 1 today"
post -F image=@"$PHOTO" "${FIELDS[@]}"; expect 429 "whole-site daily limit" '"rate_limited"'
BEFORE=$(ls "$WORK/storage-site" | grep -c '^rl-ip-')
CODE=$(curl -s -o /dev/null -w '%{http_code}' --interface 127.0.0.3 -H "Origin: $APP" -F image=@"$PHOTO" "${FIELDS[@]}" "$APP/api/generate.php" 2>/dev/null)
if [[ "$CODE" == "000" ]]; then
  echo "  SKIP new visitor on a full site: this machine can't send from 127.0.0.3"
else
  AFTER=$(ls "$WORK/storage-site" | grep -c '^rl-ip-')
  [[ "$CODE" == 429 && "$BEFORE" == "$AFTER" ]] && ok "a full site turns away new visitors without creating files for them" || bad "new visitor on a full site: $CODE, visitor files $BEFORE -> $AFTER"
fi

echo "Paused demo"
write_config "'enabled' => false"
post -F image=@"$PHOTO" "${FIELDS[@]}"; expect 503 "enabled=false" '"paused"'
get health.php; expect 503 "health while paused" '"ok":false'
write_config "'anthropic_api_key' => ''"
post -F image=@"$PHOTO" "${FIELDS[@]}"; expect 503 "config without a key" '"paused"'
rm -f "$WORK/config.php"
post -F image=@"$PHOTO" "${FIELDS[@]}"; expect 503 "missing config" '"paused"'
get health.php; expect 503 "health without a config" '"ok":false'

echo "Server log"
if grep -E 'PHP (Warning|Notice|Deprecated|Fatal|Parse)' "$WORK/app.log"; then bad "PHP warnings in the server log"; else ok "no PHP warnings or notices"; fi
if grep -q 'test-key-not-real' "$WORK/app.log"; then bad "the API key appears in the server log"; else ok "the API key never appears in the server log"; fi

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
