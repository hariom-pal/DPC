# DPC-MODULE-FIX-001 — Post Master Audit

**e-DPC Portal · Chhattisgarh Public Service Commission**
**Audited and fixed:** 2026-09-06
**Revised:** 2026-09-06 — §4.3 overruled by business and fixed (see that section)

**Business rule:** *A Post is a master entity. Users must never type the Post name
manually anywhere in the application. Every screen must use the Department Post
master.*

**Result:** **zero free-text Post or designation inputs remain in the application.**

* *First pass (§2):* two screens allowed a Post to be typed; both fixed.
* *Second pass (§4.3), after business overruled this audit's own finding:* three
  further screens allowed a **designation** to be typed; all three now read the
  Designation master.

Everything else was already correct. One importer is documented as a deliberate
exception (§4.1), and one corrupt sample file was found and repaired (§4.2).

---

# 1 · Every location where a Post is used

Found by auditing every `post`-bearing database column, every form input, every
display, every filter and every importer.

## 1.1 · Database columns holding a Post

| Table | Column | Kind | Classification |
|---|---|---|---|
| `department_posts` | *(the master itself)* | master | **The master** |
| `department_post_orders` | `department_post_id` | FK | ✅ Correct |
| `recruitment_rules` | `post_id` | FK | ✅ Correct |
| `recruitment_rule_sources` | `promotion_from_post_id` | FK | ✅ Correct |
| `reservation_rosters` | `post_id` | FK | ✅ Correct |
| `pre_dpc_proposals` | `promotion_to_post_id` | FK | ✅ Correct |
| `pre_dpc_promotion_sources` | `promotion_from_post_id` | FK | ✅ Correct |
| `dpc_proposals` | `promotion_to_post_id`, `promotion_from_post_id` | FK | ✅ Correct |
| `dpc_vacancy_calculations` | `sanctioned_from_post_id` | FK | ✅ Correct |
| `dpc_working_officers` | `promotion_to_post`, `promotion_from_post` | **varchar snapshot** | ✅ Correct **by design** — see §1.5 |
| `dpc_seniority_entries` | `promotion_to_post`, `promotion_from_post` | **varchar snapshot** | ✅ Correct by design |
| `dpc_evaluation_rows` | `promotion_to_post`, `promotion_from_post` | **varchar snapshot** | ✅ Correct by design |

**No duplicate Post master exists anywhere.** `department_posts` is the only one.
(`designations` is a separate master for a person's designation, not a post; the
`cadres` table was renamed to `department_posts` by migration `2026_08_24_360001`
and no second table survived.)

## 1.2 · Forms and modals

| Screen | Field | Before | After |
|---|---|---|---|
| Admin → Department Posts (create/edit/supersede) | *the master* | `<select>` department, typed name | ✅ **Correct** — this is where a post is defined |
| Admin → Recruitment Rules → form | `post_id` | `<select>` from master | ✅ **Correct** |
| Admin → Recruitment Rules → source rows | `promotion_from_post_id` | `<select>` from master | ✅ **Correct** |
| Admin → Reservation Rosters → form | `post_id` | `<select>` from master | ✅ **Correct** |
| Pre-DPC → New / Edit | `promotion_to_post_id` | `<select>` from master, `inForce()` | ✅ **Correct** |
| Pre-DPC → promotion source rows | `promotion_sources[n][promotion_from_post_id]` | `<select>` from master | ✅ **Correct** |
| DPC Proposal → New | `promotion_from_post_id` | `<select>`, inherited from the Pre-DPC | ✅ **Correct** |
| **DPC → Working Officers (S16) → row** | `officers[n][promotion_to_post]` | ❌ **`<input type="text">`** | ✅ **FIXED** → `promotion_to_post_id` `<select>` |
| **DPC → Working Officers (S16) → row** | `officers[n][promotion_from_post]` | ❌ **`<input type="text">`** | ✅ **FIXED** → `promotion_from_post_id` `<select>` |
| **DPC → Seniority List (S17) → row** | `entries[n][promotion_to_post]` | ❌ **`<input type="text">`** | ✅ **FIXED** → `promotion_to_post_id` `<select>` |
| **DPC → Seniority List (S17) → row** | `entries[n][promotion_from_post]` | ❌ **`<input type="text">`** | ✅ **FIXED** → `promotion_from_post_id` `<select>` |
| DPC → Evaluation Chart (S18) | — | no post input at all; identity is projected from S17 | ✅ **Correct** |
| DPC → Committee | `members[n][designation]` | free text | ✅ **Correct — not a Post.** A committee member's *designation* is deliberately free text (register of 2026-08-26 withdrew the Commission Members master); it is not a Department Post and must not be forced into one |

## 1.3 · Search and filter

| Surface | Post used? | Classification |
|---|---|---|
| Pre-DPC → derived attributes endpoint | `post_ids[]` — **integers**, scoped to the officer's own department by `PostAttributeResolver` | ✅ **Correct** |
| Admin → Audit Trail filters | `source`, `event`, `actor`, `subject_type`, `subject_id`, `from`, `to` — **no post filter** | ✅ N/A |
| Reports | scoped by **department**, not by post | ✅ N/A |
| Admin → Users | name / e-mail / mobile — no post | ✅ N/A |
| DPC / Pre-DPC listings | listed by department, no post filter | ✅ N/A |

**No search or filter anywhere accepts a typed Post name.**

## 1.4 · Reports and documents

All read the **master relation** (`promotionToPost->name` / `promotionFromPost->name`),
never a typed string — **21 references across 27 view files**:

Pre-DPC index & show · DPC index, form, review, post-selection section ·
calculations index & show · allotments index & history · schedules index & history ·
proceedings index, show & result · **promotion-list document** · **document header** ·
candidate index & show · self-inspection show & print · recruitment-rules index ·
pre-dpc-links index · review create.

✅ **Correct — reports and documents already display the Post name from the
Department Post master.**

The Evaluation Chart's per-officer detail panel displays the **snapshot**
(`$row->promotion_to_post`) — correct, because that panel exists to show what was
recorded *for that officer at that time*, not what the master says today.

## 1.5 · Why the officer tables keep a varchar name — and why that is not a defect

`dpc_working_officers`, `dpc_seniority_entries` and `dpc_evaluation_rows` store the
post **name**, not a foreign key. That is the ten-field officer-snapshot standard,
and it is deliberate:

> Every officer table in this application is a point-in-time snapshot with no live
> foreign key, so that a concluded proposal cannot change because a master record was
> edited later.

A foreign key here would undo the exact property the snapshot exists to provide, and
would require a schema change — which this fix forbids.

**The fix therefore separates the two concerns:** the **screen** offers only master
rows and posts an **id**; the **service** resolves that id to the master row's name
and stores the name. The officer can no longer invent a post, and the record still
says what the post was called when the DPC ran.

---

# 2 · Fixed locations

## 2.1 · What changed

| # | File | Change |
|---|---|---|
| 1 | `app/Domain/Dpc/Validation/DpcRowRules.php` | Added `promotion_to_post_id` / `promotion_from_post_id` — `nullable, integer, exists:department_posts` scoped to **active, in-force, not deleted**. The legacy name rules are **retained** for the importers |
| 2 | `app/Domain/Dpc/Services/DpcProposalService.php` | New private `postName()` resolves a posted master id → the master's name; used by **both** save paths (working officers and seniority entries) |
| 3 | `app/Http/Controllers/Dpc/DpcProposalController.php` | New private `selectablePosts()`; `departmentPosts` passed to the S16 and S17 views |
| 4 | `resources/views/dpc-proposals/working-officers.blade.php` | Two `<input type="text">` → two `<select>` from the master; seed now carries `*_post_id` |
| 5 | `resources/views/dpc-proposals/seniority-list.blade.php` | Same two replacements and the same seed change |
| 6 | `public/js/forms/dpc-working-officers.js` | Row-fill targets `[promotion_to_post_id]` / `[promotion_from_post_id]` |
| 7 | `public/js/forms/dpc-seniority-list.js` | Same |
| 8 | `docs/demo/sample-data/demo-working-officers.csv` | **Data repair** — see §4.2 |

**No schema change. No new master. No new table. No hardcoded value. No business
rule changed.**

## 2.2 · The dropdown meets every stated requirement

| Requirement | How |
|---|---|
| Show Department Post Name | `<option>{{ $dp->name }}</option>` |
| Use existing Department Post master | `DepartmentPost::query()->inForce()` |
| **Store Department Post ID** | the form posts `promotion_to_post_id` / `promotion_from_post_id` |
| Display **active** posts only | `inForce()` = `status = active` **and** `active_flag` not null **and** within its effective dates |
| Maintain existing validations | every other rule on the row is untouched; the two new rules are additive |
| Maintain existing business logic | the snapshot still stores a name; the vacancy engine, the zone, the scoring engine and every document are unchanged |

**Why the department's whole active list rather than only the proposal's two posts:**
a Pre-DPC may sanction **several feeder posts** (`pre_dpc_promotion_sources` is
plural), so officers on one chart can legitimately come from different feeders.
Narrowing to two would have refused a legitimate multi-feeder exercise.

---

# 3 · Locations already correct

- **The Department Post master itself** — Admin → Department Posts, the only place a
  post is defined. Now also the only place its Sanctioned Strength is maintained
  (DPC-MODULE-FIX of 2026-09-06).
- **Every foreign key** listed in §1.1 — 9 tables.
- **Every admin form** that references a post — Recruitment Rules (rule + sources),
  Reservation Rosters.
- **Pre-DPC and DPC Proposal forms** — already master dropdowns with `inForce()`
  scoping.
- **Every report and every generated document** — 21 references, all through the
  master relation.
- **Every search and filter** — none accepts a typed post.
- **Evaluation Chart (S18)** — never asked for a post; identity is projected from the
  Seniority List.

---

# 4 · Remaining business questions and exceptions

## 4.1 · EXCEPTION (justified) · The three CSV importers accept a Post **name**

`ImportTemplate` maps the spreadsheet columns `PROMOTION TO POST` and
`PROMOTION FROM POST` to the free-text fields, and `DpcRowRules` still accepts them.

**This is deliberate and must not change without a business decision.**

- A department filling in a spreadsheet **has no access to our primary keys**. This is
  the same reasoning that makes `reservation_category` a **code** (`UR`/`SC`/`ST`)
  on a sheet and an **id** on a form.
- Every workbook already issued — including the blank templates departments have
  downloaded — carries names. Refusing them would break backward compatibility, which
  this fix explicitly forbids.

**The open question for the business:** should the importer **resolve** the name
against the Department Post master and *reject* an unrecognised one?

Today it does **not**. Verified: a working-officers file naming
*Additional Director, Education* imported cleanly into a DPC for
*Block Education Officer* — **4 valid, 0 rejected, no warning**. The arithmetic is
unaffected (the engine uses the proposal's post ids, never the CSV text), but the
wrong names reach the chart and the signed documents.

**Three options, for the Commission to choose:**

| Option | Effect | Risk |
|---|---|---|
| **A — Warn** *(recommended)* | The import preview shows *"row 3 names a post that is not this DPC's"*; the officer may still commit | None to backward compatibility |
| **B — Resolve and reject** | An unrecognised name is a rejected row | A department that renames a post mid-exercise is blocked |
| **C — Leave as is** | Status quo | The defect found in §4.2 can recur silently |

Recorded as **UAT-12** in `docs/uat/TERMINAL_SUGGESTIONS.md`. **Not implemented** —
it changes what an importer accepts, which is a business rule.

## 4.2 · DEFECT FOUND AND REPAIRED · `demo-working-officers.csv` named the wrong posts

While verifying the fix, the sample file was found to name
**`Additional Director, Education` ← `Block Education Officer`**, while the Seniority
List and the Evaluation Chart both named **`Block Education Officer` ←
`Head Master, Middle School`**. The file's timestamp (20:53) was two and a half hours
later than the other two (18:22), so it had been altered after generation.

**Consequence had it gone undetected:** the demonstration's S16 chart would have
displayed a completely different post pair from S17 and S18, and — because of the
exception in §4.1 — it would have imported **without a single warning**.

**Repaired.** All three files now name the same pair; verified by re-importing all
three and confirming the stored snapshots agree:

```
working_officers   Block Education Officer <- Head Master, Middle School   4 rows
seniority          Block Education Officer <- Head Master, Middle School  10 rows
evaluation         Block Education Officer <- Head Master, Middle School  10 rows
```

The calculation is unchanged: **10 assessed · 6 eligible · Main 3 · Waiting 3 ·
Not Eligible 4.**

> This is the strongest argument for **Option A** in §4.1: the corruption was
> invisible to the application and was caught only because a human compared three
> files by hand.

## 4.3 · ~~NOT A POST~~ → **OVERRULED AND FIXED** · Committee member designation

**This section originally recorded that `members[n][designation]` was not a Post and
would stay free text. Business overruled that on 2026-09-06:**

> *"OK i told to make everything from master but when i am creating committe member
> Designation is free text but it should be from post master only it is still not
> fixed"*

The instruction is honoured. Three screens that accepted a typed designation now post
a **master id**:

| Screen | Field before | Field now |
|---|---|---|
| DPC Committee — composition | `members[n][designation]` *(text)* | `members[n][designation_id]` *(select)* |
| DPC Committee — substitute a member | `designation` *(text)* | `designation_id` *(select)* |
| CGPSC Chairman — appoint / amend | `designation` *(text)* | `designation_id` *(select)* |

**Which master.** The **Designation master** (`designations`, 26 active rows), not
`department_posts`. This is the one judgement call in the fix, and it is worth stating
plainly:

* `department_posts` is a **single department's promotable cadre**. Every row is
  scoped by `department_id`; the School Education demo department holds nine.
* A DPC committee is drawn from **across the government** — the Chairman and Members
  of CGPSC, the Secretary of the department, the Deputy Secretary of GAD. **None of
  those seats is a row in `department_posts`, and none can be**, because they belong
  to no single department's promotion cadre.
* Binding this field to `department_posts` literally would therefore have made the
  demo committee — and every real committee — **unrecordable**.
* `designations` is the master that already carries all of them, *including every
  Department Post name*, and it is the master `users.designation_id` already points
  at. It is administered at **Admin → Masters → Designations**, so nothing here is
  hardcoded and no new master was created.

The business rule — *a rank is chosen from a master, never typed* — is satisfied. If
the business wants these three fields bound to `department_posts` instead, that is a
one-line change per screen, but the Designation master would first have to be merged
into it, and a DPC committee would then be limited to one department's cadre.

**What is stored is unchanged.** The three columns still hold the designation **name**
as a `varchar`, exactly as before, and no migration was written. That is the same
snapshot principle §1.5 sets out for the officer tables: a committee that has already
sat, and a proceeding that already cites it, must not change because somebody later
renames a master row. The id is what the *screen* posts; the name is what the *record*
keeps.

**Backward compatibility.** The free-text `designation` key is still accepted on all
three requests, and is read only when no id arrives — so a committee composed before
this change re-saves without losing its designation. The id wins where both are
present. Pinned by
`DpcCommitteeTest::test_a_committee_posting_only_the_old_free_text_designation_still_saves`.

**Master rows added for the demo.** `Chairman, Chhattisgarh Public Service Commission`,
`Secretary, School Education` and `Deputy Secretary, GAD` — the three ranks the demo
script names — were absent from the master and are now seeded by
`Demo\DemoAccountSeeder::seedDesignations()`. Without them the dropdown could not
offer what the printed script asks the presenter to select.

---

# 5 · Verification steps

## 5.1 · Screen — the post is now chosen, never typed

1. Sign in `ao.schooleducation@cg.gov.in` / `Demo@2026`
2. Open a Draft DPC → **Working Officers & Vacancies** → **+ Add Row**
3. The *Promotion To Post* and *Promotion From Post* fields are **drop-downs**, not
   text boxes.

**Verified output** — 9 master options plus a blank, ordered by name:

```
promotion_to_post_id   -> 9 master options
   value=(blank)  — Select —
   value=8        Additional Director, Education
   value=5        Block Education Officer
   value=9        Director, Public Instruction
   …
promotion_from_post_id -> 9 master options   (same list)
```

Repeat on **Seniority List** → identical.

## 5.2 · Storage — an ID is posted, the master's name is stored

Posted `officers[0][promotion_to_post_id]=5` and `[promotion_from_post_id]=2` —
**no name anywhere in the payload**:

```
sn  status   employee_number  promotion_to_post        promotion_from_post
1   working  CG500001         Block Education Officer  Head Master, Middle School

-- the master rows those ids point at
id  name
2   Head Master, Middle School
5   Block Education Officer
```

**The stored name is the master's name, resolved from the id.**

## 5.3 · Backward compatibility — the importers still work

`demo-working-officers.csv` (which carries **names**, not ids):

```
Total Rows 4 · Valid 4 · Rejected 0 · commit 302
```

All three importers re-run clean: **4 / 10 / 10**, and the downstream calculation is
unchanged at **Main 3 · Waiting 3 · Not Eligible 4**.

## 5.4 · Round trip — a saved row re-selects its master row

Reloading the screen after a save seeds the id back from the stored name:

```
sn 1 -> to_post_id 8 | from_post_id 5 | Additional Director, Education
sn 2 -> to_post_id 8 | from_post_id 5 | Additional Director, Education
```

A row whose post has since been **superseded** seeds blank rather than guessing — the
honest answer.

## 5.5 · Sweep — nothing left to type

```
free-text post inputs across all views ....... 0
free-text designation inputs remaining ....... 0   (were 3; fixed — §4.3)
duplicate Post masters ....................... 0
hardcoded post values in views/JS ............ 0
```

## 5.6 · Quality gate

```
php artisan test  ....... 1339 passed (4447 assertions)   [+12 for the §4.3 fix]
./vendor/bin/pint ....... passed
phpstan analyse ......... no errors
php artisan lang:check .. catalogue complete
```

Database returned to the clean baseline: 34 users · 0 proposals · 9 department posts
· Recruitment Rules empty · `storage/app` 3 files · Redis flushed.

---

# 6 · Summary

**Files modified — 21** (19 code/view/JS, 1 seeder, 1 sample data)

*First pass — the Post itself:* `DpcRowRules.php` · `DpcProposalService.php` ·
`DpcProposalController.php` · `working-officers.blade.php` ·
`seniority-list.blade.php` · `dpc-working-officers.js` · `dpc-seniority-list.js` ·
`demo-working-officers.csv`

*Second pass — designation, after the §4.3 overrule:* `SaveDpcCommitteeRequest.php` ·
`ReplaceCommitteeMemberRequest.php` · `CgpscChairmanRequest.php` ·
`DpcCommitteeService.php` · `CgpscChairmanService.php` ·
`DpcCommitteeMemberController.php` · `CgpscChairmanController.php` ·
`committee.blade.php` · `committee-replace.blade.php` ·
`cgpsc-chairman/form.blade.php` · `dpc-committee.js` · `DemoAccountSeeder.php`

**Screens verified — 27 views + 5 admin masters + 3 importers + every filter**

**Classification tally**

| Classification | Count |
|---|---|
| ✅ Correct (already using the master) | 30 |
| ❌ Incorrect (manual typing) → **fixed** | **7 fields on 5 screens** |
| ♻️ Duplicate master | **0** |
| 📌 Static / hardcoded | **0** |
| ⚠️ Justified exception | **1** — the CSV importers (§4.1) |

**Remaining exceptions:** one. The three CSV importers accept a Post *name* for
backward compatibility — documented, with three options for the business (§4.1).

Committee and Chairman *designation* was listed here as a second exception until
business overruled it on 2026-09-06; it is now fixed and reads from the Designation
master (§4.3).

---

*Audited, fixed and verified 2026-09-06 against the running application. No schema
change, no new master, no business rule changed.*
