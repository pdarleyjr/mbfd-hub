# Chatify Real-Time Troubleshooting Guide

## Root Causes Found (2026-02-11)

### 1. Duplicate Alpine.js / Livewire Scripts
**File:** `resources/views/vendor/Chatify/layouts/app.blade.php`  
**Problem:** `app.blade.php` was a full `<!DOCTYPE html>` document. When embedded inside the Filament admin panel (which already loads Alpine.js and Livewire), this caused duplicate script loading, breaking reactivity and WebSocket connections.  
**Fix:** Converted `app.blade.php` to a partial layout (no `<html>`, `<head>`, or `<body>` tags). Removed duplicate Alpine/Livewire includes from `headLinks.blade.php` and `footerLinks.blade.php`.

### 2. Missing JavaScript File Reference
**File:** `resources/views/vendor/Chatify/layouts/footerLinks.blade.php`  
**Problem:** Referenced `chatify.js` which does not exist. The actual file is `public/js/chatify/code.js`.  
**Fix:** Changed the script reference from `chatify.js` to `code.js`.

### 3. Broadcasting Config Using External Host for Server-Side Publishing
**File:** `config/broadcasting.php`  
**Problem:** The Reverb driver's server-side connection was configured to use the external hostname (`support.darleyplex.com`) and port 443 for publishing events. This means Laravel was trying to publish through Cloudflare's proxy instead of directly to the local Reverb process, causing SSL/routing failures.  
**Fix:** Changed the reverb driver's `host` to `127.0.0.1` and `port` to `8080` (Reverb's local container port) with `scheme: http` for server-side publishing. Client-side still connects via `wss://support.darleyplex.com/app/<key>`.

## How to Verify WebSocket Connectivity

1. Open browser DevTools → Network tab → filter by "WS"
2. Navigate to the chat page
3. Look for a connection to `wss://support.darleyplex.com/app/<REVERB_APP_KEY>`
4. Status should be **101 Switching Protocols**
5. You should see periodic ping/pong frames

## Nginx / Network Architecture

- **Client WebSocket:** `wss://support.darleyplex.com/app/<key>` → Cloudflare → Nginx
- **Nginx routes** `/app/` → `127.0.0.1:8090` (host port)
- **Docker mapping:** Host port `8090` → Container port `8080` (Reverb)
- **Server-side publishing:** Laravel → `127.0.0.1:8080` (direct, inside container)

## Rollback Procedure

1. Restore files from backup:
   ```bash
   cp /root/backups/2026-02-11-pre-rescue/app.blade.php /var/www/html/resources/views/vendor/Chatify/layouts/
   cp /root/backups/2026-02-11-pre-rescue/headLinks.blade.php /var/www/html/resources/views/vendor/Chatify/layouts/
   cp /root/backups/2026-02-11-pre-rescue/footerLinks.blade.php /var/www/html/resources/views/vendor/Chatify/layouts/
   cp /root/backups/2026-02-11-pre-rescue/broadcasting.php /var/www/html/config/
   ```
2. Revert `.env` changes if needed
3. Clear config cache:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```
