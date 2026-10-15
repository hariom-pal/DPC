# Change Register — 2026-08-27

**The Vacancy Calculation Engine and the Merit Calculation Engine**
e-DPC Portal · Chhattisgarh Public Service Commission

**Status: implemented, and the quality gate is green.**
1,287 tests pass. PHPStan clean. Pint clean. No security advisories. MySQL builds the full
schema from zero — 79 migrations, 78 tables — and `demo:seed` rebuilds a complete,
publishable journey from an empty database.

---

## What the register asked for, and what it got

Twenty-four items across two engines, plus ten questions it asked to be recorded and not
built.

| Part | Items | Status |
|---|---|---|
| 1 — Vacancy Calculation Engine | 1–10 | **Implemented** |
| 2 — Merit Calculation Engine | 11–24 | **Implemented**, with three collisions inside the register recorded rather than resolved in code |
| 5 — Pending business questions | 10 items | **Documented, not built** — as instructed |
| 7 — This report | — | Below |

**The four standing instructions were honoured.** No business logic was invented; every
better idea found on the way is a proposal in `SUGGESTIONS_AND_IMPROVEMENTS.md` (items 16
to 21) rather than a change; every unclear rule is a question in
`CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` (BD-32 to BD-49) rather than an assumption; and
items 21 to 23 were taken literally — **the marking formula, the qualifying score and
Super Seat were not modified.**

---

## Read this first — one thing needs a decision before a real list is published

**BD-39. The portal cannot compare an officer's qualification against the Recruitment
Rule, and it is declining to guess.**

Item 12 asks for the qualification to be validated against the rule. The two sides do not
describe the same kind of thing:

- the Recruitment Rule links to the **Qualification Master**, which holds *levels* —
  `Post-Graduate`, `Graduate`, `Diploma`;
- the Evaluation Chart carries the **degree** an operator typed — `M.A., B.Ed.`

Nothing in software knows that an M.A. *is* a post-graduate degree. The obvious
implementation — match the text — refuses `M.A., B.Ed.` against a requirement of
`Post-Graduate`, which means **refusing, in writing, an officer who plainly holds the
qualification.** That is the worst error this system can make, and worse than a blocked
list: a blocked list is visibly incomplete, and a wrong refusal is not.

So the finding is **reported and not applied**. It appears on the calculation's Pending
Decisions, aggregated into one entry naming every officer affected, and changes nobody's
status. The other two limbs of item 12 — Experience and Promotion Duration, which compare
numbers — **are** enforced. `dpc.merit.qualification_gate` turns the qualification limb on
the day the Commission supplies a degree-to-level mapping; suggestion 16 sets out what
that would take.

Everything else in this register is running.

---

## Files Modified

### New — Vacancy Calculation Engine

| File | What it is |
|---|---|
| `app/Domain/Dpc/Services/VacancyEngine.php` | Pure. The nine figures of item 8, the period, the retiring and deputed officers, the rounding |
| `app/Domain/Dpc/ValueObjects/VacancyBreakdown.php` | Readonly VO — the nine figures, the period, the contributors, `isComplete()` |
| `app/Domain/Dpc/Services/VacancyCalculationRecorder.php` | Persists a breakdown, supersedes the previous one under a lock, writes the timeline |
| `app/Domain/Dpc/Models/DpcVacancyCalculation.php` | Append-only. Only `superseded_by_id` may be updated; nothing may be deleted |

### New — Merit Calculation Engine

| File | What it is |
|---|---|
| `app/Domain/Eligibility/Services/PromotionHierarchyService.php` | Pure. Item 11 — Class, Pay Scale level, Grade Pay |
| `app/Domain/Eligibility/Services/CandidateGateService.php` | Pure. Items 12–20, run before the formula |
| `app/Domain/Eligibility/ValueObjects/CandidateGateVerdict.php` | Status, reasons, gap citations, advisories |
| `app/Domain/Master/Models/RecordReason.php` | Item 16 — the configurable Reason Master |
| `app/Domain/Master/Requests/RecordReasonRequest.php` | Including the unticked-checkbox fix, so a mandatory-remarks flag can be turned off again |
| `app/Http/Controllers/Admin/RecordReasonController.php` | Admin CRUD |
| `app/Domain/Dpc/Models/DpcEvaluationRecordGap.php` | Items 14/15/19 — one row per (officer, kind, year) |
| `app/Domain/Dpc/Requests/RecordGapRequest.php` | Reason + PDF both mandatory; remarks conditional on the reason's own flag |
| `app/Domain/Dpc/Services/RecordGapService.php` | Saves the reason and the document together or not at all |
| `app/Http/Controllers/Dpc/DpcRecordGapController.php` | The per-officer screen, the download, the withdrawal |

### Modified

`DpcProposalService` (`saveVacancies` signature and ordering) · `SaveWorkingOfficersRequest`
(item 9's four validations) · `VacancyCalculationService` (delegates to the engine) ·
`DpcProposalCompletenessService` (+`sectionIsComplete()`) · `DpcWorkingOfficer`
(+`retiresDuring()`, `isOnDeputationDuring()`) · `DepartmentPost` · `PayScale` ·
`RecruitmentRuleSource` (+`minExperienceInMonths()`) · `DpcEvaluationCrGrade` ·
`DpcEvaluationRow` · `DpcProposal` (nullability annotation) · `DpcRowRules` ·
`EvaluationCandidateService` (the per-year writer and the carry-forward map) ·
`ExcelImportService` · `ImportTemplate` · `ImportTemplateBuilder` ·
`RemarkCode` · `OfficerRecord` · `EligibilityVerdict` (+`overriddenBy()`) ·
`DpcCalculationService` · `DpcCalculationResult` (+`status()`, `failureReasons()`,
`gateCitations()`) · `ExplainCalculationService` · `DpcProposalController` ·
`ResetTransactionalDataCommand` · `DemoDpcBuilder` · `DemoChart` · `DemoOfficers`

### Views and assets

`dpc-proposals/working-officers.blade.php` (the nine-figure summary, the contributors, the
retirement and deputation inputs) · `dpc-proposals/evaluation-chart.blade.php` (the
per-year Tribal Area column, the Missing records link) · `dpc-proposals/record-gaps.blade.php`
(new) · `dpc-proposals/calculations/show.blade.php` (four statuses, the awaited record) ·
`admin/record-reasons/{index,form}.blade.php` (new) · `layouts/admin.blade.php` (nav) ·
`public/js/forms/dpc-working-officers.js` · `public/js/forms/dpc-record-gaps.js` (new,
external — the CSP forbids inline script)

### Language

`lang/{en,hi}/dpc.php` — a new `record_gaps` group (35 keys) and two `evaluation_chart`
keys. `lang/{en,hi}/admin.php` — a new `record_reasons` group (17 keys).
`lang/{en,hi}/ui.php` — one nav key. **`php artisan lang:check` reports the catalogue
complete**: nothing missing from Hindi, nothing extra, one pre-existing identical string.

---

## Database Changes

### Migrations

| Migration | What it does |
|---|---|
| `2026_08_27_410001_add_sanctioned_strength_and_pay_scale_ordering` | `department_posts.sanctioned_strength`; `pay_scales.level`, `pay_scales.grade_pay`. Backfills the sanctioned strength **only where every existing proposal for a post agreed** — a disagreement is left null rather than guessed |
| `2026_08_27_410002_add_retirement_and_deputation_period_to_working_officers` | `date_of_retirement`; renames `deputation_date` → `deputation_from`; adds `deputation_to` |
| `2026_08_27_410003_create_dpc_vacancy_calculations_table` | The audit table — all nine figures, the exact fractional promotion figure, the rounding rule, the contributors, the inputs, `superseded_by_id` |
| `2026_08_27_410004_create_record_reasons_and_evaluation_gaps` | `record_reasons` (seeding the register's seven suggested values, `requires_remarks` on `other` alone) and `dpc_evaluation_record_gaps` |
| `2026_08_27_410005_link_tribal_area_service_to_its_cr_year` | `dpc_evaluation_cr_grades.tribal_area`, nullable |

**All five are additive**, as the change-control rule requires. One rename
(`deputation_date` → `deputation_from`) preserves the data.

### New tables

**`dpc_vacancy_calculations`** — append-only evidence. Registered in
`dpc.security.evidence_tables`, so `php artisan security:evidence-grants` covers it and
`EvidenceGrantsTest` enforces that it is covered. A recalculation writes a new row and
points the old one at it.

**`record_reasons`** — a master, soft-deletable, UUID-addressed. `requires_remarks` is a
**column** rather than a comparison against the code `other`, so an Administrator can mark
*Court Case* as needing the case number without a deployment, and renaming "Other" does
not silently switch the rule off.

**`dpc_evaluation_record_gaps`** — one row per (officer, kind, year), unique on that
triple, with the reason (`restrictOnDelete` — a withdrawn reason stays on the records that
cite it), the remarks, and the document with its SHA-256.

### Modified tables

`department_posts` (+1) · `pay_scales` (+2) · `dpc_working_officers` (+2, 1 renamed) ·
`dpc_evaluation_cr_grades` (+1)

`dpc_proposals.sanctioned_post_count` is **kept**, as a snapshot the service writes rather
than a figure the officer enters. Removing it would lose what each historical DPC was
computed against.

---

## API Changes

No public or machine API exists, so nothing external changed. The internal routes added:

| Method | Route | Name |
|---|---|---|
| GET/POST/PUT/DELETE | `admin/record-reasons` (resource, no `show`) | `admin.record-reasons.*` |
| GET | `dpc-proposals/{p}/officers/{sn}/missing-records` | `dpc-proposals.record-gaps.index` |
| POST | `dpc-proposals/{p}/officers/{sn}/missing-records` | `dpc-proposals.record-gaps.store` |
| GET | `…/missing-records/{gap}/document` | `dpc-proposals.record-gaps.download` |
| DELETE | `…/missing-records/{gap}` | `dpc-proposals.record-gaps.destroy` |

The officer is addressed by **seniority number, scoped to the proposal** — the chart's own
identifier — and `Route::scopeBindings()` stops one proposal's URL reaching another's
officer. The document is streamed from a private disk; there is no public URL for a
departmental record about a named officer.

`sanctioned_post_count` **no longer appears in the working-officers payload.** A crafted
request supplying it is ignored, not honoured.

---

## Validation Changes

### Vacancy (item 9)

| Rule | Where |
|---|---|
| Working Strength ≤ Sanctioned Strength | `SaveWorkingOfficersRequest::assertBalanced()` |
| A retirement date must fall inside the vacancy period | `assertRetirementDatesFallInThePeriod()` |
| A deputation must overlap the vacancy period | `assertDeputationsOverlapThePeriod()` |
| The promotion percentage is never accepted from the form | the field does not exist |
| The vacancy breakdown must balance against the **arriving** officer list | `assertBalanced()`, computed from the posted rows |

That last one was a defect found in testing: the balance check read the *saved* chart, so a
correctly balanced submission was reported as unbalanced by exactly the number of rows
being added.

### Merit

| Rule | Where |
|---|---|
| A gap needs a reason **and** a PDF | `RecordGapRequest`, `RecordGapService`, and `OfficerRecord::fromEvaluationRow()` |
| Remarks are mandatory when the reason says so | `RecordGapRequest::assertRemarksWhereTheReasonDemandsThem()` |
| A Loyalty gap must be a year that carries a CR | `assertTheYearIsOnTheChart()` |
| Only a *selectable* reason may be chosen | `Rule::exists` on the active scope |
| The document must be a PDF within `dpc.documents.max_kb` | `RecordGapRequest` |
| `tribal_area` is a nullable boolean per CR year | `DpcRowRules::evaluationCrGrade()` |

Three layers check the reason-plus-document rule, and that is not redundancy: the failure
mode is a gap the screen shows as accounted for and the engine reads as unaccounted for,
which nobody can diagnose from the outside.

---

## UI Changes

- **Working Officers** — the nine-figure breakdown, the contributing officers named with
  their dates, the period stated, and the sanctioned-strength input **removed**. Three new
  date fields per officer.
- **Evaluation Chart** — a per-year **Tribal Area** column (three states: served, did not
  serve, not recorded), and a **Missing records** button per officer carrying a count.
- **Missing Mandatory Records** (new) — what the officer's chart is short of, what has
  already been accounted for with a link to each PDF and its hash, and the form to account
  for another. Remarks become visibly mandatory the moment a reason demanding them is
  chosen.
- **Calculation result** — the Outcome column now prints one of the four statuses with the
  awaited record beneath a CIR, instead of collapsing everything into Eligible / Not
  Eligible.
- **Explain Result** — states which of the four statuses was reached, on what ground, and
  which record is awaited.
- **Admin → Reasons for a Missing Record** (new) — the master, with the display order and
  the mandatory-remarks flag.

---

## Reports Updated

- The **vacancy audit** is new: `dpc_vacancy_calculations` holds every figure with the
  inputs it came from and the rule applied, superseded rows retained.
- **Pending Decisions** on a run now carry the gates' advisories, aggregated per finding
  with the officers named — one line per finding, not one per officer, so a genuine
  single-officer decision is not buried.
- The **import template** gains a `TRIBAL AREA <year>` column per CR year, and the
  pre-filled template emits it, so a round trip through Excel does not erase an
  attribution the department has already made.
- `dpc:reset-transactional-data` clears `dpc_vacancy_calculations` and
  `dpc_evaluation_record_gaps` and keeps `record_reasons` — a master.

---

## Tests Executed

**1,287 passing, 4,228 assertions.** Up from 1,219 at the end of Part 1.

### New

| Class | Covers |
|---|---|
| `tests/Unit/Eligibility/PromotionHierarchyServiceTest.php` (10) | Item 11 — the three named rejections, equality passing, an unanswerable check skipped rather than failed, every failure reported together |
| `tests/Unit/Eligibility/CandidateGateServiceTest.php` (27) | Items 12–20 — the property window, the recruitment rule in months, CIR, the gate order, and both readings of BD-39 |
| `tests/Feature/Eligibility/MeritGateTest.php` (11) | The same rules end to end, including all four statuses in one run and the result screen printing CIR rather than Not Eligible |
| `tests/Feature/Dpc/DpcRecordGapTest.php` (11) | The gap screens — reason without PDF refused, non-PDF refused, remarks conditional, loyalty needs a CR year, re-recording replaces, cross-proposal URL is a 404 |
| `tests/Feature/Admin/RecordReasonMasterTest.php` (7) | The Reason Master, including clearing the mandatory-remarks flag and withdrawal leaving the selectable list |

`ResetTransactionalDataTest`'s classification guard was **repaired** rather than extended —
see defect 6 below. It had never been able to fail.

### Extended

`DpcWorkingOfficersTest` (retirement, deputation, the audit row) ·
`DpcVacancyCalculationTest` · `DpcEvaluationChartTest` (+3 — the tribal year, its survival
through a re-projection, and the officer-level total surviving beside it) ·
`ProceedingHoldTest` (rewritten for BD-38 — the hold still fires *and* the status is now
`Rejected`) · `DpcCalculationTest`, `CalculationUiTest`, `DpcExcelImportTest`,
`ReviewDpcTest`, `ImportTemplateTest`, `ResetTransactionalDataTest`

### Fixtures migrated, and why that mattered

`CompletesDpcProposals::evaluationDataFor()` recorded **one** CR year with one property
return. Item 13 rejects a candidate whose five-year property window has a hole, so every
officer in every workflow test became a rejected officer — which is an opinion about
eligibility, and the loudest one available. The fixtures now carry the full window, read
from `dpc.merit.*` so they follow the rule in force rather than disagreeing with it
silently.

The same correction was made in `DpcCalculationTest`, `CalculationUiTest` and
`MeritGateTest`. **Property defaults to the full window; loyalty defaults to the CR
years** — deliberately not the same default, because item 15 ties a certificate to a CR
year while item 13's window is continuous and independent of the CR chart.

### The full gate

```
php artisan test            1,287 passed
vendor/bin/pint             clean
vendor/bin/phpstan          no errors
composer audit              no advisories
php artisan migrate:fresh   79 migrations, 78 tables
php artisan schema:verify-mysql   full schema built
php artisan lang:check      catalogue complete
php artisan demo:seed       rebuilds a complete, publishable journey
```

---

## Six defects found while implementing, and fixed

None was introduced by this register. All six were found by implementing it, and the
*class* of each is worth recording. **The last two are the most important**, because they
are about the quality gate rather than about the code.

**1. `min_experience_unit` was being ignored.** A recruitment rule may publish 18
**months**; the gate compared that number against whole years. An officer with 20 months
of qualifying service was refused for having "1 year" against a requirement of "18". The
enum exists precisely because *rounding those up to a year changes who is eligible* — and
the first code to actually compare the value rounded it. Both sides are now in months.

**2. `promotion_duration_years` was read off the wrong table.** It lives on
`dpc_proposals`, carried across from the Pre-DPC; the gate read it from
`recruitment_rule_sources`, where the column does not exist. Eloquent returns null for an
undefined attribute rather than erroring, so **item 12's third check was silently switched
off and no test failed.** PHPStan found it.

**3. `DemoDpcBuilder` called the old `saveVacancies()` signature** — and in the old order,
recording a vacancy calculation that described the chart about to replace it. `demo:seed`
would have failed on the first run. No test covers the seeder, which is why the demo run
is part of the gate.

**4. The carry-forward map would have erased `tribal_area`.** Re-projecting a chart deletes
and rewrites the per-year rows, so a field omitted from
`EvaluationCandidateService::carriedEvaluationData()` is a field silently lost the next
time the Seniority List is saved. Now carried, and pinned by a test that saves the list
twice.

**5. Two new tables were unclassified in `dpc:reset-transactional-data`**, so the command
refused to run at all — its own guard caught them. `dpc_evaluation_record_gaps` is now
transactional (listed explicitly, because the command **truncates** and a truncate does not
fire the cascade from `dpc_evaluation_rows` — the rows would have outlived the chart they
describe and pointed at officers who no longer exist), `record_reasons` is preserved as the
master it is, and `private/record-gaps` was added to the storage paths so the uploaded PDFs
go with the data.

**6. The test that should have caught defect 5 could not fail.**
`ResetTransactionalDataTest::test_every_table_in_the_schema_is_classified()` passed
`DB::connection()->getDatabaseName()` as the *schema* to filter on — which is `:memory:` on
the SQLite connection the suite runs on. No table lives in a schema of that name, so the
listing returned **zero tables** and `array_diff([], $known)` was trivially empty. The
assertion had never checked anything.

It now lists every table unqualified, **and asserts the listing returned something like a
whole schema before comparing** — a floor of 50 tables — so it cannot become vacuous
again. Verified by deleting an entry and watching it fail, then restoring it and watching
it pass. This is the more valuable of the two findings: a green test that cannot fail is
worse than no test, because it is counted as coverage.

---

## Where the register argued with itself

Three collisions. All are recorded as business questions rather than resolved in code, and
each has a setting so a ruling is a configuration change.

| Collision | Reading applied | Recorded as |
|---|---|---|
| Item 12 says a rule failure is *Not Eligible*; item 20 lists the same conditions under *Automatically Reject* | Item 12 — the more specific instruction and the less final | **BD-32** · `dpc.merit.rule_failure_status` |
| Item 18 ties the tribal-area benefit to its CR year; item 21 forbids modifying the marking formula, and PPT §5.2 awards one flat bonus | Item 18 as a **linkage** requirement, item 21 as the **formula**. The data now records which year; the score still adds one bonus | **BD-36** |
| Item 12 requires the qualification validated against the rule; the rule holds a *level* and the chart holds a *degree* | **Reported, not applied.** Experience and Promotion Duration are enforced | **BD-39** · `dpc.merit.qualification_gate` |

---

## Pending Business Questions

**Eighteen recorded in `CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`.** Eight arose from
implementing Parts 1 and 2; ten are the register's own Part 5, documented and not built as
instructed.

| | Question | Blocks a published list? |
|---|---|---|
| **BD-39** | How is a qualification compared against the Recruitment Rule? | **Answer this first** — the only one that could produce a wrong *refusal* |
| BD-32 | Rule failure — *Not Eligible* or *Rejected*? | No |
| BD-33 | Rounding a fractional promotion vacancy | No |
| BD-34 | Confirming the 1 Jan – 30 Nov vacancy window | No |
| BD-35 | A December retirement creates no vacancy — is that intended? | No |
| BD-36 | Tribal-area bonus — per year or one flat bonus? | No, but it reorders lists |
| BD-37 | Which five years the Property Return window covers | No, but it decides rejections |
| BD-38 | Does item 13's rejection supersede the M6 hold? | No |
| BD-40 … BD-49 | The register's own Part 5 — the vacancy period, fractional rounding, the retirement and deputation Government Rules, cascade vacancies, departmental enquiry, court case, Lokayukt, sealed cover, the Class-I → Class-I CR grade | **BD-44, BD-48 and BD-49 would** |

**Twenty-one of the project's thirty-three open questions are applied by changing a
setting**, not by writing software.

---

## Suggestions & Improvements

**Six recorded in `SUGGESTIONS_AND_IMPROVEMENTS.md` as items 16 to 21. None was
implemented**, on the register's own instruction.

| # | Title | Priority |
|---|---|---|
| 16 | Give the Evaluation Chart a structured qualification, mapped to the master | **High** — the engineering half of BD-39 |
| 17 | Reconcile the Working Officer List against last year's, rather than trusting it | Medium — the dangerous direction of that error is currently silent |
| 18 | Let a department record reasons in bulk from the chart | Medium |
| 19 | Show the nine-figure vacancy breakdown on the DPC detail page, not only on the form | Medium — half a day, and the most often asked for |
| 20 | Reconcile the M6 hold with item 13's rejection | Low — waits on BD-38 |
| 21 | Record the Government Rule authorising a retirement or deputation vacancy | Low — waits on BD-42/BD-43 |

---

## Updated Documentation

| Document | What was added |
|---|---|
| `CGPSC_DPC_ARCHITECTURE_HANDBOOK.md` | **§13d** — the derived vacancy figure and its evidence table, the gates-before-formula composition and why the score survives an override, the missing record as a statement rather than an absence, and the three collisions |
| `CGPSC_DPC_DEVELOPER_HANDBOOK.md` | **§12c** — what was renamed or moved, what is new, five traps (months vs years, the gate composition, the document requirement, the carry-forward map, and the fixture window), and the configuration added |
| `CGPSC_DPC_USER_GUIDE.md` | **§9c** — the two things you no longer type, the nine figures, the new date columns, the missing-record workflow, the four statuses, and the two things the portal will tell you it could not decide |
| `CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` | **BD-32 to BD-49**, with BD-39 first and marked highest priority |
| `SUGGESTIONS_AND_IMPROVEMENTS.md` | **Items 16 to 21** |
| This report | `CHANGE_REGISTER_2026-08-27_REPORT.md` |

The four-document limit holds: this register added no new handbook, only sections to the
existing four, plus its own report alongside the two earlier ones.

---

## In one paragraph

Both engines are built and running. The vacancy figure is now **derived** — from the
Department Post Mapping, the uploaded Working Officer List and the Recruitment Rule —
rather than typed, and every calculation is kept as evidence with the inputs it came from.
The merit engine gained gates that run **before** the PPT's formula and never touch it: a
missing Property Return rejects, an unsatisfied recruitment rule refuses, and a mandatory
record that is missing but accounted for **holds** the promotion rather than refusing it —
which is the register's own CIR, and the change most visible to a department. The PPT's
marking, its qualifying score and Super Seat are untouched, as items 21 to 23 require.
**One question needs the Commission before a real promotion list is published: BD-39** —
the portal cannot tell that an `M.A.` satisfies a requirement of `Post-Graduate`, and
rather than refuse an officer on a ground that is not true, it reports the finding and
refuses nobody.
