# CGPSC e-DPC Portal — Full Implementation Audit

**Audit date:** 2026-08-28
**Auditor method:** source code, database schema, and executed quality gate. **No status below was taken from documentation.** Where a document is cited, it is cited as the *subject* of the audit, not as evidence.
**Repository state audited:** branch `feature/m0-setup`, HEAD `71761a7`, **plus the uncommitted working tree** (422 changed paths, 147 untracked — see §8.7). The working tree is what runs; it is what was audited.

---

## Re-verification — 2026-09-07

The audit below stands as written on 2026-08-28. This section records a re-run of the executable checks on the tree as it is today, so the report is not read as older than it is. **Nothing in the audit was downgraded.**

| Check | Result today |
|---|---|
| `pint --test` | passed |
| `phpstan analyse` | No errors |
| `php artisan test` | **1447 passed**, 2 skipped (both deliberate, `MasterDataPortabilityTest:173`), 4811 assertions |
| `composer audit` | No advisories (local cache; host offline) |
| `schema:verify-mysql` (TD-24) | **84 migrations, 79 tables** built on real MySQL 8.0, clean |
| `lang:check` | catalogue complete, 0 missing either way |
| `route:list` / `view:cache` | 281 routes register; every Blade template compiles |
| Migration additive-only ruling | held — 0 existing migration files modified, 30 new files |

**Working-tree size has grown since the audit**, and §8.7 should now be read with these numbers: **303 tracked files changed, 190 untracked, +20,504 / −6,482** against HEAD `71761a7`. It remains uncommitted, and that is the single highest-exposure item in the project today — the gate is green on precisely this tree and none of it is in git.

Two further gaps surfaced that the 2026-08-28 audit does not cover:

- **A stale rename in the git index** — `RD public/js/forms/pre-dpc-posts.js -> public/js/forms/pre-dpc-posts.js.tmp`. The working tree is correct (the file is `pre-dpc-exercise.js` and is wired at `resources/views/pre-dpc/form.blade.php:262`); only the index holds the `.tmp` intermediate, which `git commit` would otherwise record into history. Re-stage the path before committing.
- **No `package-lock.json`** — `npm audit` fails with `ENOLOCK`, so the frontend dependency audit is not passing but *absent*, and the asset build is not reproducible. The file is not gitignored; it has never been committed.

`security:evidence-grants --verify` reports 26 wrong probes. **This is the expected result here and not a regression**: the probe runs as the connecting user, which on this host is `root`, while the grants are written for `odpc_app`. It remains blocker **B-3**, and it means the TD-1 control has never been demonstrated on any host — the first UAT host with `odpc_app` is where that evidence must be captured.

Full detail: `docs/worklog/2026-09-07-health-check.md`.

---

## 0. How to read this report

Three statuses are used, and they mean exactly this:

| Status | Meaning |
|---|---|
| ✅ **Implemented** | A code path exists that produces the required outcome, and I read it. Route → controller → service → persistence, or the equivalent. |
| ⚠️ **Partially Implemented** | The mechanism exists but does not reach the whole requirement — a missing screen, a disabled rule, an unwired endpoint, or a narrower model than the requirement states. The gap is named. |
| ❌ **Not Implemented** | No code path produces the outcome. Grep-verified absent. |

A fourth marker, ⭐ **Implemented Differently**, is used where the deck names one mechanism and the code delivers the business outcome by another. This is not a defect on its own, but it *is* a deviation and is listed as one.

**Nothing is marked Implemented on the strength of a docblock.** Several classes in this codebase carry very long explanatory comments; every one that mattered to a verdict was checked against the executable statements below it.

### Verified baseline facts

| Fact | Value | How verified |
|---|---|---|
| PHP / Laravel | 8.4.24 / 13.23.0 | `php -v`, `php artisan --version` |
| Application code | 344 PHP files, 43,745 lines | `find app -name '*.php'` |
| Domains | 19 (`app/Domain/*`) | directory listing |
| Controllers | 56 | `find app/Http/Controllers` |
| Models / Services / Form Requests / Policies | 62 / 71 / 51 / 5 | directory listing |
| Enums / Events / Listeners / Console commands | 39 / 5 / 8 / 10 | directory listing |
| Routes | 270 | `php artisan route:list --json` |
| Blade views | 145 (12,919 lines) | `find resources/views` |
| Front-end scripts | 13 files, no bundler, no CDN | `public/js/**` |
| Migrations → tables | **79 → 78** | `migrate:status`, `schema:verify-mysql` |
| Foreign keys / unique constraints / index entries | **199 / 87 / 393** | `information_schema` on `dpc_dev` |
| Language keys | 1,719 in `en`, 1,719 in `hi` (99.4% genuinely distinct) | recursive count + string comparison |
| Test classes / tests / assertions | **101 / 1,287 / 4,228** | `php artisan test` |

### Quality gate — executed during this audit, not quoted

| # | Check | Result |
|---|---|---|
| 1 | `vendor/bin/pint --test` | **pass** |
| 2 | `vendor/bin/phpstan analyse` (Larastan, **level 6**, no baseline, no ignores) | **`[OK] No errors`** |
| 3 | `php artisan security:audit` (`composer audit`) | **No security advisories** |
| 4 | `php artisan schema:verify-mysql` (builds every migration on real MySQL in a scratch DB) | **79 migrations, 78 tables — built clean** |
| 5 | `php artisan test` | **1,287 passed, 4,228 assertions, 254s** |

I additionally built the schema fresh on MySQL and diffed the table set against the incrementally-migrated `dpc_dev`: **identical, zero drift in either direction.**

---

# 1. Business Requirements Verification

Requirements are drawn from the two decks the codebase cites as authority (`docs/ODPC_NEW_FINAL.pptx` S1–S52; `docs/CONVENTIONAL DPC PROCESS.pptx` C1–C7) and from the three change registers of 2026-08-24, -26 and -27. **Ninety-four requirements** are assessed.

## 1.1 Authentication, registration and identity

| # | Requirement | Status | Evidence | Comments |
|---|---|---|---|---|
| R1 | Department registers itself; captures department, district, place, PIN, AH and AO name/designation/mobile/email (S5–S6) | ✅ | `Auth\RegistrationController@create/store`; `RegisterDepartmentRequest`; `RegistrationService`; `resources/views/auth/register.blade.php`; `GET|POST /register` | All fields present. `pin` = `digits:6`, mobiles `digits:10` + unique, all three emails forced to `.gov.in` by regex and unique |
| R2 | AO/AH receive IDs and passwords by mobile/email (S5) | ⚠️ | `CredentialsIssued` event → `SendCredentialNotification`; `SmsSender` contract | Credentials **are** issued and dispatched; the transport is `LogSmsSender` (writes to the log). No gateway is procured — blocker B-1. Not a code gap |
| R3 | Login by **Aadhaar number + Aadhaar OTP** (S8, S10, S13, S20, S27, S29, S32, S36, S39, S42, S46) | ❌ / ⭐ | `Auth\LoginController@store`; `LoginRequest` | Grep for "aadhaar" across `app/ config/ routes/ resources/ database/` returns **8 hits, all comments**. Login is `email │ 10-digit mobile │ employee_number` + password. This is a deliberate, documented deviation on **eleven** slides |
| R4 | DPC ID used as a login credential for Chairman/Member (S32, S42) | ❌ | `LoginController@store` match block | DPC IDs are selection keys on screens, never credentials |
| R5 | Email + mobile OTP verification before first use | ✅ | `VerificationController`; `OtpService`; `OtpVerification` model; `throttle:otp-verify`; `tests/Feature/Auth/OtpVerificationTest.php` | Beyond the deck. Config-driven length/TTL/attempts |
| R6 | Forced password change on a system-issued password | ✅ | `EnsurePasswordChanged` middleware wraps every application route; `PasswordChangeController`; `PasswordChangeTest` | |
| R7 | Brute-force protection on login and OTP | ✅ | `AppServiceProvider::configureRateLimiting()`; `throttle:login`, `throttle:otp-verify`, `throttle:calculate`, `throttle:self-inspection`; `RateLimitingTest` | Four named limiters, all config-driven |
| R8 | One account may hold several roles and switch between them (register 2026-08-26 item 9f) | ⚠️ | `ActiveRoleService`; `ResolveActiveRole` middleware; `RoleSwitchController`; `POST /acting-as`; `RoleSwitchTest` | Switching works and the active role *narrows* the session — an excellent design. **But**: `user_department_assignments` carries `unique(user_id, active_flag)` and `DepartmentAssignmentService` calls `removeRole()` on the other departmental roles when projecting. So an account **cannot** simultaneously hold AO and AH and Promotion Candidate — only one departmental role at a time, plus Commission grants. The register's wording (quoted verbatim in `CommitteeMemberDirectory`) says otherwise. **Needs a business ruling or a schema change** |
| R9 | Single CGPSC Administrator; no Department Administrator | ✅ | `App\Enums\Role` — six + `PromotionCandidate`; `Role::Admin` labelled "CGPSC Administrator"; `Role::departmental()` excludes Admin; every `/admin/*` route behind `role:admin` | |
| R10 | Users never belong permanently to a department; assignment is dated; transfer = deactivate + create; never delete | ✅ | `user_department_assignments` (role, effective_from, effective_to, active_flag, remarks, blameable); `DepartmentAssignmentService`; `UserAssignmentController` (**no destroy route by design**); `UserDepartmentAssignmentTest` | `User::getDepartmentIdAttribute()` reads through the active assignment |
| R11 | Promotion Candidate login for every officer named on a Seniority List | ✅ | `PromotionCandidateProvisioner::provision()` called from `DpcProposalService` on seniority save; `CandidateDpcController` (2 routes only); `PromotionCandidateTest` | Account keyed on `employee_number`; existing accounts reused |
| R12 | A candidate sees own DPC, status, timeline, notifications, published result — and nothing else | ✅ | `CandidateDpcController@index/show`; `{dpcProposal}` deliberately **not** route-model-bound, resolved only from the candidate's own set → unrelated UUID is 404, not 403 | Marks, remarks and other officers' rows are stripped in the controller |
| R13 | Password reissue for a candidate who has no mobile or email | ✅ | `PromotionCandidateProvisioner::reissuePassword()`; `POST /dpc-proposals/{p}/candidates/reissue-password` | Guarded by the proposal's own Seniority List, not by role |

## 1.2 Master data (CGPSC Administrator)

| # | Requirement | Status | Evidence | Comments |
|---|---|---|---|---|
| R14 | Department Master | ✅ | `Admin\DepartmentController` (resource, no `show`); `DepartmentRequest`; `admin/departments/*` | |
| R15 | District, Designation, Pay Scale, Class, Reservation Category, Vacancy Type, DPC Type, Committee Type, Review Reason masters | ✅ | 9 `Admin\*Controller` resources; `admin/partials/lookup-{index,form}.blade.php` drive four of them generically by `$routeBase` | |
| R16 | Class Master, administrable (register 2026-08-24 item 1) | ✅ | `ClassMaster` model + `ClassMasterController` + `service_classes` table; `ClassLabelRepository` bound `scoped()`; `ClassMasterTest` | Route named `service-classes`, parameter `classMaster` |
| R17 | "Cadre" terminology eliminated; the term is **Post** | ✅ | Migration `360001` renames `cadres` → `department_posts`; `Cadre.php` → `DepartmentPost.php`; **zero occurrences of "cadre" in `app/`, `resources/`, `routes/`, `config/` or the five live handbooks** | Still present in the four stale root `PPT_*.md` files — see §10 |
| R18 | Department Post Mapping: Department → Post → Pay Scale → Class → Promotion Duration → Sanctioned Strength → Active | ✅ | `department_posts` + `sanctioned_strength` (migration `410001`); `DepartmentPostRequest`; `DepartmentPostController` (13 methods, includes `show`) | |
| R19 | The mapping is effective-dated and authorised by a Government Order | ✅ | Migration `380001`; `unique(department_id, name, active_flag)`; `DepartmentPost::inForce()` scope; `supersedeForm`/`supersede` routes; `SupersedeDepartmentPostRequest` | A superseded mapping stays on record and cannot be named on a new Pre-DPC |
| R20 | **Multiple Government Orders per mapping**, each with at most one PDF (register 2026-08-26 item 10) | ✅ | `department_post_orders` table (migration `400005`, `unique(department_post_id, government_order_number)`); `DepartmentPostOrder` model; `orders.*.document` validation; `public/js/forms/department-post-orders.js`; download route by `{order}` UUID; `DepartmentPostMappingTest` | Files streamed from the **private** disk, never linked |
| R21 | Recruitment Rules, versioned, never overwritten, with the GO PDF | ✅ | `RecruitmentRuleService::createVersion()/newVersion()`; `unique(post_id, version)`; `recruitment-rules/{r}/version` and `/download`; `RecruitmentRuleTest`, `RecruitmentRuleIntegrityTest` | A clone carries its qualification list forward |
| R22 | Minimum qualification chosen from a controlled list, not free text | ✅ | `qualifications` table; `QualificationController`; `QualificationCatalogue`; `Qualification::selectable()` | |
| R23 | **Multiple qualifications** per promotion source (register 2026-08-26 item 11) | ✅ | `rr_source_qualifications`, `pre_dpc_source_qualifications` pivots (migration `400006`) | |
| R24 | Reservation policy and roster with points | ✅ | `reservation_policies`, `reservation_rosters`, `reservation_roster_points`; `ReservationRosterPointController`; `ReservationHierarchyTest` | |
| R25 | Committee Configuration master (bounds per committee type) | ✅ | `committee_configurations`; `CommitteeConfigurationController` + `alignBounds`; `CommitteeRuleResolver` reads master first, config as fallback; `CommitteeBoundsReconcilerTest` | |
| R26 | **CGPSC Chairman as an administered, effective-dated record** (register 2026-08-26 item 9b) | ✅ | `cgpsc_chairmen` table with `unique(active_flag)`; `CgpscChairmanController` (**no destroy** — a Chairman is succeeded, not deleted); `CgpscChairmanService`; `CgpscChairmanTest` | Auditable + blameable + timeline |
| R27 | **Record Reason master** — why a mandatory CR/Loyalty record is missing (register 2026-08-27 item 16) | ✅ | `record_reasons` table; `RecordReasonController`; `requires_remarks` flag enforced in `RecordGapRequest`; `RecordReasonMasterTest` | |
| R28 | Portal notice board as data | ✅ | `portal_notices`; `PortalNoticeController`; `PortalNoticeComposer` with catalogue fallback; `PortalNoticeTest` | |
| R29 | Commission Members master | ❌ *(withdrawn)* | Migration `360008` created `commission_members`; migration `400003` **dropped it two days later** | Correctly withdrawn by the 2026-08-26 register. Both migrations still ship — see §8.3 |

## 1.3 Pre-DPC (S7, S9, S11)

| # | Requirement | Status | Evidence | Comments |
|---|---|---|---|---|
| R30 | AO enters sanctioned promotion posts: post, pay scale, DR %, promotion %, feeder posts, share, experience, qualification | ✅ | `PreDpcProposalController` (16 methods); `SavePreDpcProposalRequest`; `PreDpcProposalService`; `pre-dpc/form.blade.php`; `public/js/forms/pre-dpc-exercise.js` | |
| R31 | **Multiple Promotion From Posts** on one order | ✅ | `pre_dpc_promotion_sources` (migration `390001`, `unique(pre_dpc_proposal_id, promotion_from_post_id)`); `pre-dpc/partials/promotion-source-row.blade.php`; `PreDpcExerciseTest` | Restored after the 2026-08-24 reshape removed it |
| R32 | Pay Scale, Class and Promotion Duration **auto-populate**, never typed | ✅ | `PostAttributeResolver` → `PostAttributes`; JSON endpoint `GET /pre-dpc/post-attributes`; request ignores posted values | |
| R33 | Every Pre-DPC carries a DPC Year | ✅ | `pre_dpc_proposals.dpc_year`, required and bounded | |
| R34 | Unique Pre-DPC number | ✅ | `IdentifierSequenceService` with `series`; `unique(pre_dpc_number)`; issued at draft | |
| R35 | Exercise identity = (department, To Post, DPC Year); at most one approved-and-active; history never deleted | ✅ | `pre_dpc_active_exercise_unique_v2(department_id, promotion_to_post_id, dpc_year, active_flag)`; `PreDpcProposalService::supersedeIncumbent()` (line 403–408) | `active_flag` is `1` or `NULL`, never `0` |
| R36 | AH approves and digitally signs | ✅ | `PreDpcProposalPolicy::approve()`; `config('dpc.workflows.pre_dpc.transitions.approve')` has `signature => true`; `SignatureService`; `pre-dpc/show.blade.php` | |
| R37 | Amend an approved Pre-DPC into a new version | ✅ | `PreDpcProposalService::amend()` (clones into a fresh Draft, `parent_version_id`) | |

## 1.4 DPC Proposal (S12–S19)

| # | Requirement | Status | Evidence | Comments |
|---|---|---|---|---|
| R38 | **No standalone New DPC** — only from an active approved Pre-DPC, inheriting the order's terms | ✅ | `DpcProposalService::createFromPreDpc()` is the only creation path; `dpc_proposals.pre_dpc_proposal_id` FK `RESTRICT`; inherits 8 facts including `promotion_duration_years` **per feeder** | |
| R39 | Post selection: post-to, post-from, classes, legal stay, DPC type, proposal date (S14) | ⚠️ | `DpcProposalController@create/store/edit/update`; `SaveDpcProposalRequest`; `dpc-proposals/form.blade.php` | All fields present, but only **two** are editable on the draft (`dpc_type_id`, `legal_stay`) — `updateDraft()` fills nothing else, by design. The deck's NEXT/SAVE/EDIT/RESET buttons do not exist as such |
| R40 | Proposal Date auto-generated, never typed | ✅ | `createFromPreDpc()` sets `Carbon::today()`; not a request field | |
| R41 | DPC Number issued once, never changed by a return; revision number increments | ✅ | `dpc_proposal_revisions` + `unique(dpc_proposal_id, revision_number)`; `DpcRevisionService` writes no `dpc_code`; `DpcProposalRevision` is immutable in `booted()` | |
| R42 | DPC Committee: name, designation, mobile, email, capacity (S15) | ✅ | `DpcProposalController@committee/updateCommittee`; `SaveDpcCommitteeRequest`; `DpcCommitteeService`; `dpc-proposals/committee.blade.php`; `public/js/forms/dpc-committee.js` | |
| R43 | Search an existing user by email/mobile before creating a member; warn on duplicate | ✅ | `CommitteeMemberDirectory::matching()`/`existing()`; `GET /dpc-proposals/{p}/committee/search`; mobile normalised to last 10 digits, email case-folded | See §7.6 — this endpoint has **no test at all** |
| R44 | Committee membership grants no role | ✅ | No `assignRole()` on any committee path (grep-verified); `DpcCommitteeTest` | |
| R45 | CGPSC Chairman mandatory and seated automatically | ✅ | `CommitteeCapacity::CgpscChairman`; seated by `DpcCommitteeService`; `chairman_required` config; AO cannot remove it | |
| R46 | CGPSC Chairman designates the DPC Chairman | ✅ | `dpc_committee_members.is_dpc_chairman` + `unique(dpc_proposal_id, is_dpc_chairman)`; `POST /cgpsc/dpc-proposals/{p}/committee/{m}/designate-chairman` gated `role:chairman` alone; `DpcChairmanDesignationTest` | |
| R47 | Committee validated on **combined** attributes, minimum members, one member may cover several categories | ✅ | `CommitteeComplianceService::uncovered()` — coverage, not counting; `MemberCategory` enum; six `is_*` flags on the membership row; `CommitteeMasterTest` | Reads the posted rows and refuses **before** the write |
| R48 | Configurable member level (equal to / higher than the promotion post) | ❌ | `config('dpc.committee.member_level')` exists at `config/dpc.php:430` and **nothing reads it** (grep across `app/`, `resources/`, `tests/` → 0 hits). `MemberLevelRule` does not exist | Deliberately withdrawn as unenforceable (**BD-29**) — but the config key and two stale comments remain. See §8.4 |
| R49 | Administrator may replace a committee member at **any** stage | ✅ | `DpcCommitteeMemberController@replaceForm/replace` under `role:admin`; `replaces_id`/`replaced_at`/`replaced_by`/`replacement_reason`; `CommitteeReplacementTest` | |
| R50 | Working officers on the target post: chart, sanctioned count, vacancy breakdown, roster, deputation (S16) | ✅ | `DpcProposalController@workingOfficers/updateWorkingOfficers`; `SaveWorkingOfficersRequest`; `dpc_working_officers` + `dpc_vacancies`; `DpcWorkingOfficersTest` | |
| R51 | **Vacancy is calculated, never typed** (register 2026-08-27 items 1–8) | ✅ | `VacancyEngine::calculate()` — Base = Sanctioned − Working; Total = Base + Retirement + Deputation; Promotion = Total × RR%; Direct = remainder. `VacancyBreakdown` VO; `VacancyCalculationRecorder` persists append-only to `dpc_vacancy_calculations`; the input field is **removed from the form**, not disabled; `DpcVacancyCalculationTest` | Period derived from `dpc_year`, never the clock. Exact fractional figure shown beside the rounded one |
| R52 | Seniority list upload, with the zone formula `2 × 2 + 4 = 8` (S17) | ✅ | `DpcProposalController@seniorityList/updateSeniorityList`; `ZoneOfConsiderationService`; `VacancyCalculationService::requiredSeniorityCount()`; the zone is rendered read-only on the Working Officers screen with its formula; `DpcSeniorityListTest`, `ZoneAndSuperSeatGoldenTest::test_the_s17_worked_zone_figure_is_reproduced_exactly` | The deck's worked figure is pinned by a golden test |
| R53 | Evaluation / tabulation chart: CR, loyalty, property, note (S18) | ✅ | `DpcProposalController@evaluationChart/updateEvaluationChart`; `dpc_evaluation_rows` + 4 child tables; `SaveEvaluationChartRequest`; `DpcEvaluationChartTest` | |
| R54 | The chart is **projected from the Seniority List**, and must agree with it | ✅ | `EvaluationCandidateService::project()`; `DpcProposalCompletenessService` gate requires `$chart['matches']`; re-import rebuilds the candidate set; `DpcSeniorityListReuploadTest` | |
| R55 | Pre-filled S18 template with only the evaluation columns blank | ✅ | `GET /dpc-proposals/{p}/evaluation-chart/template`; `ImportTemplateBuilder` | |
| R56 | **Missing mandatory records** — reason + PDF per officer per year (register 2026-08-27 items 14–19) | ✅ | `DpcRecordGapController` (4 routes, `scopeBindings()`, addressed by seniority number); `RecordGapRequest` (`required`, `file`, `mimes:pdf`, config max); `RecordGapService`; `dpc_evaluation_record_gaps` with `unique(row, kind, year)`; `DpcRecordGapTest` | |
| R57 | Excel upload for S16/S17/S18 with Preview → Validation → Error Report → Commit | ✅ | `DpcImportController` (5 routes); `ExcelImportService`; `import_batches` + `import_rows`; SHA-256 of the uploaded bytes stored; commit reuses the same save methods manual entry uses; `DpcExcelImportTest` | Rules shared with the Form Requests via `DpcRowRules` — one definition, two paths |
| R58 | Blank upload templates downloadable | ✅ | `ImportTemplateController@index/download`; `ImportTemplate` enum (3 cases); `ImportTemplateTest` | Its own screen rather than a button on the frozen S16–S18 screens |
| R59 | Submission gate — proposal complete before the AH is asked to sign | ✅ | `DpcProposalCompletenessService::checklist()` — 5 sections + 3 arithmetic invariants (ceiling, classification balance, seniority-matches-zone), each with its own config switch | |
| R60 | AH reviews each section and approves (S21–S25) | ⭐ | `DpcProposalController@review($step)`; `dpc-proposals/sections/*.blade.php`; a single `POST /{p}/approve` | Five read-only review steps exist; **one** approval, not five. Documented architectural decision |
| R61 | Approval signs, stamps and forwards to CGPSC (S19) | ✅ | `DpcProposalService::approve()` sets `approved_at`, `signature_id`, `forwarded_at`; `config` transition `approve` has `signature => true`; `DpcProposalWorkflowTest` | |

## 1.5 CGPSC workflow — allotment, scheduling, sitting (S26–S34)

| # | Requirement | Status | Evidence | Comments |
|---|---|---|---|---|
| R62 | Forwarded proposal reaches the Commission; allotment to a Member (S28) | ✅ | `Cgpsc\AllotmentController@index/store/reallot/history`; `AllotmentService::assertMayAllot()`; `dpc_allotments`; `DpcAllotmentPolicy`; `AllotmentTest` | Dynamic members, not fixed M1–M5 columns |
| R63 | Re-allotment with history | ✅ | `allotments.reallot`; `cgpsc/allotments/history.blade.php` | |
| R64 | **Completion deadline; return on expiry** (register 2026-08-26 item 9d) | ✅ | `dpc_allotments.complete_by`, `returned_at`, `return_reason`; `AllotmentStatus::Returned`; `dpc:return-expired-allotments` console command; `AllotmentDeadlineTest` | |
| R65 | Member accepts; sets date and time; reschedule; schedule report (S30) | ✅ | `SchedulingController@index/accept/store/reschedule/history`; `dpc_schedules`; `SchedulingTest`, `ReschedulingTest` | |
| R66 | AH and AO notified of the date/time (S26) | ✅ | `WorkflowTransitioned` → `RecordInAppNotification` (always on) + `SendWorkflowNotification` (flagged); `NotificationCentre`; bell in both layouts; `NotificationCoverageTest` | In-app channel deliberately outside the feature flag |
| R67 | The sitting opens at the scheduled date/time; department data frozen (S31) | ⚠️ | `ProceedingController@open`; `DpcProposalStatus::isEditable()` locks the five sections on submit | Freezing is correct and enforced. **Early opening is permitted by default** (`dpc.proceeding.allow_open_before_scheduled_time` defaults `true`), so the scheduled time is recorded rather than enforced |
| R68 | Commission views the chart and may EDIT / TRIBAL, with a reason (S33) | ✅ | `ProceedingCorrectionController@index/store/destroy`; `CorrectableField` enum bounds it, `dpc.proceeding.correctable_fields` enables it; `correction_reason_min` = 10 chars; `dpc_proceeding_overrides` append-only with `unique(proceeding, sequence)`; DELETE **withdraws by appending**, never deletes; `ProceedingCorrectionTest` | Identity fields deliberately not correctable |
| R69 | CALCULATE at the sitting (S33) | ✅ | `ProceedingResultController@calculate`; `ProceedingCalculationService` (decorated by `ReviewAwareCalculationService`); `ProceedingResultTest` | |
| R70 | Main list and waiting list (S34) | ✅ | `ListType::{Main,Waiting,None}`; `DpcCalculationService`; `cgpsc/proceedings/result.blade.php` | Every officer gets a result row — absence from both lists is explained, not silent |
| R71 | APPROVE BY CGPSC (S34) | ✅ | `ProceedingResultController@approve`; `ResultApprovalService` (decorated); `ApproveResultRequest`; pins the calculation run | |
| R72 | CANCEL DPC (S33) | ✅ | `ProceedingResultController@cancel`; `CancelDpcRequest`; `cancel_dpc` transition | |
| R73 | Held promotions (`CIR`) detected from missing CR / property (S45) | ⚠️ | `HoldService::detect()` runs as part of CALCULATE; `dpc_proceeding_holds` with `unique(proceeding, sn, reason_code)`; `ProceedingHoldTest` | Detection and **release** are wired to the UI. `POST .../holds` (record a Commission hold) and `POST .../holds/detect` (re-check) have **no screen and no HTTP test** — see §3.13 and §8.2 |
| R74 | Documents generated, hashed, signed, verifiable, published (S34, S45) | ✅ | `ProceedingDocumentService` — render → store → SHA-256 → sign → publish; `DocumentType::{Proceedings,PromotionList}`; `generator_commit_hash` column; `ProceedingDocumentTest` | **Publication is refused while the result is provisional** (verified in `publish()`, lines 83–88) |
| R75 | Documents as PDF | ⚠️ | `DocumentRenderer` contract; only `HtmlDocumentRenderer` implements it | HTML today. Blocked on B-2 (whether CSC eSign takes bytes or a PDF). Swap is behind a contract |

## 1.6 Review DPC (S35–S44)

| # | Requirement | Status | Evidence | Comments |
|---|---|---|---|---|
| R76 | Department raises a Review against a concluded DPC, choosing reasons | ✅ | `ReviewProposalController@create/store`; `CreateReviewProposalRequest`; `ReviewDpcService`; `dpc_proposal_review_reasons`; `review_reasons` master; `ReviewDpcTest` | |
| R77 | Review follows the same process as a Fresh DPC | ✅ | The Review is an ordinary `DpcProposal`; the same sections, workflow, calculation and proceeding paths apply | |
| R78 | Review calculates under the **original's** rules | ✅ | `ReviewAwareCalculationService` decorates `ProceedingCalculationService` in `AppServiceProvider::register()`; `dpc_review_calculations`; `ReviewCalculationTest` | Fresh DPC passes straight through |
| R79 | Supersession takes effect at approval; superseded documents stay downloadable, watermarked | ✅ | `ReviewAwareApprovalService`, `ReviewAwareDocumentService`, `ReviewAwareDocumentRenderer`; `documents/superseded-watermark.blade.php`; `DpcSupersededByReview` event; `ReviewSupersessionTest` | |
| R80 | The full correction chain is readable | ✅ | `GET /dpc-proposals/{p}/review-chain`; `dpc_review_links` with `chain_uuid`, `review_number`, `depth`; `dpc/reviews/chain.blade.php` | |
| R81 | Commission-initiated Review | ❌ | No CGPSC-side route creates a review; `reviews.create`/`reviews.store` sit under `role:authorized_officer│administrative_head` | S35 lists "Commission process errors" as a ground. Today the Commission must ask the department to raise it |

## 1.7 Circulation DPC (S45–S48, C3/C7)

| # | Requirement | Status | Evidence | Comments |
|---|---|---|---|---|
| R82 | Department raises a circulation note against held officers, with resolution evidence | ✅ | `Dpc\CirculationController` (8 routes); `RaiseCirculationRequest`; `CirculationService`; `dpc_circulations`/`_items`; `CirculationRaiseTest` | Own aggregate — no `dpc_proposals` row created |
| R83 | Circulation carries its own identifier, distinguishable from a DPC ID | ✅ | `CirculationCodeService` → `<origin DPC ID>/C<n>`; `unique(circulation_code)` | |
| R84 | Commission sees before/after, decides per officer, consents, signs, releases | ✅ | `CirculationConsentController` (6 routes); `CirculationResultService` (before/after from **one** stored result — nothing recalculated); `CirculationCommissionService`; `CirculationConsentTest`, `CirculationSignatureTest` | Consent and signature are separate acts; holds release at **sign** |
| R85 | Circulation note published | ⚠️ | `CirculationDocumentController@publish` throws `PendingBusinessDecisionException`; `CirculationPendingBusinessDecisionTest` | **Deliberately refused** while **BD-14** (effective date of a circulated promotion) is unanswered. The note is still generated, signed and verifiable |
| R86 | A CR-shortfall hold resolvable by circulation | ❌ | `CirculationService` refuses a CR-ground hold with a structured `PendingBusinessDecision`; `CirculationPendingBusinessDecisionTest::test_a_cr_ground_hold_is_detected_without_scoring_anything` | Blocked on **BD-15**. Those officers have **no resolution route today** — a real service gap, correctly refused rather than guessed |

## 1.8 Self-Inspection (S49–S51)

| # | Requirement | Status | Evidence | Comments |
|---|---|---|---|---|
| R87 | Public landing, outside the authenticated area | ✅ | `/self-inspection/*` declared outside `auth`; `SelfInspectionController@landing` | |
| R88 | Individual identifies by Aadhaar no. + OTP + Aadhaar name + date of joining (S50) | ⭐ | `SelfInspectionAuthenticator`; `SelfInspectionFactor` enum; `IdentifySelfInspectionRequest` generated from the same source the matcher reads; `throttle:self-inspection` (strictest limiter in the app) | Configurable identity factors, not Aadhaar. `SelfInspectionAuthDriver` returns a named **BD-19** refusal rather than a broken screen when set to an unavailable driver |
| R89 | Officer sees own row, notice, CLOSE and PRINT (S51) | ✅ | `SelfInspectionController@show/print/close`; `SelfInspectionViewService`; `ObjectionDeadlineResolver`; 24 tests in `SelfInspectionTest` | No mark, remark or decision is shown — asserted by test |
| R90 | **RAISE OBJECTION** (S51) | ❌ | No route, no controller method, no view element (grep-verified) | Deliberate under **BD-20**. It *is* a slide-level control that does not exist |

## 1.9 Cross-cutting

| # | Requirement | Status | Evidence | Comments |
|---|---|---|---|---|
| R91 | Bilingual (English + Hindi), nothing hardcoded | ✅ | 13 groups × 2 locales, **1,719 keys each**, parity asserted; `BilingualCoverageTest` (6 checks incl. "no view carries a hardcoded sentence" and "no key resolves to an array"); `ScreenSmokeTest` renders every parameterless screen in **both** locales for **every** role | Independent check: only 10/1,719 Hindi values are identical to English, and all 10 are numerals or already-Hindi strings |
| R92 | Reports for every role, correctly scoped | ✅ | `ReportController@index/export`; `DpcReportService::scopeFor()` — one scope expression; `reports/index.blade.php`; CSV export; `DpcReportTest` | Counts read stored calculation results; nothing recalculates |
| R93 | Audit trail readable from a screen | ✅ | `AuditTrailService` — a SQL `UNION` over `workflow_transitions`, `activity_timelines` and `audits`, paginated honestly; `AuditTrailController@index/export/show`; **no create/update/delete/purge route exists**; `AuditTrailScreenTest` | |
| R94 | Every external system behind a Provider contract, flagged off by default | ✅ | `App\Enums\Feature` (7 flags); `AppServiceProvider::register()` binds each contract by flag; `/admin/integrations` shows live state; `FeatureFlagTest` | An enabled-but-unimplemented flag **throws at boot** rather than silently degrading |

---

# 2. Database Verification

**79 migrations produce 78 tables.** A fresh MySQL build and the incrementally-migrated `dpc_dev` have an **identical table set** (verified by diff). All tables are InnoDB / `utf8mb4_unicode_ci`.

## 2.1 New tables since the 2026-08-24 baseline (13)

| Table | Migration | Purpose |
|---|---|---|
| `user_department_assignments` | `360003` | Dated postings; `unique(user_id, active_flag)` |
| `service_classes` | `360004` | Class Master |
| `dpc_proposal_revisions` | `360007` | Revision history; immutable |
| `commission_members` | `360008` | **Dropped by `400003`** |
| `portal_notices` | `370001` | Landing-page notice board |
| `department_post_documents` | `380002` | **Dropped by `400005`** |
| `pre_dpc_promotion_sources` | `390001` | Several Promotion From Posts per order |
| `cgpsc_chairmen` | `400002` | Effective-dated Chairman record |
| `department_post_orders` | `400005` | Several GOs per mapping |
| `rr_source_qualifications` | `400006` | Several qualifications per RR source |
| `pre_dpc_source_qualifications` | `400006` | Several qualifications per Pre-DPC source |
| `dpc_vacancy_calculations` | `410003` | Append-only vacancy evidence |
| `record_reasons`, `dpc_evaluation_record_gaps` | `410004` | Missing-record justification |

## 2.2 Removed tables (5)

`cadres` (renamed), `pre_dpc_posts`, `pre_dpc_post_feeders`, `recruitment_rule_feeders` (renamed to `recruitment_rule_sources`), `commission_members`, `department_post_documents`.

## 2.3 Renamed tables (3)

`cadres → department_posts` (`360001`), `recruitment_rule_feeders → recruitment_rule_sources` (`370002`), plus column renames `current_post_id/feeder_post_id → promotion_to_post_id/promotion_from_post_id` (`360002`) and `deputation_date → deputation_from` (`410002`).

## 2.4 Notable added columns

| Table | Columns | Migration |
|---|---|---|
| `users` | `employee_number` (unique) | `360009` |
| `department_posts` | `effective_from/to`, `active_flag`, `superseded_by`, `sanctioned_strength` | `380001`, `410001` |
| `pay_scales` | `level`, `grade_pay` | `410001` |
| `dpc_working_officers` | `date_of_retirement`, `deputation_from`, `deputation_to` | `410002` |
| `dpc_proposals` | `pre_dpc_proposal_id`, `dpc_year`, `class_level`, `promotion_duration_years`, `revision_number` | `360007` |
| `dpc_allotments` | `complete_by`, `returned_at`, `return_reason` | `400004` |
| `dpc_committee_members` | six `is_*` attribute flags, `is_dpc_chairman`, `replaces_id`, `replaced_at/by`, `replacement_reason`, `active_flag` | `400001` |
| `dpc_evaluation_cr_grades` | `tribal_area` (per CR year) | `410005` |
| `dpc_id_sequences` | `series` | `360005` |

## 2.5 Removed columns (destructive)

`users.department_id`, `users.level`, `department_posts.promotion_duration_years`, `department_posts.government_order_number/date`, `pre_dpc_proposals.is_current`, `pay_scales` legacy columns, and the pre-`400001` shape of `dpc_committee_members`.

**Finding D-1 — the additive-migration rule was broken, by exception.** The change-control standing rule for this project is *migrations additive-only*. Migrations `360001`, `360003`, `360006`, `360007`, `380001`, `390001`, `400001`, `400003`, `400005` **drop tables and columns and rename both.** The change registers record this as a granted exception; it is nonetheless the single largest structural risk in the repository, and it is compounded by the fact that **none of it is committed** (§8.7). There is no rollback point.

## 2.6 Foreign keys, indexes, constraints

- **199 foreign keys**, every one with an explicit `ON DELETE` rule.
- **87 unique constraints** beyond primary keys.
- **393 index entries** total.
- **Every routable aggregate carries a `uuid` with a unique index** — `dpc_proposals`, `pre_dpc_proposals`, `dpc_proceedings`, `dpc_allotments`, `dpc_schedules`, `dpc_circulations`, `dpc_calculation_runs`, `dpc_vacancy_calculations`, `dpc_evaluation_record_gaps`, `department_post_orders`, `portal_notices`, `record_reasons`, `cgpsc_chairmen`, `user_notifications`, `workflow_transitions`, `activity_timelines`, and the review/document tables. Asserted by `PublicIdentifierTest`.
- **Partial-uniqueness by NULL** is used deliberately and consistently for "at most one active": `department_posts_active_mapping_unique`, `uda_user_active_unique`, `cgpsc_chairmen_serving_unique`, `dpc_committee_members_dpc_chairman_unique`, `pre_dpc_active_exercise_unique_v2`. The `1`-or-`NULL`-never-`0` invariant is enforced in the models.
- `SchemaIdentifierLengthTest` guards the 64-character MySQL identifier limit; `schema:verify-mysql` builds the whole schema on MySQL in the gate.

## 2.7 Does the schema match the latest agreed business rules?

Yes, on every rule I could test:

| Rule | Schema evidence |
|---|---|
| Vacancy is derived and audited, not typed | `department_posts.sanctioned_strength` + `dpc_vacancy_calculations` (append-only, `superseded_by_id`) |
| Sanctioned strength lives on the mapping | `410001` |
| Retirement/deputation drive vacancy per officer | `410002` three date columns |
| Tribal-area service belongs to a CR year | `410005` moves `tribal_area` onto `dpc_evaluation_cr_grades` |
| One active Pre-DPC per (dept, to-post, year) | `pre_dpc_active_exercise_unique_v2` |
| A DPC must come from a Pre-DPC | `dpc_proposals.pre_dpc_proposal_id` FK `RESTRICT` |
| One DPC Chairman per DPC | `unique(dpc_proposal_id, is_dpc_chairman)` |
| Several GOs per mapping | `department_post_orders` + `unique(post, go_number)` |
| Several qualifications per source | two pivot tables |
| Missing record justified once per (row, kind, year) | `dpc_eval_gap_unique` |

**Finding D-2 — Spatie permission tables are empty and unused.** `permissions` = 0 rows, `role_has_permissions` = 0 rows. `RolesAndPermissionsSeeder` creates roles only. Grep finds **no** `givePermissionTo`, `syncPermissions` or `Permission::create` anywhere, and **no route uses the `permission:` or `role_or_permission:` middleware** although both aliases are registered in `bootstrap/app.php`. Authorization is entirely role + policy. This works, but three database tables and two middleware aliases are dead weight, and a reader will reasonably assume a permission layer exists.

---

# 3. Screen Verification

145 Blade views, 270 routes. `ScreenSmokeTest` renders **every parameterless screen for every one of the seven roles in both locales** and fails on any 5xx; `RoleBasedUiVisibilityTest` and `AuthorizationCoverageTest` assert who may reach what. Both pass.

| # | Screen | Route(s) | Completed | Missing / Broken / Inconsistent |
|---|---|---|---|---|
| 3.1 | **Login** | `GET/POST /login` | Three identifier types (email, 10-digit mobile, employee number), rate-limited, deactivated-account message, unverified → OTP, session regenerated | No Aadhaar/OTP login (R3). No MFA for an already-verified account. Minor: a non-existent login skips `Hash::check`, so response timing differs — mitigated by the limiter |
| 3.2 | **Registration** | `GET/POST /register` | All S6 fields; `.gov.in` enforced on three addresses; PIN 6 digits; mobiles 10 digits and unique; AO ≠ AH | Deck's EDIT / SAVE / REGISTER trio is a single Register submit |
| 3.3 | **OTP verification** | `GET/POST /verify` | Dual-channel codes, attempt cap, throttle | Delivery is log-only (B-1) |
| 3.4 | **Dashboard** | `GET /dashboard` | One screen, every role; `DashboardSummaryService` scopes each card; role switcher in the layout | — |
| 3.5 | **Admin dashboard** | `GET /admin` | Master-data landing | `AdminDashboardController` has 1 method, 78 lines — thin |
| 3.6 | **Department** | `admin/departments` resource (no `show`) | CRUD, status, unique code and name | — |
| 3.7 | **Department Post Mapping** | `admin/department-posts` full resource + `show`, `supersede`, `orders/{order}` download | Effective dating, `inForce()` scope, sanctioned strength, **multiple GOs with per-order PDF**, private-disk streaming, supersession form | `index`, `create`, `edit`, `destroy` have no test by route name (the mapping *behaviour* is well covered by `DepartmentPostMappingTest`) |
| 3.8 | **Recruitment Rules** | `admin/recruitment-rules` + `version` + `download` | Versioned clone, GO PDF, multi-qualification rows, `public/js/forms/recruitment-rule.js` | **`download` has no test.** PDF validated by `mimetypes:` only, where Department Post uses `mimes` + `mimetypes` + `extensions` — inconsistent (§5.4) |
| 3.9 | **Users** | `admin/users` (index/create/store/edit/update) + `toggle-active` + `reset-password` | Provision, deactivate, reissue password | No `destroy` — deliberate |
| 3.10 | **Role Assignment** | `admin/users/{u}/assignments` index/store/release | Dated postings, transfer = deactivate + create, **DELETE releases and never deletes** | `assignments.release` untested by route name |
| 3.11 | **Pre-DPC** | `/pre-dpc/*` (11 routes) | Index, create, show, edit, submit, approve, return, cancel, amend; multi-source repeater; auto-populated attributes via JSON | — |
| 3.12 | **DPC** | `/dpc-proposals/*` (~35 routes) | Index, create, show, edit, 5 sections, 5-step review walkthrough, submit/approve/return/cancel, calculate + history + per-officer explain, imports, record gaps, candidate password reissue | Only 2 fields editable on a draft (R39). `update`/`edit`/`committee.search` untested by route name |
| 3.13 | **Committee** | `committee`, `committee.search`, `committee.update`, admin `replace` | Combined-attribute compliance, CGPSC Chairman auto-seated, duplicate detection, admin replacement at any stage | `committee.search` — the JSON directory endpoint — has **zero tests** |
| 3.14 | **Working Officers / Vacancy** | `working-officers`, `working-officers.update` | Nine-row derived vacancy summary, exact fractional figure beside the rounded one, zone read-only with its formula, breach and imbalance explained inline; the manual vacancy input is **removed from the form** | Not a separate "Vacancy" screen — the deck does not have one either |
| 3.15 | **Seniority** | `seniority-list`, `seniority-list.update` | Upload/entry, exact-count gate against the zone, re-upload rebuilds the chart and provisions candidate accounts | — |
| 3.16 | **Evaluation Chart** | `evaluation-chart`, `.template`, `.update`, `record-gaps.*` | Projected from the seniority list, must agree with it, pre-filled template, per-officer missing-record justification with mandatory PDF | — |
| 3.17 | **Allotments** | `cgpsc/allotments` index/store/reallot/history | Allot, re-allot, deadline, history | — |
| 3.18 | **Schedules** | `cgpsc/schedules` index/accept/store/reschedule/history | Accept, schedule, reschedule, history | — |
| 3.19 | **Proceedings** | `cgpsc/proceedings/*` (~18 routes) | Open, show, history, corrections (add + withdraw), calculate, result, approve, cancel, holds, documents | **Holds: the screen shows holds and offers release only.** No control posts to `holds.store` or `holds.detect` — both are unreachable from the UI |
| 3.20 | **Circulation (department)** | `dpc-circulations/*` (8 routes) | Create, show, submit, approve, return, cancel | `submit`/`approve`/`return`/`cancel` untested by route name |
| 3.21 | **Circulation (Commission)** | `cgpsc/circulations/*` (11 routes) | Before/after table, per-officer decide, consent, sign, return, documents index/store/publish/download/verify | `publish` refuses on **BD-14** by design |
| 3.22 | **Reports** | `/reports`, `/reports/export` | Pipeline by PPT stage, by year, outcomes, variants, by department; CSV export; one scope expression | — |
| 3.23 | **Notifications** | `/notifications` + read / read-all | Bell in both layouts via composer; ownership enforced in the service | — |
| 3.24 | **Audit Trail** | `admin/audit-trail` index/export/show | UNION over three sources, filtered, paginated, streamed export | Read-only by construction |
| 3.25 | **Candidate** | `/my-dpc`, `/my-dpc/{p}` | Own DPC and status only; marks/remarks/other officers stripped; unrelated UUID → 404 | Two routes, and that is the whole surface — correct |
| 3.26 | **Self-Inspection** | `/self-inspection/*` (6 routes) | Landing, identify, record, print, close; strictest rate limiter; access recorded per view and per print | **No RAISE OBJECTION** (R90) |
| 3.27 | **Integrations** | `admin/integrations` | Live flag state per provider | — |
| 3.28 | **Error pages** | `errors/{403,404,419,429,500,503}` | All six exist, translated | Flagged "unreferenced" by naive grep; they are Laravel-convention auto-discovered. **Not dead** |

**UI consistency.** Enforced mechanically, not by review: `ViewsAreCspCleanTest` asserts no inline `<script>`, no inline event handler, no `javascript:` URL, that every script a view loads exists, and that every extracted script is loaded by some view. `BilingualCoverageTest` asserts every view compiles, carries no hardcoded sentence and renders no untranslated text. Status badges come from one `<x-status-badge>` component; four lookup masters share one index/form partial pair driven by `$routeBase`. **I found no UI inconsistency worth reporting.**

---

# 4. Workflow Verification

Every state machine is **configuration** (`config/dpc.php` → `workflows`) executed by one `WorkflowEngine`, which validates `from`-state legality and actor role, then writes a ledger row + a timeline row + a notification event, stamping `WORKFLOW_VERSION` (currently 2) on the ledger. Notification dispatch is isolated in a `try/catch` so a dead gateway can never roll back a completed transition.

## 4.1 Pre-DPC — requested vs. implemented

| Requested step | Implemented | Evidence |
|---|---|---|
| Draft | ✅ `PreDpcStatus::Draft` | enum + `isEditable()` |
| ↓ Submitted | ✅ `submit`: `draft│returned → submitted`, role `authorized_officer` | `config/dpc.php:170` |
| ↓ Returned | ✅ `return`: `submitted → returned`, role `administrative_head` | `:172` |
| ↓ Approved | ✅ `approve`: `submitted → approved`, role `administrative_head`, **`signature => true`** | `:171` |
| ↓ **Active** | ✅ — **not a status, an attribute.** `approve()` sets `active_flag = 1` and deactivates the incumbent under a lock in the same transaction | `PreDpcProposalService` lines 158, 403–408; `pre_dpc_active_exercise_unique_v2` |
| ↓ Create DPC | ✅ Only from an active approved Pre-DPC | `DpcProposalService::createFromPreDpc()`; FK `RESTRICT` |
| *(extra)* Cancelled | ✅ `cancel`: `draft│returned → cancelled` | `:173` |
| *(extra)* Amend | ✅ clones an approved proposal into a new Draft version | `PreDpcProposalService::amend()` |

Race safety: `PreDpcTransitionLockTest` pins that the write lock is taken **before** the transition is decided (TD-5).

## 4.2 DPC — requested vs. implemented

The requested chain maps onto **three** state machines plus a completeness gate. It is all present; it is not one enum.

| Requested step | Where it lives | Evidence |
|---|---|---|
| Draft | `DpcProposalStatus::Draft` | enum |
| ↓ Committee | Section, not a status | `DpcProposalCompletenessService` checklist key `committee` (S15) |
| ↓ Evaluation | Section, not a status | checklist keys `working_officers` (S16), `seniority_list` (S17), `evaluation_chart` (S18) |
| ↓ Calculation | Department-side CALCULATE | `DpcCalculationController@store` → `DpcCalculationService`; `dpc_calculation_runs`/`_results`, immutable |
| ↓ Recommendation | Main + Waiting lists | `ListType`; `documents.promotion-list` |
| ↓ Approval | Two approvals, both real | AH: `dpc_proposal.approve` (signed). Commission: `dpc_proceeding.approve_result` |
| ↓ Published | `ProceedingDocumentService::publish()` | Refuses while the run has pending decisions |

The four workflows, verified line by line in `config/dpc.php`:

| Workflow | Transitions | Roles | Signature |
|---|---|---|---|
| `pre_dpc` | submit, approve, return, cancel | AO / AH | approve |
| `dpc_proposal` | submit, approve, return, cancel | AO / AH | approve |
| `dpc_cgpsc` | allot, reallot, accept, schedule, reschedule | `chairman│member` for allot/reallot; `member` for the rest | none |
| `dpc_proceeding` | open, approve_result, cancel_dpc | `member` | none *(documented: signing needs a document, which is a later phase)* |
| `dpc_circulation` | submit, approve, return, cancel, consent, return_to_department, sign | AO / AH then `member` | approve, sign |

**Being the allottee is enforced by policy and service, not by role** (`AllotmentService::assertMayAllot()`, `DpcProceedingPolicy::act()`), so the role gate and the identity gate are independent. This is correct and is the single most important authorization decision in the CGPSC half of the system.

**Workflow findings.**

- **W-1 (⚠️).** `dpc_proceeding.approve_result` and `cancel_dpc` declare `signature => false` with an in-code note that a later phase will set it true. That phase shipped (documents exist and are signed), and the flag was not revisited. The *documents* are signed; the *approval transition* is not. For a promotion order that may be contested, "who signed the approval" and "who signed the document" being different records is worth a decision.
- **W-2 (⚠️).** The sitting may be opened before the scheduled time by default (`allow_open_before_scheduled_time = true`). Correct for demonstrability; wrong for S31 read literally. One env var.
- **W-3 (✅).** No state is reachable that no transition produces — each phase declared only the transitions it implemented. I verified every enum case against the transition table.

---

# 5. Validation Verification

**51 Form Requests.** Business logic never validates in a controller — spot-checked across all 56 controllers.

## 5.1 Implemented validations (representative, all read in source)

| Area | Rules |
|---|---|
| Registration | `digits:6` PIN, `digits:10` unique mobiles, `.gov.in` regex on three emails, AO ≠ AH on both mobile and email, department must be `unregistered` |
| Login | required login + password; identifier type inferred, never trusted |
| Import upload | `Rule::file()->extensions(config)->max(config)` — detected type, not the client-supplied name |
| Record gap | `kind` in an enum, `year` bounded 1950–2200, `record_reason_id` must exist **and be active**, document `required│file│mimes:pdf│max`, remarks conditionally mandatory when the reason demands them, year must exist on the chart |
| Government Order PDF | `file` + `mimes:pdf` + `mimetypes:application/pdf` + `extensions:pdf` + `max:10240`, with four bespoke messages |
| Committee | exactly one Chairman, size bounds from the master, six attribute flags, combined-coverage compliance run **before** the write |
| Working officers | per-row snapshot rules, `deputation_to` `after_or_equal:deputation_from`, retirement/deputation nullable by design |
| Corrections | reason `min:10` (config), field must be in the enabled `correctable_fields` set |
| Proposal submission | `DpcProposalCompletenessService` — 5 sections + 3 arithmetic invariants, each individually switchable |

## 5.2 Deliberate single-sourcing (the opposite of duplication)

`DpcRowRules` is the **only** definition of a valid officer row. Form Requests consume it through `prefix()`; the Excel importer consumes it unprefixed, one row at a time. A rule tightened once tightens manual entry and import together. This is the correct pattern and I found no second copy.

## 5.3 Missing validations

| # | Gap | Impact |
|---|---|---|
| V-1 | `GET /dpc-proposals/{p}/committee/search` — the `q` parameter has **no Form Request and no validation at all**. `CommitteeMemberDirectory::matching()` guards the length (`< 3` → empty) and escapes LIKE wildcards, so it is not injectable or unbounded; but the guard is in the service, not at the boundary, and nothing tests it | Low, but it is the only unvalidated request parameter in the application |
| V-2 | `GET /pre-dpc/post-attributes` — `post_ids` cast with `array_map('intval', ...)` in the controller rather than validated | Low; the resolver scopes to the officer's own department |
| V-3 | No maximum on `members[]`, `officers[]`, `entries[]`, `rows[]` array sizes in the section Form Requests | A very large POST is bounded only by PHP `max_input_vars`. The Excel path has a file-size cap; the manual path has none |

## 5.4 Incorrect / inconsistent validations

| # | Issue |
|---|---|
| V-4 | **PDF validation is inconsistent across the three upload paths.** `DepartmentPostRequest` and `SupersedeDepartmentPostRequest` use `mimes` + `mimetypes` + `extensions`; `RecordGapRequest` uses `mimes:pdf` alone; `RecruitmentRuleRequest` uses `mimetypes:application/pdf` alone. All three are *safe* (each checks something real), but three different standards for the same act invites the weakest one to spread |
| V-5 | Max upload size is `10240` **hardcoded** in `RecruitmentRuleRequest`, `DepartmentPostRequest` and `SupersedeDepartmentPostRequest`, while `RecordGapRequest` reads `config('dpc.documents.max_kb', 10240)`. The project's own convention is no hardcoded business values |

## 5.5 Duplicate validations

None found that matter. Bounds appear in both `CommitteeRuleResolver` (for the compliance service) and `SaveDpcCommitteeRequest`, but both read the resolver — the resolver's own docblock names this as the reason it exists. Verified: the request does not restate the numbers.

---

# 6. API Verification

**There is no `routes/api.php`.** The JSON surface is five endpoints inside the web routes. Assessed as an API surface:

| Endpoint | Auth | Authorization | Validation | Response | Errors |
|---|---|---|---|---|---|
| `GET /health` | none | none — intentionally public | n/a | `{status, checks…}` | ✅ Degrades rather than throwing |
| `GET /pre-dpc/post-attributes` | `auth` + role AO/AH | `authorize('create', PreDpcProposal::class)` + `abort_if(department === null, 403)` | ⚠️ none (V-2) | `{posts: [...]}` | 403 |
| `GET /dpc-proposals/{p}/committee/search` | `auth` + role AO/AH | `authorize('update', $dpcProposal)` | ❌ none (V-1) | `{members: [{id, name, email, mobile, designation, department}]}` | 403 |
| `POST /cgpsc/circulations/{c}/items/{i}/decide` and `consent` / `sign` / `return` | `auth` + role chairman/member | `DpcCirculationPolicy` + allottee identity in the service | Form Requests | `{status, message}` or the decision's timeline properties | ✅ `422` with `{status:'error', message}` on `WorkflowException` |
| `POST /cgpsc/circulations/{c}/documents/{d}/verify` | as above | policy | n/a | JSON verification result | ✅ |

**Findings.**

- **A-1 (⚠️ security-relevant).** `committee/search` returns **name, email, mobile, designation and department for any account in the portal** on a three-character name prefix, to any AO/AH who can update any proposal. `LIMIT 10` and the 3-character floor bound it, and the class docblock argues (correctly) that narrowing the search to Commission role-holders would defeat the duplicate-prevention it exists for. It remains a directory-enumeration surface with **no test, no rate limit, and no request validation**. Recommend: a `throttle:` limiter, a Form Request, and dropping `mobile` from the payload unless the exact-match branch matched.
- **A-2 (⚠️).** `bootstrap/app.php` renders JSON for exceptions only when `$request->is('api/*')` — a path that exists in no route. The circulation endpoints therefore hand-roll their own JSON error shape (`ok()`/`fail()` in `CirculationConsentController`). Two conventions for one concern.
- **A-3 (✅).** No API tokens, no Sanctum, no stateless surface — consistent with a session-only government portal, and the right choice.

---

# 7. Security Review

## 7.1 Authentication — ✅ sound

Session-only; `session()->regenerate()` on login and `invalidate()` + `regenerateToken()` on logout; deactivated accounts refused with a distinct message; unverified accounts diverted to OTP; forced password change enforced by middleware on every application route; four config-driven rate limiters. `SESSION_ENCRYPT=false` in the dev `.env` — worth turning on in production.

## 7.2 Authorization — ✅ strong, and structurally guarded

Three independent layers, all verified:

1. **Route gates.** Every route is inside `role:` middleware, or is on a **named, argued allowlist** in `AuthorizationCoverageTest::EVERY_ROLE` (10 entries, each with a written reason). Adding an ungated authenticated route **fails the build.** This is the best structural authorization guard I have seen in a codebase of this size.
2. **Acting capacity.** `ResolveActiveRole` replaces the loaded `roles` relation with the single active role, so `hasRole()`, `role:` middleware and every policy answer about the acting capacity without any of them being edited. A stale session naming a withdrawn role falls back to precedence rather than to the union — the fail-safe direction.
3. **Policies.** 5 policies; department ownership in `DpcProposalPolicy::sameDepartment()`; **allottee identity** enforced in `AllotmentService`/`DpcProceedingPolicy` rather than by role, so a Chairman is not implicitly the conductor of every DPC.

Plus scoping that does not rely on a policy at all: the candidate's `{dpcProposal}` is not route-model-bound, so an unrelated UUID is a 404.

**Finding S-1 (minor).** `DpcProposalPolicy::view()` checks department but not role. Harmless today because the route group already gates the roles; it would stop being harmless if the policy were reused from a differently-gated route.

## 7.3 Permissions — ⚠️ coarse

As §2 D-2: no permissions exist. Everything is role-level. For this system that is defensible (seven roles, clear separation), but there is no way to grant, say, "read the audit trail" without granting all of `admin`.

## 7.4 Mass assignment — ✅ clean

**Every model in `app/` declares `$fillable`; none uses `$guarded`.** Verified by scanning all 62 models. Sensitive lifecycle columns are written through `forceFill()` inside services (`active_flag`, `password`, `must_change_password`), never through request input. `Model::preventSilentlyDiscardingAttributes()` is on outside production, so a non-fillable write is a loud failure in development.

## 7.5 File upload — ✅ with the inconsistency at §5.4

Uploads are stored on the **private** disk (`storage/app/private`), never the public one; downloads are streamed through a controller after an authorization check (`DepartmentPostController@downloadDocument` also `abort_unless($disk->exists(...), 404)`); import files are SHA-256 hashed **before** storage and the digest is checked on re-read; the import extension allow-list is config-driven and uses `Rule::file()->extensions()`, which reads the detected type. `.gitignore` excludes `.env` and `storage/*.key`; `git ls-files` confirms **no `.env`, no credential file and no upload is tracked**.

## 7.6 Audit trail — ✅ strong, with one real gap

- Three independent records: `workflow_transitions` (legal ledger, with actor, IP, user-agent, signature id, workflow version), `activity_timelines` (domain events with an actor **snapshot** — who they were then), `audits` (owen-it model diffs).
- **48 classes carry `Auditable`**, including every master, every workflow aggregate, and `UserDepartmentAssignment`.
- 13 evidence tables guard themselves in model events and are enumerated in `config('dpc.security.evidence_tables')` with column-level allowances; `security:evidence-grants` emits and **verifies** the matching database grants; `EvidenceGrantsTest` compares the config list against every self-guarding model, so a new evidence table cannot be forgotten.
- No create/update/delete/purge route exists on `admin/audit-trail`.

**Finding S-2 (⚠️ — the most substantive security finding in this report).** The officer-data tables are **not** audited. `DpcEvaluationRow`, `DpcEvaluationCrGrade`, `DpcEvaluationLoyaltyFlag`, `DpcEvaluationPropertyFlag`, `DpcEvaluationYearFlag`, `DpcSeniorityEntry`, `DpcWorkingOfficer`, `DpcVacancy` and `DpcCalculationResult` carry **no `Auditable` contract** (grep-verified, 0 hits each). A CR grade — the number a promotion turns on — can be changed on a draft proposal and the `audits` table records nothing. The *section save* is on the timeline and the *result* is immutable once calculated, so the exposure is bounded to the drafting window; but "who changed officer 14's 2024 CR from `GA` to `GB` before submission" is not answerable from the data-change audit today. Adding the trait is a one-line change per model; the volume implication (six CR rows × ~70 officers per save) is the reason to decide it deliberately rather than by default.

## 7.7 Transport and response security — ✅

`SecurityHeaders` middleware applies a **fully enforced** `Content-Security-Policy` (`script-src 'self'`, `'unsafe-inline'` for styles only and argued), `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy`. Enforcement was earned: 6 inline `<script>` blocks and 21 inline handlers were extracted into `public/js/` first, and `ViewsAreCspCleanTest` fails the build if one returns. No CDN, no bundler — which is what makes the policy affordable. `SecurityHeadersTest` covers it. Headers are never overwritten if a controller set one deliberately.

## 7.8 Dependencies — ✅

`composer audit`: **no advisories**, verified during this audit.

## 7.9 Security issues, consolidated

| # | Issue | Severity |
|---|---|---|
| S-2 | Officer evaluation/seniority/working/vacancy/result rows are not `Auditable` | **Medium** |
| A-1 | Committee directory search discloses email + mobile portal-wide on a 3-char name prefix; no validation, no throttle, no test | **Medium** |
| D-2 | Permission tables and two middleware aliases are dead; RBAC is role-granularity only | Low |
| V-4/V-5 | Three different PDF-validation standards; hardcoded 10 MB caps in three requests | Low |
| S-1 | `DpcProposalPolicy::view()` omits the role check | Low |
| — | Evidence-table database grants **not applied** in any environment (blocker B-3) | Deployment |
| — | `SESSION_ENCRYPT=false` in the working `.env` | Deployment |

---

# 8. Code Quality Review

## 8.1 What is genuinely good, stated so the rest is calibrated

- **PHPStan level 6 with no baseline and no ignores, zero errors, across `app` + `config` + `database` + `routes`.** That is unusual and expensive to maintain.
- **Zero `TODO`, `FIXME`, `HACK` or `XXX` in the entire application, views, routes, config and database directories.** Verified by grep.
- **Zero orphan classes.** I checked all 344 application files for a reference outside themselves; every one is reached.
- **No duplicated business logic in the places it usually appears.** Validation lives once in `DpcRowRules`; the zone lives once in `ZoneOfConsiderationService` and is *asked* by `VacancyCalculationService` rather than reimplemented; the Review behaviour is applied by **decoration** (`ReviewAware*` bound in the provider) rather than by editing the proceeding services. The one accepted duplication — M9's circulation document pipeline mirroring M7's — is argued in the class docblock (M7's is hard-typed to `DpcProceeding`) and is mitigated by asserting the same properties rather than sharing code.
- Thin controllers throughout: the largest is `DpcProposalController` at 514 lines / 27 methods, and it delegates.

## 8.2 Dead code

| # | Item | Evidence |
|---|---|---|
| Q-1 | `POST /cgpsc/proceedings/{p}/holds/detect` — **no UI control and no HTTP test.** Holds are detected as part of CALCULATE, so the endpoint is redundant | grep across `resources/views` and `tests` → 0 |
| Q-2 | `POST /cgpsc/proceedings/{p}/holds` (record a Commission hold) — **no UI control.** `RecordHoldRequest` and `HoldService::record()` exist and are service-tested | Only `holds.destroy` appears in `result.blade.php:196` |
| Q-3 | `config('dpc.committee.member_level')` — **read by nothing.** `MemberLevelRule` was removed (BD-29) | grep → 1 hit, the definition |
| Q-4 | `config('dpc.directory')` — an empty array with only comments | grep → 0 readers |
| Q-5 | `permission` and `role_or_permission` middleware aliases registered in `bootstrap/app.php` — **used by no route** | grep → 0 |
| Q-6 | `permissions` and `role_has_permissions` tables — 0 rows, never written | §2 D-2 |

**Not dead, despite looking it:** the six `errors/*.blade.php` views (Laravel auto-discovery), `components/status-badge.blade.php` (used as `<x-status-badge>` in 10 views), and the ~20 lookup-master route names that a naive grep misses because `lookup-index.blade.php` builds them as `route($routeBase.'.edit', …)`. I verified each before excluding it.

## 8.3 Migrations that cancel each other out

| Created | Removed | Net effect |
|---|---|---|
| `360008_create_commission_members_table` (2026-08-24) | `400003_remove_commission_member_master` (2026-08-26) | Every fresh install creates a table, migrates data into it, and drops it two migrations later |
| `380002_create_department_post_documents_table` (2026-08-25) | `400005_create_department_post_orders` (2026-08-26) | Same |

Both pairs are correct history and neither can simply be deleted while `dpc_dev` and any deployed database have already run them. They are, however, four migrations of pure churn inside 48 hours, and `400005.down()` **recreates** `department_post_documents` — a table nothing else in the codebase knows about.

## 8.4 Stale comments pointing at removed code

`config/dpc.php:426` says "See `MemberLevelRule` for why…" and `PreDpcProposalService:305` says "until 2026-08-26 `MemberLevelRule` read it as…". The class does not exist. `CGPSC_DPC_ARCHITECTURE_HANDBOOK.md:540` still gives `MemberLevelRule` as the live reason `class_level` stays on the Pre-DPC order, while **line 638 of the same document** says the class was removed. The handbook contradicts itself.

## 8.5 Duplicate controllers / services

None. The two document pipelines (§8.1) are the only near-duplicate and are argued. `VacancyCalculationService` and `VacancyEngine` look like a duplicate pair and are not: the engine owns the vacancy, the service owns the zone and delegates the vacancy to the engine — I read both.

## 8.6 Unused views / unused routes

Covered at §8.2. Net: **7 views flagged by grep, all 7 false positives; 20 route names flagged, 17 false positives, 3 genuine** (`holds.detect`, `holds.store`, and Laravel's own `storage.local*`).

## 8.7 Repository hygiene — the largest governance finding

**Finding Q-7 (⚠️ High).** The working tree holds **422 changed paths against HEAD**: 258 modified, 147 untracked, 7 deleted, 10 renamed. The untracked set includes **24 migrations**, **all 26 language files**, **20 test classes**, 9 controllers, 15 services, 6 models and the `docs/demo/` credential set. HEAD (`71761a7`) predates all three change registers.

Consequences, all factual:
- There is **no rollback point** for three registers' worth of destructive schema change (§2.5).
- The documented freeze-verification procedure — diff against tag `v0.6.0-m6-complete` — cannot produce a meaningful answer while the diff is dominated by uncommitted work.
- A `git clean -fd` or a fresh clone loses the language files and the migrations, and the application will not boot.

This is the first thing to fix, and it is fifteen minutes of work.

## 8.8 Refactoring opportunities (all low priority)

1. Delete or wire `holds.detect` / `holds.store` (Q-1, Q-2).
2. Remove `dpc.committee.member_level` and `dpc.directory`, and the two comments naming `MemberLevelRule` (Q-3, Q-4, §8.4).
3. Remove the `permission` / `role_or_permission` aliases, or introduce the permission layer they imply (Q-5, D-2).
4. Move the three hardcoded `max:10240` values to `config('dpc.documents.max_kb')` and settle on one PDF rule triplet (V-4, V-5).
5. Give `committee/search` a Form Request and a throttle (A-1, V-1).
6. Decide whether `bootstrap/app.php`'s `api/*` JSON handler should go, or whether the circulation controllers' hand-rolled JSON should route through it (A-2).

---

# 9. Test Coverage

**101 test classes, 1,287 tests, 4,228 assertions — all passing.** 30,079 lines of test code against 43,745 lines of application code (0.69:1).

## 9.1 Structural tests — the ones that keep the others honest

These are worth naming because they prevent whole categories of regression rather than one bug each:

| Test | Guarantees |
|---|---|
| `AuthorizationCoverageTest` | Every authenticated route is role-gated **or** on an argued allowlist. A new ungated route fails the build |
| `ScreenSmokeTest` | Every parameterless screen renders without a 5xx for **all 7 roles in both locales**. Route list is discovered, not written down; asserts it found >40 screens so it cannot pass vacuously |
| `BilingualCoverageTest` | en/hi key parity; no key resolving to an array; every view compiles; no hardcoded sentence; no untranslated text |
| `ViewsAreCspCleanTest` | No inline script, no inline handler, no `javascript:` URL; every referenced script exists; every extracted script is loaded |
| `SchemaIdentifierLengthTest` | The 64-char MySQL identifier limit, on any engine |
| `VerifyMysqlSchemaCommandTest` + `schema:verify-mysql` | The whole schema builds on **MySQL**, not just SQLite — and the command **fails rather than skips** when MySQL is unreachable |
| `EvidenceGrantsTest` | The evidence-table config list matches every self-guarding model |
| `PublicIdentifierTest` | No sequential id appears in a URL |
| `RoleBasedUiVisibilityTest` | Each role sees only its own navigation |

## 9.2 Business rules covered by tests

| Rule | Test |
|---|---|
| CR grade → marks, five most recent **available** years | `ScoringServiceTest`, `S33AnswerKeyGoldenTest::test_us4_…` |
| Tribal-area bonus is one flat bonus, and changes the outcome | `S33AnswerKeyGoldenTest::test_us3_…` |
| `GH` anywhere in the window disqualifies; `GA` in the final year fails a Class-I→I promotion | `EligibilityServiceTest` (17 tests) |
| The deck's own S18 worked rows reproduce exactly | `GoldenCaseTest::test_s18_worked_example_rows` |
| Nine of the ten stated marks on S33 reproduce | `S33AnswerKeyGoldenTest::test_nine_of_the_ten_stated_marks_are_reproduced` |
| Zone `2 × V + 4`, and *top 5* for a single vacancy | `ZoneAndSuperSeatGoldenTest` |
| Super Seat: all top grades → notional seniority | `SuperSeatServiceTest`, `ZoneAndSuperSeatGoldenTest` |
| Reserved shortfall is **named, not expanded** | `test_the_zone_flags_a_reserved_shortfall_without_expanding_it` |
| Reserved relaxation is **not** applied while unruled | `test_no_officer_is_relaxed_while_the_rule_is_unruled` |
| An older RuleSet stays executable from its stored snapshot | `test_an_older_rule_version_stays_executable_from_its_stored_snapshot` |
| A configuration change cannot rewrite a stored calculation or its explanation | `DpcCalculationTest` (2 tests) |
| A completed run/result cannot be edited or deleted by anyone | `DpcCalculationTest` (4 tests) |
| Property-return shortfall rejects; justified missing CR/loyalty earns `CIR`; a reason without its document does **not** | `MeritGateTest` (11), `CandidateGateServiceTest` (27) |
| A downhill promotion rejects every candidate | `PromotionHierarchyServiceTest`, `MeritGateTest` |
| Vacancy = Sanctioned − Working + Retirement + Deputation, split by RR% | `DpcVacancyCalculationTest` |
| Seniority list must equal the zone; chart must equal the seniority list | `DpcSeniorityListTest`, `DpcEvaluationChartTest` |
| Committee coverage is combined, not per-member | `CommitteeMasterTest` |
| Committee membership grants no role | `test_sitting_on_a_committee_grants_no_role` |
| Allotment deadline returns on expiry | `AllotmentDeadlineTest` |
| A correction is appended; a withdrawal is itself an appended correction | `ProceedingCorrectionTest` |
| Supersession takes effect at approval; superseded documents watermark | `ReviewSupersessionTest` |
| A provisional result cannot be published | `ProceedingDocumentTest`, `CirculationPendingBusinessDecisionTest` |
| A candidate sees no mark, no remark, no other officer | `PromotionCandidateTest` |
| Self-inspection: all factors must match; a wrong factor says nothing about which | `SelfInspectionTest` (24 tests) |
| The write lock is taken before a transition is decided | `PreDpcTransitionLockTest` |

## 9.3 Missing tests

| # | Gap | Why it matters |
|---|---|---|
| T-1 | **`dpc-proposals.committee.search` — zero tests of any kind** | The only JSON endpoint returning portal-wide PII (A-1) |
| T-2 | `admin.recruitment-rules.download` — untested | A file-download route with an authorization decision. The equivalent `department-posts.documents.download` **is** tested, including cross-department refusal — the asymmetry is the point |
| T-3 | `cgpsc.proceedings.holds.store` / `.detect` — no HTTP test | `HoldService` is well tested; the controller layer, its `authorize()` and its `RecordHoldRequest` are not |
| T-4 | Lookup-master `update` / `destroy` — untested for `committee-types`, `dpc-types`, `review-reasons`, `vacancy-types` | `store` is covered by `LookupMasterTest`; the other half of the CRUD is not |
| T-5 | `admin.users.assignments.release` — untested by route | The service is covered by `UserDepartmentAssignmentTest`; the release **route** is not |
| T-6 | `dpc-circulations.submit/approve/return/cancel` — untested by route name | The department-side workflow is covered at service level in `CirculationWorkflowTest` |
| T-7 | `dpc-proposals.edit` / `.update` — untested by route | Only two fields are editable, so the risk is small |
| T-8 | No test asserts the **role-multiplicity** limitation of R8 either way | The system's actual behaviour (one departmental role at a time) is unasserted, so a future change could silently alter it |

## 9.4 A structural caveat the suite cannot close

The suite runs on **in-memory SQLite**; production is **MySQL**. This is mitigated better than most projects manage — `schema:verify-mysql` builds every migration on real MySQL in the gate, and `SchemaIdentifierLengthTest` covers the identifier limit — but *behavioural* engine differences (collation-dependent ordering, strict-mode truncation, `SUBSTR(col, -10)` semantics in `CommitteeMemberDirectory::mobileTailExpression()`) are exercised only on SQLite. The mobile-tail expression is explicitly written for both engines; nothing proves it.

---

# 10. Documentation Review

## 10.1 The five live handbooks — current and accurate

`docs/CGPSC_DPC_{USER_GUIDE, ARCHITECTURE_HANDBOOK, DEVELOPER_HANDBOOK, PENDING_BUSINESS_DECISIONS, BLOCKERS}.md`, 3,223 lines total.

Verified against code, not accepted:

- **Zero occurrences of "cadre"** in any of the five. Terminology is current.
- Three of them document the 2026-08-27 Vacancy and Merit engines.
- I spot-checked eight specific claims against source and **all eight held**: promotion rounding defaults to floor with the exact figure stored (`VacancyEngine::split()`/`roundingRule()`); reserved relaxation is `false` by default; the reserved zone expansion is named-not-applied; sealed cover is not implemented (**0 grep hits**); cascade vacancy is not modelled (**0 grep hits**); circulation publication throws on BD-14; the qualification gate is advisory by default; the vacancy period derives from `dpc_year` and never the clock.
- The `PENDING_BUSINESS_DECISIONS` register's structure — a named `BD-n` visible in the UI wherever the system declines to decide — is real: `PendingBusinessDecision` value object, `PendingBusinessDecisionException`, and `dpc/circulations/_pending-business-decision.blade.php`.

## 10.2 Outdated sections

| # | Location | Problem |
|---|---|---|
| Doc-1 | **`README.md`** | Five verifiable statements are now false: *"Engineering complete, 2026-08-22. Development frozen"* (three registers landed after); *"the **eight** policy questions"* (the register now lists BD-2 … BD-49); *"54 migrations, 68 tables"* (**79 / 78**); *"960 passed / 3135 assertions"* (**1,287 / 4,228**); *"`lang/{en,hi}` × 13 groups"* is right but the key count is not stated |
| Doc-2 | **`ARCHITECTURE_HANDBOOK.md:540`** | Gives `MemberLevelRule` as the live reason `class_level` stays on the Pre-DPC order. **Line 638 of the same file** says the class was removed. The document contradicts itself |
| Doc-3 | **`config/dpc.php:426`**, **`PreDpcProposalService:305`** | Comments referring the reader to `MemberLevelRule`, which does not exist |
| Doc-4 | **`docs/CHANGE_REGISTER_2026-08-24_REPORT.md`** items 13, 15, 16 | Cite `commission_members`, `CommitteeSelectionService`, `MemberLevelRule`, `CommissionMemberMasterTest`, `RoleCoverageTest` — **all five gone** (grep: 0 files). Defensible as historical record, but the file sits beside the live handbooks with no "superseded" marker |

## 10.3 The four root `PPT_*.md` files — stale, and misleading in a specific way

`PPT_COMPLIANCE_MATRIX.md`, `PPT_MISSING_FEATURES.md`, `PPT_IMPLEMENTATION_DIFFERENCES.md`, `PPT_DEMO_RISKS.md`, all dated 2026-08-22 and unchanged since.

- **Old terminology**: "Cadre master", "feeder cadres", "date of joining current cadre" — the term was eliminated on 2026-08-24.
- **Broken evidence**: cites `public/js/forms/pre-dpc-posts.js`, a file that no longer exists (it is `pre-dpc-exercise.js`).
- **Wrong statuses**: slide 16 is marked *"CALCULATE is not on this section"* and slide 17 *"the form does not calculate/display the zone inline"* — **both are now false.** The Working Officers screen renders a nine-row derived vacancy summary and the zone with its formula, and neither is typed.
- **Its headline number is wrong**: *"Overall PPT compliance: 67%"*. On the evidence in §1 the figure is materially higher.

These four files are the first thing a reviewer opens (repository root, `PPT_` prefix) and they **understate a system that has moved on**. They should be archived or regenerated.

## 10.4 Missing sections

| Feature (shipped) | Guide | Architecture | Developer |
|---|---|---|---|
| Missing-record justification (`record-gaps`) | 2 mentions | 1 | **0** |
| Record Reason master | **0** | **0** | **0** |
| Vacancy Calculation Engine, by that name | **0** | **0** | **0** |
| Role Assignment screen | **0** | **0** | **0** |
| Import Template screen | **0** | **0** | **0** |
| Portal Notice master | 1 | **0** | **0** |

Six shipped, routable, tested screens are absent or near-absent from the handbook set. The User Guide claims to cover "every role, screen, button and workflow"; it does not cover these.

## 10.5 Screenshots

`docs/archive/2026-08-19-pre-final/design/**` holds 11 PNGs of the pre-2026-08-24 recruitment-rule and Pre-DPC screens. They are **archived**, so they mislead nobody, but they are the only screenshots in the project and they no longer depict the application.

## 10.6 Documentation-count discipline

The standing rule is four (later five) permanent documents. The `docs/` root currently holds **nine** markdown files: the five handbooks, three change registers and `SUGGESTIONS_AND_IMPROVEMENTS.md` — plus four more at the repository root. Whether that is a violation or an evolved convention is a call for the architect; it is stated here because the rule exists.

---

# 11. Final Implementation Matrix

| Requirement | Status |
|---|---|
| Single CGPSC Admin (no Department Admin) | ✅ Implemented |
| Department registration (S5–S6) | ✅ Implemented |
| Credential issuance to AO/AH | ⚠️ Partially Implemented — no gateway (B-1) |
| Aadhaar + OTP login (11 slides) | ❌ Not Implemented — email/mobile/employee-no + password |
| DPC ID as a login credential | ❌ Not Implemented |
| OTP verification & forced password change | ✅ Implemented |
| Rate limiting / brute-force protection | ✅ Implemented |
| Multi-role account with role switching | ⚠️ Partially Implemented — one departmental role at a time |
| Department Assignment (dated, transferable, never deleted) | ✅ Implemented |
| Candidate Login (Promotion Candidate) | ✅ Implemented |
| Candidate sees only own DPC | ✅ Implemented |
| Master data — 12 masters | ✅ Implemented |
| Class Master (administrable) | ✅ Implemented |
| "Cadre" → "Post" terminology | ✅ Implemented |
| Department Post Mapping (effective-dated) | ✅ Implemented |
| **Multiple GO Upload** | ✅ Implemented |
| Recruitment Rules + **Versioning** | ✅ Implemented |
| Multiple qualifications per source | ✅ Implemented |
| CGPSC Chairman master (dated) | ✅ Implemented |
| Record Reason master | ✅ Implemented |
| Commission Member master | ❌ Not Implemented — deliberately withdrawn |
| Pre-DPC proposal (S7/S9/S11) | ✅ Implemented |
| Multiple Promotion From Posts | ✅ Implemented |
| Pre-DPC versioning + active exercise | ✅ Implemented |
| Auto-populated post attributes | ✅ Implemented |
| DPC only from an approved Pre-DPC | ✅ Implemented |
| DPC revisions (number never changes) | ✅ Implemented |
| **Committee Logic** (combined-attribute coverage) | ✅ Implemented |
| Committee member search + duplicate detection | ✅ Implemented |
| DPC Chairman designation | ✅ Implemented |
| Committee member replacement (any stage) | ✅ Implemented |
| Configurable committee member level | ❌ Not Implemented — withdrawn (BD-29), dead config remains |
| Working Officers chart | ✅ Implemented |
| **Vacancy Engine** (derived, audited, never typed) | ✅ Implemented |
| Seniority List + exact zone gate | ✅ Implemented |
| Evaluation Chart projected from the Seniority List | ✅ Implemented |
| Missing-record justification + PDF | ✅ Implemented |
| Excel import (Preview→Validate→Errors→Commit) | ✅ Implemented |
| Blank upload templates | ✅ Implemented |
| Submission completeness gate | ✅ Implemented |
| AH per-section approval (S21–S25) | ⭐ Implemented Differently — one final approval |
| AH approval signs and forwards | ✅ Implemented |
| CGPSC allotment + re-allotment + history | ✅ Implemented |
| Allotment completion deadline / auto-return | ✅ Implemented |
| Acceptance, scheduling, rescheduling | ✅ Implemented |
| Sitting opened at the scheduled time | ⚠️ Partially Implemented — early opening permitted by default |
| Department data frozen after submission | ✅ Implemented |
| Commission corrections (EDIT / TRIBAL) | ✅ Implemented |
| **Merit Calculation** (score, gates, zone, super seat) | ✅ Implemented |
| Main + Waiting lists | ✅ Implemented |
| Per-officer explanation from stored data | ✅ Implemented |
| Calculation immutability & reproducibility | ✅ Implemented |
| APPROVE BY CGPSC / CANCEL DPC | ✅ Implemented |
| Approval transition signature | ⚠️ Partially Implemented — documents signed, transition flagged `false` |
| Held promotions (`CIR`) — detect & release | ⚠️ Partially Implemented — no UI to record or re-detect |
| Documents: render→hash→sign→verify→publish | ✅ Implemented |
| Documents as PDF | ⚠️ Partially Implemented — HTML, behind a contract (B-2) |
| Provisional result cannot be published | ✅ Implemented |
| Review DPC (department-raised) | ✅ Implemented |
| Review calculates under the original's rules | ✅ Implemented |
| Review supersession + watermark + chain | ✅ Implemented |
| Commission-initiated Review | ❌ Not Implemented |
| Circulation DPC (raise, consent, sign, release) | ✅ Implemented |
| Circulation publication | ⚠️ Partially Implemented — refused on BD-14 |
| CR-shortfall resolution by circulation | ❌ Not Implemented — blocked on BD-15 |
| Self-Inspection (landing, identify, view, print, close) | ✅ Implemented |
| Self-Inspection by Aadhaar + OTP | ⭐ Implemented Differently — configurable factors |
| RAISE OBJECTION | ❌ Not Implemented — BD-20 |
| Bilingual portal (en + hi) | ✅ Implemented |
| Reports + CSV export, correctly scoped | ✅ Implemented |
| In-app notifications | ✅ Implemented |
| External SMS / email notifications | ⚠️ Partially Implemented — log driver (B-1) |
| Audit Trail screen (3 sources) | ✅ Implemented |
| Audit of officer evaluation data | ❌ Not Implemented — models not `Auditable` |
| Evidence immutability (application layer) | ✅ Implemented |
| Evidence immutability (database grants) | ⚠️ Partially Implemented — command exists, grants unapplied (B-3) |
| Digital signature pipeline | ✅ Implemented (mock provider; real is B-2) |
| Feature-flagged integrations, off by default | ✅ Implemented |
| Enforced CSP + response security headers | ✅ Implemented |
| UUID-only public identifiers | ✅ Implemented |
| Fine-grained permissions | ❌ Not Implemented — role granularity only |
| Sealed cover | ❌ Not Implemented — BD-48 |
| Cascade vacancy | ❌ Not Implemented — BD-44 |

---

# 12. Implementation Summary

| | Count | Share |
|---|---:|---:|
| **Total requirements assessed** | **94** | 100% |
| ✅ Fully Implemented | **66** | **70%** |
| ⚠️ Partially Implemented | **13** | 14% |
| ⭐ Implemented Differently (business outcome met, mechanism deviates) | **4** | 4% |
| ❌ Not Implemented | **11** | 12% |

**Of the 11 not implemented, only two are unexplained by a recorded decision** — the Commission-initiated Review (R81) and the dead `member_level` configuration (R48). The remaining nine are either deliberate deviations (Aadhaar login, DPC-ID login), deliberate withdrawals (Commission Member master), or explicitly blocked on a business decision (RAISE OBJECTION/BD-20, CR-shortfall circulation/BD-15, sealed cover/BD-48, cascade vacancy/BD-44), plus the two structural gaps this audit is raising for the first time (officer-data audit, fine-grained permissions).

**Counting differently, because the framing matters.** If the Aadhaar-authentication family is counted as **one** requirement rather than eleven slides, and the seven decision-blocked items are set aside as *awaiting CGPSC* rather than *incomplete*, the engineering completion rate is approximately **93%**, with the residue being the two genuine gaps and the four deployment blockers.

---

# 13. Final Report

## 13.1 What is fully completed

The **entire promotion pipeline runs end to end in code, with evidence at every step**: department registration → Pre-DPC (versioned, active-exercise-controlled) → DPC proposal derived from it → committee (combined-attribute compliance, mandatory CGPSC Chairman, admin replacement) → working officers → **derived vacancy** → seniority list gated on the zone → evaluation chart projected from that list → merit calculation → allotment with a deadline → acceptance and scheduling → the sitting with appended corrections → main and waiting lists → approval → hashed, signed, verifiable documents → publication. Review DPC and Circulation DPC are complete as their own aggregates. Self-Inspection is complete but for the objection button.

Around it, and finished to a standard well above the norm:

- **A merit engine that is reproducible by construction.** Every calculation stores its own complete `RuleSet` snapshot; `fromSnapshot()` and `toArray()` are exact inverses pinned by a golden test; a 2026 calculation re-runs in 2031 under 2026's rules. Runs and results are immutable in the application and enumerated for database-level grants.
- **Golden tests against the decks' own worked answers** — the S17 zone figure, the S18 rows, nine of ten S33 marks.
- **Structural test guards** that make classes of regression impossible rather than merely detected (§9.1).
- **A quality gate that is green today**, including PHPStan level 6 with no baseline and a real MySQL schema build.
- **Bilingual throughout** — 1,719 keys per locale, genuinely translated, and smoke-rendered in both.
- **Enforced CSP** earned by removing every inline handler first.
- **A discipline of refusing rather than guessing.** Where CGPSC has not ruled, the system names the decision (`BD-n`) on screen and declines. That is the most valuable property in this codebase and it should not be traded away for completeness.

## 13.2 What is partially completed

1. **Held promotions** — detection and release are wired; recording a Commission hold and re-detecting have controllers, requests and services but **no screen**.
2. **Multi-role accounts** — switching works; simultaneous departmental roles do not.
3. **Approval signatures** — documents are signed; the `approve_result` transition still declares `signature => false`.
4. **Scheduled-time enforcement** — recorded, not enforced (config default).
5. **Documents** — HTML, behind a contract, pending B-2.
6. **External notification delivery** — the full pipeline exists behind a log driver, pending B-1.
7. **Evidence immutability** — complete in the application; the database grants that close the query-builder bypass have never been applied (B-3).
8. **Circulation publication** — everything but the final release, pending BD-14.

## 13.3 What is still pending (engineering, not policy)

1. **Commit the working tree.** 422 changed paths, including 24 migrations and every language file, exist only on this disk (§8.7). Nothing else on this list matters as much.
2. **Audit the officer-data models** (S-2) — nine models, one trait each, plus a volume decision.
3. **Test and harden `committee/search`** (A-1, T-1, V-1) — a Form Request, a throttle, and tests.
4. **Close the named test gaps** T-2 … T-8.
5. **Remove the dead code** at §8.2 — six items, all small.
6. **Correct the documentation** at §10.2–§10.4 — the README's five wrong numbers, the handbook's self-contradiction, the four stale root `PPT_*` files, and the six undocumented screens.
7. **Decide the Commission-initiated Review** (R81).

## 13.4 What is blocked pending business clarification

Thirty-three open questions are recorded; these are the ones that change what the software does:

| | Question | Effect today |
|---|---|---|
| **BD-39** | How is a qualification compared with the Recruitment Rule's? | The gate is **advisory** and refuses nothing. The only open item that could put a *wrong refusal* on a signed list — which is why the code declines to make the comparison. **Answer first** |
| **BD-9** | Which waiting list when a category has no vacancy? | **Raises no flag.** The system considers the calculation complete and would publish it. A confidently wrong published list is worse than a blocked one. **Answer second** |
| **BD-2 / BD-3** | Reserved-category relaxation — automatic or the Committee's discretion? | Disabled. Such a DPC produces a fully visible but **provisional** result that cannot be published. The most commonly-hit limitation |
| **BD-15** | May a CR-shortfall hold be resolved by Circulation, or must it go to Review? | Those officers have **no resolution route at all**. A real gap in service to them |
| **BD-14** | From what date does a circulated promotion take effect? | Publication refused, by name |
| **BD-19** | How does an individual officer sign in to Self-Inspection? | Running on the three-facts default, whose weakness is that those facts appear on a circulating seniority list |
| **BD-20** | Is an online objection in scope? | RAISE OBJECTION not built |
| **BD-29** | Must a committee member out-rank the post being filled? | Unenforced; the config key is dead |
| **BD-27** | Is the same employee number in two departments one officer or two? | Reported as a mismatch, never resolved automatically |
| **BD-44 / BD-48 / BD-49** | Cascade vacancy / sealed cover / Class-I→I grade vocabulary | Not modelled; all three would change a published list |

And four deployment blockers, none of them software: **B-1** SMS/email gateway (**the only true blocker — pure procurement lead time, start it now**), **B-2** the CSC eSign interface contract (one sentence of answer decides whether a PDF renderer is scheduled), **B-3** the evidence-table grants (a DBA runs `security:evidence-grants` and then `--verify`), **B-4** the capacity and backup plan for permanently-retained evidence.

## 13.5 What to do next, in priority order

| # | Action | Effort | Why this order |
|---|---|---|---|
| 1 | **Commit and tag the working tree** | 15 min | There is no rollback point for three registers of destructive schema change. Everything else risks being lost |
| 2 | **Start B-1 procurement** | External | Pure lead time; nothing engineering does shortens it; without it nobody can sign in production |
| 3 | **Ask CGPSC's CSC supplier the B-2 question** | External | One sentence decides whether a PDF renderer is scheduled |
| 4 | **Get BD-39 and BD-9 answered** | External | BD-39 is the only item that can produce a wrong *refusal*; BD-9 is the only one that raises no flag and would publish |
| 5 | **Make the officer-data models `Auditable`** (S-2) | ~1 hour | Closes the one hole in an otherwise complete evidence chain, in the tables a promotion actually turns on |
| 6 | **Harden and test `committee/search`** (A-1, T-1, V-1) | ~2 hours | The only unvalidated, untested, PII-returning endpoint in the application |
| 7 | **Apply and verify the evidence grants** (B-3) | DBA, ~1 hour | The command exists. Re-verify at every release — a grant can be widened by an unrelated change |
| 8 | **Fix the documentation** (§10.2–§10.4) | ~3 hours | The four root `PPT_*` files actively understate the system to the first person who opens the repository |
| 9 | **Wire or delete the hold routes** (Q-1, Q-2) | ~1 hour | Either finish S45's Commission-recorded hold, or stop shipping an unreachable endpoint |
| 10 | **Close test gaps T-2 … T-8; remove the dead code at §8.2** | ~1 day | Ordinary hygiene, once the above is done |
| 11 | **Decide R8 (multi-role) and R81 (Commission-initiated Review)** | External | Both are business questions the code has already answered one way without being asked |

---

## Auditor's closing note

This is a well-built system. The engineering discipline is unusually high — level-6 static analysis with no baseline, a 0.69:1 test-to-code ratio, golden tests against the decks' own arithmetic, structural tests that fail the build when someone forgets to make a decision, an enforced CSP, UUID-only public identifiers, and an evidence chain that survives configuration change. The habit of **refusing and naming the decision** rather than guessing a business rule is the right instinct for software whose outputs are contested in a tribunal, and it has been applied consistently.

The two things I would not let pass are the **uncommitted working tree** and the **unaudited officer-data tables**. The first because three change registers' worth of destructive schema migration exists in exactly one place; the second because this system's entire value proposition is that it can explain a promotion years later, and the CR grade is the number the promotion turns on.
