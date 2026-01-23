# Observability Setup Report

## Overview
This document summarizes the Sentry error tracking and Lighthouse CI performance monitoring setup for the MBFD Hub project.

## Sentry Configuration

### Organization
- **Sentry Org Slug:** `peter-darley`
- **Dashboard:** https://peter-darley.sentry.io

### Projects Created
| Project | Platform | Slug | Purpose |
|---------|----------|------|---------|
| MBFD Hub Backend | PHP/Laravel | `mbfd-hub-backend` | Server-side error tracking |
| MBFD Hub Frontend | JavaScript/React | `mbfd-hub-frontend` | Client-side error tracking with sourcemaps |

### GitHub Repository Secrets
The following secrets were configured in `pdarleyjr/mbfd-hub`:
- `SENTRY_AUTH_TOKEN` - API authentication for release creation
- `SENTRY_ORG` - Organization identifier
- `SENTRY_PROJECT_BACKEND` - Backend project slug
- `SENTRY_PROJECT_FRONTEND` - Frontend project slug
- `VITE_SENTRY_DSN` - Frontend DSN for error reporting
- `SENTRY_LARAVEL_DSN` - Backend DSN for error reporting

### Files Modified
- `bootstrap/app.php` - Sentry exception handler integration
- `config/sentry.php` - Sentry configuration (published)
- `composer.json` - Added sentry/sentry-laravel dependency
- `resources/js/daily-checkout/src/main.tsx` - Sentry React SDK initialization
- `resources/js/daily-checkout/vite.config.js` - Sourcemaps and Sentry Vite plugin
- `routes/web.php` - Test route (remove after verification)

## Lighthouse CI Configuration

### Workflow
- **File:** `.github/workflows/lighthouse.yml`
- **Triggers:** Push to main, Pull requests
- **Action:** `treosh/lighthouse-ci-action@v12`

### URLs Audited
- https://support.darleyplex.com/
- https://support.darleyplex.com/daily
- https://support.darleyplex.com/admin/login

### Performance Budget
- `budget.json` - 1500KB total resource budget for all paths

## GitHub Actions Workflows

### Sentry Release Workflow
- **File:** `.github/workflows/observability.yml`
- **Purpose:** Create Sentry releases on deploy, upload sourcemaps
- **Action:** `getsentry/action-release@v3`

### Lighthouse CI Workflow
- **File:** `.github/workflows/lighthouse.yml`
- **Purpose:** Performance auditing with artifact upload
- **Features:** Temporary public storage, artifact upload

## Verification Steps

### Backend Verification
1. Visit `/__sentry_test` route (dev only) to trigger test exception
2. Check Sentry dashboard for captured event

### Frontend Verification
1. Trigger a JS error in the daily checkout app
2. Verify readable stack trace in Sentry (sourcemaps working)

### Actions Verification
1. Check Actions tab for workflow runs after PR merge
2. Verify Sentry Release created in dashboard
3. Verify Lighthouse artifacts in workflow summary

## Pull Request
- **PR #2:** https://github.com/pdarleyjr/mbfd-hub/pull/2

## Next Steps
1. Merge PR to trigger workflows
2. Verify Sentry Release creation in dashboard
3. Review Lighthouse CI reports
4. Remove `/__sentry_test` route after verification
5. Configure Sentry alert rules as needed
6. Tighten Lighthouse budgets based on baseline
