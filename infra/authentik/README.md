# MBFD authentik operations

This stack is a separate identity-provider trust domain. It does not replace the Hub personnel roster, account status, permissions, application grants, canonical session registry, or downstream Hub federation.

## Install and upgrade

1. Copy this directory to `/opt/mbfd/authentik`, create the state directories as root, and create `/etc/mbfd/authentik.env` with mode `0600`. Generate every secret on the host; never commit or print it.
2. Validate with `docker compose --env-file /etc/mbfd/authentik.env -f compose.yaml config --quiet`, pull the exact digests, then enable `mbfd-authentik.service`.
3. Keep the origin on `127.0.0.1:9000`. Cloudflare Tunnel is the only public path. Do not publish 9000 or 9443 on a LAN or WAN address.
4. Run `configure-tenant.sh` once. It configures the MBFD Hub OAuth2/OIDC provider for authorization code flow, PKCE S256, RS256 signing, exact redirect URI `https://www.mbfdhub.com/auth/identity/callback`, and `user_uuid` subject mode. It stores the client secret and least-privilege API token outside Git, retires the bootstrap API token, and removes bootstrap values from the running containers.
5. Before an upgrade, run `backup.sh`, `restore-drill.sh` against the new dump, record the image digests, and change the pinned image only through source review. Roll back by restoring the prior compose plus dump/state archive while Hub remains in `hybrid` or `local` mode.

## Backup and recovery

`mbfd-authentik-backup.timer` creates a consistent PostgreSQL custom dump plus authentik data/cert/template state under `/opt/mbfd/authentik/backups`. The existing encrypted restic ecosystem job captures `/opt/mbfd`; confirm its snapshot after every identity backup. A local dump alone is not off-host acceptance.

`restore-drill.sh` restores a selected dump into a disposable PostgreSQL container on tmpfs and requires non-empty restored tables. It never connects to or modifies the production database.

## Security and monitoring

- The worker has no Docker socket. Server and worker are read-only, have no Linux capabilities, and use `no-new-privileges`.
- Only explicitly configured reverse-proxy CIDRs may supply forwarded headers. Recheck the actual Docker subnet after deployment and narrow it if possible.
- The five-minute health timer checks all three containers and the loopback liveness endpoint. Review failed units with `systemctl status mbfd-authentik-health.service` and bounded container logs.
- The initial `akadmin` break-glass password is held in a root-readable file outside Git. Recover locally with the documented `ak changepassword akadmin` container-console procedure; never expose that file or password in terminal evidence. Add a second named break-glass administrator only when an authoritative individual owner and secure credential destination have been supplied.
