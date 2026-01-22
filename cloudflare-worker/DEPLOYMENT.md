# Cloudflare Worker Deployment Guide

## Prerequisites

- Cloudflare account with Workers enabled
- Node.js and npm installed
- Wrangler CLI installed (`npm install -g wrangler`)
- Cloudflare API token with Workers permissions

## Configuration

### 1. Worker Configuration

The worker is configured in [`wrangler.toml`](wrangler.toml):

```toml
name = "mbfd-support-ai"
main = "src/index.ts"
compatibility_date = "2024-01-01"

[ai]
binding = "AI"

[vars]
SUPPORT_HUB_URL = "https://support.darleyplex.com"
```

### 2. Secrets Management

**IMPORTANT**: Never commit secrets to version control. All secrets must be set via Wrangler CLI.

#### Set API Secret

Generate a strong random secret (e.g., using OpenSSL):

```bash
openssl rand -base64 32
```

Then set it in Cloudflare:

```bash
cd laravel-app/cloudflare-worker

# Set API secret (use the generated secret from above)
echo "YOUR_GENERATED_SECRET_HERE" | wrangler secret put API_SECRET

# Verify secrets are set (doesn't show values)
wrangler secret list
```

#### Delete a Secret (if needed)

```bash
wrangler secret delete API_SECRET
```

### 3. Laravel Configuration

Update your Laravel `.env` file (do NOT commit this file):

```env
# Cloudflare Worker Integration
CLOUDFLARE_WORKER_URL=https://mbfd-support-ai.pdarleyjr.workers.dev
CLOUDFLARE_WORKER_API_SECRET=YOUR_GENERATED_SECRET_HERE
```

**Note**: Use the SAME secret value that you set via `wrangler secret put`.

## Deployment

### Install Dependencies

```bash
cd laravel-app/cloudflare-worker
npm install
```

### Deploy to Cloudflare

```bash
# Deploy to production
wrangler deploy

# Deploy with a specific environment (if configured)
wrangler deploy --env production
```

### View Deployment Status

```bash
# List all deployed workers
wrangler deployments list

# View worker logs in real-time
wrangler tail

# View worker logs for specific deployment
wrangler tail --format pretty
```

## Testing

### Test Smart Updates Endpoint (Default Route)

```bash
curl -X POST https://mbfd-support-ai.pdarleyjr.workers.dev \
  -H "Content-Type: application/json" \
  -H "X-API-Secret: YOUR_SECRET_HERE"
```

### Test Inventory Chat Endpoint

```bash
curl -X POST https://mbfd-support-ai.pdarleyjr.workers.dev/ai/inventory-chat \
  -H "Content-Type: application/json" \
  -H "X-API-Secret: YOUR_SECRET_HERE" \
  -d '{
    "message": "What items are low in stock?",
    "inventory_context": {
      "low_stock_items": []
    }
  }'
```

## Monitoring

### View Worker Metrics

1. Log in to Cloudflare Dashboard
2. Navigate to Workers & Pages
3. Select `mbfd-support-ai`
4. View metrics, logs, and performance data

### Enable Logpush (Optional)

```bash
wrangler logpush create
```

## Troubleshooting

### Check Worker Configuration

```bash
wrangler whoami
wrangler deployments list
```

### View Logs

```bash
# Real-time logs
wrangler tail

# Filtered logs
wrangler tail --status error
```

### Verify Secrets

```bash
# List secrets (doesn't show values)
wrangler secret list
```

### Common Issues

1. **401 Unauthorized**: Check that API_SECRET is set correctly in both Worker and Laravel
2. **500 Internal Server Error**: Check Worker logs with `wrangler tail`
3. **Rate Limit Exceeded**: Worker has 10 requests/minute rate limit per IP
4. **Cache Issues**: Clear Worker cache by redeploying or wait for 5-minute TTL

## Security Notes

- **Never commit** the `.env` file or any secrets
- Rotate API secrets regularly
- Use different secrets for development and production
- Monitor Worker logs for unauthorized access attempts
- Consider adding IP allowlisting for production

## Integration Flow

```
Admin Dashboard
  ↓
Chat Widget (Livewire - future work)
  ↓
POST /api/admin/ai/inventory-chat
  ↓
Laravel InventoryChatController
  ↓
HTTP → Cloudflare Worker /ai/inventory-chat
  ↓
Workers AI (@cf/meta/llama-2-7b-chat-int8)
  ↓
Returns proposed_actions JSON
  ↓
Laravel validates & enriches
  ↓
UI shows confirmation dialog
  ↓
User confirms → POST /api/admin/ai/inventory-execute
  ↓
Laravel applies stock mutation
```

## Cost Considerations

- Workers AI Free Tier: 10,000 neurons/day
- Current model (`llama-2-7b-chat-int8`): ~50-100 neurons per request
- Estimated capacity: 100-200 free requests per day
- Monitor usage at: https://dash.cloudflare.com

## Additional Resources

- [Cloudflare Workers Documentation](https://developers.cloudflare.com/workers/)
- [Workers AI Documentation](https://developers.cloudflare.com/workers-ai/)
- [Wrangler CLI Documentation](https://developers.cloudflare.com/workers/wrangler/)
