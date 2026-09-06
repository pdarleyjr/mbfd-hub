# MBFD AI Gateway release runbook

The canonical gateway owner is this repository. Production may be changed only
from a clean checkout whose `HEAD` equals an exact 40-character `origin/main` SHA.
The deployment helper rejects a branch tip, dirty tracked files, a stale
remote-tracking ref, or a candidate that is not a descendant of the currently
recorded production source.

## Persisted release surface

The source of truth is `scripts/operations/`:

- `mbfd_ai_gateway.py` — provider-neutral gateway implementation;
- `mbfd-ai-gateway.json` — secret-free live registry template;
- `ollama-ai-proxy.service` — systemd service and credential bindings;
- `migrate-ollama-ai-proxy.sh` — guarded update and rollback transaction;
- `provision-mbfd-ai-gateway-consumers.sh` — idempotent, non-disclosing
  per-consumer credential provisioning;
- `verify-ollama-ai-proxy.sh` — provenance, parity, listener, auth, and smoke gate;
- `mbfd-ai-gateway-smoke.py` — inference-free authenticated smoke client;
- `mbfd_ai_gateway_release.py` — exact-source and stale-source guard;
- `mbfd-ai-gateway-ingress.json` — public-ingress declaration.

Credentials remain root-owned mode-0600 files under `/etc/ollama-ai-proxy`.
The PRM consumer uses `sports-intelligence-api-key`, provisioned out of band and
copied to the Sports secret store as `mbfd_ai_gateway_credential`. The retained
MBFD consumers each use a different `<consumer>-api-key` file. Before deploying
the expanded registry, run `provision-mbfd-ai-gateway-consumers.sh` as root. It
creates only missing credentials, refuses unsafe existing paths or modes,
checks uniqueness, and reports only short SHA-256 fingerprints. Never place a
credential on a command line, in Git, in this runbook, browser JavaScript, or
release evidence. Deployment and smoke clients read credentials internally and
print no value.

Port 11440 is the canonical gateway. Port 11441 belonged to the rejected BID
experiment; it must remain closed and must not be reused. Raw Ollama port 11434
is not locked down by this source-persistence procedure; that is a later,
explicit consolidation gate.

The `sports-intelligence` consumer is restricted to the
`prm-sports-research` capability at concurrency one. That capability targets
the private Sports Ollama backend and the validated `qwen3.5:9b` model through
the global `gpu-heavy` admission lease. PRM requests retain `keep_alive=0` so
the Sports model unloads after every bounded job.

The retained MBFD consumer/capability pairs are fail closed:

- `mbfd-hub`, `media-control`, `command`, `ts-orchestrator`, and
  `mbfd-support-ai` -> `mbfd-general`;
- `hermes` -> `mbfd-ops-summary`;
- `eoc` -> `mbfd-eoc-grounding` on the private EOC provider;
- `external-coding` -> `mbfd-code`.

The initial `mbfd-general` rollout uses the stable `qwen3.6:35b` binding. This
physical binding is gateway-only and must never appear in an application
configuration. Applications send their logical capability as both the request
model and `X-MBFD-Capability`, authenticate with their own credential, and send
an `X-Request-ID`. The gateway rejects a non-legacy request when the capability
header is absent or differs from the request model, and it never resolves a
compatibility alias for a non-legacy identity. There is no silent capability
substitution. Only the explicitly marked `legacy-11440` identity retains the
temporary compatibility-alias behavior needed for bounded migration; remove
that identity after every registered application has cut over.

The two private listeners are `127.0.0.1:11440` and
`172.20.0.1:11440`. The latter is the retained `mbfd-ai` bridge gateway and
replaces the legacy bridge-owned `172.20.11.1` address so retirement of ports
11435/11438 cannot remove the gateway's container ingress.

## Candidate gate

On the production host, fetch `origin/main`, check out the exact merged SHA in a
clean deployment checkout, and confirm the remote-tracking ref equals it. Run
the repository tests and the inference-free predeploy smoke before restarting
the service. The deployer repeats config, Python, Bash, systemd, provenance, and
credential-mode checks before making a backup or changing a live file.

The first source-persisted deployment has no prior
`/etc/ollama-ai-proxy/deployment-source.json`. That one transition therefore
requires the explicit one-time flag:

```bash
sudo scripts/operations/migrate-ollama-ai-proxy.sh \
  "$PWD/scripts/operations" \
  "$(git rev-parse HEAD)" \
  --initialize-source-state
```

Do not use `--initialize-source-state` after the marker exists. Routine updates
omit it:

```bash
sudo scripts/operations/migrate-ollama-ai-proxy.sh \
  "$PWD/scripts/operations" \
  "$(git rev-parse HEAD)"
```

Both forms require `HEAD` and `refs/remotes/origin/main` to resolve to the same
exact SHA. The script writes a secret-free, hash-bound
`deployment-source.json`, restarts the gateway, and calls the verifier. A pass
ends with `GATEWAY_CANONICAL_SOURCE=PASS`.

## Independent verification

```bash
sudo scripts/operations/verify-ollama-ai-proxy.sh \
  "$PWD/scripts/operations" \
  "$(git rev-parse HEAD)"
```

This verifies the protected source ancestry, marker, source/live hashes, file
modes, enabled service, exact two-address listener scope, unauthenticated 401,
authenticated health and catalog behavior, primary backend health, absence of
11441, and `GATEWAY_CANONICAL_SOURCE=PASS`. It does not invoke inference.

## Rollback

Every deployment creates a root-only directory under
`/var/backups/mbfd-ai-gateway/`. If any install, restart, parity, listener, auth,
or smoke check fails, the deployer restores the prior implementation, config,
unit, and `deployment-source.json`, then restarts the prior service. During the
one-time initialization, rollback removes the newly introduced marker because
there was no prior marker.

For a later operator-directed rollback, choose the exact backup created by the
failed/current release, validate its `SHA256SUMS`, restore only its `.before`
files to their documented live paths and modes, reload systemd, restart the
service, and run the verifier against the restored marker's exact source SHA.
Do not delete backup evidence or substitute another source revision.
