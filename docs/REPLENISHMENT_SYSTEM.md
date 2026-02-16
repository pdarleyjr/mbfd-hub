# Replenishment Dashboard System

## Purpose
Centralized admin view of all low-stock items across stations with bulk ordering workflows.

## Feature Flag
```env
FEATURE_REPLENISHMENT_DASHBOARD=true
```

Set in `.env` to enable/disable the dashboard.

## Database Schema

### Tables
1. **station_supply_orders** - Order headers
   - Tracks who created orders, send method, status, vendor
   - Status flow: `draft` → `sent` OR `manual_ordered`
   
2. **station_supply_order_lines** - Order line items
   - Links orders to stations, inventory items, and quantities
   - Status flow: `pending` → `ordered` → `delivered`

## Admin Interface

### Access
- **Path**: `/admin/replenishment-dashboard`
- **Navigation**: Operations → Replenishment Dashboard
- **Permission**: Requires admin access and feature flag enabled

### Features
- **Low-Stock Detection**: Automatically filters items below 50% PAR
- **Station View**: See which stations need supplies
- **Vendor Links**: Click to open Grainger product pages
- **Bulk Actions**:
  1. Generate Order Email Draft (Phase 3 integration)
  2. Mark Ordered (Manual) - For phone/fax orders
  3. Mark Delivered - Updates on-hand counts

## Commands

### Detect Low Stock
```bash
php artisan inventory:detect-low-stock
```

Scans all stations and displays low-stock items in table format with current counts and percentages.

## Workflow

### 1. Detection
System automatically filters low-stock items (< 50% PAR or custom threshold set on inventory item).

### 2. Selection
Admin selects items needing replenishment from dashboard table.

### 3. Ordering
Choose bulk action:
- **Generate Draft**: Creates draft order for email sending (Phase 3)
- **Mark Ordered (Manual)**: For orders placed by phone/fax

### 4. Delivery
When supplies arrive:
- Select "Mark Delivered" bulk action
- Enter quantity delivered for each item
- System updates `station_inventory_items.quantity`
- Items removed from low-stock view

## Status Tracking

### Order Status
- `draft` - Email draft created, not sent
- `sent` - Email sent successfully (Phase 3)
- `manual_ordered` - Ordered via phone/fax
- `failed` - Email send failed (Phase 3)

### Line Item Status
- `pending` - In draft order
- `ordered` - Order placed with vendor
- `delivered` - Received and counted
- `canceled` - Order line canceled

## Integration Points

### Existing Systems (Read-Only)
- `station_inventory_items` - Current on-hand counts
- `inventory_items` - Master item catalog with vendor URLs
- `stations` - Station information

### New Systems (Write)
- `station_supply_orders` - Order tracking
- `station_supply_order_lines` - Line item tracking

### Future Integration (Phase 3)
- Gmail OAuth for email order sending
- Email templates for vendor orders
- Delivery confirmation emails

## Testing Checklist

1. ✅ Enable feature flag: `FEATURE_REPLENISHMENT_DASHBOARD=true`
2. ✅ Run migrations: `php artisan migrate`
3. ✅ Run detection: `php artisan inventory:detect-low-stock`
4. ✅ Visit admin dashboard: `/admin/replenishment-dashboard`
5. ✅ Test bulk action: "Mark Ordered (Manual)"
6. ✅ Verify order created in database
7. ✅ Test bulk action: "Mark Delivered"
8. ✅ Verify quantities updated
9. ✅ Disable feature flag, verify dashboard hidden

## Maintenance

### View Orders
Orders are stored in `station_supply_orders` table. Future enhancement may add a dedicated Orders resource.

### Adjust Thresholds
- Global: Set `low_threshold` on `inventory_items` records
- Per-station: Future enhancement for station-specific PAR overrides

### Vendor Changes
Update `inventory_items.vendor_url` to change product links.
