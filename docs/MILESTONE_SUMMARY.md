# Milestone Summary

One page per milestone: what it delivered, what governs it now, and what it left behind.

**Created 2026-08-14.** Updated at each milestone closure.

---

## At a glance

| # | Milestone | PPT | Status | Freeze | Tests added |
|---|---|---|---|---|---|
| **M0** | Foundation & Platform | — | ✅ Complete | — | — |
| **M1** | Authentication, RBAC, Department Registration | S5–S11 | ✅ Complete | — | — |
| **M2** | Master Data, Rule Configuration, User Management | — | ✅ Complete | — | — |
| **M3** | Pre-DPC Proposal | — | ✅ Complete | — | — |
| **M4** | DPC Proposal (detailed) — 7 phases | S12–S25 | ✅ Complete | **Architecture frozen** | 145 |
| **M5** | Scoring & Eligibility Engine — 4 phases | S18, S33, C4–C6 | ✅ Complete | **Engine frozen** | 146 |
| **M6** | CGPSC Workflow — 4 phases | S26–S30 | ✅ Complete | **Frozen** | 77 |
| **M7** | DPC Proceedings & Result Generation — 4 phases | S31–S34, S45 | ✅ **Complete** | — | 169 |
| **M8** | Review DPC | S35–S44 | 📐 Design | — | — |
| **M9** | Circulation DPC | S45–S48 | ⚪ Not started | — | — |
| **M10** | Self-Inspection & Objection | S49–S51 | ⚪ Not started | — | — |
| **M11** | Notifications · Reports · Dashboards · Audit UI · Hardening | — | ⚪ Not started | — | — |

**537 tests / 1682 assertions.** Pint clean · PHPStan (Larastan level 6) 0 errors · `composer audit` clean · `migrate:fresh --seed` OK, 58 tables.

---

## M0 — Foundation & Platform

**Delivered.** Laravel 13 on PHP 8.4, MySQL, Redis for cache/queue/sessions, Blade + Bootstrap 5.3 vendored with no Node build step. `/health` probes DB, Redis and cache. Bilingual shell (EN/HI).

**Standing consequence.** The no-Node decision has held through eight milestones and shaped M7's document rendering: HTML from Blade rather than a JavaScript toolchain.

---

## M1 — Authentication, RBAC, Department Registration

**Delivered.** Six roles via `spatie/laravel-permission`; login by e-mail or mobile; dual OTP verification at registration; forced password change on first login; department self-registration with credential issue.

**Approved deviation (Q1).** The PPT shows Aadhaar number + Aadhaar OTP on every screen. That was replaced with password login plus OTP step-up at each legally significant signing action, logged to the audit trail as a record of intent. Every subsequent "login" slide is a documented Category-B difference rather than a defect.

**Left behind.** OTP delivery depends on an SMS gateway that does not exist yet (**TD-6**) — in production, nobody can complete verification.

---

## M2 — Master Data, Rule Configuration, User Management

**Delivered.** Cadres, pay scales, designations, districts, reservation categories and roster, committee masters, versioned recruitment rules, CGPSC user provisioning.

**Standing consequence.** Every business value the engine later needed was already master data or configuration. M5's freeze — "changes only through Configuration, Master Data or Government Notification" — is only possible because M2 put them there first.

---

## M3 — Pre-DPC Proposal

**Delivered.** The first workflow milestone: draft → submitted → approved/returned, on the config-driven `WorkflowEngine` that every later milestone reuses unchanged.

**Left behind.** `PreDpcProposalService` does not re-fetch with `lockForUpdate()` before reading status (**TD-5**). M4 onward do; this one was never backported.

---

## M4 — DPC Proposal *(architecture frozen)*

**Delivered across 7 phases.** Post selection (S14), committee (S15), working officers and vacancies (S16), seniority list (S17), evaluation chart (S18), Excel import with staged preview → validation → error report → commit, and closeout: the AH review walkthrough (S19–S25) with a single signed approval covering all five sections.

**The milestone that established the evidence model.** Every section is immutable outside `DRAFT`/`RETURNED` at both Policy and Service layers. All three officer-snapshot tables carry the same ten fields. A proposal cannot be submitted until the S14→S18 sequence is complete.

**Where the Demo Readiness Rule proved itself.** Applying it at closeout caught **three P1 defects that six consecutive green gates had missed**: empty proposals could be submitted and signed; the signed document contained only a header; and **the audit trail had never been exercised by any test since M1**, because `audit.console` was hardcoded false and PHPUnit runs in console.

**Freeze:** bug fixes, security fixes, performance, VAPT findings and production hardening only.

---

## M5 — Scoring & Eligibility Engine *(engine frozen)*

**Delivered across 4 phases.** Scoring (§5.1–5.2), eligibility gates and thresholds (§5.3–5.4), zone of consideration (2 × vacancies + 4), Super Seat, calculation runs and results with Explain Result, and the CALCULATE UI.

**Built pure.** The four engines touch no database, no clock and no configuration at run time; `DpcCalculationService` is the only class that reads the database, and it exists so the engines never have to. That purity is what let M7 hand the engine a *corrected* chart three milestones later without changing a line of it.

**Nothing is ever overwritten.** Recalculating creates a new run. Each run stores the complete rule set it executed under, so a result explains itself years later under the rules that applied to it.

**The slide-33 discovery.** The mandated full-PPT comparison at closure found the customer's own answer key — MARK and REMARK filled in for all ten officers — correcting an earlier claim of ours that no worked answer existed. **The engine reproduced 9 of the 10 stated marks exactly.** Two rows diverged and became **BD-1** rather than being implemented.

**Freeze:** changes only through Configuration, Master Data or Government Notification. Unresolved business rules are explicitly excluded from the freeze, so settling them changes behaviour without breaching it.

---

## M6 — CGPSC Workflow *(frozen)*

**Delivered across 4 phases.** Chairman allotment and re-allotment (S28), member acceptance and scheduling (S29–S30), re-scheduling with the previous slot struck through beside the new, and notification coverage verified **at the listener** rather than at the event.

**The milestone that made the timeline a legal ledger.** Versioned (`schema_version`), UUID-identified, actor-**snapshotted** — a renamed user cannot rewrite who acted — deterministically ordered by `(occurred_at, uuid)`, and replayable: a test rebuilds a notification and a calendar entry from timeline records alone, with no business table.

**Two defects found by implementing directives properly rather than by testing the feature.** A failing notification could roll back a completed workflow transition — the obvious fix (queued + `afterCommit`) was insufficient, because dispatch ran *inside* the transaction. And every timeline read was non-deterministically ordered, because `occurred_at` has second granularity while one transition writes two rows.

---

## M7 — DPC Proceedings & Result Generation ✅

**Delivered across 4 phases, closed 2026-08-14.**

| Phase | Delivered |
|---|---|
| 1 | Proceeding entity and lifecycle, append-only override model, timeline, audit |
| 2 | S33 editable workspace — `EDIT`, `TRIBAL`, `SAVE`, corrections beside the department's entry |
| 3 | `CALCULATE`, recalculation, stale-calculation protection, S34 result, `APPROVE BY CGPSC`, `CANCEL DPC`, concurrency locking |
| 4 | Held promotions (`CIR`), HTML proceedings document and promotion list, versioning, render → store → sign → publish |

**The milestone that had to touch two frozen ones without touching them.** A correction never modifies M4 data — it is recorded as an append-only override, and the *effective chart* is assembled and handed to the frozen M5 engine as in-memory copies. `git diff v0.6.0-m6-complete -- app/Domain/{Dpc,Eligibility,Cgpsc}` is empty across all four phases.

**The PPT's own worked example, reproduced end to end.** US3 scores **14** and is `NE`; once `TRIBAL 2021` is recorded it scores **15** and moves to the waiting list — exactly as slide 33 shows.

**Stale protection by sequence, not by timestamp.** `applied_at` and `executed_at` have second granularity, so a correction and a recalculation inside one second would be indistinguishable by time — and the failure would be *silent*, approving a list that does not reflect the last correction. The per-proceeding correction sequence removes the ambiguity entirely.

**A silent-failure class closed permanently.** Twice in M7 a newly added column failed to reach its model, because `update()` discards non-fillable attributes and **reports success**. `Model::preventSilentlyDiscardingAttributes()` is now on outside production.

**Left open, deliberately.** BD-9 and BD-10, found by the Phase 4 full-PPT comparison against slide 34's stated result. Ruled: raise to CGPSC, implement no assumption, do not modify M5. **M7 is not reopened for them.**

---

## What every milestone since M4 has had in common

Worth stating once, because it is the strongest pattern in the project's history.

**The gate has not been where defects were caught.** Of the defects found since M4, the significant ones were found by:

- applying the **Demo Readiness Rule** (M4 — three P1s, six green gates missed them);
- implementing an **architect directive properly** (M6 — the notification rollback, the ordering defect);
- performing the mandated **full PPT comparison** (M5 — slide 33; M7 — BD-9 and BD-10);
- asserting an **outcome** rather than a rendering (M7 — the enum/string list filter that returned three empty lists while the screen test passed).

Every one of those defects passed Pint, PHPStan, PHPUnit and `composer audit` while broken. The gate proves the code is well-formed. It has never once proved it was right.
