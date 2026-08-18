# Single-Department End-to-End Demo Journey (School Education)

**Date:** 2026-08-17
**Scope:** Rebuild the demonstration database around **one** department so every
screen in the portal can be shown from linked, workflow-produced data with no
manual editing.

---

## What was asked

One fully realistic end-to-end demo journey, in the **School Education
Department only**, covering twenty named steps from department registration
through to a Review DPC and a Circulation DPC — every record referencing the
others, no isolated fixtures — plus a demo cheat sheet.

## What changed

The demo seeders already existed and ran, but seeded **three** departments
(School Education, Health, Finance) with four of the six intermediate workflow
states parked in Health and Finance. The work was to collapse that to one
department without losing any workflow state.

| File | Change |
|---|---|
| `database/seeders/Demo/DemoAccountSeeder.php` | `DEPARTMENTS` reduced to `SE`; two Chairmen reduced to one (`chairman@cgpsc.gov.in`); designation list trimmed to the School Education ladder plus `Additional Director, Education` |
| `database/seeders/Demo/DemoMasterSeeder.php` | `LADDERS` (3 departments) → `LADDER` (one, 9 posts). Added `Additional Director, Education` and `Director, Public Instruction` so there are enough distinct promotion pairs. Post spec now takes a **`feeders` list** instead of a single `feeder`, and `Lecturer` carries two. `ROSTER_CADRES` is now a list — a roster per journey cadre |
| `database/seeders/Demo/DemoPipelineSeeder.php` | Rewritten. All six intermediate-state DPCs are now School Education, each on a distinct rung. Shared `approved()` / `allot()` / `acceptAndSchedule()` helpers replace the per-method duplication |
| `database/seeders/Demo/DemoJourneySeeder.php` | Chairman lookup updated to the single Chairman account |
| `database/seeders/PayScaleSeeder.php` | Added `L13A` and `L14` for the two new apex posts (additive) |
| `docs/DEMO_CHEAT_SHEET.md` | Rewritten for one department, with figures read out of the database rather than restated from the previous draft |

### Why nine posts on the ladder

The demo needs one DPC per workflow state, and a list screen reads badly when
two rows are promotions to the same cadre. Nine posts give eight distinct
promotion pairs, which is exactly enough for the two journey DPCs plus the six
parked states. `Lecturer` was deliberately given **two** feeder posts: a
recruitment rule with a single feeder makes the Recruitment Rule screen look as
though it can only ever hold one.

## What the database now contains

Nine DPC proposals, all School Education, one per workflow state:

| # | DPC ID | Promotion | State |
|---|---|---|---|
| 1 | `SE202600001` | DEO → Joint Director | Concluded, published, **superseded** |
| 2 | `SE202600002` | DEO → Joint Director | **Review DPC**, concluded, **operative** |
| 3 | `SE202600003` | Lecturer → Principal, HSS | Concluded, **one officer held** |
| 4 | *(no ID)* | Principal → Block Education Officer | Draft, complete, ready to submit |
| 5 | *(no ID)* | Teacher → Head Master | Submitted, awaiting AH |
| 6 | `SE202600004` | Teacher → Lecturer | Approved, awaiting allotment |
| 7 | `SE202600005` | Principal → DEO | Allotted, not accepted |
| 8 | `SE202600006` | Addl Director → Director, PI | Scheduled, sitting not opened |
| 9 | `SE202600007` | Joint Director → Addl Director | Sitting open, result calculated |

Plus circulation `SE202600003/C1`, signed, releasing the held promotion.

Rows 4–9 exist so that every step the presenter performs **live** has something
to act on; rows 1–3 are finished and cannot be re-submitted or re-allotted.

## Verification

Nothing here is asserted from reading the seeder — each claim was queried back
out of the seeded database.

- **All 20 required steps verified present and correctly linked** (30 assertions,
  including foreign-key linkage: circulation items → proceeding holds, review
  link → original proposal, schedules → allotments, proceedings → schedules,
  documents → hashes and signatures).
- **HTTP smoke test**: all 9 accounts sign in, and **34 screens** return 200 with
  the expected record on the page, each under the role the cheat sheet names.
- **Reproducible**: `demo:seed --force` run twice from scratch produced identical
  DPC IDs and identical results.
- **Quality gate**: Pint pass · PHPStan no errors · **727 tests pass** (2203
  assertions) · `composer audit` clean.

### Two corrections the verification forced

1. **The section screens (S15–S18) are edit-only.** `/committee`,
   `/working-officers`, `/seniority-list` and `/evaluation-chart` authorize
   `update`, which requires the AO role *and* an editable status — they return
   403 on a concluded DPC. The cheat sheet had been walking them on
   `SE202600001`. Corrected: the concluded DPC's full record is read from its
   **show page** (which renders every section read-only), and the editable
   section screens are demonstrated on the **Draft**.
2. **Proceedings, calculation runs and circulations are addressed by UUID**, and
   UUIDs change on every re-seed. The cheat sheet now says to navigate from the
   list screen rather than typing those URLs, and records this under
   troubleshooting.

### Figures corrected against the database

The previous cheat-sheet draft had a partly hand-written result table. The real
final run for `SE202600001`: zone 14 (2 × 5 + 4), 14 of 16 eligible, main list
5, waiting list 7, one Super Seat, two ineligible (one on the Class-I most-recent-CR
gate, one on grade घ), two eligible-but-outside-zone. The Commission's sitting
correction moved S.N. 7 from **13 and ineligible** to **15 and waiting list #2**
across two calculation runs, neither overwriting the other. The Review moved
S.N. 8 from **15 and ineligible** to **18 and waiting list #3**, taking eligible
from 14 to 15 and the waiting list from 7 to 8 — **with the main list unchanged
at 5**, which is the point worth making out loud: a Review corrects the record,
it does not re-run the promotion.

## Notes

- No migrations were added or altered; the schema is untouched.
- `PayScaleSeeder` change is additive (two new rows).
- The department master still lists all nine departments — that list feeds the
  registration dropdown, and showing it is part of the demonstration. School
  Education is the only registered one.
- Every record is produced by calling the application's own services under the
  acting officer's identity, at the date it would have happened. Nothing is
  inserted directly and no business rule is bypassed.
