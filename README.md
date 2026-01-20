# MBFD Support Hub

![Miami Beach Fire Department](https://raw.githubusercontent.com/yourusername/mbfd-hub/main/mbfd-logo.png)

A modern, production-ready support services platform for the Miami Beach Fire Department built with the TALL Stack (Tailwind, Alpine, Laravel, Livewire) and FilamentPHP.

## 🚒 Features

- **Apparatus Management**: Track fire apparatus with VIN, make, model, status, and mileage
- **Station Directory**: Manage fire stations with addresses and commanding officers
- **Uniform Inventory**: Monitor uniform stock levels with reorder alerts
- **Capital Projects**: Track budget allocation and project status with Kanban boards
- **Shop Work Orders**: Document equipment modifications and repairs

## 🏗️ Architecture

- **Frontend**: Tailwind CSS + Alpine.js + Livewire (TALL Stack)
- **Backend**: Laravel 11 with FilamentPHP Admin Panel
- **Database**: PostgreSQL 16
- **Deployment**: Docker Compose on Ubuntu VPS
- **Reverse Proxy**: Nginx with SSL/TLS
- **Mobile-First**: Responsive design optimized for iOS and Android

## 📋 Requirements

- Ubuntu Server 20.04+
- Docker & Docker Compose
- Nginx
- Certbot (for SSL certificates)
- SSH access to VPS

## 🚀 Quick Start

### Local Development

```bash
# Clone the repository
git clone https://github.com/yourusername/mbfd-hub.git
cd mbfd-hub

# Start Docker containers
docker-compose up -d

# Access the application
open http://localhost:8082
```

### Production Deployment

```bash
# SSH into VPS
ssh -i ~/.ssh/id_ed25519_hpb_docker root@145.223.73.170

# Navigate to project directory
cd /root/mbfd-hub

# Pull latest changes
git pull origin main

# Start services
docker-compose up -d

# Run migrations
docker-compose exec app php artisan migrate --force
```

## 🔧 Configuration

### Environment Variables

Key environment variables in `docker-compose.yml`:

- `APP_NAME`: Application name
- `APP_URL`: Public URL (https://support.darleyplex.com)
- `DB_CONNECTION`: Database driver (pgsql)
- `DB_HOST`: Database host (db)
- `DB_DATABASE`: Database name (mbfd_hub)

### Nginx Configuration

The application is proxied through system Nginx at `/etc/nginx/sites-available/support.darleyplex.com`:

- Listens on ports 80 (HTTP) and 443 (HTTPS)
- Proxies to Docker container on localhost:8082
- WebSocket support enabled for Livewire
- SSL certificate via Let's Encrypt

## 📊 Database Schema

### Apparatus
- `id`: Primary key
- `unit_id`: Unique unit identifier
- `vin`: Vehicle identification number
- `make`: Manufacturer
- `model`: Model name
- `status`: In Service / Out of Service
- `mileage`: Current mileage
- `timestamps`: Created/updated dates

### Stations
- `id`: Primary key
- `station_number`: Station identifier
- `address`: Physical location
- `captain`: Officer in charge
- `timestamps`: Created/updated dates

### Uniforms
- `id`: Primary key
- `item_name`: Uniform item description
- `size`: Size specification
- `quantity`: Current stock
- `reorder_level`: Minimum stock threshold
- `timestamps`: Created/updated dates

### CapitalProjects
- `id`: Primary key
- `project_name`: Project title
- `budget`: Allocated budget
- `spend`: Current expenditure
- `status`: Project status (Planning/Active/Complete)
- `timestamps`: Created/updated dates

### ShopWork
- `id`: Primary key
- `project_name`: Work order title
- `parts_list`: Required parts (JSON)
- `notes`: Additional notes (text)
- `timestamps`: Created/updated dates

## 🛡️ Security

- All containers run on isolated Docker network
- Database credentials stored in environment variables
- Application exposed only to localhost (127.0.0.1:8082)
- Public access via Nginx reverse proxy
- SSL/TLS encryption with Let's Encrypt certificates
- Laravel security features (CSRF, XSS protection)

## 📱 Mobile Compatibility

Optimized for:
- iOS Safari
- Android Chrome
- Responsive breakpoints for tablets and phones
- Touch-friendly UI components
- Fast loading with optimized assets

## 🔄 Updates & Maintenance

```bash
# Pull latest code
git pull origin main

# Rebuild containers (if needed)
docker-compose up -d --build

# Clear application cache
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan view:clear
```

## 📚 Technology Stack

- **Laravel 11**: PHP framework
- **FilamentPHP**: Admin panel builder
- **Livewire 3**: Full-stack framework
- **Alpine.js**: JavaScript framework
- **Tailwind CSS**: Utility-first CSS
- **PostgreSQL 16**: Relational database
- **Docker**: Containerization
- **Nginx**: Web server & reverse proxy

## 🤝 Inspired By

Professional fire service platforms:
- First Due
- Emergency Reporting
- PSTrax
- Station Boss

## 📄 License

Internal use only - Miami Beach Fire Department

## 👨‍💻 Support

For issues or questions, contact the IT department or submit an issue on GitHub.

---

**Deployed By**: Kilo Code Solutions
**Deployment Date**: January 2026
**Version**: 1.0.0
