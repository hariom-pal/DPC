# Pre-DPC Screen — Master-Sourced Post, Feeder Cadre and Qualification

**Date:** 2026-08-18
**Scope:** The Pre-DPC proposal screen and the masters behind it. No workflow,
DPC processing, eligibility rule or calculation was changed.

---

## What was asked

> On `/pre-dpc/create`: **Minimum Qualification** must be a dropdown from the
> master; **Post Name** and **Feeder Post** must come from the Cadre master;
> **give each feeder cadre field a label.**

All four were free text or unlabelled. All four are now master-sourced and
labelled.

## Why this is a change of position, stated plainly

The previous round (2026-08-17) made the *Recruitment Rule master* referential
and **deliberately left the Pre-DPC screen alone**, on this reasoning:

> *"The Pre-DPC screens are unchanged — the department still enters a
> qualification as text on its own form; resolution to the master happens at the
> moment of projection into the Recruitment Rule master, which is where the
> integrity requirement applies."*

That was a defensible reading then and it is the wrong one now, because business
has said where the integrity requirement applies. **Resolving text afterwards is
a guess about what somebody meant; capturing a reference is a record of what they
chose.** The guess is good — it mapped 11 of 15 existing rows — but the four it
missed are the argument: `12rth` and `10tth`, hand-typed, unmappable, and
invisible until something went looking for them. A dropdown cannot produce them.

**Traceability** (permanent rule 17): explicit business instruction, 2026-08-18,
corroborated by **SRS Screen 4**, which specifies *Promotion Post — Dropdown* and
*Feeder Post — Dropdown*, and consistent with **permanent rule 7** — *always
prefer Master-based architecture over free-text input*. PPT S9 shows the posts
typed into a table and is neutral on the control.

---

## ⚠ The consequence that needs a decision

**A newly registered department has no cadres, and now cannot file a Pre-DPC.**

Verified, not assumed: `RegistrationController` creates no cadre, and until today
the **Pre-DPC was itself what created them** — `projectToMasters()` calls
`Cadre::updateOrCreate(['department_id','name'])` for every post on approval. The
department typed a post name and the master grew a row.

Making Post Name a reference inverts that: the master must be populated **before**
the department can name anything. The only routes into it are the CGPSC Admin's
Cadre screen and a seeder.

**The screen now says so rather than appearing broken:**

> **No post is registered for your department yet.** Posts and feeder cadres on
> this form are chosen from the **Cadre master**, which the CGPSC Administration
> maintains for each department. Ask the Commission to register your department's
> sanctioned posts, then return to this screen.

*Add Post* and *Save Draft* are disabled, and no blank card is opened over a form
that cannot be completed. A test pins it.

**This is a workflow change, and it is the client's to confirm** — it moves
first-time cadre creation from the department to the Commission. It is arguably
more correct for a government system (a department should not be able to invent
its own sanctioned posts) and it is the necessary consequence of what was asked
for, so it is implemented and flagged rather than blocked. **Raised as BD-18.**
If the answer is that departments must remain self-sufficient, the fix is small
and known: let the Cadre master be seeded at registration, or allow the AO to add
a cadre inline.

---

## 1. Database changes

| Change | Table | Notes |
|---|---|---|
| **New column** `qualification_id` | `pre_dpc_post_feeders` | nullable FK → `qualifications`, **ON DELETE SET NULL**, backfilled |
| **Retained** `min_qualification` | `pre_dpc_post_feeders` | **not dropped** — see below |
| *(none needed)* `cadre_id` | `pre_dpc_posts` | **already existed**; it was written by the projection after approval, and is now written by the screen at entry |
| *(none needed)* `feeder_cadre_id` | `pre_dpc_post_feeders` | **already existed and was already validated** by the request — only the form never offered it |

**Two of the four fields needed no migration at all.** The columns had been there
since M3; the screen simply never populated them. Worth recording, because the
instinct on reading the request was to add three foreign keys.

`min_qualification` is kept for the same three reasons the Recruitment Rule
migration kept it: it is the record of what the department wrote, unmatched text
must stay readable because dropping the column drops the finding with it, and the
migration discipline here is additive-only.

## 2. Migration

`2026_08_18_310004_add_qualification_id_to_pre_dpc_post_feeders`

Additive, with a backfill that **matches the same normalised form** — case-folded,
punctuation and whitespace stripped, against both `code` and `name_en` — as the
recruitment-rule backfill, so the same text resolves the same way on both sides
of the projection.

Run against the existing development database:

```
feeders total : 15
mapped        : 11
unmapped      : 4   →  "12rth", "10tth"  (hand-typed during testing)
```

**The four unmapped are the point, not a defect.** They keep a null
`qualification_id` *and* their original text; nothing guesses. This migration
does **not** seed the catalogue — `…_310002_…` had to, because it introduced the
`qualifications` table's first consumer on a fresh database; by the time this
runs the table and its catalogue exist.

`down()` is implemented.

## 3. Validation

Every rule below applies **only where a reference was actually given**, so a
text-only caller is untouched.

| Rule | Where | Why |
|---|---|---|
| Post cadre must be Active and **in the department** | scoped `Rule::exists` | cadres are department-scoped |
| Feeder cadre, same | scoped `Rule::exists` | scoping the `exists` rather than checking afterwards means another department's cadre reads as "not a valid selection", not as "exists but forbidden" |
| Qualification must be **Active and not soft-deleted** | `exists` + `whereNull('deleted_at')` | `Rule::exists` runs on the query builder, so the SoftDeletes scope never applies and a trashed row would otherwise validate — its `status` still reads `active` |
| A post cannot be its **own** feeder | `validateCadreReferences()` | |
| No **duplicate feeder** within a post | `validateCadreReferences()` | |
| No **duplicate post** in one proposal | `validateCadreReferences()` | |

**The last three exist for a specific reason.** The Recruitment Rule master
already refuses a self-feeder and a duplicate feeder, and
`recruitment_rule_feeders` carries a unique index on (rule, feeder cadre).
Without these checks a department could approve a Pre-DPC whose projection either
writes a rule the admin screen can never re-save, or **fails on a database
constraint inside the approval transaction — after the proposal has been
digitally signed.** Same argument as the DR + PR rule added earlier today: one
rule, enforced at both ends.

## 4. Backward compatibility — the part that took the most care

**The endpoint still accepts the shape it always accepted.** `post_name` and
`feeder_name` remain `required` and remain stored; the screen simply no longer
sends them. `prepareForValidation()` derives them from the chosen cadres.

| Caller | Sends | Result |
|---|---|---|
| The screen, from today | `cadre_id`, `feeder_cadre_id`, `qualification_id` | names derived; references stored |
| `DemoMasterSeeder`, `PreDpcWorkflowTest`, any existing API client | `post_name`, `feeder_name`, `min_qualification` | **unchanged behaviour** — cadres created from names at approval, qualification text resolved at projection, exactly as before |
| A caller sending both | both | **its own name is kept.** The master is the better answer, but silently replacing a value somebody supplied is not the request's decision to make |

`projectToMasters()` now **skips cadre creation for a post that already carries a
reference** — creating one from its name would at best find the same row and at
worst mint a duplicate once the cadre was renamed — and prefers a stored
`qualification_id` over re-matching text.

`amend()` carries `cadre_id` and `qualification_id` into the cloned draft.
Without that, an amendment would quietly downgrade a referenced proposal to a
text one **and look correct**, because the names travel with it and would
re-resolve by name at the next projection.

**Verified:** `PreDpcWorkflowTest` passes **unmodified**, and `demo:seed --force`
rebuilds the whole journey with **all seven DPC IDs and the Circulation
unchanged**.

## 5. UI changes

Screenshot: [`docs/design/pre-dpc/`](../design/pre-dpc/README.md).

- **Post Name** — searchable `<select>` over the department's Cadre master.
  Choosing one fills in **Class** and **Pay Scale** from the cadre; both stay
  editable, because the Pre-DPC registers what the Government Order says and if
  the Order disagrees with the master the Order is what is being recorded.
- **Feeder grid** — rebuilt as a proper table. It previously had **no header row
  at all**: four bare inputs identified only by placeholder text, which vanishes
  the moment anything is typed, so a half-filled row was unreadable. Now five
  labelled columns, each with a one-line note, in the **same vocabulary as the
  Recruitment Rule screen**.
- **Minimum Qualification** — searchable `<select>`; the free-text input is gone.
- An empty grid says what to do; an empty **master** says who to ask.
- A stored reference the master no longer offers is **kept and labelled**
  *"(no longer in the master)"* rather than silently falling back to
  "— Select —", which would let a user re-save a blank over real data.
- The **Pre-DPC show screen** now displays the minimum qualification, which it
  never did. Both it and the feeder read through `feederLabel()` /
  `qualificationLabel()`, which prefer the master and fall back to the text.
- `searchable-select.js` is now loaded on `layouts/app.blade.php` as well as the
  admin shell. It is a progressive enhancement over a native `<select>` — with
  the script blocked every field degrades to an ordinary working dropdown.

## Verification

- **798 tests pass** (2404 assertions), up from 782 — **16 new, none modified.**
- **Pint pass · PHPStan (Larastan L6) 0 errors · `composer audit` clean.**
- **All five freezes verified empty.**
- `php artisan migrate` on the existing database: 11/15 mapped, 4 unmapped and
  named, legacy text intact.
- `demo:seed --force`: full journey rebuilds, seven DPC IDs unchanged.
- **Driven in a real browser** — 13 assertions on the grid (every control is a
  `<select>`, options come from the masters, Class auto-fills, every column is
  labelled, the free-text inputs are absent), then **saved end to end**: the row
  stored `cadre_id=3`, `feeder_cadre_id=2`, `qualification_id=7`,
  `min_qualification=NULL` — pure references, no text.

### One defect the screenshot caught that the assertions missed

The **DR + PR** total rendered without its ✓ until the user typed something: a
newly added post card never called `refreshSplit`, so the mark span stayed empty
on a card that was already balanced at 0 / 100. Fixed, and now asserted on load.

## Still open

**BD-18** — does first-time cadre registration move to the Commission, as this
change implies? Nothing else in this work is uncertain.
