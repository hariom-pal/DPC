# Milestone 2 — Review: Master Data & User Management

**Date:** 2026-07-30 · **Branch:** `feature/m0-setup` · **Stack:** Laravel 13 / PHP 8.4 / MySQL / Redis / Blade + Bootstrap 5.3
**Commits:** `53146e5` (P1) · `8202269` (P2) · `765022b` (P3a) · `63e4734` (P3b) · `c83ed8f` (P4) · `c4a2277` (P5) · `ed89f59` (P6) · `b19f340` (P7)

## Objective
Deliver the reference-data and user-administration foundation the DPC proposal flow depends on — derived strictly from the PPTs and the architect's rulings — and adopt the domain + integrations architecture. Scope limited to master **definitions + CRUD + validation + admin UI + audit + seeders**; no proposal/committee-workflow/recommendation/order behaviour.

## Work completed (by phase)
1. **P1 — Architecture migration:** M1 code moved into `app/Domain/{Authentication,Department,Signature}` + `app/Integrations/{Contracts,Mock,CSC}`; no mixed layout.
2. **P2 — Foundations:** `ServiceClass` + `MasterStatus` enums; `Blameable` audit-column trait; **Activity Timeline** (separate from owen-it audit); role-gated `/admin` area + sidebar layout + status-badge component.
3. **P3a — Core masters:** District (LGD-aligned, `DirectoryProvider`), Designation, Pay Scale; `IsMaster` trait + `MasterCrudService`; registration wired to `district_id`/`designation_id`.
4. **P3b — Lookups:** ReservationCategory, ReviewReason, VacancyType, DpcType (+ seeders).
5. **P4 — Reservation hierarchy:** Cadre; ReservationPolicy → ReservationRoster → ReservationRosterPoint.
6. **P5 — Committee:** CommitteeType + CommitteeConfiguration (11 parameters).
7. **P6 — Recruitment Rules:** versioned RecruitmentRule + feeders (GO # + S3 PDF, never-overwrite).
8. **P7 — User Management:** provisioning of Chairman/Member/Admin, role assignment, activate/deactivate, reset password.

## Database changes
15 new/related tables (34 total in schema): `districts`, `designations`, `pay_scales`, `cadres`, `reservation_categories`, `review_reasons`, `vacancy_types`, `dpc_types`, `reservation_policies`, `reservation_rosters`, `reservation_roster_points`, `committee_types`, `committee_configurations`, `recruitment_rules`, `recruitment_rule_feeders`, `activity_timelines`. Every reference master carries `status` (Draft/Active/Inactive/Archived), `created_by`/`updated_by`/`deleted_by`, timestamps, soft deletes; effective-dating on policy/roster/committee-config/recruitment-rule. Wiring migration replaced free-text `departments.district` → `district_id` and `users.designation` → `designation_id`. All FKs + indexes + unique constraints in place. 18 migration files.

## Backend changes
- **Domains:** `app/Domain/{Authentication,Department,Signature,Master,UserManagement,Shared}`.
- **15 master models** (`app/Domain/Master/Models`) via `IsMaster` (Auditable + Blameable + SoftDeletes + status cast).
- **Services:** `MasterCrudService`, `RecruitmentRuleService`, `UserProvisioningService`, `ActivityTimelineService` (+ M1 services relocated).
- **Integrations:** `DirectoryProvider` (+ `SeedDirectoryProvider`) added to the existing SmsSender/SignatureProvider contracts.
- **16 thin admin controllers**; Form Requests per master in the domain `Requests/` folders.

## Frontend changes
Bootstrap 5.3 admin area: left-nav `layouts/admin`, `status-badge` component, shared `lookup-index`/`lookup-form` partials, and per-master index/form views (incl. nested roster-points and repeatable recruitment-rule feeders + PDF upload). Registration form now uses district + designation dropdowns.

## APIs
Web (admin) resource routes under `/admin` (role:admin + auth + password-changed): districts, designations, pay-scales, reservation-categories, review-reasons, vacancy-types, dpc-types, cadres, reservation-policies, reservation-rosters (+ nested points), committee-types, committee-configurations, recruitment-rules (+ version/download), users (+ toggle-active/reset-password). No JSON API this milestone.

## Business rules implemented
Master lifecycle (Draft/Active/Inactive/Archived); effective-dated reservation policies & recruitment rules; roster scoped to department+cadre with configurable size; committee composition/voting configuration; **recruitment rules versioned & never overwritten** (draft-only edits; re-version to amend); config-driven reservation percentages. No statutory computation (deferred to M5).

## Validation rules
Per-master Form Requests: unique codes/names (self-ignored on update), `.gov.in` emails, 10-digit mobiles, enum status, percentages 0–100, `max_members ≥ min_members`, `quorum ≤ max_members`, roster point ≤ size & unique, GO PDF `mimetypes:application/pdf ≤10MB`, provisionable-role restriction.

## Files created / modified
~120 files created across `app/Domain`, `app/Integrations`, `app/Http/Controllers/Admin`, `resources/views/admin`, `database/migrations`, `database/seeders`, `tests/Feature`. Modified: M1 files (namespaces), `bootstrap/app.php`, `routes/web.php`, `config/dpc.php`, `config/auth.php`, `resources/views/auth/register.blade.php`, `database/seeders/DatabaseSeeder.php`, `database/factories/UserFactory.php`. (Full list in git history for the 8 commits above.)

## Composer packages added
None in M2 (spatie/laravel-permission and owen-it/laravel-auditing were added in M1).

## Tests executed
17 test files, **52 tests / 156 assertions**, all passing — covering master CRUD, validation, uniqueness, soft delete, audit/timeline, reservation hierarchy + roster points, committee configuration, versioned recruitment rules (feeders + PDF + never-overwrite), user provisioning, and RBAC/authorization.

## Quality gate results (final)
- Laravel Pint (PSR-12 + strict types): **passed**
- PHPStan / Larastan level 6: **0 errors (141 files)**
- `php artisan test` / `composer test`: **52 passed / 156 assertions**
- `php artisan migrate:fresh --seed`: **OK** (9 seeders)
- `php artisan optimize`: **OK**

## Remaining work
None for M2 scope. Consumption of these masters occurs in later milestones (Pre-DPC M3, Proposal M4, Eligibility Engine M5).

## Risks
- **District LGD codes** are null pending the live LGD sync (names authoritative) — see questions.
- **Seeded reservation percentages** are provisional editable defaults — confirm figures.
- Per-master **Policies** were not added (the `role:admin` middleware gates the whole admin area); finer-grained policies can be layered if required — see questions.

## Performance considerations
Redis cache/queue/sessions; indexed FKs and status columns; paginated admin lists; queued notifications; eager-loading on list screens (`with(...)`).

## Security considerations
RBAC (`role:admin`) on the entire admin area; CSRF on all forms; Form Request validation; owen-it audit on every master; Activity Timeline for workflow events; `.gov.in` email enforcement; GO PDFs on a private disk (S3 in prod); no stack traces in prod. Provisioned accounts require OTP verification + forced password change.

## Deferred items
Live LGD API provider; per-master authorization policies (if required); bulk import of masters; master consumption (M3–M5); scoring/threshold/zone masters (M5).
