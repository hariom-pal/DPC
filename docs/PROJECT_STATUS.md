# DPC Project — Status

**Last updated:** 2026-08-14. This is the single page to read first when returning to the project. Full detail: `docs/PROJECT_PROGRESS.md`. Pending decisions/findings: `docs/ARCHITECT_REVIEW.md`. Business questions: `docs/PENDING_BUSINESS_DECISIONS.md`, put to business users in `docs/UAT_BUSINESS_VALIDATION.md`. Known limitations & deferred items: `docs/TECHNICAL_DEBT.md`. Architecture rulings: `docs/ARCHITECTURE_DECISIONS.md`. Per-milestone summary: `docs/MILESTONE_SUMMARY.md`. History: `docs/PROJECT_TIMELINE.md`. Workflows: `docs/WORKFLOWS.md`. Business decisions: `docs/PENDING_BUSINESS_DECISIONS.md`. Integrations: `docs/INTEGRATION_ROADMAP.md`. UAT: `docs/UAT_CHECKLIST.md`, `docs/DEMO_SCRIPT.md`. History: `docs/CHANGELOG.md`. Source of truth for business rules: `docs/ODPC_NEW_FINAL.pptx` + `docs/CONVENTIONAL DPC PROCESS.pptx`.

## Current Status

| | |
|---|---|
| ✅ | **M1 Complete** — Authentication, RBAC, Department Registration |
| ✅ | **M2 Complete** — Master Data, Rule Configuration, User Management |
| ✅ | **M3 Complete** — Pre-DPC Proposal |
| ✅ | **M4 Complete (Frozen)** — DPC Proposal |
| ✅ | **M5 Complete (Frozen)** — Scoring & Eligibility Engine |
| ✅ | **M6 Complete (Frozen)** — CGPSC Workflow |
| 🟡 | **UAT Ready** — qualified: see the note below |
| ✅ | **M7 Design Approved** — `docs/design/08-m7-proceedings.md`; all 8 questions ruled |
| ✅ | **M7 Complete** — DPC Proceedings & Result Generation |
| ⏳ | **M8 Design** — Review DPC; design only, implementation not started |

(M0 Foundation is complete and predates M1.)

**On "UAT Ready" being amber rather than green:** the pipeline runs end to end and UAT can begin, but **BD-2/BD-3 (reserved relaxation) is unsettled**, so any DPC where a reserved category has fewer eligible officers than vacancies returns a result the system marks **provisional**. That is correct behaviour — it declines to guess — but a provisional result cannot be published, so business sign-off on the calculation is partial until the rule is settled.

**On M7 — COMPLETE (closed 2026-08-14).** All four phases delivered and approved: the proceeding lifecycle, the S33 editable workspace with append-only corrections, `CALCULATE` against the frozen M5 engine with stale-calculation protection, the S34 result, `APPROVE BY CGPSC`, `CANCEL DPC`, held promotions (`CIR`), and the proceedings document and promotion list rendered, stored, signed and published — 169 tests. **No file in M4, M5 or M6 was modified**, verified by full-repository diff at every phase boundary.

**Four business questions remain open and are tracked only in `docs/PENDING_BUSINESS_DECISIONS.md`** — BD-2, BD-3, BD-9 and BD-10. **M7 is not reopened for them** (architect ruling). They constrain what may be *published*, not what is built: a result affected by BD-2/BD-3 or BD-10 is marked provisional and cannot be published. **BD-9 is the exception and the one to chase** — it raises no flag, so it can produce a confidently wrong published list rather than a blocked one.

**The milestone is not closed.** Four business questions change what a published promotion list says: **BD-2** and **BD-3** (reserved relaxation), **BD-9** (which waiting list an officer joins when their category has no vacancy) and **BD-10** (what a pending court case does). BD-9 and BD-10 were found by comparing the system's output against the customer's own worked answer on slide 34, row by row. All four are put to business users in plain language in `docs/UAT_BUSINESS_VALIDATION.md`. **M5 is not to be modified for BD-9** (architect ruling) — it is raised to CGPSC instead, and **M8 does not start** until business answers or explicitly defers.

### Repository baseline

Tagged **`v0.6.0-m6-complete`** on branch `feature/m0-setup`, 2026-08-13 — the first commit since M3, capturing M4, M5 and M6 as one frozen state. This closes **TD-14**: until this tag existed there was no commit at any milestone boundary, which meant the three freeze policies could not be verified by diff. They now can.

**The branch name `feature/m0-setup` is six milestones stale** and describes none of what it contains. Renaming it is a one-line change but rewrites the remote ref, so it is left for a decision rather than done silently.

### Previously

**M4 — DPC Proposal (detailed): COMPLETE.** All 7 phases shipped 2026-08-13: Post Selection (S14), Committee (S15), Working Officers + Vacancies (S16), Seniority List (S17), Evaluation/Tabulation Chart (S18), Excel Import (S16–S18), and Closeout (S19–S25 AH review + single approval). Stopped per instruction to wait for architect sign-off before M5.

**M4 is ARCHITECTURE FROZEN** (ruled 2026-08-13): bug fixes, security fixes, performance improvements, VAPT findings and production hardening only. No new features, workflow changes, schema changes, or refactoring. Enhancements go to a future milestone or the M11 Enhancement Backlog.

**Feature Flags & Provider Pattern established** (2026-08-13, before M5): every external integration is switched by a `FEATURE_*` environment variable and defaults to **off**. The whole M4 workflow — draft through signed approval — is tested to run with every integration disabled. Live state is visible to the CGPSC Admin at `/admin/integrations`.

**Demo Readiness Rule adopted** (2026-08-13, permanent): a phase is complete only when independently demoable to the customer — 14-point checklist, verified for M4 in `docs/ARCHITECT_REVIEW.md`. Applying it caught **three P1 defects that six consecutive green gates had missed** (an empty proposal could be submitted and signed; the signed document contained only a header; the audit trail had never been tested anywhere in the project). All three are fixed and covered.

**Roadmap update:** a **UAT Readiness Review** checkpoint was inserted between M6 and M7 in `docs/design/05-milestones.md` (approved 2026-08-13) — the full proposal → evaluation → allotment pipeline gets demoed to business users before the higher-stakes proceeding/legal-output work (M7+) begins. M4's own phasing is unchanged.

## Overall Progress

**~64% by milestone count** — M0 Foundation, M1 Auth/RBAC/Registration, M2 Master Data/User Management, M3 Pre-DPC Proposal, **M4 DPC Proposal**, **M5 Scoring & Eligibility Engine**, and **M6 CGPSC Workflow** are complete.

## Completed Modules

- ✅ Foundation & platform, Authentication & RBAC, Department Registration, Master Data & Rule Configuration, User Management, Pre-DPC Proposal
- ✅ DPC Proposal (M4) — all five sections, Excel import, AH review walkthrough, single approval with full-proposal digital signature; every section immutable outside DRAFT/RETURNED at Policy and Service layers; all three officer snapshot tables carry the same standard ten fields; every section save on the Activity Timeline; a proposal cannot be submitted until the S14→S18 sequence is complete
- ✅ Excel Import — staged Preview → Validation → Error Report → Commit pipeline for the three PPT upload templates; imports reuse the manual-entry service and validation layers, so neither path can drift
- 🟡 CSC DigiSign (Mock working; real CSC integration pending CGPSC's API — see `docs/integration/CSC-DigiSign.md`; confirmed cross-cutting, not a standalone milestone)
- 🟡 Notifications (mechanism + OTP delivery working; live SMS gateway not connected)
- 🟡 Audit trail (owen-it audit + Activity Timeline live; no browser UI yet)

## Current Work

**M6 — CGPSC Workflow: COMPLETE.** All 4 phases delivered 2026-08-13, covering S26–S30 in full. The Chairman allots a forwarded proposal and may re-allot it (S28); the allottee accepts, schedules, and may re-schedule (S30) — with the previous allottee and the previous date/time shown struck through beside the new, as the slide does. Allotments and schedules are **append-only**, and the workflow state is **derived** from the current allotment rather than stored on the frozen proposal.

The timeline is now **versioned, UUID-identified, actor-snapshotted and replayable** across every workflow: a test rebuilds a notification and a calendar entry from timeline records alone, without reading a business table.

**M5 remains COMPLETE and FROZEN.** All 4 phases approved 2026-08-13. CALCULATE is wired, with calculation history, Main/Waiting list screens, an all-officers table, and Explain Result per officer. Runs are addressed by **UUID only**. Every run stays self-contained and **immutable**; recalculating creates a new run.

**The full PPT comparison this phase required found the customer's answer key on slide 33** — MARK and REMARK filled in for all ten officers. Earlier reviews recorded that no worked answer existed; that was true of slide 18 and **false** of slide 33. **The engine reproduces 9 of the 10 stated marks exactly**, and three rows independently confirm approved decisions. **Two diverge** and need a ruling — see Open Architect Decisions.

**The M5 ENGINE FREEZE is now in force.** The Eligibility Algorithm, Scoring Algorithm, Zone Logic and Super Seat Logic are frozen — future changes only through Configuration, Master Data or Government Notification, never source code. Bug and security fixes remain permitted. Only *approved* rules are frozen: the unresolved business rules (BD-1, BD-2, BD-3) are explicitly excluded.

**M6 IS NOW FROZEN** (2026-08-13, on closure): no further functional changes; **bug fixes only**. All three completed workflow milestones are now under freeze — M4 architecture-frozen, M5 engine-frozen, M6 frozen. M4 and M5 were not touched during M6.

**UAT documentation set delivered** (2026-08-13, documentation only — no source file modified):

- `docs/INTEGRATION_ROADMAP.md` — all six external integrations plus internal notifications, each with Feature Flag, Provider, Events Used, Dependencies, Current Status
- `docs/UAT_CHECKLIST.md` — ~60 business-language scenarios, one scenario to one expected outcome
- `docs/DEMO_SCRIPT.md` — Parts 0–9 following the PPT, with a 15-minute short version
- **`docs/TECHNICAL_DEBT.md`** — a **permanent** register of every known limitation, deferred item and recommendation. Created because `ARCHITECT_REVIEW.md` is replaced in full each phase, so a carried-forward risk previously survived only by being manually re-copied.

## Next Milestone

**UAT Readiness Review — prepared and awaiting architect approval.** Written up in `docs/ARCHITECT_REVIEW.md`. The full proposal → evaluation → allotment → scheduling pipeline is demonstrable end to end with every integration switched off. **M7 has not begun and will not begin without approval.**

**One item must be settled before UAT results can be treated as final:** BD-2/BD-3 (reserved relaxation). Any DPC where a reserved category has fewer eligible officers than vacancies produces a **provisional** result, correctly flagged rather than guessed. UAT can proceed — testers see the flag and it is documented in the checklist preamble — but a provisional result cannot be published.

**Highest-severity engineering item, raised at M6 closeout (TD-14):** the last commit on this branch is `3133ac4 M3`. **M4, M5 and M6 — three closed, frozen milestones, 133 changed paths — have never been committed**, and there is no stash. Beyond the obvious loss risk, this makes the three freeze policies unenforceable: a freeze is a statement about a commit, and with no commit at any milestone boundary there is nothing to diff a suspected breach against. Recommendation: commit each closed milestone at its own boundary before M7 begins. Awaiting instruction.

**Before production, not before UAT:** a government SMS gateway (`TD-6` — without it OTP delivery fails, so no officer can complete verification), the evidence-table permission grants (`TD-1`), and a capacity plan for permanently retained import files and signed documents (`TD-8`). All tracked in `docs/TECHNICAL_DEBT.md`.

After the UAT Readiness Review: M7 (DPC Proceeding) → M8+ → **UAT & Security Hardening sprint** → production deployment.

## Open Architect Decisions

**Engineering questions** are in `docs/ARCHITECT_REVIEW.md`: approval of the UAT Readiness Review and authorisation to begin M7.

**Known limitations and deferred items** are in `docs/TECHNICAL_DEBT.md` — permanent, and not reset at a phase boundary.

**Business questions now have their own register — `docs/PENDING_BUSINESS_DECISIONS.md`** (created 2026-08-13 by directive; they are no longer kept in `ARCHITECT_REVIEW.md`). Eight items are open, three of them P1 and blocking publishable promotion results:

- **BD-1 — does `PUNISHMENT` disqualify an officer?** PPT slide 33 marks two officers NE who score 15 and carry `PUNISHMENT` in the Note column; the engine passes them, since §5.3 lists only two hard gates. Ruled a *business* rule, not an engineering one — not implemented, current behaviour pinned by test.
- **BD-2 / BD-3 — reserved relaxation (Q5/Q6):** automatic or committee discretion, and does relaxation to 10 apply beyond Class-I→I?

While any of these is open, the engine applies the printed rule, flags every case the unruled rule could have changed, and never guesses. Flagged runs are marked provisional on screen.

**Every PPT difference is classified** Category A (implementation bug — none open), B (documentation difference), C (business clarification — in the register), or D (future milestone).

## Milestone Closure Record

Formal record of closed milestones. A closed milestone is approved, gate-checked, documented, and subject to whatever freeze applies to it.

| Milestone | Scope | Closed | Freeze in force |
|---|---|---|---|
| **M0** | Foundation & platform | 2026-07-30 | — |
| **M1** | Authentication, RBAC, Department Registration | 2026-07-30 | — |
| **M2** | Master Data, Rule Configuration, User Management | 2026-08-13 | — |
| **M3** | Pre-DPC Proposal | 2026-08-13 | — |
| **M4** | DPC Proposal (detailed) — 7 phases | 2026-08-13 | **Architecture frozen.** Bug fixes, security fixes, performance improvements, VAPT findings, production hardening only. No new features, workflow changes, schema changes, or refactoring. |
| **M6** | CGPSC Workflow — 4 phases | **2026-08-13** | **Frozen.** No further functional changes; bug fixes only. |
| **M5** | Scoring & Eligibility Engine — 4 phases | **2026-08-13** | **Engine frozen.** Eligibility Algorithm, Scoring Algorithm, Zone Logic, Super Seat Logic. Changes only through Configuration, Master Data, or Government Notification — never source code. Bug and security fixes remain permitted. |

### M5 closure detail

Closed on architect approval of Phase 4, 2026-08-13. Delivered across four phases:

1. Scoring Engine, Eligibility Engine, Golden Test Suite
2. Zone of Consideration, Super Seat
3. `DpcCalculationService`, calculation runs and results, Main/Waiting lists, Explain Result, UUID, persistence, audit
4. CALCULATE wiring, calculation history, Main/Waiting list UI, Explain Result UI

**Closure evidence:** Pint clean · PHPStan (Larastan level 6) 0 errors · `composer audit` no advisories · 291 tests / 1007 assertions passing · `migrate:fresh --seed` OK (52 tables) · Security, Performance, VAPT and full PPT reviews completed · 14-point Demo Readiness checklist verified.

**The full PPT comparison at closure found the customer's answer key on slide 33** and corrected an earlier error of ours that no worked MARK/REMARK existed. The engine reproduces **9 of the 10 stated marks exactly**; three rows independently confirm approved decisions; two diverge and are recorded as **BD-1** in the business register rather than being resolved unilaterally.

**Freeze scope:** only *approved* rules are frozen. Unresolved business rules — BD-1, BD-2, BD-3 — are explicitly excluded, so settling them changes engine behaviour through configuration without breaching the freeze.

### M6 closure detail

Closed on architect approval of Phase 4, 2026-08-13. Delivered across four phases:

1. Workflow definition, `dpc_allotments`, Chairman allotment screen, Allotment Report
2. Member acceptance, `dpc_schedules`, scheduling screen, Scheduling Report
3. Re-scheduling, re-allotment history, old-beside-new display for both dates and allottees
4. Notification coverage across every transition, closeout, full PPT verification

**Closure evidence:** Pint clean · PHPStan (Larastan level 6) 0 errors · `composer audit` no advisories · 368 tests / 1281 assertions passing · `migrate:fresh --seed` OK (54 tables) · Security, Performance, VAPT and full S26–S30 PPT reviews completed · 14-point Demo Readiness checklist verified.

**Two defects found and fixed during the milestone**, both by directives that looked like documentation tasks: a failing notification could roll back a completed workflow transition (Phase 2), and every timeline read had a non-deterministic order because `occurred_at` has second granularity while a transition writes two rows inside one transaction (Phase 4).

**The timeline is now the legal workflow ledger** — versioned, UUID-identified, actor-snapshotted and deterministically ordered, with a test that replays a full workflow and rebuilds both a notification and a calendar entry without reading a business table.

## Build Status

Permanent four-part gate (ruled 2026-08-13) — every phase must pass all four before completion:

- Laravel Pint (PSR-12 + strict types): **passed**
- PHPStan / Larastan level 6: **0 errors**
- `composer audit`: **no advisories**
- PHPUnit: **368 passed**

Plus `php artisan migrate:fresh --seed`: **OK** (54 tables, 10 seeders). Run all four with `composer gate`.

## Test Status

**368 passed / 1281 assertions, 0 failing** (41 test files, `composer test`). Golden cases run against the *shipped* configuration, so a rule change that would alter a promotion outcome fails the build. **All 13 configurable business rules have at least one golden test** — a permanent project rule as of 2026-08-13. Auditing is now exercised under test for the first time — `config/audit.php` had it hardcoded off in console, silently disabling every audit assertion since M1.
