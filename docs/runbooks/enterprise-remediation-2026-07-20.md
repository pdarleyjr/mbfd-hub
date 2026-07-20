# MBFD Hub Enterprise Remediation Runbook

This runbook records the reversible operational controls introduced during the
2026-07-20 incident-response and reliability pass. It intentionally contains no
credentials, customer data, or raw application payloads.

## Protected recovery evidence

- Local pre-change evidence: `D:\MBFD-remediation-20260720-081719`
- GMKtec pre-change evidence: `/var/lib/mbfd-remediation/20260720-081719`
- Restic repository: `/mnt/mbfd-backup-local/restic/mbfd-ecosystem`
- Initial verified snapshot: `a6668c9b`

The protected directories are restricted to the workstation user/SYSTEM and
GMKtec root respectively. Do not copy credentials out of those directories or
commit their contents.

## Managed services and timers

| Capability | Service/timer | Validation |
| --- | --- | --- |
| Encrypted ecosystem backup | `mbfd-ecosystem-backup.timer` | Restic check plus database validation |
| Representative restore test | `mbfd-ecosystem-restore-smoke.timer` | Restores DB/config samples into an isolated temporary directory |
| Ollama model warmup | `ollama-warmup.service` | Confirms the storage mount and `qwen3.6:35b` inference |
| Internal/public origin health | `mbfd-origin-monitor.timer` | Tracks health, recovery, and camera playlist/segment advancement |
| Main/admin error classification | `mbfd-site-error-monitor.timer` | Filters expected client cancellations and deduplicates incidents |
| Conservative OOM priorities | `mbfd-oom-priority.timer` | Reapplies bounded runtime priorities after restarts |

## Credential containment

- Cloudflared reads its tunnel token through systemd `LoadCredential`; it is not
  present in the unit or process arguments.
- The Ollama proxy reads its API key through `LoadCredential`, runs as the
  unprivileged `ollama-proxy` user, and listens only on `127.0.0.1:11440`.
- OBS websocket credentials are absent from the unit and process arguments.
- Repository hooks run Gitleaks before commits and pushes and fail closed when
  the configured scanner is missing.

Externally administered credentials that were disclosed must still be revoked
and replaced in GitHub and Cloudflare. That step requires authenticated control
plane access and coordinated consumer updates; local containment is not a
substitute for provider-side rotation.

## Routine verification

```bash
sudo systemctl --failed
sudo docker ps --filter health=unhealthy
sudo /opt/mbfd/runbooks/mbfd-origin-monitor-status.sh
sudo /opt/mbfd/runbooks/mbfd-oom-priority.sh status
sudo /opt/mbfd/runbooks/mbfd-service-inventory.sh
sudo restic -r /mnt/mbfd-backup-local/restic/mbfd-ecosystem check
```

## Rollback boundaries

- Restore service files from the matching subdirectory beneath
  `/var/lib/mbfd-remediation/20260720-081719`, run `systemctl daemon-reload`, and
  restart only the affected service.
- Reset runtime OOM priorities with
  `sudo /opt/mbfd/runbooks/mbfd-oom-priority.sh reset`.
- Do not restore the original Git history to a shared remote. The original bare
  mirror is incident evidence and still contains revoked historical material.
