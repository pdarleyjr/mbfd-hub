# Training Administration

## Overview
Training administration is part of the regular Filament Admin panel. Training accounts use the same email/password login and navigation as every other administrator.

## Access
- **Admin:** https://www.mbfdhub.com/admin
- **Login:** https://www.mbfdhub.com/admin/login
- **Training Todos:** https://www.mbfdhub.com/admin/training-todos

Legacy `/training` bookmarks redirect to the equivalent `/admin` path.

## User Credentials
Training users retain their `training_admin` or `training_viewer` label for notification targeting and also receive the regular `admin` role. Passwords remain unchanged and stored out-of-band.

- danielgato@miamibeachfl.gov
- victorwhite@miamibeachfl.gov
- ClaudioNavas@miamibeachfl.gov
- michaelsica@miamibeachfl.gov

## Access Model

- `admin` provides the canonical Admin panel access, including Operational Forms.
- `training_admin` and `training_viewer` are retained as secondary labels for Training Todo notifications.
- `2026_07_18_220000_consolidate_training_accounts_into_admin_panel.php` adds `admin` to every existing Training account without removing roles or changing passwords.

## Resources
- **Training Todos** — available under the Admin panel's Training Tasks navigation group.
- **Operational Forms** — available under Active Operations.
- All regular administration, fleet, inventory, logistics, station, and workgroup information allowed to `admin` accounts.

## Troubleshooting

- Clear route and panel caches with `php artisan optimize:clear` after deployment.
- Confirm the account has `admin` plus its existing Training role.
- Run `php artisan migrate --force` to consolidate older Training accounts.
