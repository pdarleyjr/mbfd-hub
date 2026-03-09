# AI AGENT ERROR LOG & PREVENTION GUIDE
## MBFD Hub — Mandatory Pre-Work Reading

> ⚠️ **CRITICAL MANDATE**: Every AI agent working on this codebase MUST read this entire file BEFORE making any changes. Failure to read this file WILL result in breaking existing functionality.

**Last Updated**: 2026-03-09  
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
**Root cause**: Filament v3 widgets are separate Livewire components. Passing new session props via make() sets INITIAL state only. When parent page re-renders after wire:click, widgets may NOT remount — they keep old session data.
**Wrong approach**: wire:key on HTML div does NOT force widget remounting
**Correct fix**: Remove Livewire widgets from pages with reactive switching. Compute all data in getViewData() (always fresh) and render as plain HTML in blade.
**Commits**: d167eb45

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
