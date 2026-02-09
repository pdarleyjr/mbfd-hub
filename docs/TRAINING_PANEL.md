# Training Panel Documentation

## Overview
The Training Division panel is a separate Filament v3 panel accessible at `/training`. It provides training-specific resources and functionality.

## Access
- **URL:** https://support.darleyplex.com/training
- **Login:** https://support.darleyplex.com/training/login

## User Credentials
Training users with `training_admin` role:
- danielgato@miamibeachfl.gov / Gato1234!
- victorwhite@miamibeachfl.gov / Vic1234!
- ClaudioNavas@miamibeachfl.gov / Flea1234!
- michaelsica@miamibeachfl.gov / Sica1234!

## Roles & Permissions
- `training_admin` - Full access to Training panel
- `training_viewer` - Read-only access
- `training.access` - Permission required to access panel

## Resources
- **Training Todo** - Manage training tasks
- **External Sources** - Configure external data sources (Baserow)
- **External Nav Items** - Create dynamic navigation items

## Baserow Integration
See [docs/BASEROW_INTEGRATION.md](BASEROW_INTEGRATION.md)

## Troubleshooting
- RouteNotFoundException: Clear caches with `php artisan optimize:clear`
- Login issues: Ensure user has `training_admin` role
