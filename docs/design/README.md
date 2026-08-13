# ODPC Management System — Design Baseline (Laravel 13)

**Version:** 1.1 · **Baseline:** 2026-07-30 · **Last updated:** 2026-08-13
**Status:** Baseline approved and largely implemented. **M0–M5 are complete**; M4 is architecture-frozen and the M5 calculation engine is frozen. M6 design is submitted for approval.

Current project state lives in `docs/PROJECT_STATUS.md`; this folder is the design baseline those milestones were built against.

This folder is the authoritative design set for the Online Departmental Promotion Committee (ODPC) Management System, rebuilt from scratch on the Laravel 13 stack. All prior Node/React/Prisma planning is discarded and does not inform this design.

## Source of truth
1. `docs/ODPC_NEW_FINAL.pptx` (52 slides)
2. `docs/CONVENTIONAL DPC PROCESS.pptx` (7 slides)

Everything else (SRS, `Online DPC Module-.docx`, `technical-proposal.md`, `screen-specs.md`) is secondary. On any conflict the PPTs win. Nothing outside the PPTs is implemented without explicit approval. The raw extracted PPT text is preserved under `reference/` for auditability.

## Documents
| File | Purpose |
|---|---|
| `01-requirements.md` | Roles, 6-stage workflow + variants, screen & field inventory, business rules, DPC types, codes, masters, notifications, documents, out-of-scope |
| `02-traceability-matrix.md` | Every feature mapped to its PPT slide source |
| `03-database-design.md` | Entities, relationships, key columns, indexes, constraints, soft deletes, audit columns |
| `04-laravel-architecture-and-structure.md` | Layering (controllers/services/form requests/policies), config-driven rules, folder structure, security |
| `05-milestones.md` | Milestone-by-milestone plan with per-milestone deliverables and quality gate |
| `06-ambiguities.md` | Open questions requiring a decision before/within specific milestones. **Business questions have moved to `docs/business/PENDING_BUSINESS_DECISIONS.md`**; this file records what each ambiguity was and which milestone it affects. |
| `07-m6-cgpsc-workflow.md` | **M6 design — CGPSC allotment and scheduling (S26–S30). Submitted for approval, not implemented.** |

## Tech stack
PHP 8.4 · Laravel 13 · MySQL · Redis (cache/queue/sessions) · Blade + Bootstrap 5.3 · jQuery (only where needed) · Chart.js · AWS EC2 + S3 · Nginx · PHP-FPM · Ubuntu LTS. No Docker/Kubernetes. Architected for later horizontal scaling without app-architecture changes.

## Approval gate
Please review `06-ambiguities.md` first — several items (auth model, admin role, config-driven rules) shape the earliest milestones and need a ruling. Implementation of **Milestone 0** begins only on your approval.

## Documentation outside this folder

The design baseline is not the whole documentation set. Also authoritative:

| Location | Purpose |
|---|---|
| `docs/PROJECT_STATUS.md` | Current state — read this first when returning to the project. Includes the Milestone Closure Record. |
| `docs/PROJECT_PROGRESS.md` | Module-by-module detail of what is built |
| `docs/CHANGELOG.md` | History, in reverse chronological order |
| `docs/ARCHITECT_REVIEW.md` | Engineering review, findings, and questions awaiting architect ruling |
| `docs/business/PENDING_BUSINESS_DECISIONS.md` | Questions blocked on CGPSC or the department — **not** engineering decisions |
| `docs/deployment/EVIDENCE-TABLE-PERMISSIONS.md` | Production database-permission requirement for calculation evidence tables |
| `docs/integration/CSC-DigiSign.md`, `docs/integration/HRMS.md` | External integration contracts, failure behaviour, and enablement |
