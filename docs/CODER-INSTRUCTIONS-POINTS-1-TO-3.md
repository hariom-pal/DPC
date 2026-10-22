# Coder Instructions — Points 1 to 3 (with Seniority, CR, Property Return and PH)

> **Status (2026-10-17): implemented and committed on the development branch
> `feature/uat-cleanup-and-admin-audit`; migrations run on the DEVELOPMENT database
> (`dpc_dev`) only. Not deployed to UAT or Production.** Test-suite optimisation and the
> migration record are in §8 and §9.
>
> Source: `Understanding of DPC with Coder Instructions.pdf` (6 pages). Page numbers below
> are the PDF's own pages. Requirement IDs `CI-xx` are the same IDs used in
> [`ADMIN-IMPLEMENTATION-STATUS.md`](ADMIN-IMPLEMENTATION-STATUS.md) §3.

## 1. What the PDF says

| Point | PDF page | Rules |
|---|---|---|
| **1. Vacancy calculation and statutory checks** | 1–2 | 1.1 verification checklist · 1.2 higher-post vacancy · 1.3 rounding · 1.4 reservation ended 2019 / proposals before 2019 · 1.5 effective date and 31 December · **1.6 final seniority list as on 1 April of the preceding year** |
| **2. Reservation, PH and sealed cover** | 2–3 | **2.1 PH 3 % of the promotion quota, availed once, separate record, floor computation** · 2.2 DE / chargesheet / SCN / suspension → sealed cover · 2.3 deputation |
| **3. ACR/CR evaluation and missing CR** | 3–4 | **3.1 up to 4 CRs a year, averaged and truncated; the put-up / grading / reporter-reviewer-accepting flow; NRC; channel undertaking** · **3.2 missing CR: NRC, long leave, suspension, each with its document** · 3.3 Class-I merit-cum-seniority (NA) · 3.4 marks 9/7/5/3/1 via master · 3.5 old grading intervals via master |
| Related, point 4 | 4–5 | **4.1 every IPR uploaded; late IPR only with the department's decision; next-increment date as duplicate check** · 4.4 no-duplicate-CR undertaking at each CR upload; DOB / joining order documents |

## 2. Business decisions applied (from the task, 2026-10-17)

1. **The Authorized Officer owns the whole proposal.** Every employee-wise screen is inside the existing DPC proposal, gated by the existing `DpcProposalPolicy::update` (Authorized Officer of the proposal's department, Draft or Returned). No employee login, no new role, no parallel workflow. Downstream roles are unchanged. *(The task says "AO = Administrative Officer"; in this application the role is `authorized_officer`, "Authorized Officer". It is the same owner of the proposal.)*
2. **The Seniority List CSV carries identity and seniority only.** CR, Property Return, PH and documents are recorded afterwards on each officer's details.
3. **Only PH reservation is handled in this scope;** reservation fields are removed from the seniority CSV without deleting data or removing reservation functionality elsewhere.
4. **PH questionnaire** Q1 → Q2 → Q3, undertaking mandatory for both answers to Q3, a claim is not an approval.
5. **FRESH/fresh** DPC Types template defect fixed.

## 3. Conflicts and decisions needed (not silently resolved)

| # | Conflict | Affects | What was done | Decision needed |
|---|---|---|---|---|
| D-1 | **Reservation category.** The task removes it from the CSV ("only PH in scope"); rule 1.4 says reservation (in promotion) ended in 2019. But the **eligibility engine places officers on the Main / Waiting lists category by category** (`DpcCalculationService::placeOnLists`). An officer without a category would never be placed. | CI-08, list placement | Category is **optional in the CSV** and recorded on the officer's details (Section A). The submission gate still asks for it while `dpc.employee_details.require_category=true`. Nothing was removed from the engine. | Whether promotions are still placed category-wise. If not, the engine's placement must change first, then the switch can be turned off. |
| D-2 | **Missing / ungraded CR — "take one previous year CR"** (page 3) vs. the change register of 2026-08-27 (items 14–19). | CI-19 | **Decided 2026-10-21: no carry-forward.** A CR year the Class Master rule makes mandatory and that is missing needs its reason selected and the reason's document uploaded (rule 3.2); with both the promotion is held (**CIR**), without them **NE**. | — |
| D-3 | **CR marks.** Rule 3.4: क+ 9, क 7, ख 5, ग 3, घ 1 via master data. | CI-23, CI-18 | **Decided 2026-10-21:** the DPC calculation runs on the **Class Master promotion rule** for its class pair and DPC year and on the **Eligible CR Grades' points** valid on 1 January of that year (`RuleSet::forPromotionRule`). Score = total of the rule's CR years (the most recent graded) + the rule's tribal bonus, against the rule's **qualifying total**; a year below the rule's minimum → NE; Super Seat = every year at the rule's super-seat points; several CRs in a year score their average on the points, decimal dropped. **No rule → the calculation is refused.** Each run keeps the rule in its snapshot. | — |
| D-4 | **Rule 3.2 says "out of 4" reasons but names three** (NRC, long leave, suspension). | CI-22 | The three are added to the Reason Master (only if absent). The existing seven reasons remain. | The fourth reason, and whether the other existing reasons stay valid for a CR. |
| D-5 | **PH verification and final decision.** Rule 2.1 asks the system to verify whether the benefit was availed before, and the task says a claim goes to eligibility verification. Neither names who verifies or decides, and no new role may be created. | CI-13, CI-12 | A claim sets `ph_verification_status = pending` and leaves `ph_final_decision` empty. The officer's PH records on other proposals of the same department are shown (reported, not applied). | Which existing role verifies and decides, and at which stage. |
| D-6 | **PH quota placement.** Rule 2.1 gives the 3 % and the floor, but not how a PH seat interacts with the Main / Waiting lists. | CI-12 | The quota is computed (floor) and shown beside the officers. The engine's lists are not changed. | How PH seats are allotted on the lists (horizontal reservation procedure). |
| D-7 | **PH undertaking wording.** No official template exists in the PDF or the repository. | PH undertaking | The Authorized Officer uploads the employee's signed undertaking and confirms it is the employee's own. No legal wording was invented. | The official undertaking format. |
| D-8 | **Existing Promotion Candidate logins.** The task says "do not create an employee login". The application already provisions a read-only Promotion Candidate account per officer on the Seniority List (change register item 17, 2026-08-25). | — | Not changed (it predates this task and is not a data-entry path). Nothing new was added for employees. | Whether item 17 stays. |
| D-9 | **Current Post vs. multiple feeder cadres.** Page 5 says each cadre is vertical. | CSV | `CURRENT POST` must equal the proposal's own Promotion From Post (or, if the proposal names none, one of the Recruitment Rule's feeder posts). | — (consistent with the PDF) |

## 4. Implementation

### 4.1 Seniority List — rule 1.6 and the simplified CSV

**Template** (`ImportTemplateBuilder::headers`, downloadable per proposal):

| Column | Required | Maps to | Why |
|---|---|---|---|
| `SENIORITY NO` | yes | `seniority_number` | order; must be unique in the file |
| `EMPLOYEE ID` | yes | `employee_number` | identifies the officer across uploads; unique in the file |
| `EMPLOYEE NAME` | yes | `name` | |
| `DATE OF BIRTH` | yes | `dob` | |
| `DATE OF APPOINTMENT` | yes | `date_of_joining_service` | |
| `CURRENT POST` | yes | `promotion_from_post` | must be this DPC's feeder post (D-9) |
| `DATE OF POSTING ON CURRENT POST` | **optional** | `date_of_posting_on_promotion_from_post` | the one extra column: the recruitment-rule experience / promotion-duration gate (`CandidateGateService`) counts from it. Without it that gate cannot run. Can also be filled on the officer's details. |

Not in the template and **not required**: category, seat category, mobile, qualification, any CR / property / loyalty / PH column. Files written in the older columns are still read (aliases kept), so nothing a department already holds is refused for its headers alone.

**Cutoff.** `DpcProposal::seniorityReferenceDate()` = 1 April of `dpc_year − 1` (DPC year 2026 → 01-04-2025; 2027 → 01-04-2026). Derived from the DPC year only — never the current date, upload date or meeting date. Shown on the Seniority List screen, the upload form and the officer's details.

**"As on" date as proposal metadata.** The upload form (and the manual form, when enabled) asks for the date the list was drawn up as on. It must equal the cutoff, or the upload is refused before anything is staged. The date travels on the import batch (`import_batches.list_as_on`) and is stored on the proposal at commit (`dpc_proposals.seniority_list_as_on`). The submission gate's Seniority List section is incomplete while the stored date is missing or no longer matches the current DPC year (e.g. the DPC year was edited after the upload).

**Validation and preview.** Row rules (`DpcRowRules::seniorityEntry`) plus cross-row checks (`ExcelImportService::checkSeniorityRows`): duplicate seniority numbers, duplicate Employee IDs (case-insensitive), Current Post. Every row involved is reported, so the error report names each line. The preview shows each row's seniority no, Employee ID, post and appointment date, and **what committing would change**: officers kept (details preserved), added, and removed — naming any removed officer whose CR / Property / PH / documents would be detached.

**Safe re-import.**
- A Seniority List file with **any** rejected row is **not committed** (it replaces the whole list; a partial commit would silently drop officers). The existing list stays.
- `EvaluationCandidateService::project()` now **reconciles in place**: an officer still on the list keeps their evaluation row and everything recorded on it (CR years and questionnaire, Property Returns, PH, missing-record justifications, documents); only identity is refreshed. Fields the simplified list does not carry (category, qualification, mobile, posting dates) are not erased by a blank. Previously the rows were deleted and recreated, which cascaded away every missing-record justification on each save — **a pre-existing defect, fixed**.
- A removed officer's documents are kept (link nulled, `ON DELETE SET NULL`) and re-attach by Employee ID if the officer is added back.
- Chart saves (`applyEvaluationData`) now synchronise per year instead of delete-and-recreate; a year whose grade the chart changes has its CR questionnaire cleared so it must be answered again.

### 4.2 Employee Details inside the proposal

Routes (all under `dpc-proposals/{proposal}/employees`, scoped bindings, officer addressed by seniority number):

| Screen | Purpose |
|---|---|
| `GET employees` | every officer, completion badge (Complete / "CR Pending, Property Return Pending, PH Pending…"), "Complete Details" |
| `GET employees/{sn}` | sections A–G; previous / next / next-pending officer |
| `PUT …/basic` | A. category, qualification, mobile, date of posting on current post |
| `POST …/cr`, `DELETE …/cr/{year}` | C. one CR year |
| `POST …/property`, `DELETE …/property/{year}` | D. one IPR year |
| `PUT …/ph` | E. PH questionnaire |
| `POST …/documents`, `GET …/documents/{uuid}` | F. upload / download |

Each section saves on its own form: one section's validation error never loses another's work, and whatever is saved is there when the officer returns (draft = saved state). Reads: `view` (Authorized Officer and Administrative Head of the department). Writes: `update` (Authorized Officer, Draft/Returned only) — authorised **before** validation.

**C. CR (page 3, rules 3.1, 3.2; page 5).** Per year: *Was a CR put up?* → *Is there a grading?* → up to **4 reports**, each with reporting / reviewing / accepting officer designation and remark. Final remark = accepting, else reviewing, else reporting. Several reports → average of the configured marks, **decimal dropped** (8 / 3 = 2.67 → 2). The year's `grade` (what the engine reads) is derived, never typed. Missing remark → NRC required. Not put up → the existing missing-record justification (reason + PDF; the three rule-3.2 reasons were added). No grading → CR-without-grading upload required. Graded year → CR copy with the **"no duplicate CR for this year" undertaking** (refused without it). Once per officer → channel undertaking + channel document. Years must lie between `dpc.evaluation.min_year` and the DPC year. **How many years are required is not decided here** (engine settings unchanged).

**D. Property Return (rule 4.1, page 4).** Per year: submitted yes/no, submission date, **date of next increment** (required for a submitted return; the same date on two years is refused), IPR upload (same file for two years refused). Submitted after **31 January of the following year** → the department's decision is required; *rejected* → the return does not count (`flag = false`); *accepted* → reason and decision document required. `flag` stays the engine's only input; disqualification rules are unchanged.

**E. PH (rule 2.1).**

| Case | Answers | Result recorded | Undertaking |
|---|---|---|---|
| 1 | not PH | no PH benefit; Q2/Q3 not asked (cleared) | not required |
| 2 | PH, availed before | no fresh benefit; reason recorded (default text cites rule 2.1); Q3 not asked | not required (the PDF names no additional document) |
| 3 | PH, not availed, declines | no PH reservation; `ph_final_decision = no_benefit` | **required** |
| 4 | PH, not availed, claims | claim recorded; `ph_verification_status = pending`; **`ph_final_decision` stays empty** | **required** |

Separate facts: `is_ph`, `ph_previously_availed`, `ph_claims_reservation`, the undertaking document, `ph_verification_status`, `ph_final_decision`; the outcome (`PhReservationOutcome`) is derived, never stored. Recorded by / at are stamped. The officer's PH records on other proposals of the same department are shown on the screen (rule 2.1 "system verification") — reported, not applied. The 3 % quota is computed with the floor and displayed (33 vacancies → 0; 67 → 2).

**F. Documents.** One table, `dpc_evaluation_documents`: PDF only, `dpc.documents.max_kb` (10 MB), stored on the private documents disk under `private/employee-documents/{proposal}`, SHA-256 of the received bytes, uploaded-by and uploaded-at, audited (owen-it) and on the activity timeline. A replacement **supersedes** the previous row (`superseded_at`, `superseded_by_id`); nothing is deleted. Download streams from the private disk to users who may view the proposal.

**G. Completion.** `EmployeeDetailsService::status()` lists what is missing per section. Only requirements from the PDF and existing rules are checked; see §4.1/4.2 above.

### 4.3 Proposal completeness

New checklist section **Employee Details** (`employee_details`) in `DpcProposalCompletenessService`, between the Evaluation Chart and Rule 1.1. `submit()` refuses while any officer is incomplete, naming up to five officers and what each lacks. Switches: `dpc.employee_details.required_for_submission`, `require_cr_documents`, `require_property_documents`, `require_category`, and `dpc.seniority.require_as_on_date`. Downstream (AH approval, Commission, calculation) unchanged; a Review DPC copies the as-on date and the current documents with the rest of the proposal (and a JSON double-encoding bug in that copy was fixed).

### 4.4 FRESH / fresh

The DPC Types template sample said `FRESH`; the seeded code is `fresh`. On MySQL the import matched the seeded row and **rewrote its code** to `FRESH`, after which the Fresh DPC screen refused every proposal; on SQLite it created a duplicate. Fixed: the sample uses `fresh`; master imports match the unique key **case-insensitively on every database** and **never rewrite** the matched row's key; the Fresh check is case-insensitive.

### 4.5 Migration (`2026_10_17_460001_add_employee_details_to_dpc_proposals`)

Additive only: new nullable columns on `dpc_proposals`, `import_batches`, `dpc_evaluation_rows`, `dpc_evaluation_cr_grades`, `dpc_evaluation_property_flags`; NOT NULL relaxed to NULL on the two `reservation_category_id` columns (FK kept); new table `dpc_evaluation_documents`; three Reason Master rows inserted only if absent. No drop, no delete, no rewrite of existing values. `down()` removes only what was added and leaves the category columns nullable. Verified on MySQL with `schema:verify-mysql` (scratch database, dropped afterwards): 87 migrations, 84 tables. The table is classified in `ResetTransactionalDataCommand` and `UatCleanupPlan` (CLEAN, with its file reference).

## 5. Traceability

Statuses: **C** COMPLETED · **P** PARTIALLY COMPLETED · **N** NOT STARTED · **B** BLOCKED · **V** NOT VERIFIABLE.

| ID | Requirement (PDF) | Page | Before | Changes in this task | Code evidence | Test evidence | Status | Remaining gap |
|---|---|---|---|---|---|---|---|---|
| CI-01 | 1.1 vacancy setup verified | 1 | C | — | `ProposalVerificationService` | `ProposalVerificationTest` | **C** | — |
| CI-02 | 1.1 filled posts vs previous minutes | 1 | C | — | same | same | **C** | — |
| CI-03 | 1.1 Commission account enters an offline previous DPC | 1 | N | — | — | — | **N** | BD-62 |
| CI-04 | 1.1 uploads optional below 50 eligible | 1 | B | — | — | — | **B** | BD-59 |
| CI-05 | 1.2 higher-post vacancy conditions | 1 | P | — | `VacancyMasterService` | `VacancyMasterTest` | **P** | not fed into the proposal |
| CI-06 | 1.2 undertaking + higher DPC number | 1 | P | — | same | same | **P** | no undertaking |
| CI-07 | 1.3 rounding | 1 | C | — | `VacancyEngine::quotaPosition` | `VacancyMasterTest` | **C** | — |
| CI-08 | 1.4 pre-2019 / reservation ended 2019 | 1 | B | category made optional on the list (D-1) | — | — | **B** | D-1, wording |
| CI-09 | 1.5 effective first day of the year | 1–2 | N | — | — | — | **N** | — |
| CI-10 | 1.5 vacancies to 31 December | 2 | C | — | `VacancyEngine::periodForYear` | `VacancyMasterTest` | **C** | — |
| CI-11 | 1.6 seniority list as on 1 April of the preceding year | 2 | P | as-on date stated, validated, stored, gated; cutoff from DPC year only | `DpcProposal::seniorityReferenceDate`, `ExcelImportService::assertListAsOn`, `SaveSeniorityEntriesRequest`, completeness `seniority_list` | `SimplifiedSeniorityListTest` (cutoff ×2, refused date, missing date, gate) | **C** | — |
| CI-12 | 2.1 PH 3 % of the promotion quota | 2 | N | percentage and rounding from the DPC Rules version of the proposal year; quota computed and shown; claims counted | `EmployeeDetailsService::phQuota`, `DpcRuleBook` | `EmployeeDetailsTest::test_the_ph_quota_is_three_percent…` | **P** | D-6 list placement |
| CI-13 | 2.1 once in service; separate record; verify previous benefit | 2 | N | questionnaire, separate facts, earlier records shown | `savePh`, `earlierPhRecords`, `PhReservationOutcome` | `EmployeeDetailsTest` cases 1–4, earlier record | **P** | D-5 verifier / decision |
| CI-14 | 2.1 PH computed with floor | 2 | N | integer floor | `phQuota` | same quota test | **C** | used for display only (D-6) |
| CI-15 | 2.2 sealed cover / DE / SCN / suspension | 2 | N | — | — | — | **N** | not in this scope |
| CI-16 | 2.3 deputation date, > 1 year | 2 | P | — | Vacancy Master | `VacancyMasterTest` | **P** | chart rule differs |
| CI-17 | 2.3 deputation without setup excluded from list | 3 | N | — | — | — | **N** | — |
| CI-18 | 3.1 up to 4 CRs a year, average truncated | 3 | N | reports per year, average truncated | `EmployeeDetailsService::yearGrade` | `…several_reports_are_averaged…`, `…at_most_four…` | **C** | on the configured scale (D-3) |
| CI-19 | 3.1 put up? / grading? / previous CR / ungraded upload | 3 | N | put up and grading questions; ungraded upload required | `saveCrYear`, `crMissing` | `…not_put_up…`, `…without_grading…` | **P** | carry-forward (D-2) |
| CI-20 | 3.1 reporter / reviewer / accepting; fallback; NRC | 3 | N | implemented | `finalGrade`, `reportNeedsNrc` | `…accepting_officers_remark`, `…falls_back_and_needs_an_nrc` | **C** | — |
| CI-21 | 3.1 channel undertaking + channel document | 3 | N | implemented | `confirmChannel` | `…complete_with_its_documents_and_the_channel_undertaking` | **C** | — |
| CI-22 | 3.2 missing CR: NRC / leave / suspension with documents | 3 | P | three reasons added; required per not-put-up year | migration `addRule32Reasons`, `crMissing` | `…not_put_up_needs_its_reason…`, `…rule_3_2_reasons_are_offered` | **P** | D-4 fourth reason; document type not tied to reason |
| CI-23 | 3.4 marks 9/7/5/3/1 via master | 4 | N | versioned scale from a DPC year, with order + PDF | `DpcRuleBook`, `DpcRuleController` | `DpcRulesTest`, `DpcCalculationTest` | **C** | averaging on 9/7/5/3/1 (D-3) |
| CI-24 | 3.5 old grading intervals via master | 4 | P | qualifying score per class pair in the same version | `DpcRuleBook` | `DpcRulesTest`, eligibility tests | **C** | — |
| CI-25 | 4.1 every IPR uploaded | 4 | N | IPR upload per submitted year | `propertyMissing` | `…submitted_return_is_complete…` | **C** | — |
| CI-26 | 4.1 late IPR only with department decision | 4 | N | decision, reason, document; rejected does not count | `savePropertyYear`, `SaveEmployeePropertyYearRequest` | `…late_return_needs_the_departments_decision` | **C** | — |
| CI-27 | 4.1 next-increment date; duplicate check | 4 | N | captured, required, duplicates refused (date and file) | `savePropertyYear`, `storeDocument` | `…same_next_increment_date…`, `…same_ipr_file…` | **C** | — |
| CI-33 | 4.4 DOB / joining order documents + undertaking | 5 | N | — (only "other documents" upload) | — | — | **N** | order number/date fields |
| CI-36 | 4.4 no-duplicate-CR undertaking at every CR upload | 5 | N | refused without it | `EvaluationDocumentKind::requiresUndertaking` | `…cr_document_needs_the_no_duplicate_undertaking` | **C** | — |

Task requirements that are not PDF items (also verified): simplified CSV, duplicate detection, safe re-import, AO-only authorization, cross-department refusal, draft/resume, employee-wise completion, FRESH fix — see `SimplifiedSeniorityListTest`, `EmployeeDetailsTest`, `DpcTypeTemplateCodeTest`.

## 6. Completion counts

| Set | Requirements | C | P | N | B | V | Completed |
|---|---|---|---|---|---|---|---|
| **A. Points 1–3** (CI-01 … CI-24) | 24 | 11 | 7 | 4 | 2 | 0 | **45.8 %** (was 4 C = 16.7 %) |
| **B. Directly related CR / Property / Seniority items outside points 1–3** (CI-25, 26, 27, 33, 36) | 5 | 4 | 0 | 1 | 0 | 0 | **80.0 %** (was 0 %) |
| **C. All Coder Instructions** (CI-01 … CI-45) | 45 | 16 | 11 | 14 | 3 | 1 | **35.6 %** (was 5 C = 11.1 %) |

Partial requirements are not counted as completed.

## 7. How to test it by hand (development)

1. As an Authorized Officer, open a Draft proposal → **Seniority List**: note "must be drawn up as on 01-04-(DPC year − 1)".
2. Download the template for this DPC; fill the six columns; upload with the stated date. Try a wrong date (refused), a duplicate Employee ID (both rows rejected), another post (rejected).
3. Commit; open **Employee Details** from the Seniority List screen or the readiness checklist.
4. For an officer: Section A category; Section C record a CR year with two reports, upload the CR (tick the undertaking), the channel undertaking; Section D record a return submitted on 15-03 of the following year and see the decision requirement; Section E answer Yes / No / Yes and upload the undertaking.
5. Upload the Seniority List again in a different order: the officer's details stay.
6. Submit: refused while any officer is incomplete; accepted when all are.

## 8. Test-suite optimisation (2026-10-17)

**Method.** Every test method in `tests/` was read and classified — A essential, B duplicate,
C obsolete, D low-value, E uncertain — and every B/C/D finding was re-verified by hand against
the test it was said to duplicate and against `app/` before anything was removed. Only B and D
were removed; nothing was removed to make the suite green or to hit a number. Authorization,
role, AO proposal, seniority, CR, Property Return, PH, undertaking, Recruitment Rule, Vacancy
Master, integrity, workflow, security and regression tests were all classified **A** and kept.

| | Before | After |
|---|---|---|
| Test executions (PHPUnit "Tests") | 1 571 | 1 548 |
| Test methods removed | — | 23 |
| Methods consolidated into data providers | — | 9 → 3 (every case kept) |
| Obsolete (C) found | — | 0 |

**Classification of the whole suite (1 511 methods before):** A 1 471 · B 15 · C 0 · D 11 · E 14.
Disposition: 23 removed (16 B — including two E re-verified as exact duplicates — and 7 D); 1 B and 3 D
strengthened instead of removed (§8.3); 1 D kept (payload schema version); 12 E kept (§8.4).

### 8.1 Removed (23)

| # | Test | Class | Why it adds nothing |
|---|---|---|---|
| 1 | `Unit/ExampleTest::test_that_true_is_true` | D | Laravel scaffold; `assertTrue(true)`, no application code. |
| 2 | `Feature/ExampleTest::test_the_application_returns_a_successful_response` | B | `GET /` → 200; `HealthCheckTest::test_home_page_renders` makes the same request and asserts the page content too. |
| 3 | `PreDpc/PreDpcExerciseTest::test_a_post_cannot_be_promoted_into_itself` | B | Same payload and error key inside `test_the_refusals_use_the_wording_written_for_them`, which also asserts the exact message. |
| 4 | `PreDpc/PreDpcExerciseTest::test_an_order_naming_no_promotion_from_post_is_refused` | B | As #3 (`promotion_sources = []`). |
| 5 | `SelfInspection/SelfInspectionTest::test_all_configured_factors_must_match` | B | Body is identical to the `identify()` helper every successful-identification test runs; the mismatch case is `test_a_wrong_factor_refuses_and_says_nothing_about_which_one`. |
| 6 | `Admin/RecruitmentRuleIntegrityTest::test_the_share_total_is_not_required_without_a_promotion_quota` | B | Identical request to `test_a_direct_recruitment_only_rule_needs_no_feeder`, which also asserts no feeder was saved. |
| 7 | `Admin/DepartmentPostMappingTest::test_adding_an_order_keeps_the_ones_already_recorded` | B | Same call as `test_a_later_order_never_displaces_an_earlier_one`; its one extra assertion was moved there. |
| 8 | `Admin/DepartmentPostMappingTest::test_the_superseded_mapping_still_answers_for_a_proposal_raised_under_it` | B | Same supersede inputs as `test_a_new_order_supersedes_the_mapping_without_changing_it` (HTTP → the same service), whose assertions are a superset; the clock it froze is never read. |
| 9 | `Proceeding/ProceedingLifecycleTest::test_opening_early_is_permitted_by_default` | B | Merged into `test_the_actual_opening_time_is_always_recorded` (same default config, also an early open), which now asserts the open succeeded. |
| 10 | `Proceeding/ProceedingDocumentTest::test_the_commit_hash_is_recorded_even_when_the_build_does_not_know_it` | D | Sets the config to `unknown` and reads the same literal back; the code has no branch for it. `test_a_document_records_the_generator_commit_hash` covers the copy. |
| 11 | `Proceeding/ProceedingOverrideTest::test_an_override_records_both_the_previous_and_the_new_value` | D | Asserted the values it had just passed to `create()` on the in-memory model. The stored values are asserted from the database by `ProceedingCorrectionTest::test_the_conducting_member_can_record_a_tribal_correction`. |
| 12 | `Dpc/DpcVacancyCalculationTest::test_a_zero_in_one_type_is_fine_as_long_as_the_total_matches` | B | Same save and same `balanced` assertion as `test_the_directives_worked_example`. |
| 13 | `Dpc/DpcVacancyCalculationTest::test_the_balanced_total_is_the_number_the_engine_will_use` | B | Same input as the worked example; its `totalVacancy()` assertion and its rationale were moved into it. |
| 14 | `Dpc/DpcExcelImportTest::test_a_seniority_import_is_unrestricted_before_a_vacancy_is_known` | B | Same file and commit as `test_commit_writes_through_the_existing_service_layer`, which asserts a superset; the explanation moved there. |
| 15 | `Dpc/DpcProposalClosureTest::test_a_complete_proposal_submits` | B | Strict subset of `DpcProposalWorkflowTest::test_ao_can_create_and_submit_a_draft`. |
| 16 | `Dpc/DpcProposalClosureTest::test_non_production_still_signs_so_uat_stays_demonstrable` | B | Same path as `test_one_approval_covers_every_section_and_signs_the_whole_proposal` (the flag it set is already the default); the explicit config pin and its reason moved there. |
| 17 | `Cgpsc/AllotmentDeadlineTest::test_the_cgpsc_chairman_may_allot_before_a_dpc_chairman_is_designated` | B | Identical `allot()` call to `test_the_completion_date_is_recorded_alongside_the_allotment_date`; the "before anybody is designated" state no longer exists (the presiding seat is required in the same request since 2026-09-05). |
| 18 | `Circulation/CirculationWorkflowTest::test_the_workflow_declares_exactly_what_phases_1_and_2_perform` | B | Same transition list asserted by `CirculationArchitectureTest::test_the_circulation_workflow_is_its_own_and_borrows_no_proposal_transition`. |
| 19 | `Circulation/CirculationDocumentTest::test_a_generated_document_verifies_against_its_stored_bytes` | B | `test_rendering_signing_storage_and_verification_all_work_while_bd14_is_open` verifies every generated document. |
| 20 | `Review/ReviewDpcTest::test_the_review_banner_appears_on_the_reviews_own_screens` | B | Same request and text as `test_the_banner_offers_the_chain_to_a_departmental_officer`; the Directive 3 note moved there. |
| 21 | `Cgpsc/NotificationCoverageTest::test_notifications_stop_entirely_when_the_feature_is_disabled` | D | The listener is registered at boot, so setting the flag afterwards changes nothing; it asserted only that allotment works and that the flag reads back false. |
| 22 | `Cgpsc/NotificationCoverageTest::test_timeline_ordering_is_deterministic_within_a_single_second` | D | Ran its own query twice and compared the results; no application code involved. |
| 23 | `Circulation/CirculationSignatureTest::test_phase_3_documents_exist_but_are_produced_only_after_signature` | D | Counted documents on an unsigned circulation where nothing could produce one. The rule is `CirculationDocumentTest::test_documents_cannot_be_produced_before_the_note_is_signed`. |

### 8.2 Consolidated (every case kept as a named data-provider row)

- `Eligibility/DpcCalculationTest`: run / result × edit / delete immutability (4 methods) → `test_a_run_and_its_results_are_immutable`.
- `Auth/LoginTest`: login by mobile / by email (2) → `test_a_user_can_login_with_either_identifier`.
- `Admin/RecruitmentRuleIntegrityTest`: inactive / soft-deleted / non-existent qualification (3) → `test_an_unattachable_qualification_is_refused`.

Further candidates the audit found (feeder refusals, allotment refusals, proceeding wrong-actor 403s, admin non-admin 403s) were left as separate tests: their set-ups differ per case and a provider would cost readability.

### 8.3 Strengthened instead of removed

| Test | Weakness | Change |
|---|---|---|
| `Eligibility/CalculationUiTest::test_calculating_redirects_to_the_result_addressed_by_uuid` | never asserted the redirect its name promises | asserts the redirect names the run's UUID |
| `Dpc/DpcEvaluationChartTest::test_the_screen_reports_expected_against_present` | asserted only two labels | asserts the expected / present figures |
| `Dpc/DpcCommitteeTest::test_three_members_may_cover_the_whole_required_combination` | passed even if the coverage rule broke | asserts the committee section is complete |
| `Review/ReviewSupersessionTest::test_supersession_cannot_be_applied_twice` | compared one value with itself | attempts the second approval for real, a day later, and asserts the supersession did not move |
| `Dpc/DpcProposalDetailPerformanceTest` | fixture saves were not checked, so a refusal would measure empty screens | each save asserts no errors |
| `Dpc/DpcExcelImportTest` | wrote uploads into the real `storage/app` | `Storage::fake('local')` |
| `Demo/SampleDataTest::test_the_with_hold_variant_holds_serial_four` | trailing `assertTrue(true)` | removed |

**Reliability note.** In a run that overlapped other PHPUnit processes, five file-handling tests failed
(`Storage::fake('local')` empties the shared `storage/framework/testing` directory). All five pass when
the suite runs alone, which is how it must be run; `DpcExcelImportTest` no longer writes into `storage/app`.

### 8.4 Kept although uncertain (E) — owner's call

`Eligibility/CalculationUiTest::test_history_is_empty_before_any_calculation` (empty state only) ·
`SelfInspection/SelfInspectionTest::test_a_year_with_no_recorded_value_renders_as_absent` (its `—` also appears in the
page title — needs a sharper assertion) · `Proceeding/ProceedingDocumentTest::test_the_renderer_is_resolved_from_the_contract`
and `::test_the_document_service_does_not_depend_on_approval` (architecture rules AQ-3 / Directive 3) ·
`Proceeding/ProceedingOverrideTest::test_an_override_never_touches_the_frozen_evaluation_row` ·
`Proceeding/CommitteeSittingTest::test_concluding_records_no_score` · `Dpc/DpcVacancyCalculationTest::test_an_unbalanced_proposal_cannot_be_submitted`
(name says "unbalanced", input is a ceiling breach) · `Cgpsc/AllotmentTest::test_a_dpc_cannot_be_allotted_to_a_non_cgpsc_user`
(same rule as the off-committee case since 2026-09-07) · `Circulation/CirculationArchitectureTest::test_the_circulation_table_is_its_own_and_not_dpc_proposals` ·
`Circulation/CirculationWorkflowTest::test_a_hold_can_never_be_deleted_by_removing_a_circulation` ·
`Security/SecurityHeadersTest::test_the_self_inspection_print_view_uses_no_inline_script` · `HealthCheckTest::test_home_page_renders`.
`Cgpsc/ReschedulingTest::test_the_notification_payload_carries_its_version` (D) was kept: it pins the payload schema version.

**Also noticed, not changed:** `SelfInspectionTest` and `SelfInspectionConfigurationTest` each carry an `assertDontSee('Date of
Joining Promotion To Post')` that became vacuous with the factor change of 2026-09-06; the empty-factor fallback in
`SelfInspectionFactor::configured()` still names that field; `MasterDataPortabilityTest` never uploads the departments /
department-posts templates unaltered.

## 9. Development database migration (2026-10-17)

- **Database verified first:** `APP_ENV=local`, `DB_DATABASE=dpc_dev` on `127.0.0.1`, read back through the application
  (`DB::connection()->getDatabaseName()`), config not cached. No UAT or Production database was touched.
- **Backup:** `mysqldump --single-transaction` of `dpc_dev` taken before migrating (kept outside the repository).
- **Reviewed:** all three pending migrations are additive in `up()` — new tables, new nullable columns, a NOT NULL relaxed to
  NULL (`reservation_category_id` on `dpc_seniority_entries` and `dpc_evaluation_rows`, foreign key kept), three reason
  rows inserted only when absent. Drops exist only in `down()`, which was not run.
- **Executed:** `php artisan migrate --force` →
  `2026_10_15_440001_create_dpc_proposal_verifications_table`, `2026_10_15_450001_create_vacancy_master_tables`,
  `2026_10_17_460001_add_employee_details_to_dpc_proposals` — all DONE. `migrate:status`: 87 Ran, 0 Pending.
- **Verified afterwards:** every one of the 79 pre-existing tables has the same row count as before, except `migrations`
  (+3) and `record_reasons` (+3: `nrc_issued`, `long_leave`, `suspended`); 5 new tables, all empty; both
  `reservation_category_id` columns nullable with their foreign keys intact. Nothing was seeded or deleted.
