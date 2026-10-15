# 2026-08-28 — Full Implementation Audit

**Task:** Complete implementation audit of the e-DPC Portal, verified from source, schema and an executed quality gate — not from documentation.

**Deliverable:** `docs/ARCHITECT_REVIEW.md` (full replace). Thirteen sections: business requirements, database, screens, workflows, validation, API, security, code quality, tests, documentation, matrix, summary, final report.

## What was verified, and how

| Area | Method |
|---|---|
| Quality gate | Ran all five parts: Pint **pass**, PHPStan L6 **0 errors**, `composer audit` **clean**, `schema:verify-mysql` **79 migrations / 78 tables**, PHPUnit **1,287 passed / 4,228 assertions** |
| Database | `information_schema` on `dpc_dev`; built the schema fresh on MySQL in a scratch DB and **diffed the table set — identical** |
| Routes | `route:list --json` (270 routes) cross-referenced against views, controllers and tests to find unreachable endpoints |
| Screens | All 145 Blade views; `ScreenSmokeTest` covers every parameterless screen × 7 roles × 2 locales |
| Business rules | Read the engines (`VacancyEngine`, `ScoringService`, `ZoneOfConsiderationService`, `CandidateGateService`, `RuleSet`) and confirmed eight specific documentation claims against executable code |
| Docs | Grep-verified terminology, class references and stated numbers against the codebase |

## Headline result

94 requirements assessed: **66 ✅ / 13 ⚠️ / 4 ⭐ / 11 ❌**. Nine of the eleven not-implemented items are explained by a recorded deviation, withdrawal or open business decision.

## The two findings that should not wait

1. **The working tree is uncommitted.** 422 changed paths against HEAD `71761a7` — 147 untracked, including **24 migrations**, **all 26 language files** and **20 test classes**. Three change registers' worth of *destructive* schema change (dropped tables, dropped columns, renames) has no rollback point, and a fresh clone will not boot.
2. **The officer-data tables are not audited.** `DpcEvaluationRow`, `DpcEvaluationCrGrade`, the three flag tables, `DpcSeniorityEntry`, `DpcWorkingOfficer`, `DpcVacancy`, `DpcCalculationResult` carry no `Auditable` contract. A CR grade — the number a promotion turns on — can change on a draft and the `audits` table records nothing.

## Other findings raised

- `committee/search` returns portal-wide name/email/mobile on a 3-char prefix, with **no validation, no throttle and zero tests**.
- `holds.store` and `holds.detect` are reachable routes with no UI control.
- `config('dpc.committee.member_level')` and `config('dpc.directory')` are read by nothing; `MemberLevelRule` is cited by two live comments and one handbook paragraph but does not exist.
- Spatie permission tables are empty and the `permission:` middleware aliases are unused.
- Three different PDF-validation standards across three upload paths; hardcoded 10 MB caps in three Form Requests.
- `README.md` states five numbers that are now wrong; the four root `PPT_*.md` files (2026-08-22) still use "cadre", cite a deleted JS file, and understate two screens that have since been built.
- Six shipped screens are absent from the handbook set.

## Recommended order

Commit and tag the tree → start B-1 procurement → ask the B-2 question → get **BD-39** and **BD-9** answered → make the officer-data models `Auditable` → harden and test `committee/search` → apply the evidence grants → correct the documentation.

**Nothing was changed in the application. This task was read-only apart from the two documents it produced.**
