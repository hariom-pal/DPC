# Worklog — UX and business corrections

**e-DPC Portal · CGPSC**
**Date:** 2026-09-06 → 2026-09-07
**Gate:** `php artisan test` 1412 passed (4669 assertions) · `pint` passed · `phpstan` no errors · `lang:check` complete

---

## 1 · Masters — nothing is typed that a master holds

| Screen | Was | Now |
|---|---|---|
| DPC Committee — composition | `designation` free text | `designation_id`, from the Designation master, **required** |
| DPC Committee — substitute a member | free text | master dropdown |
| CGPSC Chairman — appoint / amend | free text | master dropdown |
| Department Post Mapping | post name free text | Designation master dropdown |

**Which master, and why not `department_posts`.** A DPC committee is drawn from
across the government — the Chairman and Members of CGPSC, the Secretary of the
department, the Deputy Secretary of GAD. `department_posts` holds *one department's*
promotable cadre, and none of those seats is in it. `designations` already carries
all of them **and** every Department Post name. Binding to `department_posts`
literally would have made the demo committee unrecordable.

**No schema change.** All four columns still store the **name**. The screen posts an
id; the row keeps the snapshot. A concluded committee does not change because
somebody later renames a master row.

Three ranks the demo script names were missing from the master and are now seeded:
`Chairman, Chhattisgarh Public Service Commission`, `Secretary, School Education`,
`Deputy Secretary, GAD`.

---

## 2 · Committee composition

**The rule, as business finally stated it:** one member from **each** of SC, ST and
OBC, plus Female, Bhar Sadhak Sachiv and Vibhag Sachiv. One person may hold one
social category *and* any of the other three, so three members still satisfy all six.

* `config/dpc.php` → `required_categories = sc,st,obc,female,bhar_sadhak_sachiv,vibhag_sachiv`.
* A live checklist on the screen ticks each requirement as it is covered.
* `DpcProposalCompletenessService` reports the committee **incomplete** until every
  requirement is met, and an incomplete section blocks submission.

**Saving and completeness were separated**, which was the fix for *"remove means
remove"*: a committee now saves as a draft in whatever state the officer has it in.
Only faults that are not "unfinished" refuse a save — a member claiming two social
categories, a tampered Chairman seat, two DPC Chairmen, more than the maximum. Being
short of members, or short of an attribute, marks it incomplete instead.

### Bugs found and fixed on this screen

* **The whole form vanished after a refused save.** Removing a card left a gap in the
  posted indices (`members[0]`, `members[2]`), PHP read that as a non-sequential
  array, `json_encode` wrote the seed as a JSON **object**, and the script's
  `rows.forEach` threw before drawing anything. Indices are now renumbered on every
  change, and the seed is `array_values`'d as a second guard.
* **"Save this member" appeared to do nothing.** Bootstrap only reveals an
  `.invalid-feedback` beside an `.is-invalid` sibling — and the searchable-select
  enhancement moves the designation `<select>` into a wrapper, so the message was no
  longer a sibling and stayed hidden. Errors are now shown explicitly and the first
  invalid field is focused.

### Also on this screen

Rows lock into a numbered one-line summary when saved, with Edit and Remove;
only one card is ever open; **+ Add Member** is disabled while one is. Mobile is ten
digits, digits only; e-mail must end in `gov.in` — both client- and server-side. A
duplicate is named ("already on this committee as Member 2").

---

## 3 · CGPSC allotment and scheduling

* **One date, not two.** The suggested DPC date is no longer collected — it bound
  nobody, and only the completion deadline governs anything. The allotment's own date
  is today by construction.
* **The allottee comes from this DPC's own committee**, not the Commission's roll,
  and only seats holding a portal account are offered.
* **Decline.** The allottee may refuse with a reason; the allotment leaves the
  current scope and the DPC is back on the Chairman's screen to be given to somebody
  else. New `AllotmentStatus::Declined`. This replaces architect Decision 3.
* **One date-time picker** for the sitting, bounded by the completion deadline,
  replacing a date box beside a time box.

---

## 4 · Admin

* **Search, CSV export and bulk upload on every reference master**, driven by one
  registry (`MasterRegistry`) and one controller. Export honours the current search.
  Upload is update-or-create, all-or-nothing, and **never deletes**; every bad line is
  reported at once. Templates carry worked example rows.
* **Pagination was rendering as unstyled Tailwind markup** on every admin list —
  `Paginator::useBootstrapFive()`.
* **Wide tables** gained a scrollbar pinned to the bottom of the viewport, drag-to-pan
  and edge shadows, so nobody scrolls to the foot of a hundred-row table to reach the
  horizontal bar.

---

## 5 · Public site

* Signed in, the header and the home page offer **Dashboard** and **Logout** instead
  of Login and Registration; the three account tiles are hidden.
* The top menu's section links were bare `#about` fragments and did nothing on four
  screens out of five. They are absolute now.

---

## 6 · Imports (S16 / S17 / S18)

* Every downloaded template now carries two `#` lines: **what each column means, when
  to fill it and in what format**, and a **worked example**. The importer skips `#`
  lines, so forgetting to delete them costs nothing — which is what let the builder
  emit an example at all.
* The template is now **per DPC**: Promotion To Post is stated as a fixed value,
  Promotion From Post lists the feeders the Recruitment Rule allows, and Qualification
  lists the master's active rows. Nothing has to be typed from memory.
* The same guidance renders on the upload screen, from the same source.
* **`DATE OF POSTING ON PROMOTION TO POST` was a Self-Inspection identity factor**,
  and business was right that a candidate awaiting promotion can never have one — it
  is why a demo file had been filled with the feeder date in that column. The factor
  is now `date_of_posting_on_promotion_from_post`, which every candidate has and
  which S17 already carries as `DATE OF POSTING ON US`.

---

## 7 · Qualification multiselect

Replaced the ctrl-click list on both feeder grids with a dropdown of checkboxes,
searchable, with the selections shown as removable chips underneath so nothing is
hidden behind a click.

Three defects were found and fixed while doing it:

1. The admin copy carried `data-searchable` — the **single-value** enhancement, which
   collapses a multiple select to one option. A feeder accepting three qualifications
   could not be recorded, and nothing failed.
2. The panel was clipped invisible by `.table-responsive`'s `overflow-x`. It is
   `position: fixed` now.
3. Closing on `focusout` fired on mousedown, before a label's click reached its
   checkbox — so every attempt to tick a box closed the dropdown. `focusin` now.

---

## 8 · Pre-DPC (frozen after this)

* Promotion must go **upward**: `PromotionHierarchyService` is now consulted when the
  Pre-DPC is drafted, not only at calculation. Same class requires a higher pay scale.
* The summary states the **Promotion To Post's own class and pay scale**, beside the
  post.
* Qualification is mandatory per feeder; every field is marked with `*`; the feeder
  shares total is shown live and Save is blocked below 100%.
* A **draft** Pre-DPC can be deleted — soft, keeping its number.

---

## 9 · Manual entry off; sample data for DPC-2026-SE-0001

**Working Officers and the Seniority List are upload-only.** Both grids are hidden and
both routes are gated on `dpc.manual_entry.*`, default off — hidden rather than
deleted, so *"in case we want it back"* is a setting change. The rows are still drawn,
frozen, so an officer can see what was imported. The upload path is untouched:
`ExcelImportService` calls the service directly rather than through the route.

**Sample data**, built for the live DPC and verified by importing it:

| File | Rows | Result |
|---|---|---|
| `demo-working-officers.csv` | 10 chairs — 6 working, 1 deputed, 3 vacant | Vacancy **4** of 10 sanctioned |
| `demo-seniority-list.csv` | 10 Block Education Officers | Chart projected, 10 rows |
| `demo-evaluation-chart.csv` | CR / Loyalty / Property / Tribal, 2021–2025 | **Main 4 · Waiting 3 · Not eligible 3** |

One Super Seat, and the three refusals are each for a different reason — below the
qualifying mark (13 for Class II→I), an adverse `घ` in the window, and under five
years on the feeder post. Written up in `docs/demo/SAMPLE_DATA_GUIDE.md`.

Found while doing it: **`DEPUTATION TO` is a date, not a place** — the day the
deputation ends. The column name says otherwise and the guidance now corrects it.

## 10 · Login, and the committee's own screen

### Why members could not log in — three walls, not one

1. **The password nobody could read.** Admin → Reset Password issues a random
   twelve characters and sent the only copy over SMS and e-mail, which on a
   log-mode gateway means a log file. Pressing the button *locked the account out*
   rather than recovering it, and each retry issued a different password. It now
   returns the password and the screen shows it once, beside the user's name — the
   same treatment the Seniority List already gives the accounts it creates.
2. **The OTP wall.** An unverified account is sent to `/verify`. Passable with the
   demo OTP `123456` in local; no change needed.
3. **No role, so nothing to do.** A committee member holds none, and must not —
   item 7 forbids granting a Commission role for sitting on one committee.

### Allotment refused the very people the committee rule elects

`AllotmentService::assertAllottable()` asked for `Role::Member` or `Role::Chairman`
— the old proxy for "a Commission actor", from before allotment was scoped to a
committee at all. Once a DPC could only go to one of its own members, the two rules
contradicted each other and refused exactly the people item 7 forbids giving a role
to. Committee membership is now the qualification, which is the *narrower* of the
two: a Commission Member not seated on this DPC could be allotted it before and
cannot now.

### The committee's sitting screen (new)

Outside `/cgpsc` on purpose, because everything in there is role-gated. Authorised
by a **seat** on the proposal's committee, per proposal:

* **My DPC Sittings** → the DPCs this person sits on, with the sitting date.
* **Candidates** → the whole list, showing Not called / Called / Concluded.
* **One candidate** → the full record, CR year by year.
* **Call** — live only on the day of the sitting and at or after its hour; the
  window stays open for the rest of that day, because a sitting scheduled for 14:00
  and still running at 17:00 is an ordinary sitting. Enforced on the request, not by
  disabling a button.
* **Mark concluded**, with remarks. **No marks are recorded** — the scheme is
  undecided, and a score column invented now would be inventing it.

`dpc_candidate_calls` is its own table rather than columns on `dpc_evaluation_rows`:
the chart is projected from the Seniority List and rebuilt on every re-upload, which
would destroy anything a sitting had written into it.

Both coverage guards caught the new work and were answered rather than silenced —
the ungated routes are on the allowlist *with the reason*, and the new table is
classified transactional so the reset clears it.

## 11 · Candidate mobile, and the seat's category

* **`MOBILE`** on all three import files, carried into the account
  `PromotionCandidateProvisioner` creates — which until now had *no login identifier
  at all*, so an officer handed a temporary password had nothing to type it against.
  A spreadsheet hands a phone number back as a *number*, which was rejected with
  "the mobile field must be a string"; the parser casts it.
* **`SEAT CATEGORY`** — the roster point an officer sits on, beside `CAT`, which
  stays their own category. The case it exists for: an SC officer selected on merit
  sits on a General seat.

Both are nullable, on all three snapshots, and carried through the projection.

## 12 · Reset, 2026-09-07

462 rows across 25 transactional tables, plus the projected Recruitment Rule — an
order citing a Pre-DPC that no longer exists. **No user was deleted**: 48 accounts,
9 departments, 9 department posts, 26 designations, 17 qualifications and the CGPSC
Chairman all stand. Every non-candidate account is set to `Demo@2026`, verified, with
no forced change; candidate accounts are left alone because the Seniority List
rebuilds them. Redis flushed.

Verified by signing in as the CGPSC Chairman, a Commission Member, a role-less
committee member and the Authorized Officer — all four reach the dashboard.

## Still open

Raised and not yet built:

1. Evaluation Chart — per-candidate CR entry in a page or modal, rather than one
   screen carrying every officer's five years at once. The download-and-upload path
   covers the bulk case; this is for correcting one row.
2. Self-Inspection sign-in by mobile + OTP `123456`. The mobile now exists on the
   snapshot, so the data half is done; the screen still authenticates on three
   identity factors.
3. A document describing how a committee member and the DPC Chairman award marks —
   the scheme itself is undecided, so this would describe the flow as built.
4. Department Post Mapping and CGPSC Chairman — a clickable "what changed, when, by
   whom" history page.
5. Manage Users — an explanation of the screen.
