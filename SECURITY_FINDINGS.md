# Security Findings

Severity uses Critical, High, Medium, Low.

## Critical

### C-01: Exposed credentials require rotation

Evidence: User-provided prompt included Cloudflare, R2, and GitHub credentials; local `.git/config` also contained an embedded GitHub PAT in remote URLs and was sanitized.  
Impact: Any copied transcript, shell history, local config backup, or log can enable account/repo/cloud access.  
Fix: Removed PAT-bearing Git remotes locally.  
Required: Rotate GitHub PAT, Cloudflare Wrangler/API tokens, Cloudflare R2 token/key pair, and any repo/org secrets using those values. Values are intentionally not repeated.

### C-02: Hardcoded Snipe-IT DB password in ignored backup script

Evidence: `backup.sh` contained a literal MariaDB root password before remediation.  
Impact: Source/local backups/process history exposure can compromise Snipe-IT DB.  
Fix: Replaced literal password with `SNIPEIT_DB_PASSWORD` loaded from a protected env file path.  
Required: Rotate the Snipe-IT DB password and create `/opt/mbfd/secrets/snipeit-backup.env` with `0600` permissions.

## High

### H-01: Public apparatus inspection can affect operational status

Evidence: `routes/api.php` exposes `POST /api/public/apparatuses/{apparatus}/inspections`; controller can mark apparatus out of service.  
Impact: Unauthenticated operational integrity/availability risk.  
Status: Not changed to avoid breaking current daily workflow.  
Required: Convert to signed/PIN/authenticated submission with pending-review state before operational status mutation.

### H-02: Public station APIs expose internal operational data

Evidence: public station routes expose assets, projects, equipment requests, gas meter serials, inspections, and personnel/operator details.  
Impact: Reconnaissance, social engineering, and operational privacy risk.  
Status: Documented for staged remediation.  
Required: Split public resources from internal dashboards and redact sensitive fields.

### H-03: Workgroup reports/exports/AI lacked workgroup authorization

Evidence: Workgroup routes used `auth` only outside the Filament panel.  
Impact: Any authenticated user could potentially access workgroup reports/exports.  
Fix: Added `workgroup.access` alias and applied it to report, export, file, and AI routes; added regression tests.

### H-04: Public/legacy inventory PDFs and workgroup uploads use public storage

Evidence: station inventory PDFs and workgroup shared uploads are stored under Laravel public disk paths.  
Impact: URL/path leakage bypasses controller authorization.  
Status: Not changed due migration risk.  
Required: Move sensitive/generated files to private disk/R2 and serve through signed or authorized controller routes.

### H-05: Media Control TUS uploads bypassed multipart MIME allowlist

Evidence: `server/lib/finalize-upload.js` trusted TUS metadata.  
Impact: Authenticated user could upload script-capable content for same-origin public serving.  
Fix: Shared upload MIME allowlist; rejects SVG/HTML/JavaScript and unknown types; added tests.

### H-06: Media Control legacy provisioning returned full device rows

Evidence: legacy `/api/provision` paired by code and returned `SELECT *`.  
Impact: Pairing disruption and possible token/field disclosure.  
Fix: Disabled legacy endpoint with 410; `/api/provision/pair` now requires write-tier workspace access and returns minimized fields.

### H-07: Cloudflare Access coverage is not live-verified

Evidence: repo/docs show many hostnames and a documented ONLYOFFICE Access bypass; Cloudflare MCP auth failed 403.  
Impact: A Tunnel route without Access is public internet exposure even with no open router ports.  
Required: Export and reconcile live DNS/Tunnel/Access/WAF rules.

## Medium

### M-01: CI security gates were advisory-only

Fix: Composer audit, npm audit, Trivy high/critical, and CodeQL are now blocking in workflows.

### M-02: Production deploy used `npm install`

Fix: Replaced with deterministic `npm ci --legacy-peer-deps` in deploy workflow.

### M-03: Troubleshooting workflow could print sensitive logs

Fix: Added minimal permissions, production environment gate, timeout, and token/password redaction.

### M-04: Admin PWA cached authenticated admin HTML/JSON

Fix: Changed admin service worker to network-only for authenticated admin content and bumped cache version.

### M-05: CSP report endpoint could amplify logs

Fix: Added throttle and bounded logged report size/depth.

### M-06: Uptime Kuma mounted Docker socket

Fix: Removed socket mount from local ignored observability compose. Dozzle still needs socket-proxy follow-up.

### M-07: Open WebUI signup and AI sandbox settings need hardening

Evidence: compose/docs mismatch on signup; AI agent configs mount writable SSH/token material.  
Required: Disable signup after bootstrap and split read-only/write/deploy AI profiles.

### M-08: Backups are local and not confirmed encrypted/offhost

Required: Implement encrypted Restic/Borg/offsite backup to R2/B2 and restore drills.

## Low

### L-01: Lighthouse temporary public storage enabled

Fix: Disabled public temporary Lighthouse report storage.

### L-02: Dependabot missed nested npm projects

Fix: Added Dependabot coverage for daily-checkout, cloudflare-worker, vacation-app, and ts-orchestrator apps.

### L-03: Mutable container tags remain

Required: Pin production/observability images by digest in a tested maintenance window.
