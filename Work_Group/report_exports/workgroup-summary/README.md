# MBFD Workgroup Summary — DOCX Export Pipeline

## Purpose
Converts the live MBFD Workgroup Summary report webpage into a clean, editable Microsoft Word `.docx` file.

## Source of Truth
The **live Blade report page** at `resources/views/workgroup/workgroup-summary.blade.php` is the single source of truth. The export pipeline reads this file, strips web-only chrome (print buttons, JS controls), resolves image paths, and converts to `.docx` via Pandoc.

## Directory Structure
```
Work_Group/report_exports/workgroup-summary/
├── source/                          # Export-ready intermediate HTML
│   └── workgroup-summary-export.html
├── templates/                       # Pandoc reference doc for Word styling
│   └── mbfd-workgroup-reference.docx
├── dist/                            # Final output
│   └── MBFD_Workgroup_Summary.docx
├── logs/                            # Export run logs
│   └── export_YYYYMMDD_HHMMSS.log
└── README.md                        # This file
```

## How to Re-run the Export

### On the VPS (production)
```bash
ssh root@145.223.73.170
bash /root/mbfd-hub/scripts/export-workgroup-summary-docx.sh
```

### From local Windows (via SSH)
```powershell
ssh -i "$env:USERPROFILE\.ssh\id_ed25519_hpb_docker" root@145.223.73.170 "bash /root/mbfd-hub/scripts/export-workgroup-summary-docx.sh"
```

### Download the result locally
```powershell
scp -i "$env:USERPROFILE\.ssh\id_ed25519_hpb_docker" root@145.223.73.170:/root/mbfd-hub/Work_Group/report_exports/workgroup-summary/dist/MBFD_Workgroup_Summary.docx .
```

## Key Files
| File | Purpose |
|------|---------|
| `scripts/export-workgroup-summary-docx.sh` | Main export script (runs on VPS) |
| `resources/views/workgroup/workgroup-summary.blade.php` | Live Blade report (DO NOT MODIFY for exports) |
| `public/images/workgroup-summary/` | Report images (12 PNGs) |
| `Work_Group/report_exports/workgroup-summary/templates/mbfd-workgroup-reference.docx` | Pandoc reference doc |

## Requirements
- `pandoc` 3.x+ installed on the VPS (`apt install pandoc`)
- Report images present at `public/images/workgroup-summary/`

## CRITICAL RULES FOR FUTURE AGENTS
1. **DO NOT** modify the live report page for export purposes
2. **DO NOT** convert the PDF to DOCX — use the Blade webpage as source
3. **DO NOT** rebuild/redesign the report page
4. **DO NOT** create a competing webpage
5. The export script is read-only against the site — it only reads, never writes site files
6. If the report content changes, just re-run the export script

## Word Template Customization
To customize Word styles (fonts, heading formatting, etc.):
1. Open `templates/mbfd-workgroup-reference.docx` in Word
2. Modify the styles (Heading 1, Heading 2, Normal, etc.)
3. Save and re-run the export script
