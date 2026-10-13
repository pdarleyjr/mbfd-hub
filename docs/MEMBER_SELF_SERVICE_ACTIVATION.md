# Member self-service activation

Members awaiting their first sign-in can activate their existing MBFD Hub account
from the login page instead of waiting for an administrator-issued invitation.

## What members see

`/login` shows **First time using MBFD Hub? Activate Your Account**, which opens
`/activate`:

1. Employee ID
2. Date of birth
3. Personal phone number (the one the department has on file)
4. A new password, twice

A match activates the member's existing account and signs them in. Nothing is
created; no SMS, email or administrator approval is involved.

**Use email activation instead** (`/activate/email`) asks only for an Employee ID
and sends the existing 30-minute invitation to the member's approved City email.
The member cannot choose the destination address.

## Two independent switches

Both default to off and are set in the production `.env`, followed by
`php artisan config:cache` inside the app container.

| Variable | Effect when `true` |
|---|---|
| `MBFD_SELF_SERVICE_ACTIVATION_ENABLED` | `/activate` accepts Employee ID + date of birth + phone |
| `MBFD_MEMBER_EMAIL_ACTIVATION_REQUEST_ENABLED` | `/activate/email` lets a member request their own invitation |

With both off, the login page shows the original "open your setup link" text and
the routes redirect to `/login`. Turning either off never touches an account that
is already active, and the administrator invitation tools keep working either way.

## Loading the trusted records

Self-service activation only works for members whose date of birth and phone have
been loaded from an owner-approved source. Members without a record stay
ineligible and use email activation. Never invent or infer a missing value.

CSV columns (other columns are ignored): `Employee ID`, `Date of Birth`
(`YYYY-MM-DD` or `M/D/YYYY`), `Phone` (10-digit US number, any punctuation).

```bash
# 1. Dry run: reconciliation report, changes nothing
php artisan identity:import-member-activation-verifiers /path/to/records.csv

# 2. Apply, binding the run to the exact file that was reviewed
php artisan identity:import-member-activation-verifiers /path/to/records.csv \
  --apply --confirm=IMPORT_MEMBER_ACTIVATION_VERIFIERS --source-sha256=<sha256 from step 1>
```

The report lists counts and, for rows that were not loaded, the Employee ID and
reason only. It checks established accounts before and after and fails if any
changed. Delete the CSV from the server afterwards.

Row outcomes: `ready`, `unchanged`, `established_skipped` (already active),
`privileged_skipped` (holds an administrative role or permission),
`inactive_skipped`, `not_pending_onboarding`, `employee_not_found`,
`canonical_identity_conflict`, `duplicate_roster_employee_id`,
`duplicate_database_employee_id`, `missing_*`, `invalid_*`.

## How the data is protected

- Date of birth and phone are stored only as HMAC-SHA256 digests in
  `member_activation_verifiers`, keyed with a secret derived from `APP_KEY` and
  bound to the personnel record. No plaintext date of birth exists in the database.
- The digests are erased when the account is claimed.
- The phone typed on the form is compared only; it is never written to the profile.
- Logs and audit events carry fingerprints and counts, never the submitted values.
- Rotating `APP_KEY` invalidates unclaimed records; re-run the import.

## Safeguards

- Only an account that is pending activation, never completed onboarding, linked
  one-to-one to an active personnel record, and holding no role beyond `member`
  can be claimed. Active, disabled, departed and privileged accounts are refused.
- Every refusal returns the same message, whether the Employee ID is unknown, the
  details are wrong, or the account is not eligible.
- Limits: 5 attempts per Employee ID per 15 minutes and 20 per network address per
  hour. Ten wrong answers against one member lock that member's record until it is
  re-imported; the member can still use email activation.
- Member-requested email links are limited to 3 requests per Employee ID and 10
  per network address per hour, 2 links per member per day, and 40 across all
  members per hour. An unexpired link is never replaced. Members with an
  administrative role cannot self-request; an administrator sends theirs.
- Claiming requires local sign-in to be enabled (`MBFD_LOCAL_LOGIN_ENABLED`).
- Claiming is one transaction under row locks. A claim invalidates any outstanding
  email invitation, and an email activation makes the account ineligible for
  claiming, so the two methods can never both set a password.
- A claimed account's City email is left **unverified**, so it does not gain
  email password recovery until the member confirms an address from their account
  page. Until then a forgotten password needs administrator recovery.
- Members activated this way are not sent to the City email review screen.

## Limitation to keep in mind

Employee ID, date of birth and phone number are things a person knows, not
something they hold. Anyone who has all three for a member who has not yet
activated could claim that account first. This is a deliberate convenience
trade-off, not identity proofing or multi-factor authentication. It applies only
to the first activation and can never be used to reset an active account.

## Administrators

*Personnel → Onboarding status* shows how many records are on file, locked, and
activated. The existing invitation actions are unchanged and remain the route for
members with no record, a changed phone number, or a locked record.
