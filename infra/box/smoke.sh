#!/bin/bash
# Post-deploy checks for the single restaurant site.
set -euo pipefail

# shellcheck disable=SC1091
set -a
# shellcheck source=/dev/null
. /etc/dilibori/box.env
set +a

DOMAIN="${DOMAIN:?DOMAIN required}"

echo "Waiting for https://${DOMAIN}/ ..."
code=""
for _ in $(seq 1 60); do
  code="$(curl -sS -o /tmp/dilibori-smoke.html -w '%{http_code}' --max-time 20 "https://${DOMAIN}/" || true)"
  if [ "$code" = "200" ]; then
    break
  fi
  sleep 5
done

if [ "$code" != "200" ]; then
  echo "FAIL: ${DOMAIN} returned HTTP ${code}" >&2
  exit 1
fi
if grep -qiE 'rudderanalytics|rudderstack|cdn\.rudderlabs' /tmp/dilibori-smoke.html; then
  echo "FAIL: storefront HTML contains a RudderStack snippet (CDP should be off)" >&2
  exit 1
fi

admin_code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 30 "https://${DOMAIN}/admin" || true)"
if [ "$admin_code" != "200" ] && [ "$admin_code" != "302" ]; then
  echo "FAIL: ${DOMAIN}/admin returned HTTP ${admin_code}" >&2
  exit 1
fi

echo "smoke ok env=${PLATFORM_BOX_ENV} domain=${DOMAIN}"
