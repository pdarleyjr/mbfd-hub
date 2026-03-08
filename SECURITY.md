# Security Policy

## Overview

The Miami Beach Fire Department (MBFD) Support Hub is an enterprise-grade internal operations platform maintained by the Miami Beach Fire Department's IT and logistics division. This is a **closed-source, internal-use application** not intended for public contribution.

---

## Supported Versions

| Version | Supported |
|---------|-----------|
| Current `main` branch | ✅ Active maintenance |
| Feature branches | ⚠️ Development only |

---

## Reporting a Vulnerability

If you discover a security vulnerability in the MBFD Support Hub, please follow responsible disclosure practices.

### ⚠️ Do NOT:
- Open a public GitHub Issue with vulnerability details
- Post in Pull Request comments or public forums

### ✅ Contact Process:
1. **Email**: Contact the MBFD IT department at `it-security@miamibeachfl.gov`
2. **Subject line**: `[SECURITY] MBFD Hub Vulnerability Report`
3. **Include**: Description, steps to reproduce, potential impact, and your contact information

We commit to responding within **72 business hours** and providing a resolution timeline within **14 days** of confirmed reproduction.

---

## Security Architecture

| Layer | Implementation |
|-------|---------------|
| Authentication | Laravel Auth + Filament with bcrypt hashing |
| Authorization | Role-based access control (`spatie/laravel-permission`) |
| Network | Cloudflare Tunnel (zero direct port exposure) |
| Database | PostgreSQL with TLS connections |
| Secrets | Docker secrets + environment variables (never in version control) |
| CI/CD | GitHub Actions with least-privilege token scoping and pinned action SHAs |
| Monitoring | Sentry error tracking with PII scrubbing enabled |

---

## Notes

- Documentation files (`*.md`) contain only placeholder values such as `[VPS_IP_REDACTED]` — real credentials are stored in the organization's 1Password vault
- SAML SSO code is present in the codebase but intentionally disabled in production (`saml_enabled=0`)
- The `.env.example` file contains no real secrets or keys

---

*Last updated: 2026-03-08*
