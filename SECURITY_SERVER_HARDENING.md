# GMKtec Ubuntu Server Hardening Review

## Safe Live Metadata Collected

- Host reachable as `mbfd@gmktec` with BatchMode SSH.
- OS: Ubuntu 26.04 LTS, kernel `7.0.0-15-generic`.
- Pending package updates count from `apt list --upgradable`: 6 lines reported.
- UFW: active, default deny incoming, allow outgoing/routed, logging low.
- SSH effective config: root login disabled, password auth disabled, public key auth enabled, keyboard-interactive disabled, max auth tries 3, X11 forwarding disabled.
- fail2ban, unattended-upgrades, cloudflared, tailscale, docker, chrony, smartmontools active.
- Most Docker-published service ports bind to `127.0.0.1`.
- `cloudflared` active/enabled.

## Positive Controls

- No PostgreSQL/Redis public bind observed; both loopback-bound.
- Most admin/AI/observability ports are loopback-only.
- Firewall posture is default deny inbound.
- SSH hardening is already strong.
- Time sync is enabled.

## Findings

| Severity | Finding | Evidence | Recommendation |
|---|---|---|---|
| Medium | Ollama listens on `*:11434` | `ss` shows wildcard bind; UFW allows only container subnet | Prefer loopback bind if practical; keep UFW deny inbound and audit after updates |
| Medium | Several containers use `latest`/mutable images | Docker inventory: Open WebUI `main`, tool images `latest`, Snipe-IT `latest`, Web-Check `latest` | Pin digest/version after testing |
| Medium | Backups not confirmed encrypted/offhost | Repo scripts/docs only show local backup flows | Implement encrypted offhost backups and restore drills |
| Medium | Docker socket remains exposed to Dozzle | Dozzle still needs socket access for logs | Replace with restricted socket proxy; keep loopback-only |
| Low | Package updates pending | 6 upgradable lines reported | Apply during maintenance and verify container health |

## Manual Approval Required

- Patching host packages and rebooting if needed.
- Changing Ollama host bind from wildcard to loopback if any service depends on host networking.
- Replacing Dozzle socket mount with a proxy.
