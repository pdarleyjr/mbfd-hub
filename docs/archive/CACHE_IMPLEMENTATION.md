# Cloudflare Cache Implementation

## Overview
This document describes the cache control implementation for https://support.darleyplex.com to ensure updates reflect immediately without "purge everything" or incognito mode workarounds.

## Cloudflare Zone Information
- **Domain**: darleyplex.com
- **Zone ID**: d462d29a7b0f4c6ba0ed9790e0fd8dbb
- **API Token**: Stored in deployment scripts

## Cache-Control Headers

### HTML Entry Points (`/daily/index.html`, `/daily/`)
```
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
Pragma: no-cache
Expires: 0
```
**Purpose**: Force browsers and Cloudflare to always fetch fresh HTML, ensuring users get the latest version immediately.

### Static Assets (`/daily/assets/*`)
```
Cache-Control: public, max-age=31536000, immutable
```
**Purpose**: Cache hashed/versioned assets forever since filenames change with each build.

## Implementation Files

### 1. Routes Configuration: `routes/web.php`
- Sets `no-store` headers on `/daily` and `/daily/{any}` routes
- Excludes static assets from catch-all route to let web server handle them
- Regex pattern prevents Laravel from intercepting asset requests

### 2. Cache Headers Middleware: `app/Http/Middleware/SetCacheHeaders.php`
- Applies appropriate `Cache-Control` headers based on path
- Registered in `bootstrap/app.php` web middleware stack
- Handles both HTML and static asset caching logic

### 3. Cloudflare Purge Script: `scripts/purge-cloudflare.sh`
- Purges specific URLs after deployment:
  - `https://support.darleyplex.com/daily/index.html`
  - `https://support.darleyplex.com/daily/`
  - `https://support.darleyplex.com/__version`
- Uses Cloudflare API with Zone ID
- Includes error handling and success confirmation

## Deployment Workflow

### After Deployment
1. Code is deployed to production
2. Run purge script:
   ```bash
   bash scripts/purge-cloudflare.sh
   ```
3. Verify cache headers:
   ```bash
   # Check HTML (should have no-store)
   curl -I https://support.darleyplex.com/daily/
   
   # Check assets (should have long max-age)
   curl -I https://support.darleyplex.com/daily/assets/index-*.js
   ```

### Integration with Existing Deploy Script
Add to `scripts/deploy.sh` after successful deployment:
```bash
echo "🧹 Purging Cloudflare cache..."
bash scripts/purge-cloudflare.sh
```

## Verification Commands

```bash
# Check Cloudflare Zone ID
curl -X GET "https://api.cloudflare.com/client/v4/zones?name=darleyplex.com" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"

# Check HTML cache headers
curl -I https://support.darleyplex.com/daily/

# Check asset cache headers
curl -I https://support.darleyplex.com/daily/assets/index-BSdMW0b1.js

# Check version endpoint (should also be purged)
curl https://support.darleyplex.com/__version
```

## How It Works

1. **Build Time**: Vite builds assets with content hashes in filenames (e.g., `index-ABC123.js`)
2. **HTML Delivery**: `index.html` has no-store, always fetched fresh
3. **Asset Delivery**: Assets cached forever since filenames change with content
4. **After Deploy**: Purge script clears HTML and version endpoint from Cloudflare
5. **Result**: Users immediately get new HTML which references new hashed assets

## Troubleshooting

### Issue: Changes not reflecting immediately
1. Check if deploy script ran purge successfully
2. Manually run `bash scripts/purge-cloudflare.sh`
3. Verify cache headers with curl commands above
4. Check Cloudflare dashboard for cache settings

### Issue: Assets not loading
1. Verify assets exist in `public/daily/assets/`
2. Check nginx is serving static files (not Laravel)
3. Verify route regex excludes assets: `(?!assets|...)`

### Issue: Purge script fails
1. Verify API token has cache purge permissions
2. Check Zone ID is correct
3. Test API authentication:
   ```bash
   curl -X GET "https://api.cloudflare.com/client/v4/user/tokens/verify" \
     -H "Authorization: Bearer YOUR_TOKEN"
   ```

## Benefits

✅ **No more "Purge Everything"**: Selective purging only what needs to be updated  
✅ **Optimal Performance**: Long-term caching of assets  
✅ **Immediate Updates**: HTML always fresh  
✅ **Better SEO**: Proper cache headers signal freshness to search engines  
✅ **Reduced Bandwidth**: Assets cached by CDN and browsers  

## References

- [Cloudflare Cache Documentation](https://developers.cloudflare.com/cache/)
- [MDN Cache-Control](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control)
- [Vite Build Guide](https://vitejs.dev/guide/build.html)
