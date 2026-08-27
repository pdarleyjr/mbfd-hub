# Daily Checkout Production Apparatus Matrix — 2026-08-25

This is a read-only production snapshot collected during the Hub audit. It is
not a policy backfill and does not authorize any deployment or database change.
At observation time, production did not have either the
`daily_checkout_requirement` or `daily_checkout_template` column. Therefore
every policy value below is deliberately `SCHEMA_ABSENT / PENDING_OWNER_DECISION`;
operational status is not used as a policy inference.

Candidate template means only the source-family proposal that may be used after
an authorized owner classifies the row as required. It is not an assertion that
the apparatus must receive Daily Checkout.

| ID | Name | Station | Type | Status | Slug | Daily policy | Candidate template | Checkout URL | Ambiguity / hold |
| ---: | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 38 | E1 | Station 1 | Engine | In Service | `e1` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `engine` | `/daily/vehicle-inspections/e1` | Owner policy decision required |
| 39 | L1 | Station 1 | Ladder | In Service | `l1` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `ladder1` | `/daily/vehicle-inspections/l1` | Candidate source currently fails closed: duplicate `scba_radio` item labels |
| 40 | R1 | Station 1 | Rescue | In Service | `r1` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r1` | Owner policy decision required |
| 41 | R11 | Station 1 | Rescue | In Service | `r11` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r11` | Owner policy decision required |
| 42 | A1 | Station 2 | Air Truck | In Service | `a1` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | UNMAPPED SPECIALTY | `/daily/vehicle-inspections/a1` | Fail closed until an approved specialty template exists |
| 43 | A2 | Station 2 | Air Truck | In Service | `a2` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | UNMAPPED SPECIALTY | `/daily/vehicle-inspections/a2` | Fail closed until an approved specialty template exists |
| 44 | E2 | Station 2 | Engine | In Service | `e2` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `engine2` | `/daily/vehicle-inspections/e2` | Explicit E2 override; owner policy decision required |
| 45 | R2 | Station 2 | Rescue | In Service | `r2` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r2` | Owner policy decision required |
| 46 | R22 | Station 2 | Rescue | In Service | `r22` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r22` | Owner policy decision required |
| 47 | E3 | Station 3 | Engine | In Service | `e3` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `engine` | `/daily/vehicle-inspections/e3` | Owner policy decision required |
| 48 | L3 | Station 3 | Ladder | In Service | `l3` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `ladder3` | `/daily/vehicle-inspections/l3` | Explicit L3 override; candidate source currently fails closed: duplicate `scba_radio` item label |
| 49 | R3 | Station 3 | Rescue | In Service | `r3` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r3` | Owner policy decision required |
| 50 | E4 | Station 4 | Engine | In Service | `e4` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `engine` | `/daily/vehicle-inspections/e4` | Owner policy decision required |
| 51 | R4 | Station 4 | Rescue | In Service | `r4` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r4` | Owner policy decision required |
| 52 | R44 | Station 4 | Rescue | In Service | `r44` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r44` | Owner policy decision required |
| 53 | E11 | Station 2 | Engine | Out of Service | `e11` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `engine` | `/daily/vehicle-inspections/e11` | Status is not policy; owner policy decision required |
| 54 | E21 | Station 2 | Engine | Available | `e21` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `engine` | `/daily/vehicle-inspections/e21` | Status is not policy; owner policy decision required |
| 55 | E31 | Station 2 | Engine | Available | `e31` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `engine` | `/daily/vehicle-inspections/e31` | Status is not policy; owner policy decision required |
| 56 | L11 | Station 2 | Ladder | Available | `l11` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `ladder1` | `/daily/vehicle-inspections/l11` | Candidate source currently fails closed: duplicate `scba_radio` item labels |
| 57 | R-1033 | Station 2 | Rescue | Available | `r1033` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r1033` | Reserve designation is not policy; owner decision required |
| 58 | R-1034 | Station 2 | Rescue | Available | `r1034` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r1034` | Reserve designation is not policy; owner decision required |
| 59 | R-1035 | Station 2 | Rescue | Available | `r1035` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r1035` | Reserve designation is not policy; owner decision required |
| 60 | R-1036 | Station 1 | Rescue | Available | `r1036` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r1036` | Reserve designation is not policy; owner decision required |
| 61 | R-14500 | Station 2 | Rescue | Available | `r14500` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r14500` | Reserve designation is not policy; owner decision required |
| 62 | R-14501 | Station 2 | Rescue | In Service | `r14501` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | `rescue` | `/daily/vehicle-inspections/r14501` | Reserve designation is not policy; owner decision required |
| 63 | Captain5 | Station 2 | Command Vehicle | In Service | `captain-5` | SCHEMA_ABSENT / PENDING_OWNER_DECISION | UNMAPPED ADMIN/SPECIALTY | `/daily/vehicle-inspections/captain-5` | Fail closed until an approved specialty template exists |

## Reconciliation and release gates

- Fire Boat 6 / FB6 was named in the audit direction but did not appear in the
  26-row observed production dataset. Reconcile the live apparatus registry
  before a policy migration; do not fabricate a row or template assignment.
- `daily_checkout_requirement` is the eligibility decision. A template is a
  separate configuration decision and cannot make a row eligible by itself.
- The current audited L1/L3 source files have duplicate item display names in
  `scba_radio`. The resolver treats them as unusable because the submission
  contract identifies items by compartment and display name. The exact labels
  need an authorized operational correction before those candidates can open.
- No row in this matrix has been migrated, classified, deployed, or otherwise
  changed in production.
