# DPC Project — Progress Report

**Date:** 2026-08-14
**Source of truth:** `docs/ODPC_NEW_FINAL.pptx` (52 slides, `S<n>`) and `docs/CONVENTIONAL DPC PROCESS.pptx` (7 slides, `C<n>`). Cross-checked against `docs/design/*.md`, git history, and the live codebase.
**Stack:** PHP 8.4 / Laravel 13.23.0 / MySQL / Redis (cache+queue+sessions) / Blade + Bootstrap 5.3. No Node/Vite build step. Architecture: thin controllers → Domain Services → Eloquent models, under `app/Domain/{Authentication,Cgpsc,Department,Dpc,Eligibility,Import,Master,PreDpc,Proceeding,Review,Shared,Signature,UserManagement}` + `app/Integrations`. **All external integrations are behind `FEATURE_*` flags (default off) and the Provider Pattern** — business logic never calls an external system directly; only Providers may.
**Verified today (full gate re-run 2026-08-14):** PHPUnit → **620 passed / 1902 assertions, 0 failing** (95 MB peak, 1m19s, run bounded at 2 GB). `vendor/bin/pint --test` → passed. `vendor/bin/phpstan analyse` (Larastan level 6) → 0 errors. `composer audit` → no advisories. `php artisan migrate:fresh --seed` → 43 migrations, **62 tables** live in `dpc_dev`, 10 seeders. Freeze diff against `v0.7.0-m7-complete` → empty. All four gate steps are mandatory per phase (`composer gate`).

**This run replaced figures that were not reproducible.** The line above previously read *607 passed / 1865 assertions* and *61 tables*. **Two P0 defects in M8 were found by running the gate, both now fixed:** a circular container dependency among M8's four decorators, which crashed the PHP process on every document generation and — under this environment's `memory_limit=-1` — exhausted the host rather than failing; and a foreign-key identifier one character over MySQL's 64-character limit, which meant `dpc_document_review_references` had never been created in the database. The second survived 84 green tests because the suite runs on **in-memory SQLite**, which does not enforce that limit (**TD-24**).

**M5 ENGINE FREEZE (2026-08-13):** the Eligibility Algorithm, Scoring Algorithm, Zone Logic and Super Seat Logic are frozen. Future changes only through Configuration, Master Data or Government Notification — never source code; bug and security fixes remain permitted. Only *approved* rules are frozen — unresolved business rules are excluded and are tracked in `docs/PENDING_BUSINESS_DECISIONS.md`.

**M6 FREEZE (2026-08-13):** M6 (CGPSC Workflow) is frozen on closure — no further functional changes; **bug fixes only**. All three completed workflow milestones are now under freeze: M4 architecture-frozen, M5 engine-frozen, M6 frozen.

**M8 FREEZE (2026-08-14) — ACTIVE.** M8 (Review DPC) is frozen at baseline `v0.8.0-m8-phase3` / `a60689d`, by architect decision, recorded permanently as **AD-21**: (1) no production code changes inside M8; (2) bug fixes only, each with Architect approval; (3) documentation may continue; (4) **M9 development must not modify the M8 implementation**; (5) any other change requires a formal **Freeze Exception**. **Five freezes are now in force** — M4, M5, M6, M7, M8 — and M8's is the strictest, because the other four accept security, performance and hardening work without per-change approval while M8 does not. Verified by `git diff --name-only v0.8.0-m8-phase3 -- app/Domain/Review`, which must also cover M8's views (`resources/views/dpc/reviews/`, `documents/review-context.blade.php`, `documents/superseded-watermark.blade.php`, `partials/review-banner.blade.php`) since M8's UI deliberately lives outside M4's frozen screens.

**Known limitations now have a permanent home: `docs/TECHNICAL_DEBT.md`** (created 2026-08-13). `docs/ARCHITECT_REVIEW.md` is replaced in full at each phase, so a carried-forward risk previously survived only by being manually re-copied. Items move to Resolved rather than being deleted. Currently 15 open engineering items, 2 blocking business items, 9 documented interpretations, 9 resolved.

**M7 COMPLETE (closed 2026-08-14):** design approved with all eight architect questions ruled; **complete and closed** (see Module 11). Four business questions remain open in `docs/PENDING_BUSINESS_DECISIONS.md`; M7 is not reopened for them. Every phase boundary now runs a **freeze verification** — `git diff v0.6.0-m6-complete -- app/Domain/{Eligibility,Cgpsc,Dpc}` must be empty. It has been, for all four phases — including Phase 3, where the freeze was most at risk because CALCULATE is M5's own entry point.

**UAT documentation set (2026-08-13, documentation only):** `docs/INTEGRATION_ROADMAP.md` (every integration with its flag, provider, events, dependencies and status), `docs/UAT_CHECKLIST.md` (~60 business-language scenarios, one expected outcome each), `docs/DEMO_SCRIPT.md` (Parts 0–9 following the PPT, with a 15-minute short version). The **UAT Readiness Review** was prepared and approved; M7 followed.

**Updated 2026-08-13 (post-approval):** documentation cleanup executed (archive/restore/delete per `docs/project-documentation-audit.md`); P0 rate-limiting gap closed (see Module 2); decisions Q4, Q9, Q10 ruled; **M4 COMPLETE — all 7 phases** (Post Selection, Committee, Working Officers + Vacancies, Seniority List, Evaluation/Tabulation Chart, Excel Import, Closeout) — 145 tests / 516 assertions passing, Pint clean, PHPStan 0 errors, `composer audit` clean, 50 DB tables. Applying the new **Demo Readiness Rule** caught three P1 defects that six consecutive green gates had missed (empty proposals were submittable and signable; the signed document contained only a header; the audit trail had never been tested anywhere in the project) — all fixed and covered. A **UAT Readiness Review** milestone sits between M6 and M7 (see `docs/design/05-milestones.md`). Full detail: `docs/ARCHITECT_REVIEW.md`, `docs/CHANGELOG.md`.

**M8 COMPLETE (2026-08-14):** all three phases of Review DPC are built, tested and gate-green, committed and tagged `v0.8.0-m8-phase3`. **BD-11** (what supersession means for an officer already promoted) is a **Deferred Business Decision — Required before production activation of the Review supersession policy** by architect ruling of 2026-08-14 and **does not block M8 completion**. **No file in M4, M5, M6 or M7 was modified**, verified by full-repository diff at every phase boundary; M8 extends M7 by **decoration** (AD-20), with four decorators bound over it in the container. **M9 (Circulation DPC) has NOT started.** See the Implementation Summary at the end of this document for the current status, remaining milestones, pending decisions, debt, suggestions and next steps.

This document is the single source of truth for project status and for AI progress/suggestions going forward, per your instruction. It is replaced in full at each phase boundary and no additional progress markdown file is created. It supersedes any per-milestone status claims in older worklog files.

---

## How to read this report

Every module below corresponds to a stage of the 6-stage ODPC process (or a cross-cutting concern called out separately in the PPT/traceability matrix) and to one of the 11 implementation milestones in `docs/design/05-milestones.md`. Status:

- ✅ **Completed** — implemented, tested, passing the quality gate, and matches the PPT.
- 🟡 **Partially Completed** — some part is implemented (usually as a byproduct of an earlier milestone), but the module's own milestone hasn't been built yet.
- ⚪ **Not Started** — no code for this module exists yet.

---

## Module 1 — Foundation & Platform

**PPT Reference:** N/A (infrastructure, not a PPT screen) · **Milestone:** M0 · **Status:** ✅ Completed

1. **Purpose:** Stand up the Laravel 13 application, dev environment (MySQL `dpc_dev`, Redis), quality tooling, and the bilingual (EN/HI) shell every later screen builds on.
2. **Business Workflow:** N/A — platform scaffolding.
3. **Current Implementation Status:** Done. Laravel 13.23.0 installed at repo root; Bootstrap 5.3.3 vendored (no CDN/build step); `/health` endpoint probes DB + Redis + cache; bilingual shell (`lang/en`, `lang/hi`, `SetLocale` middleware, `/lang/{locale}` switch route).
4. **Database Tables:** None business-specific (base Laravel `users`, `cache`, `jobs` tables only at this stage).
5. **Backend Components:** `App\Http\Controllers\HealthController`; `App\Http\Middleware\SetLocale`.
6. **Frontend Components:** `resources/views/layouts/app.blade.php`, `resources/views/welcome.blade.php`.
7. **APIs:** `GET /`, `GET /health`, `GET /lang/{locale}`.
8. **Business Rules Implemented:** N/A.
9. **Remaining Work:** None for this milestone's scope.
10. **Dependencies:** None (first milestone).
11. **Next Milestone:** N/A — feeds into all subsequent milestones.

---

## Module 2 — Authentication & RBAC

**PPT Reference:** S8, S10, S13, S20, S27, S29, S32, S36, S39, S42, S46, S50 (login screens, all actors) · **Milestone:** M1 · **Status:** ✅ Completed

1. **Purpose:** Let every actor (AO, AH, Chairman, Member, Individual, Admin) log in securely, with role-based access control gating every screen.
2. **Business Workflow:** PPT shows Aadhaar No. + Aadhaar OTP as the login/e-sign mechanism on every screen. **Approved deviation (decision Q1, 2026-07-30):** replaced with email-or-mobile + password login, dual email+mobile OTP verification at registration, and OTP step-up at each legally significant signing action (logged to the audit trail as record of intent).
3. **Current Implementation Status:** Done. 6 roles seeded via `spatie/laravel-permission` (admin, authorized_officer, administrative_head, chairman, member, individual); login accepts email or mobile; session regeneration on login; forced password change on first login (system-issued temp passwords); deactivated accounts blocked.
4. **Database Tables:** `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, `otp_verifications`, `signatures` (e-sign record of intent), `audits` (owen-it/laravel-auditing).
5. **Backend Components:** `App\Domain\Authentication\Services\{OtpService,VerificationService}`; `App\Domain\Authentication\Models\{User,OtpVerification}`; `App\Http\Controllers\Auth\{LoginController,VerificationController,PasswordChangeController}`; `App\Http\Middleware\EnsurePasswordChanged`; role middleware alias.
6. **Frontend Components:** `resources/views/auth/{login,verify,change-password}.blade.php`.
7. **APIs:** `GET/POST /login`, `GET/POST /verify`, `GET/POST /password/change`, `POST /logout`.
8. **Business Rules Implemented:** Both email and mobile must be OTP-verified before the account is usable; login by email or mobile; role-gated route groups (`role:` middleware) on every authenticated area.
9. **Remaining Work:** Fresh OTP step-up is wired at the Pre-DPC approval step (M3); still needs wiring at each future legally-significant action (M4 approval, M7 CGPSC approval, etc.) as those milestones are built. ~~Rate limiting on `/login`/`/verify`~~ — **closed 2026-08-13**: config-driven `RateLimiter` (`config/dpc.php → rate_limiting`) applied via `throttle:login`/`throttle:otp-verify`, keyed per identifier+IP, tested in `tests/Feature/Auth/RateLimitingTest.php`. Self-service Forgot Password / Password Reset do not exist in the app (not in the PPT, not built) — raised as an open question in `docs/ARCHITECT_REVIEW.md` rather than invented silently.
10. **Dependencies:** M0.
11. **Next Milestone:** N/A — cross-cutting, consumed by every later milestone.

**Composer packages added:** `spatie/laravel-permission` ^8.3, `owen-it/laravel-auditing` ^14.0.

---

## Module 3 — Department Registration

**PPT Reference:** S5–S6 (Registration of Departments) · **Milestone:** M1 · **Status:** ✅ Completed

1. **Purpose:** Let a department self-register for Online DPC and receive its two-tier accounts.
2. **Business Workflow:** Department picks itself from a master list, enters district/place/PIN and both AH (Level-2) and AO (Level-1) details; on submit, both accounts are provisioned and credentials are issued over mobile + email (S5).
3. **Current Implementation Status:** Done, PPT-faithful field-for-field (S6), with district/designation now via dropdown (linked to master data added in M2, not free text).
4. **Database Tables:** `departments`.
5. **Backend Components:** `App\Domain\Authentication\Services\RegistrationService`; `App\Domain\Department\Models\Department`; `App\Http\Controllers\Auth\RegistrationController`.
6. **Frontend Components:** `resources/views/auth/register.blade.php` (bilingual, PPT-faithful labels).
7. **APIs:** `GET/POST /register`.
8. **Business Rules Implemented:** Department must exist and be `unregistered`; PIN is 6 digits; AO/AH mobiles are 10 digits, unique, and must differ from each other; department email required, `.gov.in`, unique; department code (from the master list) will prefix the future DPC ID.
9. **Remaining Work:** None for this milestone. AH-only accounts currently verify mobile only (PPT gives AH no email field) — noted as a design characteristic, not a defect.
10. **Dependencies:** M0.
11. **Next Milestone:** Feeds Pre-DPC Proposal (M3) — AO/AH created here are the actors of that workflow.

---

## Module 4 — Master Data & Rule Configuration

**PPT Reference:** S6, S9, S18, C4–C6 (§10 Master Modules in `01-requirements.md`) · **Milestone:** M2 · **Status:** ✅ Completed

1. **Purpose:** Provide the reference/config data every later workflow depends on — masters must exist and be admin-manageable before Pre-DPC, Proposal, or the Eligibility Engine can be built, per decision Q3 (config/master-data-driven rules, PPT values seeded as defaults, never hardcoded).
2. **Business Workflow:** CGPSC Admin (role added per decision Q2 — not in the PPT, explicitly approved) maintains districts, designations, pay scales, cadres, reservation categories/policy/roster, committee types/configuration, and versioned recruitment rules through an admin CRUD area.
3. **Current Implementation Status:** Done. 15 master/lookup models, each carrying a lifecycle status (Draft/Active/Inactive/Archived), soft deletes, and `created_by`/`updated_by` audit columns via a shared `IsMaster` trait. Reservation policies, rosters, committee configuration, and recruitment rules are effective-dated; **recruitment rules are versioned and never overwritten** (amend = new version). Government reservation percentages are deliberately **not seeded** (commit `a90ea0c`) — left for the Admin to configure per current government notification, consistent with "never hardcode statutory values."
4. **Database Tables:** `districts`, `designations`, `pay_scales`, `cadres`, `reservation_categories`, `review_reasons`, `vacancy_types`, `dpc_types`, `reservation_policies`, `reservation_rosters`, `reservation_roster_points`, `committee_types`, `committee_configurations`, `recruitment_rules`, `recruitment_rule_feeders` (15 tables).
5. **Backend Components:** `App\Domain\Master\Services\{MasterCrudService,RecruitmentRuleService}`; 15 models in `App\Domain\Master\Models`; `App\Domain\Master\Concerns\IsMaster` trait.
6. **Frontend Components:** `layouts/admin.blade.php`, shared `admin/partials/{lookup-index,lookup-form}` + 15 per-master `index`/`form` view pairs (30 Blade files) under `resources/views/admin/`.
7. **APIs:** 15 resource route groups under `/admin/*` (role:admin), plus `POST recruitment-rules/{id}/version` and `GET recruitment-rules/{id}/download`.
8. **Business Rules Implemented:** Master lifecycle states; unique codes/names (self-ignored on update); `.gov.in` email / 10-digit mobile validation where relevant; percentages constrained 0–100; `max_members ≥ min_members`, `quorum ≤ max_members` for committees; roster points ≤ roster size and unique; GO PDF upload validated (`application/pdf`, ≤10MB); recruitment rules never overwritten, only re-versioned.
9. **Remaining Work:** None for this milestone. No statutory computation lives here — that's the Eligibility Engine (M5), which will read these masters/config.
10. **Dependencies:** M0. Registration (M1) now reads District/Designation from here.
11. **Next Milestone:** Feeds Pre-DPC Proposal (M3, cadre/recruitment-rule) and later the Eligibility Engine (M5, category/threshold/zone-rule data).

---

## Module 5 — User Management (CGPSC account provisioning)

**PPT Reference:** Not shown in PPT — see decision Q2 · **Milestone:** M2 (phase 7) · **Status:** ✅ Completed

1. **Purpose:** The PPT's login screens for Chairman/Member accounts imply those accounts exist, but no screen creates them. Decision Q2 added a CGPSC Admin role to provision them.
2. **Business Workflow:** CGPSC Admin creates Chairman/Member/Admin accounts, assigns roles, activates/deactivates, and resets passwords (system issues a temporary password, forcing change on first login — reuses M1's `EnsurePasswordChanged` mechanism).
3. **Current Implementation Status:** Done.
4. **Database Tables:** Uses the `users` table (from M1) + Spatie role tables; no new tables.
5. **Backend Components:** `App\Domain\UserManagement\Services\UserProvisioningService`.
6. **Frontend Components:** `resources/views/admin/users/{index,form}.blade.php`.
7. **APIs:** `admin/users` resource (index/create/store/edit/update), `POST admin/users/{user}/toggle-active`, `POST admin/users/{user}/reset-password`.
8. **Business Rules Implemented:** Provisionable-role restriction (Admin can only create the roles this screen is meant for); temp password + forced change on first login; activate/deactivate.
9. **Remaining Work:** None for this milestone.
10. **Dependencies:** M1 (auth/RBAC foundation).
11. **Next Milestone:** Provisioned Chairman/Member accounts are consumed starting at M6 (CGPSC Workflow).

---

## Module 6 — Pre-DPC Proposal

**PPT Reference:** S7–S11 ("Posts Sanctioned for Promotion" — entry + approval) · **Milestone:** M3 · **Status:** ✅ Completed

1. **Purpose:** The department's Authorized Officer records which posts have promotion quotas, at what pay scale, promotion %, and feeder cadres (per Schedule-IV of the recruitment rules); the Administrative Head approves before anything reaches CGPSC.
2. **Business Workflow (S7–S11):** AO enters posts sanctioned for promotion (pay scale, % direct recruitment, % promotion recruitment, up to 2 feeder cadres each with % and required experience) → AH reviews and **approves** (digitally signed) or **returns** for correction. Approved data becomes the department's authoritative promotion-post basis.
3. **Current Implementation Status:** Done. Built on a new, reusable **config-driven workflow engine** (`app/Domain/Shared/Workflow`) that later milestones (M4, M7, M8, M9) will reuse. State machine: `DRAFT → SUBMITTED → APPROVED` (terminal) with a `RETURNED` correction loop and a `CANCELLED` terminal from DRAFT/RETURNED. On AH approval, data **projects into** the Cadre + Recruitment Rule masters (new recruitment-rule version each approval) — the single source of truth decision (Q1 of the M3 questions log). Approved versions are immutable; amending creates a new DRAFT version (parent-linked), never overwriting history.
4. **Database Tables:** `workflow_transitions` (generic append-only ledger, reused by future workflows), `pre_dpc_proposals` (versioned; audit + soft deletes), `pre_dpc_posts`, `pre_dpc_post_feeders` (4 tables).
5. **Backend Components:** `App\Domain\Shared\Workflow\{WorkflowEngine,WorkflowTransition,WorkflowException}`; `App\Domain\Shared\Events\WorkflowTransitioned` + queued listener; `App\Domain\PreDpc\Services\PreDpcProposalService`; `App\Domain\PreDpc\Models\{PreDpcProposal,PreDpcPost,PreDpcPostFeeder}`; `App\Http\Controllers\PreDpc\PreDpcProposalController`.
6. **Frontend Components:** `resources/views/pre-dpc/{index,form,show}.blade.php` — repeatable posts + nested feeder rows, role-aware workflow action buttons, workflow-history panel.
7. **APIs:** 11 routes under `/pre-dpc` (role `authorized_officer|administrative_head`): index, create, store, show, edit, update, submit, approve, return, cancel, amend.
8. **Business Rules Implemented:** AO-only editing while DRAFT/RETURNED; AH approval **requires a digital signature** (`SignatureService` — Mock provider in dev/UAT, production hard-refuses to approve without a real CSC DigiSign provider configured, by design — never silently bypassed); approved = immutable; one current version per department (`is_current`); submit requires ≥1 post + GO number + effective date; feeders resolved to a department cadre by id/name project into recruitment-rule feeders, unresolved feeders stay recorded on the Pre-DPC as source of truth but aren't projected (documented limitation, CQ1).
9. **Remaining Work:** None for M3 scope.
10. **Dependencies:** M1 (auth/roles), M2 (Cadre + Recruitment Rule masters it projects into).
11. **Next Milestone:** M4 (DPC Proposal) will read the approved Pre-DPC / projected masters as its input.

**Tests:** `tests/Feature/PreDpc/PreDpcWorkflowTest.php` — 7 tests (create+submit, AH approval signs + projects to masters, AO-cannot-approve, illegal transition rejected, return-with-remarks, amend creates new version, submit-requires-GO-number).

---

## Module 7 — CSC DigiSign / E-Signature

**PPT Reference:** "By providing Aadhar No. And Aadhar OTP you are digitally signing the content" — every login/action screen (S8, S10, S13, S20, etc.) · **Milestone:** cross-cutting, introduced M1, load-bearing from M3 onward, live integration is unscheduled · **Status:** 🟡 Partially Completed

1. **Purpose:** Every legally significant action in the PPT (approval, allotment, scheduling, result approval) is meant to carry a digital signature / record-of-intent, replacing the PPT's Aadhaar-OTP mechanism per decision Q1.
2. **Business Workflow:** OTP step-up at the moment of signing, written to an immutable signature/audit record, tied to the object being signed (e.g. a Pre-DPC approval).
3. **Current Implementation Status:** The **abstraction and Mock provider** are built and wired into the one workflow that exists so far (Pre-DPC approval, M3). The **real CSC DigiSign integration is not built** — production approval currently hard-fails (by design, not a bug) until a real provider is configured, because CGPSC's CSC API details aren't available yet.
4. **Database Tables:** `signatures`.
5. **Backend Components:** `App\Domain\Signature\Services\SignatureService`; `App\Domain\Signature\Models\Signature`; `App\Integrations\Contracts\SignatureProvider` with a Mock implementation (`App\Integrations\Mock`).
6. **Frontend Components:** Signature step surfaced inline in the Pre-DPC approval action (no dedicated screen).
7. **APIs:** Consumed internally by `PreDpcProposalService::approve()`; no standalone route yet.
8. **Business Rules Implemented:** Signature mandatory on approval, no environment can silently skip it; Mock in dev/UAT, CSC-only in production.
9. **Remaining Work:** Real CSC DigiSign API integration (needs CGPSC to supply API access/credentials — external dependency, not a code task; the information needed and the planned implementation shape are now documented in `docs/integration/CSC-DigiSign.md`, created 2026-08-13); re-wiring the same service at every future approval point (M4, M6, M7, M8, M9).
10. **Dependencies:** M1 (OTP), M3 (first consumer).
11. **Next Milestone:** Becomes load-bearing again at M4 (AH proposal approval) and M7 (CGPSC result approval) — same service, no rework expected, just more call sites.

---

## Module 8 — DPC Proposal (Detailed)

**PPT Reference:** S12–S25 (proposal entry S12–S18, AH approval S19–S25) · **Milestone:** M4 · **Status:** ✅ Completed (all 7 phases)

1. **Purpose:** The core proposal the AO sends to CGPSC — post-to/post-from + class, legal-stay declaration, DPC committee, working officers under consideration (with vacancy sub-types), feeder seniority list, and the tabulation/evaluation chart — approved by AH and forwarded to CGPSC.
2. **Business Workflow (S12–S25):** AO selects posts (to/from + class, DPC type, proposal date) → names the DPC committee → enters/uploads working officers under consideration with a vacancy breakdown (clear/promotion/retirement/deputation) → enters/uploads the feeder seniority list (zone count shown, e.g. "2×2+4=8") → enters/uploads the tabulation/evaluation chart (loyalty, immovable property, year-wise CR, notes) → AH reviews and approves (a single approval event covering every section — architect decision, 2026-08-13) → forwarded to CGPSC, DPC ID generated.
3. **Current Implementation Status:** **Complete — all 7 phases shipped 2026-08-13** (Post Selection S14, Committee S15, Working Officers + Vacancies S16, Seniority List S17, Evaluation/Tabulation Chart S18, Excel Import S16–S18, Closeout S19–S25). `DpcProposal` reuses the M3 workflow engine unchanged; post-to/post-from select an existing department `Cadre`; approval generates a race-safe DPC ID and forwards to CGPSC. Committee members reference an existing `User` wherever possible with an external-member fallback. Vacancies are strictly row-based (one row per vacancy-type × reservation-category pair). Working officers, seniority entries, and evaluation rows are all proposal-time snapshots carrying the identical standard ten-field employee set (architect directive) — plain columns, no live employee/HRMS reference anywhere. **Every section is immutable once a proposal leaves DRAFT/RETURNED, enforced at both the Policy and Service layers** (verified directly by tests that call the Service bypassing HTTP entirely). The evaluation chart stores raw data only: no mark, remark, score, total, service-year, experience, or zone column exists, asserted by a test against the persisted column list. No CALCULATE/zone-of-consideration logic anywhere — that's M5, deliberately not built here even though the S16/S17 mockups show a CALCULATE button. Every section save is recorded on the Activity Timeline, and every officer array is bounded from configuration. Phase 7 added the **Administrative Head review walkthrough** (S21–S25: five read-only steps, with approve/return only on the final step — the PPT's per-screen APPROVE buttons and the single-approval ruling reconciled), **final workflow validation** (a proposal cannot be submitted until the S14→S18 sequence is complete), a **signed document containing every approved section**, and the first genuine **audit-trail coverage** in the project.
4. **Database Tables:** `dpc_proposals` (+`sanctioned_post_count`), `dpc_id_sequences`, `dpc_committee_members`, `dpc_vacancies`, `dpc_working_officers`, `dpc_seniority_entries`, `dpc_evaluation_rows`, `dpc_evaluation_cr_grades`, `dpc_evaluation_loyalty_flags`, `dpc_evaluation_property_flags` (all 10 planned tables built), plus `import_batches` and `import_rows` for the staged Excel import.
5. **Backend Components:** `App\Domain\Dpc\Services\{DpcProposalService,DpcIdGeneratorService}`; `App\Domain\Dpc\Models\{DpcProposal,DpcIdSequence,DpcCommitteeMember,DpcVacancy,DpcWorkingOfficer,DpcSeniorityEntry,DpcEvaluationRow,DpcEvaluationCrGrade,DpcEvaluationYearFlag,DpcEvaluationLoyaltyFlag,DpcEvaluationPropertyFlag}`; `App\Domain\Dpc\Enums\{DpcProposalStatus,CommitteeCapacity}`; `App\Domain\Dpc\Policies\DpcProposalPolicy`; `App\Domain\Dpc\Requests\{SaveDpcProposalRequest,ReturnDpcProposalRequest,SaveDpcCommitteeRequest,SaveWorkingOfficersRequest,SaveSeniorityEntriesRequest,SaveEvaluationChartRequest}`; `App\Domain\Dpc\Validation\DpcRowRules` (one shared row-rule definition per section, used by both manual entry and import); `App\Domain\Import\{Enums\ImportTemplate,Enums\ImportBatchStatus,Models\ImportBatch,Models\ImportRow,Services\ExcelImportService,Requests\UploadImportFileRequest,Support\RawSheetImport}`; `App\Http\Controllers\Dpc\{DpcProposalController,DpcImportController}`; `Database\Seeders\CommitteeMasterSeeder`.
6. **Frontend Components:** `resources/views/dpc-proposals/{index,form,show,committee,working-officers,seniority-list,evaluation-chart}.blade.php` plus `imports/{preview,_upload}.blade.php`; dashboard entry point added. All three officer screens offer manual entry **or** Excel upload, per PPT §10. Proposal Detail renders the S18 chart as year-grouped columns and eager loads every section (architect directive), asserted by a query-count regression test.
7. **APIs:** 24 routes under `/dpc-proposals` (role `authorized_officer|administrative_head`, policy-checked): index, create, store, show, edit, update, committee (get/put), working-officers (get/put), seniority-list (get/put), evaluation-chart (get/put), review (5-step AH walkthrough), imports (store / show / errors / commit / cancel), submit, approve, return, cancel.
8. **Business Rules Implemented:** *(Phase 1)* DPC ID generation `<DeptCode><Year><Serial>`, race-safe via a locked per-department-per-year sequence; post-to ≠ post-from; both cadres scoped to the acting AO's department; only `fresh`-type proposals; every transition locks the row before checking status. *(Phase 2)* exactly one Chairman required; no duplicate user/mobile; committee size within the active `CommitteeConfiguration`; external members blocked when disallowed; per-member audit + one timeline entry per save. *(Phase 3)* vacancies are row-based (vacancy type × reservation category, no duplicate pairs); working officers stored as plain snapshot columns. *(Phase 4)* seniority entries stored as plain snapshot columns; no duplicate seniority numbers within one list. *(Phase 5)* evaluation chart rows carry the standard ten-field snapshot plus tribal-area service years and Note; CR grade, Loyalty, and Immovable Property are each stored per year (Q4), all nullable so "recorded as No" stays distinct from "not recorded" — the distinction the Circulation rule (Q12) turns on, and why a partial chart is accepted; CR grade codes validated against config (Q3); no duplicate S.N. within a chart and no duplicate year within an officer. All sections immutable outside DRAFT/RETURNED, enforced at both Policy and Service layers. *(Phase 6)* Excel import runs a staged **Preview → Validation → Error Report → Commit** pipeline: an upload is parsed, mapped, and validated into staged rows and writes nothing until an explicit commit, which hands the valid rows to the same service methods manual entry uses. Rows are rejected individually with per-field reasons; a CSV error report is downloadable. Dates parse day-first to match the PPT templates; reservation category is resolved from its code; S18 per-year columns are matched by pattern. Only a previewed batch is committable, so double-commit and commit-after-cancel are refused. **Not yet implemented:** CSC DigiSign signing itself (schema only), M5's scoring/zone-of-consideration CALCULATE.
9. **Remaining Work:** None — M4 is complete. Three items are logged against the later UAT & Security Hardening sprint in `docs/ARCHITECT_REVIEW.md`: a capacity plan for permanently retained import files and signed documents, an audit-trail UI, and the deferred Excel template export (M11 backlog).
10. **Dependencies:** M1 (auth), M2 (masters), M3 (approved Pre-DPC posts/cadres this proposal is built from).
11. **Next Milestone:** M5 (Scoring & Eligibility Engine) — awaiting architect sign-off on the M4 closeout report before starting, per the "stop after each milestone" instruction.

---

## Module 8b — External Integration Architecture (cross-cutting)

**PPT Reference:** N/A (infrastructure) · **Milestone:** cross-cutting, established 2026-08-13 · **Status:** ✅ Completed

1. **Purpose:** Let every external integration be enabled or disabled by configuration alone, so modules can be tested independently, UAT can run without integrations whose APIs have not arrived, production can roll out in phases, and a future deployment for another state is a configuration exercise.
2. **Business Workflow:** N/A — infrastructure. Its guarantee is that **no workflow may fail because an integration is disabled.**
3. **Current Implementation Status:** Done. `config/features.php` is the single switchboard; `App\Enums\Feature` is the typed accessor; `AppServiceProvider::register()` is the only place a provider is chosen. Every integration flag defaults to off. The entire M4 workflow — draft through signed approval — is tested to run with all of them disabled. Production is tested never to sign with the mock provider, while non-production still signs so UAT stays demonstrable.
4. **Database Tables:** None.
5. **Backend Components:** `App\Enums\Feature`; `App\Integrations\Contracts\{HrmsProvider,SignatureProvider,SmsSender,DirectoryProvider}`; `App\Integrations\Mock\{MockHrmsProvider,MockSignatureProvider,LogSmsSender,SeedDirectoryProvider}`; `App\Integrations\HRMS\RealHrmsProvider`; `App\Integrations\CSC\CscDigiSignProvider`; `App\Http\Controllers\Admin\IntegrationStatusController`.
6. **Frontend Components:** `resources/views/admin/integrations/index.blade.php`; admin dashboard entry point.
7. **APIs:** `GET /admin/integrations` (CGPSC Admin only, read-only).
8. **Business Rules Implemented:** Integration flags default off; enabling an unimplemented integration fails loudly rather than degrading silently; production refuses to approve without a real signature provider and never falls back to the mock; `MockHrmsProvider` never fabricates employee data; flags are deployment configuration and are not editable from the UI.
9. **Remaining Work:** None. `RealHrmsProvider` and `CscDigiSignProvider` await CGPSC's API contracts — see `docs/integration/HRMS.md` and `docs/integration/CSC-DigiSign.md`.
10. **Dependencies:** None.
11. **Next Milestone:** Every future integration follows this pattern.

---

## Module 9 — Scoring & Eligibility Engine

**PPT Reference:** C4–C6 (scoring rules), S16–S18/S33 (CALCULATE) · **Milestone:** M5 · **Status:** ✅ Completed (all 4 phases) — **APPROVED and FROZEN 2026-08-13**

1. **Purpose:** The rules engine that turns a tabulation chart into MARK/REMARK per officer and produces the Main + Waiting lists — the single most legally sensitive piece of business logic in the system.
2. **Business Workflow:** CR grade → marks (क+=4, क=3, ख=2, ग=1, घ=0); tribal-area service +1; eligibility gates (any of last 5 CRs = घ → not eligible; CLASS-I→I: last year's CR must not be ग); qualifying score by class pair (I→I ≥15, II→I ≥13, II→II ≥11, others ≥10, reserved relaxable to 10); Super Seat (all 5 CRs = क+, sum 20 → notional seniority); Zone of Consideration (I→I: 1 vacancy → top 5, >1 → 2×vacancies+4, reserved shortfall → expand 7× that category only; other cases → all experience-qualified officers).
3. **Current Implementation Status:** **All 4 phases shipped 2026-08-13** — `ScoringService` (§5.1–§5.2), `EligibilityService` (§5.3–§5.4), `ZoneOfConsiderationService` (§5.6) and `SuperSeatService` (§5.5), plus golden cases transcribed from PPT slides 17–18 and run against the *shipped* configuration. **The PPT's own printed zone figure — "2 X 2 + 4 = 8" — is reproduced exactly**, the one number on the deck checkable without interpretation. Both engines are **pure**: no database, no clock, no `config()` read inside — they take an officer record and an immutable `RuleSet` and return a score breakdown and a verdict, which is what makes a stored calculation reproducible years later. No UI and no persistence in this phase, by architect ruling. **Reserved relaxation and the 7× reserved zone expansion (both Q5/Q6) are BLOCKED pending a business decision:** the engine applies the printed rules and *flags* every officer and category the unruled rules could have changed the answer for, rather than guessing in either direction. **Older rule versions stay executable** without an archive: every run stores its own complete rule set and `RuleSet::fromSnapshot()` reconstructs it exactly, so a calculation can always be re-run under the rules that actually applied to it. **Note for whoever builds this:** the S18 chart carries **six** CR year columns (2012–2017) while §5.4 sums only the **last five** — M4 stores all six as entered, so this engine must select its five-year window rather than assume the stored set is already the scoring set.
4. **Database Tables:** `dpc_calculation_runs` (UUID public identifier, auto-increment id internal) and `dpc_calculation_results` (one row per officer, including those on neither list). Each run stores its complete rule set, an officer-data fingerprint, executed-by/at, and build provenance; calculations are **immutable** and never overwritten. Reads `dpc_evaluation_rows`/`dpc_evaluation_cr_grades`/`dpc_evaluation_loyalty_flags`/`dpc_evaluation_property_flags` (built in Module 8, Phase 5) plus the M2 masters (`cr_grade_marks`, `qualifying_thresholds`, `zone_rules` — not yet seeded as distinct tables; currently only planned in `03-database-design.md` §G).
5. **Backend Components:** `App\Domain\Eligibility\{RuleSet,Enums\{RemarkCode,CalculationStatus,ListType},ValueObjects\{OfficerRecord,ScoreBreakdown,EligibilityVerdict,ZoneOfConsideration,SuperSeatVerdict},Models\{DpcCalculationRun,DpcCalculationResult},Services\{ScoringService,EligibilityService,ZoneOfConsiderationService,SuperSeatService,DpcCalculationService,ExplainCalculationService}}`. `DpcCalculationService` is the **only** class in the milestone that touches the database — it exists so the engines never have to.
6. **Frontend Components:** `resources/views/dpc-proposals/calculations/{index,show,explain}.blade.php`, plus the CALCULATE entry point on the proposal detail screen. History and results are addressed by **UUID only**; officers within a run by their chart serial number. Every screen renders stored data — nothing is recomputed for display.
7. **APIs:** 4 routes — `POST /dpc-proposals/{proposal}/calculate` (rate limited per user per proposal), `GET .../calculations`, `GET .../calculations/{uuid}`, `GET .../calculations/{uuid}/officers/{sn}`. All authorise `view` on the parent proposal.
8. **Business Rules Implemented:** §5.1 CR grade → marks; §5.2 tribal-area bonus (one bonus, not one per year); §5.3 both hard gates (any घ in the window; Class-I→I final CR may not be ग), with **every** failed ground recorded rather than just the first; §5.4 qualifying scores per class pair; §5.5 Super Seat (tested on the *grade*, not on a total of 20 — the two readings agree only while क+ is worth 4 — and never awarded on an incomplete CR window); §5.6 zone sizing for Class-I→I, with other class pairs uncapped as the PPT's "other cases" requires. The scoring window is the five most recent *available* years — a year with no grade is skipped, never scored as zero, since a missing CR is an administrative gap (the Circulation-DPC case), not a bad assessment. §5.7 Main List (मुख्य सूचि) and Waiting List (अनुपूरक सूचि) per reservation category, with §6.3 remark codes. **Not yet implemented:** CALCULATE wiring into the screens (Phase 4). **Blocked:** reserved relaxation and the 7× reserved zone expansion (both Q5/Q6) — as of Phase 3 these block *publishable* output, not just flagging.
9. **Remaining Work:** None — M5 is approved and frozen. **Three items remain blocked on business answers**, tracked in `docs/PENDING_BUSINESS_DECISIONS.md`: (a) whether `PUNISHMENT` in the Note column disqualifies an officer — slide 33 marks two such officers NE who score 15, while the engine passes them, since §5.3 lists only two hard gates and the slide is the only evidence for a third; (b) Q5/Q6, which are unruled *business* rules blocking publishable output; (c) the column-level `UPDATE` grant on `dpc_calculation_runs.status` — approved as a deployment recommendation; revoking `UPDATE` outright would break every calculation.

   Recorded for **M9** rather than M5: slide 33 shows an incomplete CR window as MARK "–" with REMARK CIR (the engine computes a partial score and flags it), an officer with a property `N` marked CIR (Q12's circulation trigger), and an SC officer appearing on the UR waiting list (which per-category placement would not produce).
10. **Dependencies:** M4 (evaluation data), M2 (rule masters).
11. **Next Milestone:** This is the next-but-one milestone. **Blocked by:** **Q5** (is reserved-relaxation/7× zone expansion automatic or a logged committee-discretion action?), **Q6** (does relaxation-to-10 apply only to CLASS-I→I, or all class pairs?), **Q12** (circulation trigger: "2+ years CR missing" vs. the S14 label's "CR ≤ 4" — which governs).

---

## Module 10 — CGPSC Workflow (Allotment & Scheduling)

**PPT Reference:** S26–S30 · **Milestone:** M6 · **Status:** ✅ Completed (all 4 phases)

1. **Purpose:** Once a proposal reaches CGPSC, the Chairman allots each DPC to himself or a Member; the allottee accepts and schedules (or re-allots/reschedules) a date and time.
2. **Business Workflow (S26–S30):** Proposal reaches Chairman's account → Chairman allots to self or a named Member (allotment report shows status + a default DPC date) → allottee accepts and sets DPC date/time, or re-allots/reschedules → AH and AO are notified via registered mobile + email.
3. **Current Implementation Status:** **Complete — all 4 phases shipped 2026-08-13**, covering S26–S30 in full: Chairman allotment and re-allotment with Allotment Report (S28), member acceptance, scheduling and re-scheduling with Scheduling Report (S30), and both histories rendered from timeline records with the previous allottee and previous date/time shown struck through beside the new. Design in `docs/design/07-m6-cgpsc-workflow.md`; workflow reference in `docs/WORKFLOWS.md`. The CGPSC-side state is *derived* from the current allotment rather than stored on `dpc_proposals`, so M4 stays frozen and there is no second source of truth.
4. **Database Tables:** `dpc_allotments` and `dpc_schedules` — both **append-only**, UUID public identifiers, superseded-by chains, model-layer immutability (edit and delete both throw). This supersedes the earlier sketch in `03-database-design.md` §C, which proposed mutable fields on `dpc_proposals`: S30 displays the previous date beside the new one on a reschedule, which is only possible if history is kept.
5. **Backend Components:** `App\Domain\Cgpsc\{Enums\AllotmentStatus, Models\{DpcAllotment,DpcSchedule}, Services\{AllotmentService,SchedulingService}, Policies\DpcAllotmentPolicy, Requests\{AllotDpcRequest,ScheduleDpcRequest}}`; `App\Http\Controllers\Cgpsc\{AllotmentController,SchedulingController}`; `dpc_cgpsc` workflow definition extending the existing config-driven `WorkflowEngine`.
6. **Frontend Components:** `resources/views/cgpsc/allotments/{index,history}.blade.php` and `resources/views/cgpsc/schedules/index.blade.php` — allotment screen, Allotment Report, allotment history rendered purely from timeline records, plus the acceptance/scheduling screen and Scheduling Report.
7. **APIs:** 9 routes under `/cgpsc` (role `chairman|member`): allotments index, allot, re-allot, allotment history, schedules index, accept, schedule, re-schedule, scheduling history.
8. **Business Rules Implemented:** Acceptance and scheduling belong to the **allottee alone** — holding the Member role is not the same as being *this* DPC's member. A DPC must be accepted before it can be scheduled. Scheduling is **not** validated against the default DPC date and a past date is permitted, since enforcing either would invent a rule the PPT does not state. Only a proposal approved *and* forwarded by its department may be allotted; the allottee must hold a CGPSC role and be active; a DPC cannot be allotted twice or re-allotted to the same member; re-allotment requires a reason. Members are resolved **dynamically from the role** — the PPT's M1–M5 columns are a layout, not a limit. The **default DPC date is manual Chairman input, never derived**, and is optional because requiring it would itself be an invented rule. **There is no member-decline transition** — re-allotment is the Chairman's remedy.
9. **Remaining Work:** None — M6 is complete. Notification coverage is verified at the listener across all five transitions, including the negative cases (unrelated department, deactivated user, former allottee).
10. **Dependencies:** M4 (proposal must exist and be forwarded), M5 (not required to allot, but scheduling precedes the M7 calculation). Both are complete and frozen, so M6 builds on a stable base.
11. **Next Milestone:** the **UAT Readiness Review** checkpoint, then M7 (DPC Proceeding & Results), which consumes a scheduled DPC. **S34's MAIN LIST is a mandatory golden test at M7** per the worked-example directive. The event contract M7 will publish against is documented in `docs/EVENT_CATALOG.md`.

---

## Module 11 — DPC Proceeding & Results (Promotion Orders)

**PPT Reference:** S31–S34 (Fresh DPC proceeding, calculate, approve) · **Milestone:** M7 · **Status:** ✅ Completed (closed 2026-08-14, tag `v0.7.0-m7-complete`)

1. **Purpose:** The actual online DPC proceeding on the scheduled date — confirm the evaluation, produce Main + Waiting lists, and get CGPSC approval, issuing the proceedings.
2. **Business Workflow (S31–S34):** On the scheduled date and time the allotted member conducts the proceeding, may correct the chart (`EDIT`, `TRIBAL`), runs `CALCULATE`, reviews Main List and Waiting/Supplementary List by category, and either **APPROVE BY CGPSC** or **CANCEL DPC**. Department entries made after the detailed proposal was sent are not valid unless made between submission and the DPC date.
3. **Current Implementation Status:** **All four phases complete (2026-08-14).** The proceeding lifecycle; the **S33 editable workspace** with `EDIT` / `TRIBAL` / `SAVE`; **`CALCULATE`** against the frozen M5 engine on the corrected chart, with stale-calculation protection by correction sequence; the **S34** Main and Waiting lists; **`APPROVE BY CGPSC`** and **`CANCEL DPC`**; held promotions (**`CIR`**); and the **proceedings document** and **promotion list** rendered, stored, signed and published. The PPT's worked example reproduces exactly for US1, US3 and US8; two rows diverge for US4 (BD-9, BD-10).
4. **Database Tables:** `dpc_proceedings` (closure identity, calculation tracking, engine version), `dpc_proceeding_overrides` (sequence, submitted value), `dpc_proceeding_holds`, `dpc_proceeding_documents`, plus `dpc_proposals.uuid` (added and backfilled) — all additive, none touching M4/M5/M6.
5. **Backend Components:** `App\Domain\Proceeding\{Enums\{DpcProceedingStatus, CorrectableField, HoldReason, DocumentType, DocumentStatus}, Models\{DpcProceeding, DpcProceedingOverride, DpcProceedingHold, DpcProceedingDocument}, Documents\{DocumentRenderer, HtmlDocumentRenderer}, Events\ProceedingDocumentsPublished, Listeners\SendDocumentPublicationNotification, Policies\DpcProceedingPolicy, Requests\*, Services\{ProceedingService, ProceedingCorrectionService, ProceedingCalculationService, ResultApprovalService, HoldService, ProceedingDocumentService}}`; `App\Http\Controllers\Cgpsc\{ProceedingController, ProceedingCorrectionController, ProceedingResultController, ProceedingHoldController, ProceedingDocumentController}`.
6. **Frontend Components:** `resources/views/cgpsc/proceedings/{index,show,history,corrections,result,documents,_cell}.blade.php`; `resources/views/documents/{proceedings,promotion-list,_header,_footer,_styles}.blade.php`.
7. **APIs:** `GET /cgpsc/proceedings`, `POST /cgpsc/proceedings/{dpcProposal}/open`, `GET /cgpsc/proceedings/{proceeding}`, `GET /cgpsc/proceedings/{proceeding}/history`, `GET|POST /cgpsc/proceedings/{proceeding}/corrections`, `DELETE /cgpsc/proceedings/{proceeding}/corrections/{correction}` (withdraws; never deletes), `POST /cgpsc/proceedings/{proceeding}/calculate`, `GET /cgpsc/proceedings/{proceeding}/result`, `POST /cgpsc/proceedings/{proceeding}/approve`, `POST /cgpsc/proceedings/{proceeding}/cancel`. All bind by **UUID**.
8. **Business Rules Implemented:** The allottee alone conducts the sitting, and the Chairman only after re-allotting to himself (AQ-6). A DPC is conducted once — a concluded proceeding is never re-opened; correction is a Review DPC. Early opening is configurable, permissive by default, and the actual opening time is always recorded (AQ-5). The department's submission is fingerprinted at opening (S31 evidence). Corrections are append-only, require a stated reason, supersede rather than overwrite, carry a per-proceeding sequence, and **never modify M4 data** (AQ-1); withdrawal is itself a correction; identity fields are not correctable. Corrected values are validated by M4's own `DpcRowRules`. **A stale calculation can never be approved** (AQ-2), decided by correction sequence rather than by timestamp. Recalculating creates a new run and never overwrites the previous one; the approved run is pinned and cannot be displaced. `promotion_as_on_year` is captured at approval, never derived (AQ-7). Approval and cancellation are terminal and record who closed the sitting and when.
9. **Remaining Work:** **Nothing in the approved scope.** Closure is held pending **BD-9** (waiting-list placement when a category has no vacancy) and **BD-10** (whether a court case annotates the remark) — both found by the Phase 4 full PPT comparison against S34's stated result, and both business rulings rather than engineering work. PDF rendering and the real CSC DigiSign provider remain deferred by ruling (AQ-3, BD-4).
10. **Dependencies:** M5 (calculation engine), M6 (scheduled DPC). Both complete and frozen; the freeze is verified by diff at every phase boundary.
11. **Next Milestone:** M8 (Review DPC), which reuses the override table, the correction pipeline and the document renderer built here. **Three business rulings gate publishable results:** BD-2/BD-3 (reserved relaxation — a provisional result is now blocked from publication rather than merely flagged), BD-9 and BD-10.

---

## Module 12 — Review DPC

**PPT Reference:** S35–S44 · **Milestone:** M8 · **Status:** ✅ Completed (all 3 phases) — **FROZEN 2026-08-14 (AD-21)**; BD-11 deferred, not blocking

1. **Purpose:** Correct an error discovered in a completed DPC — a name omitted from the seniority list, a wrong seniority order, a wrong roster point, a mis-entered CR, tribal-area service not counted, or a reservation rule not followed — by running a new DPC that references the original and states why.
2. **Business Workflow (S35–S44):** The AO raises a Review against a concluded DPC, selecting it by DPC ID and ticking the grounds (S37); the chart arrives pre-filled and is corrected (S38); the AH approves (S39–S40); the Commission conducts a sitting and approves the result (S41–S44). **S35 states the process is identical to a Fresh DPC**, and it is — M8 adds no new workflow, engine, renderer or proceeding.
3. **Current Implementation Status:** **All three phases complete (2026-08-14). BD-11 deferred by architect ruling; M8 is complete.** Review proposal creation with origin and multiple coded reasons; copy-on-create; the correction chain (`chain_uuid`, `review_number`, `chain_depth`, `original_proposal_version`) with append-only bidirectional links and three chain roles; the "THIS IS A REVIEW DPC" banner; calculation under the **original's configuration snapshot** with per-calculation provenance; full M6/M7 pipeline reuse; **supersession taking legal effect at approval**; Review documents stating what they correct; the **`SUPERSEDED` watermark applied on delivery**; publication; and 83 feature tests.
4. **Database Tables:** `dpc_review_links` (chain UUID, review number, chain depth, original proposal version, effect, `superseded_at`), `dpc_proposal_review_reasons`, `dpc_review_calculations` (run UUID, configuration snapshot UUID, original calculation run UUID) — all additive.
5. **Backend Components:** `App\Domain\Review\{Enums\ChainRole, Models\{DpcReviewLink, DpcProposalReviewReason, DpcReviewCalculation}, Documents\ReviewAwareDocumentRenderer, Events\DpcSupersededByReview, Listeners\SendSupersessionNotification, Services\{ReviewDpcService, ReviewAwareCalculationService, ReviewAwareApprovalService, ReviewAwareDocumentService}, Requests\CreateReviewProposalRequest}`; `App\Http\Controllers\Dpc\ReviewProposalController`; `App\Http\View\Composers\ReviewDpcComposer`. **Four decorators sit over M7 in the container** — see `docs/ARCHITECTURE_BASELINE_v0.7.md`.
6. **Frontend Components:** `resources/views/dpc/reviews/{create,chain}.blade.php`, `resources/views/partials/review-banner.blade.php`.
7. **APIs:** `GET|POST /dpc-proposals/reviews`, `GET /dpc-proposals/{dpcProposal}/review-chain`. The original is selected by **UUID**.
8. **Business Rules Implemented:** A Review may be raised only against a **concluded** DPC, only by the owning department's Authorized Officer (AQ-10), and only against the **latest** DPC in a chain (AQ-14). At least one ground is required. The Review **owns its own rows**; the original is never modified. A Review calculates under the **original's configuration snapshot**, never today's rules (AQ-11). It runs the **full M6 allotment cycle** (AQ-12). On approval it **supersedes** the DPC it corrects (AQ-9) — never before, so a Review that is cancelled or never approved leaves the original standing. Superseded documents remain downloadable and are watermarked **on delivery**, so the stored artefact and its hash stay exactly as issued. A provisional result cannot be published.
9. **Remaining Work:** **Nothing.** **BD-11** — what supersession means for an officer already promoted under the reviewed DPC — is a **Deferred Business Decision — Required before production activation of the Review supersession policy** (architect ruling 2026-08-14) and does not block completion. The system records the supersession and notifies the department; it asserts nothing about the promotion itself, and must not until CGPSC rules. **That ruling is required before the Review supersession policy is activated in production.**
10. **Dependencies:** M4 (proposal sections), M5 (engine), M6 (allotment), M7 (proceeding and documents) — all complete, and none modified.
11. **Next Milestone:** M9 (Circulation DPC), which resolves a held promotion (`CIR`) from an earlier DPC and faces the same supersession question M8 raised. **BD-11 gates neither M8's completion nor M9's start** — it gates production activation of the supersession policy.

---

## Module 13 — Circulation DPC

**PPT Reference:** C3, C7, S45–S48 · **Milestone:** M9 · **Status:** ⚪ Not Started

1. **Purpose:** Handle officers whose promotion must be held during a DPC because their CR or immovable-property records are incomplete, until the gap is resolved.
2. **Business Workflow (S45–S48, C7):** During a DPC, an officer missing 2+ years of CR or 1+ years of immovable-property declaration is still included in the proceeding but their promotion decision is held (`CIR`); once the missing record is resolved, CGPSC signs a circulation note to finalize that officer's promotion.
3. **Current Implementation Status:** Not started.
4. **Database Tables:** None yet. Planned: `circulation_holds` (`03-database-design.md` §E).
5. **Backend Components:** None yet. Planned: `CirculationDpcService`.
6. **Frontend Components:** None yet.
7. **APIs:** None yet.
8. **Business Rules Implemented:** None yet.
9. **Remaining Work:** Entire milestone.
10. **Dependencies:** M7 (proceeding that identifies held officers), M5 (the `CIR` remark is produced by the eligibility engine).
11. **Next Milestone:** **Blocked by Q12** — same circulation-trigger wording ambiguity as Module 9 ("2+ years CR missing" vs. "CR ≤ 4").

---

## Module 14 — Self-Inspection & Objection

**PPT Reference:** S49–S51 · **Milestone:** M10 · **Status:** ⚪ Not Started

1. **Purpose:** Let the individual officer under consideration see the data being used to evaluate them and raise a time-boxed objection before the DPC date.
2. **Business Workflow (S49–S51):** Individual logs in, views their own DOB/category/CR/loyalty/property data as entered by the department, can PRINT it, and can RAISE OBJECTION before the stated deadline (objections after the DPC date are not considered).
3. **Current Implementation Status:** Not started.
4. **Database Tables:** None yet. Planned: `objections` (`03-database-design.md` §E).
5. **Backend Components:** None yet. Planned: `SelfInspectionService`.
6. **Frontend Components:** None yet.
7. **APIs:** None yet.
8. **Business Rules Implemented:** None yet.
9. **Remaining Work:** Entire milestone.
10. **Dependencies:** M4 (evaluation data to display).
11. **Next Milestone:** **Blocked by Q11** — is the objection deadline configurable, and does raising one notify the department / block the DPC / only log?

---

## Module 15 — Notifications

**PPT Reference:** S5 (registration credentials), S26 (scheduling) — implied at every workflow transition · **Milestone:** cross-cutting (introduced M1, full matrix is M11) · **Status:** 🟡 Partially Completed

1. **Purpose:** Deliver credentials, approvals, and scheduling information to the right actor by mobile + email at every workflow step.
2. **Business Workflow:** Registration issues Level-1/Level-2 credentials via mobile+email (S5); DPC scheduling notifies AH/AO via registered mobile+email (S26); by implication, every workflow transition should notify the relevant actor.
3. **Current Implementation Status:** A generic notification mechanism exists and fires on every workflow transition (`WorkflowTransitioned` event → queued `SendWorkflowNotification` listener, built in M3), and OTP delivery (SMS + email) exists from M1. What's **not** built: a live SMS gateway (currently a log-driver `SmsService`, gateway-pluggable but not connected to a real provider), and the full notification matrix across all 11 milestones' events.
4. **Database Tables:** Laravel's own `notifications`/queue tables exist by framework default; a dedicated `notification_logs` delivery-audit table (`03-database-design.md` §F) is planned but not yet built.
5. **Backend Components:** `App\Domain\Authentication\Services\OtpService` (+ `SmsService`, log driver); `App\Domain\Shared\Events\WorkflowTransitioned` + listener.
6. **Frontend Components:** None dedicated (delivered out-of-band via SMS/email).
7. **APIs:** None (internal event-driven).
8. **Business Rules Implemented:** OTP delivery on registration and verification; workflow-transition notification on every Pre-DPC state change.
9. **Remaining Work:** Real SMS gateway integration (external dependency — needs CGPSC/vendor credentials); `notification_logs` delivery-audit table; notification coverage for M4–M10 events as they're built.
10. **Dependencies:** M1 (OTP), M3 (workflow-event pattern to extend).
11. **Next Milestone:** Full matrix is explicitly scoped to **M11**.

---

## Module 16 — Reports & Dashboards

**PPT Reference:** Not in either PPT — appears only in secondary docs (`technical-proposal.md`, pre-reset) · **Milestone:** M11 · **Status:** ⚪ Not Started

1. **Purpose:** Give departments and CGPSC visibility into DPC status, volumes, and trends.
2. **Business Workflow:** Not specified by the PPTs (out-of-scope-by-default unless approved — see decision Q16, still open).
3. **Current Implementation Status:** Not started. A minimal role-aware landing `dashboard.blade.php` exists (from M1, extended in M3 to link to Pre-DPC), but it is a navigation landing page, not a reporting/analytics dashboard.
4. **Database Tables:** None reporting-specific yet.
5. **Backend Components:** `App\Http\Controllers\{DashboardController,Admin\AdminDashboardController}` exist but only render navigation, not metrics.
6. **Frontend Components:** `resources/views/dashboard.blade.php`, `resources/views/admin/dashboard.blade.php`.
7. **APIs:** `GET /dashboard`, `GET /admin` (both navigation-only today).
8. **Business Rules Implemented:** None (no reporting logic).
9. **Remaining Work:** Entire reporting/Chart.js scope.
10. **Dependencies:** Most other milestones (reports are cross-cutting over their data).
11. **Next Milestone:** Scoped to **M11**, gated on decision **Q16** confirming this is in scope at all (it's absent from both PPTs).

---

## Module 17 — Audit Trail & Security Hardening

**PPT Reference:** Implied by the e-signature statement on every screen and standard government-system norms, not an explicit screen · **Milestone:** cross-cutting (foundation in M1, final pass is M11) · **Status:** 🟡 Partially Completed

1. **Purpose:** A tamper-evident record of who did what, when, on every legally significant action — required for a system whose outputs can be contested in CAT/High Court.
2. **Business Workflow:** Every create/update/delete on business models, and every workflow transition, is recorded.
3. **Current Implementation Status:** Foundation is in place and used consistently since M1: `owen-it/laravel-auditing` audits models directly (`User`, `Department`, and all 15 masters via the `IsMaster` trait); a separate, purpose-built `ActivityTimeline` records human-readable workflow history (used on the Pre-DPC show screen); the `workflow_transitions` ledger (M3) is an append-only record of every state change. What's not done: a dedicated audit-trail **UI** for browsing/searching the log, and the M11 security/performance hardening pass (rate limiting review, header hardening, Redis cache tuning, etc.).
4. **Database Tables:** `audits` (owen-it), `activity_timelines`, `workflow_transitions`.
5. **Backend Components:** `App\Domain\Shared\Services\ActivityTimelineService`; `App\Domain\Shared\Models\ActivityTimeline`; owen-it `Auditable` trait on audited models.
6. **Frontend Components:** Workflow-history panel embedded in `resources/views/pre-dpc/show.blade.php`; no standalone audit-browser screen yet.
7. **APIs:** None dedicated (data is queried internally for the timeline panel).
8. **Business Rules Implemented:** Every Pre-DPC workflow transition is logged to both the audit trail and the activity timeline; CSRF protection; no stack traces in production (Laravel default + verified).
9. **Remaining Work:** Standalone audit-trail UI; formal security review pass; performance/cache tuning pass — both explicitly scoped to M11.
10. **Dependencies:** M1 (packages), M3 (pattern established).
11. **Next Milestone:** Full pass scoped to **M11**, gated on decision **Q16** (confirming final scope before hardening it).

---

## Implementation Summary

**This is the project's single progress-and-suggestions document.** It is replaced in full at each phase boundary rather than appended to, and no additional progress markdown file is created. The module-by-module report above is the detailed record; this section is the current state, what remains, what is blocked, and what I recommend next.

### 1. Current project completion status

**M8 (Review DPC) is COMPLETE — all three phases, committed and tagged `v0.8.0-m8-phase3`. BD-11 is deferred by architect ruling and does not block completion. M9 has NOT started.**

```
Foundation                          ✅ 100%
Authentication & RBAC               ✅ 100%
Department Registration             ✅ 100%
Master Data & Rule Configuration    ✅ 100%
User Management (provisioning)      ✅ 100%
Pre-DPC Proposal                    ✅ 100%
External Integration Architecture   ✅ 100% (feature flags + Provider Pattern)
DPC Proposal (detailed)             ✅ 100% (7 phases — ARCHITECTURE FROZEN)
Scoring & Eligibility Engine        ✅ 100% (4 phases — ENGINE FROZEN)
CGPSC Workflow (Allotment/Sched.)   ✅ 100% (4 phases — FROZEN)
DPC Proceeding & Results            ✅ 100% (4 phases — FROZEN, closed v0.7.0-m7-complete)
Review DPC                          ✅ 100% (3 phases) — COMPLETE; BD-11 deferred, not blocking
Circulation DPC                     ⚪ 0%  — M9, NOT STARTED
Self-Inspection & Objection         ⚪ 0%  — M10
Reports & Dashboards                ⚪ 0%  — M11 (gated on Q16: absent from both PPTs)
CSC DigiSign / E-Signature          🟡 Partial (abstraction + Mock done; real CSC pending — BD-4)
Notifications                       🟡 Partial (mechanism + OTP done; live SMS gateway pending — TD-6)
Audit Trail & Security Hardening    🟡 Partial (foundation done; UI + hardening pass = M11)
```

**Milestone completion: 9 of 11 complete (M0–M8) — ~82% of the planned milestone roadmap by count.** M8 is complete, tagged `v0.8.0-m8-phase3`, and **frozen as of 2026-08-14 (AD-21)**. Not effort-weighted: M4 and M5 were the two largest and are both frozen.

**Five freezes are in force** — M4 architecture-frozen, M5 engine-frozen, M6 frozen, M7 frozen from M8 onward, and **M8 frozen from 2026-08-14 (AD-21)**, the strictest of the five. M8 was built entirely on that stable core **without modifying a single file in any of them**, verified by full-repository diff at every phase boundary. That is the freeze doing its job, and it is the strongest evidence the boundaries are in the right places.

### 2. Remaining milestones

| # | Milestone | Scope | Status | Gated on |
|---|---|---|---|---|
| **M9** | Circulation DPC | S45–S48, C3, C7 — resolve a held promotion (`CIR`) from an earlier DPC; CGPSC signs a circulation note once the missing CR or immovable-property record is supplied | ⚪ **NOT STARTED** | Architect approval to begin. **Q12** (circulation-trigger wording: "2+ years CR missing" vs. "CR ≤ 4") should be settled first; **BD-11** informs the design |
| **M10** | Self-Inspection & Objection | S49–S52 — an officer inspects their own record and raises an objection | ⚪ Not started | M9; the objection outcome may require a Review DPC, which M8 now provides |
| **M11** | Reports, Audit-trail UI, Security & Performance Hardening | Reporting/dashboards, a standalone audit browser, rate-limiting review, header hardening, Redis cache tuning | ⚪ Not started | **Q16** — reporting is absent from both PPTs and is out-of-scope-by-default until confirmed |
| — | **UAT & Security Hardening sprint** | Full business UAT, VAPT, capacity planning | Not started | M11; and the government SMS gateway (TD-6) before any officer can complete verification |

**M9 has not started.** No M9 code, migration, route, view or test exists in this working tree.

### 3. Pending business decisions

Register: `docs/PENDING_BUSINESS_DECISIONS.md`. Put to business users in plain language in `docs/UAT_BUSINESS_VALIDATION.md`. **None of these is an engineering task, and none is resolved unilaterally.**

| # | Question | Severity | Blocks | Behaviour while open |
|---|---|---|---|---|
| **BD-11** | What happens to an officer already **promoted** under a DPC that is later reviewed and superseded? | **P1** | **Nothing in the build.** **Deferred Business Decision — Required before production activation of the Review supersession policy** (architect ruling 2026-08-14) | The system marks the DPC superseded on both timelines, notifies the department with both codes, watermarks the superseded documents on delivery, and **asserts nothing about the promotion itself** — no withdrawal, no reverted rank, no arrears |
| **BD-2 / BD-3** | Reserved relaxation — automatic or committee discretion, and does relaxation to 10 apply beyond Class-I→I? | **P1** | Publishable results | Result marked **provisional** and **blocked from publication**. Fires on Reviews too — US3's case is exactly this |
| **BD-9** | Which waiting list does an officer join when their own category has no vacancy? | **P1** | Publishable results | **The one to chase.** It raises **no flag**, so it can produce a confidently wrong *published* list rather than a blocked one. **M5 is not modified for it** (ruling) — raised to CGPSC |
| **BD-10** | Does a pending court case annotate the remark? | P1 | Publishable results | Result marked provisional; publication blocked |
| **BD-1** | Does `PUNISHMENT` disqualify an officer? | P2 | Nothing | Ruled a business rule, not an engineering one. Not implemented; current behaviour pinned by test |
| **BD-4** | CSC DigiSign — the real provider and API contract | P2 | Production signing | Mock provider behind `FEATURE_*`, default off. **Never mock-sign in production** |

**Why BD-11 in particular cannot be guessed:** the three plausible answers — the promotion stands and only the paper is corrected; it is withdrawn from the date of the Review; it is withdrawn retrospectively with recovery of pay — differ in what they do to a serving officer's substantive rank and pay. Choosing one silently would be the software deciding a service-law question.

### 4. Technical debt

Register: `docs/TECHNICAL_DEBT.md` — permanent, not reset at a phase boundary. The four items below are the ones M8 touched or deliberately left alone; all four are blocked by a **freeze**, not by effort.

| # | Item | Status | Recommendation |
|---|---|---|---|
| **TD-19** | `DpcProposal` has no `HasUuids`; new proposals get their UUID by direct update, because adding the trait would modify frozen M4 | Open — **now two callers** (`ProceedingService::open()`, `ReviewDpcService::create()`) | One line when the M4 freeze is next opened. Cheapest of the four, most callers |
| **TD-20** | The department-scoped notification recipient query is duplicated across listeners | Open — **now three copies**; Phase 3's `SendSupersessionNotification` is the third | Extract `NotificationRecipientResolver` when the M6 freeze is next opened. Two copies of "who should be told" is how one quietly stops matching the other; three is worse |
| **TD-21** | Calculation Engine Version is not on `dpc_calculation_runs` — it is on the timeline, the proceeding and every document, but not the run row | Open, unchanged. M8 records its own provenance in `dpc_review_calculations` rather than writing to M5's table | Add the column when the M5 freeze is next opened |
| **TD-22** | The rule-reuse config override is process-global (AD-19) | **Open by directive.** Restored in a `finally`; PHP-FPM serves one request per process, so two calculations cannot interleave today | **Do not solve it yet.** Real only if calculation is queued or run in a concurrent worker. Solving a concurrency residual before concurrent workers exist would be designing against a hypothesis |

**Also open, and due before production rather than before UAT:** **TD-6** (government SMS gateway — without it OTP delivery fails, so no officer can complete verification), **TD-1** (evidence-table permission grants), **TD-8** (capacity plan for permanently retained import files and signed documents).

### 5. Architect suggestions

1. **Batch TD-19, TD-20 and TD-21 into one authorised freeze opening at M8's closure boundary.** Each is small and well understood; each is waiting for its own reason to arrive. Opening the freeze once, with the gate and the freeze-verification diff run around it, is cheaper and safer than three separate openings — and TD-20 is now at three copies, which is where duplicated logic starts to drift.
2. **Stop at four decorators.** `docs/ARCHITECTURE_BASELINE_v0.7.md` is now the document to read before extending a frozen or completed milestone. It records what the pattern costs — indirection; the class that runs is not the class the caller names — and that a **fifth** decorator is the signal to ask whether the M7/M8 boundary is in the right place, rather than adding it and deciding later.
3. **Chase BD-9 ahead of the others.** BD-2, BD-3 and BD-10 fail loudly — they block publication — and BD-11 is now explicitly deferred with its constraint named. BD-9 fails **silently** — it produces a plausible published promotion list that may name the wrong officer on the wrong waiting list. Of the open business questions it is the only one that can do real harm without anyone noticing.
4. **Settle Q12 before M9 opens.** The circulation trigger is the same wording ambiguity that already affects the eligibility engine. Designing M9 around an unresolved trigger would mean building a workflow whose entry condition is a guess.
5. **Rename the branch, or decide not to.** `feature/m0-setup` is eight milestones stale and describes none of what it contains. It rewrites the remote ref, so it needs a decision rather than a silent fix — but leaving it is a real handover hazard for anyone new to the repository.
6. **Keep the Demo Readiness Rule ahead of the gate.** Six consecutive green gates once missed three P1 defects that an afternoon of demoing caught. The gate is necessary, not sufficient (AD-17), and every milestone since has been checked against the PPT slide by slide as well as gated.

### 6. Recommended next steps

**In order. Nothing here starts M9.**

1. **Put BD-11 to CGPSC** with the three candidate answers and their consequences, via `docs/UAT_BUSINESS_VALIDATION.md`. It no longer blocks M8, but it **is required before the Review supersession policy is activated in production**, so it needs an answer on a procurement-style lead time rather than an engineering one.
2. **Obtain architect approval of M8 Phase 3** against the checklist in `docs/ARCHITECT_REVIEW.md` §10.
3. ~~**Declare the M8 freeze**~~ — **done 2026-08-14 (AD-21), ACTIVE.** Next: **define the Freeze Exception mechanism** that rule 5 names. Nothing in the project says who grants one, what record it leaves, or whether it reopens the freeze for one change or for a window — better settled now than under the pressure of the first request.
4. **At that boundary, open the freeze once** for TD-19 + TD-20 + TD-21 (suggestion 1), with the gate and freeze verification run around it.
5. **Chase BD-9, BD-2, BD-3 and BD-10** in parallel with the above — they gate *publication* of any result, Fresh or Review, and therefore gate the end of UAT rather than the start of M9.
6. **Settle Q12**, then design M9 (Circulation DPC) and obtain approval of the design **before** any M9 code is written — the established pattern for every milestone since M4.
7. **Before production, not before UAT:** connect the government SMS gateway (TD-6), grant the evidence-table permissions (TD-1), and produce the retention/capacity plan (TD-8).

### 7. Implementation notes from this session

Notes worth carrying forward that are not obvious from the diff:

- **The M5 seam M8 needed already existed, built by M5 itself.** `DpcCalculationService` takes no rule set and calls `RuleSet::fromConfig()` internally — but `fromConfig()` and `fromSnapshot()` share one `build()`, so a stored `configuration_snapshot` has exactly the shape of `config('dpc.scoring')`. Under AD-3 business rules *are* configuration, so running under the original's rules is running under the original's configuration. **No second scoring path exists**, and that is the point: a parallel "historic rules" implementation would be a second engine to keep correct.
- **Configuration is restored in a `finally`, deliberately.** It is process-global, and a failed calculation leaving a 2017 rule set in place would silently corrupt the *next* one. This is the whole of TD-22, and it is why TD-22 is documented rather than solved.
- **`engine_version` is preserved across the rule override.** The rules are the original's; the engine doing the work is this build. Replacing `dpc.scoring` wholesale would have blanked it, and every Review document would then have claimed an unknown engine.
- **The watermark is a delivery concern, not a storage one.** `contents()` is what a reader gets; `storedContents()` and `verify()` are what was issued. Three tests pin the split, including that **a superseded document still verifies** — its SHA-256 is what proves a department's copy is the copy the Commission issued, and rewriting the bytes would invalidate every copy already in circulation. It is also the honest model: the document *was* issued as it stands and was *later* superseded.
- **The configuration snapshot UUID is a UUIDv5 over the canonical snapshot, keys sorted.** A random UUID would have satisfied the directive and recorded nothing useful. Derived, *"were these two results decided under the same rules?"* becomes an equality check. Tested both ways: reordered keys give the same UUID, a changed rule version gives a different one.
- **`chain_depth` and `review_number` are not the same number**, and storing one under two names would have been the easy mistake. Depth counts DPCs in the chain including the original (first Review = depth 2); the number counts corrections (first Review = number 1). Stored rather than derived, because walking a chain to measure it is exactly the query that slows as the thing it measures grows.
- **Three chain roles, not two.** A superseded **Review** and the **Original** are both "not the operative DPC" but are not the same thing, and the role is derived from **position**, not from `superseded_at` — keeping the structural view separate from supersession's legal effect.
- **Supersession is recorded on both timelines** because a correction chain has to be readable from either end. Someone opening the original needs to know it no longer governs.
- **The provisional-result block fires on Reviews in practice**, not just in theory — US3's case is exactly BD-2/BD-3. The companion test shows the other side: a Review that corrects the very CR it was raised for produces a clean result and publishes.
- **Four decorators, each with a test asserting a Fresh DPC is unaffected.** A decorator that changes behaviour for anything that is not a Review is a defect, and it would be invisible without those tests.

### Codebase Inventory (live count, 2026-08-14)

| Metric | Count |
|---|---|
| Database tables (`migrate:fresh --seed`) | 62 |
| Migrations | 43 files |
| Models | 48 |
| Controllers | 36 |
| Domain Services | 31 |
| Policies | 3 |
| Form Requests | 39 |
| Seeders | 10 |
| Blade views | 84 |
| Routes | 176 |
| Test files | 51 |
| Composer packages added beyond Laravel default | `spatie/laravel-permission`, `owen-it/laravel-auditing`, `predis/predis`, `larastan/larastan` (dev) |

### Quality Gate (run 2026-08-14, this session)

| Check | Result |
|---|---|
| PHPUnit (`php -d memory_limit=2G vendor/bin/phpunit`) | **620 passed / 1902 assertions, 0 failing** |
| Laravel Pint (`--test`) | **Passed** — PSR-12 + strict types, no violations |
| PHPStan / Larastan (level 6) | **0 errors** |
| `composer audit` | **No advisories** |
| `php artisan migrate:fresh --seed` | **OK** — 43 migrations, **62 tables**, 10 seeders |
| Freeze verification (M4/M5/M6/M7 vs. `v0.7.0-m7-complete`) | **Empty** |

All four gate steps are mandatory per phase (`composer gate`). Golden cases run against the *shipped* configuration, so a rule change that would alter a promotion outcome fails the build; all 13 configurable business rules have at least one golden test. **The gate is necessary, not sufficient (AD-17)** — every phase is also PPT-verified and demoed.
