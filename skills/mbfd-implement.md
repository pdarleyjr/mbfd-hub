# MBFD Implement Skill

## Purpose
Execute code changes for MBFD Hub following the plan from mbfd-plan.

## Workflow
1. Create a feature branch: `git checkout -b feat/<description>`
2. Make targeted edits to identified files
3. Run local validation (lint, type-check where applicable)
4. Commit with conventional commit messages: `feat:`, `fix:`, `chore:`
5. Push to GitHub: `git push origin feat/<description>`

## Constraints
- Work in /mnt/user-data/workspace/mbfd-hub inside the sandbox
- Design source of truth is `.impeccable.md`: white field, grey glass, navy authority (`#012766`). Colours, radii, shadows and bar heights come from `resources/css/mbfd-theme.css` tokens; never write a hex literal or an inline style in a view
- CSS: No @apply; cool-neutral scale (`--hub-n-*`), no warm stone palettes and no red primary buttons
- Typography: Plus Jakarta Sans (self-hosted), 14px/22px base
- Glass is level 1 only (no blur, no hover lift, no side stripes); anything inside glass is a white well; never nest glass in glass
- Filament v3 only — no x-filament::card.heading or x-filament::card.content
- Never import packages not in composer.json or package.json
- Always read the target file before editing
- public/build/ is gitignored — never commit compiled assets

## VPS Deployment
A merge to main runs CI only. Production changes need a deliberate dispatch of `.github/workflows/deploy.yml` (exact SHA and image digest, protected `production` approval) after `prepare-production-image.yml`.
For manual deploy:
```bash
ssh root@145.223.73.170 "cd /root/mbfd-hub && git pull && docker compose exec laravel.test bash -c 'npm install && npm run build' && docker compose exec -u root laravel.test chmod -R 777 storage bootstrap/cache && docker exec mbfd-hub-laravel.test-1 php artisan optimize:clear"
```
