# Capital Projects Enhancements

## Overview

This document describes the enhancements made to the Capital Projects module in MBFD Hub on January 27, 2026.

## Features Implemented

### 1. Percent Complete (Progress Tracking)

**Database:**
- Column: `percent_complete` (SMALLINT, nullable)
- Added via migration: `2026_01_27_180000_add_percent_complete_and_attachments_to_capital_projects.php`

**Model (`app/Models/CapitalProject.php`):**
- Added to `$fillable`: `'percent_complete'`
- Added cast: `'percent_complete' => 'integer'`

**Edit Form:**
- Slider control (0-100) with step of 5
- Shows percentage suffix (%)
- Located in "Progress" section of the form

**View Page:**
- Displays as a color-coded badge:
  - Green (success): 90-100%
  - Yellow (warning): 50-89%
  - Red (danger): 0-49%
  - Gray: Not set

### 2. File Attachments

**Database:**
- Column: `attachments` (JSONB, nullable)
- Stores array of file paths

**Model:**
- Added to `$fillable`: `'attachments'`
- Added cast: `'attachments' => 'array'`

**Edit Form:**
- FileUpload component with:
  - Multiple file support
  - Downloadable files
  - Preview capability
  - Directory: `capital-projects/{project_id}`
  - Max size: 25MB per file
  - Accepted types: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, GIF

**View Page:**
- Lists all uploaded files with:
  - Filename display
  - Download links
  - Opens in new tab

**Storage:**
- Uses `public` disk
- Requires `php artisan storage:link` to be configured
- Files accessible via `/storage/capital-projects/{id}/filename`

### 3. Project Updates (Status Updates)

**Database:**
- Table: `project_updates` (already existed)
- Links to capital_projects via `capital_project_id` foreign key

**Model (`app/Models/ProjectUpdate.php`):**
- Already existed with fields: title, body, percent_complete_snapshot, created_by, created_at

**Edit Form:**
- UpdatesRelationManager tab allows:
  - Creating new updates
  - Editing existing updates
  - Viewing update history

**View Page:**
- Shows "Total Updates" count in Related Information
- Full Updates section with search and list of all updates

## Migration Safety

All schema changes were **additive only**:
- New nullable columns added (no default values that would lock table)
- No existing columns modified or dropped
- No data was deleted or overwritten
- Fully backwards compatible

## Rollback Procedure

To rollback code changes:
```bash
git revert <commit-hash>
```

Database columns can remain in place (they're nullable and won't affect functionality).

To fully remove database columns (only if needed):
```sql
ALTER TABLE capital_projects DROP COLUMN IF EXISTS percent_complete;
ALTER TABLE capital_projects DROP COLUMN IF EXISTS attachments;
```

## Verification

Verified on production (https://support.darleyplex.com):
1. ✅ Existing capital projects data preserved (notes, names, budgets)
2. ✅ Edit page shows percent_complete slider
3. ✅ Edit page shows file upload field
4. ✅ View page shows Progress with badge
5. ✅ View page shows Attachments section
6. ✅ View page shows Updates RelationManager
7. ✅ Updates can be created/edited via relation manager
