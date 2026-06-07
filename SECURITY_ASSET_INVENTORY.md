# MBFD Hub Security Asset Inventory

Assessment date: 2026-06-06  
Scope: `pdarleyjr/mbfd-hub`, `pdarleyjr/media-control`, related local workspaces, GMKtec Ubuntu host, GitHub settings, Cloudflare/Tunnel configuration evidence in repo, and safe live server metadata.

No secret values are intentionally recorded here.

| Asset | Type | Location | Classification | Data Touched | Auth / Exposure | Recommended Protection |
|---|---|---|---|---|---|---|
| MBFD Hub Laravel | Web app | `www.mbfdhub.com`, `mbfdhub.com`, GMKtec `mbfd-hub-laravel` | Public with protected admin areas | MBFD operational data, users, workgroups, inventory | Cloudflare Tunnel to loopback origin; Filament auth | Keep app patched, Access/WAF/rate limits on admin/API, least-privilege routes |
| Filament Admin | Admin panel | `/admin` | Admin-only | Users, roles, station data, audits | Filament auth + roles | Keep behind app auth plus Cloudflare challenge/access where feasible |
| Workgroup Panel/Reports | Internal panel/reporting | `/workgroups`, `/workgroup-*`, `/reports/*` | Protected | Workgroup evaluations, uploads, exports, AI summaries | Fixed to require `workgroup.access` | Keep role tests and object-level policies |
| Daily Checkout/Public APIs | Public/API | `/daily`, `/api/public/*` | Public / partially sensitive | Apparatus, station, inspections, requests | Rate limits; some write routes still public | Move sensitive ops data behind signed/PIN/auth flows |
| PostgreSQL | Database | `mbfd-hub-pgsql`, host `127.0.0.1:5432` | Internal | Laravel data | Loopback Docker bind | Maintain loopback bind, backups, least-privilege users |
| Redis | Cache/queue | `mbfd-hub-redis`, host `127.0.0.1:6379` | Internal | Sessions/cache/queues | Loopback bind; Redis password in env | Keep non-public and password-protected |
| Reverb | WebSockets | Laravel container `:8080`, public through app origin | Protected | Realtime events | Origin-limited config | Add private channel auth before sensitive use |
| Baserow | Admin/data tool | `baserow.mbfdhub.com`, `mbfd-hub-baserow` | Protected/unknown live Access | Tables/training data | Cloudflare Tunnel + app auth | Confirm Cloudflare Access or strong MFA; add WAF/rate limits |
| Snipe-IT | Asset tool | `inventory.mbfdhub.com`, `mbfd-snipeit` | Protected/unknown live Access | Inventory/asset records | Cloudflare Tunnel + app auth | Protect with Access, rotate DB credential, avoid public admin exposure |
| Nextcloud | File workspace | `cloud.mbfdhub.com`, `mbfd-nextcloud` | Protected | Files, docs, configs | Cloudflare Access + Nextcloud auth per docs | Keep Access, 2FA/admin review, encrypted offhost backups |
| ONLYOFFICE | Document editor | `office.mbfdhub.com`, `mbfd-onlyoffice` | Public app-layer exception | Document editing callbacks | Access bypass documented for iframe/callback compatibility | JWT enforcement, path WAF, exception review |
| Open WebUI | AI UI | `ai.mbfdhub.com`, `open-webui` | Protected | Prompts, docs, AI chats | Cloudflare Access + WebUI auth per docs | Disable signup after bootstrap, audit admin users, scrub logs |
| Ollama | AI runtime | Host `*:11434`, UFW limited; bridge at `127.0.0.1:11435` | Internal/firewall-protected | Model prompts/results | Firewall allows container subnet only | Prefer loopback bind if practical; keep UFW deny inbound |
| Qdrant | Vector DB | `127.0.0.1:6333-6334` | Internal | Embeddings | Loopback bind | Keep non-public; avoid secret-bearing embeddings |
| Dozzle | Log viewer | `127.0.0.1:8888` | Internal/admin-only | Container logs | Loopback; Docker socket read-only | Keep off public routes; prefer socket proxy |
| Uptime Kuma | Monitoring | `127.0.0.1:3001`, `status.mbfdhub.com` in docs | Protected/unknown | Availability checks | Loopback in compose; possible Tunnel hostname | Removed socket mount in local config; protect if tunneled |
| Web-Check | Audit tool | `127.0.0.1:3000` | Internal | Web check results | Loopback only | Pin image digest/version; do not expose publicly |
| Media Control | Display control | `media.mbfdhub.com`, `media-control.mbfdhub.com`, GMKtec `media-control` | High-risk control plane | Displays, playlists, scenes, assets | Cloudflare/app auth; public player routes | Hardened uploads/provisioning; add signed playback for sensitive content |
| ScreenTinker fork | Repo/app | `pdarleyjr/screentinker` | Public repo / legacy | Display code | GitHub public | Audit before reuse; avoid secrets in public fork |
| Vacation app | Separate app | `vacation.mbfdhub.com`, `vacation-origin.mbfdhub.com` | PIN-gated / origin unknown | Vacation import data, R2 files | Worker PIN gate + origin | Verify direct origin rejects bypass |
| Cloudflare Workers | Edge services | `mbfd-support-ai`, `pulsepoint-proxy`, `workgroup-ai`, `vision-agent` in repo | Public/protected mixed | AI, PulsePoint, RAG | Worker secrets and CORS | Confirm auth on non-public endpoints and rotate exposed tokens |
| Cloudflare Tunnel | Edge ingress | `cloudflared` on GMKtec, repo configs | Public edge to loopback origins | All tunneled apps | Access/WAF varies by hostname | Export live ingress and Access policies; classify every hostname |
| GitHub repos | Source/CI | `mbfd-hub`, `media-control`, `mbfd-bid`, `mbfd-vacation`, others | Private/public mixed | Source, workflows, secrets | GitHub auth | SHA pinning enforced; add branch/ruleset protections where plan permits |
| GMKtec EVO-X2 | Server | `mbfd@gmktec`, Ubuntu 26.04 LTS | Internal admin host | All local services | SSH key auth, Tailscale, UFW | Keep patched; validate 6 pending updates; encrypted offhost backups |
| Starlink/local network | Internet path | Local LAN/Starlink | Unknown/partial | Network access | Cloudflare Tunnel/Tailscale primary inbound | Verify no router forwards/UPnP; segment displays/admin if possible |

Unknowns requiring live export: current Cloudflare Access policies, exact DNS/WAF/rate-limit rules, router/Starlink admin settings, and direct public reachability of `*-origin` or legacy hostnames.
