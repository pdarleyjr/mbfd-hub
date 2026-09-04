# Hermes watchdog source persistence

The MBFD Hub repository is the existing deployment authority for the
server-wide watchdog and runbook surface. The EOC application repository owns
the EOC application and Hermes skills, but it does not deploy system-wide
Hermes scripts, cron state, or systemd units.

The canonical deployable artifacts are:

- `scripts/operations/mbfd-site-error-monitor.sh`
- `scripts/operations/hermes-watchdog/mbfd-eoc-watchdog.py`
- `scripts/operations/hermes-watchdog/run-hermes-bounded-summary.sh`
- `scripts/operations/hermes-watchdog/systemd/*.service`
- `scripts/operations/hermes-watchdog/systemd/*.timer`
- `scripts/operations/hermes-watchdog/managed-config.json`

`managed-config.json` records the server-managed Hermes cron jobs that must
remain paused and the legacy timer that must remain disabled. The live Hermes
`jobs.json` is runtime state and is intentionally not copied into Git.

The production deployment workflow runs the installer from the exact checked
out release SHA. `--apply` creates a timestamped backup, installs the canonical
files, enforces the paused/disabled legacy configuration, reloads systemd, and
enables the three managed timers. `--check` is non-mutating and proves
source/live byte parity plus the required runtime policy.
