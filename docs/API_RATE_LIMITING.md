# API Rate Limiting Documentation

## Overview
Rate limiting has been implemented on all public API routes to protect against spam and abuse.

## Configuration

### Public API Routes
**Location:** `routes/api.php` (Line 14)

```php
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('apparatuses', [ApparatusController::class, 'index']);
    Route::get('apparatuses/{apparatus}/checklist', [ApparatusController::class, 'checklist']);
    Route::post('apparatuses/{apparatus}/inspections', [ApparatusController::class, 'storeInspection']);
});
```

**Rate Limit:** 60 requests per minute per IP address

### Protected Routes
Admin routes under `/api/admin` are protected by `auth:sanctum` middleware and are not subject to the same rate limiting.

## How It Works

- The `throttle:60,1` middleware limits requests to **60 requests per minute**
- Rate limiting is applied per IP address
- When the limit is exceeded, the API returns a **429 Too Many Requests** response
- The rate limit counter resets every minute

## Response Headers

When rate limiting is active, Laravel includes the following headers:

- `X-RateLimit-Limit`: Maximum number of requests (60)
- `X-RateLimit-Remaining`: Number of requests remaining
- `Retry-After`: Seconds until the rate limit resets (when limit exceeded)

## Error Response

When rate limit is exceeded:

```json
{
  "message": "Too Many Requests"
}
```

**HTTP Status Code:** 429

## Testing Rate Limiting

To test the rate limiting functionality:

```bash
# Send multiple requests rapidly
for i in {1..65}; do
  curl -I https://support.darleyplex.com/api/public/apparatuses
  echo "Request $i"
done
```

Expected behavior:
- First 60 requests: HTTP 200 OK
- Requests 61-65: HTTP 429 Too Many Requests

## Implementation Date
January 24, 2026

## Notes
- Rate limiting is automatically enabled by Laravel's built-in throttle middleware
- No custom RateLimiter configuration needed for standard use case
- Rate limits are stored in cache (default: file-based cache)
