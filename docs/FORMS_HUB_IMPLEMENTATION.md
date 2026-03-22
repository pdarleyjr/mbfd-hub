# Forms Hub Implementation Guide

## Overview

The MBFD Forms Hub replaces the old "Daily Stations" tab with a modern, user-friendly interface for submitting two types of forms:

1. **Big Ticket Item Request** - For requesting large equipment/furniture
2. **Station Inventory Form** - For ordering supplies from a curated catalog

## Changes Made

### Frontend Changes

| File | Description |
|------|-------------|
| [`resources/views/welcome.blade.php`](../resources/views/welcome.blade.php) | Renamed "Daily Checkout" card to "MBFD Forms" |
| [`resources/js/daily-checkout/src/components/ApparatusList.tsx`](../resources/js/daily-checkout/src/components/ApparatusList.tsx) | Added Forms Hub tab to navigation |
| [`resources/js/daily-checkout/src/components/FormsHub.tsx`](../resources/js/daily-checkout/src/components/FormsHub.tsx) | New Forms Hub landing with 2 action cards |
| [`resources/js/daily-checkout/src/components/BigTicketRequestForm.tsx`](../resources/js/daily-checkout/src/components/BigTicketRequestForm.tsx) | Multi-step wizard for big ticket requests |
| [`resources/js/daily-checkout/src/components/StationInventoryForm.tsx`](../resources/js/daily-checkout/src/components/StationInventoryForm.tsx) | Tabbed inventory ordering form |
| [`resources/js/daily-checkout/src/types.ts`](../resources/js/daily-checkout/src/types.ts) | Added BigTicketRequest & Inventory types |
| [`resources/js/daily-checkout/src/utils/api.ts`](../resources/js/daily-checkout/src/utils/api.ts) | Added API methods for form submissions |
| [`resources/js/daily-checkout/src/App.tsx`](../resources/js/daily-checkout/src/App.tsx) | Added routes for forms hub |

### Backend Changes

| File | Description |
|------|-------------|
| [`database/migrations/2026_02_03_000001_create_big_ticket_requests_table.php`](../database/migrations/2026_02_03_000001_create_big_ticket_requests_table.php) | BigTicketRequest migration |
| [`database/migrations/2026_02_03_000002_create_station_inventory_submissions_table.php`](../database/migrations/2026_02_03_000002_create_station_inventory_submissions_table.php) | StationInventorySubmission migration |
| [`app/Models/BigTicketRequest.php`](../app/Models/BigTicketRequest.php) | BigTicketRequest model |
| [`app/Models/StationInventorySubmission.php`](../app/Models/StationInventorySubmission.php) | StationInventorySubmission model |
| [`app/Http/Controllers/Api/BigTicketRequestController.php`](../app/Http/Controllers/Api/BigTicketRequestController.php) | API controller for big ticket requests |
| [`app/Http/Controllers/Api/StationInventoryController.php`](../app/Http/Controllers/Api/StationInventoryController.php) | API controller for inventory submissions |
| [`routes/api.php`](../routes/api.php) | Added API routes |
| [`resources/views/pdf/station-inventory.blade.php`](../resources/views/pdf/station-inventory.blade.php) | PDF template for inventory orders |

## API Endpoints

### Big Ticket Requests
```
POST /api/big-ticket-requests
GET /api/stations/{station}/big-ticket-requests
DELETE /api/big-ticket-requests/{id}
```

### Station Inventory
```
GET /api/station-inventory/categories
POST /api/station-inventory-submissions
GET /api/stations/{station}/station-inventory-submissions
GET /api/station-inventory-submissions/{id}/pdf
```

## Database Tables

### big_ticket_requests
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| station_id | bigint | Foreign key to stations |
| room_type | string | kitchen, common_areas, dorms, apparatus_bay, watch_office |
| room_label | string | Optional custom room name |
| items | json | Array of selected items |
| other_item | string | Optional custom item |
| notes | text | Optional notes |
| created_by | bigint | Foreign key to users |
| timestamps | | created_at, updated_at |

### station_inventory_submissions
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| station_id | bigint | Foreign key to stations |
| items | json | Array of items with quantities |
| pdf_path | string | Path to generated PDF |
| created_by | bigint | Foreign key to users |
| timestamps | | created_at, updated_at |

## Installation Steps

1. **Run migrations:**
   ```bash
   php artisan migrate
   ```

2. **Install PDF package (if not installed):**
   ```bash
   composer require barryvdh/laravel-dompdf
   ```

3. **Build frontend assets:**
   ```bash
   cd resources/js/daily-checkout
   npm install
   npm run build
   ```

4. **Clear caches:**
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

## Testing Checklist

### Landing Page
- [ ] "MBFD Forms" card displays with new icon and description
- [ ] "Open MBFD Forms" button navigates to Forms Hub

### Forms Hub
- [ ] Both action cards display correctly
- [ ] Big Ticket Request card has correct icon and description
- [ ] Station Inventory card has correct icon and description
- [ ] Clicking a card navigates to the appropriate form

### Big Ticket Request Form
- [ ] Step 1: Station selector works
- [ ] Step 2: Room type selector works (5 options)
- [ ] Step 3: Item selection works (multi-select)
- [ ] Step 4: Other item and notes fields work
- [ ] Submit creates record in database
- [ ] Success message displays

### Station Inventory Form
- [ ] 5 category tabs display correctly
- [ ] Each category shows correct items with max quantities
- [ ] Quantity selectors work (0 to max)
- [ ] Submit generates PDF and saves submission
- [ ] PDF download works
- [ ] Success message displays with PDF link

## Big Ticket Items List

| Room Type | Available Items |
|-----------|-----------------|
| Kitchen | Refrigerator, Stove/Oven, Dishwasher, Microwave, Coffee Maker, Toaster, Blender, Ice Maker |
| Common Areas | Sofa, Armchair, Coffee Table, End Table, TV Stand, Bookshelf, Floor Lamp, Area Rug |
| Dorms | Bed (Bunk/Single), Mattress, Dresser, Nightstand, Desk, Chair, Wardrobe, Mirror |
| Apparatus Bay | Workbench, Tool Cabinet, Storage Shelving, Heavy Duty Shelving, Floor Crane |
| Watch Office | Desk, Chair, Filing Cabinet, Bookshelf, Computer Desk, Server Rack |

## Inventory Categories

1. **Garbage/Paper goods** - Kitchen liners, trash can liners, paper towels, tissues, plates, cups, utensils, napkins
2. **Floors** - Dust mops, wet mops, buckets, brooms, floor signs, vacuum cleaner, carpet spotter
3. **Laundry** - Detergent, bleach, fabric softener, baskets, hangers
4. **Bathroom & Cleaners** - Toilet bowl cleaner, bathroom cleaner, glass cleaner, disinfectant wipes, hand soap, shower curtains, brushes, plungers
5. **Kitchen** - Dish soap, dishwasher detergent, scouring pads, sponges, aluminum foil, plastic wrap, bags, containers

## Admin Integration

In Filament admin, navigate to:
- **Stations** → Select a station → **Big Ticket Requests** tab to view requests
- **Stations** → Select a station → **Inventory Submissions** tab to view submissions

## Troubleshooting

### PDF not generating
- Ensure `barryvdh/laravel-dompdf` is installed
- Check storage permissions: `chmod -R 755 storage`
- Verify PDF view exists at `resources/views/pdf/station-inventory.blade.php`

### Forms not submitting
- Check API routes are registered: `php artisan route:list | grep station-inventory`
- Verify CORS settings allow frontend domain
- Check Laravel logs for errors: `storage/logs/laravel.log`

### Navigation issues
- Ensure React Router is configured correctly in `App.tsx`
- Clear browser cache and rebuild assets
- Check for JavaScript console errors