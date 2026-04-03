# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in MBFD Hub, please report it responsibly.

**Do NOT open a public issue.**

Instead, email **pdarleyjr@gmail.com** with:
- Description of the vulnerability
- Steps to reproduce
- Potential impact assessment

You will receive an acknowledgment within 48 hours and a detailed response within 5 business days.

## Supported Versions

| Version | Supported |
|---------|-----------|
| main branch (latest) | Yes |
| Older commits | No |

## Security Measures

This project implements the following security controls:

- **Authentication:** Laravel Sanctum + Filament Shield RBAC
- **Authorization:** 20+ resource-level policies with Spatie Permissions
- **Edge Protection:** Cloudflare Tunnel with Zero Trust Access for internal tools
- **CI/CD Security:** Gitleaks secret scanning, Trivy vulnerability scanning, CodeQL SAST, Dependency Review, PHPStan static analysis
- **Infrastructure:** UFW firewall, fail2ban, Docker container hardening (no-new-privileges, non-privileged, 127.0.0.1 bindings)
- **Monitoring:** Sentry error tracking, Uptime Kuma, Laravel Pulse
- **Dependencies:** Dependabot automated updates for Composer, NPM, and GitHub Actions

## Disclosure Policy

We follow coordinated disclosure. We ask that you:
1. Allow reasonable time for fixes before public disclosure
2. Make a good-faith effort to avoid privacy violations, data destruction, and service disruption
3. Do not access or modify other users' data
