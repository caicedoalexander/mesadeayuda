#!/usr/bin/env bash
set -euo pipefail

: "${WHATSAPP_TOKEN:?WHATSAPP_TOKEN is required}"
HOST="${HOST:-http://localhost:8765}"
MSG_ID="${MSG_ID:-wamid.smoke.$(date +%s)}"

echo "→ POST /webhooks/whatsapp/import (first call, expect 200 created:true)"
curl -sS -X POST "$HOST/webhooks/whatsapp/import" \
    -H "X-Webhook-Token: $WHATSAPP_TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
        \"message_id\": \"$MSG_ID\",
        \"phone_number\": \"+573001234567\",
        \"contact_name\": \"Smoke Test\",
        \"subject\": \"Smoke test ticket\",
        \"description\": \"Generado por whatsapp_import.sh\"
    }" | tee /tmp/whatsapp_smoke_1.json
echo

echo "→ POST repeat (expect 200 created:false)"
curl -sS -X POST "$HOST/webhooks/whatsapp/import" \
    -H "X-Webhook-Token: $WHATSAPP_TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
        \"message_id\": \"$MSG_ID\",
        \"phone_number\": \"+573001234567\",
        \"subject\": \"Smoke test ticket\",
        \"description\": \"Generado por whatsapp_import.sh\"
    }" | tee /tmp/whatsapp_smoke_2.json
echo

echo "→ POST without token (expect 401)"
curl -sS -o /dev/null -w "%{http_code}\n" -X POST "$HOST/webhooks/whatsapp/import" \
    -H "Content-Type: application/json" -d '{}'

echo "→ POST with invalid payload (expect 400)"
curl -sS -o /dev/null -w "%{http_code}\n" -X POST "$HOST/webhooks/whatsapp/import" \
    -H "X-Webhook-Token: $WHATSAPP_TOKEN" \
    -H "Content-Type: application/json" -d '{"message_id":"x"}'
