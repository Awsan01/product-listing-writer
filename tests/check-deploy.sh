#!/usr/bin/env bash
# Checks a deployed copy of the site: private files are blocked, the page and the health check work,
# and the security headers are set. It never calls the generate endpoint with a photo, so it costs nothing.
#
#   tests/check-deploy.sh https://listing.awsankhaled.com
set -uo pipefail

BASE="${1:?Usage: tests/check-deploy.sh https://your-site}"
BASE="${BASE%/}"
PASS=0
FAIL=0
ok() { echo "  PASS $1"; PASS=$((PASS + 1)); }
bad() { echo "  FAIL $1"; FAIL=$((FAIL + 1)); }
status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "Page and API ($BASE)"
c=$(status "$BASE/")
[[ "$c" == 200 ]] && ok "the page loads (200)" || bad "the page returned $c"
body=$(curl -s "$BASE/api/health.php")
[[ "$body" == '{"ok":true}' ]] && ok "health check: $body" || bad "health check: $body (is private/listing-config.php in place and enabled?)"
c=$(status "$BASE/api/generate.php")
[[ "$c" == 405 ]] && ok "GET on the generate endpoint is refused (405)" || bad "GET on the generate endpoint returned $c"
c=$(status -X POST -H 'Origin: https://other-site.example' "$BASE/api/generate.php")
[[ "$c" == 403 ]] && ok "a POST from another site is refused (403)" || bad "a POST from another site returned $c"

echo "Private paths"
for p in /.git/config /.git/HEAD /.gitignore /.htaccess /README.md /config.example.php /config.local.php \
         /api/lib/config.php /api/lib/ /tests/smoke.sh /tests/mock-anthropic.php /tests/ /listing-storage/ /private/listing-config.php; do
  c=$(status "$BASE$p")
  [[ "$c" == 403 || "$c" == 404 ]] && ok "$p is blocked ($c)" || bad "$p should be blocked, got $c"
done
c=$(status "$BASE/api/")
[[ "$c" == 403 || "$c" == 404 ]] && ok "/api/ shows no folder listing ($c)" || bad "/api/ returned $c"

echo "Headers"
h=$(curl -s -D - -o /dev/null "$BASE/")
for x in 'X-Content-Type-Options: nosniff' 'X-Frame-Options: DENY'; do
  grep -qi "^$x" <<<"$h" && ok "$x" || bad "missing header: $x"
done
h=$(curl -s -D - -o /dev/null "$BASE/api/health.php")
grep -qi '^Cache-Control: no-store' <<<"$h" && ok "API responses send Cache-Control: no-store" || bad "API responses miss Cache-Control: no-store"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
