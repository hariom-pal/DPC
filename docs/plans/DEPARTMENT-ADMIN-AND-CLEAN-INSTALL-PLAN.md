# Plan: Department Admin, Registration Redesign and Clean Installation

**Status: PLAN ONLY.** Nothing in this document has been implemented. No migration has been run, no data deleted, and nothing committed or pushed.

**Prepared:** 2026-10-26.
**Baseline:** UAT commit `eed36c0`.
**Inspected read-only:** the dev database `dpc_dev`.

---

## 0. Current state

| Item | State |
|---|---|
| Branch / HEAD | `main` / `eed36c0` (= UAT) |
| Uncommitted work (preserved, disposition pending: decision D8) | Aadhaar/seat registration; UIDAI integration (`app/Integrations/{Aadhaar,Uidai}`); docs (`docs/DEPARTMENT-REGISTRATION.md`, `pre-dpc-guide/`, `new-dpc-guide/`, `department-onboarding/`, `uat-first-dpc-guide/`); this plan |
| Dev DB `dpc_dev` | 91 tables, 46 non-empty. Holds the seeded School Education exercise **plus records entered by hand during browser testing**: department **HE**, users `aohighereducation@gov.in` and `ahhighereducation@gov.in`, a Pre-DPC, a DPC Proposal, a second vacancy record, 24 audit rows, 24 timeline rows. **Not classified as disposable without your confirmation.** |
| Dev backups | `storage/app/backups/` holds three SQL dumps from 2026-10-05 |
| Dev files | `storage/app/private`: 10 files (department-post order, vacancy-master orders, one Pre-DPC signature) |
| Shared Redis | `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`. This machine also runs other Laravel apps. **Never `FLUSHALL`.** |

---

## 1. Seeders

| Seeder | In `DatabaseSeeder` | Creates | Class | Plan |
|---|---|---|---|---|
| `RolesAndPermissionsSeeder` | yes | roles (`findOrCreate`) | system | keep; add `department_admin` |
| `DistrictSeeder` | yes | districts | master | keep |
| `DesignationSeeder` | yes | designations | master | keep |
| `PayScaleSeeder` | yes | pay scales L10–L14 | master | keep |
| `LookupMasterSeeder` | yes | reservation categories, review reasons, vacancy types, DPC types | master | keep |
| `QualificationSeeder` | yes | qualifications | master | keep |
| `DepartmentMasterSeeder` | yes | department `SE` (Unregistered) | master | keep |
| `DepartmentPostSeeder` | yes | SE posts BEO (II) and Additional Director (I), with their GO | master | keep |
| `ReservationRosterSeeder` | yes | rosters and 200 points | master | keep |
| `CommitteeMasterSeeder` | yes | committee rules (4–7, coverage) | master | keep |
| `AdminUserSeeder` | yes | **admin user with `DPC_ADMIN_PASSWORD`, default `ChangeMe@12345`** | bootstrap | **replace** with the secure bootstrap (§5) |
| `CgpscChairmanSeeder` | yes | **chairman user with `DPC_CHAIRMAN_PASSWORD`, default `ChangeMe@12345`**, plus the Chairman master record | master + account | **split**: keep the master record, stop creating an account (D13) |
| `ReadyDpcSeeder` | **yes** (skipped in production) | AO/AH accounts (through registration), an **approved Pre-DPC, a Recruitment Rule, a finalized vacancy record, a Promotion CR Rule** | **demo / transactional** | **remove from `DatabaseSeeder`** (D12 covers its promotion rule) |
| `DemoSeeder` + `Demo/*` | no (`php artisan demo:seed`) | demo accounts with a shared password, full demo pipeline | demo | not in a fresh install. Keep the files for demo machines, or delete them (D14). It already refuses production. |
| `Support/PptPromotionRules` | test helper | — | — | no change |

## 2. Migrations

All 107 migrations are **genuine schema history** and stay unchanged. Some also write data:

| Migration | Data it writes on a **fresh** database | Class |
|---|---|---|
| `2026_08_24_360004_create_service_classes_table` | Class I, II, III | master |
| `2026_08_27_410004_create_record_reasons_and_evaluation_gaps` | 7 record reasons | master |
| `2026_10_17_480001_make_committee_configuration_the_dpc_committee_rules` | adjusts committee rules | master |
| `2026_10_17_500001_make_ph_the_only_reservation_in_promotion` | PH category and the 3 % policy | master |
| `2026_10_17_520001_seed_coder_instruction_cr_grades` | CR grades 9/7/5/3/1 from 2019 | master |
| `2026_10_21_590001_create_sealed_cover_reasons_and_vigilance_details` | 4 sealed-cover reasons | master |
| `310002`, `360003`, `360006`, `360007`, `390001`, `400005`, `400006`, `460001` | backfills that act only on rows that already exist; **nothing** on a fresh database | — |

## 3. Tables: master vs transactional

The repository already classifies every table in two places:
- `ResetTransactionalDataCommand` (TRANSACTIONAL / PRESERVED).
- `UatCleanupPlan` (CLEAN / PARTIAL / PRESERVE).

A test enforces that every new table is added to both. A dry run on 2026-10-26 found **no unclassified table among the 91**.

**Master / reference / system (27 tables, kept):**
- **Access:** `roles`, `permissions`, `role_has_permissions`.
- **Organisation and posts:** `departments` (rows kept; status reset on a full reset), `department_posts`, `department_post_orders`, `designations`, `districts`, `pay_scales`, `service_classes`.
- **Eligibility masters:** `qualifications`, `cr_grade_values`, `promotion_cr_rules`, `promotion_cr_rule_years`, `record_reasons`, `sealed_cover_reasons`.
- **Reservation:** `reservation_categories`, `reservation_policies`, `reservation_rosters`, `reservation_roster_points`.
- **Committee:** `committee_configurations`, `cgpsc_chairmen`.
- **Other lookups:** `review_reasons`, `vacancy_types`, `dpc_types`, `portal_notices`.
- **Framework:** `migrations`.

**Currently classified as kept, but transactional for a full disposable reset** (your instruction: generated Recruitment Rules go):
- `recruitment_rules`, `recruitment_rule_sources`, `rr_source_qualifications`. These rows are created only by a Pre-DPC approval.
- `users`, `model_has_roles`, `model_has_permissions`, `user_department_assignments`. Every account except the Portal Super Admin goes (D13 decides the Chairman).

**Transactional (57 tables, cleared on a full reset; with the 7 above: 64; 27 + 64 = 91):**
- **Import staging:** `import_rows`, `import_batches`.
- **Evaluation chart:** `dpc_evaluation_cr_grades`, `_loyalty_flags`, `_property_flags`, `_record_gaps`, `_documents`, `dpc_evaluation_rows`.
- **Proposal and vacancy:** `dpc_proposal_verifications`, `dpc_proposal_representations`, `previous_dpc_records`, `vacancy_position_entries`, `_working_strengths`, `_sanctioned_strengths`, `vacancy_positions`, `dpc_seniority_entries`, `dpc_working_officers`, `dpc_vacancies`, `dpc_committee_members`, `dpc_proposal_review_reasons`, `dpc_proposal_revisions`.
- **Calculation:** `dpc_calculation_results`, `dpc_calculation_runs`, `dpc_vacancy_calculations`.
- **Circulation and review:** `dpc_circulation_documents`, `dpc_circulation_items`, `dpc_circulations`, `dpc_document_review_references`, `dpc_review_calculations`, `dpc_review_links`.
- **Sitting and order:** `dpc_candidate_calls`, `dpc_promotion_orders`, `dpc_proceeding_documents`, `dpc_proceeding_holds`, `dpc_proceeding_overrides`, `dpc_proceedings`, `dpc_schedules`, `dpc_allotments`.
- **Pre-DPC and proposals:** `pre_dpc_source_qualifications`, `pre_dpc_promotion_sources`, `dpc_proposals`, `pre_dpc_proposals`.
- **History:** `workflow_transitions`, `activity_timelines`, `signatures`, **`audits`**, `user_notifications`, `self_inspection_accesses`, `dpc_id_sequences`.
- **Transient:** `otp_verifications`, `password_reset_tokens`, `sessions`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`.
- **New in this design:** `department_invitations`.

**Files:**

| Kind | Paths | On a full reset |
|---|---|---|
| Transactional | `private/{imports, employee-documents, record-gaps, proposal-verifications, proposal-representations, previous-dpcs, vacancy-master, proceedings, circulations, promotion-orders}`, `signatures/` | removed |
| Master | `private/department-posts`, `private/reservation-policies` | kept |

**Defect found:** the two existing commands disagree on files. The reset dry run reports 1 file, the UAT-cleanup dry run 6. The reset command's `STORAGE_PATHS` also omits `promotion-orders`, `previous-dpcs` and `proposal-representations`. This must be fixed before either command is trusted.

## 4. Fresh installation: exactly what is created

`php artisan migrate` on an **empty** database, then `php artisan db:seed` with the master-only `DatabaseSeeder`.

**Created:**
- the roles, including `department_admin`;
- districts, designations, pay scales, qualifications, service classes, CR grades, record reasons, sealed-cover reasons, reservation categories, the PH policy and rosters, committee rules, review reasons, vacancy types and DPC types;
- department `SE` and its two posts (D15);
- the CGPSC Chairman master record **without an account** (D13).

**Created by the bootstrap command (§5):**
- the **Portal Super Admin**, and nothing else.

**Not created:**
- any AO, AH, Department Admin, member or candidate account;
- any Pre-DPC, DPC, Recruitment Rule, vacancy record, signature, notification, OTP or audit row (other than the audits of the bootstrap itself);
- any Promotion CR Rule (D12).

## 5. Secure bootstrap of the Portal Super Admin

- **New command:** `php artisan dpc:bootstrap-super-admin --name= --email= --mobile=`. It:
  - refuses if a Super Admin already exists (`--recover` re-issues a password with a reason, audited);
  - validates `.gov.in`;
  - generates `Str::password(16)` and prints it **once** to the operator's terminal;
  - stores only the hash and sets `must_change_password`.
- `AdminUserSeeder` no longer creates an account. It refuses to run, and points to the command.
- `DPC_ADMIN_PASSWORD` and `DPC_CHAIRMAN_PASSWORD` are removed from config, so no shared default password remains anywhere.

## 6. Existing databases: safe transition

**UAT and production: never reset.** Deploying this design is **additive only**:
1. New table `department_invitations`.
2. New nullable columns: `users.mobile_verification_waived_at`, `users.mobile_verification_waived_by`, `users.password_changed_at`.
3. Role `department_admin`, through `RolesAndPermissionsSeeder` (`findOrCreate`).
4. `User::$auditExclude = ['password', 'remember_token']` from deployment onwards.

**Unchanged:**
- existing AO/AH accounts, postings, Pre-DPC, vacancy, DPC, CGPSC and order records;
- existing audit rows. Note: existing `audits` rows contain bcrypt hashes of passwords (2 rows on dev). Redacting them is a separate, approval-only task (D16).

**Departments already registered on UAT** get their Department Admin through a Portal Admin invitation. Their AO/AH stay.

**Dev (`dpc_dev`):**
- Recommended: **do not reset it.** Create a new database `dpc_fresh` → migrate → master seed → bootstrap the Super Admin. Then switch `DB_DATABASE` once you approve.
- `dpc_dev` stays untouched as the rollback.

If you still want `dpc_dev` itself reset:
1. You confirm that its records, **including the HE test department you entered**, are disposable.
2. A fresh `mysqldump` is taken and checked (the command refuses a backup without a users table).
3. A dry run is shown.
4. You approve the exact target.
5. The table plan in §3 is applied, and app-prefixed Redis keys only are cleared.

---

## 7. Registration (Department Admin only, invitation-based)

1. **Invitation.** Portal Super Admin → Departments → **Invite Department Admin**: department and official e-mail. A one-time link (token hashed, 72 hours, revocable, bound to that e-mail) is sent with no password. No invitation is offered while an active invitation or an active Department Admin exists, unless it is a recovery.
2. **Registration page `/register/{token}`.**
   - Read-only from the master: department, name, district, place, PIN.
   - Entered: full name, designation, mobile. The e-mail is fixed from the invitation.
3. **E-mail OTP** (required), then **mobile OTP**. Without SMS, mobile is waived per §9.
4. **Set password.** The officer chooses it (8+ characters, mixed case, number, symbol). No temporary password exists.
5. **Activation.**
   - The account is activated only when every check of the policy has passed.
   - The department becomes Registered and the invitation is consumed.
   - A confirmation e-mail goes to the verified address, without a password. SMS goes once the gateway exists.
6. **Duplicate and unauthorised claims.**
   - A token is required.
   - A used, expired, revoked or other-e-mail token is refused.
   - There is no public department dropdown any more.

## 8. Department Admin and password management

**Role `department_admin`** (departmental; one posting per user).

| Area | Rule |
|---|---|
| Screens | Department Dashboard → User Management: list, create, edit, activate/deactivate, reset password, history |
| Create | name, designation, role **AO or AH only**, e-mail, mobile. The department is fixed to the Department Admin's own. |
| Edit | name, designation, mobile, e-mail. The role cannot be raised. |
| Cannot | manage another department, give any Commission role or `department_admin`, touch a Portal Admin or another Department Admin, or approve a Pre-DPC/DPC |
| Several officers | Many AO and AH per department. Creating one never deactivates or replaces another. |

**Temporary password (create and reset):**
- **Generation and storage:**
  - `Str::password(16)` (CSPRNG); hash only; `must_change_password`; `remember_token` rotated.
  - `AuthenticateSession` in the web group, so every other session ends at its next request.
- **Display:**
  - The POST response renders the password page directly: no redirect, no flash, `Cache-Control: no-store`.
  - **Copy** button (external JavaScript, so it stays CSP-clean).
  - Message: "the previous password no longer works".
- **Reset:** a confirmation dialog first. Each reset issues a new password. The old one is never shown, because it is never stored.
- **Record and limits:**
  - Timeline entry `user.password_reset` with the actor, target and time.
  - Rate limit per actor and per target.
  - CSRF.
  - A policy refuses any cross-department target.
- **Never in transit or at rest:** never e-mailed, sent by SMS, logged, put in a URL, put in browser storage or audited. **This applies to every existing path too:** Portal Admin create and reset, and Promotion Candidate provisioning (D9).

**Portal Super Admin:** the same reset screen for Department Admin accounts, plus invitation resend and revoke.

## 9. OTP

**Fixed OTP:**
- One setting: `DPC_OTP_FIXED_CODE` (e.g. `123456`).
- It is honoured **only** when `app()->environment(['local', 'testing'])` **and** `DPC_OTP_FIXED_ENABLED=true`.
- In any other environment, a configured fixed code is ignored and the health check reports it.
- The old knobs `DPC_OTP_FAKE_CODE` (which works in **any** environment today) and `DEMO_OTP` are folded into it and stop working elsewhere.

**Attempts, expiry and limits:**
- Expiry 10 minutes.
- 5 attempts per code, after which a new code is needed.
- **Resend** endpoint: 60-second cooldown, at most 5 per hour per identifier.
- Rate limits per identifier and per IP.
- Codes are hashed and never logged.

**Status accuracy:** `email_verified_at` and `mobile_verified_at` are set **only** by a verified OTP.

**Mobile without SMS:**
- While `FEATURE_SMS_GATEWAY=false`, the Portal Admin may grant a recorded **waiver** at invitation time: `mobile_verification_waived_at` and `_by`.
- The login gate then accepts e-mail verified plus mobile verified-or-waived.
- The screens show "Mobile not verified (waived)".
- When the gateway is on, waivers stop being granted and a mobile OTP is required.

**E-mail:** must actually work on UAT for invitations and e-mail OTP (D17).

## 10. Future DPC assignment (designed for, not built)

- Department Admin, AO and AH are distinct departmental roles, and users stay department-scoped.
- A later `dpc_assignments` table (`pre_dpc_proposal_id` / `dpc_proposal_id`, `user_id`, role, `assigned_by`, `assigned_at`, `ended_at`) plus policy checks will add assignment. Nothing in this phase blocks it.

## 11. Tests and regression risks

**New tests (§8 of the brief):**
- invitation registration;
- e-mail OTP; mobile verified versus waived (never falsely verified);
- fixed OTP accepted only in local/testing and refused elsewhere;
- resend cooldown; attempts; expiry;
- duplicate and unauthorised registration;
- multiple AO and AH;
- create and reset;
- old password and old session invalidated;
- cross-department 403; privilege escalation refused;
- Super Admin reset of a Department Admin;
- no password in logs, audits, mail or SMS;
- first-login change;
- existing AO/AH login;
- bootstrap command;
- fresh `migrate` + `db:seed` creates **exactly** §4 and no transactional row (a test asserts every §3 transactional table is empty).

**Regression suite:**
- the full suite (memory cap, no config cache);
- the `eed36c0` end-to-end route test adapted to the new registration;
- Pint, PHPStan, `lang:check`.

**Risks:**
- Many tests and fixtures call the current `RegistrationService` and rely on `ReadyDpcSeeder`. Their fixtures must move to factories.
- Removing `DPC_OTP_FAKE_CODE` changes how the whole test suite produces OTPs: `phpunit.xml` moves to `DPC_OTP_FIXED_*`.
- `AuthenticateSession` logs users out after their own password change unless the session hash is refreshed. This must be handled.
- If UAT e-mail is not working, nobody can register (D17).
- Promotion Candidate accounts lose their e-mailed password (D9). Their onboarding needs the same on-screen or reset route.

---

## 12. Decisions needed before implementation

| # | Decision | Recommendation |
|---|---|---|
| D1–D11 | (from the previous plan) invitation only; mobile waiver; e-mail OTP for AO/AH first login; Department Admin sets own password; several Department Admins, created only by the Portal Admin; a Department Admin is not also an AO; transfers by the Portal Admin; D8 below; passwords never sent (D9); Department Admin **may edit AO/AH details** (now required by you); 72 h validity and rate limits | as before; D10 is now "yes, edit" |
| D8 | Uncommitted Aadhaar/seat registration and UIDAI code | Superseded by this design. Keep the files uncommitted until you decide; do not ship them. |
| D12 | Fresh install: seed a Promotion CR Rule (the "seeded starting point" II→I 2026 now in `ReadyDpcSeeder`) or leave it to the Admin? | Leave it to the Admin. The figures were never confirmed. |
| D13 | CGPSC Chairman on a fresh install: master record only, or also an account? | Master record only. The Portal Admin creates and links the account. |
| D14 | Demo seeders (`demo:seed`) | Keep for demo machines only; not called by `db:seed` |
| D15 | Keep `SE` and its 2 posts as seeded master data, or start with no department? | Your call: they are master data, but they came from the demo exercise |
| D16 | Redact password hashes already in `audits` on UAT/dev? | Leave history untouched (append-only), or redact once with an audited command; approval needed either way |
| D17 | Is e-mail (SMTP) operational on UAT? | Required. Otherwise onboarding needs an e-mail waiver too, which I do **not** recommend. |
| D18 | Dev database | Build `dpc_fresh` alongside; do not reset `dpc_dev`. If you want `dpc_dev` reset instead, confirm the HE data is disposable and approve the backup. |
