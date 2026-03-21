# MBFD Hub Snipe-IT Integration Project Brief

**Last Updated**: 2026-03-06  
**Project Status**: Requirements Gathered - Ready for Implementation

---

## Executive Summary

This project will deploy Snipe-IT on the MBFD Hub VPS and create a dual-method equipment intake system in Laravel/Filament that supports AI-powered photo capture, manual entry, and bulk CSV import.

---

## Phase 1: Snipe-IT Deployment on VPS

### Deployment Details
- **URL**: `https://www.inventory.mbfdhub.com`
- **Access Method**: Cloudflare Worker/proxy via Wrangler CLI
- **Token**: `U6XGuhQXd5JwIrkuIprFiXA_OvyCqd6ZQeLs_cmZ`

### Admin Users (Pre-synced from MBFD Hub)
| Email | Password | Role |
|-------|----------|------|
| MiguelAnchia@miamibeachfl.gov | Penco1 | Admin |
| RichardQuintela@miamibeachfl.gov | Penco2 | Admin |
| PeterDarley@miamibeachfl.gov | Penco3 | Admin |
| GreciaTrabanino@miamibeachfl.gov | MBFDSupport! | Admin |
| geralddeyoung@miamibeachfl.gov | MBFDGerry1 | Admin |

### Sync Configuration
- **Direction**: One-way (MBFD Hub → Snipe-IT)
- **Scope**: Fixed list of 5 admin users (not role-based)

---

## Phase 2: Cloudflare Worker AI Vision Agent

### Worker Configuration
- **Name**: `mbfd-equipment-vision`
- **Account**: `pdarleyjr` (existing)

### AI Vision Extraction
The Worker extracts from photos:
- Equipment Name, Category, Model, Serial Number, Condition, Visible Labels
- Only populate fields if detected with high confidence
- NO extra text added if not needed

### Categories
Fire Extinguisher, SCBA, Hose, Tools, Communication, PPE, Vehicle, Fans, K-Saws, Chainsaws, Other
**NO Medical Equipment**

---

## Phase 3: Snipe-IT Asset Mapping

### Fields (All OPTIONAL)
Name | Category | Model | Serial Number | Purchase Date | Location | Assigned To | Notes | Photo

### Location Hierarchy
- Station → Apparatus → Equipment Location (e.g., Station 1 → Engine 1 → Compartment A)
- Fire Shop Supply Room

### Assignment Model
Equipment assigned to **locations** (Supply Room, Apparatus), NOT persons. Person assignment available but rarely used.

---

## Phase 4: Filament Equipment Intake UI

### Panel Location
- **Panel**: Admin Panel (`/admin`)
- **Navigation**: Under "Inventory & Logistics" sidebar

### Three Intake Methods

#### 1. AI Camera Mode
- Upload/take photo → Send to Cloudflare Worker → Review extracted fields → Submit to Snipe-IT

#### 2. Manual Entry Mode
- Single item form with all optional fields
- Quick entry mode: After success, show "Add Another" button

#### 3. Bulk Import Mode
- CSV Upload + Rapid-Entry Grid

### UI/UX Requirements (CRITICAL)
- **Desktop**: Full-featured interface
- **Mobile**: Touch-friendly, camera access, simplified layout, readable text
- Must work flawlessly on BOTH desktop AND mobile browsers

---

## Deployment Approach
- **All 4 phases to be executed in one session**
- User will review and test after each phase

---

## Success Criteria
1. Snipe-IT at `https://www.inventory.mbfdhub.com`
2. All 5 admin users can log in
3. AI Vision Worker extracts equipment details
4. Equipment Intake page supports AI, Manual, Bulk methods
5. Page works flawlessly on desktop AND mobile
6. Equipment syncs to Snipe-IT correctly
7. Location hierarchy maps to Stations/Apparatus
