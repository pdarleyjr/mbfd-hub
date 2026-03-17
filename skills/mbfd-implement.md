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
- CSS: No @apply, no pure grays (#808080), warm-tinted neutrals only
- Typography: Plus Jakarta Sans + Source Sans 3
- No nested cards in Filament views
- Filament v3 only — no x-filament::card.heading or x-filament::card.content
- Never import packages not in composer.json or package.json
- Always read the target file before editing
- public/build/ is gitignored — never commit compiled assets

## VPS Deployment
After push, CI/CD deploys automatically via .github/workflows/deploy.yml.
For manual deploy:
```bash
ssh root@145.223.73.170 "cd /root/mbfd-hub && git pull && docker compose exec laravel.test bash -c 'npm install && npm run build' && docker compose exec -u root laravel.test chmod -R 777 storage bootstrap/cache && docker exec mbfd-hub-laravel.test-1 php artisan optimize:clear"
```
