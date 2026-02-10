# Baserow Self-Hosting Guide

Self-hosted Baserow instance running alongside the MBFD Hub Laravel application via Docker Compose.

**URL:** `https://baserow.support.darleyplex.com`

---

## Docker Compose Configuration

Baserow is added as a service in [`compose-vps-fixed.yaml`](../compose-vps-fixed.yaml:44) using the official all-in-one image (`baserow/baserow:latest`).

Key configuration:
- **Port binding:** `127.0.0.1:8082:80` — localhost-only, not publicly accessible
- **Volume:** `baserow_data:/baserow/data` — persistent storage for all Baserow data
- **Network:** Shares the `sail` bridge network with the Laravel stack
- **Restart:** `unless-stopped`

Baserow runs independently from the Laravel app — it has its own embedded PostgreSQL, Redis, and Celery workers inside the all-in-one container.

---

## Required Environment Variables

Add these to your `.env` file on the VPS:

| Variable | Description | Example |
|---|---|---|
| `BASEROW_PUBLIC_URL` | Public URL for Baserow | `https://baserow.support.darleyplex.com` |
| `BASEROW_ADMIN_EMAIL` | Initial admin account email | `admin@mbfd.org` |
| `BASEROW_ADMIN_PASSWORD` | Initial admin account password | (strong password) |
| `BASEROW_SECRET_KEY` | Django secret key for signing | (generate with `openssl rand -hex 32`) |

Generate the secret key:
```bash
openssl rand -hex 32
```

---

## Reverse Proxy Setup (Nginx)

Baserow is bound to `127.0.0.1:8082` and requires an nginx reverse proxy to serve traffic on port 443.

Add this server block to your nginx configuration (e.g., `/etc/nginx/sites-available/baserow`):

```nginx
server {
    listen 443 ssl http2;
    server_name baserow.support.darleyplex.com;

    ssl_certificate /etc/ssl/certs/your-cert.pem;
    ssl_certificate_key /etc/ssl/private/your-key.pem;

    client_max_body_size 100M;

    location / {
        proxy_pass http://127.0.0.1:8082;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

        # WebSocket support (needed for real-time collaboration)
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}

server {
    listen 80;
    server_name baserow.support.darleyplex.com;
    return 301 https://$host$request_uri;
}
```

> **Note:** If using Cloudflare in Full (Strict) SSL mode, you can use a Cloudflare Origin Certificate for the SSL cert.

Enable and test:
```bash
sudo ln -s /etc/nginx/sites-available/baserow /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## Cloudflare DNS & Access

### DNS Record
Create an **A record** in Cloudflare DNS:
- **Name:** `baserow.support`
- **Content:** Your VPS IP (e.g., `145.223.73.170`)
- **Proxy:** Enabled (orange cloud)

### SSL/TLS
Ensure Cloudflare SSL mode is **Full (Strict)** with a valid origin certificate on the VPS.

### Cloudflare Access (Optional)
To restrict access to authorized users only, create a Cloudflare Access application:
1. Go to **Zero Trust → Access → Applications**
2. Add application → Self-hosted
3. Application domain: `baserow.support.darleyplex.com`
4. Add an access policy (e.g., email domain `@mbfd.org`)

---

## Deployment

### Initial Deploy
```bash
# From the project root on the VPS
docker compose -f compose-vps-fixed.yaml up -d baserow
```

### Verify
```bash
docker compose -f compose-vps-fixed.yaml ps baserow
curl -s http://127.0.0.1:8082/api/settings/ | head -c 200
```

### View Logs
```bash
docker compose -f compose-vps-fixed.yaml logs -f baserow
```

---

## Backup & Restore

### Backup
The `baserow_data` volume contains all Baserow data (database, media, etc.).

```bash
# Stop Baserow first for a consistent backup
docker compose -f compose-vps-fixed.yaml stop baserow

# Backup the volume
docker run --rm -v baserow_data:/data -v $(pwd)/backups:/backup \
  alpine tar czf /backup/baserow-backup-$(date +%Y%m%d).tar.gz -C /data .

# Restart
docker compose -f compose-vps-fixed.yaml start baserow
```

### Restore
```bash
docker compose -f compose-vps-fixed.yaml stop baserow

docker run --rm -v baserow_data:/data -v $(pwd)/backups:/backup \
  alpine sh -c "rm -rf /data/* && tar xzf /backup/baserow-backup-YYYYMMDD.tar.gz -C /data"

docker compose -f compose-vps-fixed.yaml start baserow
```

---

## Upgrade Procedure

```bash
# Pull latest image
docker compose -f compose-vps-fixed.yaml pull baserow

# Recreate with new image (data persists in volume)
docker compose -f compose-vps-fixed.yaml up -d baserow

# Check logs for migration output
docker compose -f compose-vps-fixed.yaml logs -f baserow
```

> The all-in-one image handles database migrations automatically on startup.

---

## Port Map

| Service | Internal Port | Host Binding | Purpose |
|---|---|---|---|
| Laravel | 80 | `0.0.0.0:80` | Main app |
| Vite | 5173 | `127.0.0.1:5173` | Dev HMR |
| PostgreSQL | 5432 | `127.0.0.1:5432` | Laravel DB |
| **Baserow** | **80** | **`127.0.0.1:8082`** | **Baserow UI/API** |

No port conflicts exist between services.

---

## Troubleshooting

- **Container won't start:** Check logs with `docker compose -f compose-vps-fixed.yaml logs baserow`
- **502 from nginx:** Ensure the Baserow container is running and healthy; it can take 30–60 seconds to initialize
- **WebSocket errors:** Ensure `Upgrade` and `Connection` headers are set in nginx config
- **Admin account not created:** The admin is only created on first startup with an empty database. If you need to reset, remove the volume and restart.
