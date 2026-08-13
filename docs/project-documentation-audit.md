# Project Documentation Audit

**Date:** 2026-08-13
**Scope:** Every Markdown document in the repository, classified as **KEEP**, **ARCHIVE**, or **DELETE**.
**Source of truth for this audit:** `docs/ODPC_NEW_FINAL.pptx` and `docs/CONVENTIONAL DPC PROCESS.pptx` remain authoritative for business content; `git log` is authoritative for what actually happened to the documentation set over time.

**No files have been moved or deleted.** This report is for your approval per Step 2/5 of the audit request.

---

## 0. Important finding — three milestone worklogs are missing from the working tree

Before the classification below, one thing needs flagging: this project's own convention (established 2026-07-30) requires a `-review.md` and `-questions.md` file per milestone, kept permanently under `docs/worklog/`. Checking git history against the current working tree shows **7 of these files were deleted** as an uncommented side effect of the M3 commit (`3133ac4`, "M3: Pre-DPC Proposal workflow"):

| File | Milestone | Deleted in |
|---|---|---|
| `docs/worklog/2026-07-30-milestone-0-foundation.md` | M0 | `3133ac4` |
| `docs/worklog/2026-07-30-laravel-baseline-design.md` | M0 | `3133ac4` |
| `docs/worklog/2026-07-30-milestone-1-review.md` | M1 | `3133ac4` |
| `docs/worklog/2026-07-30-milestone-1-questions.md` | M1 | `3133ac4` |
| `docs/worklog/2026-07-30-milestone-2-design.md` | M2 | `3133ac4` |
| `docs/worklog/2026-07-30-milestone-2-questions.md` | M2 | `3133ac4` |
| `docs/worklog/2026-07-30-milestone-2-review.md` | M2 | `3133ac4` |

The commit message doesn't mention documentation cleanup, so this looks unintentional. Content is not lost — every file is fully recoverable from git history (verified by reading each blob) — but right now the working tree understates the project's own audit trail, which matters for a government/legal-grade system.

**Recommendation:** restore all 7 files from git history into `docs/archive/worklog/` (old milestones — archived, not active docs) as part of this cleanup, rather than leaving them stranded in history. This is listed as a proposed action below; nothing is restored yet pending your approval.

---

## 1. Files to KEEP

Design baseline, current-milestone worklog, and reference material — all still actively used as the working design/status record.

| File | Why KEEP |
|---|---|
| `docs/design/README.md` | Index/entry point for the design set; states the source-of-truth rule. Still accurate. |
| `docs/design/01-requirements.md` | Full requirements extracted from both PPTs (roles, 6 stages, 35-screen inventory, field inventory, business rules, DPC types, status model). This is the primary requirements reference used to write `PROJECT_PROGRESS.md` below — actively load-bearing. |
| `docs/design/02-traceability-matrix.md` | Maps every feature to its PPT slide and milestone. Actively used to verify implementation coverage. |
| `docs/design/03-database-design.md` | Entity model reference; still the plan being implemented against. |
| `docs/design/04-laravel-architecture-and-structure.md` | Layering/folder-structure decisions the codebase currently follows (thin controllers, Services, Form Requests, config-driven rules). |
| `docs/design/05-milestones.md` | The authoritative milestone plan (M0–M11) with dependencies and blockers. Still the roadmap in use. |
| `docs/design/06-ambiguities.md` | Live decision log — ruled items (Q1–Q3, Q13) and still-open items (Q4–Q12, Q14–Q16) per milestone. Actively consulted before each milestone. |
| `docs/design/reference/odpc_new_final.extracted.txt` | Raw extracted text of all 52 slides of the primary source PPT. Not Markdown, but the underlying evidence for every requirement — required for auditability. |
| `docs/design/reference/conventional_dpc.extracted.txt` | Raw extracted text of the 7-slide scoring-rules deck. Same reasoning. |
| `docs/worklog/2026-07-30-milestone-3-design.md` | Design doc for the current (most recent) milestone — the Pre-DPC workflow engine. Still the reference for how the workflow engine works. |
| `docs/worklog/2026-07-30-milestone-3-review.md` | Review/closeout for the most recently completed milestone (M3). Current, accurate, actively referenced (used directly to build `PROJECT_PROGRESS.md`). |
| `docs/worklog/2026-07-30-milestone-3-questions.md` | Architect decision record for M3, including the closeout section showing all three decisions + two closeout questions as resolved and implemented. Auditable decision trail. |
| `README.md` (root) | Project entry point. **Note:** this is still the stock Laravel framework README, not yet customized for the ODPC project — not a cleanup problem, but worth a follow-up task to replace it with project-specific setup/run instructions. Not reclassified here since it's not generated clutter. |

---

## 2. Files to ARCHIVE → `docs/archive/`

Superseded by the post-reset design set (`docs/design/`), but preserved for historical traceability — they explain what CGPSC's original ask looked like before the 2026-07-30 reset narrowed scope strictly to the two PPTs (e.g. why video conferencing / live voting / HRMS import were dropped — they appear only in these documents, not in the PPTs).

| File | Proposed destination | Why ARCHIVE (not DELETE) |
|---|---|---|
| `docs/technical-proposal.md` | `docs/archive/technical-proposal.md` | Pre-reset (dated 2026-07-29) technical proposal. Describes features explicitly ruled **out of scope** post-reset (video conferencing, real-time multi-member voting, HRMS import — see `01-requirements.md` §11). Superseded by `docs/design/04-laravel-architecture-and-structure.md`. Kept for the "why was X excluded" question a reviewer will eventually ask. |
| `docs/screen-specs.md` | `docs/archive/screen-specs.md` | Pre-reset (dated 2026-07-29) Figma handoff spec. Self-describes two synthesized (non-PPT) elements — a charted dashboard and a multi-member live-vote panel — that are **not** in the current design. Superseded by `docs/design/01-requirements.md` §3 (Screen Inventory, which is PPT-verbatim). Kept as the historical UI-spec draft. |
| `docs/worklog/2026-07-30-ppt-analysis-and-plan.md` | `docs/archive/worklog/2026-07-30-ppt-analysis-and-plan.md` | The very first PPT read-through, written *before* the Laravel reset (it still refers to scaffolding `dpc-api`/`dpc-web`, the old Node.js layout). Fully superseded by `docs/design/01-requirements.md` and `02-traceability-matrix.md`, which are the same analysis done properly post-reset. Kept because it documents the original discovery of the Aadhaar-vs-email/OTP conflict, which shaped decision Q1. |
| *(recovered)* `docs/worklog/2026-07-30-milestone-0-foundation.md` | `docs/archive/worklog/` | See §0 — accidentally deleted; recoverable from commit `8951c30`/`649a431`. Historical milestone record, no longer the current milestone. |
| *(recovered)* `docs/worklog/2026-07-30-laravel-baseline-design.md` | `docs/archive/worklog/` | See §0 — accidentally deleted; recoverable from commit `8951c30`. |
| *(recovered)* `docs/worklog/2026-07-30-milestone-1-review.md` | `docs/archive/worklog/` | See §0 — accidentally deleted; recoverable from commit `d0dbe90`. |
| *(recovered)* `docs/worklog/2026-07-30-milestone-1-questions.md` | `docs/archive/worklog/` | See §0 — accidentally deleted; recoverable from commit `d0dbe90`. |
| *(recovered)* `docs/worklog/2026-07-30-milestone-2-design.md` | `docs/archive/worklog/` | See §0 — accidentally deleted; recoverable from commit `53146e5`. |
| *(recovered)* `docs/worklog/2026-07-30-milestone-2-questions.md` | `docs/archive/worklog/` | See §0 — accidentally deleted; recoverable from commit `53146e5`. |
| *(recovered)* `docs/worklog/2026-07-30-milestone-2-review.md` | `docs/archive/worklog/` | See §0 — accidentally deleted; recoverable from commit `8ad5c8b`. |

**Not classified (out of Markdown scope, flagged for awareness only):** `docs/DPC- SRS.docx` and `docs/Online DPC Module-.docx` are pre-reset Word documents, explicitly named as SECONDARY sources in `docs/design/README.md`. They aren't Markdown so fall outside this audit's stated scope, but they're the same vintage as the two files above — if you want them archived too, say so and I'll include them in the same move.

---

## 3. Files to DELETE

**None of the current Markdown files qualify for outright deletion.** Everything left in the working tree either is actively used (§1) or has enough historical/legal traceability value to archive rather than destroy (§2) — this is a government system where past decisions need to stay reconstructable.

Two **non-Markdown, empty leftover directories** were found and are flagged for deletion since they hold no content and predate the current `docs/design/` structure:

| Path | Why DELETE |
|---|---|
| `docs/api/` | Empty (0 files), untracked by git (git never saw any content in it). Leftover from before `docs/design/` was adopted as the documentation structure on 2026-07-30. Nothing references it. |
| `docs/architecture/` | Same — empty, untracked, superseded by `docs/design/04-laravel-architecture-and-structure.md`. |
| `docs/requirements/` | Same — empty, untracked, superseded by `docs/design/01-requirements.md`. |

---

## 4. Summary of proposed actions (pending your approval)

1. **Restore** 7 milestone worklog files from git history (commits `8951c30`, `649a431`, `d0dbe90`, `53146e5`, `8ad5c8b`) directly into `docs/archive/worklog/`.
2. **Move** `docs/technical-proposal.md`, `docs/screen-specs.md`, and `docs/worklog/2026-07-30-ppt-analysis-and-plan.md` into `docs/archive/` (preserving the `worklog/` subfolder for the latter).
3. **Delete** the three empty, untracked directories `docs/api/`, `docs/architecture/`, `docs/requirements/`.
4. Leave `docs/design/*`, the M3 worklog trio, and root `README.md` exactly where they are.

Nothing above has been executed. Reply with approval (or corrections) and I'll carry it out.
