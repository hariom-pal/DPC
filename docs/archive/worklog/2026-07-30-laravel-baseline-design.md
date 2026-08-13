# Worklog — 2026-07-30 — Laravel 12 Baseline: Fresh Analysis & Design

## Task
Project reset. Rebuild the entire analysis and design from scratch on the Laravel 12 stack, PPTs as source of truth. No application code until approval.

## What was done
- Discarded all prior Node/React/Prisma planning. Removed the Node-era docs (`docs/ppt-analysis-and-plan.md`, `docs/decisions-and-open-questions.md`).
- Confirmed toolchain: PHP 8.4.23, Composer 2.7.9, MySQL 8 (root/root), all required PHP extensions. Created MySQL db `dpc_dev`.
- Re-derived the full design from the two PPTs and wrote a reviewable set under `docs/design/`:
  - `README.md` — index + source-of-truth + stack
  - `01-requirements.md` — roles, 6-stage workflow + variants, 35-screen inventory, field inventory, scoring/eligibility rules, DPC types & codes, masters, notifications, documents, out-of-scope
  - `02-traceability-matrix.md` — 36 features → PPT slides → milestones
  - `03-database-design.md` — entities, relationships, indexes, constraints, soft deletes, audit columns (documents on S3, metadata in DB)
  - `04-laravel-architecture-and-structure.md` — thin controllers / services / form requests / policies, config-driven rules, folder tree, security, scalability posture
  - `05-milestones.md` — M0–M11, each with full deliverable set + quality gate
  - `06-ambiguities.md` — 16 open questions needing rulings (Q1/Q2/Q3 block earliest milestones)
  - `reference/` — raw extracted PPT text preserved for auditability
- Updated project memory to the Laravel baseline; re-flagged prior "decisions" as open ambiguities pending re-approval.

## Key facts carried from the PPTs (unchanged, source of truth)
- Roles: AO (L1), AH (L2), Chairman, Member, Individual.
- CR marks क+=4…घ=0; tribal +1; eligibility (no घ; I→I last ≠ ग); qualifying I→I≥15/II→I≥13/II→II≥11/others≥10; super-seat (all-क+); zone (2×vac+4 / top-5 / 7× reserved).
- DPC types: Fresh / Review (orig DPC ID + coded reason) / Circulation (held for missing CR/property).

## Decision needed (blocks M0/M1/M2)
Rulings on `06-ambiguities.md`, especially: **Q1** auth/e-sign (Aadhaar vs email/mobile+password+OTP), **Q2** add CGPSC Admin role, **Q3** config-driven rules. Recommended answers are listed at the bottom of that doc.

## Next action
Await approval of the new architecture + answers to Q1/Q2/Q3 (ideally Q13/Q14/Q15 too). On approval → begin **Milestone 0 (Laravel 12 project setup + quality gate)**.
