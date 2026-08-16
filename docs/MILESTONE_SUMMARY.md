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
| **M7** | DPC Proceedings & Result Generation — 4 phases | S31–S34, S45 | ✅ **Complete** | **Frozen** (from M8) | 169 |
| **M8** | Review DPC — 3 phases | S35–S44 | ✅ **Complete & released** | **Frozen — AD-21** | 83 |
| **M9** | Circulation DPC | S45–S48 | ⚪ Not started | — | — |
| **M10** | Self-Inspection & Objection | S49–S51 | ⚪ Not started | — | — |
| **M11** | Notifications · Reports · Dashboards · Audit UI · Hardening | — | ⚪ Not started | — | — |

**620 PHPUnit tests / 1902 assertions.** Pint clean · PHPStan (Larastan level 6) 0 errors · `composer audit` clean · `migrate:fresh --seed` OK, 62 tables. Verified by the full gate run of 2026-08-14 and re-verified before the M8 documentation commit.

**Five freezes are in force** — M4 (architecture), M5 (engine), M6, M7 and M8 (**AD-21**). The mechanism for opening any of them is **AD-22**, the Freeze Exception; its register is empty and no exception has been requested. Verification is a diff per freeze:

```
git diff --name-only v0.6.0-m6-complete -- app/Domain/{Dpc,Eligibility,Cgpsc}   # M4/M5/M6
git diff --name-only v0.7.0-m7-complete -- app/Domain/Proceeding                # M7
git diff --name-only v0.8.0-m8-phase3   -- app/Domain/Review                    # M8 (+ its view paths)
```

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

## M8 — Review DPC ✅ *(complete, released, frozen)*

**All three phases complete, released and frozen.** Design at `docs/design/09-m8-review-dpc.md`. Closed, committed and tagged **`v0.8.0-m8-phase3`** / `a60689d` on 2026-08-14, pushed to `origin/feature/m0-setup`.

| | |
|---|---|
| **Status** | ✅ Complete · Released · **Frozen (AD-21)** |
| **Freeze** | **AD-21**, ACTIVE from 2026-08-14 at baseline `v0.8.0-m8-phase3`. Stricter than AD-15: **bug fixes only, each individually approved**; anything else needs a Freeze Exception |
| **Freeze Exception mechanism** | **AD-22**, defined 2026-08-14 — approver, admissibility, five recorded fields, three scopes, mandatory full gate plus an all-milestone freeze diff, automatic re-arm at a new baseline, three-artefact audit record. Governs all five freezes. **Register empty; none requested** |
| **BD-11** | **Deferred Business Decision — Required before production activation of the Review supersession policy.** Blocks no milestone |
| **Tests** | **83 feature tests** across four files in `tests/Feature/Review`, within a suite of **620 PHPUnit tests / 1902 assertions** |

**The smallest milestone since M2, by design rather than by ambition.** S35 says outright that *"the Review DPC process will be exactly the same as the Fresh DPC process"*, and S36–S44 bear it out: every screen already exists. M8 adds no new workflow, no new engine, no new renderer and no new proceeding — it adds an **origin** (which DPC is being corrected, and why), **copy-on-create** so the department corrects rather than retypes, and the answer to what a Review does to the original.

**Six questions raised, all ruled.** AQ-9: a Review **supersedes** the original, with permanent bidirectional references, a chain UUID and a review number. AQ-11: a Review applies **the original's** configuration snapshot, never today's rules.

**Phase 1 delivered (2026-08-14):** origin, chain, coded reasons, copy-on-create, and the "THIS IS A REVIEW DPC" banner — rendered from the layout via a view composer, because a Review traverses M4's frozen screens and they could not be edited. The milestone's central guarantee is its first test: **editing a Review never touches the original.**

**Phases 2 and 3 delivered:** calculation under the **original's** configuration snapshot (a correction cannot silently re-decide eligibility under later rules), full M6/M7 reuse, supersession taking effect at approval, and the `SUPERSEDED` watermark applied **on delivery** so a superseded document stays byte-identical to what was issued and still verifies.

**M8 introduced the decoration pattern** — four subclasses bound over M7 in the container, so M8 changes how M7 approves, renders and serves without modifying a byte of it. Documented, with its costs and limits, in `docs/ARCHITECTURE_BASELINE_v0.7.md`.

**BD-11 is deferred, not outstanding.** Architect ruling, 2026-08-14: what supersession means for an officer already promoted under the reviewed DPC is a **Deferred Business Decision — Required before production activation of the Review supersession policy.** It gates **no milestone** — not M8's completion, not M9's start. The three plausible answers (the promotion stands and only the paper is corrected; it is withdrawn from the date of the Review; it is withdrawn retrospectively with recovery of pay) differ in what they do to a serving officer's substantive rank and pay, so the system records the supersession, notifies the department, and asserts nothing about the promotion itself. That is shippable behaviour and it is not a settled policy — which is why the ruling is required **before the policy is switched on in production** rather than merely desirable.

**One finding in existing schema:** `review_reason_id` is singular; S37's reasons are checkboxes. Fixed additively, with the frozen column left unused rather than repurposed.

**The milestone that found the decorator pattern's ceiling — by hitting it.** The full gate run of 2026-08-14 turned up **two P0 defects that 83 green tests had not**, and neither was cosmetic:

- **A circular container dependency spanning M7 and M8** killed the PHP process on every document generation. Three of AD-20's four decorators sit in each other's dependency graph, and M7's own renderer already depended on the approval service, so Phase 3's constructor closed the ring. `ARCHITECTURE_BASELINE_v0.7.md` had warned that a *fifth* decorator was the signal to re-examine the boundary; **the fourth was already enough.** Fixed by resolving the document service inside `approve()` rather than in the constructor — one edge, no refactor.
- **A foreign-key identifier 65 characters long against MySQL's limit of 64**, which meant `dpc_document_review_references` **had never been created in the real database**. It survived a green suite because the suite runs on in-memory SQLite, which does not enforce the limit (**TD-24**). A green suite was not evidence the schema deploys.

> **What it changed.** Both were found by *running* the gate rather than reading the code — and the second is the counter-example to the pattern in the closing section below: here the gate did prove something the disciplines had missed. The lesson recorded is narrower and sharper: **the suite must run against the engine the system deploys on.**

---

## What every milestone since M4 has had in common

Worth stating once, because it is the strongest pattern in the project's history.

**The gate has not been where defects were caught.** Of the defects found since M4, the significant ones were found by:

- applying the **Demo Readiness Rule** (M4 — three P1s, six green gates missed them);
- implementing an **architect directive properly** (M6 — the notification rollback, the ordering defect);
- performing the mandated **full PPT comparison** (M5 — slide 33; M7 — BD-9 and BD-10);
- asserting an **outcome** rather than a rendering (M7 — the enum/string list filter that returned three empty lists while the screen test passed).

Every one of those defects passed Pint, PHPStan, PHPUnit and `composer audit` while broken. The gate proves the code is well-formed. It has never once proved it was right.
