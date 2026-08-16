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


### AD-19 — Stored rules are re-applied through the configuration mechanism, not a second code path

**Decided:** M8 Phase 2, implementing AQ-11.

A Review DPC must calculate under the **original's** rules. `DpcCalculationService` calls `RuleSet::fromConfig()` internally and accepts no rule set, and M5 is engine-frozen.

The seam was already there. `fromConfig()` and `fromSnapshot()` share one `build()`, so a stored `configuration_snapshot` has exactly the shape of `config('dpc.scoring')` — and under **AD-3**, business rules *are* configuration. Running under the original's rules therefore means running under the original's configuration: set it, calculate, restore it in a `finally`.

**Consequence.** No second scoring path exists, which is the point — a parallel "historic rules" implementation would be a second engine to keep correct. The cost is that configuration is process-global: it is restored on every exit path, and the residual risk under a concurrent worker is recorded as **TD-22**. `engine_version` is deliberately preserved across the override, because the rules are the original's but the engine doing the work is this build.

### AD-20 — Behaviour is added to a completed milestone by decoration, never by edit

**Decided:** M8 Phase 2.

M8 needed M7's calculation service to behave differently for a Review. `ReviewAwareCalculationService` extends it and is bound over it in the container; M7's file is untouched.

**Consequence.** `git diff v0.7.0-m7-complete -- app/Domain/Proceeding` stays empty while M7's behaviour is genuinely extended — which is what makes the freeze check meaningful rather than a formality. The cost is indirection: the class that runs is not the class the caller names, and a reader following `ProceedingCalculationService` will not see the Review behaviour until they check the container binding. Mitigated by a test asserting which implementation resolves, so the binding cannot be removed silently.

This is now the standing pattern for extending a complete milestone. It is **not** a licence to avoid the freeze process for changes that belong inside the frozen code — a bug in M7 is still fixed in M7, with approval.

---

## Process

### AD-15 — Freezes, and freeze verification

**Decided:** M4 (architecture), M5 (engine), M6. **Verification added 2026-08-13.**

A frozen milestone accepts bug fixes, security fixes, performance work, VAPT findings and hardening — never features, workflow changes, schema redesign or refactoring.

**Consequence, and the part that was missing for a month.** A freeze is a claim about a **commit**. Until `v0.6.0-m6-complete` existed there was no commit at any milestone boundary, so no breach could be detected by diff. `git diff v0.6.0-m6-complete -- app/Domain/{Dpc,Eligibility,Cgpsc}` now runs at every phase boundary and has never been non-empty.

**What it forecloses.** Several small improvements are visibly blocked by it — `HasUuids` on `DpcProposal` (TD-19), a shared notification recipient resolver (TD-20), the engine version on the run row (TD-21), a shared content fingerprint (TD-16). Each was left undone rather than take the freeze quietly. They should be batched into the next legitimate opening.

### AD-21 — M8 Freeze (Review DPC)

**Declared:** 2026-08-14 by architect decision. **Status: ACTIVE.** **Baseline:** `v0.8.0-m8-phase3` / `a60689d`.

M8 (Review DPC) is frozen. The rules, as declared:

| # | Rule |
|---|---|
| **1** | **No production code changes inside M8.** |
| **2** | **Only bug fixes, and only with Architect approval.** |
| **3** | **Documentation may continue.** |
| **4** | **M9 development must not modify the M8 implementation.** |
| **5** | **Any future change requires a formal Freeze Exception.** |

**Verification.** `git diff --name-only v0.8.0-m8-phase3 -- app/Domain/Review` must be empty, alongside the existing checks for M4/M5/M6 (`v0.6.0-m6-complete`) and M7 (`v0.7.0-m7-complete`). M8's decorators, its four tables and its views are all reachable from `app/Domain/Review`, `resources/views/dpc/reviews`, `resources/views/documents/review-context.blade.php`, `resources/views/documents/superseded-watermark.blade.php` and `resources/views/partials/review-banner.blade.php` — the view paths matter, because M8's UI deliberately lives outside M4's frozen screens (Phase 1, Directive 3) and a diff scoped to `app/` alone would miss them.

**This freeze is stricter than AD-15's**, and the difference is deliberate rather than accidental. Under AD-15 a frozen milestone accepts bug fixes, security fixes, performance work, VAPT findings and hardening **without per-change approval**. M8 accepts **bug fixes only, each individually approved**, and anything else needs a Freeze Exception. Read together with AD-15, M8 is the most tightly held milestone in the project.

**Consequence.** Rule 4 is the one that will be tested first. M9 (Circulation DPC) resolves a held promotion from an earlier DPC and faces the same supersession question M8 raised; the temptation will be to reach into M8 rather than decorate it. **AD-20 is the answer** — M9 extends M8 the way M8 extended M7, and rule 4 plus the diff check is what makes that non-optional.

**What it forecloses, stated as AD-15 requires.** Nothing on the debt register is inside M8 except **TD-22**, which is already open by directive and is not to be solved before concurrent workers exist. **TD-23** is a test change and **TD-24** a CI change; neither touches M8 production code, so neither needs an exception. The freeze therefore costs nothing today — which is the right moment to declare one.

**Rule 5's mechanism is defined in AD-22**, added 2026-08-14 — while no exception was pending, which was the point. Until then rule 5 required a Freeze Exception that did not exist, so no change to M8 was possible by any route. AD-22 applies to all five freezes, not only this one.

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

### AD-22 — The Freeze Exception mechanism

**Decided:** 2026-08-14, closing the gap **AD-21 rule 5** opened by requiring "a formal Freeze Exception" without defining one.

**Scope: governance only.** This defines how a frozen milestone is opened, recorded and re-closed. It settles no business rule, changes no code, and grants no exception.

**It applies to every freeze in force** — M4 (AD-15, architecture), M5 (engine), M6, M7 and M8 (AD-21) — not only to M8. A single mechanism is the point: five freezes with five procedures is how one of them quietly stops being enforced.

#### 1. Who approves

**The Architect, and no one else.** A Freeze Exception is the only route through a freeze, so the authority to grant one is the authority that declared it.

**No delegate is defined, deliberately.** Naming a stand-in would invent an organisational structure this project has not stated. **The consequence is real and should be understood rather than discovered:** while the Architect is unavailable, no frozen milestone can be changed for any reason — including a production security incident. If that is not acceptable, the remedy is a stated delegate or a standing pre-authorisation for a named class of change, and either is an architect decision, not one to be assumed here.

#### 2. When it may be requested

**Before the work starts. Always.**

An exception requested after the code is written is not an exception; it is a request to ratify a breach, and approving one teaches that the freeze is negotiable after the fact. The freeze diff is what makes the boundary checkable, and it cannot distinguish "approved beforehand" from "explained afterwards" — only the record can, and the record must therefore precede the work.

A request is admissible when **all** of the following hold. They are deliberately narrow:

| | Condition |
|---|---|
| **a** | The change cannot be made **outside** the frozen milestone. Decoration (**AD-20**) is the first thing to rule out — M8 extended completed M7 with no M7 file touched, and that is the standing pattern |
| **b** | It is a **defect, security, performance, VAPT or hardening** change — never a feature, workflow change, schema redesign or refactor (**AD-15**). A freeze that admits features is not a freeze |
| **c** | The frozen milestone is **committed and tagged**, so "unchanged" is a checkable claim rather than an assertion |

**Two situations are explicitly not exceptions, because treating them as such would inflate the mechanism until it means nothing:**

- **Documentation.** Permitted outright under AD-21 rule 3, and the equivalent has always been true of the earlier freezes.
- **Tests and CI.** Changing a test, a fixture or a gate step touches no production code inside the frozen boundary. **TD-23** (drive M8's end-to-end test through M4's real `submit()`) and **TD-24** (run the suite against MySQL) are therefore ordinary work needing no exception — which matters, because TD-24 has already cost a P0 and must not sit behind a governance queue.

**The emergency case is not carved out, and that is a decision rather than an oversight.** A production security incident inside a frozen milestone still requires an exception, because the alternative is an undefined path that gets used under pressure and documented afterwards, if at all. What the mechanism owes such a case is **speed**, not a bypass: a request is five fields, and §7's record can be completed after the fix ships provided the approval itself preceded it.

**One reading needs the Architect's confirmation and is not settled here.** AD-21 rule 2 admits "only bug fixes with Architect approval", while AD-15 lets the other four freezes take security, performance and hardening work without per-change approval. On a strict reading a **security** fix in M8 is not a "bug fix" and needs a full exception, making M8 harder to patch than M4. That may be intended. **Recorded as an open question rather than resolved by interpretation**, because reading a rule broadly to make it convenient is how a freeze erodes.

#### 3. What must be recorded

Five fields, before approval:

| Field | Content |
|---|---|
| **Milestone and baseline** | Which freeze, and the tag it is measured against — e.g. M8 at `v0.8.0-m8-phase3` |
| **What and why** | The defect or finding, and the evidence for it. A reproduction, a failing test, a VAPT finding, or a stated risk |
| **Why it cannot be done outside the freeze** | Explicitly why **AD-20** decoration does not solve it. This is the field that does the work: most requests should fail here |
| **Blast radius** | The files and tables expected to change, named in advance — so the freeze diff after the work can be compared against what was authorised, not merely inspected |
| **Scope** | Single change, window, or milestone — see §4 |

#### 4. Scope of an exception

| Scope | Ruling |
|---|---|
| **Single change** | **The default, and what "exception" means.** It authorises one named change against one baseline, and it closes when that change is committed |
| **Time window** | **Permitted, but only with an explicit expiry and a stated purpose** — e.g. batching TD-19, TD-20 and TD-21 into one opening, which §8 has recommended since M6. It expires on the stated date whether the work is finished or not; unfinished work needs a fresh request. **A window without an expiry is a revoked freeze that still calls itself frozen** |
| **Milestone-wide** | **Not an exception. That is unfreezing the milestone**, and it requires a new architect decision superseding the freeze in this register — not an entry in the exception log |

**A window is the higher-risk form**, because it is open when nobody is watching it. Any window should therefore name its own end date and the specific items it covers, and both belong in the record.

#### 5. Gates after the work

**All five, plus freeze verification. Without exception, and re-run rather than inherited:**

Laravel Pint · PHPStan (Larastan level 6) · PHPUnit · `composer audit` · `php artisan migrate:fresh --seed` · the freeze diff for **every** milestone under freeze, not only the one opened.

**Every milestone, because a change inside one freeze is exactly the kind of change that reaches into another.** This is not hypothetical here: the M8 gate run of 2026-08-14 found a **circular container dependency** among decorators spanning M7 and M8 that no single-milestone check would have located, and a **migration that could not run on MySQL** while 84 tests passed on SQLite.

**And the freeze diff must be compared against §3's declared blast radius**, not merely read. A non-empty diff is expected after an approved exception — that is the point of granting one. What must be verified is that it contains **only** what was authorised. A diff nobody compares to a prior claim tells you a file changed, not whether it should have.

**The exception is not complete until the gate is green.** Approval authorises the work; the gate closes it.

#### 6. Does the freeze re-arm

**Yes. Automatically, and without a further decision.**

An exception suspends the freeze for exactly what §4 defined and no longer. On the last commit of the authorised work the freeze is **fully active again at a new baseline**: a new tag is cut, and every subsequent freeze diff is measured against it. **The old baseline is not reused** — the milestone has legitimately changed, and continuing to diff against a superseded tag would report an authorised change as a breach for the rest of the project's life.

**A window expires on its stated date whether or not the work finished.** Nothing re-arms it, because nothing disarmed it beyond that date.

#### 7. Audit record

**The record lives in this file, in the register below** — not in `docs/ARCHITECT_REVIEW.md`, which is **replaced in full at every phase**. That distinction is the reason `TECHNICAL_DEBT.md` and this file exist at all: a decision recorded only in the review section survives exactly one prompt.

Three artefacts, and only the first is prose:

1. **A register entry here**, added when the exception is **approved** and completed when the gate closes it.
2. **A commit trailer** on every commit made under the exception — `Freeze-Exception: FE-<n>` — so the authorisation is reachable from the code, by `git log --grep`, by anyone who never reads this file.
3. **A tag at the new baseline** (§6), which is what the next freeze diff measures against.

**Git is the tamper-evident part; the prose is the reasoning.** A register entry can be edited afterwards. A commit trailer and a tag are what an auditor can check independently, years later, against a milestone whose outputs may be contested in CAT or the High Court — which is the standard this system is held to.

#### The Freeze Exception Register

**No exception has been requested or granted.** M4, M5, M6, M7 and M8 are all unopened.

| # | Milestone / baseline | What | Scope | Approved | Gate green | New baseline |
|---|---|---|---|---|---|---|
| — | *(none)* | | | | | |

**Consequence.** The mechanism costs a request and a gate run — deliberately more than a code change and deliberately less than a milestone decision. The cost is the point: it should be cheaper to decorate (AD-20) or to wait for a batched window than to open a freeze, so that opening one stays rare enough to mean something.

**What it forecloses**, as AD-15 requires this register to state: nothing that was previously possible. Before AD-22, AD-21 rule 5 required a mechanism that did not exist, so *no* change to M8 was possible by any route. This is strictly an unblocking decision — and the first request it is likely to receive is the batching of **TD-19, TD-20 and TD-21** against M4, M5 and M6, which §8 of the review has recommended since M6 and which is a **window**, not a single change.

---

## Maintaining this file

- A decision goes here when it **constrains future work**, not merely when it is made.
- Record the **consequence**, including what it costs. A decision whose cost is unwritten gets re-litigated by whoever next finds it inconvenient.
- Superseding a decision means adding an entry that says so — never editing the original.
- Business rules belong in `docs/PENDING_BUSINESS_DECISIONS.md`; known imperfections in `docs/TECHNICAL_DEBT.md`.
