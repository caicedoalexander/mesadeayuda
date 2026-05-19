#!/usr/bin/env bash
set -euo pipefail

: "${TAGS_TOKEN:?TAGS_TOKEN is required}"
: "${TICKET_ID:?TICKET_ID is required}"
HOST="${HOST:-http://localhost:8765}"

echo "→ POST /webhooks/tickets/$TICKET_ID/tags (expect 200)"
curl -sS -X POST "$HOST/webhooks/tickets/$TICKET_ID/tags" \
    -H "X-Webhook-Token: $TAGS_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"tag_ids":[1,2,99999],"source":"auto"}' | tee /tmp/tags_smoke_1.json
echo

echo "→ POST repeat same tags (expect skipped_existing)"
curl -sS -X POST "$HOST/webhooks/tickets/$TICKET_ID/tags" \
    -H "X-Webhook-Token: $TAGS_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"tag_ids":[1,2],"source":"auto"}' | tee /tmp/tags_smoke_2.json
echo

echo "→ POST against nonexistent ticket (expect 404)"
curl -sS -o /dev/null -w "%{http_code}\n" -X POST "$HOST/webhooks/tickets/99999999/tags" \
    -H "X-Webhook-Token: $TAGS_TOKEN" \
    -H "Content-Type: application/json" -d '{"tag_ids":[1]}'

echo "→ POST without token (expect 401)"
curl -sS -o /dev/null -w "%{http_code}\n" -X POST "$HOST/webhooks/tickets/$TICKET_ID/tags" \
    -H "Content-Type: application/json" -d '{"tag_ids":[1]}'
