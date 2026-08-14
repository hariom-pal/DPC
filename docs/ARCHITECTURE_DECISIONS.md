# Architecture Decisions

The durable engineering rulings this system is built on — what was decided, why, and what it forecloses.

**Created 2026-08-14** on the directive to keep Business Decisions, Technical Debt and Architecture Decisions as three separate registers.

---

## Why this file exists

It is the third of the three registers, and it was the one missing.

`docs/PENDING_BUSINESS_DECISIONS.md` holds questions only CGPSC can answer. `docs/TECHNICAL_DEBT.md` holds what we know is imperfect. Until now, **the decisions that shaped the architecture lived only in `docs/ARCHITECT_REVIEW.md` — which is replaced in full at every phase.** A ruling made in M4 Phase 5 and never restated since survives in a git history nobody reads.

That is the same failure mode `TECHNICAL_DEBT.md` was created to fix, applied to a different kind of information. This file closes it.

**What belongs here:** a decision that constrains future work. **What does not:** business rules (first register), known imperfections (second), or anything already obvious from the code.

Each entry records the **consequence** as well as the decision, because a decision whose cost is not written down gets re-litigated by whoever next finds it inconvenient.

---

## Foundational

### AD-1 — The two PPT decks are the only source of business requirements

**Decided:** 2026-07-30. **Standing.**

`ODPC_NEW_FINAL.pptx` and `CONVENTIONAL DPC PROCESS.pptx` govern. On conflict, the PPTs win. `docs/archive/` — including the SRS — is historical reference and must not be implemented from.

**Consequence.** The archived SRS describes per-member voting, free-text meeting minutes, an uploaded signed-proceedings file, and a separate promotion-order module. **None was built.** That is roughly a doubling of M7 avoided, and it would have been built on the authority of a superseded document. Confirmed by ruling at M7 design (AQ-8).

### AD-2 — Never invent a business rule

**Decided:** 2026-08-13, on the M5 slide-33 finding. **Standing.**

Where the deck is silent or ambiguous, the printed rule is applied, every case the unruled rule could have changed is flagged, and nothing is guessed in either direction.

**Consequence.** Two officers whom the customer's own answer key marks ineligible remain eligible — flagged, provisional, unpublishable — rather than being quietly disqualified on an inference (BD-1). The same discipline produced BD-9 and BD-10 and **held M7 open on the strength of two rows in a worked example**. It is the most expensive rule in the project and the one least worth relaxing.

### AD-3 — Business rules live in configuration and master data, never in source

**Decided:** M2, formalised at the M5 freeze.

Grade marks, thresholds, zone sizing, hold triggers, correctable fields, reason minimums — all configuration or master data. Every configurable rule has at least one golden test pinned against the **shipped** configuration, so a change that would alter a promotion outcome fails the build.

**Consequence.** A government notification changes a setting, not the software. It is also what makes the M5 freeze *possible*: "changes only through Configuration, Master Data or Government Notification" is only a viable rule because M2 put them there first.

---

## Evidence and immutability

### AD-4 — Nothing that constitutes evidence is ever overwritten

**Decided:** progressively M4 → M7. **The system's central architectural commitment.**

Calculation runs, allotments, schedules, corrections, holds and documents are all append-only. Superseding writes a new row and marks the old one superseded; it never edits. Immutability is enforced at the **model layer**, in `updating`/`deleting` guards, so the guarantee survives any caller.

**Consequence.** More rows, more joins, and a "current state" that is always derived rather than read. In exchange, the question a tribunal actually asks — *what did the system say at the moment the decision was taken?* — is answerable, and no role including an administrator can change the answer.

**Known limit.** Model-layer guards cannot stop a query-builder mass update. Closing that is a deployment control (**TD-1**), not code — ruled against DB triggers.

### AD-5 — Actors are snapshotted, not referenced

**Decided:** M6 Phase 3.

Every timeline event and every evidence record stores the actor's **name as it was at the time**, alongside the foreign key.

**Consequence.** A reference tells you who that user *is now*; a snapshot tells you who acted *then*, and they diverge exactly where it matters — a renamed user, or one since removed. A test asserts renaming an actor does not change the record.

### AD-6 — The timeline is the legal workflow history; business tables hold current state

**Decided:** M6, reaffirmed at every M7 phase.

History screens read timeline rows **only**, and never reconstruct a lifecycle by joining business tables. Every event carries enough to be replayed without them — including previous values on supersession, because otherwise old-beside-new could only be rebuilt by walking a `superseded_by_id` chain.

**Consequence.** Event payloads are deliberately redundant with the tables. That redundancy is the point: a subscriber, an export or an audit can be rebuilt from `activity_timelines` alone.

### AD-7 — Timeline ordering is `(occurred_at, uuid)`, never the sequential id

**Decided:** M6 Phase 4, after a live defect.

**Consequence.** `occurred_at` has second granularity and one transition writes several rows, so ordering by time alone returned events in whatever order the database chose — a history that reordered itself between reads. The same reasoning later produced M7's **correction sequence**: staleness could not be decided by timestamp either.

### AD-8 — UUID is the only public identifier

**Decided:** M5 Phase 3, extended since.

Every route binds by UUID; sequential ids never leave the database. The proceeding UUID additionally appears in the audit trail, the timeline, and every generated document.

**Consequence.** A legal document cannot leak how many records exist, and neighbours cannot be reached by guessing. `dpc_proposals` had no UUID until M7 Phase 4 needed one — added and backfilled, with **TD-19** recording that minting it for new proposals is still a workaround around the M4 freeze.

---

## Structure

### AD-9 — Thin controllers → Domain Services → Eloquent models

**Decided:** M0. Never varied.

Controllers authorise and delegate. Validation lives in Form Requests. Business logic lives in `app/Domain/*` services. **Every rule enforced in a policy is enforced again in the service**, so a non-HTTP caller is bound by it too.

### AD-10 — Engines are pure; one class touches the database

**Decided:** M5 Phase 1.

Scoring, eligibility, zone and Super Seat take value objects and return value objects. No database, no clock, no `config()` at run time. `DpcCalculationService` absorbs all of it.

**Consequence, and the payoff arrived two milestones later.** M7 needed the engine to score a *corrected* chart. Because the engine reads only what it is handed, M7 assembled corrected in-memory rows and handed them over — **without changing one line of frozen M5 code.** An engine that fetched its own data could not have been reused that way.

### AD-11 — One workflow engine, driven by configuration

**Decided:** M3.

Transitions are declared in `config/dpc.php`; `WorkflowEngine` executes them, writes the ledger, records the timeline and dispatches notifications. Four workflows now run on it unchanged.

**Corollary — declare only implemented transitions.** A transition nothing can perform reads as supported behaviour when it is not. And an action that changes no state is **not** modelled as a transition: corrections, calculations, holds and publication all leave the DPC where they found it, so the ledger must not claim otherwise.

### AD-12 — A failing notification can never fail the workflow

**Decided:** M6 Phase 2, after the obvious fix proved insufficient.

**Consequence.** Queueing plus `afterCommit` was *not* enough — `dispatch()` ran inside the transaction, so a throwing listener rolled back a completed transition. Dispatch is now isolated in `WorkflowEngine::record()` and degrades to a logged warning. Delivery is best-effort **per recipient**: one unreachable mobile must not stop the next person being told.

### AD-13 — Every external system sits behind a Provider, flagged off by default

**Decided:** 2026-08-13, before M5.

Interface + Mock + Real, bound by a `FEATURE_*` flag. Enabling one requires environment variables, credentials and the existing registration — nothing else. **The application is fully demonstrable with every integration off**, and a test asserts it.

**Consequence, deliberately asymmetric.** A Mock that fabricates data is worse than no Mock: `MockHrmsProvider` returns nothing rather than a plausible fake officer, and **production refuses to sign** rather than falling back to a development signature. A promotion order carrying a mock signature would look valid while being legally worthless.

The same pattern was applied to something that is *not* an external system — `DocumentRenderer` — so PDF can later replace HTML without touching the approval path.

### AD-14 — Approval creates the decision; documents render it

**Decided:** M7 Phase 4.

`ProceedingDocumentService` has no dependency on `ResultApprovalService`, asserted by a reflection test so the separation cannot rot.

**Consequence.** A rendering failure cannot damage a decision already taken, and a template change cannot require re-approval. A failed generation is a **first-class event**, not a log line: an approved DPC with no document is exactly what a subscriber should notice.

---

## Process

### AD-15 — Freezes, and freeze verification

**Decided:** M4 (architecture), M5 (engine), M6. **Verification added 2026-08-13.**

A frozen milestone accepts bug fixes, security fixes, performance work, VAPT findings and hardening — never features, workflow changes, schema redesign or refactoring.

**Consequence, and the part that was missing for a month.** A freeze is a claim about a **commit**. Until `v0.6.0-m6-complete` existed there was no commit at any milestone boundary, so no breach could be detected by diff. `git diff v0.6.0-m6-complete -- app/Domain/{Dpc,Eligibility,Cgpsc}` now runs at every phase boundary and has never been non-empty.

**What it forecloses.** Several small improvements are visibly blocked by it — `HasUuids` on `DpcProposal` (TD-19), a shared notification recipient resolver (TD-20), the engine version on the run row (TD-21), a shared content fingerprint (TD-16). Each was left undone rather than take the freeze quietly. They should be batched into the next legitimate opening.

### AD-16 — Migrations are additive; approved phases are not refactored without approval

**Decided:** 2026-08-13.

No migration modifies or removes a column from an approved phase. New requirements are additive.

### AD-17 — The gate is necessary, not sufficient

**Decided:** M4 Phase 7, after the Demo Readiness Rule caught three P1s that six green gates had missed.

Every phase additionally carries Security, Performance, VAPT and full-PPT reviews, and a 14-point demo-readiness check.

**Consequence, stated because it keeps being re-proved.** Every significant defect in this project was found by applying a discipline, not by running the gate: the Demo Readiness Rule, a directive implemented properly, a full PPT comparison, or an assertion about an *outcome* rather than a rendering. All of them passed Pint, PHPStan, PHPUnit and `composer audit` while broken.

### AD-18 — Silent failures are converted into loud ones

**Decided:** M7 Phase 4.

`Model::preventSilentlyDiscardingAttributes()` is enabled outside production.

**Consequence.** Twice in M7 a newly added column failed to reach its model: `update()` discards non-fillable attributes and **reports success**, so the write vanished with no error anywhere and surfaced much later as a puzzling assertion failure. Deliberately not enabled in production — a request that would have half-worked should not become a 500 in front of a user.

---

## Maintaining this file

- A decision goes here when it **constrains future work**, not merely when it is made.
- Record the **consequence**, including what it costs. A decision whose cost is unwritten gets re-litigated by whoever next finds it inconvenient.
- Superseding a decision means adding an entry that says so — never editing the original.
- Business rules belong in `docs/PENDING_BUSINESS_DECISIONS.md`; known imperfections in `docs/TECHNICAL_DEBT.md`.
