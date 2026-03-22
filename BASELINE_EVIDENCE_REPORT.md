# Baseline Evidence Report - Phase 0.2-0.5
**Generated:** 2026-01-25 20:22 UTC  
**Branch:** fix/audit-report-implementation-20260125  
**Production URL:** https://support.darleyplex.com

## 1. Git Setup Status ✅

- **Current Branch:** `fix/audit-report-implementation-20260125` (newly created)
- **Remote:** origin → `https://github.com/pdarleyjr/mbfd-hub.git`
- **Git Status:** Clean (PROJECT_SUMMARY.md has uncommitted changes from main)
- **Recent Commits:**
  - 8e6add5a - Fix EquipmentItem.php syntax error
  - f3cfbaa2 - Fix StockMutation model class not found error
  - 891adf70 - Consolidate documentation
  - 1fcc8748 - Add automated deployment workflow

## 2. Production Baseline Testing

### 2.1 `/admin/login` - ⚠️ WARNINGS FOUND

**Status:** Page loads successfully  
**Screenshot:** `screenshots/baseline-admin-login.png`

**Console Warnings:**
- ❌ **Mixed Content (4 warnings):** HTTP resources loaded on HTTPS page
  - Image: `http://support.darleyplex.com/images/large_mbfd_logo_no_bg.png` 
  - Auto-upgraded to HTTPS
- ❌ **Favicon Blocked:** `http://support.darleyplex.com/images/small_mbfd_logo_no_bg.png`
  - Blocked due to mixed content (not auto-upgraded)
- ✅ **Filament Shortcuts:** Initialized successfully

**Page Elements:** Login form renders correctly with email/password fields, remember me checkbox, and sign in button.

---

### 2.2 `/admin` (Dashboard) - 🔴 CRITICAL 500 ERROR

**Status:** Page loaded but contains embedded 500 error dialog  
**Screenshot:** `screenshots/baseline-admin-500-error.png`

**Critical Error Details:**
```
Illuminate\Database\QueryException
SQLSTATE[42703]: Undefined column: 7 ERROR: column stock_mutations.stockable_type does not exist
LINE 1: ...mount") as aggregate from "stock_mutations" where "stock_mut...

SQL: select sum("amount") as aggregate from "stock_mutations" 
     where "stock_mutations"."stockable_type" = App\\Models\\EquipmentItem 
     and "stock_mutations"."stockable_id" = 4 
     and "stock_mutations"."stockable_id" is not null 
     and "created_at" <= 2026-01-25 20:21:30

Connection: pgsql
```

**Error Source:**
- File: [`app/Filament/Widgets/InventoryOverviewWidget.php`](app/Filament/Widgets/InventoryOverviewWidget.php:26)
- Line 26: `$lowStockItems = $allEquipment->filter(fn($item) => $item->stock <= $item->reorder_min);`
- **Root Cause:** Missing database column `stockable_type` in `stock_mutations` table
  - The widget tries to calculate stock levels using polymorphic relationship
  - Database schema doesn't match model expectations

**Additional Console Errors:**
- ❌ **Alpine.js Errors (4):** "expanded is not defined"
  - ReferenceError in sidebar collapse/expand functionality
- ❌ **Mixed Content:** Same image issues as login page
- ❌ **Failed Resource:** 500 response from `/livewire/update` endpoint

**Network Activity:**
- POST to `/livewire/update` returned 500 error
- Request attempted to lazy-load `InventoryOverviewWidget`

**Page Partial Functionality:**
- ✅ Dashboard layout renders
- ✅ Navigation sidebar displays correctly
- ✅ Stats widgets show: Total Apparatus (25), Out of Service (25), Open Defects (0)
- ✅ Recent & Pending Todos widget (shows "No pending todos")
- ❌ Inventory Overview Widget fails to load (500 error)

---

### 2.3 `/daily` (Daily Checkout) - ✅ SUCCESS

**Status:** Page loads successfully  
**Screenshot:** `screenshots/baseline-daily-page.png`

**Console Messages:**
- ✅ **PWA Service Worker:** Registered successfully
- ⚠️ **Manifest Icon Warning:** Icon load error (non-critical)

**Page Functionality:**
- ✅ Displays 25 apparatuses with inspection links
- ✅ All apparatus cards show: Name, Unit ID, Type, "Start Inspection" button
- ✅ Navigation works (links to `/daily/apparatus/{slug}`)

**Sample Apparatuses Listed:**
- E 21 (002-16, Engine)
- E 11 (002-14, Engine - Out of Service)
- E 31 (002-10, Engine)
- 1033 (1033, Rescue - Out of Service)
- L 11, L 1, L 3 (Ladders)
- Various Rescue units (R 1, R 11, R 2, etc.)
- Air Trucks (A 1, A 2)

---

### 2.4 `/api/public/apparatuses` - ✅ SUCCESS

**Status:** API endpoint returns data successfully  
**Response:** Valid JSON array with 25 apparatus objects

**Sample Data Structure:**
```json
{
  "id": 4,
  "unit_id": "1",
  "name": "E 21",
  "type": "Engine",
  "vehicle_number": "002-16",
  "status": "Active",
  "slug": "e-21",
  "mileage": 0,
  "notes": "",
  "created_at": "2026-01-25T18:20:01.000000Z",
  "updated_at": "2026-01-25T18:20:01.000000Z"
}
```

**Data Quality:**
- ✅ All required fields present
- ✅ 25 apparatus records returned
- ⚠️ Most apparatus have empty VIN, make, model fields
- ⚠️ Year is null for all records
- ✅ Status field populated ("Active" or "Out of Service")

---

## 3. Identified Issues Summary

### 🔴 CRITICAL (Must Fix)
1. **Database Schema Error:** Missing `stockable_type` column in `stock_mutations` table
   - Breaks InventoryOverviewWidget on admin dashboard
   - Prevents accurate inventory tracking
   - **Impact:** High - Core functionality broken

### ⚠️ HIGH (Should Fix)
2. **Mixed Content Security Issues:**
   - HTTP resources on HTTPS pages
   - Favicon blocked by browser
   - **Impact:** Medium - Security warnings, poor user experience

3. **Alpine.js Reference Errors:**
   - "expanded" variable undefined in sidebar
   - Affects sidebar collapse/expand functionality
   - **Impact:** Medium - UI functionality degraded

### ℹ️ LOW (Nice to Fix)
4. **Data Quality:**
   - Missing vehicle metadata (VIN, make, model, year)
   - **Impact:** Low - Feature completeness

5. **PWA Manifest Icon:**
   - Icon loading warning
   - **Impact:** Low - PWA installation experience

---

## 4. Production Health Score

| Component | Status | Score |
|-----------|--------|-------|
| Authentication | ✅ Working | 100% |
| Admin Dashboard | 🔴 Partial | 40% |
| Daily Checkout | ✅ Working | 95% |
| Public API | ✅ Working | 100% |
| **Overall** | ⚠️ **Degraded** | **84%** |

---

## 5. Database Analysis

### Suspected Missing Migration
The error indicates the `stock_mutations` table exists but is missing polymorphic relationship columns:
- Missing: `stockable_type` (string)
- Likely missing: proper polymorphic indexes

**Expected Schema:**
```sql
CREATE TABLE stock_mutations (
    id BIGSERIAL PRIMARY KEY,
    stockable_type VARCHAR(255) NOT NULL,  -- MISSING!
    stockable_id BIGINT NOT NULL,          -- EXISTS
    amount INTEGER NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## 6. Sentry/Error Tracking

- **Sentry Integration:** Configured (based on [`config/sentry.php`](config/sentry.php))
- **Live Errors Captured:** At least 1 (the 500 error from InventoryOverviewWidget)
- **Error Frequency:** Unknown (requires Sentry dashboard access)

---

## 7. Next Steps (Phase 1 - Diagnosis)

1. **Investigate Database Schema:**
   - Check migration files for `stock_mutations` table
   - Verify if migration was run or rolled back
   - Identify missing columns

2. **Fix Mixed Content:**
   - Update asset URLs to use HTTPS/protocol-relative URLs
   - Fix image references in app configuration

3. **Debug Alpine.js Errors:**
   - Review sidebar component for missing variable initialization
   - Check Filament panel provider configuration

4. **Monitor Sentry:**
   - Check for additional errors
   - Verify error frequency and user impact

---

## 8. Baseline Artifacts

### Screenshots Captured
- ✅ `screenshots/baseline-admin-login.png` (Playwright temp)
- ✅ `screenshots/baseline-admin-500-error.png` (Playwright temp)
- ✅ `screenshots/baseline-daily-page.png` (Playwright temp)

### Console Logs
- All critical console messages documented above
- No unexpected JavaScript errors beyond Alpine.js issues

**Baseline verification complete. Ready for Phase 1: Root Cause Analysis.**
