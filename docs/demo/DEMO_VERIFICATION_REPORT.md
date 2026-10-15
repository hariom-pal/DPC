# Demo Verification Report — e-DPC Portal (CGPSC)

> ## Superseded on 2026-09-07 — read this first
>
> **This is a record of a pass made on 2026-09-06, and it is kept as evidence of
> that pass rather than as a statement of current fact.** The sample data was
> regenerated on 2026-09-07 and the figures below moved with it:
>
> | This report says | Current |
> |---|---|
> | 4 working-officer rows, sanctioned 4 | **10 rows, sanctioned 10** |
> | Total Vacancy 3 | **4** |
> | 60 CR · 60 loyalty · 60 property | **50 · 50 · 50** |
> | Main 3 · Waiting 3 | **Main 4 · Waiting 3 · Not eligible 3** |
> | Class III → Class II | **Class II → Class I** |
> | `CG2000xx` candidate logins | **`SE/BEO/1001`–`SE/BEO/1010`** |
>
> For the current figures see **`SAMPLE_DATA_GUIDE.md`** and
> **`CURRENT_DEMO_FLOW.md`**; both are now asserted by
> `tests/Feature/Demo/SampleDataTest.php`, so they cannot drift again without the
> build going red. Everything below is left exactly as it was written.

---

**Verified 2026-09-06 against the running application.** Every figure, message and
database row quoted here was produced by an instrumented end-to-end pass — not read
from a specification. Method: HTTP requests against `http://127.0.0.1:8001` as each
real actor, with `SELECT` evidence captured between steps. The database was returned
to its clean baseline afterwards.

**Verdict: GO.** One documentation defect was found and corrected; no code defect
was found. Remaining risks are listed at the end.

---

# 1 · Recruitment Rule Generation

## When exactly it is created

**At the instant the Administrative Head approves the Pre-DPC — inside the same
database transaction as the approval, the signature and the supersession.** Not on
draft, not on submit, and never by typing.

`PreDpcProposalService::approve()` runs, in one `DB::transaction`:

1. `assertSignatureAvailable()` — *before* the transaction, so a deployment fault
   does not hold a row lock
2. `lock()` — re-reads the row under `lockForUpdate()`
3. `engine->assertAllowed('pre_dpc','approve', …)`
4. `signatures->sign(...)`
5. `supersedeIncumbent()`
6. status → `approved`, `approved_by`, `approved_at`, `signature_id`, `active_flag = 1`
7. **`projectToMasters()`** ← the rule is written here
8. `engine->record(...)` — ledger entry

`projectToMasters()` maps each `PreDpcPromotionSource` to a feeder row and calls
`$this->rules->create([...])`. The post is fetched by **id** —
`DepartmentPost::findOrFail($proposal->promotion_to_post_id)` — so nothing is matched
by name.

## Database evidence

**Immediately after CREATE and SUBMIT — no rule exists:**

```
predpc_status   rules
submitted       0
```

**Immediately after AH APPROVAL — the rule, its source and its qualification links
all appear together:**

```
predpc_status   rules   sources   quals   signatures
approved        1       1         3       1
```

**The projected rule in full:**

```
                       id: 1
                  post_id: 5          -- Block Education Officer
                  version: 1
  government_order_number: F-2-14/2026/20-Three
   direct_recruitment_pct: 0.00
promotion_recruitment_pct: 100.00
           effective_from: 2026-01-01
                   status: active
```

**Its feeder source, carried from the Pre-DPC:**

```
id  rule_id  feeder                      promotion_%  min_exp  unit
1   1        Head Master, Middle School   100.00       5        years
```

Three `rr_source_qualifications` rows — Post-Graduate, Post-Graduate with B.Ed.,
Ph.D. — exactly the three ticked on the Pre-DPC.

## Screen evidence

| Moment | `/admin/recruitment-rules` shows |
|---|---|
| Before approval | the list, **empty** |
| After approval | `Block Education Officer` · `v1` · `F-2-14/2026/20-Three` · `100.00` · `Active` |

**Verified.** The rule is a *consequence* of approval, never an input.

---

# 2 · Vacancy Engine

`VacancyEngine::calculate()` — pure: it reads models it is handed and returns a
`VacancyBreakdown`. It writes nothing and holds no clock.

## Every input

| Input | Source | Why it is not the officer's to type |
|---|---|---|
| **Sanctioned Strength** | `department_posts.sanctioned_strength` | It is what a Government Order says; the mapping is where the portal keeps that |
| **Working Strength** | count of `dpc_working_officers` with `status = working` | The Excel import writes those rows, so any stored total is wrong the moment a file is committed |
| **Vacant chairs** | rows with `status = vacant` | ditto |
| **Source of each chair** | `dpc_working_officers.source` — `promotion` / `direct` / `deputation` | Each occupied chair declares which quota it consumes |
| **Retirement dates** | each officer's `date_of_retirement` | Two officers retiring in the window is two vacancies; a typed total cannot be checked against anybody |
| **Deputation dates** | `deputation_from` / `deputation_to` | ditto |
| **Promotion %** | `recruitment_rules.promotion_recruitment_pct` | Only from the Recruitment Rule |
| **Vacancy period** | derived from the **DPC Year**, `dpc.vacancy.period` = 01-01 → 30-11 | Derived from the year, **not from today**, so recalculating in December gives March's answer |

## Every calculation

```
promotion chairs   = Sanctioned × promotion %              (floor)
direct chairs      = Sanctioned − promotion chairs         (remainder, never a second multiplication)
chairs held        = officers still substantively holding a chair, by source
                     (deputation holds NEITHER quota)
promotion vacancy  = promotion chairs − promotion chairs held
direct vacancy     = direct chairs − direct chairs held
TOTAL VACANCY      = Sanctioned − (promotion held + direct held)
Base Vacancy       = count of vacant chairs               (reported, not summed)
Retirement Vacancy = officers retiring inside the period
Deputation Vacancy = officers on deputation inside the period
```

**Why the total does not use the Recruitment Rule.** It is the number of chairs
nobody substantively holds — a fact about the chart alone. Only the *split* needs the
rule, so a proposal awaiting one can still show its vacancy. The two agree by
construction: `(promotion chairs − held) + (direct chairs − held) = Sanctioned − held`,
because the chairs sum to the strength and deputation holds neither.

**Direct takes the remainder**, never its own `Sanctioned × direct %` — at 33.33 /
66.67 both multiplications floor and a chair goes missing.

## Every output — the recorded evidence

`dpc_vacancy_calculations` is append-only. The row from this run:

```
   sanctioned_from_post_id: 5
       sanctioned_strength: 4
       recruitment_rule_id: 1
      promotion_percentage: 100.00
               period_from: 2026-01-01
                 period_to: 2026-11-30
          working_strength: 3
              base_vacancy: 1
        retirement_vacancy: 1
        deputation_vacancy: 0
             total_vacancy: 3
   promotion_vacancy_exact: 4.00
         promotion_vacancy: 3
direct_recruitment_vacancy: 0
             rounding_rule: floor
              contributors: [{"sn":1,"name":"Shri Ramkumar Verma",
                              "detail":"Retires on 30-06-2026","ground":"retirement"}]
                    inputs: {"period_to":"2026-11-30","period_from":"2026-01-01",
                             "direct_chairs":0,"rounding_rule":"floor",
                             "promotion_chairs":4,"working_strength":3,
                             "direct_chairs_held":0,"recruitment_rule_id":1,
                             "sanctioned_strength":4,"promotion_percentage":"100.00",
                             "promotion_chairs_held":1,"deputation_chairs_held":1,
                             "sanctioned_from_post_id":5}
             calculated_by: 8
```

**Why it is persisted at all.** It used to be derived on read. It is now a function
of five things that live elsewhere and change underneath it — the sanctioned strength
on the mapping, the promotion share on the rule, and each officer's retirement and
deputation dates — so it cannot be recomputed later and guaranteed to give the answer
the Commission acted on. **The zone remains derived.**

## Derived vacancy breakdown

Not typed. `Expected from promotion · UR · 1` · `Expected from deputation · OBC · 1`
· `Clear vacancy · SC · 1` — **total 3**. A vacant chair becomes a Clear vacancy in
its own category, a retiring officer a Retirement vacancy in theirs, an officer on
deputation a Deputation vacancy in theirs.

## Zone of Consideration

Asked of `ZoneOfConsiderationService` — never computed here (AD-19: one rule, one
mechanism).

- **Class III → Class II** (the demonstration pair): the deck states no formula, so
  the zone is **uncapped** — *"every officer meeting the experience qualification is
  considered"*. The exact-count gate on the Seniority List does not apply.
- **Class I → Class I**: `(V × 2) + 4`, with `single_vacancy_size = 5` for one
  vacancy. **Independently verified on a Class-I pair during this session**: 3
  vacancies → the Seniority List screen demanded *"exactly 10"*.

**Verified.** Every figure traceable to a record somebody else owns.

---

# 3 · Working Officers

## Every field

| # | Column | Required | Meaning | Effect on the vacancy |
|---|---|---|---|---|
| 1 | `SN` | ✔ | Serial number on the chart | none |
| 2 | `STATUS` | — (`working` if absent) | `working` or `vacant` — **the chart lists chairs, not only people** | `vacant` → **Base Vacancy +1**, and a Clear vacancy in its category |
| 3 | `SOURCE` | for `working` | `promotion` / `direct` / `deputation` — which quota this chair consumes | decides whether the chair is deducted from promotion chairs, direct chairs, or **neither** |
| 4 | `EMPLOYEE NO` | for `working` | Identity | none |
| 5 | `NAME` | for `working` | Identity | none |
| 6 | `DOB` | for `working` | `DD-MM-YYYY` | none |
| 7 | `QUAL` | for `working` | Qualification master name | none |
| 8 | `PROMOTION TO POST` | ✔ | The post being filled | **snapshot text only** — see the risk register |
| 9 | `PROMOTION FROM POST` | ✔ | The feeder post | snapshot text only |
| 10 | `CAT` | ✔ | `UR`/`SC`/`ST`/`OBC`/`EWS` — **required on a vacant row too** | decides the **category** of the vacancy this chair produces |
| 11 | `DATE OF JOINING IN SERVICE` | for `working` | Service record | none |
| 12 | `DATE OF POSTING ON PROMOTION TO POST` | for `working` | When they took this chair | none |
| 13 | `DATE OF POSTING ON PROMOTION FROM POST` | for `working` | Feeder posting date | none |
| 14 | `ROSTER POSITION` | — | Reservation roster point | reservation reporting |
| 15 | `DEPUTATION DATE` | — | Deputation start | inside the period → **Deputation Vacancy +1**; the chair consumes no quota |
| 16 | `DEPUTATION TO` | — | Deputation end | a deputation that **ended before** the period leaves the chair held |
| 17 | `DATE OF RETIREMENT` | — | Retirement date | inside the period → **Retirement Vacancy +1**, and the chair is no longer held |

## How the vacancy changes — the four rows in the demonstration file

| Row | Status / Source | Category | Dates | Effect |
|---|---|---|---|---|
| 1 | working / promotion | UR | retires **30-06-2026** (inside 01-01→30-11) | **not** counted as held → Retirement Vacancy 1, `Expected from promotion · UR · 1` |
| 2 | working / deputation | OBC | — | consumes **no quota** → `Expected from deputation · OBC · 1` |
| 3 | working / promotion | ST | deputation 01-03-2023 → 31-12-2025, **ended before** the period; retires 2027 | **counted as held** — the only held promotion chair |
| 4 | **vacant** | SC | — | Base Vacancy 1 → `Clear vacancy · SC · 1` |

`Sanctioned 4 − held 1 = TOTAL VACANCY 3.`

**Demonstrated live during this session:** the same 4-row file uploaded against a
post with **sanctioned strength 10** was refused at the submission gate —
*"The chart does not account for every sanctioned post. Sanctioned 10, 4 row(s) on
the chart."* The chart must have one row per chair.

**Verified.**

---

# 4 · Evaluation Chart

## Every column

| Column group | Count | Required | Meaning |
|---|---|---|---|
| `SN`, `EMPLOYEE NO`, `NAME OF US`, `DOB`, `QUAL`, `DOP`, `CAT`, `PROMOTION TO POST`, `PROMOTION FROM POST` | 9 | ✔ | Identity — **projected from the Seniority List**, not typed here |
| `LOYALTY <year>` | 6 | ✔ | `Y`/`N` — is a loyalty certificate on record for that year |
| `IMMOVABLE PROPERTY <year>` | 6 | ✔ | `Y`/`N` — was the property return filed |
| `CR <year>` | 6 | ✔ (blank allowed) | The Hindi CR grade for that year |
| `TRIBAL AREA <year>` | 6 | — | `Y`/`N` — tribal-area service that year |
| `TRIBAL AREA SERVICE YEARS` | 1 | — | Integer |
| `NOTE` | 1 | — | A configured note code |

**35 columns; verified as recognised by the importer** — a programmatic check
against `ImportTemplate::columnMap()` reports **no unrecognised columns**. Per-year
columns are matched **by pattern**, not by fixed header, so a different span of years
works.

## Where the CR data comes from

**The department, and nowhere else.** There is no HRMS feed in this build
(`FEATURE_HRMS=false`). CR grades are the department's own record of each officer's
Confidential Reports, supplied on the S18 chart.

**The officers on the chart are not the department's to choose.** They are
**projected from the Seniority List** — `EvaluationCandidateService::project()` copies
all ten identity fields across on the S17 commit. The Authorized Officer supplies
**only** what evaluation needs: CR grades, loyalty, property, tribal service and a
note. They **cannot add, remove or rename an officer.**

> This closed a real defect: S16, S17 and S18 each used to carry the same ten
> identity fields, so an officer could be `US4` with one date of birth on the
> seniority list and another on the chart — and the scoring engine used the chart's.
> The copy that was least verified was the one that counted.

**Identity is still *copied* onto `dpc_evaluation_rows`, not read through a
relation** — every officer table here is a point-in-time snapshot, so a concluded
proposal cannot change because a record was edited later.

**Evidence:** the S17 commit produced **10 evaluation rows** with no chart upload;
the S18 commit then wrote **60 CR grades · 60 loyalty · 60 property** (10 officers ×
6 years).

## How the five-year CR is evaluated

- The chart carries **six** year columns (`evaluation.max_cr_years = 6`) — a data-entry
  bound.
- Scoring uses the **five most recent available** CR years
  (`scoring.scoring_years = 5`) — a *calculation* rule, deliberately a different
  setting.
- Years are selected most-recent-first, so the sixth column is carried but not scored.

**Evidence** — SN 1's recorded breakdown:

```json
{"total":19,"base_total":19,
 "years_used":[2026,2025,2024,2023,2022],
 "marks_by_year":{"2022":4,"2023":4,"2024":3,"2025":4,"2026":4},
 "incomplete_window":false,"tribal_area_bonus":0,
 "years_without_grade":[],"scoring_years_required":5}
```

`years_used` names the exact five. The 2021 column was supplied and correctly ignored.

## Hindi grading

`scoring.cr_grade_marks` — the grades are stored and displayed in Devanagari because
that is how the Commission records them:

| Grade | Marks |
|---|---|
| **क+** | 4 |
| **क** | 3 |
| **ख** | 2 |
| **ग** | 1 |
| **घ** | 0 |

Entry is bounded by `evaluation.cr_grades = ['क+','क','ख','ग','घ']`. **UTF-8 is
mandatory** on the CSV — an ANSI save from Excel renders them as `????`.

## Disqualification rules

| Gate | Rule | Applies to |
|---|---|---|
| **Disqualifying grade** | any **`घ`** in the five-year window → **not eligible, whatever the total** | every class pair |
| **Last-year grade** | most recent CR must not be **`ग`** | **Class-I → Class-I only** (`gates.last_year_forbidden_grade['I-I']`) |
| **Qualifying score** | below the threshold → not eligible | thresholds below |
| **Reserved relaxation** | **not applied** — awaits business decision Q5/Q6. Affected officers are *flagged*, never silently decided | — |

**Qualifying scores** — `I-I` 15 · `II-I` 13 · `II-II` 11 · **`default` 10** (the
demonstration's Class III → Class II pair).

**Super Seat (SS)** — all five window years at **क+** → notional (काल्पनिक)
seniority. Keyed on the *grade*, not on a total of 20, so changing the marks table by
notification cannot silently break the rule.

**Evidence — the hard gate beating a passing score.** SN 5 scored **13** against a
requirement of **10** and is still refused:

```json
score_breakdown: {"total":13,"base_total":12,
  "years_used":[2026,2025,2024,2023,2022],
  "marks_by_year":{"2022":3,"2023":3,"2024":0,"2025":3,"2026":3},
  "tribal_area_bonus":1}
verdict: {"remark":"NE","eligible":false,"achieved_score":13,"required_score":10,
  "failure_reasons":["CR grade घ recorded in 2024 — an officer with this grade in
                      the scoring window is not eligible."]}
```

The 2024 grade is `घ` → 0 marks *and* a hard gate. **Explain Result** shows exactly
this on screen.

## Final score calculation

```
score = Σ marks(last five available CR years)
      + tribal_area_bonus (1, once, if any tribal-area service is recorded)
```

Then, in order: **disqualifying-grade gate** → **last-year gate** (I-I only) →
**qualifying-score test** → **reserved-relaxation flag** (recorded, not applied).

**Worked examples from this run:**

| SN | Window marks | Base | Tribal | Total | Required | Outcome |
|---|---|---|---|---|---|---|
| 1 | 4+4+3+4+4 | 19 | 0 | **19** | 10 | Eligible → Main · UR · rank 1 |
| 5 | 3+3+**0**+3+3 | 12 | +1 | **13** | 10 | **Not Eligible** — `घ` in 2024 |
| 9 | 2+1+1+1+1 | 6 | +1 | **7** | 10 | Not Eligible — score |

**Run-level provenance.** `dpc_calculation_runs` pins the entire scoring
configuration with the run:

```json
{"zone":{"rules":{"I-I":{"addend":4,"multiplier":2,"single_vacancy_size":5}}},
 "gates":{"disqualifying_grade":"घ","last_year_forbidden_grade":{"I-I":"ग"}},
 "super_seat":{"required_grade":"क+"},"rule_version":"2026.1","scoring_years":5,
 "cr_grade_marks":{"क":3,"ख":2,"ग":1,"घ":0,"क+":4},
 "qualifying_scores":{"I-I":15,"II-I":13,"II-II":11,"default":10},
 "tribal_area_bonus":1,"reserved_relaxation":{"floor":10,"enabled":false}}
```

plus `rule_version 2026.1`, `calculation_engine_version 1.0.0`, a
`proposal_version` hash, `officers_considered 10`, `eligible_count 6`,
`main_list_count 3`. **A concluded DPC can be re-explained years later under the
rules it actually ran under.**

**Verified.**

---

# 5 · Committee Validation

`CommitteeComplianceService::findings()` returns *all* problems at once, so an
officer fixes three faults in one round trip rather than three.

## Every business rule

| # | Rule | Enforcement |
|---|---|---|
| 1 | **Chairman + minimum 3, maximum 6 members** — bounds count members *besides* the Chairman | `too_few` / `too_many` findings. A registered Committee Configuration overrides, converted by subtracting the mandatory Chairman so one field never means two things |
| 2 | **The CGPSC Chairman sits on every committee**, seated by the system | `chairman_missing` if absent, `chairman_duplicated` if two |
| 3 | **At most one DPC Chairman.** Absence is *not* a finding — the CGPSC Chairman designates later | `dpc_chairman_duplicated` |
| 4 | **One reserved social category per member** | `social_category_conflict`, naming the member and the pair |
| 5 | **Required attributes covered by the committee as a whole, with the fewest members** | `uncovered()` asks *"does anybody represent ST"*, never *"is there a member whose only job is ST"* |
| 6 | **No permanent role is ever granted** | `DpcCommitteeService` calls `assignRole()` on no path; pinned by `CommitteeMembershipTest` |
| 7 | Nobody named twice — by `user_id`, normalised mobile, or lower-cased e-mail | three duplicate findings |
| 8 | Editable only while Draft/Returned | `WorkflowException` |

`config('dpc.committee.required_categories')` is **empty** by default — a system that
invented "every committee needs an SC member" would be inventing a business rule.

## Chairman — verified

```
id  name                        capacity          is_dpc_chairman  sort_order
1   Dr. Ajay Kumar Shrivastava  cgpsc_chairman     NULL             0
```

Seated automatically from `cgpsc_chairmen` (effective-dated, `unique(active_flag)` so
"at most one serving Chairman" is a fact the database holds). Chosen by the
proposal's **own date** — `servingOn($proposal->proposal_date)` — so a back-dated
proposal seats the Chairman who actually held office. Any `cgpsc_chairman` row in the
payload is discarded. With no Chairman on record the save is refused by name.

## Members — verified

```
2   Shri Brijesh Kumar Pandey   member  sort_order 1
3   Smt. Sunita Rathore         member  sort_order 2
4   Shri Sanjay Kumar Agrawal   member  sort_order 3
```

**Chairman + 3 = the official minimum, satisfied exactly.** Confirmed unchanged by
business ruling 2026-09-06: `min_members = 3`, `max_members = 6`.

## Member attributes — verified

| Member | SC | ST | OBC | Female | Bhar Sadhak Sachiv | Vibhag Sachiv | Effective |
|---|---|---|---|---|---|---|---|
| Chairman | 0 | 0 | 0 | 0 | 0 | 0 | **UR (General)** |
| Brijesh Kumar Pandey | **1** | 0 | 0 | **1** | **1** | 0 | SC + Female + BSS |
| Sunita Rathore | 0 | 0 | **1** | 0 | 0 | 0 | OBC |
| Sanjay Kumar Agrawal | 0 | 0 | 0 | 0 | 0 | **1** | UR + Vibhag Sachiv |

- **Social category exclusive** — submitting SC **and** ST is refused: *"Member 1
  (Shri Brijesh Kumar Pandey) is recorded as SC + ST. A member belongs to only one
  reserved social category…"* (verified live). The browser also clears siblings, but
  the server refuses regardless.
- **No UR checkbox** — UR is the absence of a claim; `socialCategoryLabel()` resolves
  the null for display.
- **Independent attributes combine freely** — Brijesh holds three at once.
- **Attributes belong to the membership**, not the person, and grant nothing.

**Verified.**

---

# 6 · Promotion Candidate

## Provisioning

Happens on the **Seniority List commit**, by employee number, in the same
transaction as the list.

**Evidence:**

```
users before = 34   after = 35   (+1 created, 9 reused)

id  employee_number  name                    email  mobile  must_change_password  hash
47  CG200010         Shri Mahesh Kumar Sahu  NULL   NULL    1                     $2y$12$…

user_department_assignments · role = promotion_candidate · 24 rows
  remarks: "Named on the Seniority List of DPC-2026-SE-0001."
```

Nine of the ten employee numbers already had accounts and were **reused, not
duplicated**. The tenth was created, given the `promotion_candidate` role and an
active department assignment naming the DPC.

**Candidature is derived, not stored.** It comes from the Seniority List — clear the
lists and the accounts survive with no candidature, which is exactly the fresh state.

## Password generation

- A **random 12-character** password at provisioning, `bcrypt` cost 12
  (`$2y$12$` confirmed above).
- `must_change_password = 1` → `EnsurePasswordChanged` middleware forces
  `/password/change` before any other screen.
- **Dispatched over the officer's contact channel** — and **a candidate has neither an
  e-mail nor a mobile**, because the ten-field officer snapshot has no contact
  column. So the password goes nowhere and is unrecoverable.
- **The one route that yields a readable password:** the AO's **Reissue password** on
  the proposal while it is **Draft** or **Returned**, which displays a 10-character
  value **once**. Verified in an earlier session: `CG200001` → `Tgntexi9K8` → forced
  change → `/my-dpc` 200.
- Administration → Users → **Reset Password** issues one but **does not display it** —
  useless for a candidate.

## Login

`LoginController` picks the column by the shape of the identifier:

| Typed | Matched on |
|---|---|
| contains `@` | `email` |
| exactly 10 digits | `mobile` |
| anything else | **`employee_number`** |

The third form exists **only** so a candidate can sign in at all. Then: active check
→ verification check → `Auth::login` → session regenerated. Rate-limited
(`throttle:login`).

`/my-dpc` shows only proposals whose `forwarded_at` is set and whose Seniority List
names this employee number. **A candidate who types another proposal's UUID gets a
404, not a 403** — a 403 would confirm the proposal exists.

**What the candidate can see:** own DPC, own status, own seniority entry, timeline
with remarks stripped, notifications, final result when published. **What does not
exist for this role:** any route to a Seniority List, a Committee, an Evaluation
Chart, a calculation run, or another officer's anything.

## Self-Inspection

Account-free, by design — an officer under consideration is not a portal user.

- **Three identity factors, all must match exactly:** `name`,
  `date_of_posting_on_promotion_to_post`, `dob`. Configured, not hard-coded
  (`DPC_SELF_INSPECTION_FACTORS`). The deck asks for two; the default is three
  because the screen it opens shows CR grades and property compliance.
- **The session holds row ids, not a person** — nothing is written to `users`, no
  guard is involved, which is what lets somebody with no account reach it.
- **Inspectability is re-checked on every request**, never trusted from the session.
- Visible only for proposals in `submitted` or `approved`; **closes once the sitting
  approves its result** (`close_after_result = true`) — after that the route to a
  correction is a Review DPC.
- **Every attempt is recorded** in `self_inspection_accesses`, matched or not, with
  the factor names required, the IP and the user agent — and **failed attempts do not
  store the answers**. Rate-limited.
- **There is deliberately no "Raise Objection"** — the deck directs the officer to
  the department in person.

**Verified.**

---

# 7 · Reports

Every one below was opened during this pass. **Nothing on a report is
recalculated** — promotion figures come from the run each proceeding *pinned at
approval*, so a report cannot disagree with the signed document it summarises.

| Report | Route / Screen | Verified output |
|---|---|---|
| **Outcome tiles** | `/reports` | **Promoted 3 · On a waiting list 3 · Held (CIR) 0 · Not placed 4** |
| **DPC Pipeline** | `/reports` | `Drafted 0 · Awaiting departmental approval 0 · Returned 0 · Forwarded to the Commission 1 · Allotted to a Member 1 …` — a DPC appears against every stage it has reached, so counts deliberately do not sum |
| **By year** | `/reports` | DPCs per DPC Year |
| **By variant** | `/reports` | Fresh / Review / Circulation |
| **By department** | `/reports` | Commission and Admin only; a department sees itself |
| **Pipeline export (CSV)** | `/reports/export` | `Content-Type: text/csv`, `filename=dpc-pipeline-20260906-141414.csv`, header `Stage,"PPT reference",Count` |
| **DPC Allotment Report** | CGPSC → Allotments | allottee, **DPC Chairman**, deadline, status |
| **Allotment History** | per DPC | allot · reallot · **automatic return**, read only from the timeline |
| **DPC Schedule Report** | CGPSC → Schedules | 25-09-2026 11:00, old beside new |
| **Scheduling History** | per DPC | accept · schedule · reschedule, from the timeline |
| **Calculation Result + Explain** | per run | Main 3, Waiting 3, All Officers Assessed 10, per-officer derivation |
| **Calculation History** | per proceeding | both runs — department's and Commission's — nothing overwritten |
| **Proceedings document** | Proceeding → Documents | signed, `rule_version 2026.1`, `engine 1.0.0`, hash `93711c95…`, published |
| **Promotion List document** | Proceeding → Documents | signed, hash `fa812d7e…`, published |
| **Audit Trail + CSV** | `/admin/audit-trail` | workflow ledger · activity timeline · data changes; read-only by design, formula injection neutralised on export |
| **Self-Inspection print** | `/self-inspection/record/print` | the officer's own row |

**Verified.**

---

# 8 · Final Business Flow — step by step, with evidence

| # | Step | Screen | Controller | Service | DB tables updated | Notifications | Business rule applied |
|---|---|---|---|---|---|---|---|
| 1 | **Department** login | `/login` | `Auth\LoginController@store` | `VerificationService` | `sessions` | — | Identifier shape → column; active + verified before dashboard |
| 2 | **Pre-DPC** created | `/pre-dpc/create` | `PreDpc\PreDpcProposalController@store` | `PreDpcProposalService::createDraft`, `PostAttributeResolver` | `pre_dpc_proposals`, `pre_dpc_promotion_sources`, `pre_dpc_source_qualifications`, `identifier_sequences`, `activity_timelines`, `audits` | — | Posts must be own-department, active, in force · Direct % + Promotion % = 100 · feeder shares = 100% of the promotion quota · no duplicate active exercise |
| 3 | Pre-DPC submitted | `/pre-dpc/{uuid}` | `…@submit` | `PreDpcProposalService::submit`, `WorkflowEngine` | `pre_dpc_proposals.status`, `workflow_transitions`, `activity_timelines` | `submit` × 2 → AH | `draft\|returned → submitted`, role `authorized_officer` |
| 4 | **AH** approves Pre-DPC | `/pre-dpc/{uuid}` | `…@approve` | `PreDpcProposalService::approve`, `SignatureService`, **`projectToMasters`** | `pre_dpc_proposals`, `signatures`, **`recruitment_rules`**, **`recruitment_rule_sources`**, **`rr_source_qualifications`**, `workflow_transitions`, `activity_timelines` | `approve` × 2 → AO | `submitted → approved`, role `administrative_head`, **signature required**; supersede incumbent; **project the rule** |
| 5 | **Recruitment Rule** | `/admin/recruitment-rules` | `Admin\RecruitmentRuleController@index` | — (read) | — | — | v1 · `F-2-14/2026/20-Three` · 100% · active — **evidence in §1** |
| 6 | **Final DPC** raised | `/dpc-proposals/create` | `Dpc\DpcProposalController@store` | `DpcProposalService::createFromPreDpc` | `dpc_proposals`, `dpc_id_sequences`, `dpc_proposal_revisions`, `activity_timelines`, `audits` | — | **Only an `activeApproved()` Pre-DPC may be raised from**; post pair, pay scale, class, duration and rule all inherited |
| 7 | Committee (S15) | `/dpc-proposals/{uuid}/committee` | `…@updateCommittee` | `DpcCommitteeService::compose` | `dpc_committee_members`, `users` (new externals), `activity_timelines` | — | Chairman + 3..6 · CGPSC Chairman seated by the system · one social category · **no role granted** |
| 8 | Working Officers (S16) | `…/working-officers` | `…@updateWorkingOfficers` / `DpcImportController` | `DpcProposalService::saveWorkingOfficers` → `saveVacancies` → `VacancyEngine` + `VacancyCalculationRecorder` | `import_batches`, `import_rows`, `dpc_working_officers`, `dpc_vacancies`, **`dpc_vacancy_calculations`**, `dpc_proposals.sanctioned_post_count` | — | One row per sanctioned chair · officers ≤ sanctioned · vacancy ≥ 1 · **officers first, then vacancies** |
| 9 | Seniority List (S17) | `…/seniority-list` | `…@updateSeniorityList` / import | `DpcProposalService::saveSeniorityEntries`, `EvaluationCandidateService::project`, `PromotionCandidateProvisioner` | `dpc_seniority_entries`, **`dpc_evaluation_rows`**, `users`, `model_has_roles`, `user_department_assignments` | credential dispatch (queued) | Exact zone count where a zone applies · **chart projected from the list** · accounts reused by employee number |
| 10 | Evaluation Chart (S18) | `…/evaluation-chart` | `…@updateEvaluationChart` / import | `EvaluationCandidateService::applyEvaluationData` | `dpc_evaluation_cr_grades`, `…loyalty_flags`, `…property_flags`, `dpc_evaluation_record_gaps` | — | Officers must be **exactly** the Seniority List's · `Y`/`N` only · configured CR grades |
| 11 | Calculate (department) | `…/calculate` | `Dpc\DpcCalculationController@store` | `DpcCalculationService`, `EligibilityService`, `ZoneOfConsiderationService` | `dpc_calculation_runs`, `dpc_calculation_results` | — | 5-year window · `घ` gate · qualifying 10 · Super Seat · relaxation flagged not applied |
| 12 | DPC submitted | `/dpc-proposals/{uuid}` | `…@submit` | `DpcProposalService::submit`, `DpcProposalCompletenessService` | `dpc_proposals.status`, `workflow_transitions` | `submit` × 2 | Five sections complete **plus** three arithmetic invariants |
| 13 | **AH** approves Final DPC | `…/review/{step}` → `…@approve` | `Dpc\DpcProposalController@approve` | `DpcProposalService::approve`, `SignatureService` | `dpc_proposals` (`approved_at`, **`forwarded_at`**, `signature_id`), `signatures`, `workflow_transitions` | `approve` × 2 | `submitted → approved`, **signature required**; one signature covers all five sections |
| 14 | **Chairman** — routed | `/cgpsc/allotments` | `Cgpsc\AllotmentController@index` | — (read) | — | — | Queue = `status = approved AND forwarded_at IS NOT NULL` → **automatic, no manual routing** |
| 15 | **Assignment** | `/cgpsc/allotments` | `…@store` | `AllotmentService::allot` → `DpcCommitteeService::designateChairman` | `dpc_allotments`, `dpc_committee_members.is_dpc_chairman`, `workflow_transitions`, `activity_timelines` | `allot` × 3 → AO, AH, Member | Three decisions in one act · DPC Chairman must be a serving member of **this** committee · deadline required, not past |
| 16 | Accept | `/cgpsc/schedules` | `Cgpsc\SchedulingController@accept` | `SchedulingService::accept` | `dpc_allotments.status`, `accepted_at` | `accept` × 3 | Only the allottee; `allotted → accepted` |
| 17 | **Scheduling** | `/cgpsc/schedules` | `…@store` | `SchedulingService::schedule` | `dpc_schedules`, `dpc_allotments.status` | `schedule` × 3 | **Only the Allottee** · **Meeting Date ≤ Completion Deadline** (form *and* service) |
| 18 | **Notifications** | — | — | `AnnounceSittingToParticipants` (queued, `afterCommit`) | `user_notifications` | **`sitting_scheduled` × 14** | Every serving committee member + every officer on the Seniority List · date, time, joining instructions · replaced members excluded |
| 19 | **Proceedings** | `/cgpsc/proceedings` | `Cgpsc\ProceedingController@open`, `ProceedingResultController` | `ProceedingService`, `ProceedingCalculationService`, `ResultApprovalService`, `HoldService` | `dpc_proceedings`, `dpc_calculation_runs` (2nd), `dpc_calculation_results`, `dpc_proceeding_holds`, `dpc_proceeding_overrides` | `open`, `approve_result` | Only the allottee, only while open · proposal version pinned · **"Promotion as on" year required** · approval pins the run permanently |
| 20 | **Promotion List** | Proceeding → Documents | `Cgpsc\ProceedingDocumentController` | `ProceedingDocumentService`, `DocumentRenderer`, `SignatureService` | `dpc_proceeding_documents`, `signatures` | publication notice | Generated only after approval; provenance + content hash; signed; publish is separate |
| 21 | **Candidate** | `/my-dpc` | `Candidate\CandidateDpcController` | `PromotionCandidateProvisioner::entriesFor` | — (read) | — | Scoped by employee number, derived once; `forwarded_at` required; remarks stripped; 404 not 403 |
| 22 | **Completed** | — | — | — | — | — | Result approved, documents published, hold register clear |

## Ledger evidence — the complete transition chain

```
workflow       event    from_state   to_state
pre_dpc        submit   draft        submitted
pre_dpc        approve  submitted    approved
dpc_proposal   submit   draft        submitted
dpc_proposal   approve  submitted    approved
dpc_cgpsc      allot    unallotted   allotted
dpc_cgpsc      accept   allotted     accepted
dpc_cgpsc      schedule accepted     scheduled
dpc_proceeding open     scheduled    in_proceeding
dpc_proceeding approve_result in_proceeding result_approved
```

## Notification evidence

```
event               n
sitting_scheduled   14      <- 4 committee members + 10 candidates
allot                3
accept               3
schedule             3
submit               2
approve              2
```

## Final state

```
proceeding: result_approved · promotion_as_on_year 2026 · final_calculation_run_id 2
            conducted_by / closed_by: Shri Brijesh Kumar Pandey

documents:  proceedings     v1  rule 2026.1  engine 1.0.0  hash 93711c95…  published  signed
            promotion_list  v1  rule 2026.1  engine 1.0.0  hash fa812d7e…  published  signed

holds:      none
reports:    Promoted 3 · Waiting 3 · Held 0 · Not placed 4
```

**Verified end to end.**

---

# GO / NO-GO

## **GO**

Every calculation and every flow step verified against the running application with
database evidence. **No code defect was found.** One documentation defect was found
and corrected (below). The database was returned to its clean baseline: 34 accounts,
0 proposals, Recruitment Rules empty, `storage/app` at 3 files, Redis flushed.

## Defect found and corrected during this verification

**The "13 notifications" figure was stale.** It dated from the 9-candidate sample
CSVs. With the current 10-candidate data the sitting announcement reaches **14** —
4 committee members plus 10 candidates — and the delivery log reads
`{"sms":4,"email":4,"unreachable":10,"recipients":14}`. Corrected in
`CURRENT_DEMO_FLOW.md`, `SAMPLE_DATA_GUIDE.md` and `TERMINAL_SUGGESTIONS.md`.
**Documentation only; no code change.**

## Remaining business risks

| # | Risk | Likelihood at the demonstration | Impact | Mitigation |
|---|---|---|---|---|
| 1 | **Queue worker not started** | Medium — easy to forget | High: the bell stays empty, no OTP, no sitting notice | Start it and confirm one login before the customer arrives |
| 2 | **Port 8000 opened by mistake** | Medium | High: a different application answers, looking similar | Use `:8001` |
| 3 | **CSV post names are free text.** `promotion_to_post` is `nullable\|string\|max:255` and is **not** checked against the proposal. A file naming the wrong post pair imports **4 valid, 0 rejected, with no warning** | Low if the script is followed; certain if a post is created live | Medium: wrong post names reach the chart and the signed documents | Do not create a Department Post live; use the supplied files. **Logged as a validation gap** |
| 4 | **Candidates receive no SMS or e-mail** | Certain — structural | Low at the demonstration, Medium in production | State it plainly; `FBE-002` |
| 5 | **Self-Inspection shown too late** refuses | Medium | Low: looks like a fault | Show between step 13 and step 19 |
| 6 | **Deadline sweep needs a host cron** | Certain here | Low | Demonstrate with `--as-of=` |
| 7 | **Reserved relaxation not applied** (Q5/Q6 unruled) | Certain | Medium if asked: a reserved officer just below the threshold is flagged, not relaxed | Answer honestly — it awaits a Commission decision and is flagged rather than guessed |
| 8 | **Documents are HTML, signature is the mock** | Certain | Low | Provenance, hash and signature records are all real |
| 9 | **No multi-participant sitting** (`FBE-001 §9`) | Certain | Medium if the audience expects members to join | One accountable allottee conducts; presiding is recorded |
| 10 | `Teacher` carries no Sanctioned Strength | Certain | Very low | Intentional — demonstrates the warning state |

**None is blocking.** Risks 1, 2 and 5 are procedural and covered by the
prerequisites and the checklist in `CURRENT_DEMO_FLOW.md`. Risk 3 is the only genuine
software gap surfaced by this exercise and is avoidable by following the script.

---

*Verified 2026-09-06 by an instrumented end-to-end pass. Evidence captured with
`SELECT` between steps and HTTP responses recorded per actor. Cross-checked against
`docs/uat/TERMINAL_SUGGESTIONS.md`, `docs/demo/CURRENT_DEMO_FLOW.md` and
`docs/FUTURE_BUSINESS_ENHANCEMENTS.md`.*
