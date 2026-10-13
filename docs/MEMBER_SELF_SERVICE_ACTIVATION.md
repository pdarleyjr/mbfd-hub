# Member self-service activation

Members awaiting their first sign-in can activate their existing MBFD Hub account
from the login page instead of waiting for an administrator-issued invitation.
It uses only information the Hub already holds.

## What members see

`/login` shows **First time using MBFD Hub? Activate Your Account**, which opens
`/activate`:

1. Employee ID
2. City email (typed; must match the approved address on file)
3. Shift they bid (drop-down)
4. Unit they bid (drop-down)
5. A new password, twice

The shift and unit are the member's **latest published bid results**, not where
they currently work. The drop-downs list every shift and unit in those results,
so spelling can never block a match.

Members whose position does not bid (chief officers, civilian inspectors,
analysts, mechanics, administrative staff) open **My position does not bid** and
select their position from a list instead of a shift and unit. That list is the
positions of active personnel who have no result in the latest bid. A member who
has a bid result cannot use the position list, and vice versa.

A match activates the member's existing account and signs them in. Nothing is
created; no SMS, emailed link or administrator approval is involved. The member's
City inbox receives a short notice that the account was activated.

**Use email activation instead** (`/activate/email`) asks only for an Employee ID
and sends the existing 30-minute invitation to the member's approved City email.
The member cannot choose the destination address.

## Who can use it

A member can self-activate only when the Hub holds all of:

- an approved Employee ID / City email pairing (the onboarding roster), and
- either exactly one current result for that member in the latest bid year, or
  no bid result and a position on their personnel record.

Everyone else (no approved email, or locked after wrong answers)
uses email activation or an administrator-issued invitation. Nothing needs to be
imported or maintained for this feature: corrected bid results and roster updates
take effect immediately.

## Two independent switches

Both default to off and are set in the production `.env`, followed by
`php artisan config:cache` inside the app container.

| Variable | Effect when `true` |
|---|---|
| `MBFD_SELF_SERVICE_ACTIVATION_ENABLED` | `/activate` accepts Employee ID + City email + bid shift and unit |
| `MBFD_MEMBER_EMAIL_ACTIVATION_REQUEST_ENABLED` | `/activate/email` lets a member request their own invitation |

With both off, the login page shows the original "open your setup link" text and
the routes redirect to `/login`. Turning either off never touches an account that
is already active, and the administrator invitation tools keep working either way.

## Safeguards

- Only an account that is pending activation, never completed onboarding, linked
  one-to-one to an active personnel record, and holding no role beyond `member`
  can be claimed. Active, disabled, departed and privileged accounts are refused.
- Every refusal returns the same message, whether the Employee ID is unknown, an
  answer is wrong, or the account is not eligible.
- Limits: 5 attempts per Employee ID per 15 minutes and 20 per network address per
  hour. **Five wrong answers against one member permanently lock self-service for
  that member**; they then activate by email. This matters because there are only
  about 100 shift/unit combinations.
- The member's City email is notified the moment the account is activated, so a
  claim made by someone else is noticed immediately.
- Claiming is one transaction under row locks. A claim invalidates any outstanding
  email invitation, and an email activation makes the account ineligible for
  claiming, so the two methods can never both set a password.
- Typing an address proves nothing about the mailbox, so a claimed account's City
  email stays **unverified** and does not gain email password recovery until the
  member confirms it from their account page. Until then a forgotten password
  needs administrator recovery.
- Member-requested email links are limited to 3 requests per Employee ID and 10
  per network address per hour, 2 links per member per day, and 40 across all
  members per hour. An unexpired link is never replaced. Members with an
  administrative role cannot self-request; an administrator sends theirs.
- Claiming requires local sign-in to be enabled (`MBFD_LOCAL_LOGIN_ENABLED`).

## Limitation to keep in mind

Employee ID, City email and bid results are things people know, and inside the
department they are widely known: City addresses follow a pattern and bid results
are shared. This keeps outsiders out, but a coworker could activate another
member's account before that member does. The lockout and the activation notice
limit and expose that; they do not prevent it. This is a deliberate convenience
trade-off, not identity proofing or multi-factor authentication. It applies only
to the very first activation and can never be used to reset an active account.

If a member reports an activation they did not perform: disable the account or
issue an administrative recovery password from Personnel, which signs out every
session.

## Administrators

*Personnel → Onboarding status* shows how many members have self-activated and how
many are locked. The existing invitation actions are unchanged and remain the
route for members who cannot self-activate.
