#!/bin/bash
# Purge specific URLs from Cloudflare cache after deploy
# This ensures updates reflect immediately without requiring "purge everything"
#
# SECURITY: Never hardcode API tokens here. Set CLOUDFLARE_API_TOKEN as an
# environment variable or GitHub Actions secret before running this script.

set -e

# Require the token from the environment — fail loudly if not set
if [ -z "$CLOUDFLARE_API_TOKEN" ]; then
  echo "❌ Error: CLOUDFLARE_API_TOKEN environment variable is not set."
  echo "   Set it via: export CLOUDFLARE_API_TOKEN=<your-token>"
  echo "   Or pass it as a GitHub Actions secret."
  exit 1
fi

if [ -z "$CLOUDFLARE_ZONE_ID" ]; then
  # Fall back to the known darleyplex.com zone ID if not overridden
  CLOUDFLARE_ZONE_ID="d462d29a7b0f4c6ba0ed9790e0fd8dbb"
fi

echo "🧹 Purging Cloudflare cache for specific URLs..."

response=$(curl -s -X POST "https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/purge_cache" \
  -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
  -H "Content-Type: application/json" \
  --data '{
    "files": [
      "https://support.darleyplex.com/daily/index.html",
      "https://support.darleyplex.com/daily/",
      "https://support.darleyplex.com/__version",
      "https://www.mbfdhub.com/daily/index.html",
      "https://www.mbfdhub.com/daily/",
      "https://www.mbfdhub.com/__version"
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
