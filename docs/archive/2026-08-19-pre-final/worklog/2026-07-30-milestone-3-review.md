# Milestone 3 — Review: Pre-DPC Proposal Workflow

**Date:** 2026-07-30 · **Branch:** `feature/m0-setup` · **Stack:** Laravel 13 / PHP 8.4 / MySQL / Redis / Blade + Bootstrap 5.3

## Objective
Deliver the first **workflow** milestone — the Pre-DPC Proposal (PPT S7–S11): the department Authorized Officer (AO) authors the posts sanctioned for promotion with recruitment-rule detail; the Administrative Head (AH) approves (with a digital signature) or returns. Introduce a reusable, configuration-driven workflow engine, and — on approval — project into the Cadre + Recruitment Rule masters as the single source of truth. All per architect decisions Q1 (single source of truth), Q2 (mandatory signature), Q3 (immutable approved versions, versioned amendments) and the versioning directives.

## Work completed
- **Config-driven workflow engine** (`app/Domain/Shared/Workflow`): `WorkflowEngine` (legality + role from `config/dpc.php → workflows.pre_dpc`), `WorkflowTransition` append-only ledger, `WorkflowException`, `WorkflowTransitioned` event + queued `SendWorkflowNotification` listener.
- **Pre-DPC aggregate** with the mandated versioning columns (version, parent_version_id, is_current, approved_at, approved_by, signature_id) + timeline + audit.
- **State machine** DRAFT→SUBMITTED→APPROVED with RETURNED loop and CANCELLED; 6 transitions with role + ownership + business guards.
- **Signature-gated approval** via `SignatureService` (Mock in non-prod; production refuses to approve unless CSC DigiSign is configured — approval fails rather than bypass signing).
- **Projection to masters:** on approval, posts create/update department `cadres` and each creates a **new `recruitment_rules` version**; feeders map to recruitment-rule feeders (resolved by cadre).
- **Versioned amend:** approved proposals are immutable; "Amend" clones to a fresh DRAFT version (parent linked).
- AO/AH Blade screens (list, form with repeatable posts + nested feeders, show with role-aware actions + workflow history), dashboard entry.

## Database changes
4 new tables (38 total): `workflow_transitions` (generic append-only ledger), `pre_dpc_proposals` (versioned; audit + soft deletes), `pre_dpc_posts`, `pre_dpc_post_feeders`. FKs, indexes, unique `(department_id, version)`.

## Backend changes
- New domain `app/Domain/PreDpc` (Enums, Models, Requests, Services) + `app/Domain/Shared/Workflow`, `app/Domain/Shared/Events`, `app/Domain/Shared/Listeners`.
- `PreDpcProposalService` (draft save, submit/approve/return/cancel/amend, projection to masters); reuses `RecruitmentRuleService` + `SignatureService`.
- `PreDpcProposalController` (thin) in `app/Http/Controllers/PreDpc`.
- `WorkflowTransitioned` listener registered in `AppServiceProvider`.

## Frontend changes
`resources/views/pre-dpc/{index,form,show}.blade.php` (Bootstrap 5.3, app layout); repeatable posts + nested feeder rows (vanilla JS); role-aware workflow buttons; workflow-history panel; dashboard link for AO/AH.

## APIs
11 web routes under `/pre-dpc` (auth + password-changed + role `authorized_officer|administrative_head`): index, create, store, show, edit, update, submit, approve, return, cancel, amend. No JSON API.

## Business rules implemented
State machine + legal transitions; AO-only editing in DRAFT/RETURNED; AH approval requires signature; approved = immutable, amend = new version; approval projects to Cadre + Recruitment Rule masters (new RR version each approval); one current version per department (`is_current`).

## Validation rules
Save: posts array; each post name/class/percentages (0–100); feeders name/%/experience. Submit guard: ≥1 post + GO number + effective date. Return: remarks required. Role + department-ownership enforced on every transition; illegal transitions rejected.

## Files created / modified
Created ~22 files (migrations 2, enum, 3 Pre-DPC models, workflow engine/ledger/exception/event/listener, service, 2 requests, controller, 3 views, test). Modified: `config/dpc.php` (workflows), `routes/web.php`, `app/Providers/AppServiceProvider.php`, `resources/views/dashboard.blade.php`.

## Composer packages added
None.

## Tests executed
`tests/Feature/PreDpc/PreDpcWorkflowTest` (7): create+submit, AH approval signs + projects to masters, AO-cannot-approve, illegal transition rejected, return-with-remarks, amend new version, submit-requires-GO. Total suite: **59 tests / 185 assertions**, all passing.

## Quality gate results
- Pint: **passed** · PHPStan L6: **0 errors (155 files)** · `php artisan test`: **59 passed / 185 assertions** · `migrate:fresh --seed`: **OK** · `optimize`: **OK**.

## Remaining work
None for M3 scope. The DPC Proposal (M4) consumes the approved Pre-DPC / masters.

## Risks
- **Feeder-cadre resolution:** on approval, recruitment-rule feeders are created only for feeders resolvable to a department cadre (by name/id); unresolved feeders remain fully recorded on the Pre-DPC (source of truth) but are not projected. See questions.
- **GO number + effective date** are captured at Pre-DPC proposal level (not explicit in S9) to produce valid recruitment rules. See questions.
- Production approval hard-fails without CSC DigiSign (by design, Q2).

## Performance considerations
Redis cache/queue/sessions; queued notifications; transitions wrapped in DB transactions; eager loading on show; indexed workflow ledger.

## Security considerations
Role + department-ownership on every action; signature step-up + immutable approved versions; append-only `workflow_transitions` ledger + owen-it audit + Activity Timeline; CSRF; no stack traces in prod.

## Deferred items
Consumption of approved Pre-DPC in M4; richer workflow visualisation; live CSC DigiSign; branching/parallel workflow states (not needed for Pre-DPC).
