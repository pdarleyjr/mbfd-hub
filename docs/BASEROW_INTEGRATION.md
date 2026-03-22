# Baserow Integration Documentation

## Overview
The Training panel supports embedding Baserow shared views as navigation items.

## Excel Workflow (Phase 1)
1. Import Excel directly in Baserow UI
2. Create appropriate view (Grid/Kanban/Form)
3. Enable "public sharing" for the view
4. Copy the shared view URL
5. Create ExternalNavItem in MBFD Hub with that URL

## Adding External Nav Items
1. Go to Training panel → External Nav Items
2. Click "Create External Nav Item"
3. Fill in:
   - Label: Display name
   - Division: training
   - Type: iframe
   - URL: Baserow shared view link
   - Open in New Tab: true (recommended for iframe)
4. Assign allowed roles
5. Save

## Security
- Always use HTTPS
- Consider password protection in Baserow shared views
- Tokens stored encrypted at rest
