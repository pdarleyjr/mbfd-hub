# Deferred Owner Secret Rotation — MBFD Hub (Phase 2)

Date: 2026-06-06

This file lists secrets that should be **rotated/recorded by the owner**. It contains **no secret values** — only provider, name/purpose, where used, and why. Rotation was intentionally NOT performed (per task constraints). Per-item priority reflects exposure.

> During this engagement the owner pasted several live credentials into the chat transcript to unblock the Cloudflare live review. Any credential that has appeared in a transcript must be treated as exposed and rotated.

## Critical — exposed in this session's transcript (rotate ASAP)

| # | Provider / name | Purpose | Where used | Why rotate | Update after rotation |
|---|---|---|---|---|---|
| 1 | Cloudflare API token (Wrangler, `cfut_…f63f`) | Broad CF API — Zone/Access/Tunnel read **and write**, Workers | Used this session for the live CF review/changes; Wrangler CLI | Pasted in transcript; very broad scope | Re-auth `wrangler`; any CI/automation using it |
| 2 | Cloudflare R2 S3 Access Key ID + Secret (`7f5fa6af…`) | R2 object storage (now also used by the new Restic backup repo, and by Laravel) | GMKtec `/opt/mbfd/mbfd-hub/.env` (`R2_*`); `/opt/mbfd/secrets/restic.env`; provided in transcript | Pasted in transcript | Update `R2_ACCESS_KEY_ID`/`R2_SECRET_ACCESS_KEY` in box `.env` AND `restic.env`; any GitHub repo R2 secrets |
| 3 | GitHub PAT (`ghp_Efq0…`) | GitHub API/repo access for `pdarleyjr` | Provided in transcript (not otherwise used — `gh` uses a separate stored token) | Pasted in transcript | Anywhere this specific PAT is configured |
| 4 | Cloudflare token `cfat_…37d6` (labeled "mbfd-hub-laravel") | Was Laravel R2/CF API token | Provided in transcript; **already returns "Invalid API Token"** (stale/revoked) | Pasted in transcript; confirm it is fully revoked | Box `.env` `CLOUDFLARE_API_TOKEN` if a replacement is needed |

## High — carried over from Phase 1 (still pending)

| # | Provider / name | Purpose | Where used | Why rotate |
|---|---|---|---|---|
| 5 | GitHub PAT previously embedded in local git remotes | Repo push | Was in local `.git/config` (removed Phase 1) | Possible prior exposure |
| 6 | GitHub repo secrets `GH_PAT`, `CLOUDFLARE_API_TOKEN`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY` | CI/CD | `pdarleyjr/mbfd-hub` Actions | If they overlap with exposed values above |
| 7 | Snipe-IT DB password | MariaDB root for Snipe-IT backups | `/opt/mbfd/secrets/snipeit-backup.env` (per Phase 1) and Snipe-IT container env | Was hardcoded in `backup.sh` before Phase 1 |
| 8 | Box `/opt/mbfd/mbfd-hub/.env` `CLOUDFLARE_API_TOKEN` | Laravel CF API | GMKtec | Currently **stale/invalid** (verified); replace with a correctly-scoped token only if Laravel needs CF API, else remove |

## Record (do NOT rotate) — created this session

| # | Item | Purpose | Location | Action |
|---|---|---|---|---|
| 9 | **Restic backup repository passphrase** | Decrypts the encrypted off-host backups in R2 (`mbfd-hub-backups`) | GMKtec `/opt/mbfd/secrets/restic.env` (`0600`, owner-readable via `sudo`) | **RECORD in your password manager.** Losing it makes the backups unrecoverable. Generated on-box; never printed/transmitted. Rotating it would orphan existing snapshots — only rotate via `restic key` if needed. |

## Notes
- The on-box `restic.env` currently uses the **existing box R2 credentials** (item 2). When you rotate item 2, update `restic.env` and `/opt/mbfd/mbfd-hub/.env` together, then run `/opt/mbfd/restic-backup.sh` once to confirm access.
- Recommended hardening when you re-issue the Cloudflare token: scope a dedicated read-mostly token for routine review, and a separate narrowly-scoped token for any automation, rather than one broad token.
- After rotating, delete this engagement's transcript export if one was saved locally.
