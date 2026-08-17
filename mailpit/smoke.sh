#!/usr/bin/env bash
# Send one message from the TastyIgniter app container and assert Mailpit received it.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="$(cd "$ROOT/../app" && pwd)"
UI_PORT="${MAILPIT_UI_PORT:-8025}"
UI="http://127.0.0.1:${UI_PORT}"
EXPECTED_NAME="El Taconazo Taqueria"
EXPECTED_ADDR="hola@eltaconazo.example"
SUBJECT="mailpit-smoke-$(date +%s)-$$"

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

if ! curl -sf "${UI}/readyz" >/dev/null; then
    fail "Mailpit is down (${UI}/readyz). Run: make up"
fi

if ! docker compose -f "${APP_DIR}/docker-compose.yml" --project-directory "${APP_DIR}" exec -T app true >/dev/null 2>&1; then
    fail "App container is not running. From app/: make up && make seed"
fi

echo "Sending ${SUBJECT} from the app container..."
if ! docker compose -f "${APP_DIR}/docker-compose.yml" --project-directory "${APP_DIR}" \
    exec -T -u www-data -e SMOKE_SUBJECT="${SUBJECT}" app php <<'PHP'
<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$subject = getenv('SMOKE_SUBJECT');
if ($subject === false || $subject === '') {
    fwrite(STDERR, "SMOKE_SUBJECT is empty\n");
    exit(1);
}
Illuminate\Support\Facades\Mail::raw('Mailpit smoke test', function ($message) use ($subject) {
    $message->to('smoke@eltaconazo.example')->subject($subject);
});
echo "sent\n";
PHP
then
    fail "App could not send mail (SMTP error). Is Mailpit on the dilibori network? Seed SMTP settings with: cd app && make seed"
fi

query="$(python3 -c "import urllib.parse,sys; print(urllib.parse.quote('subject:'+sys.argv[1]))" "${SUBJECT}")"
search_url="${UI}/api/v1/search?query=${query}"
found=""
i=0
while [ "$i" -lt 20 ]; do
    payload="$(curl -sf "${search_url}" || true)"
    if [ -n "$payload" ]; then
        found="$(printf '%s' "$payload" | python3 -c "
import json, sys
data = json.load(sys.stdin)
msgs = data.get('messages') or []
count = data.get('messages_count', data.get('total', len(msgs)))
if int(count or 0) < 1 or not msgs:
    sys.exit(0)
m = msgs[0]
frm = m.get('From') or {}
print((frm.get('Name') or '') + '\t' + (frm.get('Address') or '') + '\t' + (m.get('ID') or ''))
")"
        if [ -n "$found" ]; then
            break
        fi
    fi
    i=$((i + 1))
    sleep 1
done

[ -n "$found" ] || fail "Mailpit never saw subject ${SUBJECT}. Check SMTP host mailpit:1025 and that make seed set protocol=smtp."

name="$(printf '%s' "$found" | cut -f1)"
addr="$(printf '%s' "$found" | cut -f2)"
msgid="$(printf '%s' "$found" | cut -f3)"

[ "$name" = "$EXPECTED_NAME" ] || fail "From name is '${name}', expected '${EXPECTED_NAME}'"
[ "$addr" = "$EXPECTED_ADDR" ] || fail "From address is '${addr}', expected '${EXPECTED_ADDR}'"

echo "OK  From: ${name} <${addr}>"
echo "    ${UI}/view/${msgid}"
