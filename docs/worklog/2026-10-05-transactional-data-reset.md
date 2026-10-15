# The portal was cleared back to a blank workflow slate — 2026-10-05

**Asked:** delete all transactional data so the demo can be given again.
**Done:** `php artisan dpc:reset-transactional-data --force` on `dpc_dev`, after a
full `mysqldump`.
**Result:** **503 rows across 26 tables** and **6 stored files** cleared. Masters,
users, roles and department assignments untouched.

---

## 1 · What was cleared

The existing `ResetTransactionalDataCommand` already classifies every table in the
schema into exactly one of `TRANSACTIONAL` or `PRESERVED`, and refuses to run if a
table is in neither. **That guard passed**, so nothing in the schema was unaccounted
for — including the tables added since the command was last touched.

| Group | Tables cleared |
|---|---|
| Import staging | `import_rows` (70), `import_batches` (7) |
| Evaluation chart | `dpc_evaluation_cr_grades` (50), `dpc_evaluation_loyalty_flags` (50), `dpc_evaluation_property_flags` (50), `dpc_evaluation_rows` (20) |
| Proposal body | `dpc_seniority_entries` (20), `dpc_working_officers` (20), `dpc_vacancies` (8), `dpc_committee_members` (8), `dpc_proposal_revisions` (2) |
| Engine output | `dpc_calculation_results` (30), `dpc_calculation_runs` (3), `dpc_vacancy_calculations` (2) |
| Sitting and queue | `dpc_proceedings` (1), `dpc_schedules` (2), `dpc_allotments` (2) |
| Pre-DPC | `pre_dpc_source_qualifications` (4), `pre_dpc_promotion_sources` (2), `pre_dpc_proposals` (2) |
| Proposals | `dpc_proposals` (2) |
| Record of what happened | `workflow_transitions` (15), `activity_timelines` (54), `signatures` (4), `audits` (73) |
| Issued numbers | `dpc_id_sequences` (2) |

Truncated rather than deleted, so `AUTO_INCREMENT` resets and the next proposal
numbers from **0001** again — which is the point of the exercise for a demo.

Also removed: the 6 generated and uploaded files under `private/proceedings`,
`private/circulations`, `private/signatures`, `private/imports` and
`private/record-gaps`.

## 2 · What survived

Verified by direct count after the run:

| Table | Rows |
|---|---|
| `users` | 50 |
| `user_department_assignments` | 37 |
| `roles` | 7 |
| `departments` | 9 |
| `department_posts` | 9 |
| `qualifications` | 17 |
| `record_reasons` | 7 |
| `pay_scales` | 6 |
| `recruitment_rules` | 2 |
| `reservation_rosters` | 2 |
| `committee_configurations` | 2 |
| `cgpsc_chairmen` | 1 |

Every demo login still works and keeps its department assignment. Candidature is
derived from the Seniority Lists rather than stored, so the Promotion Candidate
accounts are simply back to having none — the correct fresh state.

## 3 · Backup

`storage/app/backups/dpc_dev-predemo-20261005-113820.sql` (397K), taken with
`--single-transaction --routines --triggers` immediately before the clear. Gitignored.
Restore with:

```
mysql -h127.0.0.1 -uroot -proot dpc_dev < storage/app/backups/dpc_dev-predemo-20261005-113820.sql
```

## 4 · Notes for the presenter

- **Sessions were truncated**, so every open browser session is logged out. Log in again.
- The cache was cleared with them; the first page load will be marginally slower.
- Nothing in the working tree changed. No code, no migrations, no documents.
- If a **pre-built** demo (DPCs already sitting in various states) is wanted instead
  of a blank slate, that is `php artisan demo:seed` — but it runs `migrate:fresh` and
  drops the whole database, masters included.

---

# Addendum — the reset was not enough on its own

After the reset, a review of what each department can actually demo turned up a
**blocker the reset could not have caught**, plus two open items. Full statement:
`docs/demo/DEMO_DEPARTMENT_READINESS.md`.

## 5 · Two recruitment rules survived, and they should not have

`CURRENT_DEMO_FLOW.md` §2 states the baseline as **"Recruitment Rules deliberately
empty"**, and §5.4's whole point to the audience is *"Nobody typed that rule. This is
why the master was empty in §5.3."* The master was **not** empty: two active rules sat
there, `GOVOD1394/F/2026` on Additional Director and `GR34/N/2026` on Director, Public
Instruction.

**Neither was typed by anyone.** Both were created by `ah.schooleducation@cg.gov.in` on
2026-09-07 — an approved Pre-DPC generates its recruitment rule automatically
(`PreDpcProposalService` → `RecruitmentRuleService`). They were the **previous demo's
output**, preserved because `recruitment_rules` is in the command's `PRESERVED` list.

Worse than the lost punchline: rule `#3` was on **Additional Director, Education** —
the exact post this demo runs. The Pre-DPC would have produced a **second active rule
on the same post**, both without an `effective_to`, with different accepted
qualifications (10th / B.Ed. against PG / PG+B.Ed. / Ph.D.).

**Deleted** on approval: rules 3 and 4, 2 promotion sources, 4 qualification links.
Backed up first to `storage/app/backups/recruitment-rules-before-delete-20261005-121956.sql`.

**Hard-deleted, deliberately.** `RecruitmentRuleService::nextVersion()` reads
`MAX(version)`, so a soft delete would have left the demo's new rule at **v2** where
§5.4 expects **v1**. Done in SQL rather than through the service, so the freshly
cleared `audits` and `activity_timelines` stayed at zero.

## 6 · Baseline as verified

| Check | Value |
|---|---|
| Transactional rows / files | **0 / 0** |
| Accounts | 50, all `Demo@2026` |
| Departments / posts / GOs | 9 / 9 / 9 |
| Recruitment rules | **0** |
| `audits` / `activity_timelines` | **0 / 0** |
| Next rule version, Additional Director | **v1** |

Matches `CURRENT_DEMO_FLOW.md` §2 exactly.

## 7 · Left open, not acted on

- **BD-22 (proposed)** — roster-point attribution. `ReservationRoster` is read by
  nothing but its own admin CRUD and a dashboard count; the vacancy's category comes
  from the officer who vacated the chair, walked in serial order.
  `VacancyEngine.php:264` records this as a deliberate deferral, but it never reached
  `CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`. **Not yet written up** — awaiting the go-ahead.
- **`recruitment_rules` classification** in `ResetTransactionalDataCommand`. A rule is
  both master data and workflow output; the command treats it only as the first, so
  every reset leaves the last demo's rules behind. Moving it to `TRANSACTIONAL` or
  adding a flag is a **change-control decision** and was not taken.

## 8 · Higher Education is a shell

AO and AH accounts exist; **0 posts, 0 GOs, 0 recruitment rules**. Signing in as
`ao.he@cg.gov.in` during the demo produces no error — just an empty post list, which
reads worse. Both registered departments are Education departments; the other seven
are `unregistered` and entirely empty.

---

# Addendum 2 — the account list was pruned to one department

**Asked:** keep one department's users and remove the rest; the demonstration will be
given on that department alone.

**Done:** 41 of 50 accounts deleted. **Nine remain**, which is exactly the set
`CURRENT_DEMO_FLOW.md` §3 calls *"the accounts the walkthrough is driven from"*.

## 9 · What went, and the one that was not obvious

| Deleted | Count |
|---|---|
| Higher Education AO + AH | 2 |
| Promotion Candidates | 33 |
| Role-less accounts | 6 |

**The 33 candidates were the non-obvious ones.** The instinct was to keep the ten the
seniority CSV names — `SE/BEO/1001` … `SE/BEO/1010`. §5.9 rules that out: committing
`demo-seniority-list.csv` **provisions all ten accounts itself** — *"All ten are new"*,
*"ten accounts created"*, and the one-time passwords appear in *"Hand these over to the
officers named."* Keeping them would have made the import match existing accounts
instead of creating them, and the panel the presenter is told to read would have
reported nothing. **Same class of fault as the surviving recruitment rules in Addendum 1.**

Safe because every FK onto `users` is `SET NULL` or `CASCADE`, none `RESTRICT`; masters
were untouched and `cgpsc_chairmen.user_id` still resolves. Backed up to
`storage/app/backups/users-before-prune-20261005-122852.sql`.

## 10 · `demo:credentials` is now a trap — do not run it

`DemoCredentialsCommand::createMissingDemoUsers()` finds the `HE` department and
**recreates `ao.he@cg.gov.in` and `ah.he@cg.gov.in`**, then marks Higher Education
`registered`. `CURRENT_DEMO_FLOW.md` §3 instructs the presenter to *"Run it before the
demonstration"* — following that now undoes the prune.

So the nine passwords were set directly instead, and
`docs/demo/DEMO_LOGIN_CREDENTIALS.md` was rewritten by hand. The generated
`DEMO_LOGIN_CREDENTIALS.pdf` was **removed** — it listed fifty accounts, forty-one of
which no longer exist.

**Not fixed:** the command itself, and the instruction in §3 that points at it. Both
are change-control items, recorded as §7 item 3 of
`docs/demo/DEMO_DEPARTMENT_READINESS.md`.

## 11 · Final state

| | |
|---|---|
| Accounts | **9**, all `Demo@2026`, all active |
| Transactional rows / files | 0 / 0 |
| Recruitment rules | 0 |
| `audits` / `activity_timelines` | 0 / 0 |
| Departments / posts / GOs | 9 / 9 / 9 (masters untouched) |
| CGPSC Chairman | still linked to user 2 |

---

# Addendum 3 — `demo:credentials` no longer widens the demonstration

**Asked:** make the command such that only one department's demonstration is possible.

**Found first:** this was not a new decision to take. `DemoAccountSeeder` had **already
settled it** — *"The one demo department … One department, deliberately. Every DPC,
every master and every officer in the walkthrough belongs to it, so the presenter never
has to explain why the record on screen is in a different department from the one they
just showed."* Its `DEPARTMENTS` array holds School Education alone.

`DemoCredentialsCommand::createMissingDemoUsers()` then ran **after** the seeder and put
a second department back — inventing `ao.he@cg.gov.in` and `ah.he@cg.gov.in` and
force-flipping Higher Education to `registered`. So the fix was not a design change but
**removing code that contradicted a decision already recorded**.

## 12 · What changed

| File | Change |
|---|---|
| `DemoCredentialsCommand` | `createMissingDemoUsers()` **deleted**, with its `$created` plumbing through `handle()` and `render()` and the *"Accounts created for this walkthrough"* section of the sheet. Four imports and the constructor's `DepartmentAssignmentService` dependency went with it |
| `DemoCredentialsCommand` | **`reportDepartments()` added** — names the departments holding accounts and warns when there is more than one. A report, never a repair: a command that publishes passwords must not delete accounts behind the operator's back |
| `DemoCredentialsCommand` | The Promotion Candidate empty-note now explains that **zero is correct** and that §5.9 provisions them, rather than *"No accounts hold this role"* — which invited someone to go and seed some |
| `DemoAccountSeeder` | Docblock said *"an Authorized Officer and an Administrative Head for each of the three demo departments"*. Stale — corrected to the one |
| `departments` | `HE` returned to `unregistered`. The old command had force-flipped it; `registered_at` was never set, which is the proof it never went through real registration |
| `CURRENT_DEMO_FLOW.md` | §2 baseline 50 → 9 accounts; *"The other 41 accounts"* replaced; risk row 13 corrected from **"Do not run it"** to safe-to-run |

**Cross-department isolation** was the stated reason for the second department. It is
pinned by the test suite, which is where a security property belongs; it does not need
a standing account to prove it.

## 13 · Quality gate

| Check | Result |
|---|---|
| `pint --test` | **passed** (whole project) |
| `phpstan analyse` | **No errors** |
| `php artisan test` | 1417 passed, 2 skipped, **43 failed** — see below |
| `composer audit` | **5 advisories affecting 4 packages** — see below |
| `demo:credentials` end-to-end | 9 passwords reset · HE accounts created: **0** · users still **9** · reports *"One department: School Education Department."* |

### The 43 failures are not from this change

All 43 are in `Tests\Feature\Cgpsc\AllotmentTest` and `AllotmentDeadlineTest`. Neither
file — nor anything in `tests/Feature/Cgpsc/` — references `DemoCredentials`,
`DemoAccountSeeder` or `demo:credentials`; the grep returns nothing.

The allotment area is **mid-refactor in the uncommitted tree**: 8 modified source files
under `app/Domain/Cgpsc/`, 4 modified test files, and **four untracked new directories**
(`Concerns/`, `Events/`, `Listeners/`, `ValueObjects/`) plus two untracked new test files
— `AllotmentDeadlineTest.php` is itself untracked, i.e. a test written against behaviour
not yet finished. That is where these failures belong.

**This was not verifiable by stashing**: `DemoCredentialsCommand.php` is untracked, so
`git stash push` on it fails outright. The attribution above rests on the dependency
grep and the state of the Cgpsc tree, not on a before/after run.

### `composer audit` is no longer clean

`ARCHITECT_REVIEW.md` records *"No advisories (local cache; host offline)"* on
2026-09-07 — an audit against a cold cache on an offline host, which could not have
found anything. With network, there are **five**:

| Package | Severity | Advisory |
|---|---|---|
| `maatwebsite/excel` | **high** | CVE-2026-84374 — writes exports outside the configured filesystem disk when given a crafted path |
| `league/commonmark` | **high** | Quadratic-time denial of service in GitHub Flavored Markdown |
| `league/commonmark` | medium | `DisallowedRawHtml` bypassed when a disallowed tag name ends the input |
| `laravel/framework` | low | CVE-2026-102279 — XSS in debug page information |
| `league/flysystem` | low | CVE-2026-102601 — control-character path normalisation |

**Unrelated to this change and not acted on.** `maatwebsite/excel` deserves attention
first: this portal's whole import path runs through it, and the advisory is about
writing outside the configured disk.

---

# Addendum 4 — the 43 failing tests were a time bomb, and it has been defused

**Asked:** make the whole suite pass.

**Result: 1460 passed, 2 skipped, 0 failed.** `pint` passed, `phpstan` No errors.

## 14 · One cause, forty-three symptoms

Every failure carried the same session error:

```
The completion date cannot be in the past.
```

`AllotmentTest` and `AllotmentDeadlineTest` both allot with `complete_by` hard-coded to
**30-09-2026**, and `AllotDpcRequest` validates it `after_or_equal:today`. The tests were
written in September, when that date was comfortably ahead. **Today is 05-10-2026** — five
days past it — so the fixture stopped being an allotment and became a validation error,
and every test that allots anything fell over with it.

Nothing was wrong with the allotment code. It was never a refactor-in-progress, which is
what Addendum 3 inferred from the uncommitted Cgpsc tree; that inference was wrong, and
the dependency grep it rested on only established that the failures were not from
`demo:credentials`.

## 15 · The fix

`Carbon::setTestNow('2026-07-01 09:00:00')` in `setUp()` of both classes, cleared in
`tearDown()`. That instant is the one `ProceedingLifecycleTest` already pins to, and it
makes the narrative hold: proposal dated 25-06-2026, allotment made now, deadline three
months out at 30-09-2026, sweep the day after at 01-10-2026.

**Relative dates were the alternative and were rejected.** The assertions name the dates
they expect — `30-09-2026`, `30/09/2026` on the report — and a test that computes its
expected value from the same expression as the code under test cannot catch that code
being wrong.

`tearDown()` clears the clock, because a frozen `now()` left behind would silently
re-date every test running after these in the same process.

## 16 · Checked for the same bug elsewhere

`after_or_equal:today` and `after:today` appear in exactly **one** place in `app/` —
`AllotDpcRequest::complete_by`, the rule above. Every other today-relative rule
(`before:today`, `before_or_equal:today` on dates of birth, Government Order dates and
`effective_from`) points the other way: a hard-coded past date stays past as time moves
forward, so those cannot rot.

`SchedulingTest`, `ReschedulingTest` and `SittingAnnouncementTest` also hard-code
2026-07-xx dates and do not freeze the clock, but `ScheduleDpcRequest` validates
`dpc_date` as `['required', 'date']` with no future constraint, and the sitting-versus-
deadline check compares against the allotment's stored `complete_by` rather than against
today. They are not time bombs.

## 17 · Gate

| Check | Result |
|---|---|
| `pint --test` | **passed** |
| `phpstan analyse` | **No errors** |
| `php artisan test` | **1460 passed**, 2 skipped, **0 failed** (4886 assertions) |
| `composer audit` | 5 advisories, 4 packages — unchanged, see Addendum 3 §13 |

---

# Addendum 5 — the same time bomb was in the demo script

Fixing the tests exposed that `CURRENT_DEMO_FLOW.md` carried the identical fault, and
there it was a **demo blocker**: §5.14 tells the presenter to type a Completion Deadline
of **30-09-2026**, which `AllotDpcRequest` validates `after_or_equal:today`. On 05-10-2026
the walkthrough would have stopped dead at the allotment screen with *"The completion
date cannot be in the past"* — in front of the audience.

## 18 · Dates rolled forward

| Step | Was | Now |
|---|---|---|
| §5.14 Completion Deadline | 30-09-2026 | **31-12-2026** |
| §5.15 refused sitting (after the deadline) | 20-10-2026 | **15-01-2027** |
| §5.15 accepted sitting | 25-09-2026 | **22-12-2026** |

Same in `sample-data/reference/REFERENCE-allotment-and-schedule.csv`, and in the §12
checklist. The §12 prerequisite line also said *"34 users"*; corrected to **9**.

A note was added at §5.15 stating that these three are the only values in the script that
expire, and the shape to preserve when rolling them: deadline ahead of today, refused date
after the deadline, accepted date on or before it.

**A future sitting date does not block §5.17.** `dpc.proceeding.allow_open_before_scheduled_time`
defaults to **true** (`DPC_ALLOW_EARLY_PROCEEDING`), and `ProceedingLifecycleTest` pins
that opening early is permitted, so the member can open the sitting on the day of the
demonstration.

## 19 · Readiness checks run

| Check | Result |
|---|---|
| `http://127.0.0.1:8001/login` | **HTTP 200** |
| Redis cache read/write | **ok** — `put`/`get` round-trips (an earlier "FAIL" was a bad strict comparison in the probe, not Redis) |
| Cache / session / queue driver | `redis`, predis, 127.0.0.1:6379 |
| Additional Director sanctioned strength | **10** — the figure §5.8 derives the vacancy from |
| Block Education Officer | Class II, sanctioned 4 |
| Suite | 1460 passed, 2 skipped, 0 failed |

## 20 · Still open, unchanged

- **BD-22** roster-point attribution — not written up.
- **`recruitment_rules` in `PRESERVED`** — every reset still leaves generated rules behind.
- **`composer audit`** — 5 advisories, `maatwebsite/excel` high and on the import path.

---

# Addendum 6 — a self-contained demo kit

**Asked:** how much vacancy is set from the Administrator side, and one folder holding the
credentials, every step, and every CSV with where it uploads.

**Built:** `docs/demo-kit/` — `README.md`, `uploads/`, `typed-values/`. Nothing outside the
folder needs to be opened to give the demonstration.

## 21 · The vacancy answer

**The Administrator sets exactly one number: Sanctioned Strength = 10**, on the Department
Post Mapping for Additional Director, Education. It is the only vacancy input a human
enters; everything else is derived from the chart uploaded at S16.

```
Base        = Sanctioned 10 − Working 7                      = 3
+ Deputation  (row 7, away until 31-07-2027, frees his chair) = 1
+ Retirement  (earliest on the chart is 30-04-2028)           = 0
TOTAL VACANCY                                                 = 4
× promotion share 100%  →  Promotion Vacancy                  = 4
```

Categories **UR · ST · OBC · SC** — three from the `vacant` rows 8/9/10, one from the
deputed officer's own category. The retirement window (01-01-2026 to 30-11-2026) comes from
the **DPC Year**, not from today, so the chart is reproducible.

## 22 · Two errors in the reference data, found while assembling the kit

| Found | Fix |
|---|---|
| `REFERENCE-committee-members.csv` gave Member 3 the login `ds.gad@cg.gov.in` — **an account deleted in the prune**. The presenter would have looked for it in a dropdown that no longer offers it | `SaveDpcCommitteeRequest` has `members.*.user_id` nullable, `required_without` on the name — a committee member needs no account. The kit's copy now says **"NO ACCOUNT — use *Create New Member* and type the name"** |
| `REFERENCE-department-posts.csv` recorded Director, Public Instruction at sanctioned strength **1**; the database says **10** | Corrected to 10. Not demo-critical — the walkthrough runs on Additional Director — but a kit that disagrees with the screen is worse than no kit |

Both were corrected **in the kit's copies only**; `docs/demo/sample-data/` is left as it
was, so nothing already referenced elsewhere shifted under it.

## 23 · Verified

- Every login named in the kit exists in `dpc_dev`; `ds.gad@cg.gov.in` is the only one
  referenced and it is explicitly marked as having no account.
- Upload order is stated and enforced by the system: S18 is **projected from** S17, so the
  Seniority List must commit first.
- The three expiring dates are isolated into their own section with the rule for rolling
  them, so the fault that stopped this walkthrough once is visible rather than buried.

## 24 · Two files removed from the kit — they implied work that does not exist

`REFERENCE-cgpsc-chairman.csv` and `REFERENCE-department-users.csv` were copied into
`typed-values/` and should not have been. Neither is uploaded and neither is typed:

- The **CGPSC Chairman** is seeded — `cgpsc_chairmen` id 1, Dr. Ajay Kumar Shrivastava,
  active since 02-09-2026, linked to `chairman@cgpsc.gov.in`.
- The **department users** file lists the same nine accounts the kit's §2 already gives.

Sitting in a folder whose other six files are all things you type, they read as work.
Removed. The Chairman is now a line in the pre-flight state table with the reason it
matters: `DpcCommitteeService` refuses to compose a committee without a serving Chairman,
so an empty record stops the walkthrough at §6 with a message about a master nobody was
demonstrating. `typed-values/` now holds **only** files the presenter acts on.

## 25 · `typed-values/` folded into the README and deleted

The folder held a second copy of values the walkthrough already carried inline, and the
two copies had **already drifted twice in one day** — the rolled-forward dates had to be
edited in both places, and the dead `ds.gad@cg.gov.in` login existed only in the CSV.
Removed; the README is now the single source.

Everything was inlined first, not dropped: the Pre-DPC field list (§4), the committee
roster (§6), the allotment and schedule values (§12–§13), the nine-post ladder with pay
scales and sanctioned strengths (§3), the registration form (§2) and the self-inspection
identifiers (§15).

**One conflict surfaced while inlining.** `REFERENCE-department-registration.csv`
registers **Higher Education** with two new accounts — `ao.highereducation@cg.gov.in` and
`ah.highereducation@cg.gov.in`. Running §2 therefore undoes the one-department decision.
The step is optional and nothing later depends on it, so it was kept with the consequence
stated in a warning rather than removed: showing Department Registration at all means
registering a department that is not School Education, and there is no way round that.

Kit is now `README.md` + `uploads/` (7 files).
