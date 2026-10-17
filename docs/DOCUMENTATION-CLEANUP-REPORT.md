# Documentation Cleanup Report

**Date:** 2026-10-17 · **Branch:** `feature/uat-cleanup-and-admin-audit` (from release tag `uat-2026-10-15-r1`)

**Scope:** every tracked `*.md`, `*.doc(x)`, `*.txt`, `*.html`, `*.pptx`, `*.pdf` in the repository root and `docs/`.
- **127 files audited.**
- **36 deleted** with `git rm`. They remain recoverable from history, e.g. `git show uat-2026-10-15-r1:<path>`.
- **91 preserved.**

**Method.** For every file:
1. its first lines were read;
2. the repository was searched for its file name — in code (`app/`, `tests/`, `database/`, `config/`, `routes/`) and in other documents;
3. it was checked whether a newer document carries the same content.

A file named by code or tests was never deleted. A file whose value could not be settled is **preserved** and listed under §3.

**"Coder Introduction."** No document with that name, or a spelling variant ("coder intro", "codar", "koder"), exists in the repository, `~/Downloads`, `~/Documents` or `~/Desktop`. The equivalent authoritative source is `~/Downloads/Understanding of DPC with Coder Instructions.pdf` (6 pages, 2026-09-25). `docs/business/DOCUMENT_INVENTORY.md` already adopts it as the Master Business Document. It is outside the repository and was not touched. Every repository document that analyses it is preserved:
- `docs/business/CURRENT_SYSTEM_VS_LATEST_DPC_BUSINESS_DOCUMENT.md`
- `docs/CR_UPLOAD_AND_NUMBER_SYSTEM_REVIEW.md`
- `docs/business/DOCUMENT_INVENTORY.md`

---

## 1. Deleted (36)

| # | File | Class | Reason |
|---|---|---|---|
| 1 | `PPT_COMPLIANCE_MATRIX.md` | OBSOLETE | 2026-08-22 gap audit against the PPT; superseded by `docs/business/CURRENT_SYSTEM_VS_LATEST_DPC_BUSINESS_DOCUMENT.md` (latest business document) |
| 2 | `PPT_DEMO_RISKS.md` | OBSOLETE | Same audit, demo-risk view; the demo it prepared for is past |
| 3 | `PPT_IMPLEMENTATION_DIFFERENCES.md` | OBSOLETE | Same audit; superseded as in #1 |
| 4 | `PPT_MISSING_FEATURES.md` | OBSOLETE | Same audit; superseded as in #1 |
| 5 | `docs/business/DOCUMENT_INVENTORY.html` | DUPLICATE | Styled copy of `DOCUMENT_INVENTORY.md` (kept) |
| 6 | `docs/demo/DEMO_VERIFICATION_REPORT.md` | OBSOLETE | Declares itself "Superseded on 2026-09-07" |
| 7 | `docs/demo/DEMO_DEPARTMENT_READINESS.md` | TEMPORARY | One-day status report of the dev database (2026-10-05) |
| 8 | `docs/uat/DEMO_READINESS_REPORT.md` | TEMPORARY | Go/no-go report for the 2026-09-02 demonstration |
| 9 | `docs/uat/TERMINAL_TRANSCRIPT.md` | TEMPORARY | Verbatim terminal transcript (2,105 lines); its findings are carried in `TERMINAL_SUGGESTIONS.md`, `BUSINESS_UAT_OBSERVATIONS.md` and the change registers |
| 10 | `docs/archive/2026-09-05-superseded/DEMO_CHEAT_SHEET.md` | OBSOLETE | Archived in a folder named "superseded"; replaced by `docs/demo-kit/README.md` |
| 11 | `docs/archive/2026-09-05-superseded/DEMO_FLOW.md` | OBSOLETE | As #10; replaced by `docs/demo/CURRENT_DEMO_FLOW.md` |
| 12 | `docs/archive/2026-09-05-superseded/README.md` | OBSOLETE | Index of the superseded folder |
| 13 | `docs/archive/2026-09-05-superseded/UPLOAD_FILES_GUIDE.md` | OBSOLETE | As #10; replaced by `docs/demo/SAMPLE_DATA_GUIDE.md` |
| 14 | `docs/archive/2026-08-19-pre-final/PROJECT_STATUS.md` | OBSOLETE | 2026-08-19 status snapshot |
| 15 | `docs/archive/2026-08-19-pre-final/PROJECT_PROGRESS.md` | OBSOLETE | 2026-08-14 progress report |
| 16 | `docs/archive/2026-08-19-pre-final/UAT_CHECKLIST.md` | OBSOLETE | Superseded by `docs/UAT-MANUAL-TESTING-CHECKLIST.md` |
| 17 | `docs/archive/2026-08-19-pre-final/UAT_BUSINESS_VALIDATION.md` | OBSOLETE | Superseded by `docs/UAT-MANUAL-TESTING-CHECKLIST.md` and the change registers |
| 18 | `docs/archive/2026-08-19-pre-final/design/reference/conventional_dpc.extracted.txt` | DUPLICATE | Text extracted from `docs/CONVENTIONAL DPC PROCESS.pptx` (kept) |
| 19 | `docs/archive/2026-08-19-pre-final/design/reference/odpc_new_final.extracted.txt` | DUPLICATE | Text extracted from `docs/ODPC_NEW_FINAL.pptx` (kept) |
| 20 | `docs/archive/2026-08-19-pre-final/worklog/2026-08-18-pre-m10-verification-sweep.md` | TEMPORARY | One-time verification sweep |
| 21 | `docs/archive/2026-08-19-pre-final/worklog/2026-08-17-single-department-demo-journey.md` | TEMPORARY | One-time demo preparation note |
| 22 | `docs/worklog/2026-08-28-full-implementation-audit.md` | DUPLICATE | Says its deliverable is `docs/ARCHITECT_REVIEW.md` (kept) |
| 23 | `docs/worklog/2026-09-01-demo-readiness-verification.md` | TEMPORARY | Summary of #8 |
| 24 | `docs/worklog/2026-09-02-demo-fresh-reset.md` | TEMPORARY | Record of one dev-database reset |
| 25 | `docs/worklog/2026-09-02-uat-transactional-data-cleanup.md` | TEMPORARY | Record of one dev-database reset |
| 26 | `docs/worklog/2026-09-05-demo-cleanup-and-documentation.md` | TEMPORARY | Record of one dev-database reset and demo documents |
| 27 | `docs/worklog/2026-09-05-final-business-change-before-freeze.md` | DUPLICATE | States "Full detail in `docs/CHANGE_REGISTER_2026-09-05_REPORT.md`" (kept) |
| 28 | `docs/worklog/2026-09-06-final-business-alignment.md` | DUPLICATE | States "Full detail in `docs/CHANGE_REGISTER_2026-09-06_REPORT.md`" (kept) |
| 29 | `docs/worklog/2026-09-07-demo-docs-and-sample-data.md` | TEMPORARY | Demo-data regeneration note |
| 30 | `docs/worklog/2026-09-07-demo-seeder-repair.md` | TEMPORARY | One-time demo seeder repair note |
| 31 | `docs/worklog/2026-09-07-health-check.md` | TEMPORARY | Point-in-time gate report |
| 32 | `docs/worklog/2026-10-05-transactional-data-reset.md` | TEMPORARY | Record of one dev-database reset |
| 33 | `docs/worklog/2026-10-06-demo-kit-readme-rewrite.md` | TEMPORARY | Note on rewriting `docs/demo-kit/README.md` (kept) |
| 34 | `docs/worklog/2026-10-06-demo-preflight.md` | TEMPORARY | Demo-day pre-flight note |
| 35 | `docs/worklog/2026-10-08-downloads-document-inventory.md` | DUPLICATE | Summary of `docs/business/DOCUMENT_INVENTORY.md` (kept) |
| 36 | `docs/worklog/2026-10-13-cr-number-system-and-structure-gap.md` | DUPLICATE | Says "poora review … `docs/CR_UPLOAD_AND_NUMBER_SYSTEM_REVIEW.md`" (kept) |

None of them is named by code or tests. Links to them from preserved documents (mostly archived ones) now point at removed files. That is acceptable for an archive, and the files remain in Git history.

---

## 2. Preserved (91)

### REQUIRED — setup, deployment, security, business rules, code references

| File(s) | Why |
|---|---|
| `README.md` | Setup |
| `docs/UAT-SERVER-INSTALLATION.md`, `UAT-APPLICATION-DEPLOYMENT.md`, `UAT-ENV-CONFIGURATION.md`, `UAT-MIGRATION-AND-ROLLBACK.md`, `UAT-MANUAL-TESTING-CHECKLIST.md`, `UAT-TROUBLESHOOTING.md` | Active installation, deployment and test procedures |
| `docs/CGPSC_DPC_ARCHITECTURE_HANDBOOK.md`, `CGPSC_DPC_DEVELOPER_HANDBOOK.md`, `CGPSC_DPC_USER_GUIDE.md` | Current architecture / developer / user documentation; referenced by code and tests |
| `docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` | Business decision register (BD-xx), cited by code and tests |
| `docs/CGPSC_DPC_BLOCKERS.md` | Production-rollout blockers |
| `docs/CHANGE_REGISTER_2026-08-24/26/27_REPORT.md`, `CHANGE_REGISTER_2026-09-05/06_REPORT.md` | Business change registers — the requirements code comments cite |
| `docs/business/CURRENT_SYSTEM_VS_LATEST_DPC_BUSINESS_DOCUMENT.md`, `docs/CR_UPLOAD_AND_NUMBER_SYSTEM_REVIEW.md`, `docs/business/DOCUMENT_INVENTORY.md` | Analyses of the Coder Instructions document |
| `docs/ODPC_NEW_FINAL.pptx`, `docs/CONVENTIONAL DPC PROCESS.pptx`, `docs/archive/DPC- SRS.docx`, `docs/archive/Online DPC Module-.docx`, `docs/archive/screen-specs.md`, `docs/archive/technical-proposal.md` | Original requirement / source documents (`GoldenCaseTest` cites the ODPC deck) |
| `docs/archive/2026-08-19-pre-final/EVENT_CATALOG.md`, `WORKFLOWS.md`, `integration/HRMS.md` | Named by code (`WorkflowEngine`, `ActivityTimeline`, `RealHrmsProvider`, `NotificationReplayDryRun`) |
| `docs/archive/2026-08-19-pre-final/PENDING_BUSINESS_DECISIONS.md` | Named by code (3 references) |
| `docs/archive/2026-08-19-pre-final/deployment/EVIDENCE-TABLE-PERMISSIONS.md`, `integration/CSC-DigiSign.md` | Security / integration instructions |
| `docs/SUGGESTIONS_AND_IMPROVEMENTS.md` | Named by a migration |
| `docs/uat/DPC_MODULE_FIX_001_POST_MASTER_AUDIT.md` | States a business rule ("a Post is a master entity") with a business overrule |
| `docs/worklog/2026-09-02-vacancy-by-quota.md`, `2026-09-02-vacancy-stated-on-the-chart.md` | The "business directive of 2026-09-02" that `VacancyEngine` and the Working Officers code cite |
| `docs/worklog/2026-09-07-ux-and-business-corrections.md`, `2026-09-07-allottee-workspace-fix.md` | Business corrections and a defect analysis that explain existing code |
| `docs/demo/SAMPLE_DATA_GUIDE.md`, `docs/demo/sample-data/**`, `docs/demo-kit/**` | Named by `SampleDataTest`; sample files the UAT checklist uses |

### Preserved as historical design record (archive)

These cannot be classified with certainty, so they are kept:
- `docs/archive/2026-08-19-pre-final/` — `ARCHITECT_REVIEW.md`, `ARCHITECTURE_BASELINE_v0.7.md`, `ARCHITECTURE_DECISIONS.md`, `CHANGELOG.md`, `IMPLEMENTATION_GUIDE_BLUEPRINT.md`, `INTEGRATION_ROADMAP.md`, `MILESTONE_SUMMARY.md`, `PROJECT_TIMELINE.md`, `TECHNICAL_DEBT.md`
- `docs/archive/2026-08-19-pre-final/design/*`
- the milestone worklogs under `docs/archive/2026-08-19-pre-final/worklog/` and `docs/archive/worklog/`

They hold design decisions and answered business questions from milestones M0–M9.

---

## 3. Needs manual review (preserved — UNKNOWN)

| File | Question |
|---|---|
| `docs/PENDING_BUSINESS_QUESTIONS.md` | UAT-phase questions (opened 2026-08-28). Are they all carried into `CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`? If yes, it is a duplicate. |
| `docs/BUSINESS_UAT_OBSERVATIONS.md` | Business UAT observations of 2026-08-31. Still open, or all resolved? |
| `docs/uat/TERMINAL_SUGGESTIONS.md` | Calls itself "the permanent source of truth" for UAT suggestions UAT-1…16. Keep if any are still open. |
| `docs/uat/END_TO_END_UAT_EXECUTION_LOG.md` | "Permanent execution journal / meeting minute" — evidence of the earlier UAT; keep unless the Commission says otherwise |
| `docs/ARCHITECT_REVIEW.md` and `docs/archive/2026-08-19-pre-final/ARCHITECT_REVIEW.md` | Two generations of the architect review; the current one follows a full-replace policy |
| `docs/demo/CURRENT_DEMO_FLOW.md`, `docs/demo/DEMO_DATA_GUIDE.md`, `docs/demo-kit/README.md` | Needed only while demonstrations are given on a development database. They contain the shared demo password `Demo@2026`. |
| `docs/demo/DEMO_LOGIN_CREDENTIALS.md`, `docs/demo/DEMO_USERS.md`, `docs/demo/sample-data/reference/REFERENCE-department-users.csv` | **Not in Git** (excluded from the UAT release, listed in local `.git/info/exclude`). Local-only demo credential sheets; not touched. |

Uploaded employee / service documents (`storage/app/**`) are **not** documentation and were not touched. They belong to the database cleanup workflow (`docs/UAT-DATA-CLEANUP.md`).
