# DigiLocker Identity Verification and Officer Lifecycle — Architecture Audit and Implementation Plan

**Status:** Read-only audit. Nothing has been implemented, migrated or changed.
**Date:** 27 October 2026
**Codebase:** `/var/www/html/DPC`, branch `main`, HEAD `eed36c0`, with 166 uncommitted working-tree entries (all preserved; this report is the only new file).
**Stack:** PHP 8.4.26, Laravel 13.23 (`composer.lock`), MySQL (`dpc_fresh` locally), Redis sessions/cache, Spatie Permission, owen-it Laravel Auditing.
**Companion business plan:** `docs/plans/AO-AH-DIGILOCKER-IDENTITY-PLAN.md`.

**Conventions.** Each statement is tagged:

| Tag | Meaning |
|---|---|
| **[Confirmed]** | Read in the source code. |
| **[Recommendation]** | Proposed design. |
| **[Unverified]** | Needs confirmation (API, CGPSC, or deeper code reading). |

Line numbers refer to the working tree on the audit date.

---

## 1. Executive summary

1. **No identity verification exists today [Confirmed].**
   - No date of birth or gender is stored on `users`.
   - No DigiLocker code exists.
   - An Aadhaar-OTP provider exists, but it is *not wired to any controller*.
2. **The OTP is not MFA [Confirmed].**
   - OTP is required only while an account's e-mail or mobile is unverified.
   - Every later login is password-only (`LoginController::store`, `User::isFullyVerified()`).
   - The requirement "MFA on every login" is therefore a behavioural change, not a configuration change.
3. **An officer can hold exactly one department posting at a time [Confirmed].**
   - A unique index `uda_user_active_unique (user_id, active_flag)` enforces it (`database/migrations/2026_08_24_360003_create_user_department_assignments_table.php:77`).
   - So does a `hasOne` relation `User::activeAssignment()` (`app/Domain/Authentication/Models/User.php:194`).
   - **Additional charge is impossible without a schema change.**
   - **AO in one department + AH in another is impossible today.**
4. **Transfers exist only as an immediate swap [Confirmed].**
   - `DepartmentAssignmentService::assign()` retires the old posting and activates the new one at once, even for a future `effective_from`.
   - Nothing processes effective dates on a schedule.
   - No CGPSC approval step, no request record, and no receiving-department initiation exist.
5. **Security-critical gaps found during the audit [Confirmed]:**
   - deactivating an account does not end its sessions or remember-me cookie;
   - remember-me restores a session without OTP;
   - `previous-dpcs/*` is reachable before the forced password change;
   - Promotion Candidate accounts are marked verified without any OTP;
   - the Aadhaar mock provider has no production guard;
   - the timeline, workflow ledger and `audits` tables are not tamper-protected;
   - login, logout and OTP failures leave no security trail.
6. **Allotment needs follow-up work [Confirmed].** Per-proposal AO/AH allotment (built 26 Oct 2026, uncommitted) assumes the officer's single department (`ProposalAllotmentService::isAllotted()`, about line 50). On transfer, current allotments are neither ended nor reassigned.
7. **Verdict.** The proposed design **fits without a rewrite**, provided that:
   - the posting model becomes multi-row with an *acting assignment* chosen per session (the pattern already exists for roles: `ActiveRoleService`);
   - authentication gains an explicit post-login state gate;
   - about 50 call sites that read `$user->department_id` are routed through the acting assignment.

   This is the largest single change. Most call sites follow automatically, because they read the `activeAssignment` relation.

---

## 2. Existing architecture findings

### 2.1 Authentication and sessions [Confirmed]

| Area | Evidence |
|---|---|
| Guard | One guard `web`, session driver, Eloquent provider on `App\Domain\Authentication\Models\User` (`config/auth.php:42-70`). Reset tokens 60 min; `password_timeout` 10 800 s. |
| Session | `config/session.php`: lifetime 120 min, `encrypt` false, `http_only` true, `same_site` lax, `secure` from env. Driver `redis` in `.env`. |
| Middleware | `bootstrap/app.php`: web group appends `SetLocale`, `ResolveActiveRole`, `SecurityHeaders`; `$middleware->authenticateSessions()` adds `AuthenticateSession` (ends other sessions when the password hash changes). Spatie aliases `role`, `permission`, `role_or_permission`. |
| Custom middleware | `EnsurePasswordChanged` (redirects while `must_change_password`), `ResolveActiveRole` (narrows `$user->roles` to the acting role), `EnsureCanWorkAnAllotment` (CGPSC side), `SecurityHeaders`, `SetLocale`. **No middleware enforces verification, OTP or `is_active`.** |
| API | None: `routes/api.php` does not exist. Route files: `web.php`, `console.php`, `vacancy-records.php` (included inside the protected group). |
| Route groups | Public: login (`throttle:login`), invitation registration, `/verify`, self-inspection, signed `promotion-orders/{order}`. `auth` group at `routes/web.php:144`; inside it, `previous-dpcs` (`role:cgpsc_staff\|admin`, ~l.147) sits **outside** `EnsurePasswordChanged` (opened at ~l.162), which wraps everything else. |

### 2.2 Login, first login and OTP [Confirmed]

**`LoginController::store`** (`app/Http/Controllers/Auth/LoginController.php:34-95`):

1. The identifier is an e-mail, a 10-digit mobile, or an employee number.
2. `Hash::check`, then `is_active`.
3. If `! isFullyVerified()`: `VerificationService::start(..., OtpPurpose::Login)`, then `pending_verification` is stored in the session and the user is redirected to `/verify`. **No login yet.**
4. Otherwise `Auth::login($user, remember)`, session regenerate, and `password_hash_web` is stored.

**After OTP:**
- `VerificationController::store` calls `confirm()`, then `Auth::login` (no remember). It does **not re-check `is_active`**.
- `EnsurePasswordChanged` forces `/password/change`.
- `PasswordChangeController::update` sets the new password, `must_change_password = false` and `password_changed_at`.

**Order today:** password → OTP (only while unverified) → forced change → dashboard.

**OTP engine:**
- `OtpService`: `issue`, `verify`, `verifyAll` (joint), `matching`, `assertMaySend`, and `fixedCodeInForce()` with the allow-list `local`/`testing`/`uat`.
- `VerificationService`: `start`, `resend`, `confirm`, `needsEmailCode`, `needsMobileCode`.
- `MobileVerificationWaiver`.
- `SendOtpNotification`: synchronous; an SMS is not sent while the gateway is off.

| OTP setting (`config/dpc.php` `otp`) | Current default | Target |
|---|---|---|
| Expiry | 600 s (`DPC_OTP_TTL`) | 300 s |
| Wrong attempts | 5 **per code row** | 3 **per challenge** (a resend currently resets the count) |
| Resend cooldown | 60 s | 60 s ✔ |
| Sends per hour | 5 per identifier, shared across purposes | review |
| Fixed code | only `local`/`testing`/`uat` with the env flag | none in production ✔ (code-enforced) |

**Throttles** (`AppServiceProvider.php:281-357`): `login`, `otp-verify` (5/min per pending user + IP), `registration`, `password-reset`.

### 2.3 Roles and permissions [Confirmed]

- **Roles** (`app/Enums/Role.php`): Admin, AuthorizedOfficer, AdministrativeHead, Chairman, Member, Individual, PromotionCandidate, CgpscStaff, DepartmentAdmin.
  - `departmental()` = AO, AH, PromotionCandidate, DepartmentAdmin.
  - `departmentManageable()` = AO, AH.
- **Spatie roles are global** (no teams). No permission rows exist; authorization is `role:` middleware plus policies.
- **Several roles per user:** allowed. The acting role is chosen per session (`ActiveRoleService`, session key `active_role`, POST `/acting-as`, `RoleSwitchController`). It is re-validated each request against `grantedRoleNames()`.
- **Role follows the posting:** `DepartmentAssignmentService::projectRole()` (~l.180) removes every other departmental role when a posting is made. A departmental role is therefore a projection of the single posting.

### 2.4 Portal Super Admin and Department Admin [Confirmed]

- **Super Admin** (`Role::Admin`):
  - Created by `dpc:bootstrap-super-admin` (`app/Console/Commands/BootstrapSuperAdminCommand.php`). It refuses a second admin.
  - `--recover --email --reason` re-issues the password and records `user.password_recovered`.
  - Managed in `Admin\UserController` (`toggleActive`, `resetPassword`).
  - **Recovery does not force re-verification or OTP.**
- **Department Admin:**
  - Registers only by invitation: `department_invitations`, `DepartmentInvitationService`, `RegistrationController`. Invitation link shown once; e-mail OTP plus mobile OTP or waiver.
  - Manages AO/AH through `Department\DepartmentUserController`.
  - Every action is re-checked by `DepartmentUserAuthority`: same department, target holds only AO/AH, not self.
- **AO/AH creation** (`UserProvisioningService::provision`):
  - 16-character random temporary password, shown once (`ShowsIssuedPassword`, no-store).
  - `must_change_password = true`; a mobile waiver is granted when SMS is unusable; `CredentialsIssued` (no password in it).
  - `update()` clears verification when e-mail or mobile changes.
  - `resetPassword()` rotates `remember_token`.
  - **`setActive()` only flips `is_active`.**
  - `StoreDepartmentUserRequest` requires a **unique e-mail and mobile**, so a Department Admin cannot add an officer who already has an account.

### 2.5 Department-to-user relationship [Confirmed]

- **The posting is a table**, not a `users` column: `user_department_assignments`.
  - Columns: user, department, role, `effective_from`, `effective_to`, `active_flag` (1 or NULL), remarks.
  - `users.department_id` was dropped in migration `2026_08_24_360003`.
- **`User` accessors** `department_id`, `department`, `assigned_role` all read the `hasOne` `activeAssignment`.
- **Single-department assumptions:** about 45 in `app/` and 8 in views, all through these accessors. Major groups:

| Group | Call sites |
|---|---|
| Policies | `DpcProposalPolicy`, `PreDpcProposalPolicy`, `DpcCirculationPolicy`; allotment checks via `ProposalAllotmentService::isAllotted()` |
| Form requests | Save DPC / Pre-DPC, `OpenPreDpcRequest`, raise circulation, create review, store department user, assign user |
| Controllers | DPC, Pre-DPC, circulation, review, report, vacancy master/management, admin users, department users, proposal allotment |
| Services | `DepartmentUserAuthority`, `CirculationService`, `DashboardSummaryService`, `DpcReportService::scopeFor`, `NotificationRecipientResolver` (via `whereHas('activeAssignment')`), `UserProvisioningService`, `PromotionCandidateProvisioner` |
| Routes | AO/AH groups use `role:authorized_officer\|administrative_head`: the global role, no department |

- **Effective dates are recorded but never enforced.** `scopeActive()` reads only `active_flag`. The only scheduled command is `ReturnExpiredAllotmentsCommand` (`routes/console.php`).
- **`DepartmentSeatChanged` is dead code.** The event and its listener `SendSeatChangeNotification` exist, but nothing dispatches it.

### 2.6 DPC officer allotment [Confirmed — built 26 Oct 2026, uncommitted]

- **Table** `proposal_allotments` (`2026_10_26_690001`), append-only: the current row has `ended_at` NULL.
- **Service** `app/Domain/Allotment/Services/ProposalAllotmentService.php`: `allot`, `inherit`, `isAllotted`, `scopeTo`, `recipientIds`, `narrowRecipients`.
- **Screens:** `Department\ProposalAllotmentController` (`department.allotments.*`).
- **Enforcement:**
  - Pre-DPC, DPC and Circulation policies require the allotted officer.
  - Notifications narrow to allotted officers inside `WorkflowEngine::record`.
- **Gaps:**
  - `isAllotted()` compares `$user->department_id` with the proposal's department.
  - On transfer, nothing ends or reassigns allotments, so proposals are orphaned.
  - `allot()` does not refuse `$ao->id === $ah->id`. This is safe today only because a user cannot hold both roles.

### 2.7 Pre-DPC and DPC workflows [Confirmed]

- **Workflow engine:** `app/Domain/Shared/Workflow/WorkflowEngine.php`, with roles and transitions in `config/dpc.php` (`pre_dpc`, `dpc_proposal`, `dpc_cgpsc`, `dpc_proceeding`, `dpc_circulation`).
- **Pre-DPC:** opened by the Department Admin with its AO/AH; prepared by the allotted AO; approved by the allotted AH. `PreDpcProposalService`, `PreDpcProposalPolicy`.
- **DPC:** raised by the allotted AO from an approved Pre-DPC (`DpcProposalService::createFromPreDpc`); inherits the allotment; approved by the allotted AH.

### 2.8 Audit trail and timeline [Confirmed]

- **Data changes:** owen-it auditing on 55 models, including `User` (`$auditExclude` password, remember_token), `UserDepartmentAssignment`, `ProposalAllotment` and `DepartmentInvitation`.
  - `config/audit.php` `console` is off outside tests.
  - **Spatie role pivots are not audited.**
- **Activity timeline:** `ActivityTimelineService::record()` writes `activity_timelines` with an actor snapshot (`actor_uuid`, `actor_name`).
- **Workflow ledger:** `workflow_transitions`, written with IP and user agent.
- **Audit Trail screen:** `AuditTrailService` with `Admin\AuditTrailController` (filters; CSV export with formula neutralisation; `role:admin` only).
- **User events recorded today:** `user.provisioned`, `user.updated`, `user.activated` / `user.deactivated`, `user.password_reset`, `user.password_recovered`, `user.assigned` / `user.transferred` / `user.released`, the invitation events, `proposal.allotted` / `proposal.reallotted`.
- **Not recorded:**
  - login success or failure, logout;
  - OTP failure or lockout;
  - a user's own password change (only the flag columns are audited);
  - role pivot changes.
- **Tamper-evidence:**
  - Twelve evidence tables have model-level update/delete guards (`config/dpc.php` `security.evidence_tables`).
  - `security:evidence-grants` prints SQL grants that are **not applied** (blocker B-3).
  - `audits`, `activity_timelines` and `workflow_transitions` have **no** guard at all.

### 2.9 Documents [Confirmed]

- **Storage:** private disk `local` (`storage/app/private`, `serve => true`), or S3 by flag. Paths are `private/<area>/<id>`.
- **Metadata:** rows store `disk`, `path`, `original_name`, `size_bytes`, `sha256`, `uploaded_by`.
- **Validation:** `mimes:pdf` plus `max:10240` KB.
- **Downloads:** streamed through controllers, with policy or ownership checks.
- **Missing protections:** no antivirus scan, no encryption at rest. The only signed URL is the public promotion-order link.

### 2.10 Aadhaar / UIDAI [Confirmed]

- **Code** (all untracked, `??`):
  - contract `app/Integrations/Contracts/AadhaarOtpProvider.php`;
  - real `app/Integrations/Uidai/UidaiAadhaarOtpProvider.php` (CSC Web Gateway; `<Auth>` yes/no only, *not e-KYC*);
  - mock `app/Integrations/Mock/MockAadhaarOtpProvider.php`.
- **Settings:** `config/dpc.php` `aadhaar_otp`; flag `Feature::UidaiAadhaarOtp`.
- **Usage:** only in `RegistrationVerificationService`.
  - It stores in the session an HMAC of the Aadhaar number keyed with `app.key`, plus the last 4 digits.
  - **No controller or view calls it** (unwired).
  - No Aadhaar column exists in any table.
- **Provider pattern:** `AppServiceProvider` binds each contract (`SmsSender`, `SignatureProvider`, `HrmsProvider`, `AadhaarOtpProvider`, `DirectoryProvider`) by feature flag. **No binding refuses a mock in production.**

### 2.11 Data reset / cleanup [Confirmed]

- `ResetTransactionalDataCommand` (`TRANSACTIONAL` / `PRESERVED`, refuses in production) and `UatCleanupPlan` (`CLEAN` / `PARTIAL` / `PRESERVE` / `FILE_REFERENCES`) must classify every table. Tests enforce this, so new tables must be listed.
- `STORAGE_PATHS` misses several upload areas: proposal representations, previous DPCs, reservation policies, promotion orders.

---

## 3. Implemented vs missing — functionality matrix

| Capability | Status | Evidence / note |
|---|---|---|
| Department Admin by invitation, link shown once | **Implemented** | `DepartmentInvitationService`, `RegistrationController` |
| AO/AH creation by Department Admin, temp password once | **Implemented** | `UserProvisioningService::provision`, `ShowsIssuedPassword` |
| Forced first password change | **Implemented** | `EnsurePasswordChanged` (except `previous-dpcs`) |
| E-mail OTP / mobile OTP at first login | **Implemented** | `VerificationService`; mobile waivable while there is no SMS |
| OTP on every login (MFA) | **Missing** | Only while unverified |
| OTP 5 min / 3 attempts per challenge | **Partial** | 10 min / 5 per code row |
| Fixed OTP never in production | **Implemented** | `OtpService::FIXED_CODE_ENVIRONMENTS` |
| DOB / gender on officer | **Missing** | No columns on `users` |
| DigiLocker verification | **Missing** | No code |
| Aadhaar-OTP (yes/no) | **Partial** | Provider exists; unwired; mock not production-guarded |
| Identity lock + Super Admin correction | **Missing** | — |
| Manual verification by Super Admin | **Missing** | — |
| Aadhaar-linked mobile undertaking | **Missing** | — |
| Per-proposal AO/AH allotment + reassignment | **Implemented** (uncommitted) | `ProposalAllotmentService` |
| One officer, several department roles | **Missing** | Unique one-active-posting index |
| Same-department AO+AH conflict rule | **Implicit only** | Follows from one posting; no explicit rule |
| Transfer (immediate) | **Partial** | `DepartmentAssignmentService::assign`, admin-only |
| Transfer request → CGPSC approval → effective date | **Missing** | — |
| Additional charge | **Missing** | — |
| Acting-department switcher | **Missing** | Role switcher exists (`ActiveRoleService`) |
| Effective-date processing (scheduler) | **Missing** | Only `ReturnExpiredAllotmentsCommand` scheduled |
| Allotment end / reassign on transfer | **Missing** | Orphaned allotments |
| Session end on deactivation | **Missing** | `setActive` only flips the flag |
| Security log (login / OTP / logout) | **Missing** | — |
| Tamper-evident audit | **Partial** | Evidence tables guarded in code; grants unapplied; audit tables unguarded |
| Supporting documents (private, hashed) | **Implemented** pattern | No AV scan, no encryption at rest |

---

## 4. Requirements-to-code mapping (Part 2 requirements)

| # | Requirement | Where it lands | Status |
|---|---|---|---|
| 1 | Department Admin creates AO/AH with name, DOB, gender, designation, e-mail, mobile, role | `StoreDepartmentUserRequest`, `UserProvisioningService::provision`, `department/users/form.blade.php` | DOB/gender missing |
| 2 | Temporary password shown once | `ShowsIssuedPassword` | Done |
| 3–5 | DigiLocker at first login; attributes; compare | New `IdentityVerificationService` + `DigiLockerProvider` + state gate (§7) | Missing |
| 6–7 | Exact DOB/gender; normalised name, no auto-approve of initials or Hindi-English | New `IdentityMatcher` | Missing |
| 8 | Lock name, DOB, gender after success | New verification record + model guard | Missing |
| 9 | Before success, Department Admin corrects with reason + history | `UpdateDepartmentUserRequest` + new field history | Partial (edit exists; no reason; no field history) |
| 10–11 | After success, only Super Admin corrects: reason, document, history, invalidate, revoke sessions, block DPC, re-verify | New correction request flow; session revocation (§9) | Missing |
| 12 | Past DPC approvals unchanged | Workflow ledger and signatures are separate from the user record | Holds by design [Confirmed] |
| 13 | Designation change → no re-verification | Exclude `designation_id` from identity fields | Design rule |
| 14 | E-mail/mobile change → channel OTP only | `UserProvisioningService::update` already clears the channel | Done |
| 15 | No DigiLocker at every login | Verification record persists | Design rule |
| 16 | CGPSC may require re-verification | New `revoke()` action | Missing |
| 17 | 3 failed matches in 24 h; technical failures excluded | New `identity_verification_attempts` with outcome class | Missing |
| 18 | Manual verification by Super Admin | New method `manual` + document | Missing |
| 19 | Same DPC permissions either way | Gate checks "verified", not method | Design rule |
| 20 | No expiry; admin may require re-verification | as 16 | Missing |
| 21 | Department Admin sees mismatch details | Subject to DigiLocker data-sharing terms **[Unverified]** | Missing |
| 22 | No Aadhaar number / document / photo | Never request the e-Aadhaar XML; use user-details only, if enough | Design rule |
| 23 | Access-controlled identity data, no duplication in logs | Store DigiLocker values once (encrypted); timeline holds references | Design rule |
| 24 | Department Admin and Super Admin also verified; bootstrap/recovery exception | Gate applies to all roles; Super Admin break-glass via console with reason | Missing |

---

## 5. Proposed architecture [Recommendation]

### 5.1 Components

```
app/Integrations/Contracts/DigiLockerProvider.php      authorizationUrl(state, pkce) · exchange(code, verifier) · userDetails(token)
app/Integrations/DigiLocker/DigiLockerOAuthProvider.php real (OAuth 2.0 + PKCE, Requester API)
app/Integrations/Mock/SimulatedDigiLockerProvider.php   local/testing/uat only; refuses production at boot
app/Domain/Identity/
    Models/IdentityVerification.php           one row per attempt outcome (append-only)
    Models/IdentityCorrectionRequest.php       Super Admin corrections (with supporting document)
    Services/IdentityVerificationService.php   start · callback · match · record · revoke · manualApprove
    Services/IdentityMatcher.php               normalise + compare (pure, unit-tested)
    Services/IdentityStateResolver.php         the post-login state machine (§7)
    Http/Middleware/EnsureAccountReady.php      the one gate after `auth`
app/Domain/UserManagement/
    Models/UserDepartmentAssignment.php        extended: kind, status, approval, dates
    Services/DepartmentAssignmentService.php   multi-posting aware
    Services/ActingAssignmentService.php       the acting (department, role) per session
    Models/AssignmentRequest.php               transfer and additional-charge requests
    Console/ActivateDueAssignmentsCommand.php  effective-date processor (scheduled, idempotent)
```

### 5.2 Key design choices

1. **One gate, not scattered checks.**
   - `EnsureAccountReady` runs right after `auth`, on every authenticated route, including `previous-dpcs`, which today sits outside `EnsurePasswordChanged`.
   - It replaces `EnsurePasswordChanged`. It asks `IdentityStateResolver` for the account's next required step and redirects there.
   - Routes for the steps themselves (DigiLocker start/callback, undertaking, OTP, password change, logout) are an explicit allow-list.
2. **The acting assignment generalises the acting role.**
   - The session stores an *assignment id*, not just a role name.
   - Middleware loads and validates it on every request: active, not expired, belongs to the user. It sets the `activeAssignment` relation, so `department_id`, `department` and `assigned_role` — and with them about 50 call sites — follow automatically.
   - The Spatie role check stays as a coarse filter; the department comes only from the validated assignment.
3. **Identity data is minimal and separated.**
   - The DigiLocker name, DOB and gender are stored once, encrypted, on the verification row: `encrypted` casts, readable only by the Super Admin and, for mismatch display, the Department Admin, per Q-API-4.
   - Timeline and audit entries record *that* a verification happened and its outcome, never the values.
   - No Aadhaar number, document or photograph is requested. The e-Aadhaar XML endpoint is **not** called unless CGPSC decides otherwise.
4. **The simulator cannot run in production.**
   - The binding refuses when `app()->environment('production')`, and boot fails loudly if the flag points to the simulator there.
   - The same guard should be added for the existing Aadhaar mock.

---

## 6. Proposed database changes [Recommendation — no migration has been written or run]

Naming follows the current convention: next free block `2026_10_27_700001_…`, anonymous migration classes, short explicit index names under 64 characters.

| Table / change | Why | Exists? | Key columns | Indexes / constraints | FK and delete rules | Privacy |
|---|---|---|---|---|---|---|
| `users` + `date_of_birth` (date, null), `gender` (char 1: M/F/T, null), `identity_verified_at` (null), `identity_verification_id` (null) | Department Admin enters DOB and gender; fast "verified?" check | No | — | index `identity_verified_at` | `identity_verification_id` → `identity_verifications`, null on delete | DOB is personal data: encrypted cast or access-limited columns; `$auditExclude` stays narrow |
| **`identity_verifications`** (append-only) | One row per outcome: verified / mismatch / technical_failure / manual / revoked | No | user_id, method (digilocker\|manual), outcome, failure_class, matched_fields (json: per-field pass/fail, **no values**), `provider_reference` (DigiLocker id if the API supplies a stable one **[Unverified]**), `returned_identity` (encrypted json: name, dob, gender), consent_at, ip, user_agent, decided_by (manual), reason (manual), supporting_document_id, revoked_at, revoked_by, revoke_reason, created_at | (user_id, created_at); (user_id, outcome, created_at) for the 3-in-24 h rule | user_id → users **restrict** (history must survive); decided_by, revoked_by → users null on delete | Encrypted payload; model update/delete guard; add to `evidence_tables` |
| **`identity_correction_requests`** | Super Admin correction of locked fields | No | user_id, field, old_value (encrypted), new_value (encrypted), reason, supporting_document_id, corrected_by, corrected_at, verification_revoked_id | (user_id, corrected_at) | user_id restrict; corrected_by null on delete | Encrypted values; evidence-table guard |
| **`supporting_documents`** | One home for correction, manual-verification, transfer-order and additional-charge-order PDFs | Partial: each module stores its own (`dpc_evaluation_documents`, …) | disk, path, original_name, size_bytes, sha256, mime (sniffed), uploaded_by, uploaded_at, purpose | unique sha256+purpose (optional) | uploaded_by null on delete | Private disk, streamed through a policy; add to `FILE_REFERENCES`; consider encryption at rest |
| **`account_undertakings`** | Aadhaar-linked mobile self-declaration | No | user_id, kind (`aadhaar_mobile`), text_version, mobile_hash (hash of the declared number, not the number), accepted_at, ip, user_agent | (user_id, kind, accepted_at) | user_id restrict | Store a hash, not the number |
| `otp_verifications` + `challenge_id` (uuid), `user_id` (null) | Count attempts **per challenge** across resends; tie to user | Partial | — | index challenge_id | user_id null on delete | — |
| **`security_events`** (append-only) | Login success/failure, logout, OTP failure/lockout, password change, session revocation | No (timeline is not suited to volume) | user_id (null), event, outcome, ip, user_agent, context (json, no secrets), occurred_at | (user_id, occurred_at); (event, occurred_at) | user_id null on delete | Retention policy; never OTP or password |
| `user_department_assignments` **reshaped** | Several concurrent postings; additional charge; future-dated activation; approval | Exists | add `kind` (primary\|additional_charge), `status` (pending\|approved\|active\|ended\|rejected), `approved_by`, `approved_at`, `request_id`; keep effective_from/to, active_flag | **Replace** `uda_user_active_unique (user_id, active_flag)` with: unique (user_id, department_id, active_flag) (one active posting per department, which also forbids AO+AH in the same department); a guarded rule "at most one active `primary`" (unique on (user_id, kind_primary_flag) with a nullable helper column, as `active_flag` does) | unchanged (cascade from users is dangerous for history → change to **restrict**) | Audited (already) |
| **`assignment_requests`** | Transfer and additional-charge requests: initiated by the receiving Department Admin, approved by CGPSC | No | uuid, type (transfer\|additional_charge\|end_additional_charge), user_id, from_assignment_id, to_department_id, proposed_role, order_number, order_date, order_document_id, effective_from, effective_to (additional charge), status (submitted\|approved\|rejected\|cancelled\|effective\|failed), requested_by, decided_by, decided_at, decision_reason, processed_at, failure_reason | (status, effective_from) for the scheduler; (user_id, status) | user_id restrict; departments restrict | Order document via `supporting_documents` |
| `proposal_allotments` + `held_reason` (null), `held_at` | Pending work "on hold" when an officer leaves and reassignment is incomplete | Exists | — | — | — | — |

**Every new table must also be classified** in:
- `ResetTransactionalDataCommand::TRANSACTIONAL` or `PRESERVED`;
- `UatCleanupPlan` (and `FILE_REFERENCES` where files exist).

Two guard tests enforce this.

---

## 7. Authentication and verification state machine [Recommendation]

**States** are derived on every request by `IdentityStateResolver`, never trusted from the session:

```
UNAUTHENTICATED
  └─ password ok, account active ─► PASSWORD_OK (pending session, not logged in)
PASSWORD_OK
  ├─ identity not verified ─► IDENTITY_REQUIRED ─(DigiLocker match | Super Admin manual)─► IDENTITY_OK
  │        └─ mismatch ×3 in 24 h ─► IDENTITY_LOCKED (Department Admin / Super Admin action)
  └─ identity verified ─────────────────────────────────────────────────────────────────► IDENTITY_OK
IDENTITY_OK
  ├─ no current Aadhaar-mobile undertaking ─► UNDERTAKING_REQUIRED ─(accept)─► OTP_REQUIRED
  └─ ─────────────────────────────────────────────────────────────────────────► OTP_REQUIRED
OTP_REQUIRED (mobile + e-mail, every login)
  └─ both codes valid ─► Auth::login + session regenerate ─► AUTHENTICATED
AUTHENTICATED
  ├─ must_change_password ─► PASSWORD_CHANGE_REQUIRED ─(change)─► READY
  └─ ─────────────────────────────────────────────────► READY
READY ─► dashboard; DPC actions require an acting assignment (§9)
```

**Rules:**
- **Login happens late.** `Auth::login` happens only after OTP, as today. Steps before it run on a short-lived, single-purpose pending session: user id, step, expiry. A stale pending session cannot reach any authenticated route.
- **One gate.** `EnsureAccountReady` runs on every authenticated route and re-checks:
  - `is_active`;
  - verification not revoked;
  - password not stale;
  - the acting assignment is still valid.

  This closes the deactivation, revocation and transfer gaps for existing sessions.
- **Remember-me must not skip MFA.** Either remove remember-me (recommended for a government signing portal), or let it restore only PASSWORD_OK and still require OTP.
- **Every exit path revokes.** Deactivation, verification revocation, identity correction, transfer effect and role removal each:
  - rotate `remember_token`;
  - delete the user's rows in the session store (the Redis/database session driver must be queried by user; see Risk R4);
  - write a `security_events` row.
- **Technical failures do not count.** DigiLocker unavailable, timeouts and invalid state are classed `technical_failure` and excluded from the 3-in-24 h limit. A `mismatch` counts.
- **OTP per challenge.** One challenge covers one login attempt. Attempts are counted across resends within it. The challenge expires after 5 minutes. Three wrong attempts end it, and the user must start a new challenge from the password step.

**Bypass surfaces to cover (tests in §13):**
- direct URLs to any authenticated route in each pre-READY state;
- `previous-dpcs` (currently outside the password gate);
- the signed public promotion-order link (public by design; keep it read-only);
- queued listeners (they act as the system, not the user; confirm none perform user-authorised writes **[Unverified]**);
- the `/acting-as` endpoint (must refuse in pre-READY states);
- stale Spatie role cache (24 h).

---

## 8. Transfer and additional-charge state machines [Recommendation]

**Assignment request** (one table, two types):

```
SUBMITTED (receiving Department Admin, with order + date)
  ├─ CGPSC Super Admin rejects (reason) ─► REJECTED
  ├─ requester cancels before decision ─► CANCELLED
  └─ CGPSC Super Admin approves ─► APPROVED (effective_from in future) ─┐
                                    └─ effective_from ≤ today ─────────┴─► EFFECTIVE (scheduler or immediate)
EFFECTIVE (transfer):
  1. lock the officer's assignment rows (SELECT … FOR UPDATE)
  2. end the old primary posting (effective_to = D-1, active_flag NULL)
  3. create the new primary posting (approved)
  4. for each current proposal allotment in the old department naming the officer:
       mark held (held_reason = 'officer transferred'); notify the old Department Admin to reassign
  5. revoke sessions and remember token; write timeline + security events
  6. identity verification is untouched (identity fields unchanged)
EFFECTIVE (additional charge): insert an active additional_charge posting; primary untouched
ADDITIONAL CHARGE: ACTIVE ─(effective_to reached | ended by CGPSC/receiving DA)─► ENDED (+ held allotments + session refresh)
```

**Concurrency and retries:**
- **Idempotent scheduler.** `ActivateDueAssignmentsCommand` runs daily (and at login, lazily). It selects `APPROVED` requests due, takes a row lock per request, and re-checks the status inside the transaction, so it is safe to retry.
- **Failures are recorded.** A failure records `FAILED` with a reason and alerts the Super Admin. The officer keeps only the *old* access until it is resolved, so a failure never grants extra access.
- **Conflict rules are checked twice,** at approval and again at effect, in case of a race between two requests:
  - no AO+AH in the same department;
  - at most one primary posting.
- **Reassignment may lag.** If held allotments are not reassigned in time, they stay held: nobody can act, and nobody unauthorised gains access (requirement 13).

---

## 9. Authorization and data isolation [Recommendation]

1. **Department comes from the acting assignment only.**
   - Never from a request parameter.
   - Re-validated on every request in middleware: assignment active, within its dates, belongs to the user, user active, identity verified.
2. **Policies keep their shape.** Policies and `ProposalAllotmentService::isAllotted()` keep comparing `$user->department_id`, now the acting department. In addition, `isAllotted()` should verify that the allotment's officer holds an *active assignment in that department with that role* (the existing `isEligible()` logic). Then a transfer instantly disables old allotments, even before reassignment.
3. **Allotment rules.**
   - `allot()` must refuse `$ao->id === $ah->id`.
   - Eligibility must use the role *of the assignment in the proposal's department*, not the global Spatie role.
4. **Listings and dashboards.** `DashboardSummaryService`, `DpcReportService::scopeFor`, notifications and the vacancy controllers scope to the acting department.
   - Notifications by allotment already target user ids.
   - Department-wide notifications (`NotificationRecipientResolver`) must select users by an active assignment *in that department*. This already works once `activeAssignment` is no longer `hasOne`-only; it must be checked.
5. **Identity is necessary but not sufficient (requirement 4.7).** DPC access additionally requires an active assignment and a current allotment.

---

## 10. Security and privacy risks

| ID | Risk | Severity | Status | Mitigation |
|---|---|---|---|---|
| R1 | Deactivated users keep their sessions and remember-me | **High** | Confirmed | Session/remember revocation on `setActive(false)`; gate re-checks `is_active` |
| R2 | No MFA after first verification; remember-me skips it | **High** | Confirmed | §7 every-login OTP; drop remember-me or require OTP |
| R3 | `previous-dpcs/*` reachable before the forced password change | Medium | Confirmed | Move under the new gate |
| R4 | Session store cannot be enumerated per user for revocation | Medium | Unverified | `AuthenticateSession` hash change + `remember_token` rotation ends sessions at their next request; or use the database session driver with a `user_id` index |
| R5 | Promotion Candidates marked verified without OTP | Medium | Confirmed | Decide whether candidates come under the same gate |
| R6 | Aadhaar mock bindable in production; no guard for the future DigiLocker simulator | **High** | Confirmed | Production refusal in the binding and at boot |
| R7 | Audit tables not tamper-protected; evidence grants unapplied | Medium | Confirmed | Model guards on new identity tables; apply B-3 grants; consider hash chaining |
| R8 | No security log | Medium | Confirmed | `security_events` |
| R9 | Aadhaar HMAC keyed with `app.key` (key rotation breaks matching) | Low | Confirmed | Dedicated key if Aadhaar-OTP is ever wired |
| R10 | Uploads: no AV scan, MIME by extension | Medium | Confirmed | Content sniffing (`finfo`), AV scan where available |
| R11 | Allotments orphaned on transfer; old officer still notified | Medium | Confirmed | §8 step 4; `isAllotted()` assignment check |
| R12 | Effective dates not enforced | Medium | Confirmed | Scheduler (§8) |
| R13 | DigiLocker values duplicated into logs or audits | Medium | Design | Store once, encrypted; logs hold outcomes only |
| R14 | Name-matching false positives (initials, transliteration) | Medium | Design | Strict normalised match; no fuzzy auto-approval |

---

## 11. External DigiLocker dependencies and unknowns

**Confirmed from the official Requester API Specification v1.12 (API Setu):**
- OAuth 2.0 authorisation-code flow with PKCE.
- The token or user-details response returns `digilockerid` (36 characters), `name`, `dob` (DDMMYYYY), `gender` (M/F/T), `eaadhaar` (Y/N), and `reference_key`, which the spec calls "a transient reference for tracing".
- A separate e-Aadhaar XML endpoint (`/public/oauth2/3/xml/eaadhaar`), HMAC-signed with the client secret; its XML fields are not documented in the public spec.
- No mobile number is returned.

**Unknown — must be confirmed during onboarding:**

| ID | Question |
|---|---|
| Q-API-1 | Is `digilockerid` stable per person, and may it be stored as the verification reference? The spec says `reference_key` is transient. |
| Q-API-2 | Does `eaadhaar = Y` guarantee that name, DOB and gender come from Aadhaar? Or do we need the e-Aadhaar XML, which carries more data than we want (requirement 22)? |
| Q-API-3 | Are consent scopes granular enough to request only the user details? |
| Q-API-4 | May the portal store DigiLocker-returned values and show them to the Department Admin (requirement 21)? Retention terms? |
| Q-API-5 | Sandbox availability, rate limits, error codes, token lifetimes, approved return URL(s) for local / UAT / production. |
| Q-API-6 | Does the e-Aadhaar data carry a mobile hash? Not needed under the current undertaking design. |

**Because of these unknowns, switching from the simulator to the real provider is NOT claimed to be configuration-only.** The provider class will be written against the confirmed spec and adjusted after sandbox testing.

---

## 12. Phased implementation plan [Recommendation — each phase needs approval]

| Phase | Scope | Depends on |
|---|---|---|
| **0. Security hardening** (small, independent) | Revoke sessions and remember-me on deactivation; re-check `is_active` in `VerificationController` and the gate; move `previous-dpcs` under the password gate; production guard for the Aadhaar mock; `allot()` refuses AO = AH | — |
| **1. Account gate + MFA** | `IdentityStateResolver`, `EnsureAccountReady`; OTP on every login; challenge-based attempts (5 min, 3 tries); remember-me decision; `security_events` | Phase 0 |
| **2. Identity data + DigiLocker** | `users` DOB/gender; `identity_verifications`; `DigiLockerProvider` contract, simulator (non-production), real provider skeleton; `IdentityMatcher`; 3-in-24 h; Department Admin pre-verification correction with reason + field history | Phase 1; Q-API-1..5 for the real provider |
| **3. Locked identity, Super Admin correction, manual verification** | `identity_correction_requests`, `supporting_documents`, revoke + re-verify; manual approval; "require re-verification" action | Phase 2 |
| **4. Mobile undertaking** | `account_undertakings`; first-login step; re-ask when the mobile changes | Phase 1 |
| **5. Multi-assignment model** | Reshape `user_department_assignments`; `ActingAssignmentService` + department switcher; route the about 50 call sites through the acting assignment; `isAllotted()` assignment check | Phase 1 |
| **6. Transfer + additional charge** | `assignment_requests`; receiving Department Admin initiates, CGPSC approves; scheduler; allotment hold + reassignment by the old Department Admin; notifications (revive `DepartmentSeatChanged`) | Phase 5 |
| **7. Audit hardening** | Evidence guards on identity tables; apply B-3 grants; audit role-pivot changes; retention controls | Parallel |

Phases 0 and 1 can start immediately. Phase 2 can be built and tested on the simulator before onboarding completes. Phases 5 and 6 are the largest by call-site count.

---

## 13. Test strategy

**Reuse and extend:**
- `tests/Feature/Auth/*`: Login, OtpVerification, PasswordChange, RateLimiting, Registration, RoleSwitch.
- `tests/Unit/OtpServiceTest.php`.
- `tests/Feature/DepartmentAdmin/DepartmentUserManagementTest.php`, `Admin/PortalAdminAccountTest.php`, `Admin/UserDepartmentAssignmentTest.php`. The last encodes the one-posting rule and must change in Phase 5.
- `Allotment/ProposalAllotmentTest.php`, which includes a route-walk refusal test to extend to every pre-READY state.
- `RoleBasedUiVisibilityTest`, `AuthorizationCoverageTest`.
- `Audit/AuditTrailScreenTest`, `Security/EvidenceGrantsTest`, `SchemaIdentifierLengthTest`, `Console/ResetTransactionalDataTest`, `UatCleanupPlanTest`.

**New tests (one or more each):**

| Area | Tests |
|---|---|
| DigiLocker | Successful match; DOB / gender / name mismatch; salutation, case and dots ignored; initials refused; Hindi vs English refused; technical failure not counted; 3 mismatches in 24 h lock; state/PKCE tampering refused; callback replay refused |
| Simulator | Refused in production (binding and boot) |
| Manual verification | Super Admin only; reason + document required; same permissions as DigiLocker |
| Correction | Locked fields refuse the Department Admin; Super Admin correction revokes verification, ends sessions, blocks DPC actions until re-verified; history preserved; past approvals unchanged |
| MFA | OTP on every login; 5-minute expiry; 3 tries per challenge across resends; remember-me cannot skip OTP; no fixed OTP in production |
| Bypass | Every authenticated route refused in each pre-READY state; deactivated user's session ends; `/acting-as` refused pre-READY |
| Accounts | Duplicate officer prevented (same e-mail / mobile / identity) |
| Assignments | Same-department AO+AH refused; AO in A + AH in B isolated (A's data never visible as B, and vice versa) |
| Transfer | Approve, reject, cancel; future effective date not active early; effect ends old access and sessions; allotments held, then reassigned by the old Department Admin; scheduler idempotent under a double run; concurrent approvals of two transfers for one officer — one wins |
| Additional charge | Activation, expiry, removal (audited), switcher validated server-side |
| Audit | Every lifecycle event in §7/§8 writes its record; identity values never in timeline/audit; evidence guards on new tables |

---

## 14. Open questions requiring CGPSC or API confirmation

**For CGPSC:**

| ID | Question |
|---|---|
| Q1 | Name-matching strictness (plan Q1). |
| Q2 | Manual verification route (plan Q2). |
| Q3 | Remember-me: remove it, or keep it with OTP on every use? |
| Q4 | Undertaking wording, English and Hindi (plan Q4). |
| Q5 | Do Promotion Candidates and CGPSC Commission members (Chairman, Member, staff) come under DigiLocker and every-login OTP? |
| Q6 | Which role combinations may coexist across departments: AO + AH only, or also Department Admin? |
| Q7 | Additional charge: is an end date mandatory? Who may end it early: CGPSC, the receiving Department Admin, or both? |
| Q8 | Transfer effective date: can it be in the past (back-dated orders)? If so, which DPC actions taken meanwhile stay valid? |
| Q9 | During a transfer, may the officer's pending items in the old department simply be held until reassigned (current proposal)? |
| Q10 | Retention period for identity-verification records and security events. |

**For DigiLocker / API Setu:** Q-API-1 to Q-API-6 in §11.

---

## 15. Risks, assumptions and recommended mitigations

| Risk or assumption | Mitigation |
|---|---|
| **Largest code impact: the multi-assignment change (Phase 5).** About 50 call sites read `$user->department_id`. | Most follow automatically through the acting-assignment relation; the remainder are listed in §2.5 and covered by route-walk tests. |
| **Assumption: the DigiLocker user-details response is enough** (name, DOB, gender). | Confirm Q-API-2. If the e-Aadhaar XML is needed, extract the three fields and discard the rest immediately, never persisting the document. |
| **Assumption: sessions can be revoked per user.** `AuthenticateSession` + `remember_token` rotation ends sessions at their next request; that is acceptable if every exit path rotates them. | Verify R4 before relying on an immediate kill. |
| **Uncommitted work: 166 entries.** These include the allotment feature, Department Admin onboarding and the unwired Aadhaar slice. | Commit or review them before Phase 0 begins, so each phase is reviewable on its own. |
| **The simulator could leak into UAT data** that is later used as evidence. | Every simulated verification is marked `method = digilocker_simulated`, and UAT data is never promoted to production. |

---

*End of report. No code, configuration, migration, seeder, test or database record was changed. Development waits for explicit approval.*
