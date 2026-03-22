# /daily Route Stability Fix - Diagnostic Report

**Date:** 2026-02-03  
**Engineer:** Debug/Backend Specialist (Kilo Code)  
**Status:** ✅ RESOLVED

---

## Problem Statement

Historical evidence showed `/daily/stations` and other `/daily/*` routes returning 404 errors or blank pages. Investigation was needed to determine if the issue was:
- Route configuration
- Reverse proxy interception
- SPA build/deployment issues
- Cache headers

## Root Cause Analysis

### Investigation Process

1. **Route Configuration Check** ✅
   - Examined [`routes/web.php`](../routes/web.php:9-24)
   - Found correctly configured catch-all route: `Route::get('/daily/{any}', ...)->where('any', '.*')`
   - Proper cache headers set to prevent stale responses
   - No conflicting routes

2. **API Route Check** ✅
   - Examined [`routes/api.php`](../routes/api.php:18-30)
   - API routes correctly scoped to `/api/public/*`
   - No conflicts with SPA routes

3. **SPA Build Artifacts Check** ❌ **ISSUE FOUND**
   - Examined [`public/daily/index.html`](../public/daily/index.html)
   - Found asset hash mismatch

### The Problem: Asset Hash Mismatch

**Before Fix:**
```html
<!-- index.html referenced: -->
<script type="module" crossorigin src="/daily/assets/index-749e9e8c.js"></script>
<link rel="stylesheet" href="/daily/assets/index-686ef43c.css">
```

**Actual files in public/daily/assets:**
```
index-4c2b6792.js ✅
index-ed2b4c34.css ✅
```

**Impact:**
1. Laravel correctly serves [`public/daily/index.html`](../public/daily/index.html) for all `/daily/*` routes ✅
2. Browser attempts to load JS/CSS with wrong hashes → 404 errors ❌
3. React application never initializes ❌
4. User sees blank page or error ❌

### Why This Happened

This is a **stale build artifact issue**, likely caused by:
- Incomplete deployment where assets were rebuilt but `index.html` wasn't updated
- Build process interrupted mid-deployment
- Manual file copy instead of proper build process

---

## The Fix

### Solution: Rebuild SPA with Correct Asset Hashes

**Command executed:**
```bash
cd resources/js/daily-checkout
npm run build
```

**Build Output:**
```
✓ 329 modules transformed
manifest.json              1.04 kB │ gzip:  0.43 kB
index.html                 1.21 kB │ gzip:  0.55 kB
assets/index-abafe100.css 25.64 kB │ gzip:  5.08 kB
assets/index-dab661b3.js 328.81 kB │ gzip: 97.10 kB
✓ built in 15.56s
```

**After Fix:**
```html
<!-- index.html now correctly references: -->
<script type="module" crossorigin src="/daily/assets/index-dab661b3.js"></script>
<link rel="stylesheet" href="/daily/assets/index-abafe100.css">
```

**Verified files exist:**
```
public/daily/assets/index-dab661b3.js ✅
public/daily/assets/index-abafe100.css ✅
public/daily/assets/index-dab661b3.js.map ✅
```

---

## What Was NOT Broken

### Laravel Route Configuration ✅

The routes were already correctly configured:

```php
// Serve SPA at /daily
Route::get('/daily', function () {
    $response = response()->file(public_path('daily/index.html'));
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
});

// Catch-all for client-side routing
Route::get('/daily/{any}', function () {
    $response = response()->file(public_path('daily/index.html'));
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
})->where('any', '.*');
```

**Key points:**
- ✅ Proper catch-all pattern with `->where('any', '.*')`
- ✅ Aggressive cache headers prevent stale responses
- ✅ No route ordering issues (no other routes intercepting)

### React Router Configuration ✅

The client-side routing was properly configured:

```tsx
<Router basename="/daily">
  <Routes>
    <Route path="/" element={<ApparatusList />} />
    <Route path="/stations" element={<StationListPage />} />
    <Route path="/forms-hub" element={<FormsHub />} />
    {/* ... more routes */}
  </Routes>
</Router>
```

### Vite Build Configuration ✅

The build configuration was correct:

```js
export default defineConfig({
  base: '/daily/',
  build: {
    outDir: '../../../public/daily',
    emptyOutDir: true,
    sourcemap: true,
  },
})
```

### .htaccess Configuration ✅

Standard Laravel configuration, no issues with rewrite rules.

---

## Verification Steps

### Local Testing

```bash
# Test main SPA route
curl -I http://localhost/daily
# Expected: HTTP 200 with HTML content

# Test client-side routes (should serve same HTML)
curl -I http://localhost/daily/stations
curl -I http://localhost/daily/forms-hub
# Expected: HTTP 200 with HTML content

# Verify assets load
curl -I http://localhost/daily/assets/index-dab661b3.js
curl -I http://localhost/daily/assets/index-abafe100.css
# Expected: HTTP 200 for both
```

### VPS Deployment

**After deploying the fix:**

1. Deploy new build artifacts:
```bash
# On VPS, pull latest changes
git pull origin main

# Or build directly on VPS
cd resources/js/daily-checkout
npm run build
```

2. Clear any reverse proxy caches (Caddy/Nginx):
```bash
# Restart web server to clear cache
sudo systemctl restart caddy  # or nginx
```

3. Test in browser:
   - Navigate to `https://support.darleyplex.com/daily`
   - Should load Forms Hub
   - Navigate to `/daily/stations`
   - Should load Station List
   - Check browser console - no 404 errors for assets

---

## Prevention Strategy

### CI/CD Integration

To prevent this issue in the future, the deployment process should:

1. **Always rebuild SPA as part of deployment:**
```bash
# In deployment script
cd resources/js/daily-checkout
npm ci  # Clean install
npm run build  # Fresh build with new hashes
```

2. **Verify assets match before deploying:**
```bash
# Extract referenced hashes from index.html
grep -o 'index-[a-f0-9]*.js' public/daily/index.html

# Verify files exist
ls public/daily/assets/
```

3. **Atomic deployments:**
   - Build entire `/daily` directory locally or in CI
   - Deploy as single atomic operation
   - Don't manually copy individual files

### Monitoring

Add health check endpoint in Laravel:

```php
Route::get('/__daily-health', function() {
    $html = file_get_contents(public_path('daily/index.html'));
    preg_match('/index-([a-f0-9]+)\.js/', $html, $matches);
    $jsHash = $matches[1] ?? 'not-found';
    
    $jsExists = file_exists(public_path("daily/assets/index-{$jsHash}.js"));
    
    return response()->json([
        'status' => $jsExists ? 'healthy' : 'unhealthy',
        'js_hash' => $jsHash,
        'js_exists' => $jsExists,
    ]);
});
```

---

## Summary

### What Was Fixed
- ✅ Rebuilt daily-checkout SPA with matching asset hashes
- ✅ Verified index.html references correct asset files
- ✅ All assets now load successfully

### What Was Already Working
- ✅ Laravel route configuration with proper catch-all
- ✅ React Router with correct basename
- ✅ Vite build configuration
- ✅ API route scoping

### Deployment Notes
- The routes were **never the problem** - they were correctly configured from the start
- The issue was purely a **build artifact synchronization problem**
- Future deployments should always use `npm run build` to ensure consistency
- Consider adding asset validation to the deployment pipeline

### Testing Checklist
- [✅] `/daily` loads and displays SPA
- [✅] `/daily/stations` loads Station List (client-side route)
- [✅] `/daily/forms-hub` loads Forms Hub (client-side route)
- [✅] All JS/CSS assets load without 404 errors
- [✅] React app initializes and renders correctly
- [ ] Verify on VPS after deployment
- [ ] Test with reverse proxy (Caddy/Nginx)

---

## Related Files

- [`routes/web.php`](../routes/web.php) - Laravel SPA routing
- [`resources/js/daily-checkout/vite.config.js`](../resources/js/daily-checkout/vite.config.js) - Vite build config
- [`resources/js/daily-checkout/src/App.tsx`](../resources/js/daily-checkout/src/App.tsx) - React Router setup
- [`public/daily/index.html`](../public/daily/index.html) - SPA entry point
- [`public/.htaccess`](../public/.htaccess) - Apache rewrite rules
