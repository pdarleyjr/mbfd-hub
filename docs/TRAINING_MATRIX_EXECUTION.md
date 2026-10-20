# Training course matrix execution — October 20, 2026

## Scope and source

Extend existing Training courses with modules, optional attendance tracking,
private reviewed paper evidence, department filters, and Excel reports. Preserve
digital signatures, immutable roster versions, and existing employee identities.

Baseline main and live embedded source: `6eb061b45683b5b428903cc7f0bfd51bbbe20675`.
The original checkout and unrelated worktrees are preserved. Ordinary fetch
encountered an invalid preexisting reclamation reference; the verified remote
commit was already available and isolated worktrees use that exact commit.

All nine supplied package files match the source integrity manifest. Private
rosters, identity exports, and reconciliation remain outside Git under the
restricted operations directory `D:\MBFD\planning\hub-training-20261020\private`.
Public evidence contains counts and hashes only.

## Ownership and contracts

| Owner | Configuration | Exclusive work |
| --- | --- | --- |
| Lead | Current orchestrator | Integration, Filament administration, policies, own history, verification and sole production writer |
| domain_kiosk | GPT-6.1 Sol / High | Course/module schema and services, digital session and kiosk identity/concurrency |
| paper_ingestion | GPT-6.1 Sol / High | Paper schema, provenance, importer, protected sources, private preparation |
| matrix_reporting | GPT-6.1 Sol / High | Canonical aggregation, personnel classification, workbook exports |

Each specialist uses its own managed worktree at the baseline commit.
High reasoning is used for these initial assignments because each owns identity,
attendance semantics, security, or concurrency. Subsequent routine work may use
Medium; final verification requires independent High review.

Contracts: courses opt in with `track_attendance_matrix`; modules have name,
order, required flag, and optional rank/shift audience; sessions have a nullable
same-course module FK. `attendance_source` distinguishes digital and paper.
Mapping is audited without modifying digital snapshots. Reviewed paper records
live in separate provenance tables; no synthetic signature or historical
electronic certification is created.

The matrix and workbook use one canonical snapshot. Submitted/certified valid
digital attendance and reviewed verified paper cover modules once; provisional,
unassigned, unresolved, excluded and voided records remain distinct.

## Verification checkpoints

1. Baseline: verify live image/source, stable course/session IDs, and private
   original hashes. Capture existing attendance/version fingerprints.
2. Implementation: test containment, toggle authorization, same-day session
   identity, paper tamper/replay/review, filter semantics and workbook parity.
3. Rehearsal: consistent PostgreSQL backup and isolated restore, migrations,
   replay/interruption and retained-data rollback.
4. Release: exact reviewed source through current repository quality gates,
   immutable image preparation and approved deployment.
5. Acceptance: authenticated matrix/import review/history, private downloads,
   exports, unchanged existing digital evidence, and aggregate reconciliation.

## Current evidence

- GitHub CI, security, immutable-image preparation and deployment succeeded for
  the baseline on October 20. The reported Actions outage is not current evidence;
  use hosted release controls unless new failures establish fallback necessity.
- Baseline deployment run: `39453841971`; CI run: `39452888357`.
- Live Back to Basics course ID `6`; existing October 20 Module 1 session ID `8`,
  open, revision `27`, 24 attendance rows at initial capture. Live attendance may
  continue; compare preserved row evidence immediately before and after mutation,
  rather than treating legitimate later attendance as corruption.
- Combined Training feature suite: 91 tests, 581 assertions passed. Full PHPStan
  analysis passed; changed PHP Pint passed. Root TypeScript check passed.
- Consistent PostgreSQL backup restored into an isolated, network-disabled
  PostgreSQL 16 container: 268 employees, 6 courses, 8 sessions, 58 attendance
  records. Backup SHA-256:
  `8bd32cc7f1810fb997b9a38fbadb23bb391e3914467c06892b8e3d6c0ab0dccf`.
- Six original pages received independent visual reconciliation. Current
  preparation retains 67 source rows: 53 approved, 13 pending, 1 excluded.
  These are preparation counts, not a claim of production import.
- Candidate migration/import rehearsal, hosted release, browser acceptance,
  production import and final verification remain in progress.
