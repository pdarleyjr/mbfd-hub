# Database and Data Protection Review

## Databases Identified

- MBFD Hub PostgreSQL: `mbfd-hub-pgsql`, loopback `127.0.0.1:5432`.
- MBFD Hub Redis: `mbfd-hub-redis`, loopback `127.0.0.1:6379`.
- Snipe-IT MariaDB: `mbfd-snipeit-db`, internal container port only.
- Nextcloud PostgreSQL/Redis: `mbfd-postgres`, `mbfd-redis`, internal.
- Vacation PostgreSQL/Redis: internal Docker network.
- Media Control SQLite DB in Docker volume/app data.
- Open WebUI SQLite, Qdrant vector DB.

## Positive Controls

- PostgreSQL and Redis for MBFD Hub are loopback-bound on the host.
- UFW default denies inbound.
- Redis password is configured by environment in compose evidence.

## Findings

- Snipe-IT backup credential was hardcoded in ignored `backup.sh`; script fixed, credential rotation required.
- Backups include app DBs/configs and are not confirmed encrypted/offhost.
- DB admin tools must remain local-only/Access-protected; no public DB port observed in live metadata.
- Public storage of generated PDFs/uploads can become a data-protection bypass even when DB rows are protected.

## Recommendations

1. Rotate affected DB credentials.
2. Use dedicated least-privilege backup DB users.
3. Encrypt backups before offhost upload; store encryption keys outside the same host.
4. Document and test restores quarterly.
5. Keep DB/Redis/admin tools off public routes and out of Cloudflare Tunnel unless Access-protected.
