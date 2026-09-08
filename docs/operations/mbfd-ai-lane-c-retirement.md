# MBFD AI Lane C retirement runbook

Status: `PREPARED_NOT_EXECUTED`

Evidence cutoff: 2026-09-06 00:51 UTC. This runbook prepares the authorized
OpenWebUI, Office document agent, and browser-side OnlyOffice AI retirement. It
does not authorize a worker to stop production. Shared runtime mutation remains
the Release Captain's serial responsibility.

The guard at
`scripts/operations/ai-lifecycle/lane_c_lifecycle.py` is deliberately
non-mutating. It captures allowlisted metadata, validates the frozen ownership
boundary, renders the order below, checks the final state, and produces a
mountless OnlyOffice Compose candidate. It never reads container environment
values.

## Frozen ownership

| Surface | Runtime owner | Persistent state | Final classification |
| --- | --- | --- | --- |
| OpenWebUI | `mbfd-ai` / `open-webui` | `mbfd-ai_openwebui_data` | Retire |
| Qdrant | `mbfd-ai` / `qdrant` | `mbfd-ai_qdrant_data` | Retire after two-collection recheck |
| RAG sync | `mbfd-owui-rag-sync.timer` | unit and script only | Archive metadata, disable |
| Tika | `mbfd-ai-extras` / `tika` | none | Retire after caller recheck |
| MCPO | `mbfd-ai-extras` / `mcpo` | `mbfd-ai-mcp-memory-data` | Retire; preserve volume |
| Office agent | `mbfd-office-doc-agent` / `app` | data, DB, and logs volumes | Retire; preserve all three volumes |
| doc-generator | `mbfd-ai-extras` / `doc-generator` | Nextcloud bind mount | Retire tool only; never alter Nextcloud data |
| Piper | `mbfd-ai-extras` / `tts` | `mbfd-ai-tts-voices` | Retire; preserve voices |
| general Whisper | `mbfd-ai-extras` / `whisper` | `mbfd-ai-whisper-models` | Retire; preserve models |
| Internal tools | `nextcloud-user-fs`, `nextcloud-write`, `media-control-tools` | shared application access | Retire only if final caller recheck remains OpenWebUI-only |
| OnlyOffice AI seed | three read-only binds under `/opt/mbfd-workspace/onlyoffice-ai` | no accepted rollback content | Remove mounts and seed; keep core OnlyOffice |

The following are explicit keep targets and must remain running throughout the
retirement: `searxng`, `searxng-valkey`, `comfyui`, `cmd-whisper`,
`mbfd-nextcloud`, `mbfd-onlyoffice`, and `media-control`. The extras Compose
project is shared. Never run `docker compose down` against it.

## Credential boundary

The legacy bridge credential and four OpenWebUI tool credentials are
compromised. Their values must not be read into a shell variable, displayed,
copied into an archive, or restored. Safe evidence is limited to file presence,
permissions, key names, and fingerprints.

- Do not archive any `openwebui-extras.env*` file.
- Do not archive the three OnlyOffice AI seed files; archive only their
  path/size/hash receipt.
- The OpenWebUI SQLite database has no row containing the four affected tool
  service identifiers as of the evidence cutoff. The connection blob is an
  environment value, not accepted rollback state.
- At the evidence cutoff, `TOOL_SERVER_CONNECTIONS` was present in exactly the
  following six protected files (presence was checked without displaying its
  value):
  `/opt/ai-stack/openwebui-extras.env`,
  `/opt/ai-stack/openwebui-extras.env.bak.1779878348`,
  `/opt/ai-stack/openwebui-extras.env.bak.1779878372`,
  `/opt/ai-stack/openwebui-extras.env.bak-20260609-121231`,
  `/opt/ai-stack/openwebui-extras.env.bak.img.1780224356`, and
  `/opt/ai-stack/openwebui-extras.env.bak-kilo-20260615-165330`.
- A rollback must provision new credentials at every restored service and every
  retained caller before starting anything. There is no credential rollback.

The legacy credential cannot be invalidated until the support Worker is proven
migrated away from the old bridge. That migration, public gateway ingress,
credential rotation, firewall changes, and bridge retirement are outside this
lane and must be completed serially by the Release Captain.

## Release Captain sequence

### 1. Re-run the exact preflight

Use a clean checkout at the merged release SHA. Choose a unique root-only
archive child; never reuse an earlier path.

```bash
set -Eeuo pipefail
umask 077
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
archive="/mnt/mbfd-storage/backups/on-demand/mbfd-ai-lane-c-${stamp}"
sudo install -d -m 0700 "$archive"
sudo python3 scripts/operations/ai-lifecycle/lane_c_lifecycle.py \
  validate --phase preflight --output "$archive/preflight.json"
```

The preflight fails if a source hash, Compose owner, mount, Qdrant collection,
timer, retained service, Office source SHA, or rollback volume differs from the
Phase 1 freeze. Drift is a stop condition, not permission to edit the guard.

Immediately recheck aggregate traffic and source references without printing
headers, request bodies, environment values, or full logs. The signed release
receipt must explicitly adjudicate each conditional internal tool. Any new
caller changes the plan and blocks retirement.

### 2. Create a protected, credential-free rollback archive

Before stopping anything, record:

1. the preflight JSON and exact release source SHA;
2. safe hashes, modes, owners, image identities, Compose labels, mounts, and
   network names;
3. environment **key names only** and hashes of excluded env files;
4. an online SQLite backup of `/app/backend/data/webui.db`;
5. all other OpenWebUI data-volume content, excluding the live SQLite file and
   temporary backup file;
6. native Qdrant snapshots for exactly `open-webui_files` and
   `open-webui_knowledge`;
7. cold or application-consistent archives of all three Office-agent volumes;
8. the MCPO memory volume; and
9. a SHA-256 manifest verified before the first stop.

The archive must be mode `0700`; files must be mode `0600`. Use SQLite's online
backup API and Qdrant's collection snapshot API rather than copying a live
database file. Preserve the TTS voice and general-Whisper model volumes in
place. Preserve `/opt/ai-stack`, `/opt/ai-stack/extras`, and the clean Office
source checkout until rollback acceptance expires.

Do not put Nextcloud's data bind into this retirement archive. The
`doc-generator` mount is proof of shared ownership, not authority to copy,
delete, or change Nextcloud data.

### 3. Disable polling before stopping OpenWebUI

Archive only the unit/script source and safe hashes, then:

```bash
sudo systemctl disable --now mbfd-owui-rag-sync.timer
systemctl is-enabled mbfd-owui-rag-sync.timer  # expected: disabled
systemctl is-active mbfd-owui-rag-sync.timer   # expected: inactive
```

Do not disable Hermes timers. SearXNG remains required by Hermes.

### 4. Stop the retirement set without touching shared services

Set `restart=no` on the exact reviewed containers before stopping them. Stop
OpenWebUI first, then its data/provider services. The conditional tools may be
included only when their final caller receipt passed.

```bash
sudo docker update --restart=no \
  open-webui qdrant tika mcpo mbfd-office-doc-agent doc-generator \
  piper-tts whisper-stt

sudo docker stop -t 60 open-webui
sudo docker stop -t 60 qdrant tika mcpo mbfd-office-doc-agent \
  doc-generator piper-tts whisper-stt
```

If and only if the caller recheck remains exclusive:

```bash
sudo docker update --restart=no \
  nextcloud-user-fs nextcloud-write media-control-tools
sudo docker stop -t 60 \
  nextcloud-user-fs nextcloud-write media-control-tools
```

At this point leave containers, images, networks, and volumes present. Do not
remove anything until the regression gate passes.

### 5. Remove the browser-side OnlyOffice AI seed

The live Compose file has exactly three AI bind mounts. Its frozen SHA-256 is
`425600f504781c92a6b70bf16d5a64a340df9d1f8968fe61f1e04204158dff32`.
Generate a separate candidate; the helper refuses in-place editing, missing or
duplicate mounts, an unknown AI reference, and hash drift.

```bash
cd /opt/mbfd-workspace
sudo python3 /path/to/release/scripts/operations/ai-lifecycle/lane_c_lifecycle.py \
  scrub-onlyoffice-compose \
  --input /opt/mbfd-workspace/docker-compose.yml \
  --output /opt/mbfd-workspace/docker-compose.lane-c.yml \
  --expected-sha256 425600f504781c92a6b70bf16d5a64a340df9d1f8968fe61f1e04204158dff32
sudo docker compose --project-directory /opt/mbfd-workspace \
  -f /opt/mbfd-workspace/docker-compose.lane-c.yml config --quiet
```

Copy the original Compose file—not the seed contents—into the root-only archive.
Install the validated candidate at the canonical path and recreate only the
`onlyoffice` service. Never recreate Nextcloud, PostgreSQL, Redis, cron, or
ClamAV as part of this step.

```bash
sudo install -m 0600 /opt/mbfd-workspace/docker-compose.yml \
  "$archive/onlyoffice-compose.before.yml"
sudo install -m 0644 /opt/mbfd-workspace/docker-compose.lane-c.yml \
  /opt/mbfd-workspace/docker-compose.yml
sudo docker compose --project-directory /opt/mbfd-workspace \
  -f /opt/mbfd-workspace/docker-compose.yml \
  up -d --no-deps --force-recreate onlyoffice
```

Prove the new container is healthy, the three mounts are absent, the public and
internal health endpoints pass, and Nextcloud's
`onlyoffice:documentserver --check` passes. Then delete the three exact seed
files and remove the empty directory. Do not archive their contents and do not
restore the old AI mounts on failure. Roll forward to a healthy core-only
OnlyOffice service.

### 6. Retire compromised connection state

After all affected callers/endpoints have been stopped or migrated, delete each
of the six exact protected files listed above one by one, including the active
file. This eliminates both the active OpenWebUI connection blob and every
observed historical copy. The safe receipt retains only path, owner, mode,
size, SHA-256, and the `TOOL_SERVER_CONNECTIONS` key name. Never use a
recursive delete or wildcard without first resolving and recording the exact
file list; any added or missing copy is a stop-and-reinventory condition.

The final caller receipt must contain one decision for each exposed tool
credential, without its value:

| Credential owner | Retire path | Retain path |
| --- | --- | --- |
| `nextcloud-user-fs` | Stop the endpoint and retire its credential. | Rotate at the service and every newly proven caller before acceptance. |
| `doc-generator` | Stop the endpoint and retire its credential. | If a new caller blocks retirement, rotate at the service and that caller. |
| `nextcloud-write` | Stop the endpoint and retire its credential. | Rotate at the service and every newly proven caller before acceptance. |
| `media-control-tools` | Stop the endpoint and retire its credential. | Rotate at the service and every newly proven caller before acceptance. |

Credential invalidation must also cover the old bridge bearer, but only after
the support Worker has migrated and old-route traffic is absent. Never copy a
prior value into a new secret store. A stopped endpoint with a still-valid
credential, or a retained endpoint with an unrotated credential, fails the
release.

### 7. Regression and postcheck

First run the retained-service and listener regression below while every
retirement container is stopped and still recoverable. Only after that gate
passes, remove the exact retired containers so the connection blob and
service-side credential configuration no longer survive in Docker metadata.
Do not remove any image, network, or volume.

```bash
sudo docker rm \
  open-webui qdrant tika mcpo mbfd-office-doc-agent doc-generator \
  piper-tts whisper-stt
```

Remove `nextcloud-user-fs`, `nextcloud-write`, and `media-control-tools` only
when the signed caller receipt chose retirement. If any is retained, this
frozen standard postcheck is blocked: rotate its credential, recreate that one
service without the compromised value, preserve the core application, and
produce a reviewed updated postcheck contract before acceptance.

Capture a fresh final snapshot and run:

```bash
sudo python3 scripts/operations/ai-lifecycle/lane_c_lifecycle.py \
  validate --phase postcheck --output "$archive/postcheck.json"
```

In addition to the guard, prove:

- OpenWebUI `3030`, Qdrant `6333`, and Office agent `8789` are no longer
  listening;
- SearXNG and its Valkey are healthy and Hermes can still use search;
- ComfyUI `/system_stats` and `/queue` remain healthy without deleting models;
- Command's independent `cmd-whisper` remains healthy;
- Nextcloud is healthy and its data volume was unchanged;
- core OnlyOffice is healthy and opens through the existing connector;
- Media Control health/version and deterministic playback/control smoke pass;
- the retained volumes listed in the ownership table still exist; and
- no retired container or timer can restart automatically.

The guard also requires all six connection files to be absent and confirms,
using Docker-rendered environment key names only, that
`TOOL_SERVER_CONNECTIONS` is absent from any surviving OpenWebUI container
configuration. Only after this gate and the Release Captain's bounded soak may
reproducible images be removed. Keep rollback volumes and archives until
explicit retention approval. Never remove the shared `mbfd-ai` network while
retained services use it.

## Rollback

Rollback is data/config recovery, not credential recovery.

1. Verify the archive SHA-256 manifest and exact source receipts.
2. Provision new credentials for every restored service and caller; create a
   new connection configuration from key names only.
3. Restore OpenWebUI SQLite/data, Qdrant snapshots, and Office volumes to the
   same named volumes.
4. Restore only reviewed service definitions and set their intended restart
   policy.
5. Re-enable the RAG timer only after OpenWebUI and the new Nextcloud tool
   credential pass.
6. Keep OnlyOffice core mountless. Reintroducing browser AI would require a new
   design with no static browser credential and a separate authorization.

The Office public tunnel/DNS route may be removed only by the Release Captain
after origin retirement passes. If restored later, it requires a fresh route
review and new application credentials. No broad tunnel configuration rollback
is allowed.

## Stop conditions

Do not proceed if any of the following is true:

- support-chat generation still uses the legacy bridge;
- a conditional tool has a retained caller;
- Qdrant contains a collection outside the two frozen OpenWebUI collections;
- the source/mount/container hashes differ;
- an archive includes an env file or seed content;
- the archive lacks a verified SQLite/Qdrant/Office snapshot;
- a retained service is not healthy before mutation; or
- the Release Captain cannot rotate/invalidate every affected credential.

Prepared result: `LANE_C_LIFECYCLE_SOURCE_READY_FOR_RELEASE_CAPTAIN`
