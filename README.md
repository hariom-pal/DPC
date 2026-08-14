# ODPC Portal — Online Departmental Promotion Committee Management System

A Laravel application for the Chhattisgarh Public Service Commission (CGPSC) that digitizes the Departmental Promotion Committee (DPC) process end to end: department registration → Pre-DPC proposal → detailed DPC proposal → CGPSC allotment → scheduling → online DPC proceeding → result (main + waiting list) → approval, plus Review DPC, Circulation DPC, and Self-Inspection.

**This is a government system.** Its outputs (promotion orders) can be legally contested. Correctness, auditability, and security take priority over speed of delivery — see `docs/ARCHITECT_REVIEW.md` for the standing engineering doctrine.

## Source of truth

Business requirements come **only** from:

1. `docs/ODPC_NEW_FINAL.pptx` (52 slides)
2. `docs/CONVENTIONAL DPC PROCESS.pptx` (7 slides — scoring/eligibility rules)

Everything under `docs/design/` is derived from these two decks; on any conflict, the PPTs win. `docs/archive/` holds superseded pre-reset planning documents, kept for historical traceability only — do not implement from them.

## Where to start

| I want to... | Read |
|---|---|
| Know current project status at a glance | `docs/PROJECT_STATUS.md` |
| See what's built vs. what the PPT requires, module by module | `docs/PROJECT_PROGRESS.md` |
| See the current handover, decisions and review findings | `docs/ARCHITECT_REVIEW.md` |
| See known limitations, deferred items and resolved defects | `docs/TECHNICAL_DEBT.md` |
| Understand why the architecture is the way it is | `docs/ARCHITECTURE_DECISIONS.md` |
| See what each milestone delivered | `docs/MILESTONE_SUMMARY.md` |
| See when decisions were taken, and why | `docs/PROJECT_TIMELINE.md` |
| Understand a workflow, its states and who may act | `docs/WORKFLOWS.md` |
| Consume a workflow event, or build an integration against one | `docs/EVENT_CATALOG.md` |
| Know which external systems connect, and what is switched on | `docs/INTEGRATION_ROADMAP.md` |
| Run user acceptance testing | `docs/UAT_CHECKLIST.md` |
| Demonstrate the system to the customer | `docs/DEMO_SCRIPT.md` |
| Understand the requirements/design baseline | `docs/design/01-requirements.md` … `06-ambiguities.md` |
| See what changed release over release | `docs/CHANGELOG.md` |
| Know which business rules are still unsettled | `docs/PENDING_BUSINESS_DECISIONS.md` |
| Put those questions to CGPSC in plain language | `docs/UAT_BUSINESS_VALIDATION.md` |

These sixteen documents — this README plus the fifteen under `docs/` above — are **mandatory and kept synchronized**. Anything else under `docs/` is supporting detail.

**Three registers, deliberately separate** (architect directive): `PENDING_BUSINESS_DECISIONS.md` holds questions only CGPSC can answer, `TECHNICAL_DEBT.md` holds what we know is imperfect, and `ARCHITECTURE_DECISIONS.md` holds the rulings that constrain future work. Each survives a phase boundary; `ARCHITECT_REVIEW.md` does not — it is replaced in full each phase.

## Milestone state

M0–M6 are complete. **M4 (DPC Proposal), M5 (Scoring & Eligibility Engine) and M6 (CGPSC Workflow) are frozen** — bug fixes, security fixes and hardening only; no new features, workflow changes or schema changes. M5's freeze covers only *approved* rules, so the unresolved business decisions can still be settled through configuration.

**M7 (DPC Proceedings & Result Generation) is complete**, closed 2026-08-14 at tag `v0.7.0-m7-complete`. Four business questions remain open and are tracked only in `docs/PENDING_BUSINESS_DECISIONS.md`; M7 is not reopened for them. **M8 (Review DPC) is at design stage** — nothing implemented.

Current status and the exact freeze terms: `docs/PROJECT_STATUS.md`.

## Stack

PHP 8.4 · Laravel 13 · MySQL · Redis (cache, queue, sessions) · Blade + Bootstrap 5.3 (vendored, no Node/Vite build step) · AWS EC2 + S3 in production. Architecture: thin controllers → Domain Services (`app/Domain/*`) → Eloquent models, config-driven business rules (`config/dpc.php` + master-data tables — never hardcoded), Provider abstractions for every external integration (`app/Integrations/*`).

## Local setup

```bash
composer setup      # composer install, .env, app key, migrate
php artisan db:seed # seed roles, masters, admin account
php artisan serve
```

Requires a running MySQL (`dpc_dev` by default — see `.env.example`) and Redis instance; both are load-bearing (sessions, cache, and queue all run on Redis).

## Quality gate

Every milestone must pass this before being considered complete — see `docs/ARCHITECT_REVIEW.md` for the full production-readiness bar (security/VAPT/performance review, not just these checks):

```bash
composer gate   # Pint --test · PHPStan (Larastan level 6) · composer audit · config:clear · php artisan test
```

Individually: `composer pint` (auto-fix), `composer pint:test` (check only), `composer stan` (PHPStan), `composer audit:security`, `composer test` (PHPUnit). `php artisan migrate:fresh --seed` must also succeed before a milestone closes.

**CI note:** `composer audit` reaches packagist over the network and has been observed to fail with `curl error 28` on an otherwise clean tree. Retry that step in CI, or a build will fail in a way that looks like a security gate failing (`TD-2`).

## Contributing

Follow the architecture and conventions in `docs/design/04-laravel-architecture-and-structure.md`: no business logic in controllers or Blade, all validation in Form Requests, all business logic in Domain Services, external systems behind a Provider contract, no hardcoded business values. Do not add screens or workflow not present in the two source PPTs without an explicit, recorded architect decision (`docs/ARCHITECT_REVIEW.md`).
