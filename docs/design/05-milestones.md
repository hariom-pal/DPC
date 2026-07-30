# 05 — Implementation Milestones

Each milestone ships: **DB + migration + models + relationships + services + controllers + routes + views + validation (Form Requests) + tests + documentation**, and must pass the quality gate (`composer test`, `php artisan test`, `php artisan migrate`, `php artisan optimize`, Pint, PHPStan) before the next begins. Milestones marked ⛔ are blocked by an ambiguity in `06-ambiguities.md`.

| M | Name | Scope | Depends | Blockers |
|---|---|---|---|---|
| **M0** | Project setup | Laravel 12 install; `.env` (MySQL `dpc_dev`, Redis); Pint + Larastan + PHPUnit config; Bootstrap 5.3 base layout; app health route; bilingual (i18n) shell; seed skeleton; quality-gate script; git baseline | — | — |
| **M1** | Auth, RBAC, Registration | Roles & policies; login + session; OTP + e-sign mechanism; department registration; AO/AH account creation + credential issuance; audit log + sign events foundation | M0 | ⛔ Q1 (auth), Q2 (admin), Q14 (RBAC pkg), Q15 (audit pkg) |
| **M2** | Master data & rule config | All §10 masters + `config/dpc.php`; seeders (categories, cr_grade_marks, thresholds, zone_rules, review_reasons, remark_codes, pay_scales, posts); admin management screens | M0 | ⛔ Q3 (config), Q2 |
| **M3** | Pre-DPC Proposal | Posts sanctioned entry (AO) + approval (AH), feeder cadres, Schedule-4 constraint | M1,M2 | — |
| **M4** | DPC Proposal | Post selection, committee, working officers + vacancy sub-types, seniority list, tabulation chart, 3 Excel uploads→S3, AH approval, submit to CGPSC, DPC ID generation | M3 | ⛔ Q4, Q9, Q10, Q14 |
| **M5** | Scoring & Eligibility engine | ScoringService, EligibilityService, ZoneOfConsiderationService, SuperSeatService, DpcCalculationService → CALCULATE (MARK/REMARK, main/waiting). Unit tests using the worked example from S17/S18/S33 as golden cases | M4 | ⛔ Q5, Q6, Q12 |
| **M6** | CGPSC Workflow | Chairman allotment + report; Member accept/schedule/reschedule/re-allot; scheduling notifications | M4,M5 | — |
| **M7** | DPC Proceeding & Results | Conduct, CALCULATE, Main+Waiting generation, APPROVE BY CGPSC, CANCEL DPC, proceedings/result PDF (queue→S3) | M5,M6 | ⛔ Q7 |
| **M8** | Review DPC | Review proposal (reasons + original DPC ref), correction of tabulation, approval, re-run, result | M7 | — |
| **M9** | Circulation DPC | Held officers, circulation action, resolution + sign-off, result | M7 | ⛔ Q12 |
| **M10** | Self-Inspection & Objection | Individual login, own-data view, PRINT, time-boxed objection (late auto-filed) | M4 | ⛔ Q11 |
| **M11** | Notifications · Reports · Dashboards · Audit UI · Hardening | Full notification matrix; Chart.js dashboards/reports; audit-trail UI; Redis cache tuning; security & performance pass; final QA | all | ⛔ Q8 (i18n), Q16 (out-of-scope confirm) |

**Sequencing note:** M1 and M2 can proceed in parallel after M0. The scoring engine (M5) is isolated and unit-testable independently of the UI, so it can be built and verified against the PPT worked example before wiring into M4/M7.

**Definition of done (per milestone):** all gate commands green; feature tests cover the happy path + each validation/authorization rule; migrations reversible; no Pint/PHPStan violations; docs updated (and a `docs/worklog/` entry).
