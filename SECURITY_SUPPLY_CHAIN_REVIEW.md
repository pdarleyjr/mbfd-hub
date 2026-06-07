# Supply Chain and GitHub Review

## Reviewed

- GitHub repos under `pdarleyjr` metadata, including `mbfd-hub`, `media-control`, `mbfd-bid`, `mbfd-vacation`, `screentinker`, `mbfd-ops-wall`.
- Root GitHub workflows under `.github/workflows`.
- Dependabot config, Composer/npm manifests/locks, nested npm projects, Docker/compose files.
- GitHub repo secrets and variables by name only for `mbfd-hub`; `media-control` has no repo secrets listed.

## Findings

- `mbfd-hub` and `media-control` are private. `mbfd-bid` and `screentinker` are public and require stricter secret hygiene.
- GitHub Actions were enabled with `allowed_actions: all`; SHA pinning was not enforced live. Fixed to require SHA-pinned actions.
- Branch protection API returned a GitHub plan limitation message for private repo protection. Repository rulesets/branch protections should be configured through available plan features.
- Existing workflow action references were SHA-pinned.
- Security workflows existed but several gates were non-blocking. Fixed Composer/npm audits, Trivy high/critical, and CodeQL to fail when findings are present.
- Production deployment used `npm install`; fixed to `npm ci`.
- Dependabot previously covered only root Composer/npm and Actions; nested npm projects now included.
- GitHub token/PAT exposure requires rotation. `GH_PAT` repo secret is present by name and should be reviewed for scope.

## Repo Secrets by Name Only

`mbfd-hub`: `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ZONE_ID`, `DEPLOY_HOST`, `DEPLOY_SSH_KEY`, `DEPLOY_USER`, `GH_PAT`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `SENTRY_AUTH_TOKEN`, `SENTRY_LARAVEL_DSN`, `SENTRY_ORG`, `SENTRY_PROJECT_BACKEND`, `SENTRY_PROJECT_FRONTEND`, `VITE_SENTRY_DSN`.

## Required Follow-Up

1. Rotate exposed GitHub PAT and any `GH_PAT` value if related.
2. Rotate Cloudflare/R2 secrets and update GitHub secrets.
3. Add repository rulesets requiring PRs, status checks, signed commits where practical, and no force pushes.
4. Update vulnerable Composer/npm lockfiles in a controlled dependency PR.
5. Pin mutable Docker images by digest for production/observability.
