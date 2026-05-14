# CSV vs Database Schema Analysis

## CSV File Structure (under_25k_fire_dept - Sheet1.csv)
**Total Rows:** 7 (1 header + 6 data rows)

### CSV Columns (20 columns):
1. FACILITY / PROJECT NAME
2. DESCRIPTION / NOTES
3. STATUS / PROGRESS
4. START DATE
5. COMPLETION DATE
6. ZONE
7. MIAMI BEACH AREA
8. ASSIGNED TO
9. MUNIS ADOPTED/AMENDED
10. MUNIS TRANSF. IN / OUT
11. MUNIS - REVISED BUDGET
12. INTERNAL TRANSF. IN / OUT
13. INT. - REVISED BUDGET
14. REQUISITIONS
15. ACTUAL EXPENSES
16. PROJECT BAL. / SAVINGS
17. LAST COMMENT DATE
18. LATEST COMMENT
19. VFA UPDATE
20. VFA UPDATE DATE

## Current Database Schema (under_25k_projects table)

### Existing Columns:
- id
- project_number
- name
- description
- budget_amount
- spend_amount
- status
- priority
- start_date
- target_completion_date
- actual_completion_date
- project_manager
- notes
- percent_complete
- internal_notes
- attachments
- attachment_file_names
- created_at
- updated_at

## Column Mapping Analysis

### Mapped Columns (CSV → Database):
| CSV Column | Database Field | Status |
|------------|----------------|--------|
| FACILITY / PROJECT NAME | name | ✓ Mapped |
| DESCRIPTION / NOTES | description | ✓ Mapped |
| STATUS / PROGRESS | status | ✓ Mapped |
| START DATE | start_date | ✓ Mapped |
| COMPLETION DATE | target_completion_date | ✓ Mapped |
| ASSIGNED TO | project_manager | ✓ Mapped |

### Missing Columns (NOT in Database):
| CSV Column | Data Type | Description |
|------------|-----------|-------------|
| ZONE | string | Zone identifier (e.g., "PS") |
| MIAMI BEACH AREA | string | Area within Miami Beach |
| MUNIS ADOPTED/AMENDED | decimal | MUNIS adopted/amended budget amount |
| MUNIS TRANSF. IN / OUT | decimal | MUNIS transfers in/out |
| MUNIS - REVISED BUDGET | decimal | MUNIS revised budget |
| INTERNAL TRANSF. IN / OUT | decimal | Internal transfers in/out |
| INT. - REVISED BUDGET | decimal | Internal revised budget |
| REQUISITIONS | decimal | Requisition amounts |
| ACTUAL EXPENSES | decimal | Actual expenses incurred |
| PROJECT BAL. / SAVINGS | decimal | Project balance or savings |
| LAST COMMENT DATE | date | Date of last comment |
| LATEST COMMENT | text | Latest comment text |
| VFA UPDATE | text | VFA update information |
| VFA UPDATE DATE | date | Date of VFA update |

### Database Columns NOT in CSV:
| Database Field | Description |
|----------------|-------------|
| project_number | Project number identifier |
| budget_amount | Budget amount (may map to one of the budget columns) |
| spend_amount | Spend amount (may map to ACTUAL EXPENSES) |
| priority | Project priority |
| actual_completion_date | Actual completion date |
| percent_complete | Percentage complete |
| internal_notes | Internal notes (currently storing financial data as text) |
| attachments | File attachments |
| attachment_file_names | Attachment file names |

## Current Data Issues

### Problem 1: Financial Data Stored as Text
The financial columns from the CSV (MUNIS ADOPTED/AMENDED, INTERNAL TRANSF. IN / OUT, INT. - REVISED BUDGET, REQUISITIONS, ACTUAL EXPENSES, PROJECT BAL. / SAVINGS, LAST COMMENT DATE) are currently being stored in the `internal_notes` field as text rather than in separate database columns.

### Problem 2: Missing Columns
The database is missing 14 columns that exist in the CSV file, including:
- Zone and Area information
- All MUNIS budget tracking columns
- All Internal budget tracking columns
- Requisitions, Actual Expenses, Project Balance
- Comment tracking (date and text)
- VFA update tracking

### Problem 3: Data Type Mismatches
- CSV uses currency format with $ and commas (e.g., "$24,900.00")
- Database expects decimal format (e.g., 24900.00)
- CSV dates are in MM/DD/YY format (e.g., "12/11/25")
- Database expects YYYY-MM-DD format

## Required Changes

### 1. Add Missing Columns to Database
Need to add the following columns to the `under_25k_projects` table:
- zone (string, nullable)
- miami_beach_area (string, nullable)
- munis_adopted_amended (decimal:2, nullable)
- munis_transfers_in_out (decimal:2, nullable)
- munis_revised_budget (decimal:2, nullable)
- internal_transfers_in_out (decimal:2, nullable)
- internal_revised_budget (decimal:2, nullable)
- requisitions (decimal:2, nullable)
- actual_expenses (decimal:2, nullable)
- project_balance_savings (decimal:2, nullable)
- last_comment_date (date, nullable)
- latest_comment (text, nullable)
- vfa_update (text, nullable)
- vfa_update_date (date, nullable)

### 2. Update Model
Update the `Under25kProject` model to include the new columns in the `$fillable` array and add appropriate casts.

### 3. Update Filament Resource
Update the `Under25kProjectResource` to include the new fields in the form, table, and infolist schemas.

### 4. Create Data Migration Script
Create a script to:
- Parse the correct CSV file
- Import data into the new columns
- Handle currency format conversion ($24,900.00 → 24900.00)
- Handle date format conversion (12/11/25 → 2025-12-11)
- Preserve existing data

### 5. Update Seeder
Update or create a seeder to import data from the correct CSV file.

## Sample Data from CSV

Row 1:
- FACILITY / PROJECT NAME: Fire Station # 1
- DESCRIPTION / NOTES: Watch Office Renovation/ Upgrades
- STATUS / PROGRESS: IN PROGRESS
- START DATE: 12/11/25
- COMPLETION DATE: (empty)
- ZONE: PS
- MIAMI BEACH AREA: (empty)
- ASSIGNED TO: Faustino Fernandez
- MUNIS ADOPTED/AMENDED: $24,900.00
- MUNIS TRANSF. IN / OUT: (empty)
- MUNIS - REVISED BUDGET: $24,900.00
- INTERNAL TRANSF. IN / OUT: -$12,430.00
- INT. - REVISED BUDGET: $12,470.00
- REQUISITIONS: $8,690.00
- ACTUAL EXPENSES: $0.00
- PROJECT BAL. / SAVINGS: $3,780.00
- LAST COMMENT DATE: 01/20/26
- LATEST COMMENT: Faustino Fernandez - @stephanygonzales@miamibeachfl.gov yes
- VFA UPDATE: (empty)
- VFA UPDATE DATE: (empty)

## Next Steps

1. Create a migration to add the missing columns
2. Update the Under25kProject model
3. Update the Under25kProjectResource Filament resource
4. Create a data import script to populate the new columns from the CSV
5. Test the changes locally
6. Deploy to VPS
7. Clear caches
8. Test with Playwright
