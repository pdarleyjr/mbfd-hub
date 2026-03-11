# AI AGENT ERROR LOG & PREVENTION GUIDE
## MBFD Hub — Mandatory Pre-Work Reading

> ⚠️ **CRITICAL MANDATE**: Every AI agent working on this codebase MUST read this entire file BEFORE making any changes. Failure to read this file WILL result in breaking existing functionality.

**Last Updated**: 2026-03-10  
**Project**: MBFD Hub (Laravel 11, Filament v3, VPS at 145.223.73.170)

---

## HOW TO USE THIS FILE

1. **Read every error entry** before starting any task
2. **Add new entries** when you encounter and fix errors
3. **Reference existing entries** when making similar changes to avoid repeat mistakes
4. **Document the fix** completely — include file paths, code before/after, and root cause

---

## ⚠️ ERROR LOG

---

### ERROR-001: Filament v3 Component Compatibility — `x-filament::card.heading` / `x-filament::card.content`

**Date**: 2026-03-05  
**Severity**: 🔴 CRITICAL — causes 500 error, crashes blade cache  
**File(s) Affected**: Any `.blade.php` in `resources/views/filament*/**`

**Symptom**:
```
InvalidArgumentException: Unable to locate a class or view for component [filament::card.heading]
```
Blade templates cache fails with this error. Page returns 500.

**Root Cause**: 
`x-filament::card.heading` and `x-filament::card.content` are NOT valid Filament v3 components. They were used in some pre-existing blade files but Filament v3 removed the `card` sub-components. The `filament-workgroup/pages/session-results.blade.php` file (the alternate view) still uses these — **do NOT run `php artisan view:cache` if this file is included in compilation**.

**Fix Applied**:
Replace `x-filament::card.heading` / `x-filament::card.content` wrappers with plain HTML:
```html
<!-- WRONG: -->
<x-filament::card>
    <x-filament::card.heading>Title</x-filament::card.heading>
    <x-filament::card.content>Content</x-filament::card.content>
</x-filament::card>

<!-- CORRECT (Filament v3): -->
<div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6 dark:bg-gray-900">
    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Title</h3>
    <div>Content</div>
</div>
<!-- OR use x-filament::section properly -->
```

**Prevention**: 
- Never use `x-filament::card.heading` or `x-filament::card.content` in any new blade file
- When editing existing blade files that use these components, replace them immediately
- `resources/views/filament-workgroup/pages/session-results.blade.php` still has these — this file is NOT actively used by the app (the active file is `resources/views/filament/workgroup/pages/session-results.blade.php`) but should be cleaned up

---

### ERROR-002: SCP File Transfer — Path with Spaces Causes Silent Failure

**Date**: 2026-03-05  
**Severity**: 🟡 MEDIUM — files seemingly transfer but arrive empty or not at all  
**File(s) Affected**: Any SCP operation from `C:\Users\Peter Darley\Desktop\Support Services\`

**Symptom**:
SCP returns exit code 0 (success) but the file is not found on VPS. Files deployed at relative paths like `'app\services\WorkgroupAIService.php'` fail silently because PowerShell parses the `'...'` path incorrectly when the working directory has spaces.

**Root Cause**:
The workspace directory is `C:\Users\Peter Darley\Desktop\Support Services` — it contains a space. When running `pwsh -Command "scp ... 'relative/path.php' ..."', the space causes path resolution issues.

**Fix Applied**:
Use FULL absolute paths in SCP commands:
```powershell
# WRONG (may fail silently with spaces in cwd):
scp -i '...\id_ed25519' 'app\Services\Workgroup\WorkgroupAIService.php' root@vps:/path

# CORRECT (use full absolute path):
scp -i '...\id_ed25519' 'C:\Users\Peter Darley\Desktop\Support Services\app\Services\Workgroup\WorkgroupAIService.php' root@vps:/path
```

**Detection Method**:
After transferring, always verify with: `ssh vps 'wc -l /path/to/file'` and compare with local file line count.

**Prevention**:
When deploying files via SCP, always use full absolute paths. Consider creating a deployment PowerShell script (like `deploy_ai_files.ps1` in this session) that uses variables and runs each SCP individually with a delay.

---

### ERROR-003: Overwriting Critical PHP Files That Had Previous Bug Fixes

**Date**: 2026-03-05  
**Severity**: 🔴 CRITICAL — reverts previously fixed bugs, may cause 403/500 errors  
**File(s) Affected**: `app/Filament/Workgroup/Pages/SessionResultsPage.php`

**Symptom**:
Session Results page becomes inaccessible to regular workgroup members (only admin/facilitator can access) — returning either 403 (Forbidden) or appearing blank to members.

**Root Cause**:
The local workspace `SessionResultsPage.php` had a `canAccess()` method that only allowed `['admin', 'facilitator']` roles. When this was SCP'd to the VPS, it overwrote the fixed version which allowed ALL active workgroup members to access the page.

**Previous VPS Fix (2026-03-04)**:
From `CLAUDE.md` and the discovery report:
> Session Results Page Fix (2026-03-04): Now accessible to ALL workgroup members (read-only)

**Fix Applied**:
Updated `canAccess()` to allow all active workgroup members:
```php
public static function canAccess(): bool
{
    $user = Auth::user();
    if (!$user) return false;
    
    // Super admins and admins always have access
    if ($user->hasRole(['super_admin', 'admin', 'logistics_admin'])) {
        return true;
    }
    
    // ALL active workgroup members can view results (read-only)
    $member = WorkgroupMember::where('user_id', $user->id)
        ->where('is_active', true)
        ->first();
    return $member !== null;
}
```

**Prevention**:
1. **ALWAYS read `CLAUDE.md` and `MBFD_HUB_DISCOVERY_REPORT_2026-02-12.md` first** to understand what bugs have been fixed
2. **CRITICAL RULE**: Before overwriting ANY PHP file that contains `canAccess()`, `mount()`, or role-checking logic, compare with the VPS version:
   ```bash
   ssh vps 'cat /root/mbfd-hub/path/to/file.php | grep -A 20 canAccess'
   ```
3. Never blindly overwrite files — always check what changes the file contains vs. the VPS

---

### ERROR-004: Similarity Threshold Too High — Chatbot Returns Empty Context

**Date**: 2026-03-05  
**Severity**: 🟡 MEDIUM — chatbot appears to not know anything, poor user experience  
**File(s) Affected**: `cloudflare-worker/src/index.ts`

**Symptom**:
Users report chatbot says "I don't have that information in my current documents" for questions it should be able to answer. The chatbot seems broken but technically it's working — the vector search returns results but they're all filtered out by the threshold.

**Root Cause**:
Added a 0.4 similarity score threshold to filter low-relevance context:
```typescript
const relevantMatches = vectorResults.matches.filter(
    (m: any) => (m.score || 0) >= 0.4  // TOO STRICT
);
```
Cosine similarity scores for the `mbfd-rag-index` rarely exceed 0.4 for the SOG/manual documents due to their technical language — most similar matches score 0.2-0.35. Setting threshold to 0.4 effectively filters out all results.

**Fix Applied**:
Lowered threshold to 0.2:
```typescript
const relevantMatches = vectorResults.matches.filter(
    (m: any) => (m.score || 0) >= 0.2  // BETTER
);
```
The LLM system prompt already handles the case of irrelevant context — it tells the AI to reply "I don't have that information" when context doesn't contain the answer. The threshold exists only to prevent completely unrelated content from being used.

**Prevention**:
- The threshold for the mbfd-rag-index should stay at 0.2 or lower
- For `workgroup-specs` index, 0.35 is reasonable (product specs are more specific)
- Always test changes to the chatbot with ACTUAL user queries before deployment

---

### ERROR-005: getHeaderWidgets() vs getWidgets() in Filament v3 Page Views

**Date**: 2026-03-05  
**Severity**: 🟡 MEDIUM — widgets don't render, page looks empty  
**File(s) Affected**: `resources/views/filament/workgroup/pages/session-results.blade.php`

**Symptom**:
Session results page loads without errors but shows no rankings, finalists, or feedback sections.

**Root Cause**:
Used `$this->getHeaderWidgets()` in the blade template:
```html
<!-- WRONG: getHeaderWidgets() is a different method than getWidgets() -->
@if($this->getHeaderWidgets())
    <x-filament-widgets::widgets :widgets="$this->getHeaderWidgets()" ... />
@endif
```
The `SessionResultsPage::getWidgets()` returns `[FinalistsWidget, CategoryRankingsWidget, NonRankableFeedbackWidget]`. But `getHeaderWidgets()` returns a separate array (which may be empty). These are not the same method in Filament v3.

**Fix Applied**:
Use `getWidgets()` instead:
```html
<!-- CORRECT: -->
<x-filament-widgets::widgets
    :widgets="$this->getWidgets()"
    :columns="$this->getColumns()"
/>
```

**Prevention**:
- `getWidgets()` is the main method for page widgets in Filament v3 custom pages
- `getHeaderWidgets()` and `getFooterWidgets()` are separate arrays for dedicated regions
- Always check the Filament v3 docs before using widget-rendering methods

---

### ERROR-006: Vision Worker Model Requires ToS Acceptance — Error 5016

**Date**: 2026-03-08  
**Severity**: 🔴 CRITICAL — AI pipeline never triggers, form fields never populate  
**File(s) Affected**: `cloudflare-worker/vision-agent/src/index.ts` (was missing entirely)

**Symptom**:
When a user uploads a photo in the Equipment Intake page, the "Analyze Photos with AI" button appears and is clicked, but the Vision Worker returns:
```json
{"error":"5016: Prior to using this model, you must submit the prompt 'agree'..."}
```
Form fields are never populated. No submission to Snipe-IT occurs.

**Root Cause**:
Two compound issues:
1. **Worker source missing from repo**: `cloudflare-worker/vision-agent/` only contained a `package-lock.json`. The Worker was deployed to Cloudflare by a previous agent without committing the source code.
2. **Model ToS not accepted**: The deployed Worker used `@cf/meta/llama-3.2-11b-vision-instruct` which requires an initial `{ "prompt": "agree" }` request to accept the Meta Community License. This was never done.
3. **Wrong model name in Worker code**: Previous attempt used `@cf/llava-1.5-7b-hf` but the correct name is `@cf/llava-hf/llava-1.5-7b-hf`.

**Fix Applied**:
1. Created `cloudflare-worker/vision-agent/wrangler.toml`, `src/index.ts`, and `package.json`
2. Accepted llama-3.2-11b-vision-instruct ToS via:
   ```bash
   curl -X POST 'https://api.cloudflare.com/client/v4/accounts/265122b6d6f29457b0ca950c55f3ac6e/ai/run/@cf/meta/llama-3.2-11b-vision-instruct' \
     -H 'Authorization: Bearer <CF_TOKEN>' \
     -H 'Content-Type: application/json' \
     -d '{"prompt":"agree"}'
   ```
   Response: `"Thank you for agreeing to this model's terms. You may now use the model."`
3. Worker rewritten to use `@cf/llava-hf/llava-1.5-7b-hf` as primary (no ToS gate) with `@cf/meta/llama-3.2-11b-vision-instruct` as fallback
4. Deployed via `CLOUDFLARE_API_TOKEN=<token> npx wrangler deploy` from VPS

**Cloudflare AI Vision Models Available (as of 2026-03-08)**:
```
@cf/llava-hf/llava-1.5-7b-hf   ← use this, no ToS gate
  Input: { image: number[], prompt: string, max_tokens: number }
  
@cf/meta/llama-3.2-11b-vision-instruct  ← high quality, ToS accepted
  Input: { messages: [{ role: "user", content: [{ type: "text", text: "..." }, { type: "image_url", image_url: { url: "data:image/jpeg;base64,..." } }] }] }
  
@cf/unum/uform-gen2-qwen-500m  ← small, fast
```

**Prevention**:
1. **NEVER deploy a Cloudflare Worker without committing the source code first**
2. Before using any Cloudflare AI model, check the ToS requirements at `https://developers.cloudflare.com/workers-ai/models/`
3. For vision models, always test with `curl -X POST /health` first to confirm connectivity
4. Always commit Worker source code in the repo under `cloudflare-worker/<worker-name>/src/`

---

### ERROR-007: `mbfd-hub-app` Container Crash — PHP Version Mismatch

**Date**: 2026-03-08  
**Severity**: 🟡 MEDIUM — container restart loop, but does NOT affect production  
**File(s) Affected**: Production Docker compose (non-Sail container), `composer.json`

**Symptom**:
```
docker ps shows: mbfd-hub-app   Restarting (255)
docker logs: Fatal error: Your Composer dependencies require a PHP version ">= 8.4.0". You are running 8.3.30.
```

**Root Cause**:
The `mbfd-hub-app` container (production non-Sail image) runs PHP 8.3. However, `composer.json` or a dependency added a `require-php: ^8.4` constraint. The **production serving is actually done by `mbfd-hub-laravel.test-1`** (Sail image with PHP 8.5) so this crash does NOT affect users.

**Diagnosis**:
- `mbfd-hub-laravel.test-1` uses `sail-8.5/app` image — this is what serves `www.mbfdhub.com`
- `mbfd-hub-app` is a different container from a different compose file — NOT serving traffic
- Site returns 200 correctly

**Resolution needed** (not done yet):
Either update the `mbfd-hub-app` Dockerfile to PHP 8.4+, or remove the PHP 8.4 constraint from `composer.json` if it was added by mistake. Low priority since it doesn't affect traffic.

---

### ERROR-018: Filament v3 Widgets as Livewire Children — Stale State on Parent Property Change
**Date**: 2026-03-08
**Status**: ✅ RESOLVED (2026-03-11) — Workgroup Evaluation Modernization Phase 2 replaced all Livewire widgets with inline data via `getViewData()` and plain Blade HTML rendering. No Livewire child components remain on the Session Results or Admin Dashboard pages.
**Root cause**: Filament v3 widgets are separate Livewire components. Passing new session props via make() sets INITIAL state only. When parent page re-renders after wire:click, widgets may NOT remount — they keep old session data.
**Wrong approach**: wire:key on HTML div does NOT force widget remounting
**Correct fix**: Remove Livewire widgets from pages with reactive switching. Compute all data in getViewData() (always fresh) and render as plain HTML in blade.
**Commits**: d167eb45, 77067086 (Phase 2 modernization)

---

### ERROR-019: `pxlrbt/filament-excel` Not Installed — ApparatusResource 500

**Date**: 2026-03-09  
**Severity**: 🔴 CRITICAL — `/admin/apparatuses` returns HTTP 500 for ALL users  
**File(s) Affected**: `app/Filament/Resources/ApparatusResource.php`

**Symptom**:
```
production.ERROR: Class "pxlrbt\FilamentExcel\Actions\Tables\ExportAction" not found
  at /var/www/html/app/Filament/Resources/ApparatusResource.php:259
```
Every page load of `/admin/apparatuses` throws this fatal error.

**Root Cause**:
`ApparatusResource.php` imported and used three classes from the `pxlrbt/filament-excel` package (`ExportAction`, `ExportBulkAction`, `ExcelExport`) but this package is **NOT listed in `composer.json`** and is not installed. The file was added to the repo referencing this package without running `composer require pxlrbt/filament-excel`.

**Fix Applied**:
Removed the three FilamentExcel imports (lines 16–18) and replaced the `ExportAction` header action and `ExportBulkAction` bulk action with native Filament actions. The Sync to Google Sheet header action was retained:
```php
// REMOVED:
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

// headerActions: ExportAction::make('export')... → REMOVED
// bulkActions: ExportBulkAction::make(...)... → REMOVED, replaced with DeleteBulkAction only
```

**Prevention**:
1. **NEVER add `use` imports for packages that are not in `composer.json`**
2. Before adding Excel/export functionality to any Filament resource, verify `composer.json` contains `pxlrbt/filament-excel` or another export package
3. To add export properly: `composer require pxlrbt/filament-excel` first, then add the imports
4. After editing any Resource file, run `php artisan route:list --path=admin/<resource>` on VPS to confirm no class-not-found errors during route resolution

**Commit**: `52136fe8`

---

### ERROR-020: Google Sheets Apparatus Sync — Three Stacked Failures

**Date**: 2026-03-09  
**Severity**: 🔴 CRITICAL — "Sync to Google Sheet" button silently queues jobs that never run  
**File(s) Affected**: `composer.json`, `.env` (on VPS), `docker-compose` (no secrets mount)

**Symptoms**:
- Clicking "Sync to Google Sheet" button shows success notification but sheet never updates
- Jobs sit in `jobs` table with `attempts = 0` indefinitely
- No errors in `failed_jobs` table because jobs never start

**Root Causes (3 stacked)**:

1. **`google/apiclient` package not installed**  
   `composer.json` did not include `google/apiclient`. The `ApparatusSheetSyncService` imports `Google\Client` but the package was never added to the project.
   
2. **Service account JSON not mounted into container**  
   `.env` sets `GOOGLE_SERVICE_ACCOUNT_JSON_PATH=/run/secrets/google_service_account.json` but the Sail container only has `/root/mbfd-hub:/var/www/html` mounted — no `/run/secrets` volume. The file lives at `/root/secrets/google_service_account.json` on the host.

3. **No queue worker running**  
   `QUEUE_CONNECTION=database` but no `php artisan queue:work` process runs in the container. Jobs are dispatched but never consumed.

**Fixes Applied**:
1. Added `google/apiclient: ^2.15` to `composer.json` and ran `composer require` in container (committed as `5cf59c76`)
2. Copied service account key: `cp /root/secrets/google_service_account.json /root/mbfd-hub/storage/app/google_service_account.json`
3. Updated `.env`: `GOOGLE_SERVICE_ACCOUNT_JSON_PATH=/var/www/html/storage/app/google_service_account.json`
4. Started queue worker: `docker exec -d mbfd-hub-laravel.test-1 bash -c 'nohup php artisan queue:work --sleep=3 --tries=3 --max-time=3600 >> /tmp/queue-worker.log 2>&1 &'`
5. Added cron watchdog on VPS host: `*/5 * * * * /root/restart-queue-worker.sh` — restarts worker if it stops

**Verified**: Log confirmed `[ApparatusSheetSync] Sync complete — wrote 26 rows to Equipment Maintenance`

**Prevention**:
1. **ALWAYS include `google/apiclient` in `composer.json` when using Google APIs**
2. The service account JSON must be at `/var/www/html/storage/app/google_service_account.json` (within the mounted volume) — NOT `/run/secrets/` which is not mounted
3. After VPS reboots or container restarts, run `/root/restart-queue-worker.sh` manually or wait for the cron to fire
4. To check queue health: `docker exec mbfd-hub-laravel.test-1 pgrep -f queue:work` — should return a PID

---

### ERROR-021: Chatify NS_BINDING_ABORTED — Missing `enabledTransports` Prevents SockJS Fallback Blocking

**Date**: 2026-03-09  
**Severity**: 🔴 CRITICAL — WebSocket never connects, Chatify real-time messaging is broken  
**File(s) Affected**: `config/chatify.php`, `public/js/chatify/code.js`

**Symptoms**:
```
NS_BINDING_ABORTED on wss://www.mbfdhub.com/app/...
Browser then falls back to: sockjs-mt1.pusher.com (external, NOT your server)
Real-time messaging fails; online presence doesn't update
```

**Root Cause**:
Chatify's Pusher JS client, without `enabledTransports: ['ws', 'wss']`, follows Pusher's default transport cascade:
1. Native WebSocket → tries `wss://www.mbfdhub.com/app/...` (your Reverb server) → if the connection aborts or has any issue, Pusher JS falls through to...
2. SockJS → tries `https://sockjs-mt1.pusher.com` (Pusher's CLOUD server) → fails because your app isn't on Pusher cloud

Setting `enabledTransports: ['ws', 'wss']` forces Pusher JS to use ONLY native WebSockets and never fall back to SockJS/Pusher cloud.

**Fix Applied**:
1. Added `'enabledTransports' => ['ws', 'wss']` to `config/chatify.php` pusher options array
2. Added `enabledTransports: chatify.pusher.options.enabledTransports || ['ws', 'wss']` to `new Pusher(...)` constructor in `public/js/chatify/code.js`

```php
// config/chatify.php — options array
'options' => [
    'cluster' => env('REVERB_APP_CLUSTER', 'mt1'),
    'host' => env('REVERB_HOST', '127.0.0.1'),
    'port' => env('REVERB_PORT', 8080),
    'scheme' => env('REVERB_SCHEME', 'https'),
    'encrypted' => true,
    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
    'enabledTransports' => ['ws', 'wss'],  // ← CRITICAL: prevents SockJS fallback
],
```

```js
// public/js/chatify/code.js
const pusher = new Pusher(chatify.pusher.key, {
    wsHost: chatify.pusher.options.host,
    wsPort: chatify.pusher.options.port,
    wssPort: chatify.pusher.options.port,
    forceTLS: chatify.pusher.options.useTLS,
    enabledTransports: chatify.pusher.options.enabledTransports || ['ws', 'wss'],  // ← ADD THIS
    authEndpoint: chatify.pusherAuthEndpoint,
    // ...
});
```

**VPS Production Env (`.env`)**:
```
REVERB_HOST=www.mbfdhub.com
REVERB_PORT=443
REVERB_SCHEME=https
```
With these values, Pusher JS directs WebSocket traffic to `wss://www.mbfdhub.com/app/...` on port 443 — which routes through Cloudflare Tunnel → Reverb inside the container on port 8080.

**Prevention**:
- Whenever configuring Chatify with a self-hosted Reverb (or any non-Pusher WebSocket server), ALWAYS add `enabledTransports: ['ws', 'wss']` to prevent SockJS fallback
- After deploying chatify/reverb config changes, always clear ALL caches (config, view, route, app caches)

---

### ERROR-022: Reverb WebSocket Server Not Running in Container After Restart

**Date**: 2026-03-09  
**Severity**: 🔴 CRITICAL — all WebSocket features fail (Chatify, broadcasting, presence channels)  
**File(s) Affected**: `vendor/laravel/sail/runtimes/8.5/supervisord.conf` (in Sail Docker image)

**Symptom**:
```
wss://www.mbfdhub.com/app/... → NS_BINDING_ABORTED immediately
docker exec mbfd-hub-laravel.test-1 ps aux → no reverb process found
/tmp/reverb.log missing or empty
```

**Root Cause**:
The Laravel Sail Docker image (`sail-8.5/app`) uses supervisord to manage processes. The default `supervisord.conf` inside the image only configures the PHP web server process (`[program:php]`). There is **no `[program:reverb]`** section. On container restart, Reverb is not started automatically — it must be added to the supervisor config and the image rebuilt.

**Fix Applied**:
1. Added `[program:reverb]` to `vendor/laravel/sail/runtimes/8.5/supervisord.conf` (which is baked into the Docker image on build)
2. Rebuilt the Docker image: `docker compose build laravel.test --no-cache`
3. Restarted container using new image: `docker-compose up -d laravel.test`

```ini
[program:reverb]
command=/usr/bin/php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction
user=sail
environment=LARAVEL_SAIL="1"
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
autostart=true
autorestart=true
```

**Verification**:
```bash
docker exec mbfd-hub-laravel.test-1 ps aux | grep reverb
# Should show: sail  17  ... php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction
```

**Key Architecture Facts**:
- There is NO separate `reverb` container in the current compose.yaml — Reverb runs INSIDE `laravel.test`  
- Container name: `mbfd-hub-laravel.test-1`
- Reverb listens on `0.0.0.0:8080` inside the container
- Host maps `127.0.0.1:8090` → container port `8080`  
- Cloudflare Tunnel proxies `wss://www.mbfdhub.com` → `http://localhost:8090`
- DO NOT confuse the CLAUDE.md Docker Services table (which lists a `reverb` service) — that table is inaccurate; there is NO separate reverb container

**Prevention**:
- Any time the Docker image is rebuilt or the container is freshly created, verify Reverb is running: `docker exec mbfd-hub-laravel.test-1 ps aux | grep reverb`
- The `vendor/laravel/sail/runtimes/8.5/supervisord.conf` file in the local workspace (the `sail-supervisord.conf` temporary file shows the correct content) must be kept current
- A reference copy is at `sail-supervisord.conf` in the workspace root

---

### ERROR-023: Chatify "No internet access" Despite Successful WebSocket Connection

**Date**: 2026-03-09  
**Severity**: 🔴 CRITICAL — **FIXED** (2026-03-09 evening)  
**File(s) Affected**: `config/chatify.php`, `config/broadcasting.php`, `app/Providers/AppServiceProvider.php`, `app/Services/ChatifyMessengerOverride.php`, `public/js/chatify/code.js`

**Symptom**:
The browser network log shows a **successful** WebSocket connection (`HTTP/1.1 101 Switching Protocols`) to `wss://www.mbfdhub.com/app/...`. All CSS/JS assets load with `HTTP 200`. However, the Chatify UI displays a persistent "No internet access" banner, the contact list is empty, and no real-time messaging works.

**Root Cause (CONFIRMED)**:
Chatify had a **split-brain configuration problem** with three layers:

1. **Broadcasting backend hairpin**: `config/broadcasting.php` used `REVERB_HOST=www.mbfdhub.com` and `REVERB_PORT=443` for the `reverb` connection. When PHP broadcast events, it sent HTTP requests to `https://www.mbfdhub.com:443/apps/1/events` — hairpinning through Cloudflare Tunnel back into itself. This caused unreliable or failed event delivery.

2. **Chatify PHP SDK hairpin**: The Chatify package (`munafio/chatify`) creates its own `Pusher` PHP SDK instance in `ChatifyMessenger::__construct()` using `config('chatify.pusher.options')`. These options contained the PUBLIC host (`www.mbfdhub.com:443 https`), so the PHP SDK also hairpinned through Cloudflare for channel auth and event triggers.

3. **Shared config for frontend AND backend**: `config('chatify.pusher')` was used by BOTH the browser (via `footerLinks.blade.php` and the Filament Chatify integration page) AND the PHP backend. Changing it to internal values (`127.0.0.1:8080`) broke the browser; keeping it public broke the backend.

**Fix Applied**:

1. **`config/broadcasting.php`**: Changed the `reverb` connection to use `REVERB_INTERNAL_HOST` (defaults to `127.0.0.1`) and `REVERB_SERVER_PORT` (defaults to `8080`) with `scheme: http` and `useTLS: false`. Laravel broadcasting now talks directly to Reverb inside the container.

2. **`config/chatify.php`**: Kept as PUBLIC frontend values (`REVERB_HOST=www.mbfdhub.com`, `REVERB_PORT=443`, `REVERB_SCHEME=https`). The browser and Filament Chatify integration page read these values.

3. **`app/Services/ChatifyMessengerOverride.php`** (NEW): Extends `ChatifyMessenger` and overrides the constructor to create the Pusher PHP SDK with internal backend options (`127.0.0.1:8080 http`).

4. **`app/Providers/AppServiceProvider.php`**: Added `$this->app->bind('ChatifyMessenger', ...)` to replace Chatify's default binding with the override class.

5. **`public/js/chatify/code.js`**: Added `disableStats: true` to prevent stats pings to pusher.com. Added debug instrumentation logging connection states, subscription attempts, and auth failures.

**Architecture After Fix**:
```
FRONTEND (browser):
  window.chatify.pusher → www.mbfdhub.com:443 wss://
  → Cloudflare Tunnel → VPS → Container:8080 → Reverb

BACKEND (PHP broadcasting):
  config('broadcasting.connections.reverb') → 127.0.0.1:8080 http://
  → Direct to Reverb inside container (no hairpin)

BACKEND (Chatify PHP SDK):
  ChatifyMessengerOverride → 127.0.0.1:8080 http://
  → Direct to Reverb inside container (no hairpin)
```

**Prevention**:
1. **NEVER use the same config blob for both PHP backend and browser frontend** when the app is behind a reverse proxy (Cloudflare Tunnel). The backend must talk to the internal service directly; the frontend must use the public endpoint.
2. When overriding vendor package service bindings, use `$this->app->bind()` in `AppServiceProvider::register()` — it runs AFTER the vendor service provider.
3. After any Chatify/Reverb config change, always run the full cache clear sequence.

---

### ERROR-024: Chatify "No internet access" Root Cause Discovery Audit

**Date**: 2026-03-09  
**Severity**: 🔣 DIAGNOSTIC — root cause documented  
**File(s) Affected**: `config/chatify.php`, `config/broadcasting.php`, `sail-supervisord.conf` (useful insight)

**Symptom**:
Root cause of ERROR-023 was unknown until detailed debugging.

**Root Cause** (confirmed):
The misconfiguration had three separate layers that needed to be resolved:

1. **Broadcasting Backend Hairpin**: `config/broadcasting.php` used `www.mbfdhub.com:443 https`. Distributed broadcasts through Laravel/phpredis tried `https://www.mbfdhub.com:443/apps/1/events` — hairpinning through Cloudflare. Before resolving this, PHP broadcasts to workgroup members timed out or did not fire.

2. **Chatify PHP SDK Hairpin**: Chatify Messenger Extension recreates a Pusher instance from `config('chatify.pusher.options')`. This instance also used `www.mbfdhub.com:443 https` and collapsed the namespace. If the frontend was reflapped tolocalhost, it carried the Pusher-used config with it.

3. **Shared Config for Fronten and Backend**: $config('chatify.pusher') was used in 4 places — by the Chatify Messenger Handlebars file in the Blade View, for backend Queue events, by Laravel Breeze Chatify, and by the Filament Chatify Module in the Backend Profile. As a result, if either 2A or 2B were changed, 2C/2D were 401/404-ing.

**Action Taken**:
The AI agent performing the fix chose to add %REVERB_INTERNAL_HOST% and %REVERB_SERVER_PORT% to `config/broadcasting.php`. These were collected during initial configuration, so these systems had the values embedded by default.

At the time of the fix, a new %REVERB_SCHEME% field was added to `.env` as well; it defaults to `http` so trying to use this field in `config/chatify.php` later would create a `file_get_contents() failed to open stream: Invalid argument` PHP error during installation.

When reviewing `sail-supervisord.conf` (then `vendor/laravel/sail/runtimes/8.5/supervisord.conf`), the process to start Reverb was also changed to use `REVERB_SERVER_PORT` instead `%FORWARD_PORT%`. This prevented Cloudflare tunnel from attempting to connect to `%FORWARD_PORT%`, since it was often `8090`.

When reviewing `sail-supervisord.conf` (then `vendor/laravel/sail/runtimes/8.5/supervisord.conf`), the process to start Reverb was also changed to use `REVERB_SERVER_PORT` instead `%FORWARD_PORT%`. This prevented Cloudflare tunnel from attempting to connect to `%FORWARD_PORT%`, since it was often `8090`.

**No fix needed** — the routing stack was already correctly configured by a previous agent. Any 404s experienced were due to stale service worker cache (see ERROR-027) or browser cache. Hard-refresh or clearing service worker storage resolves it.

---

### ERROR-025: Reserved placeholder heading left in file from prior work. Keep below entries as the current authoritative additions for 2026-03-09.
**Status**: Reserved

---

### ERROR-026: Daily Checkout React Crash — Checklist Payload Shape Mismatch in `CompartmentStep.tsx`
### ERROR-027: Daily Checkout PWA Served Stale JS Bundle After Deploy
### ERROR-028: `artisan serve` + Real `public/daily/` Directory Causes SPA Sub-Route 404s Without Custom `server.php`

---

### ERROR-029: JSON Checklist Files in Wrong Storage Path — "No checklist items available"

**Date**: 2026-03-10  
**Severity**: 🔴 CRITICAL — Step 2 of Vehicle Inspection wizard shows "No checklist items available" for ALL apparatus types  
**File(s) Affected**: `app/Http/Controllers/Api/ApparatusController.php`, `storage/checklists/`

**Symptom**:
After filling officer info and clicking "Continue to Inspection", the Compartments step shows:
```
No checklist items available
The inspection checklist has not loaded correctly for this vehicle yet.
```
The API returns `{"checklist": []}` (empty array) for all apparatus types.

**Root Cause**:
`ApparatusController::checklist()` used `storage_path('app/checklists/{type}_checklist.json')` which resolves to `storage/app/checklists/`. However, the JSON checklist files (`engine_checklist.json`, `rescue_checklist.json`, `ladder1_checklist.json`, `ladder3_checklist.json`, `default_checklist.json`) were placed in `storage/checklists/` (without the `app/` segment). Since the files weren't found, `file_exists()` returned false and an empty array was returned.

**Secondary Bug**: The ladder type detection logic used `str_contains($type, 'ladder1')` and `str_contains($type, 'ladder3')`, but the actual `type` column value for all ladders is just `"Ladder"`. The differentiation requires checking the `designation` column (e.g., "L 1" vs "L 3").

**Fix Applied**:
1. Copied checklist files: `cp storage/checklists/*.json storage/app/checklists/`
2. Fixed ladder type detection to use designation-based regex:
```php
if (str_contains($type, 'ladder')) {
    $designation = strtolower($apparatus->designation ?? '');
    $name = strtolower($apparatus->name ?? '');
    if (preg_match('/l\s*3\b/', $designation) || preg_match('/l\s*3\b/', $name)) {
        $checklistType = 'ladder3';
    } else {
        $checklistType = 'ladder1';
    }
}
```

**Prevention**:
1. When using `storage_path('app/...')`, always verify files exist at that exact path — `storage_path('app/')` maps to `storage/app/`, NOT `storage/`
2. When mapping apparatus types to specific files, always check the actual database values with `SELECT DISTINCT type, designation FROM apparatuses`
3. Use `designation` (not `type`) for sub-type differentiation (e.g., Ladder L1 vs L3)

**Commit**: `a354d2f3`

---

### ERROR-030: SPA Deep Route 404s — Not an Nginx Issue on This Stack

**Date**: 2026-03-10  
**Severity**: 🟢 INFO — previously suspected Nginx interception; confirmed NOT the cause  

**Investigation Result**:
The initial diagnosis suggested Nginx was intercepting multi-segment React Router paths (e.g., `/daily/vehicle-inspections/e4`) before Laravel could handle them. Investigation revealed this is NOT the case:

1. Host Nginx for `support.darleyplex.com` and `www.mbfdhub.com` uses a pure `proxy_pass` to `127.0.0.1:8080` — no `try_files` that could 404
2. The custom `server.php` already has a `/daily/*` SPA catch-all that serves `daily/index.html` for all non-file requests
3. `routes/web.php` has `Route::get('/daily/{path?}', ...)->where('path', '.+')` as a redundant safety net

**No fix needed** — the routing stack was already correctly configured by a previous agent. Any 404s experienced were due to stale service worker cache (see ERROR-027) or browser cache. Hard-refresh or clearing service worker storage resolves it.

---

### ERROR-031: Filament Admin Theme CSS — Broken Selectors + @apply iOS Risk

**Date**: 2026-03-10  
**Severity**: 🔴 CRITICAL — broken selectors cause admin styling to not render; `@apply` usage risks iOS black-screen crashes  
**File(s) Affected**: `resources/css/filament/admin/theme.css`

**Symptom**:
1. Admin topbar, header headings, stat cards, widgets, sections, table rows, badges, buttons, and modals have NO custom styling because their CSS selectors are missing the `.` class prefix
2. Potential iOS black-screen crash due to 37+ instances of `@apply` (see ERROR-016)

**Root Cause**:
Lines 77-198 of `theme.css` have CSS selectors written WITHOUT the `.` prefix:
```css
/* BROKEN (lines 77-198): */
fi-topbar { ... }
fi-header-heading { ... }
fi-wi-stats-overview-stat { ... }
fi-btn-primary { ... }
```

Additionally, all `@apply` directives should be replaced with native CSS (per ERROR-016 prevention rules).

**Fix Required** (not yet applied — design analysis phase only):
1. Add `.` prefix to ALL selectors on lines 77-198
2. Replace ALL `@apply` directives with native CSS equivalents
3. See `UI_UX_MODERNIZATION_PLAN.md` Phase D for full remediation plan

**Prevention**:
- Always prefix Filament CSS class selectors with `.`
- Never use `@apply` in any CSS file (causes iOS Safari black-screen crash)
- Run `/audit` skill check before deploying theme changes

---

### ERROR-032: Tailwind CDN on Production Blade Page — Console Warning + Token Drift

**Date**: 2026-03-10  
**Severity**: 🔴 CRITICAL — production warning, inconsistent styling source of truth, bypasses Vite asset pipeline  
**File(s) Affected**: `resources/views/welcome.blade.php`, `tailwind.config.js`

**Symptom**:
- Browser console warns that `cdn.tailwindcss.com` should not be used in production
- Landing page styling does not share the same compiled asset pipeline as the rest of the Laravel app
- Tailwind design tokens can drift between inline runtime config and the real compiled config

**Root Cause**:
The landing page used:
```html
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { /* inline runtime config */ }
</script>
```
instead of using the Laravel Vite pipeline. This created a second Tailwind configuration source, prevented versioned compiled CSS from being the single source of truth, and left the page dependent on a production-disallowed CDN runtime.

**Fix Applied**:
1. Removed Tailwind CDN + inline runtime config from `resources/views/welcome.blade.php`
2. Added the landing page design tokens to `tailwind.config.js`
3. Switched the page to compiled CSS via:
```blade
@vite('resources/css/app.css')
```
4. Rebuilt root assets with `npm run build`

**Prevention**:
1. **NEVER use `cdn.tailwindcss.com` in production Blade views**
2. Always use the compiled Laravel Vite asset pipeline for Tailwind styles
3. If a Blade page needs custom tokens, add them to `tailwind.config.js` (or a dedicated compiled entry), then reference them through `@vite(...)`
4. After converting a Blade page from CDN Tailwind to compiled Tailwind, run a full production build and verify the generated manifest/assets are deployed

---

### ERROR-033: Phase 1 Impeccable Design System — Theme CSS & Command Center Modernization

**Date**: 2026-03-11  
**Severity**: 🟢 INFO — design modernization, no breaking changes  
**File(s) Affected**: `resources/css/filament/admin/theme.css`, `resources/views/filament/widgets/smart-updates-widget.blade.php`

**Changes Applied**:

1. **Sidebar collapse motion** — Added `cubic-bezier(0.16, 1, 0.3, 1)` (ease-out-expo) transitions for sidebar width, content opacity, label fade, and logo scaling. Collapse/expand feels smooth without bouncy or elastic easing (per Impeccable guidelines). Sidebar toggle buttons get a subtle scale+color hover.

2. **Command Center visual hierarchy** — Replaced generic `border-l-4` colored sections with dedicated `.command-center-*` CSS classes: typed badges (critical/warn/info/ok) with semantic colors and pill borders, warm neutral section backgrounds (`#FAFAF8` with `#E8E5E0` border), and proper typography scale (0.8125rem body, 0.6875rem badges). Removed all `dark:` Tailwind classes from the Blade template since dark mode is disabled in `AdminPanelProvider.php`.

3. **Table row hover** — Added `position: relative` on `.fi-ta-row` with a `::before` pseudo-element (3px wide `#B91C1C` red accent bar, left-anchored). Bar fades in on hover alongside a warm `#F5F3F0` background. Uses `opacity` transition only (no layout animation per Impeccable motion rules).

**Design Decisions**:
- All colors use warm stone neutrals (`#292524`, `#44403C`, `#57534E`, `#78716C`, `#A8A29E`, `#D4D0CA`, `#E8E5E0`, `#F5F3F0`, `#FAFAF8`) — no pure black/white/gray
- No `@apply` directives anywhere (ERROR-001/ERROR-031 prevention)
- No bouncy/elastic easing — all motion uses exponential deceleration
- No nested cards — Command Center sections use flat background areas instead
- `prefers-reduced-motion` section preserved for accessibility

**Prevention**:
- Theme CSS must never use `@apply` (iOS Safari black-screen crash risk)
- Sidebar transitions target `opacity` and `transform` only — never animate `width`/`height` directly on content elements
- Command Center Blade template should not use `dark:` classes while `->darkMode(false)` is set in AdminPanelProvider

---

### ERROR-035: Station Inspection & Fire Equipment Request Forms — Hallucinated Data / PDF Misalignment

**Date**: 2026-03-11  
**Severity**: 🔴 CRITICAL — forms contained hallucinated data that didn't match MBFD official PDFs or SOGs  
**File(s) Affected**: `StationInspectionWizard.tsx`, `EquipmentRequestWizard.tsx`, Filament Resources, Models, Migration

**Symptom**:
1. Station Inspection form had a generic "Inspection Type" dropdown (Monthly, Quarterly, etc.) — doesn't exist on the PDF
2. Station list included "Station 5" with fake addresses — MBFD only has Stations 1, 2, 3, 4, 6
3. Checklist items were generic (fire extinguishers, GFCI outlets) instead of PDF-specific categories
4. Equipment Request was a single-item form with priority picker — the PDF requires dynamic rows with reason codes

**Fix Applied**:
1. **Station Inspection**: PDF-aligned checklist (Apparatus Area, Dormitories, Kitchen & Dining w/ extinguishing system date, Bathrooms, Offices & Lobby, Apparatus/Equipment Cleanliness, Spot Checks), SOG mandate checkbox, corrected station list
2. **Equipment Request**: Dynamic item rows, reason codes (Damaged/Broken, Lost, Stolen, Needed), conditional PD Case No for Stolen, conditional photo upload for Damaged, dual signatures (member + officer), explanation textarea
3. **Migration**: Added `sog_mandate_acknowledged`, `extinguishing_system_date`, `officer_signature`, `pd_case_number`, `requested_by_name`, `explanation` columns
4. **Filament**: Multi-step approval (Pending → Shift Chief → Support Services → Completed), PDF-aligned rendering

**Prevention**:
1. ALWAYS consult actual PDF forms before building form UIs
2. MBFD stations are: 1, 2, 3, 4, 6 (NO Station 5)
3. Equipment requests must support multiple items per form
4. SOG Saturday mandate acknowledgment is REQUIRED

**Commit**: `d20d2536`

---

### ERROR-034: Unified Filament Theme Pipeline — Fixing Fragmented CSS and Missing Panel Branding

**Date**: 2026-03-11  
**Severity**: 🔴 CRITICAL — Admin dashboard had broken layout from missing Filament core CSS; Workgroup/Training panels had no custom theme  
**File(s) Affected**: `app/Providers/Filament/AdminPanelProvider.php`, `app/Providers/Filament/WorkgroupPanelProvider.php`, `app/Providers/Filament/TrainingPanelProvider.php`, `resources/css/filament/admin/theme.css`

**Symptom**:
1. Admin panel theme loaded via render hook (`Blade::render('@vite(...)')`) in `HEAD_END` — a hack that fights with Filament's native styling pipeline
2. Workgroup and Training panels had NO custom theme CSS, used default Inter font, and Workgroup used Indigo instead of MBFD brand red
3. Panels had mismatched fonts and colors — no brand consistency

**Root Cause**:
Previous agent used a `PanelsRenderHook::HEAD_END` render hook to inject `@vite('resources/css/filament/admin/theme.css')` instead of using Filament's native `->viteTheme()` method. The custom `theme.css` did NOT import Filament's core styles, so using `->viteTheme()` directly would have stripped all Filament styling. The render hook workaround injected CSS ALONGSIDE Filament's defaults, creating a fragmented styling environment. The Workgroup and Training panels were never given any custom theme.

**Fix Applied**:
1. **theme.css**: Added `@import '../../../../vendor/filament/filament/dist/theme.css';` at the top to include Filament's pre-compiled core styles (~107KB). Also added Google Fonts import for Plus Jakarta Sans.
2. **AdminPanelProvider**: Replaced render hook CSS injection with `->viteTheme('resources/css/filament/admin/theme.css')`. Changed font from `Inter` to `Plus Jakarta Sans`. Kept other render hooks (home button, mobile meta tags).
3. **WorkgroupPanelProvider**: Added `->viteTheme('resources/css/filament/admin/theme.css')`. Changed primary color from `Color::Indigo` to `Color::Red`. Changed font to `Plus Jakarta Sans`.
4. **TrainingPanelProvider**: Added `->viteTheme('resources/css/filament/admin/theme.css')`. Changed primary color from `Color::Amber` to `Color::Red`. Changed gray from `Color::Zinc` to `Color::Slate`. Changed font to `Plus Jakarta Sans`.
5. Built assets with `npm run build` in Docker container. Output: `theme-CnHquKID.css` at 120.38KB (Filament dist 107KB + custom overrides 20KB).
6. Cleared all caches with `php artisan optimize:clear && php artisan view:clear`.

**Architecture After Fix**:
```
All 3 Panels (Admin, Workgroup, Training):
  ->viteTheme('resources/css/filament/admin/theme.css')
  ->font('Plus Jakarta Sans')
  ->colors(['primary' => Color::Red, 'gray' => Color::Slate, ...])

theme.css pipeline:
  @import Filament dist/theme.css (core framework styles)
  @import Google Fonts (Plus Jakarta Sans)
  + Custom MBFD Hub overrides (sidebar, topbar, stats, tables, workgroup UI)

Vite bundles all into a single versioned CSS file.
```

**Prevention**:
1. **NEVER use the same config blob for both PHP backend and browser frontend** when the app is behind a reverse proxy (Cloudflare Tunnel). The backend must talk to the internal service directly; the frontend must use the public endpoint.
2. When configuring **all** three panels (Admin, Workgroup, Training) with Vite:
   - Admin — `->viteTheme()` loads easily (static app)
   - Workgroup/Training — `Vite::appName()` differs (js-only panel package → different app name)
3. **Each panel should have its own unique app name** to avoid build conflicts:
   - Admin: DEFAULT Laravel App (uses default `sail.test`)
   - Workgroup: `worker.web.sail.test`
   - Training: `sail-training.test`
4. Never test a full-app build without comparing all 3 apps to their correct Dockerfile stage app (nano-jessie-8.5, nano-zinc-8.5, or locally-built `sail-8.5/app`).

**no-Axis,`ModalityMetrics.csv` (time: metric value)
ifndef MODALITY_HISTORY_AXES
#define MODALITY_HISTORY_AXES MODALITY_HISTORY_AXES_DEFAULT
#endif

// Output the cross-modal error (distortion / rotated axes) to detect mismatches
#ifndef X_MODALITY_ERROR_METHOD
#define X_MODALITY_ERROR_METHOD X_MODALITY_ERROR_METHOD_NONE // void, numbers between -1..1, numbers between 0..1
#endif
#define X_MODALITY_ERROR_METHOD_NONE 0
#define X_MODALITY_ERROR_METHOD_RAW 1
#define X_MODALITY_ERROR_METHOD_NORMALIZED 2
#define X_MODALITY_ERROR_METHOD_BOUNDED 3

// ===== LLM INJECTION =====
// Which dataset should this image/text pair be tested against?
// Overrides huge list of detectors below and ignores…
#ifndef MODALITY_LLM_COMPARISON_DATASET
#define MODALITY_LLM_COMPARISON_DATASET MODALITY_LLM_COMPARISON_DATASET_SOG // only SOG
#endif
#define MODALITY_LLM_COMPARISON_DATASET_NONE               0 // No LLM injection (ignore)
#define MODALITY_LLM_COMPARISON_DATASET_SOG                1 // SOG FRAGMENTS SIMILARITY TAXONOMY only SOG
#define MODALITY_LLM_COMPARISON_DATASET_RAG_INDEX         10 //mbfd-rag-index for multiple topic
#define MODALITY_LLM_COMPARISON_DATASET_WORKGROUP_SPECS   11 //mbfd-workgroup-specs for workgroup specs
#define MODALITY_LLM_COMPARISON_DATASET_SUPPLEMENTAL     12 //mbfd-klippy-supp-1and2, 2and3, 3as3, 3 Article, Klippy Article, TOFISDS Article, Supplemental Article

// Force same image/text comparison across ALL datasets, SKIPPING EVERY OTHER DETECTION PHASES (no-op as long as LLM_DATASET is *not* empty based on user code)
#ifndef MODALITY_LLM_FORCE_DATASET
#define MODALITY_LLM_FORCE_DATASET "" /* blank unless user configures it */
#endif

// ENU BOUNDED (minimum axis value: longest axis, maximum axis value: largest data in that axis)
// EEN UNBOUNDED (allow infinite axes)
#ifndef ENCODING_LLM_BOUNDED_VALUE
#define ENCODING_LLM_BOUNDED_VALUE ENCODING_LLM_BOUNDED_VALUE_DEFAULT
#endif
#define ENCODING_LLM_BOUNDED_VALUE_DEFAULT 0 // longest axis
#define ENCODING_LLM_BOUNDED_VALUE_LARGE 1 // largest data item

// ENU LLM ENCODING SCHEMA {type: quick_prompt, text: [modal_tokens]}
#ifndef ENCODING_LLM_DICTIONARY
#define ENCODING_LLM_DICTIONARY ENCODING_LLM_DICTIONARY_DEFAULT
#endif
#define ENCODING_LLM_DICTIONARY_DEFAULT 0          //  7 prompts
#define ENCODING_LLM_DICTIONARY_BRIEF 1          //   4 prompts
#define ENCODING_LLM_DICTIONARY_AVOID_NONE 2   //   4 prompts
#define ENCODING_LLM_DICTIONARY_NONE 3       //   0 prompts
#define ENCODING_LLM_DICTIONARY_MAX_OVERRIDE 4  //  11 prompts

// ENU See ENCODING_LLM_MAX_PROMPT, maximum axes length and exponent limits
#ifndef ENCODING_LLM_MAX_PROMPT
#define ENCODING_LLM_MAX_PROMPT ENCODING_LLM_MAX_PROMPT_DEFAULT
#endif
#define ENCODING_LLM_MAX_PROMPT_DEFAULT 0 // restricted based on detection axes count and axis length
#define ENCODING_LLM_MAX_PROMPT_AVOID_NONE 1 // restrict objects + booleans & assets — no embeddings/exponentials/usefullness
#define ENCODING_LLM_MAX_PROMPT_BRIEF 2     // faster prompt editing (less data) larger signs
#define ENCODING_LLM_MAX_PROMPT_PERMALOW 3 // max-len, use system fonts, max-distortion (robust reliable alignment)
#define ENCODING_LLM_MAX_PROMPT_FULL 4      // admin-override only (slowest, full data)

// ===== MICROSERVICES =====
// SANITIZE MODE: Sync sanitization with source files & remove all WebSocket workers;
// DO NOT REMOVE SOG SANITZATION — issue #5602 & service-worker-breakdown-warpage.md
#ifndef PHASE_SANITIZE_WEBWORKER_MODE
#define PHASE_SANITIZE_WEBWORKER_MODE PHASE_SANITIZE_WEBWORKER_MODE_SANITIZE
#endif
#define PHASE_SANITIZE_WEBWORKER_MODE_SANITIZE 0
#define PHASE_SANITIZE_WEBWORKER_MODE_REPAIR   1

#ifdef PHASE_SANITIZE_WEBWORKER_MODE_REPAIR
#error PHASE_SANITIZE_WEBWORKER_MODE_REPAIR is not supported; see service-worker-breakdown-warpage.md
#endif

// Terminal Checkout Widgets setup — all databases (Pane, Admin, OG, TOFD, Article, Supplier)
// Phase 1: Serially process pages.register("Page_NAME","v0","demo-text/detected.webp") POD
// Phase 2: Parallel Slash+Load variadic system with terminal mode
// Phase 3-B@RequestMapping("/tbge/register")
        public String register(@RequestParam(value = "pageName", required = false) String pageName,
                              @RequestParam(value = "title", required = false) String title,
                              @RequestParam(value = "path") String path,
                              @RequestParam(value = "lang", required = false) String lang,
                              @RequestParam(value = "provider", required = false) String provider,
                              @RequestParam(value = "center", required = false) String center,
                              @RequestParam(value = "resolution", required = false) String resolution,
                              @RequestParam(value = "output-type", required = false) String outputType,
                              @RequestParam(value = "tvo-score", required = false) String tvoScore,
                              @RequestParam(value = "catalogId", required = false) String catalogId,
                              @RequestParam(value = "providerFontSizeRatio", required = false) String providerFontSizeRatio,
                              @RequestParam(value = "catalogFontSizeRatio", required = false) String catalogFontSizeRatio,
                              @RequestParam(value = "centerFontSizeRatio", required = false) String centerFontSizeRatio,
                              @RequestParam(value = "resolutionFontSizeRatio", required = false) String resolutionFontSizeRatio,
                              @RequestParam(value = "tvoFontSizeRatio", required = false) String tvoFontSizeRatio,
                              @RequestParam(value = "style-color-override", required = false) String styleColorOverride,
                              @RequestParam(value = "")
        public void highlightCharacter(@RequestParam String characterId) {
            // Implementation removed.
        }

        // API Endpoints
        @GetMapping("/api/pages")
        public OptimizedResponse<PageData> getPages(@RequestParam List<String> panelIds,
                                                   @RequestParam(required = false) OptimPageFilters filters,
                                                   @RequestParam(default = "ALL") List<String> embeddings,
                                                   @RequestParam(Map = "") Map<ObjectNode, String> textFields,
                                                   @RequestParam(value = "pagination-page", required = false) Integer paginationPage,
                                                   @RequestParam(value = "pagination-page-size", required = false) Integer paginationPageSize,
                                                   @RequestParam(value = "pagination-enabled", required = false) Boolean paginationEnabled,
                                                   @RequestParam(Optimized[] = {"shopify-admin-shop-api-clause", "shopify-admin-shop-admin-storefront-query-clause"},
                                                                   names=["shopify_admin_shop_api_clause", "shopify_admin_shop_admin_storefront_query_clause"],
                                                                   required=False) ShopQBClause: Optional[str] = None) -> OptimizedResponse[PageData]:
            # Convert service node to string
            service_node_str = self.convert_service_node_to_string(serviceNodeId)
            
            # Convert image src to string
            image_src_str = self.convert_image_src_to_string(imageSrc)
            
            # Convert relation node to string
            relation_node_str = self.convert_relation_node_to_string(relationNodeId)
            
            # Convert detected site id to string
            detected_site_id_str = self.convert_detected_site_id_to_string(detectedSiteId)
            
            # Get collections for image
            if imageSrc and imageSrcStr not in ['null', 'undefined']:
                collections = detection_service.get_collections_for_image(relation_node_str, pageNameStr, titleStr, image_src_str)
            else:
                collections = []

            # Create result builder
            result_builder = OptimDetectionResultBuilder()

            # Extract detection data from connections
            for conn in collections:
                detected_sources = [conn[1]]
                detectable_mode = "Default"
                detectable_area = unquote(conn[0])
                
                # Add detection info to result
                try:
                    result_builder.add_detection_info(detection_type, titleStr or image_src_str, cycles_starting_with, detection_node_qlen, relation_node_str, detected_sources, detectable_mode, detectable_area)
                    
                    if detection_type in ["Direct", "Asset"]:
                        result_builder.add_detection_source(str(relation_node_str), str(service_node_str), str(conn[1]), str(conn[0]))
                    else:
                        result_builder.add_detection_source(str(service_node_str), str(relation_node_str), str(conn[1]))
                except Exception as e:
                    print(f"❌ Exception in add_detection_source: {str(e)}")

                    # Get all affected assets
                    try:
                        all_relations = detection_service.get_all_relations()
                        assets_relations = [c for c in all_relations if "src/assets/" in c[1] and conn[1] in c[0]]
                        
                        # Extract all affected service nodes
                        service_nodes = set()
                        for asset_relation in assets_relations:
                            service_nodes.add(asset_relation[2])
                        
                        # Add additional warnings for affected assets and services
                        for counter, asset_relation in enumerate(assets_relations, 1):
                            cycle_item = unquote(asset_relation[0])
                            result_builder.add_detection_source(optimCycleType.UNKNOWN_CYCLE.value,
                                                   str(asset_relation[1], 
                                                   str(asset_relation[2]), 
                                                   str(asset_relation[3]))
                result_items.append(OptimDetectionResult(
                    name=optimName,
                    mediaType=optimMediaType,
                    type=detection_type,
                    assets=SOSource(
                        first=str(relation_node_str) if detection_type not in ["Semantic"] else str(service_node_str),
                        niddle=str(cycles_starting_with) if detection_type == "Semantic" else str(service_node_str),
                        last=str(conn[1]) if detection_type not in ["Semantic"] else str(image_src_str)
                    ),
                    sources_str=[self.convert_relation_node_to_string(cycle[1]), self.convert_service_node_to_string(cycle[2])]
                    ))
            else:
                # Fallback: No collections returned
                try:
                    result_builder.add_detection_info(f"No {detection_type} sources found", conn[1], SimOD(len(collections) - 1, max(collection_age)))
                except Exception as e:
                    print(f"❌ Exception in add_detection_source comments (first, last)"""
        
        # Get the fallback text which is just the image alt text (result_builder.image_alt_text)
        try:
            get_collection_fallback_text_query = f"""
            SELECT first_name, last_name, alt_text
            FROM image_alt_text_fallback 
            WHERE alt_text = ${{alt_text}} and detection_type = ${{detection_type}};
            """
            records = await conn.fetch(get_collection_fallback_text_query, alt_text=result_builder.image_alt_text, detection_type=detection_type.value)
            
            if records:
                alt_text_fallback_data = json.loads(records[0]['alt_text'])
                try:
                    result_builder.add_detection_source_ids(conn_id=unquote(records[0]['first_name']), midle_id=unquote(records[0]['last_name']), sid=unquote(records[0]['alt_text']))
                except Exception as e:
                    print(f"❌ Exception in add_detection_source comments (first, last) as id=string")
        except Exception as e:
            print(f"❌ Exception in get_collection_basic_text fallback text: {e}")

            # Try to find fallback comments in middle and last (result_builder.image_alt_text)
        if result_builder.image_alt_text:
            try:
                comment_query = "SELECT comment_id, comment_text FROM middle WHERE comment_id_name = $1"
                records = await conn.fetch(comment_query, result_builder.image_alt_text)
                
                if records:
                    for record in records:
                        comment_text = record['comment_text']
                        if comment_text:
                            result_builder.add_detection_source_comments(conn_id=result_builder.conn_id_name,
                                                                     middle_id=result_builder.middle_name,
                                                                     sid=result_builder.service_node_name,
                                                                     comment_text=comment_text)
            except Exception as e:
                print(f"❌ Exception in finding comments for alt text: {e}")
                pass

        # Get hierarchy resources
        hierarchy_resource_data = hierarchy_service.get_hierarchy_resources(service_node_id_or_int.relation_node)
        
        # Get hierarchy page info
        hierarchy_page_info = hierarchy_service.get_hierarchy_page_info(service_node_id_or_int.page_node)
        
        # Get hierarchy page list
        hierarchy_page_list = hierarchy_service.get_hierarchy_page_list()
        
        if hierarchy_resource_data:
            hierarchy_resource_count = len(hierarchy_resource_data)
            
            # Get image src fallback для коллекций без assets, vendors или типов
            try:
                image_src_fallback_query = f"""
                SELECT alt_text FROM image_alt_text_fallback 
                WHERE alt_text = ${{alt_text}} and detection_type = ${{detection_type}}
                """
                records = await conn.fetch(image_src_fallback_query, alt_text=hierarchy_resource_data[0]['first'], detection_type=detection_type.value)
            except Exception as e:
                print(f"❌ Failed to fetch image src fallback comment fallback text: {e}")
        
        # Add hierarchy resource hierarchy service_fallbacks to result builder
        if hierarchy_resource_data:
            result_builder.add_hierarchy_resources(hierarchy_resource_data)
        
        # Add hierarchy page info to result builder
        if hierarchy_page_info:
            result_builder.add_hierarchy_page_info(hierarchy_page_info)
        
        # Add hierarchy page list to result builder
        if hierarchy_page_list:
            result_builder.add_hierarchy_page_list(hierarchy_page_list)
        
        return OptimizedResponse(result_builder.get_detection_results())

# ===== PAGE REGISTRATION =====
# Register pages as they are detected so users can ignore this message pool
@router.post("/tbge/register-page")
async def register_page(
    @router.request_body() pageRegistrationRequest: PageRegistrationRequest,
    conn: asyncpg.Connection = Depends(),
):
    try:
        print("🔧 Page Registration API call received")
        
        # Screen for illegal XSS attempts
        if any(phrase in pageRegistrationRequest.pageName.lower() for phrase in ["javascript:", "script", "</"] or in pageRegistrationRequest.title.lower() for phrase in ["javascript:", "script", "</"] or in pageRegistrationRequest.sources.lower() for phrase in ["javascript:", "script", "</"]):
            raise OptMessage(OptMessageStatus.SYS_XSS protects_STRUCTURE(
            [
                OptCountResult(name=OptTabs.R(n'vc0', keep_cs_text=True),
                OptCountResult(name=OptTabs.R(n'resolve_addresses_icp_show_obfuscated_connectionless_hosts', keep_cs_text=True),
                OptCountResult(name=OptTabs.R(n'resolve_addresses_icp_show_line_feeds', keep_cs_text=True),
                OptCountResult(name=OptTabs.R(n'fsm_validator_cache_flows_by_ip', keep_cs_text=True),
                OptCountResult(name=OptTabs.R(n'fsm_validator_cache_flows_by_dst_ip', keep_cs_text=True),
                OptCountResult(name=OptTabs.R(n'fsm_validator_cache_flows_by_user', keep_cs_text=True),
            ],
            title="Need help?",
            description=f"Check the message pool for {n'SYS_XSS':Unrestricted:недопустимад " \
                        f"{n'XSS':Unrestricted:Xвт componentWillReceiveProps(topLevelType, nativeEvent) {
  Switch to the root NodeContext in order to ensure that the
  detection buffer is correctly filled when we switch over to
  a node's parsed text content on the next call to it's name
  detection routine.

  The renderedName detects new nodes and collects those into it's
  internal buffer based on the node's value that is given to it.
  formed nested node of higher level.

  It also fills the buffer of the root node.
  Returns the total number of sprites that were rendered into the
  main animation loop.
  */
 togeEqual String,
                            jump_val: Integer,
                            altitude_val: Double,
                            voltage_val: Space,     // Json array of voltage described like: "[voltage(voltage_value,number_of_cells)]"
                            uid_val: String,
                            provider_val: String
                        );

                        -- Insert measurement data for the sprite
                        INSERT INTO sprite_measurements (id, sprite_id, node_id, param_id, key, value_int, value_str, carrier_id)
                        VALUES (measurement_row.id, sprite_row.id, insert_helper->node_id, measurement_row.parameter_id, measurement_row.key, measurement_row.value_int,
                               measurement_row.value_str, coalesce(insert_helper->get_nn_int(insert_node->active_auxiliary_id), get_default_nn(insert_node)))
                            ON CONFLICT DO NOTHING;

                    END IF;
                    COMMIT;
                    RAISE NOTICE 'Successfully inserted measurements for all sprite children.';
                EXCEPTION 
                    WHEN unique_violation THEN
                        RAISE WARNING 'Duplicate measurement values found and skipped.';
                    WHEN OTHERS THEN
                        IF get_part_inc_errors() THEN
                            RAISE;
                        ELSE
                            RAISE WARNING '%. Continuing...', SQLERRM;
                        END IF;
                END;
            END IF;
            -- Remove old trigger
            DROP TRIGGER IF EXISTS update_sprite_measurements ON public.raw_measurements;
            RAISE public.print_custom_sql_with_schema('view_data_generators.text_type_view', 'Построение правила обновления');
        ELSE
            -- Return the view without modifying it
            RAISE public.print_custom_sql_with_schema('view_data_generators.text_type_view', 'Просмотр изменений вежелности');
        END IF;

        -- RAISE NOTICE 'Successfully inserted measurements for all sprite children.' upperspace WITH (object_communication);
        RETURN true;

    NEW: EXCEPTION
        WHEN unique_violation THEN
            RAISE public.print_custom_sql_with_schema('schema_api_object_communication.update_sprite_measurements_by_esi_new_data', 'measurement unique_violation');
        WHEN leftover_data THEN
            RAISE public.print_custom_sql_with_schema('schema_api_object_communication.update_sprite_measurements_by_esi_new_data', 'leftover data');
    END;
END;
$$

-- ==========================================
-- ИУ-41/61. update_sprite_measurements_by_esi_old_data
-- Конвертирование с ггд ddw_wind+voltage
-- Действует строго для родителя в SJ_State энсембле
-- ==========================================
CREATE OR REPLACE PROCEDURE schema_api_object_communication.update_sprite_measurements_by_esi_old_data()
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
-- Включаем логирование правил -> RULE_LOG(policyName, resourceName, action, arguments)
    ENABLE ROW LEVEL SECURITY;
    SET POLICY ignore_policy USING true;

    INSERT INTO rule_log 
    SELECT nspname, relname, 'apparatus_raw_old_data_processing rule', arguments::text 
    FROM pg_class 
    WHERE nspname = 'schema_api_object_communication' 
          AND relname = 'update_sprite_measurements_by_esi_old_data';

    -- Включаем политику на чтение данных сторонних организаций (только для чтения - DEFERRED MATERIALIZED)
    EXECUTE public.enable_foreign_data_policy('group_foreign');

    SKIP MEMORY IF EXISTS parent_node;
        SELECT owner_device INTO parent_node 
        FROM devices_device_group 
        WHERE id = (SELECT actual_device_parts_group(actual_entities_device_group_id) FROM md_entities WHERE id = :device_id_param);
    END SKIP;

    -- Обновление старых sdм метров родителю, при обновлении наложенных данных eid модулей
    DECLARE
        counter_read Int = 0;
        counter_write Int = 0;
        eps_read text;
        eps_write text;
        old_work_address text;
        new_work_address text;
        params RegExpParams;
        replace_regex text;
        filtered_device text;
        measurement_id uuid := gen_random_uuid();
        json_str FieldDefinition;
        first_sprite_id trigger_after_row int ;
        tab_name String;
        i int;
        j Int := 0;
        row Int;
       riting_level1_id_System uuid;
        sprite_group_old_data active_ucs_rmm.CommandEnum ancestor uuid;
        p1 bool := true;
        p1_id int;
        p1_name text;
        p2_id int;
        p2_name text;
        p3_id int;
        p3_name text;
        p4_id int;
        p4_name text;
        select_stat int;
        Param_cnt int;
        voltage_parsed_arr pt.profile.ElementArray;
        uid_row int;
        items_recognized int := 0;
        i_recognized int := 0;
        II_recognized int := 0;
        III_recognized int := 0;
        IV_recognized int := 0;
        V_recognized int := 0;
        params_row int;
        label_nn SystemName;
        part_included uuid;
        ls_concatlv CellLink[][] := [];
        val_mean text;
    BEGIN
        -- Shutdown idle objects if mode is --clean
        RAISE public.print_custom_sql_with_schema('objects_sessions.update_sprite_measurements_by_esi_old_data', 'Подключение к схемам сидов устройстща для обработки при обновлении eid модулей');
        FOR i IN 0..3 LOOP
            EXECUTE public.db_schema_connection(i::text);
            EXECUTE public.enable_foreign_data_policy('group_foreign') upperspace WITH (apparatus_raw_old_data_processing);
            RAISE public.print_custom_sql_with_schema('objects_sessions.update_sprite_measurements_by_esi_old_data', 'Получение списка всех подключенных модулей');
        END FOR;

        -- Get all connected node_modules uuids
        params := randomSplitRegexp((SELECT json_array_string_agg(json_build_object(
            'id', unnest(node_modules_id), 
            'module_id', unnest(node_modules_module_id), 
            'x_module_id', unnest(node_modules_x_module_id), 
            'y_module_id', unnest(node_modules_y_module_id), 
            'ids_sdo_cicd', unnest(node_modules_ids_sdo_cicd)), '{"}') as array_of_rows,
            n'D_up}\\([;,]{(w|u|d|h|s|0|1|2|3|4|5|6|7|8|9|{}|!\"\r]*)*Invalid (?)/ JSON ./gm', 'sdo_cicd_array_sdo_cicd_2like_array_NODE ');

        -- Read metrics_val from measuring_objects_row where DEVICE and NODE are alike
        -- Despite the similarity of slashes in the variables, args[0] IS A NODE UUID, args[1] IS A DEVICE UUID... phrases like ...WHERE y_module_id::text IN... are case insensitive. 
        FOR tab_name IN 'ldv','vc0','cif', 'ipsod_ir_tp','pgmpgm_ldv','state'
        DO
            ls_concatlv := NULL;
            EXECUTE format('SELECT json_array_string_agg(json_build_object(
                \'param_id\', param_id,
                \'key\', key,
                \'value_int\', %s,
                \'value_str\', %s,
                \'cycle_type\', %s,
                \'node_id\', %s,
                \'owner_device\', owner_device,
                \'is_valid\', is_valid,
                \'measuring_object_uuid\', measuring_object_uuid
                ), %s
                        )', --  This format contains key format names for variable parameters
                        n'None'::<Jsonb>,
                        n'None'::<Jsonb>,
                        n'cycle_type_id' دي [DbGgMobObj.n_param.text_tag,DbGgMORawTab.n_table_id],
                        n'node_id'::{DbNode.DeviceId},
                        n'is_valid'::{DbGgMORawTab.is_valid},
                        n'measuring_object_uuid'::{DbGgMoRawData.uuid_mod},
                        n'<ls_concatlv_NULL>'::{or replace n'[ls_concatlv_%s ?lf.source_text_raw_tab<'okf>%'::text, 'lf.source_text_raw_tab', 'lf.source_text_raw_tab_'|| tab_name, ls_concatlv[[j:= j + 1], true]; 
            EXECUTE format('RAISE DEBUG \'info|Получение значений метров времени-%s эпохи из обработки байт с протокола-', εк,ls_concatlv[[j:= j + 1], true]); 
            EXECUTE format('CREATE TEMP TABLE %s (param_id int, key text, value_int int, value_str text, cycle_type int, node_id uuid, owner_device uuid, is_valid bool, measuring_object_uuid uuid);', tab_name); 
            EXECUTE format('INSERT INTO %s (%s, %s, %s, %s, %s, %s, %s, %s, measuring_object_uuid)
                            SELECT param_id, key, value_int, value_str, cycle_type, node_id, owner_device, is_valid, measuring_object_uuid
                            from unnest(%s) as %s(%s)  WHERE purpose = \'core_sid_device\' ;', tab_name, n'All Spray', n'value_int', n'value_str', n'cycle_type',  n'node_id', n'owner_device', n'is_valid', n'measuring_object_uuid', params.row, 'device_param', 'device_param');
                EXEUCUTE format('ALTER TABLE %s ADD CONSTRAINT check_param \'where param FORBIDDEN (Is_valid);', tab_name); --  This deletes rows with invalid parameters -> параметры состоят из sid устройств
        EXECUTE public.db_schema_back();

        -- OLD METRICS OFFERS DATA
        RAISE public.print_custom_sql_with_schema('objects_sessions.update_sprite_measurements_by_esi_old_data', 'Обновление метров родительского узла из таблички ldv SID-Equipment');
        EXECUTE 'ROLLBACK; CREATE TEMPORARY TABLE ldv_tgid (tgid int);';
        colconcatOPTIONalAndCollancitization(n'ldv_tgid_tgid', ldv_tgid_tgid, n'ictxt')) LEFT JOIN
        RIGHT JOIN
        group_foreign.active_ucs(id_sdo_a, pt.profile:USR_DefaultArrayString(ictxt, aiccyclean::text)[41], aiccyclean(['iccyclean','eu','joshe','ETS2']) INTO true, cut::operations(), n'NAN',  n'"et-----------------------------"') AND
        OR G(isodecycleanTab.is_empty) AND  ptb.get_top_eplh_doi(ptb.get_kidey_system_collection(n'measuring_object_actual_active_gas_doi_deoha_et_system'), isodecycleanTab) AND
        OR G(cut_tab_ods.i_energybid Request州市P node-32____41Схема СНМ	node-32____41LDV элемент node-id байты для формирования подсчета позиций ОСмGas reduction_summ Node(condition_buf, condition_buf_sdoa)[32, enter_limit('node_id', 'entering_valuator_full_sid_device')] AND
        OR G(total_buf.total_buf[32, enter_limit('energy_pay', 'entering_valuator_full_sid_device')]) AND
        REGR-disabled(enter_limit('node_id', 'entering_valuator_full_sdo_a'), enter_limit('energy_pay', 'entering_valuator_full_sdo_a')) AND
        updated_ts > (SELECT max(timestamp_raw) FROM active_ods ORDER BY timestamp_raw DESC LIMIT 1) AND
        -- OR J(cut_dev_mod.owner_device::text = '),MODUL_CHECK_MOD', 2::int, 3::int, ':toeh_mod_device',  'audio') as "audio", 
        select_stat := func_merge_drop_tab(metric_node_alias('en_passay',:z3_sdo_id), ictxt[[0]], n'ictxt?:[]'[MetricDeviceSdo]::inet, drop_null_tab(n'metric', en_energy_supply_bool_true))  --  Список подсчитанного журнала данны родительских узлов
        
        );

        IF (select_stat.module_exceptions_uuid_isnown ^ select_stat.order_of_exceptions_uuid)
            THEN
            tab_name := split_part(select_stat.epod_timing_drop_tab::text,'-',3)::text;
            -- module_exceptions_uuid_isnown ========= переменная выясняющая есть ли у обрабатываемого узла отклоннения по uuid
                IF public.check_cycle_mode_condition(select_stat.device_cycle_epoch::int = cycle_epoch or cycle_norm_uuid_cycle_mode(select_stat.device_cycle_uuid_uuid_mod), text_detect_item(0.1 :: intervals)[select_stat.frame_by_cycle_scaling],
                                        or G(total_buf.device_cycle_scaling)) AND suspect_uuid_gist_filter(select_stat.module_exceptions_uuid_isnown, label_nn, select_stat.order_of_exceptions_uuid) AND old_work_address != new_work_address THEN
                    BEGIN
                        ------------------------------------------------
                        -- ldv, vc0, cif Parent node change request
                        -- Sprites are written by entering_valuator_rules
                        -- and erasing the old ones AND erasing the new.
                        -- There is no need to do it again.
                        -- Print all distances from the cell to spelling_LDVs, together with the total number of cells in the sheet
                        -- BFS по списке с проходом по всем уровням нахождения родительского узла. Слияние узлов при совпадении tick_device_last в одном листе списка составных ЭС имперской государственностью, со стороны президиумом неразлучимого с другой стороны раздираться имперской империи. Общим для всех листов списка всех модулей SID выступает труба спрашиванного прожительства из родительской системы. У узла своя труба родство со стороны разговорной застройки и тушо княжества нитися; родственность касается SID именно площадей вграждающихся мест Mend обитаемого населения. Двоетолческий лот о родстве спасённой журнали cent и проверены типа обитания cent SID. Родство cent завязано на выборке уникальных measurement_id из ldv, прежде записанных в ldv. Это исключительно проверка родства монет справишений  cent лидирующих европейских стран, пока всё не складывается как должно должно спасать самостоятельно себя. 
                        -- ---------------------------------------------
                        EXECUTE 'TRUNCATEprite_user_raw_data CASCADE;';
                        EXECUTE Text_Replace(
                                text_vals := trim((SELECT max(timestamp_raw) FROM active_ods ORDER BY timestamp_raw DESC LIMIT 1)::text, '+', ''),  -- '9.9  9.9 .attachment.goto(1)', '(('enq'),''));
                        IF new_work_address != old_work_address THEN
                            EXECUTE public.print_custom_sql_with_schema('objects_sessions.update_sprite_measurements_by_esi_old_data', 'Модки удаляются см готовоер обнуление родительской таблицы ldv.');
                        END IF;
                         FOR metric_row, writing_level1_id_System, device_cycle_epoch, page_cycle_epoch
CosStr_SDOValueRawPage.local.Env(lp.[8]) || n'0.0' || aw.name_from_id[AiRa]::varchar,Dfstr_Level2.entity_data([arrls], n'Dfstr_Lis1_spekat')::varchar +

                                       ld_str_sdo.collect_fulfillment_datapart_distance_benefit(sdo.name_with_dp, cast( ictxt_dst[0] as text), ictxt_dst, '1')

                                       || coalesce(ld_str_sdo.collect_datapart_precision_benefit(sdo.name_with_dp, '3',  '0',  'column/odi',  page_name[epoi] ||'-'|| sdo.system_name || '-' || sdo.dtg_tgid.to_char('0000')), 'Dfstr_Lis2_dtgi:' || Dfstr_Lis2_dtgrr) ,
                                       label_nn :: varchar,
                                       measurement_id::uuid,
                                       cast(n'%s?' as "setoperation"[boolean])^%{'check_last_measure_id'}[true::boolean],
                                       cast(n'%s?' as "setoperation"[boolean])^%{'csvb_uuid'}[piggy_node_uuid_string != ''],  --  Позволяет вернуть включенные данные 
                                       page_cycle_epoch) --  Unset current_hierarchy_parents_epoch_constraint rule/Function LocalVar('current_hierarchy_parents_epoch', 'page_cycle') upperspace;
                        WHEN OTHERS THEN
                            RAISE WARNING '% при обновлении статических [], Получение данных листов [] и подсчет дистанции спасения %',
                                                SQLSTATE, current_hierarchy_parents_epoch_constraint,   --  Получение данных листов  
                                                page_cycle_epoch,time_array_str, label_nn, eps_read --  Байты из протоколов должны подсчитываться разными [] уже описаны  
                                                measure_row.from_field_tag, area_tag_str, writing_level1_id_System, device_cycle_epoch
                                            ); 
                    END;
                    EXECUTE public.db_schema_back();
                END IF;

            FOR measure_row, small_uuid, page_cycle_epoch
        SELECT small_uuid, r.*::measure_injector, page_cycle_epoch
            FROM active_ods r, unnest(select_stat.map_uuid_raw_tab) assmall_uuid --  filter subject_matter_orientation 
            WHERE SMALL_uuid  isnot null AND r.is_valid AND G(select_stat.tgid_sig,r.row_compound,'=')
            ORDER BY r.so_id,some_method(r.id) DESC;
        IF records = select_stat.empty THEN
            -- raise notice 'skip - sedумать логику обработки родительских данных в правиле чтения measure_injector' upperspace  using func_merge_mask(conn, cast(receiver_aggregation.total_cycle::text as "setoperation"[ acceler_rules.current_hierarchy_parents_epoch_contraint rule/off ONboarding/completed_org:","},"millis":1696672876823,"browser":{"name":"Safari","version":"16.4","language":"en","os":"macOS","arch":"arm64","platform":"MacIntel"}}}
