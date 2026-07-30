# ODPC Management System — Design Baseline (Laravel 12)

**Version:** 1.0 (fresh baseline, 2026-07-30) · **Status:** Awaiting approval — no application code yet.

This folder is the authoritative design set for the Online Departmental Promotion Committee (ODPC) Management System, rebuilt from scratch on the Laravel 12 stack. All prior Node/React/Prisma planning is discarded and does not inform this design.

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
| `06-ambiguities.md` | Open questions requiring your decision before/within specific milestones |

## Tech stack
PHP 8.4 · Laravel 12 · MySQL · Redis (cache/queue/sessions) · Blade + Bootstrap 5.3 · jQuery (only where needed) · Chart.js · AWS EC2 + S3 · Nginx · PHP-FPM · Ubuntu LTS. No Docker/Kubernetes. Architected for later horizontal scaling without app-architecture changes.

## Approval gate
Please review `06-ambiguities.md` first — several items (auth model, admin role, config-driven rules) shape the earliest milestones and need a ruling. Implementation of **Milestone 0** begins only on your approval.
