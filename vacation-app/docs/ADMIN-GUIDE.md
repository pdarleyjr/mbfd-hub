# Admin Guide — MBFD Vacation Selection

This guide is for the person uploading Telestaff exports. No coding required.

## 1. Sign in

1. Open `https://vacation.mbfdhub.com` in any browser (phone is fine).
2. Enter the department PIN.
3. You land on the **Board** page. If you've never uploaded anything, you'll
   see "No data imported yet" — that's normal. Click **Go to Import →**.

## 2. Upload a Telestaff export

1. Go to **Import** in the top nav.
2. Drag a Telestaff file onto the dropzone, or click **Choose a file…**
   - CSV or XLSX, any column order.
   - Files up to 1 GB are supported. Large files take a minute or two; you
     can keep working — you'll watch progress on the next step.
3. Click **Upload**.

> **Duplicate files**: if you upload the same file twice, the app detects it
> and reuses the previous run. No extra work, no double-import.

## 3. Preview & map columns

Once the upload finishes, the worker reads the file and shows you:

- **Section 3 — Map columns**: each Telestaff column on the left, what we
  think it means on the right. The dropdowns are pre-selected with our best
  guess. Correct anything that looks wrong. Columns set to *— ignore —* are
  skipped.

  | If you see…              | Pick…                       |
  | ------------------------ | --------------------------- |
  | The employee/payroll ID  | **Employee ID**             |
  | Their last name          | **Last name**               |
  | Their first name         | **First name**              |
  | Captain/Lt/FF/etc.       | **Rank**                    |
  | A / B / C                | **Shift (A/B/C)**           |
  | A-day cycle group        | **A-Day group**             |
  | Start of the leave block | **Event start datetime**    |
  | End of the leave block   | **Event end datetime**      |
  | Code: V, FH, AH, etc.    | **Event work code**         |
  | "Vacation", "Sick", etc. | **Event description**      |

## 4. Resolve unknown codes

If Telestaff has a description we've never seen before, **Section 4** shows
it. Pick what to do with it:

- **Map to leave code** + pick the closest existing code (V, FH, AH, A, S, …)
- **Skip rows with this description** — they don't make it into the board

Your decisions are remembered; the next file with the same description
auto-resolves.

## 5. Commit

Click **Commit import**. The board updates within a few seconds for small
files, a few minutes for large ones. You're taken to the run detail page
where you can watch stats roll in.

## 6. Browse the board

Click **Board** to see the result.

- Filter by **Shift**: A, B, C, or Staff
- Filter by **Rank**: Division Chief, Captain, Lieutenant, Firefighter, Probationary
- Pick a **date range** (defaults to a 4-week window)
- Toggle **Only members with leave** to thin out the list

Each member row shows two cells per day: the **AM** block (08:00–20:00) and
the **PM** block (20:00–08:00 next day). Colours match the workbook's
intent — red = vacation, amber = holiday, blue = A-day, grey = working/sick.

Tap or hover a cell for the full detail: which Telestaff row populated it,
which import run, the raw record.

## 7. Roll back a bad import

If you imported the wrong file or used the wrong column mapping:

1. **Runs** → click the run.
2. Click **Roll back…** then **Confirm rollback**.
3. The leave entries from that run are removed; any prior entries they
   replaced are restored. The board refreshes.

> Nothing is ever deleted; everything is reversible. The original file stays
> in R2 and the run record stays in the database with `status = rolled_back`.

## 8. Re-import the same file later

If Telestaff updates and you re-export the same range:

1. Upload the new file (different content → different SHA → new run).
2. Map columns + resolve any new descriptions.
3. Commit. Existing entries for the same `(member, shift block)` are
   superseded — the new file is now the source of truth, but the old one
   is still in the history if you ever need to roll back.

## FAQ

**Q. What if I forgot the PIN?**
The PIN is rotated by an admin via Cloudflare's Wrangler tool. Contact the
person who deployed the app.

**Q. Why are some rows showing as "skipped"?**
Three reasons: (1) the description was set to "skip" in Section 4,
(2) the work code wasn't in the lookup table and had no description,
(3) the row was missing the Employee ID or the event start datetime.

**Q. Can I edit a single cell by hand?**
Not in V1. To correct one cell, fix the upstream Telestaff record and
re-import the file (the new entry will supersede the wrong one).

**Q. Can a firefighter use this to request vacation?**
Not in V1. The app is admin/read-only for now. Phase 2 will add member
self-service.
