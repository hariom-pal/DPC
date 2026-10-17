# Admin Module — Implementation Status

**Date:** 2026-10-17 · **Code:** branch `feature/uat-cleanup-and-admin-audit`, based on release tag `uat-2026-10-15-r1` (`f1bb261`)

**Method:** every feature traced end to end — route → middleware → controller → request → service → table → view → tests. A route or view alone was never counted as "implemented". The five highest-severity defects in §7 were re-checked by hand against the code.

**Requirement source:** no document called "Coder Introduction" exists in the repository or on this machine (searched `docs/`, `~/Downloads`, `~/Documents`, `~/Desktop`, including spelling variants). The equivalent authoritative document is `~/Downloads/Understanding of DPC with Coder Instructions.pdf` (6 pages, 2026-09-25). `docs/business/DOCUMENT_INVENTORY.md` already adopts it as the Master Business Document. §3 compares **every** instruction in it. Each one is tagged with the module that owns it, so the Admin subset can be counted separately. Nothing in §3 is an invented requirement.

---

## 1. Executive summary

- **Development status 2026-10-17:** the Points 1–3 work is committed on this branch and its migrations ran on the DEVELOPMENT database only (`dpc_dev`; 87 Ran, 0 Pending, no data lost). The test suite was audited test by test and reduced from 1 571 to 1 548 executions with no essential coverage removed. See §11 and [`CODER-INSTRUCTIONS-POINTS-1-TO-3.md`](CODER-INSTRUCTIONS-POINTS-1-TO-3.md) §8–§9.
- The CGPSC Administrator panel (role `admin`) is a mature **master-data and user-administration console**:
  - 155 routes named `admin.*` behind `auth` + `EnsurePasswordChanged` + `role:admin` (`routes/web.php:562-768`)
  - 22 sidebar entries
  - 30 distinct features
- **Feature readiness:** 11 Ready, 14 Ready with limitations, 4 Not ready, 1 removed (Vacancy Types screen).
- **Vacancy Management (2026-10-17):** one Admin entry; the finalized record is the only vacancy source of new DPC proposals, read-only to the AO; results proven equal to the Working Officers chart. See [`ADMIN-VACANCY-MANAGEMENT.md`](ADMIN-VACANCY-MANAGEMENT.md).
- **Not ready:**
  - reservation policies and rosters — stored, but no engine reads them
  - master-data CSV import for departments, department posts and DPC types
  - committee-member replacement — unreachable from the UI
- **Defects to fix before UAT:**
  - ~~recruitment rules can be deleted with no guard~~ — resolved 2026-10-17: the admin screen was removed
  - ~~the DPC Types CSV template can break every Fresh DPC on MySQL~~ — fixed 2026-10-17
  - committee-member replacement cannot be reached
  - deactivating a user does not end their session
- **Against the Coder Instructions document:**
  - **Admin-owned:** 2 of 12 requirements completed (**16.7 %**), 7 partial, 2 not started, 1 blocked.
  - **All modules:** 14 of 45 completed (**31.1 %**), 12 partial, 14 not started, 4 blocked, 1 not verifiable — after the Points 1–3 work of 2026-10-17 (was 5 / 11.1 %). Detail: [`CODER-INSTRUCTIONS-POINTS-1-TO-3.md`](CODER-INSTRUCTIONS-POINTS-1-TO-3.md).
- Most of the document's rules sit with the Department's DPC proposal (CR, IPR, undertakings) and the reservation engine, not with the Admin panel.

---

## 2. Admin feature inventory

**UAT readiness:** ✅ Ready · ⚠️ Ready with limitations · ❌ Not ready.

| # | Feature | Purpose | Where | Tables | Tests | Readiness and main limitation |
|---|---|---|---|---|---|---|
| F1 | Admin dashboard | Counts across the portal and masters | `admin.dashboard`; `AdminDashboardController` | many (read) | `AdminAccessTest`, `ScreenSmokeTest` | ⚠️ master tile labels hard-coded English |
| F2 | Department master + registration status | Department catalogue; code = DPC ID prefix | `admin.departments.*`; `DepartmentController`, `DepartmentRequest` | `departments` | `DepartmentMasterTest` (9) | ⚠️ admin can set Registered/Active by hand, bypassing S6; English titles/flashes |
| F3 | **Vacancy Management** (was Vacancy Master) | One sidebar entry: Department → Post → Year, then 4 steps — sanctioned strength for the year (order + PDF) → working strength and existing / anticipated vacancies → review & finalize. Filtered, searchable list of records with year-wise history. **The finalized record is the vacancy source of every new DPC proposal**, read-only to the AO ([`ADMIN-VACANCY-MANAGEMENT.md`](ADMIN-VACANCY-MANAGEMENT.md)). | `admin.vacancy-management.*`, `admin.departments.vacancy-master.*`; `VacancyManagementController`, `VacancyMasterController`, `VacancyMasterService`, `ProposalVacancyPosition` | `vacancy_positions`, `…_sanctioned_strengths`, `…_working_strengths`, `…_entries`; `dpc_proposals.vacancy_source` | `VacancyMasterTest`, `VacancyManagementIntegrationTest` (14) | ✅ (category-wise reservation not served — V-1) |
| F4 | Department Post Mapping | Effective-dated posts, several GOs + PDFs, supersession | `admin.department-posts.*`; `DepartmentPostController`, `DepartmentPostMappingService` | `department_posts`, `department_post_orders` | `DepartmentPostMappingTest` (27) | ⚠️ `destroy` unguarded; plain edit changes an in-force mapping without a GO |
| F5 | Class master | Service classes | `admin.service-classes.*` | `service_classes` | `ClassMasterTest` (10) | ✅ |
| F6 | Pay scales | Pay-scale master | `admin.pay-scales.*` | `pay_scales` | smoke + portability only | ⚠️ unguarded delete |
| F7 | Designations | Designation master | `admin.designations.*` | `designations` | portability only | ⚠️ unguarded delete |
| F8 | Districts | District master | `admin.districts.*` | `districts` | `DistrictMasterTest` (4) | ⚠️ unguarded delete |
| F9 | Qualifications (+ unmapped report) | Qualification master | `admin.qualifications.*`; `qualifications:unmapped` CLI | `qualifications`, `rr_source_qualifications` | `QualificationMasterTest` (7) | ⚠️ unmapped report is CLI-only |
| F10 | Reservation categories | UR/SC/ST/OBC/EWS | `admin.reservation-categories.*` | `reservation_categories` | `LookupMasterTest` | ⚠️ unguarded delete; business codes editable |
| F11 | Reservation policies | Category percentages | `admin.reservation-policies.*` | `reservation_policies` | `ReservationHierarchyTest` (create) | ❌ **no engine reads it** |
| F12 | Reservation rosters + points | Roster points | `admin.reservation-rosters.*` | `reservation_rosters`, `reservation_roster_points` | `ReservationHierarchyTest` | ❌ **no engine reads it** |
| F13 | Review reasons | Reasons for a Review DPC | `admin.review-reasons.*` | `review_reasons` | none dedicated | ⚠️ **deactivating has no effect** (`ReviewProposalController.php:39` lists every reason) |
| F14 | Record reasons | Why a CR / loyalty record is missing | `admin.record-reasons.*` | `record_reasons` | `RecordReasonMasterTest` (7) | ✅ |
| F15 | ~~Vacancy types~~ — **screen removed 2026-10-17** | The engine reads four fixed codes; renaming or deleting one dropped vacancies silently. CRUD, routes, sidebar entry and CSV import removed; table and rows kept for `dpc_vacancies`. | — | `vacancy_types` (kept) | `VacancyManagementIntegrationTest::test_the_vacancy_type_screens_are_gone_and_the_rows_survive` | — |
| F16 | DPC types | Fresh / Review / Circulation | `admin.dpc-types.*` | `dpc_types` | `LookupMasterTest` | ⚠️ `'fresh'` hard-coded (`SaveDpcProposalRequest.php:99`) |
| F17 | **Promotion rules on the Class Master** + **Eligible CR Grades** | Class Master → a class → promotion into it from the same class or the one below. Each rule applies from a DPC year until a newer one, and states: years of CR / Loyalty / Property Declaration; year-wise CR passing marks and whether each year's CR, Loyalty and Property Declaration is mandatory; the qualifying (average) mark; the super-seat grade; the tribal posting bonus (0 = none). The marks come from the Eligible CR Grades master (numeric values, each valid from a date). **Settings only: the eligibility calculation does not read them yet.** | `admin.service-classes.promotion-rules.*`, `admin.cr-grades.*` | `promotion_cr_rules`, `promotion_cr_rule_years`, `cr_grade_values` | `PromotionCrRuleTest` (7) | ✅ (the CR Marks screen recorded earlier the same day was removed) |
| F18 | **DPC Committee Rules** | Members besides the Chairman, attributes the committee must cover (SC, ST, OBC, female, Bhar Sadhak Sachiv, Vibhag Sachiv), one SC/ST/OBC member, effective dates; Chairman mandatory by rule | `admin.committee-configurations.*`, `align-bounds` | `committee_configurations` | `CommitteeMasterTest`, `CommitteeBoundsReconcilerTest`, `DpcCommitteeTest` | ✅ the 9 unread fields were dropped |
| F19 | CGPSC Chairman | Effective-dated Chairman record | `admin.cgpsc-chairman.*` (no destroy) | `cgpsc_chairmen` | `CgpscChairmanTest` (15) | ✅ |
| F20 | ~~Recruitment rules~~ — **removed 2026-10-17** | The rule is the approved Pre-DPC's: approval projects an Active version (`PreDpcProposalService::projectToMasters`). The Administrator has no screen for it. Engine readers unchanged. The months-as-years projection bug was fixed at the same time. | — | `recruitment_rules`, `recruitment_rule_sources`, `rr_source_qualifications` (written by Pre-DPC approval) | `PreDpcWorkflowTest` | ✅ |
| F21 | Users | Create, edit, activate, reset password | `admin.users.*`; `UserProvisioningService` | `users`, `model_has_roles`, `user_department_assignments` | `UserManagementTest` (6) | ⚠️ **new account's password never shown** to admin; **deactivation keeps live sessions**; no last-admin guard; edit drops extra roles |
| F22 | User department assignments | Dated postings | `admin.users.assignments.*` | `user_department_assignments` | `UserDepartmentAssignmentTest` (16) | ✅ |
| F23 | Role switching | Acting-as role | `POST /acting-as` | session | `RoleSwitchTest` (14) | ⚠️ switcher not in the admin layout |
| F24 | Notice board | Portal notices | `admin.portal-notices.*` | `portal_notices` | `PortalNoticeTest` (10) | ✅ |
| F25 | Master-data CSV import / export / template | Bulk masters | `admin.master-data.*`; `MasterDataPortabilityService` | 12 masters | `MasterDataPortabilityTest` (14), `DpcTypeTemplateCodeTest` (4) | ❌ for departments, department posts: import skips FormRequest validation; department round-trip rejected; post import can update a superseded row; no CSV-formula neutralisation. (The `FRESH`/`fresh` collision was fixed 2026-10-17.) |
| F26 | Audit trail | List, detail, CSV export | `admin.audit-trail.*` | `audits`, `activity_timelines`, `workflow_transitions` | `AuditTrailScreenTest` (20) | ✅ (not in the menu) |
| F27 | Integration status | Feature flags and providers | `admin.integrations` | — | `FeatureFlagTest` | ✅ |
| F28 | Committee member replacement | Replace a member at any stage | `admin.committee-members.replace*` | `dpc_committee_members` | `CommitteeReplacementTest` (16) | ❌ **unreachable**: the only link is on `dpc-proposals.committee`, a page gated to AO/AH. The success redirect also sends admin to that page (403). |
| F29 | Sanctioning orders (Pre-DPC links) | Link DPCs to Pre-DPCs | `admin.pre-dpc-links.*` | `dpc_proposals`, `pre_dpc_proposals` | `PreDpcLinkReconcilerTest` (7) | ✅ |
| F30 | Reports (shared) | Pipeline, outcomes, CSV | `reports.index` | read-only | report tests | ⚠️ reachable only from the admin dashboard |

**Navigation** (`resources/views/layouts/admin.blade.php`, 22 entries): Dashboard, Departments, Districts, Designations, Pay Scales, Department Posts, Class Master, Qualifications, Reservation Policies, Reservation Rosters, CGPSC Chairman, DPC Committee Rules, Eligible CR Grades, User Management, Reservation Categories, DPC Types, Review Reasons, Record Reasons, Notice Board, Sanctioning orders.

**Reached without a menu entry:**
- Audit Trail, Integration Status and Reports — dashboard buttons
- Vacancy Management also from a department's row and edit page (its own sidebar entry since 2026-10-17)
- User Assignments — user row
- Master-data import/export — a toolbar on 9 index screens
- Committee replacement — no entry point at all

---

## 3. Coder Instructions — requirement-by-requirement comparison

**Status:** C = COMPLETED · P = PARTIALLY COMPLETED · N = NOT STARTED · B = BLOCKED · V = NOT VERIFIABLE.

**Module:**
- **ADMIN** — CGPSC Administrator masters and configuration
- **DEPT** — Department DPC proposal
- **COMM** — Commission sitting and orders
- **ENGINE** — calculation engines

| ID | Requirement (rule) | Module | Status | Code evidence | Test evidence | Missing | Next action |
|---|---|---|---|---|---|---|---|
| CI-01 | Before a DPC, confirm the vacancy setup against the setup approval, RR and gradation list (1.1) | DEPT | C | `ProposalVerificationService`; `DpcProposalCompletenessService` section `rule_1_1_verification` | `ProposalVerificationTest` (22) | — | UAT |
| CI-02 | Reconcile filled posts with the previous DPC minutes (1.1) | DEPT | C | previous-DPC basis in_system / offline (minutes PDF) / none | `ProposalVerificationTest` offline / in-system tests | — | UAT |
| CI-03 | Commission staff account enters an offline previous DPC in full (1.1) | ADMIN | N | — | — | whole feature | Decide BD-62 (role, mandatory parts) |
| CI-04 | Seniority / working uploads optional if fewer than 50 eligible (1.1) | DEPT | B | — | — | scope undefined | Decide BD-59 |
| CI-05 | Higher-post promotion vacancy only if the order is issued, or the proposal is received and validated (1.2) | ADMIN | P | `VacancyMasterService::verifyEntry` (two bases, explicit verification) | `VacancyMasterTest::…higher_post…` | not fed into the proposal's own vacancy; Hindi / English wording differ | Decide which condition governs; decide how proposals consume it |
| CI-06 | Undertaking and higher-post DPC proposal number mandatory (1.2) | ADMIN | P | `higher_dpc_reference` required, linked when in portal | same | no undertaking | Add an undertaking with the verification |
| CI-07 | Rounding: ≥ .5 up; DR/promotion .5 tie goes to promotion (1.3) | ADMIN | C | `config/dpc.php` `promotion_rounding=round`; `VacancyEngine::quotaPosition` | `VacancyMasterTest::test_rounding_follows_rule_one_three…`; `DpcWorkingOfficersTest` | — | Confirm BD-33/58 closed |
| CI-08 | Proposals before 2019 closed / separate module (1.4) | ADMIN | B | Vacancy Master `earliest_year=2019`; Pre-DPC accepts ≥ 2000 | — | Hindi says "before 2019", English "for 2019"; also "reservation ended 2019" | Business clarification |
| CI-09 | Proposal effective from the first day of the year (1.5) | DEPT | N | `proposal_date = today` | — | effective date | Implement after confirmation |
| CI-10 | Vacancies reckoned to 31 December (1.5) | ENGINE | C | `dpc.vacancy.period` 1-1 → 12-31; `VacancyEngine::periodForYear` | `VacancyMasterTest::test_the_vacancy_year_is…`; `DpcWorkingOfficersTest::…outside_the_period…` | — | UAT |
| CI-11 | Final seniority list as on 1 April of the preceding year (1.6) | DEPT | C | cutoff from the DPC year only (`DpcProposal::seniorityReferenceDate`); "as on" date stated on upload/save, refused unless equal, stored (`dpc_proposals.seniority_list_as_on`), checked again at the submission gate | `SimplifiedSeniorityListTest` | — | UAT (2026-10-17, see `CODER-INSTRUCTIONS-POINTS-1-TO-3.md`) |
| CI-12 | PH 3 % of the promotion quota (2.1) | ADMIN | P | percentage and rounding are a DPC Rules version from a DPC year (Admin → CR Marks & PH Rules); quota computed and shown on Employee Details (`EmployeeDetailsService::phQuota`). Category policies and rosters are inactive: PH is the only reservation in force | `EmployeeDetailsTest::test_the_ph_quota_is_three_percent…` | how a PH seat is placed on the Main/Waiting lists | Decide D-6 |
| CI-13 | PH availed once; separate record; check previous benefit (2.1) | DEPT | P | AO questionnaire Q1→Q2→Q3; separate PH / previous benefit / choice / undertaking / verification / decision; earlier PH records of the same Employee ID shown | `EmployeeDetailsTest` cases 1–4 | who verifies and decides | Decide D-5 |
| CI-14 | PH computed with floor (2.1) | ENGINE | C | integer floor in `phQuota` | quota test (33 → 0, 67 → 2) | display only (D-6) | — |
| CI-15 | Sealed cover / DE / chargesheet / SCN / suspension rules (2.2) | COMM | N | free-text note only (BD-45/48) | — | whole feature | Decide sealed-cover process |
| CI-16 | Deputation date mandatory; validate deputation vacancy (> 1 year) (2.3) | DEPT | P | Vacancy Master applies rule 2.3; Working Officers chart date optional, one-day overlap counts | `VacancyMasterTest::test_deputation_follows_rule_two_three` | chart rule differs | Align the chart with rule 2.3 |
| CI-17 | Deputation without sanctioned setup excluded from the seniority list (2.3) | DEPT | N | — | — | — | Add field + check |
| CI-18 | Up to 4 CRs a year, average truncated (3.1) | DEPT | C | reports per year; final grade per report; average on the configured marks, decimal dropped (`EmployeeDetailsService::yearGrade`) | `EmployeeDetailsTest` | scale conflict D-3 | — |
| CI-19 | CR entry flow: put up? / grading? / previous CR / ungraded upload (3.1) | DEPT | P | put-up and grading questions; ungraded CR upload required; not-put-up needs the missing-record justification | `EmployeeDetailsTest` | "take previous year CR" not applied | Decide D-2 |
| CI-20 | Reporter / Reviewer / Accepting designation + remark; fallback; NRC if any missing (3.1) | DEPT | C | `finalGrade`, `reportNeedsNrc`, NRC upload required | `EmployeeDetailsTest` | — | UAT |
| CI-21 | CR channel undertaking + channel document (3.1) | DEPT | C | `confirmChannel` (undertaking + document together) | `EmployeeDetailsTest` | — | UAT |
| CI-22 | Missing CR: NRC / long leave / suspension, each with its own document (3.2) | ADMIN | P | the three reasons added to `record_reasons`; reason + PDF required per not-put-up year | `EmployeeDetailsTest`, `RecordReasonMasterTest`, `DpcRecordGapTest` | "out of 4" names three; document type not tied to the reason | Decide D-4 |
| CI-23 | CR marks 9/7/5/3/1, configurable via Master data (3.4) | ADMIN | C | any scale is recorded as a DPC Rules version from a DPC year, with its order and PDF; the calculation and the multi-CR average use the version of the proposal's year (`DpcRuleBook`) | `DpcRulesTest`, `DpcCalculationTest::test_the_cr_scale_in_force…` | open: on 9/7/5/3/1 an average such as 8 is no grade's mark (D-3) | Decide D-3 averaging |
| CI-24 | Old grading intervals via Master data (3.5) | ADMIN | C | qualifying score per class pair (and "other promotions") in the same DPC Rules version | `DpcRulesTest`, eligibility golden tests | — | — |
| CI-25 | Every IPR uploaded (4.1) | DEPT | C | IPR document required for each submitted year | `EmployeeDetailsTest` | — | UAT |
| CI-26 | Late IPR accepted only with an uploaded department decision (4.1) | DEPT | C | after 31 Jan of the next year: decision required; accepted needs reason + document; rejected does not count | `EmployeeDetailsTest` | — | UAT |
| CI-27 | Next increment date captured; duplicate IPR check (4.1) | DEPT | C | date required; same date or same file on two years refused | `EmployeeDetailsTest` | — | UAT |
| CI-28 | Previous DPC minutes uploaded; separate proposal per year (4.3) | DEPT | P | Rule 1.1 offline minutes; one `dpc_year` per proposal | `ProposalVerificationTest` | no retrospective DPC type | with CI-03 |
| CI-29 | Online DPC order; link by SMS and mail to officers and committee; order date (4.3) | COMM | N | no order entity; SMS/mail are mocks | — | order + gateways | Decide; needs gateways |
| CI-30 | Seniority from the date of the DPC order (4.3) | COMM | N | "promotion as on" year only | — | — | with CI-29 |
| CI-31 | Every entered and calculated data matches some version (4.4) | ENGINE | V | revisions, calculation snapshots, fingerprints exist | — | the requirement is not specific enough to test | Clarify |
| CI-32 | Working officers count entered and matching the uploaded list 100 % (4.4) | DEPT | P | new proposals: the Administrator's finalized working strength (Vacancy Management) is the count; older proposals: derived from the chart | `VacancyManagementIntegrationTest` | no named list is collected for new proposals (V-2) | Decide whether the AO uploads the list as evidence |
| CI-33 | DOB / joining order number + date + undertaking (4.4) | DEPT | N | dates only | — | — | — |
| CI-34 | Complaints / representations uploaded + undertaking (4.4) | DEPT | N | — | — | — | — |
| CI-35 | "Refused promotion on same post" field; refusal letter; last-year refusal → unfit (4.4) | DEPT | N | — | — | — | — |
| CI-36 | No-duplicate-CR undertaking at every CR upload (4.4) | DEPT | C | upload refused without the undertaking; stored on the document | `EmployeeDetailsTest` | — | UAT |
| CI-37 | Originals in the Mul folder undertaking (4.4) | DEPT | N | — | — | — | — |
| CI-38 | % of available posts by promotion from multiple cadres (4.4) | ADMIN | P | feeder share captured on Pre-DPC and RR; must total 100 % | `RecruitmentRuleIntegrityTest` | share not applied to the vacancy | Apply per-cadre split |
| CI-39 | Each cadre vertical (no transfer of an empty cadre's share) (4.4) | ENGINE | N | — | — | — | with CI-38 |
| CI-40 | Minutes only with consent; any dissent blocks (4.4) | COMM | B | — | — | whose consent is unclear | Business clarification |
| CI-41 | Qualification validated against Schedule 4 (4.4) | ADMIN | P | per-feeder qualification; gate `advise` by default (BD-39) | `CandidateGateServiceTest` | qualification mapping | Supply the degree→level mapping, then set `reject` |
| CI-42 | Fields "Experience required on post" / "Total years of experience" (4.4) | ADMIN | C | RR / Pre-DPC `min_experience_years` + unit; `promotion_duration_years`; enforced in `CandidateGateService` | `CandidateGateServiceTest::test_an_incomplete_promotion_duration_is_a_rule_failure` | — | UAT |
| CI-43 | Undertaking: officer meets RR clauses 8–11 (4.4) | DEPT | N | — | — | — | — |
| CI-44 | Latest RR notification date + undertaking (4.4) | ADMIN | N | GO number and effective date only | — | notification date | Add the field to the RR master |
| CI-45 | Two verifications for every uploaded document (4.4) | DEPT | N | AO submit + AH approve per proposal only | — | per-document verification | — |

---

## 4. Completed features

**Admin panel features — Ready (11):**
- F3 Vacancy Management (single vacancy source for new proposals)
- F17 DPC Rules (CR marks, qualifying scores, PH reservation)
- F18 DPC Committee Rules
- F5 Class master
- F14 Record reasons
- F19 CGPSC Chairman
- F22 User assignments
- F24 Notice board
- F26 Audit trail
- F27 Integration status
- F29 Sanctioning orders

**Coder Instructions — Completed (16):** CI-01, CI-02, CI-07, CI-10, CI-11, CI-14, CI-18, CI-20, CI-21, CI-23, CI-24, CI-25, CI-26, CI-27, CI-36, CI-42.

## 5. Partially implemented

**Admin panel features — Ready with limitations (14):** F1, F2, F4, F6, F7, F8, F9, F10, F13, F16, F20, F21, F23, F30.

**Admin panel features — Removed (1):** F15 Vacancy types (screen only; table kept), 2026-10-17.

**Coder Instructions — Partial (11):** CI-05, CI-06, CI-12, CI-13, CI-16, CI-19, CI-22, CI-28, CI-32, CI-38, CI-41.

## 6. Pending

**Admin panel features — Not ready (4):** F11, F12, F25 (departments / department posts / DPC types), F28.

**Coder Instructions — Not started (14):** CI-03, 09, 15, 17, 29, 30, 33, 34, 35, 37, 39, 43, 44, 45.

**Coder Instructions — Blocked (3):** CI-04, CI-08, CI-40. **Not verifiable (1):** CI-31.

## 7. Known bugs and technical risks

Ordered by severity. Items 1–5 re-verified by hand on 2026-10-17.

1. ~~**Recruitment rule delete has no status guard.**~~ Resolved 2026-10-17: the admin screen, and its delete, were removed.
   - Where: `RecruitmentRuleController.php:95-100` → `RecruitmentRuleService.php:108-112`.
   - Effect: an Active rule pinned by a proposal can be soft-deleted. The proposal then resolves its rule to null, because the soft-deleted row is excluded from lookups.
2. ~~**DPC Types CSV template breaks Fresh DPCs.**~~ **Fixed 2026-10-17.**
   - Cause was: the template's sample code `FRESH` (`MasterRegistry`), the seeded code `fresh`, and the proposal check `$type->code !== 'fresh'` (`SaveDpcProposalRequest`). On case-insensitive MySQL the import renamed the row; on SQLite it added a duplicate.
   - Fix: the sample uses `fresh`; `MasterDataPortabilityService::import` matches the unique key case-insensitively on every database and never rewrites the matched key; the Fresh check is case-insensitive. Test: `DpcTypeTemplateCodeTest`.
3. **Committee member replacement is unreachable.**
   - The only link sits on `dpc-proposals.committee`, which is gated `role:authorized_officer|administrative_head` (`sections/committee.blade.php:79`).
   - The success redirect also lands admin on that page, which returns 403.
4. **New user's password is never shown to admin.**
   - `UserController.php:42-48` only flashes "Credentials have been issued", and SMS/mail are mocks.
   - Workaround: press Reset password after creating the account.
5. **Deactivating a review reason does nothing.** `ReviewProposalController.php:39` lists every reason with no status filter.
6. Deactivating a user does not end their sessions. `is_active` is checked only at login, and there is no last-admin / self-deactivation guard.
7. Master-data CSV import bypasses FormRequest validation:
   - the department round-trip is rejected;
   - department-post import can modify a superseded row and records no GO;
   - exports do not neutralise spreadsheet formulas.
8. Unguarded soft deletes on most masters, with no restore screen. Unique rules do not exclude trashed rows, so a deleted code cannot be reused.
9. Business logic keys on master codes that admin can edit: `fresh`, `dpc_committee`. (The vacancy-type codes are no longer editable: their screen was removed 2026-10-17.)
10. Dead masters and fields:
    - `reservation_policies`, `reservation_rosters` and `reservation_roster_points` — read by no engine;
    - 9 of 11 committee-configuration fields.
11. Bilingual gaps:
    - ~52 hard-coded English flash messages in 18 admin controllers;
    - "Edit / New …" titles in 14 admin forms;
    - dashboard master labels;
    - the admin layout has no language switch, no role switcher and no Reports link;
    - audit-trail and integrations pages drop the admin sidebar.
12. Several Active recruitment-rule versions can coexist, and two "current rule" lookups disagree:
    - `VacancyEngine::recruitmentRuleFor` filters by date;
    - `DpcProposalService::currentRuleFor` takes the highest Active version.

## 8. Recommended development priorities

1. **Before UAT (small, high value):**
   - Guard recruitment-rule delete (Draft only).
   - Fix the DPC Types template code, or compare codes case-sensitively.
   - Give committee replacement an admin entry point, and fix its redirect.
   - Show the provisioned password once, as reset-password already does.
   - Filter inactive review reasons.
   - Invalidate sessions on deactivation.
2. **Master-data integrity:**
   - reference guards on master deletes;
   - restore, or unique rules that ignore trashed rows;
   - single-Active-version rule for recruitment rules;
   - CSV import through the FormRequests.
3. **Business decisions that unblock most of §3:**
   - CR model (BD-50…58)
   - PH and sealed-cover workflows
   - BD-59, BD-62
   - the 2019 wording
4. **Then the Department-side rule set:** CR capture, IPR documents, undertakings, refusal field, document verification.
5. **Wire or retire** the reservation policy and roster masters.

## 9. Totals

| Measure | All Coder Instructions | Admin-owned subset |
|---|---|---|
| Total requirements | **45** | **12** (CI-03, 05, 06, 07, 12, 22, 23, 24, 38, 41, 42, 44) |
| Completed | 14 | 2 |
| Partially completed | 12 | 7 |
| Not started | 14 | 2 |
| Blocked | 4 | 1 |
| Not verifiable | 1 | 0 |
| **Completion % (completed only)** | **31.1 %** (was 11.1 %) | **16.7 %** |

Points 1–3 only (CI-01 … CI-24): 11 completed of 24 (**45.8 %**, was 16.7 %). Directly related CR / Property / Seniority items outside them (CI-25, 26, 27, 33, 36): 4 of 5 (**80 %**). See [`CODER-INSTRUCTIONS-POINTS-1-TO-3.md`](CODER-INSTRUCTIONS-POINTS-1-TO-3.md) §6.

| Admin panel features | Count |
|---|---|
| Ready | 11 |
| Ready with limitations | 14 |
| Not ready | 4 |
| Removed | 1 |
| **Total** | **30** |

## 10. Evidence references

- **Routes:** `routes/web.php:562-768` (admin group), `:604-619` (Vacancy Master), `:705-712` (committee replacement), `:296` (`dpc-proposals.committee`).
- **Navigation:** `resources/views/layouts/admin.blade.php:44-86`.
- **Controllers:** `app/Http/Controllers/Admin/*.php`; `app/Http/Controllers/Dpc/DpcCommitteeMemberController.php`; `app/Http/Controllers/Dpc/ReviewProposalController.php:39`.
- **Services:** `app/Domain/Master/Services/{RecruitmentRuleService,DepartmentPostMappingService,MasterDataPortabilityService}.php`; `app/Domain/UserManagement/Services/UserProvisioningService.php`; `app/Domain/Dpc/Services/{VacancyMasterService,VacancyEngine,ProposalVerificationService}.php`; `app/Domain/Committee/Services/*`.
- **Requests:** `app/Domain/Dpc/Requests/SaveDpcProposalRequest.php:99`; `app/Domain/Master/Support/MasterRegistry.php:49`.
- **Tests:** `tests/Feature/Admin/*` (incl. `VacancyMasterTest`), `tests/Feature/Dpc/ProposalVerificationTest.php`, `tests/Feature/Committee/*`, `tests/Feature/Audit/AuditTrailScreenTest.php`, `tests/Unit/Eligibility/CandidateGateServiceTest.php`, `tests/Feature/AuthorizationCoverageTest.php`, `tests/Feature/ScreenSmokeTest.php`.
- **Documentation:**
  - `docs/business/CURRENT_SYSTEM_VS_LATEST_DPC_BUSINESS_DOCUMENT.md`
  - `docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` (BD-33…62)
  - `docs/CR_UPLOAD_AND_NUMBER_SYSTEM_REVIEW.md`
  - `docs/business/DOCUMENT_INVENTORY.md`
  - source PDF `~/Downloads/Understanding of DPC with Coder Instructions.pdf`

## 11. Test suite and development database (2026-10-17)

**Test suite.** Every test method (1 511) was classified A essential / B duplicate / C obsolete / D low-value / E uncertain:
A 1 471 · B 15 · C 0 · D 11 · E 14. Only verified duplicates and clearly low-value tests were removed — 23 methods —
and 9 near-identical methods were folded into 3 data-provider tests without dropping a case. Five weak tests were
strengthened instead of removed. Executions: **1 571 → 1 548**. All Admin, authorization, Recruitment Rule, Vacancy Master,
master-data and security tests in this document's evidence column are unchanged except
`RecruitmentRuleIntegrityTest` (one duplicate removed, three qualification cases consolidated) and
`DepartmentPostMappingTest` (two duplicates removed, their assertions kept in the surviving tests). Full list with reasons:
[`CODER-INSTRUCTIONS-POINTS-1-TO-3.md`](CODER-INSTRUCTIONS-POINTS-1-TO-3.md) §8.

**Development database.** `dpc_dev` (verified by name and `APP_ENV=local` before running; backed up first) received the
three pending, additive migrations: Rule 1.1 verifications, the Vacancy Master tables, and the employee-details
migration. Every pre-existing table kept its row count; `record_reasons` gained the three rule-3.2 reasons. Detail:
[`CODER-INSTRUCTIONS-POINTS-1-TO-3.md`](CODER-INSTRUCTIONS-POINTS-1-TO-3.md) §9. UAT and Production were not touched.

**Still open (unchanged by this work):** the business decisions D-1…D-9 in `CODER-INSTRUCTIONS-POINTS-1-TO-3.md` §3,
the Admin defects in §7 above, and the UAT data-cleanup command, which remains a design only (`docs/UAT-DATA-CLEANUP.md`,
not committed with this work).

## Reservation in promotion (2026-10-17)

There is no category-wise reservation in promotion today, only PH (rule 2.1).

- **Reservation Categories:** PH is active. UR, SC, ST, OBC and EWS are kept, inactive (migration 500001).
- **The PH Reservation Policy** carries the PH quota, the rounding of a decimal part (`floor` = rule 2.1, or `ceil`), the dates it is in force, and an optional PDF. `PhReservationPolicy` reads it, with the proposal date deciding which policy applies.
- **`dpc.reservation.category_wise` = false.** The AO screens ask for no category: Working Officers, Seniority List and Employee Details. Every row joins one pool (Unreserved), and the engine draws one Main / Waiting list. Turning the switch on restores category-wise lists. The test suite runs with it on, and the single-pool behaviour has its own tests.
- **Still open: D-6.** How a PH seat sits on the Main / Waiting list is not decided.
