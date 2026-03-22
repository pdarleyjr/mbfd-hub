# Exposed Secrets Inventory
**Date:** 2026-02-03  
**Incident:** Credentials exposed in chat logs  
**Status:** Requires rotation during next maintenance window

---

## Overview

All secrets listed below were potentially exposed in chat logs and should be considered compromised. This inventory documents **secret names and locations only** - no actual values are included.

## Priority Matrix

| Priority | Timeline | Impact |
|----------|----------|--------|
| P0 - Critical | Immediate (< 1 hour) | Direct database/system access |
| P1 - High | Within 24 hours | API rate limits, service disruption |
| P2 - Medium | Within 1 week | Monitoring/analytics access |
| P3 - Low | Next release | Non-critical integrations |

---

## Secrets Requiring Rotation

### P0 - Critical (Rotate Immediately)

| Secret Name | Location | Purpose | Impact if Compromised | Rotation Steps |
|-------------|----------|---------|----------------------|----------------|
| `DB_PASSWORD` | `.env`, Docker environment | PostgreSQL database password | Full database access, data exfiltration, data modification, ransomware | 1. Generate new password<br>2. Update `.env`<br>3. Update `docker-compose.yml` or Docker secrets<br>4. Restart `pgsql` container<br>5. Verify app connectivity |
| `APP_KEY` | `.env` | Laravel application encryption key | Session hijacking, decrypt encrypted data, forge signed URLs | 1. Run `php artisan key:generate`<br>2. Update `.env`<br>3. **WARNING**: Users will be logged out, encrypted data may be lost<br>4. Restart app container |

**CRITICAL NOTE:** `DB_PASSWORD` rotation is **URGENT**due to PostgreSQL being publicly accessible on port 5432.

### P1 - High (Rotate Within 24 Hours)

| Secret Name | Location | Purpose | Impact if Compromised | Rotation Steps |
|-------------|----------|---------|----------------------|----------------|
| `GITHUB_TOKEN` | `.env` | GitHub API access for deployments/integrations | Unauthorized code access, repo modifications, workflow manipulations | 1. Revoke old token in GitHub Settings<br>2. Generate new Personal Access Token<br>3. Update `.env`<br>4. Update CI/CD pipelines<br>5. Test deployments |
| `CLOUDFLARE_API_TOKEN` | `.env` | Cloudflare API (DNS, Workers, AI) | DNS hijacking, CDN manipulation, Workers code access | 1. Revoke token in Cloudflare dashboard<br>2. Generate new API token<br>3. Update `.env`<br>4. Test AI service integration |
| `CLOUDFLARE_ACCOUNT_ID` | `.env` | Cloudflare account identifier | Combined with API token: full account access | Update if using scoped tokens (usually rotates with token) |

### P2 - Medium (Rotate Within 1 Week)

| Secret Name | Location | Purpose | Impact if Compromised | Rotation Steps |
|-------------|----------|---------|----------------------|----------------|
| `SENTRY_LARAVEL_DSN` | `.env` | Sentry error tracking | Access to error logs, user data in stack traces, application insights | 1. Regenerate DSN in Sentry project settings<br>2. Update `.env`<br>3. Test error reporting |
| `VAPID_PUBLIC_KEY` | `.env` | Web push notifications (public) | Limited risk (public key) but rotate for consistency | Generate new VAPID keypair |
| `VAPID_PRIVATE_KEY` | `.env` | Web push notifications (private) | Unauthorized push notifications to users | 1. Generate new VAPID keypair<br>2. Update `.env` (both keys)<br>3. Re-subscribe all users |

### P3 - Low (Rotate Next Release)

| Secret Name | Location | Purpose | Impact if Compromised | Rotation Steps |
|-------------|----------|---------|----------------------|----------------|
| `SESSION_DRIVER` config | `.env` | Session storage method | No secret value, just configuration | N/A (not a secret) |
| `QUEUE_CONNECTION` config | `.env` | Queue driver configuration | No secret value, just configuration | N/A (not a secret) |

---

## Additional Exposed Information (Non-Secrets)

The following non-secret configuration values were also exposed:

| Item | Location | Risk Level | Notes |
|------|----------|------------|-------|
| `DB_HOST` | `.env` | Low | Hostname known (`pgsql`) |
| `DB_PORT` | `.env` | Low | Port known (`5432`) |
| `DB_DATABASE` | `.env` | Medium | Database name exposed |
| `DB_USERNAME` | `.env` | High | Username `postgres` known (combine with password = full access) |
| `APP_NAME` | `.env` | Low | Application name |
| `APP_ENV` | `.env` | Medium | Environment (production) revealed |
| `APP_DEBUG` | `.env` | Medium | Debug status |

---

## Rotation Workflow

### Pre-Rotation Checklist
- [ ] Schedule maintenance window (estimated 30-60 minutes downtime)
- [ ] Notify users of planned maintenance
- [ ] Create database backup
- [ ] Document current secret locations
- [ ] Prepare rollback plan

### Rotation Steps

####  1. Database Password (P0 - Critical)

```bash
# 1. Generate new secure password
NEW_DB_PASS=$(openssl rand -base64 32)

# 2. Backup current .env
cp .env .env.backup.$(date +%Y%m%d_%H%M%S)

# 3. Connect to PostgreSQL and change password
docker exec mbfd-hub-pgsql-1 psql -U postgres -c "ALTER USER postgres WITH PASSWORD '$NEW_DB_PASS';"

# 4. Update .env file
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$NEW_DB_PASS/" .env

# 5. Update docker-compose.yml if password is there
# (Edit manually if needed)

# 6. Restart app container to pick up new password
docker-compose restart laravel.test

# 7. Verify app can connect
docker exec mbfd-hub-laravel.test-1 php artisan tinker --execute="DB::connection()->getPdo();"
```

#### 2. Laravel Application Key (P0 - Critical)

**⚠️ WARNING:** This will **log out all users** and **invalidate all encrypted data**.

```bash
# 1. Backup current key
grep APP_KEY .env >> .env.backup.$(date +%Y%m%d_%H%M%S)

# 2. Generate new key
docker exec mbfd-hub-laravel.test-1 php artisan key:generate

# 3. Restart app
docker-compose restart laravel.test

# 4. Test login
# Manual: Try logging into /admin
```

#### 3. GitHub Token (P1 - High)

```bash
# 1. Revoke old token at: https://github.com/settings/tokens
# 2. Generate new token with same scopes
# 3. Update .env
sed -i "s/^GITHUB_TOKEN=.*/GITHUB_TOKEN=<new_token>/" .env

# 4. Test deployment pipeline
git push # (trigger CI/CD)
```

#### 4. Cloudflare API Token (P1 - High)

```bash
# 1. Revoke at: https://dash.cloudflare.com/profile/api-tokens
# 2. Create new token with scopes: Workers:Edit, Account:Read, AI:Read
# 3. Update .env
sed -i "s/^CLOUDFLARE_API_TOKEN=.*/CLOUDFLARE_API_TOKEN=<new_token>/" .env

# 4. Test AI service
docker exec mbfd-hub-laravel.test-1 php artisan tinker --execute="app(App\Services\CloudflareAIService::class)->test();"
```

#### 5. Sentry DSN (P2 - Medium)

```bash
# 1. Regenerate at: Sentry Project Settings > Client Keys (DSN)
# 2. Update .env
sed -i "s|^SENTRY_LARAVEL_DSN=.*|SENTRY_LARAVEL_DSN=<new_dsn>|" .env

# 3. Test error reporting
docker exec mbfd-hub-laravel.test-1 php artisan sentry:test
```

#### 6. VAPID Keys (P2 - Medium)

```bash
# 1. Generate new keypair
docker exec mbfd-hub-laravel.test-1 php artisan webpush:vapid

# Output will show:
# VAPID_PUBLIC_KEY=<new_public>
# VAPID_PRIVATE_KEY=<new_private>

# 2. Update .env with both keys
# 3. Re-subscribe users (they'll need to allow notifications again)
```

### Post-Rotation Checklist
- [ ] Verify application functionality
- [ ] Test database connectivity
- [ ] Confirm error tracking works
- [ ] Check push notifications
- [ ] Monitor logs for authentication errors
- [ ] Document rotation in change log
- [ ] Securely delete old credential backups (after 30 days)

---

## Access Control Review

In addition to rotating secrets, review who has access to:

| Resource | Current Access | Recommended Action |
|----------|---------------|-------------------|
| VPS SSH | root key-based | ✅ Key-based is good, ensure key is secured |
| Database | `postgres` superuser | Create app-specific DB user with limited privileges |
| `.env` file | File on server | Migrate to Docker secrets or vault service |
| GitHub repo | Team members | Audit collaborators, remove inactive accounts |
| Cloudflare account | Shared credentials | Use role-based access with individual accounts |
| Sentry project | Team members | Review members, remove unused access |

---

## Long-Term Secrets Management

### Recommendations:

1. **Use Docker Secrets** (instead of `.env` for sensitive values)
   ```yaml
   services:
     laravel.test:
       secrets:
         - db_password
         - app_key
   
   secrets:
     db_password:
       file: ./secrets/db_password.txt
     app_key:
       file: ./secrets/app_key.txt
   ```

2. **Use Environment-Specific Vaults**
   - HashiCorp Vault
   - AWS Secrets Manager
   - Azure Key Vault
   - Doppler

3. **Implement Secret Rotation Policy**
   - Database passwords: Every 90 days
   - API tokens: Every 180 days
   - Application keys: Every 365 days or after exposure

4. **Enable Audit Logging**
   - Log all secret access
   - Alert on unauthorized access attempts
   - Review logs quarterly

---

## Emergency Contact

If secrets are actively being exploited:

1. **Immediately rotate `DB_PASSWORD`** (see Priority 1 steps above)
2. **Close PostgreSQL port 5432** (see `SECURITY_HARDENING_APPLIED.md`)
3. **Check for unauthorized database connections:**
   ```bash
   docker exec mbfd-hub-pgsql-1 psql -U postgres -c "SELECT * FROM pg_stat_activity WHERE usename='postgres';"
   ```
4. **Review database audit logs** for suspicious queries
5. **Contact security team/consultant** if data exfiltration suspected

---

**Document Status:** Draft  
**Next Review Date:** After secrets rotation  
**Owner:** DevOps Team
