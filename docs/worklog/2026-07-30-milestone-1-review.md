# Milestone 1 — Review

**Date:** 2026-07-30 · **Branch:** `feature/m0-setup` · **Stack:** Laravel 13 / PHP 8.4 / MySQL / Redis / Blade + Bootstrap 5.3

## Objective
Deliver the identity foundation: RBAC, authentication (login/logout), department registration with two-tier accounts, dual-channel (email + mobile) OTP verification, credential issuance, audit trail, and the e-sign (OTP step-up) foundation. Source: PPT S5–S6; decisions Q1 (email/mobile + password + OTP, no Aadhaar), Q2 (CGPSC Admin role).

## Work completed
- RBAC via **spatie/laravel-permission** — 6 roles seeded (admin, authorized_officer, administrative_head, chairman, member, individual); middleware aliases registered.
- Audit trail via **owen-it/laravel-auditing** — `User` and `Department` are audited.
- **Department registration** (public, PPT S6): department dropdown from a seeded master list, district/place/PIN/.gov email, AH + AO details; provisions AO (Level-1) and AH (Level-2) accounts and issues credentials over SMS + email.
- **Dual-channel OTP verification**: email + mobile OTPs; account usable only when both channels are verified; verifying the registrant activates the department.
- **Authentication**: login by **email or mobile** + password; session regeneration; logout; unverified accounts routed to OTP verification; deactivated accounts blocked.
- **E-sign foundation**: `SignEvent` model + `SignatureService` (OTP step-up → immutable sign record), ready to wire to business actions in later milestones.
- Config-driven OTP/SMS/admin settings in `config/dpc.php`.

## Database changes
- **users** (modified base migration): added `designation`, `mobile` (unique), `mobile_verified_at`, `department_id` (+FK, nullOnDelete), `level` (1/2), `is_active`, `deleted_at`; `email` made nullable + unique.
- **departments** (new): `code` (unique), `name` (unique), `district`, `place`, `pin`, `email`, `status`, `registered_at`, `created_by`/`updated_by`, soft deletes.
- **otp_verifications** (new): `identifier`, `channel`, `purpose`, `code_hash`, `attempts`, `expires_at`, `consumed_at`; index (`identifier`,`purpose`).
- **sign_events** (new): `user_id`, morph `signable`, `channel`, `otp_identifier`, `ip_address`, `user_agent`, `meta`, `signed_at`.
- Published package tables: permission set (roles, permissions, model_has_roles, model_has_permissions, role_has_permissions) and `audits`.

## Backend changes
- Enums: `Role`, `UserLevel`, `DepartmentStatus`, `OtpChannel`, `OtpPurpose`.
- Models: `User` (HasRoles + Auditable + SoftDeletes), `Department` (Auditable), `OtpVerification`, `SignEvent`.
- Services: `OtpService`, `SmsService` (log driver, gateway-pluggable), `RegistrationService`, `VerificationService`, `SignatureService`.
- Controllers (thin): `Auth\LoginController`, `Auth\RegistrationController`, `Auth\VerificationController`, `DashboardController`.
- Form Requests: `LoginRequest`, `RegisterDepartmentRequest`, `VerifyOtpRequest`.
- Middleware: `SetLocale` (already present) + Spatie role/permission aliases.
- Seeders: `RolesAndPermissionsSeeder`, `DepartmentMasterSeeder`, `AdminUserSeeder`.

## Frontend changes
- Auth-aware navbar (user + logout / login + register) in the base layout.
- `partials/alerts` (status + validation errors); views: `auth/login`, `auth/register` (PPT-faithful bilingual labels), `auth/verify`, `dashboard`.

## APIs
Web routes (no JSON API this milestone):
`GET/POST /login` · `GET/POST /register` · `GET/POST /verify` · `GET /dashboard` (auth) · `POST /logout` (auth) · plus M0 `/`, `/health`, `/lang/{locale}`.

## Business rules implemented
- Two-tier department accounts — AO (Level-1) prepares, AH (Level-2) approves (PPT S5).
- Department master list from PPT S6; department code prefixes the future DPC ID.
- Credentials issued to AO and AH via mobile + email on registration (PPT S5).
- Both email and mobile must be OTP-verified before an account is usable (Q1).
- Login accepts email or mobile as username (Q1).

## Validation rules
- Registration: department must exist and be `unregistered`; PIN `digits:6`; officer mobiles `digits:10`, unique, AO ≠ AH; department email required, `.gov.in`, unique.
- Login: `login` + `password` required.
- OTP: `mobile_code` required, `email_code` nullable, each `digits:{config length}`.

## Files created
Enums (5), Models (`Department`, `OtpVerification`, `SignEvent`), Services (5), Controllers (4), Form Requests (3), Migrations (departments, otp_verifications, sign_events), Seeders (3), `config/dpc.php`, Views (`partials/alerts`, `auth/login`, `auth/register`, `auth/verify`, `dashboard`), Tests (`Feature/Auth/RegistrationTest`, `OtpVerificationTest`, `LoginTest`, `Unit/OtpServiceTest`).

## Files modified
`app/Models/User.php`, base users migration, `bootstrap/app.php`, `routes/web.php`, `resources/views/layouts/app.blade.php`, `config/dpc.php`, `phpunit.xml`, `database/seeders/DatabaseSeeder.php`, `database/factories/UserFactory.php`, `composer.json`/lock.

## Composer packages added
- `spatie/laravel-permission` ^8.3
- `owen-it/laravel-auditing` ^14.0

## Tests executed
19 tests / 58 assertions — Registration (3), OTP verification (2), Login (6), OtpService unit (2), plus M0 Health (4) and examples (2). All passing.

## Quality gate results
- Laravel Pint (PSR-12 + strict types): **passed**
- PHPStan / Larastan level 6: **no errors** (53 files)
- `php artisan test`: **19 passed**
- `php artisan migrate:fresh --seed`: **OK**
- `php artisan optimize`: **OK**
- Live HTTP smoke: `/login` 200, `/register` 200 (dropdown + Hindi labels), `/verify` gates to login, `/dashboard` 302 → login.

## Remaining work
- CGPSC Admin provisioning UI for Chairman/Member accounts (planned M2).
- Force password change on first login (temp passwords are system-issued).
- Full bilingual coverage of auth screens (labels are partially bilingual now).
- AH first-login verification currently verifies mobile only (AH has no email in the PPT model).

## Risks
- **SMS/email gateway not integrated** — OTPs and credentials are written to the log only (dev). No real delivery until a gateway is chosen (see questions Q1).
- **Identity-model reconciliation** — mobile is the primary unique login; email is nullable/unique and held by the AO. Needs confirmation (questions Q2).
- Temp passwords transit SMS/email in plaintext (standard for issuance) — mitigated later by forced change on first login.

## Performance considerations
- Redis backs cache, queue and sessions. OTP lookups use the (`identifier`,`purpose`) index. Spatie caches permissions. Registration wrapped in a DB transaction.

## Security considerations
- Passwords hashed (bcrypt); OTP codes hashed at rest; CSRF on all forms; RBAC via Spatie; audit trail on `User`/`Department`; `.gov.in` email enforced; session regenerated on login; OTP attempt cap; `APP_DEBUG=false` in production (no stack traces).
- **Gaps (deferred):** login/OTP rate limiting & lockout; forced password rotation; real gateway with delivery receipts.

## Deferred items
- Rate limiting / brute-force lockout on login and OTP.
- Password reset & forced first-login password change.
- Admin provisioning UI (M2).
- Full i18n of auth screens.
- Production SMS/email gateway integration.
