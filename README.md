# MBFD Support Hub

<div align="center">

![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=flat-square&logo=laravel&logoColor=white)
![React](https://img.shields.io/badge/React-18-61DAFB?style=flat-square&logo=react&logoColor=black)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-15-316192?style=flat-square&logo=postgresql&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?style=flat-square&logo=docker&logoColor=white)
![Cloudflare](https://img.shields.io/badge/Cloudflare-Workers%20%7C%20Tunnels-F38020?style=flat-square&logo=cloudflare&logoColor=white)
![License](https://img.shields.io/badge/License-Proprietary-red?style=flat-square)

**Enterprise internal operations platform for the Miami Beach Fire Department**

</div>

---

## Executive Summary

The **MBFD Support Hub** is a full-stack internal operations platform built to modernize fire department logistics, fleet management, inventory control, and collaborative evaluation workflows. It consolidates daily operations, capital project tracking, Snipe-IT equipment integration, and AI-assisted tools into a single, secure, Cloudflare-protected application.

**Live Production URL:** `https://www.mbfdhub.com`
**Inventory System:** `https://inventory.mbfdhub.com` (Snipe-IT)

---

## System Architecture

```
Internet -> Cloudflare Tunnel -> VPS (Ubuntu Docker Host)
                                    +-- laravel.test    (Laravel 11 + Octane)
                                    +-- pgsql           (PostgreSQL 15)
                                    +-- snipeit         (Snipe-IT + MariaDB)
                                    +-- baserow         (Baserow self-hosted)
                                    +-- nocobase        (NocoBase CE)
                                    +-- cloudflared     (Tunnel daemon)
```

### Technology Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 11.31 + PHP 8.2 + Octane |
| Admin UI | Filament v3.2 (3 panels: Admin, Training, Workgroup) |
| Frontend SPA | React 18 + TypeScript + Vite + Zustand |
| Database | PostgreSQL 15 |
| Queue/Cache | Redis + database queue |
| WebSockets | Laravel Reverb |
| Container | Docker Compose on Ubuntu VPS |
| CDN/Network | Cloudflare Tunnel (zero direct port exposure) |
| AI | Cloudflare Workers AI (llama-3.2-11b-vision + llama-3.3-70b-instruct) |
| Monitoring | Sentry |
| Equipment Tracking | Snipe-IT (fully API-integrated) |

---

## Core Features

### Logistics Admin Panel (`/admin`)
- **Fire Apparatus** management with Google Sheets auto-sync
- **Capital Projects** tracking (over and under $25k)
- **Station Inventory V2** — PIN-gated, threshold alerts, audit trail
- **Equipment Intake** — AI-powered photo scanning, multi-shot Vision Worker analysis, AI Bulk Import (up to 20 items)
- **Snipe-IT Integration** — Relational asset creation (manufacturer/category/model resolution, consumable routing)
- **Fleet Management** — Unit Master Vehicles, inspections, defect tracking
- **CSV/XLSX Export** — All resource and relation manager tables

### Eval Feedback Hub (`/workgroups`)
- Workgroup and session management
- Product evaluation with SAVER scoring framework
- AI executive reports via Cloudflare Workers AI
- Session attendance tracking and evaluation access gates
- Specialized exporters (scores, finalists, feedback, completion)

### MBFD Forms SPA (`/daily`)
- React 18 PWA (installable on mobile devices)
- Daily apparatus checkout forms with offline capability

### Pump Simulator (`/pump-simulator`)
- Interactive fire pump operations training tool
- 10 nozzle profiles, friction loss calculation, mobile-responsive

---

## Local Development Setup

### Prerequisites
- Docker Desktop / Docker Engine + Docker Compose
- Node.js 18+

### Quick Start

```bash
# 1. Clone
git clone https://github.com/pdarleyjr/mbfd-hub.git
cd mbfd-hub

# 2. Environment setup
cp .env.example .env
# Edit .env with your local database and key configuration

# 3. Start containers
docker compose up -d

# 4. Install dependencies and bootstrap
docker compose exec laravel.test composer install
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate --seed

# 5. Build frontend assets
docker compose exec laravel.test npm ci
docker compose exec laravel.test npm run build

# 6. Open http://localhost:8000
```

---

## Infrastructure & Deployment

### Continuous Deployment (GitHub Actions)

Push to `main` triggers `.github/workflows/deploy.yml`:
1. PostgreSQL backup on VPS
2. Authenticated `git pull` on VPS
3. `docker compose up -d --build`
4. `php artisan migrate --force` + optimize
5. Vite asset builds (main app + daily-checkout SPA)
6. Cloudflare Workers deployment via Wrangler
7. Cloudflare cache purge
8. Smoke tests

### Required GitHub Secrets

| Secret | Description |
|--------|-------------|
| `VPS_SSH_KEY` | SSH private key for deployment |
| `VPS_HOST` | VPS hostname (stored as secret, not in docs) |
| `VPS_USER` | SSH username |
| `GH_PAT` | GitHub PAT for authenticated git pull |
| `CLOUDFLARE_API_TOKEN` | Cloudflare API token |
| `CLOUDFLARE_ZONE_ID` | Cloudflare zone ID for cache purge |

---

## Security

See [SECURITY.md](SECURITY.md) for the full security policy and vulnerability reporting process.

---

## License

**Proprietary — All Rights Reserved**
(c) Miami Beach Fire Department. Internal use only.
