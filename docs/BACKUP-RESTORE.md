# Backup & Restore Procedure

## Backup Schedule

| Type | Frequency | Location | Retention |
|------|-----------|----------|-----------|
| Pre-deploy | Every deployment | `/opt/mbfd/backups/daily/pre_deploy_*.dump` | 7 days |
| Daily scheduled | 2:00 AM ET daily | `/opt/mbfd/backups/daily/daily_*.dump` | 7 days |

## Manual Backup

```bash
ssh mbfd-ubuntu
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
docker exec mbfd-hub-pgsql-1 pg_dump -U mbfd_user -Fc mbfd_hub > /opt/mbfd/backups/daily/manual_${TIMESTAMP}.dump
```

## Restore Procedure

### 1. Stop the application (optional but recommended)

```bash
ssh mbfd-ubuntu
cd /opt/mbfd/mbfd-hub
docker compose -f compose.prod.yaml stop laravel.test
```

### 2. Restore database from dump

```bash
# List available backups
ls -lt /opt/mbfd/backups/daily/*.dump | head -10

# Restore (replace FILENAME with actual backup file)
docker exec -i mbfd-hub-pgsql-1 pg_restore \
  -U mbfd_user \
  -d mbfd_hub \
  --clean \
  --if-exists \
  --no-owner \
  < /opt/mbfd/backups/daily/FILENAME.dump
```

### 3. Restart the application

```bash
cd /opt/mbfd/mbfd-hub
docker compose -f compose.prod.yaml up -d
# Wait for health check
docker exec mbfd-hub-laravel curl -sf http://localhost:80

# Clear caches
docker exec mbfd-hub-laravel php artisan optimize:clear
docker exec mbfd-hub-laravel php artisan config:cache
docker exec mbfd-hub-laravel php artisan route:cache
```

### 4. Verify

```bash
curl -sI https://www.mbfdhub.com/ | head -5
curl -sI https://www.mbfdhub.com/admin/login | head -5
```

## Restore to a Different Database

If you need to inspect a backup without affecting production:

```bash
# Create a temporary database
docker exec mbfd-hub-pgsql-1 createdb -U mbfd_user mbfd_hub_restore

# Restore into temporary database
docker exec -i mbfd-hub-pgsql-1 pg_restore \
  -U mbfd_user \
  -d mbfd_hub_restore \
  --no-owner \
  < /opt/mbfd/backups/daily/FILENAME.dump

# Connect and inspect
docker exec -it mbfd-hub-pgsql-1 psql -U mbfd_user -d mbfd_hub_restore

# Drop when done
docker exec mbfd-hub-pgsql-1 dropdb -U mbfd_user mbfd_hub_restore
```

## Backup Monitoring

Check backup logs:
```bash
ssh mbfd-ubuntu "tail -20 /opt/mbfd/backups/backup.log"
```

Verify cron is scheduled:
```bash
ssh mbfd-ubuntu "crontab -l | grep backup"
```
