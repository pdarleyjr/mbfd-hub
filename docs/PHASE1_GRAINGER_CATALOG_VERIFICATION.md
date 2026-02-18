# Phase 1: Grainger Catalog Integration - Verification Checklist

**Deployment Date:** 2026-02-16
**VPS Branch:** feature/all-features-clean  
**Status:** ✅ Deployed - Testing Required

---

## Overview

Phase 1 implements the Grainger catalog integration foundation, enabling administrators and members to:
- View vendor product information for all station supply inventory items
- Access a static HTML catalog with 39 matched Grainger products
- Edit vendor mappings through the admin panel

This phase sets the foundation for Phase 2 (Replenishment Dashboard) and Phase 3 (Email Center).

---

## Deployment Summary

### Files Added/Modified

**Backend (Laravel/Filament):**
- ✅ `config/features.php` - Feature flag configuration
- ✅ `app/Models/InventoryItem.php` - Added vendor fields to fillable array
- ✅ `app/Filament/Resources/InventoryItemResource.php` - Added Vendor Information section + Grainger Catalog header action
- ✅ `database/migrations/2026_02_16_210000_add_vendor_fields_and_ordering_tables.php` - Database schema changes
- ✅ `database/seeders/VendorProductMappingSeeder.php` - Vendor product mapping seeder
- ✅ `app/Models/StationSupplyOrder.php` - Order workflow model (Phase 2 prep)
- ✅ `app/Models/StationSupplyOrderLine.php` - Order line items model (Phase 2 prep)
- ✅ `app/Models/Communication.php` - Email log model (Phase 3 prep)

**Frontend (React Daily Checkout):**
- ✅ `resources/js/daily-checkout/src/components/InventoryCountPage.tsx` - Added Catalog button

**Static Assets:**
- ✅ `public/catalogs/station-supply-grainger.html` - Static Grainger product catalog (39 products)

### Database Changes

**Migration Executed:** `2026_02_16_210000_add_vendor_fields_and_ordering_tables`

**Tables Modified:**
- `inventory_items` - Added columns: `vendor_name`, `vendor_url`, `vendor_sku` (all nullable)

**Tables Created (Phase 2/3 prep):**
- `station_supply_orders` - Supply order workflow tracking
- `station_supply_order_lines` - Order line items with quantity tracking
- `communications` - Email communications log

**Seeder Run:** `VendorProductMappingSeeder`
- ✅ Updated 39 inventory items with Grainger vendor information
- ✅ 0 items not found (100% match rate)

---

## Phase 1 Testing Checklist

### 1. Admin Panel - Supply Items Management

**Test Case 1.1: View vendor information in Supply Items table**

1. Navigate to: `https://support.darleyplex.com/admin/inventory-items`
2. Login as admin: `MiguelAnchia@miamibeachfl.gov` / `Penco1`

**Expected Results:**
- ✅ Table displays new "Vendor" column (between "Low Threshold" and "Active")
- ✅ Vendor column shows "Open" link for items with vendor URLs
- ✅ Clicking "Open" link opens Grainger product page in new tab
- ✅ Items without vendor URLs show "—" placeholder

**Test Items (sample):**
- "Small Garbage bag rolls, 25 bags/roll" → Should show "Open" link
- "Toilet Paper (500 sheets/roll)" → Should show "Open" link

---

**Test Case 1.2: Grainger Catalog header action**

1. While on Supply Items page (`/admin/inventory-items`)

**Expected Results:**
- ✅ Header action bar shows "Grainger Catalog" button with external link icon
- ✅ Clicking "Grainger Catalog" opens `/catalogs/station-supply-grainger.html` in new tab
- ✅ Button is visible (feature flag `FEATURE_GRAINGER_LINKS=true` in `.env`)

---

**Test Case 1.3: Edit vendor information for existing item**

1. Navigate to Supply Items page
2. Click "Edit" action on any item (e.g., "Small Garbage bag rolls")
3. Scroll to "Vendor Information" section

**Expected Results:**
- ✅ Form displays collapsible "Vendor Information" section with 3 fields:
  - Vendor Name (text input, default "Grainger")
  - Vendor SKU (text input)
  - Vendor Product URL (URL input)
- ✅ Section is collapsed by default
- ✅ Existing values are populated (e.g., Vendor Name: "Grainger", Vendor SKU: "5WG03")
- ✅ Can edit and save changes successfully
- ✅ Changes persist after save

---

**Test Case 1.4: Create new item with vendor information**

1. Navigate to Supply Items page
2. Click "New" button
3. Fill in required fields (Name, Category, Par Quantity)
4. Expand "Vendor Information" section
5. Enter vendor details:
   - Vendor Name: "Test Vendor"
   - Vendor SKU: "TEST-SKU-123"
   - Vendor Product URL: "https://example.com/product"
6. Save

**Expected Results:**
- ✅ Item creates successfully with vendor information
- ✅ Vendor column in table shows "Open" link
- ✅ Clicking link opens https://example.com/product in new tab
- ✅ Edit form shows saved vendor information

---

### 2. Member View - Daily Checkout Inventory

**Test Case 2.1: Catalog button visibility**

1. Navigate to: `https://support.darleyplex.com/daily/forms-hub/station-inventory`
2. Enter PIN: (any valid station PIN)
3. Complete station/user info step
4. Proceed to Inventory Count page

**Expected Results:**
- ✅ Header shows "Catalog" button with icon (next to Logout button)
- ✅ Button styling: green background (`bg-green-700`), small size
- ✅ Button displays icon (shopping bag/catalog icon)

---

**Test Case 2.2: Catalog button functionality**

1. While on Inventory Count page
2. Click "Catalog" button

**Expected Results:**
- ✅ Opens `/catalogs/station-supply-grainger.html` in new tab
- ✅ Catalog page loads successfully
- ✅ Page displays 39 Grainger products with:
  - Product images
  - Product names
  - Grainger SKU
  - Direct "View on Grainger" link
- ✅ Clicking any "View on Grainger" link performs direct Grainger search

---

**Test Case 2.3: Inventory workflow still functional**

1. Complete a full inventory count workflow
2. Verify no regressions

**Expected Results:**
- ✅ All existing inventory features work:
  - Category selection
  - Item quantity input
  - Item quantity updates correctly
  - "Mark Complete" button functions
  - Supply request workflow still works
  - Data saves to database successfully

---

### 3. Grainger Catalog Static HTML Page

**Test Case 3.1: Direct catalog access**

1. Navigate directly to: `https://support.darleyplex.com/catalogs/station-supply-grainger.html`

**Expected Results:**
- ✅ Page loads without 404 error
- ✅ Page displays professional layout:
  - Miami Beach Fire Department branding/header
  - "Station Supply Catalog - Grainger Products" title
  - Product grid with 39 items
- ✅ Each product card shows:
  - Product image (or placeholder)
  - Product name
  - Grainger SKU
  - "View on Grainger" button

---

**Test Case 3.2: Grainger deep links functional**

1. From catalog page, click "View on Grainger" on 3 different products
2. Verify Grainger search works

**Expected Results:**
- ✅ Each click opens Grainger search page in new tab
- ✅ URL format: `https://www.grainger.com/search?searchQuery={SKU}`
- ✅ Grainger search returns correct product (or close match)

**Sample SKUs to test:**
- 5WG03 (Small garbage bags)
- 32GV95 (Toilet paper)
- 5NY79 (Rubbermaid WaveBrake bucket)

---

### 4. Feature Flag Control

**Test Case 4.1: Disable Grainger links feature**

1. SSH into VPS: `ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" root@145.223.73.170`
2. Edit `/root/mbfd-hub/.env`:
   ```bash
   nano /root/mbfd-hub/.env
   ```
3. Set: `FEATURE_GRAINGER_LINKS=false`
4. Clear Laravel cache:
   ```bash
   cd /root/mbfd-hub
   docker compose exec laravel.test php artisan optimize:clear
   ```

**Expected Results:**
- ✅ "Grainger Catalog" header action disappears from Supply Items page
- ✅ Vendor column still displays in table (data persists)
- ✅ Member "Catalog" button still shows (hardcoded; may want to feature-flag this too in Phase 2)

**Cleanup:** Re-enable feature flag (`FEATURE_GRAINGER_LINKS=true`) and clear cache again.

---

### 5. Database Integrity

**Test Case 5.1: Verify vendor data populated**

1. SSH into VPS
2. Connect to PostgreSQL:
   ```bash
   docker compose exec pgsql psql -U mbfd_user -d mbfd_hub
   ```
3. Query inventory items with vendor data:
   ```sql
   SELECT name, sku, vendor_name, vendor_sku, vendor_url 
   FROM inventory_items 
   WHERE vendor_url IS NOT NULL 
   LIMIT 5;
   ```

**Expected Results:**
- ✅ Query returns 5 rows (or up to 39 total)
- ✅ All returned rows have `vendor_name = 'Grainger'`
- ✅ All returned rows have valid `vendor_sku` (e.g., "5WG03", "31DK79")
- ✅ All returned rows have valid `vendor_url` starting with `https://www.grainger.com/search?searchQuery=`

---

**Test Case 5.2: Verify order workflow tables created**

Still in psql:
```sql
\dt station_supply_orders
\dt station_supply_order_lines
\dt communications
```

**Expected Results:**
- ✅ All 3 tables exist
- ✅ Tables are empty (no data yet; Phase 2/3 will populate)

**Cleanup:** Exit psql: `\q`

---

### 6. Regression Testing - Critical Paths

**Test Case 6.1: Admin panel core functionality**

1. Test core admin features to ensure no regressions:
   - Login still works
   - Dashboard loads
   - Stations resource loads
   - Apparatus resource loads
   - Users resource loads
   - Chatify still loads at `/admin/chatify`

**Expected Results:**
- ✅ All admin panel features work as before
- ✅ No 500 errors
- ✅ No JavaScript console errors
- ✅ No CSRF token issues

---

**Test Case 6.2: Member daily checkout workflow**

1. Test full daily checkout workflow:
   - Navigate to `/daily/`
   - Select station
   - Complete apparatus inspection
   - Complete inventory count
   - Submit

**Expected Results:**
- ✅ All steps complete successfully
- ✅ No errors or broken functionality
- ✅ Data saves to database
- ✅ Success page displays

---

## Known Issues / Limitations

### Phase 1 Scope

**What Phase 1 DOES:**
- ✅ Displays vendor information in admin panel
- ✅ Allows manual editing of vendor mappings
- ✅ Provides static Grainger catalog for reference
- ✅ Links directly to Grainger product search

**What Phase 1 DOES NOT:**
- ❌ Automated low-stock detection (Phase 2)
- ❌ Replenishment order creation workflow (Phase 2)
- ❌ Email draft generation (Phase 3)
- ❌ Gmail sending integration (Phase 3)
- ❌ Real-time Grainger API pricing/availability (not planned)

### Technical Notes

1. **Catalog is static HTML:** The `/catalogs/station-supply-grainger.html` file is manually maintained. If you add new products or change SKUs, you must update both:
   - The seeder (`VendorProductMappingSeeder.php`)
   - The HTML catalog file

2. **Grainger links are search-based:** Direct product page links would require Grainger item IDs (different from SKUs). Search-based links are more resilient to catalog changes.

3. **Member catalog button is always visible:** Unlike the admin "Grainger Catalog" button, the member "Catalog" button in inventory is not feature-flagged. Consider adding `config('features.grainger_links')` check in Phase 2 if needed.

4. **Vendor fields are optional:** All vendor fields are nullable, so existing workflows continue to work for items without vendor mappings.

---

## Rollback Procedure

If Phase 1 causes issues:

1. **Quick rollback (zero downtime):**
   ```bash
   ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" root@145.223.73.170
   cd /root/mbfd-hub
   git checkout main
   docker compose exec laravel.test php artisan migrate:rollback --step=1 --force
   docker compose exec laravel.test php artisan optimize:clear
   ```

2. **Verify rollback:**
   - Check `/admin/inventory-items` → vendor column should be gone
   - Catalog link should be gone
   - Member catalog button will remain (frontend rebuild required to remove)

3. **If React changes need rollback:**
   ```bash
   cd resources/js/daily-checkout
   git checkout main -- src/components/InventoryCountPage.tsx
   npm run build
   ```

---

## Phase 2 Preview

**Next Steps (after Phase 1 verified):**

1. **Replenishment Dashboard Resource:**
   - Create `StationSupplyOrderResource.php`
   - Display all low-stock items across stations
   - Bulk actions: "Generate Order", "Mark Ordered", "Mark Delivered"

2. **Automated Low-Stock Detection:**
   - Console command to check inventory against par/threshold
   - Create draft supply orders automatically
   - Admin review/approval workflow

3. **Order Line Item Management:**
   - Link inventory items to order lines
   - Quantity suggestions based on par/current levels
   - Admin can edit quantities before ordering

---

## Sign-Off

### Testing Completed By:

- [ ] **Tester Name:** _________________  
  **Date:** _________________  
  **Result:** ✅ Pass / ❌ Fail

### Issues Found:

| Issue # | Description | Severity | Status |
|---------|-------------|----------|--------|
| 1 | | | |
| 2 | | | |

---

## Contact

**Developer:** Kilo Code (AI Assistant)  
**Project Lead:** Peter Darley  
**Production URL:** https://support.darleyplex.com  
**GitHub Repo:** https://github.com/pdarleyjr/mbfd-hub  
**Branch:** feature/all-features-clean
