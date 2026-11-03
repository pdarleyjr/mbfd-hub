# EMS Supply Room Kiosk

A touchscreen that stays on inside the locked EMS supply room. A member types an employee ID, can pick a unit
(optional), finds items, sets quantities and taps Take. The stock comes off the master inventory at once; the
member and the people on the EMS supply alert list get a copy. Members never log in.

Owner decisions (2026-11-03): an unknown employee ID is refused; Captain 5's copy is in-app per withdrawal plus
one daily email; Undo lasts 60 seconds; a quantity above half the expected on-hand amount needs a second tap;
"screen offline" alerts go to the EMS supply alert list.

## 1. Trust: the screen, not the member

- An admin who can edit the master inventory (Captain 5 or any administrator) taps **Pair supply room screen** on
  Admin > Medical Supply Room > Supply room screen. That creates a device row with a 6-character pairing code
  (shown as `ABC-234`; no 0/O or 1/I/L) and a one-time link, both valid once, for 30 minutes.
- On the tablet, open `www.mbfdhub.com/supply-kiosk` and type the code (`POST /supply-kiosk/pair-code`, 10 tries a
  minute). Or, signed in on the tablet, use **Open pairing page**: the link shows a **Pair this screen** button (a
  POST, so link previews cannot consume it).
  Pairing stores a random 64-hex secret's SHA-256 on the device row and gives the tablet a long-lived, httpOnly,
  Secure, SameSite=Strict cookie `mbfd_supply_kiosk` holding `{device_id}.{secret}` (Laravel-encrypted).
- Every kiosk API call goes through `EnsureSupplyKioskDevice`: no cookie, unknown device, revoked device or wrong
  secret answers 401 `kiosk_not_paired`. There is no timer expiry; **Revoke** ends it.
- The ID-card door is the member check. The employee ID records who took what.

## 2. Routes (web group, so session + CSRF apply)

| Method | Path | Purpose |
|---|---|---|
| GET | `/supply-kiosk` | The kiosk page (works unpaired; the app shows the "not paired" screen). |
| GET | `/supply-kiosk/pair/{token}` | Pairing page with the Pair button. |
| POST | `/supply-kiosk/pair/{token}` | Pairs this browser, then redirects to `/supply-kiosk`. |
| POST | `/supply-kiosk/pair-code` | `{code}` typed on the screen; pairs this browser (JSON). |
| GET | `/ems/api/supply/screen` | Staff (inventory read): withdrawals, screens, search words waiting. |
| GET | `/supply-kiosk/api/bootstrap` | Everything the screen needs (section 3). Also refreshes the CSRF token. |
| POST | `/supply-kiosk/api/withdrawals` | Take items (section 4). Idempotent by `client_uuid`. |
| POST | `/supply-kiosk/api/withdrawals/{uuid}/undo` | Put a withdrawal back, within 60 s, same device. |
| POST | `/supply-kiosk/api/heartbeat` | The screen is alive (every 60 s). |

Responses use the EMS envelope: `{ok: true, data, meta}` or `{ok: false, error: {code, message, fields?}}`.
A 419 means the CSRF token aged out: fetch bootstrap again and retry once.

## 3. Bootstrap

```json
{
  "ok": true,
  "data": {
    "device": {"name": "Supply room screen"},
    "csrf_token": "…",
    "generated_at": "2026-11-03T12:00:00-05:00",
    "members": [{"employee_id": "1234", "name": "Lt. J. Smith"}],
    "units": [{"id": 12, "label": "R22"}],
    "locations": [{"code": "B3", "label": "Shelf B3", "sort": 3}],
    "items": [{
      "id": 41,
      "name": "IV Catheter 18g",
      "location_code": "B3",
      "location_label": "Shelf B3",
      "section_label": "IV Supplies",
      "category": "IV",
      "aliases": ["angiocath 18"],
      "learned_aliases": ["18 iv"],
      "item_numbers": ["381744"],
      "units": [
        {"key": "each", "label": "each", "plural": "each", "per": 1},
        {"key": "box", "label": "box of 50", "plural": "boxes of 50", "per": 50}
      ],
      "default_unit": "box",
      "confirm_above_base": 25,
      "popularity": 14
    }],
    "most_taken": [41, 7, 88],
    "shorthand": {"nrb": ["non rebreather", "non-rebreather"], "4x4": ["gauze"]}
  }
}
```

- `members`: active employees whose employee ID is unique, as "Rank Initial. Last". Nothing else about them.
- `units`: apparatus with a designation, rescues first.
- `items`: active, non-controlled supply items. `units` always holds `each`; it adds the count unit and the
  package unit when they hold more than one. `confirm_above_base` is half the expected on-hand amount in base
  units (null when there is no expected amount); the screen asks for a second tap above it.
- `popularity`: kiosk picks in the last 90 days (search ranking tie-break). `most_taken`: up to 12 item ids.
- `learned_aliases`: search words Captain 5 approved (section 6).

## 4. Withdraw

```json
POST /supply-kiosk/api/withdrawals
{
  "client_uuid": "6f1c…",
  "employee_id": "1234",
  "unit": {"apparatus_id": 12, "label": null},
  "lines": [{"supply_item_id": 41, "quantity": 2, "unit": "each"}],
  "note": null,
  "searches": [{"term": "18 iv", "supply_item_id": 41}]
}
```

- `employee_id` must match one active member (`employee_not_found`, 422). `unit` is optional: an apparatus id
  from bootstrap or free text up to 20 characters. Up to 40 lines; quantity 1 to 9999; `unit` one of the item's
  unit keys. A withdrawal with no lines needs a `note` ("Tell Captain 5 what you needed"), and takes no stock.
- Inside one transaction under the stock lock: one `ems_kiosk_withdrawals` row, one line row and one **issued**
  movement per line (`StockLedger::record`, key `kiosk:w{id}:l{line}`, linked by `kiosk_withdrawal_id`).
  A withdrawal is never refused because the ledger shows the shelf empty; the line records
  `shelf_showed_empty` and the master list shows the item red with "count due".
- Replaying a `client_uuid` returns the original result (`meta.replayed: true`); a different body with the same
  uuid is `idempotency_mismatch` (409).
- The ledger's after-commit hook re-checks stock levels, so withdrawals drive the colors and low-stock alerts.
- After commit: the member's copy (category "Updates on things I submitted"), and an in-app notice to the
  people on the EMS supply alert list (category `ems_supply_withdrawals`).

Response `data`:
```json
{
  "uuid": "6f1c…", "number": "W-000123", "employee_name": "Lt. J. Smith", "unit_label": "R22",
  "taken_at": "…", "undo_until": "…",
  "lines": [{"name": "IV Catheter 18g", "quantity": 2, "unit_label": "each"}],
  "note": null, "copy": "sent"
}
```
`copy` is `sent` (the member has a Hub account) or `no_account` (no copy could be sent; Captain 5 still sees it).

## 5. Undo

Within 60 seconds of `taken_at`, from the same device: one **adjusted** movement per line putting the quantity
back (`corrects_movement_id` = the issue, `kiosk_withdrawal_id` set, key `kiosk:w{id}:l{line}:undo`); the
withdrawal becomes `undone`. Later corrections are Captain 5's, from the withdrawals list.

## 6. Search words that learn

Each withdrawal carries the words the member typed before picking each item. A word that led to the same item
3 or more times is offered to Captain 5 under **Search words to approve**; approved words ship in
`learned_aliases`. Nothing reaches search without a person approving it.

## 7. Captain 5 and admin side

- Admin > Medical Supply Room: **Pair supply room screen**, **Supply room screens** (rename, revoke, last seen),
  **Search words to approve**, and the **Supply room withdrawals** list (member, unit, items, time, status,
  "count due" flags), also shown on the Captain 5 Master Supply Room page.
- `ems:kiosk-daily-summary` at 07:00 ET emails the alert list every withdrawal of the past 24 hours (nothing is
  sent when there were none).
- `ems:kiosk-watch` every 5 minutes: a paired screen silent for 15 minutes alerts the EMS supply alert list once;
  it clears when the screen checks in again.

## 8. Screens (resources/js/supply-kiosk)

ID number pad (name appears on a match) → unit chips (optional) → Search or View entire supply room →
quantity sheet (opens at 1; 1/2/3/5/10; unit toggle; second tap above `confirm_above_base`) → cart → one Take
button → Done with a 60-second Undo, then back to start. 60 seconds untouched mid-order: "Still there?" then clear.
Bootstrap refreshes every 5 minutes; heartbeat every 60 seconds; a full reload at 03:00. A withdrawal that cannot
reach the server waits in IndexedDB and is sent (same `client_uuid`) when the connection is back.
