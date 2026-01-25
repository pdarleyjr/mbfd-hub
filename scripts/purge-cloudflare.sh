#!/bin/bash
# Purge specific URLs from Cloudflare cache after deploy
# This ensures updates reflect immediately without requiring "purge everything"

set -e

CLOUDFLARE_API_TOKEN="U6XGuhQXd5JwIrkuIprFiXA_OvyCqd6ZQeLs_cmZ"
ZONE_ID="d462d29a7b0f4c6ba0ed9790e0fd8dbb"

echo "🧹 Purging Cloudflare cache for specific URLs..."

response=$(curl -s -X POST "https://api.cloudflare.com/client/v4/zones/$ZONE_ID/purge_cache" \
  -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
  -H "Content-Type: application/json" \
  --data '{
    "files": [
      "https://support.darleyplex.com/daily/index.html",
      "https://support.darleyplex.com/daily/",
      "https://support.darleyplex.com/__version"
    ]
  }')

# Check if purge was successful
if echo "$response" | grep -q '"success":true'; then
  echo "✅ Cache purge successful!"
  echo "$response" | grep -o '"id":"[^"]*"'
else
  echo "❌ Cache purge failed!"
  echo "$response"
  exit 1
fi
