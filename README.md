# MBFD Support Hub

Production-ready fire department management system for Miami Beach Fire Department.

## Stack
- Laravel 11 + FilamentPHP 3.3.50
- PostgreSQL 16
- Docker + Nginx
- Tailwind CSS

## Features
- **Apparatus Management** - Track fire apparatus, maintenance, mileage
- **Station Management** - Manage fire stations and personnel
- **Uniform Inventory** - Track uniform stock and reorder levels
- **Capital Projects** - Monitor project budgets and status
- **Shop Work Orders** - Manage repair and modification tasks

## Deployment
Production: https://support.darleyplex.com

## Local Development
```bash
docker compose up -d
docker compose exec app php artisan migrate
```

## Admin Access
Access admin panel at: `/admin`
