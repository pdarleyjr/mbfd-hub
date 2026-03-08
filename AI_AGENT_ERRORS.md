# AI AGENT ERROR LOG & PREVENTION GUIDE
## MBFD Hub — Mandatory Pre-Work Reading

> ⚠️ **CRITICAL MANDATE**: Every AI agent working on this codebase MUST read this entire file BEFORE making any changes. Failure to read this file WILL result in breaking existing functionality.

**Last Updated**: 2026-03-08  
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

### ERROR-008: `@this` Fails in Async Callbacks in Livewire v3 + Alpine.js

**Date**: 2026-03-08  
**Severity**: 🔴 CRITICAL — Livewire methods appear to be called but form fields never populate  
**File(s) Affected**: `resources/views/filament/admin/pages/equipment-intake.blade.php`

**Symptom**:
User uploads photo, clicks "Analyze with AI" button — the button disappears (processing state activates), the Worker returns a response, but form fields (Brand, Model, Serial) are never populated. No feedback is shown in the UI. Silent failure.

**Root Cause**:
In Livewire v3 with Alpine.js, `@this` is a Blade directive that compiles to `window.Livewire.find('COMPONENT_ID')`. When used inside `@push('scripts')`, the function that contains `@this` calls gets exported to global scope. When the function is called asynchronously (in a `fetch` callback), the `@this` reference **works** syntactically but the Livewire update cycle doesn't correctly re-render the form because the call happens outside the Alpine.js reactive context.

The correct approach in Livewire v3 is to use **`$wire`** — the Livewire magnetic property injected into Alpine.js components. `$wire` is available as `this.$wire` within Alpine.js `x-data` component methods and works correctly in async callbacks.

**Wrong pattern** (`@push('scripts')`):
```javascript
@push('scripts')
<script>
function equipmentScanner() {
    return {
        async analyzeAllImages() {
            const data = await response.json();
            // WRONG — @this not reliable in async @push context
            @this.processVisionResult(data.brand, data.model, data.serial);
        }
    };
}
</script>
@endpush
```

**Correct pattern** (inline `x-data`, using `$wire`):
```html
<div x-data="{
    async analyzeAllImages() {
        const data = await response.json();
        // CORRECT — $wire is Livewire v3 Alpine magic property
        await this.$wire.processVisionResult(data.brand, data.model, data.serial, data.notes);
    }
}">
```

**Fix Applied**:
1. Kept functions in `@push('scripts')` but replaced ALL `@this.xxx()` calls with `this.$wire.xxx()`
2. Added status messages visible in the UI at each step
3. Verified fix: `@this count: 0`, `$wire count: 4` in blade file

**Prevention**:
- **NEVER use `@this` in async functions** inside `@push('scripts')` in Livewire v3
- **ALWAYS use `this.$wire` inside Alpine.js `x-data` component methods** for calling Livewire
- If you must use `@push('scripts')`, use `window.Livewire.find(wireId).methodName()` instead
- Add visible status messages so silent failures are immediately obvious during testing

---

### ERROR-009: Cloudflare Workers AI — llama-3.2-11b Vision Response Format

**Date**: 2026-03-08  
**Severity**: 🟡 MEDIUM — Worker returns data but it's silently dropped due to wrong extraction  
**File(s) Affected**: `cloudflare-worker/vision-agent/src/index.ts`

**Symptom**:
Vision Worker is called and gets a 200 response from Cloudflare AI, but the extracted brand/model/serial fields are all empty strings.

**Root Cause**:
`@cf/meta/llama-3.2-11b-vision-instruct` returns the response in a nested object format:
```json
{
  "response": {
    "brand": "HURST",
    "model": "Jaws of Life",
    "serial": "",
    "confidence": "low",
    "notes": "partially visible"
  },
  "tool_calls": [],
  "usage": { "prompt_tokens": 1804, "completion_tokens": 30, ... }
}
```

**The `response` field is an OBJECT (not a string)** when the model returns properly structured JSON. Previous code only checked for string responses or `JSON.stringify(response)`.

**Fix Applied**:
Added explicit check for `response.response` being an object:
```typescript
if (response && typeof response === 'object' && response.response && typeof response.response === 'object') {
    // Model returned structured JSON directly — extract fields
    const obj = response.response as Record<string, unknown>;
    return {
        parsed: {
            brand:      String(obj.brand      ?? '').trim(),
            model:      String(obj.model      ?? '').trim(),
            serial:     String(obj.serial     ?? '').trim(),
            confidence: String(obj.confidence ?? 'low').trim(),
            notes:      String(obj.notes      ?? '').trim(),
        },
        rawText: JSON.stringify(obj),
    };
}
```

**Cloudflare AI API Format Reference (llama-3.2-11b-vision)**:
```typescript
// CORRECT format per Cloudflare documentation:
const response = await env.AI.run('@cf/meta/llama-3.2-11b-vision-instruct', {
    messages: [{
        role: 'user',
        content: [
            { type: 'text', text: YOUR_PROMPT },
            { type: 'image_url', image_url: { url: 'data:image/jpeg;base64,...' } },
        ],
    }],
    max_tokens: 512,
});
// response.response can be string OR object depending on model output
```

**Prevention**:
- Always handle BOTH `response.response` as string AND as object
- Test with a real image during Worker development
- Include `raw_text` in the API response for debugging

### ERROR-018: Filament v3 Widgets as Livewire Children — Stale State on Parent Property Change
**Date**: 2026-03-08
**Root cause**: Filament v3 widgets are separate Livewire components. Passing new session props via make() sets INITIAL state only. When parent page re-renders after wire:click, widgets may NOT remount — they keep old session data.
**Wrong approach**: wire:key on HTML div does NOT force widget remounting
**Correct fix**: Remove Livewire widgets from pages with reactive switching. Compute all data in getViewData() (always fresh) and render as plain HTML in blade.
**Commits**: d167eb45

---

### ERROR-019: Livewire v3 `dispatch()` vs Browser Events for Alpine.js

**Date**: 2026-03-08  
**Severity**: 🟡 MEDIUM — camera buffer never clears after save; user must manually reset  
**File(s) Affected**: `app/Filament/Admin/Pages/EquipmentIntake.php`, `equipment-intake.blade.php`

**Symptom**:
After clicking "Approve & Save" and a successful Snipe-IT submission, the camera thumbnail strip still shows the old photos. The user has to manually click "Clear All" before the next scan.

**Root Cause**:
`resetScanForm()` only resets PHP-side Livewire properties — it does NOT clear Alpine.js local state (`imagePreviews`, `imageFiles`). The frontend and backend are separate; you must use a browser event to bridge them.

**Fix Applied**:
1. In PHP `approveAndSave()`, after `$this->resetScanForm()`:
   ```php
   $this->dispatch('equipment-saved');
   ```
2. In blade, add `.window` event listener to the Alpine.js x-data wrapper:
   ```html
   <div x-data="equipmentScanner()" @equipment-saved.window="resetCapture()">
   ```

**How it works (Livewire v3)**:
- `$this->dispatch('event-name')` dispatches a browser `CustomEvent` on the window
- Alpine.js `@event-name.window="handler()"` catches it globally
- The `resetCapture()` method clears `imagePreviews` and `imageFiles` arrays on the Alpine component

**Prevention**:
 - When you need to trigger Alpine.js logic from a PHP Livewire method, ALWAYS use `$this->dispatch('event')` + `@event.window` in Alpine — NEVER try to call Alpine methods from PHP directly
- Document the event contract: `equipment-saved` → Alpine `resetCapture()` clears camera buffer

---

### ERROR-020: Cloudflare Vectorize — Scanned/Image PDFs Yield Zero Vectors

**Date**: 2026-03-08  
**Severity**: 🟡 MEDIUM — ingestion silently skips source, chatbot has no knowledge for that apparatus  
**File(s) Affected**: `scripts/ai/ingest_manuals.py`, `mbfd-rag-index`

**Symptom**:
`L1_L11_manual.pdf` was processed by PyMuPDF but extracted 0 characters. The source was silently skipped. The chatbot has no L1/L11 knowledge despite the file being provided.

**Root Cause**:
The `L1_L11_manual.pdf` file is a scanned image PDF (no OCR/text layer). PyMuPDF's `get_text("text")` and `get_text("blocks")` both return empty strings for image-only pages.

**Fix Applied**:
Script now detects < 100 characters extracted and prints a clear warning instead of producing empty chunks. Re-ingest once a text-based or OCR'd PDF is available.

**How to Fix L1/L11**:
1. Obtain an OCR'd version of `L1_L11_manual.pdf` (use Adobe Acrobat, AWS Textract, or similar)
2. Place at `C:\Users\Peter Darley\Downloads\L1_L11_manual.pdf`
3. Re-run: `scripts\ai\.venv\Scripts\python.exe scripts\ai\ingest_manuals.py`

**Prevention**:
- Always check extracted character count before ingesting
- For fire department manuals, assume scanned PDFs are possible
- The ingestion script `ingest_manuals.py` already warns about this — check its output before considering ingestion complete
