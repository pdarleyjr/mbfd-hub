---
name: mbfd-production-incident-triage
description: Diagnose MBFD production incidents with layered evidence.
license: MIT
metadata:
  hermes:
    tags:
      - mbfd
      - incident-response
      - production
      - cloudflare
      - docker
    related_skills:
      - systematic-debugging
---

# MBFD Production Incident Triage

Use this skill for MBFD Hub availability, authentication, Cloudflare, Docker,
LiveKit, or GMKtec production incidents.

## Non-negotiable boundaries

- Begin read-only. Change production only when the operator explicitly asks for
  a fix, and make one evidence-linked change at a time.
- Never alter Tailscale, VPN, firewall, DNS, or routes without explicit
  authorization. Record Tailscale state before and after approved network work.
- Never print, paste, move, or persist secrets. Verify a secret only by presence,
  source, or redacted length. Do not use commands that reveal configuration
  values such as application keys.
- Preserve dirty worktrees and operator files. Use an isolated worktree for
  source changes and make a dated rollback copy before production edits.
- Separate software evidence from manual hardware, browser-profile, user-auth,
  and physical-device acceptance. Never label an unobserved gate as verified.

## Evidence order

Collect and correlate these layers before assigning a root cause:

1. Client reachability, DNS, TLS, redirect chain, and exact HTTP status.
2. Cloudflare tunnel health and current connector logs.
3. Origin loopback response and reverse-proxy/service status.
4. Docker container state, health, restarts, OOM state, and current processes.
5. Complete application log entries with the first relevant application,
   route, or fixture stack frames. Do not diagnose from the error header alone.
6. Source SHA, deployed SHA or release marker, and the running artifact.
7. The exact failing user path, identity, browser profile, or physical device.

Use `/opt/mbfd/mbfd-hub` as the MBFD Hub source checkout. Its production Compose
file is `compose.prod.yaml`. Core containers include `mbfd-hub-laravel`,
`mbfd-hub-pgsql`, and `mbfd-hub-redis`. LiveKit is a separate stack whose core
container is `mbfd-livekit-server`. The Cloudflare connector is the
`cloudflared` systemd service.

## State classification

- **Healthy**: current public and origin probes pass and core runtime is healthy.
- **ApplicationEvent**: a new application error exists, but current availability
  probes and core runtime remain healthy.
- **Degraded**: a verified user path or a required component is failing while
  some service remains available.
- **Outage**: the requested user path fails and current client, tunnel, origin,
  or core-runtime evidence corroborates broad unavailability.

An isolated log entry is not proof of a current outage. Successful public HTTP
checks control the global availability state unless a narrower user path has a
separately reproduced failure.

## Known interpretation rules

- `php artisan serve` inside `mbfd-hub-laravel` is the expected Docker process;
  it does not prove that the application is running outside Docker.
- A LiveKit webhook JWT rejection is not MBFD employee or admin authentication.
- A missing LiveKit room does not prove that LiveKit is down. If the stack points
  to `/tmp/video-conference-fixture.php`, treat it as disposable fixture cleanup
  unless independent evidence shows a user-facing failure.
- PsySH or Tinker parse errors are operator/test events, not service state.
- State the timezone for every timestamp and do not merge events from different
  times into one causal chain without evidence.
- Never infer container, process, secret, or configuration state when that state
  was not present in the collected evidence.

## Required workflow

1. Reproduce the exact failing path.
2. Observe every relevant layer in the evidence order above.
3. Trace the complete request and stack to the first owned code or configuration
   boundary.
4. Identify one root cause and list competing hypotheses that the evidence rules
   out.
5. Back up the exact target and record a rollback command.
6. Make the smallest correction with a regression test.
7. Reverify source identity, installed artifact, runtime health, public behavior,
   and any separate user or physical acceptance gate.

Report `verified`, `recovered`, `not reproduced`, and `pending` claims separately.
Include exact, redacted evidence and rollback location. If evidence is missing,
say so rather than guessing.
