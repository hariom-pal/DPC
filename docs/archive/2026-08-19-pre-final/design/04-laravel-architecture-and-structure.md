# 04 — Laravel Architecture & Folder Structure

## 1. Layering

- **Routes** (`routes/web.php`, split by area via `Route::group`/middleware) → **Controllers** (thin; only orchestrate: resolve request, call a service, return a view/redirect — no business logic) → **Services** (all business logic) → **Models/Eloquent** (persistence) + **Repositories** (only where a query is complex/reused enough to warrant it — no blanket abstraction).
- **Validation** exclusively in **Form Requests** (`app/Http/Requests`), one per action.
- **Authorization** via **Policies** (per model) + **Gates** (cross-cutting checks), enforced in controllers and Blade (`@can`).
- **Enums** (PHP 8 native) for `Role`, `DpcType`, `ProposalStatus`, `ClassLevel`, `Capacity`, `VacancyType`, `CrGrade`, `RemarkCode`, `ReviewReason`.
- **Events/Listeners** for side effects: `ProposalSubmitted`, `ProposalApproved`, `DpcAllotted`, `DpcScheduled`, `ResultApproved` → fire notifications + audit entries.
- **Jobs (Redis queue)**: `SendNotificationJob`, `ProcessExcelUploadJob`, `GenerateProceedingPdfJob`.
- **Config-driven rules**: `config/dpc.php` (+ master tables) hold scores, thresholds, zone params, circulation triggers, deadlines, committee cap — no magic numbers in code (Q3).

## 2. Core services (business logic homes)

| Service | Responsibility | Slides |
|---|---|---|
| `DepartmentRegistrationService` | register dept, create AO/AH, issue credentials | S5–S6 |
| `PreDpcPostService` | posts sanctioned entry + AH approval | S7–S11 |
| `DpcProposalService` | build/submit proposal aggregate; DPC ID gen | S12–S18 |
| `ProposalApprovalService` | AH approvals + forward to CGPSC | S19–S25 |
| `ExcelImportService` | parse/validate the 3 upload templates | S16–S18 |
| `ScoringService` | CR marks, tribal bonus, per-officer total | C4, S33 |
| `EligibilityService` | घ / last-year-ग gates, qualifying thresholds | C4–C5 |
| `ZoneOfConsiderationService` | zone size, reserved 7× expansion | C6, S17 |
| `SuperSeatService` | notional seniority, tie-break | C5 |
| `DpcCalculationService` | orchestrates the above → MARK, REMARK, main/waiting | S33–S34 |
| `AllotmentService` | Chairman allotment + report | S26–S28 |
| `SchedulingService` | accept, schedule, reschedule, re-allot | S29–S30 |
| `ProceedingService` | conduct, approve result, cancel | S31–S34 |
| `ReviewDpcService` / `CirculationDpcService` | variant flows | S35–S48 |
| `SelfInspectionService` | own-data view, objection (late auto-file) | S49–S51 |
| `NotificationService` | email+SMS dispatch + delivery log | S5, S26 |
| `DocumentService` | S3 put/get, signed URLs, metadata | uploads/outputs |

## 3. Folder structure (proposed)

```
app/
  Enums/                      Role, DpcType, ProposalStatus, ClassLevel, Capacity, CrGrade, RemarkCode, ...
  Models/                     Department, User, PromotionPost, DpcProposal, CommitteeMember,
                              Vacancy, WorkingOfficer, SeniorityEntry, EvaluationRow, DpcResult,
                              CirculationHold, Objection, Document, ...
  Http/
    Controllers/
      Auth/                   login, otp, registration
      Department/             RegistrationController, PreDpcPostController
      Proposal/               ProposalController, CommitteeController, WorkingOfficerController,
                              SeniorityController, EvaluationController, UploadController, ApprovalController
      Cgpsc/                  AllotmentController, SchedulingController, ProceedingController,
                              ReviewController, CirculationController
      SelfService/            SelfInspectionController, ObjectionController
      Admin/                  MasterDataController, UserProvisioningController   (pending Q2)
      Reports/                DashboardController                                (pending Q, M11)
    Requests/                 one Form Request per action
    Middleware/               EnsureRole, EnsureLevel, RequireFreshOtpSignature
  Services/                   (as table §2)
  Repositories/               ProposalRepository (only where justified)
  Policies/                   DpcProposalPolicy, PreDpcPostPolicy, ...
  Events/  Listeners/  Jobs/  Notifications/
config/  dpc.php (rule config), filesystems.php (s3), queue.php (redis), cache.php (redis)
database/ migrations/  seeders/ (masters)  factories/
resources/
  views/
    layouts/  (app layout, Bootstrap 5.3, Hindi/English)
    components/  (tables, form rows, status badges, stepper)
    auth/  department/  proposal/  cgpsc/  self-service/  admin/  reports/
tests/
  Feature/  (per module, per role)   Unit/  (ScoringService, ZoneOfConsiderationService, EligibilityService)
routes/  web.php  console.php
```

## 4. Frontend (Blade + Bootstrap 5.3)

Server-rendered Blade matching each PPT screen; Bootstrap 5.3 for grids/forms/tables/cards; Blade components for the repeated tables and the workflow stepper; jQuery only for dynamic table rows / live tabulation totals; Chart.js for M11 dashboards. Visuals modernized only — field names, buttons, navigation, workflow, and calculations stay exactly as the PPT.

## 5. Security

CSRF on all forms; validation in Form Requests; output escaping (Blade `{{ }}`) for XSS; Eloquent/param binding for SQL-injection safety; bcrypt/argon hashing; RBAC via Policies/Gates + role middleware; rate limiting on auth/OTP endpoints; S3 private with signed URLs; `APP_DEBUG=false` in prod (no stack traces); append-only audit log + sign events; least-privilege DB user in prod.

## 6. Scalability posture (V1 stays simple)

Sessions/cache/queue in Redis (shared-ready); documents on S3 (shared-ready); stateless app nodes so an ASG + load balancer can be added later; queue workers + scheduler (`php artisan schedule`) for notifications, uploads, PDFs. No premature clustering in V1.

## 7. Quality gate (every milestone)

`composer test` · `php artisan test` · `php artisan migrate` · `php artisan optimize` · **Laravel Pint** (PSR-12) · **PHPStan/Larastan**. All must pass before the next milestone.
