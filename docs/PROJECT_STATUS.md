# DPC Project — Status

**Last updated:** 2026-08-18. This is the single page to read first when returning to the project. Full detail: `docs/PROJECT_PROGRESS.md`. Pending decisions/findings: `docs/ARCHITECT_REVIEW.md`. Business questions: `docs/PENDING_BUSINESS_DECISIONS.md`, put to business users in `docs/UAT_BUSINESS_VALIDATION.md`. Known limitations & deferred items: `docs/TECHNICAL_DEBT.md`. Architecture rulings: `docs/ARCHITECTURE_DECISIONS.md`. Per-milestone summary: `docs/MILESTONE_SUMMARY.md`. History: `docs/PROJECT_TIMELINE.md`. Workflows: `docs/WORKFLOWS.md`. Business decisions: `docs/PENDING_BUSINESS_DECISIONS.md`. Integrations: `docs/INTEGRATION_ROADMAP.md`. UAT: `docs/UAT_CHECKLIST.md`, `docs/DEMO_SCRIPT.md`. History: `docs/CHANGELOG.md`. Source of truth for business rules: `docs/ODPC_NEW_FINAL.pptx` + `docs/CONVENTIONAL DPC PROCESS.pptx`.

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
| ✅ | **M8 Phase 3 Complete** — supersession, Review documents, `SUPERSEDED` watermark, publication |
| ✅ | **M8 Complete (Frozen)** — Review DPC, all 3 phases. **M8 FREEZE ACTIVE from 2026-08-14** (AD-21). BD-11 deferred — it does not block M8 completion |
| 🟢 | **M9 CLOSURE-READY — awaiting business approval only (confirmed 2026-08-18).** Circulation DPC, all three phases. Engineering, documentation, quality gates and milestone deliverables are **all complete and re-verified today**; **BD-14 is the sole blocker.** Governed by **AD-23**, **AD-24** and the **AQ-22** ruling. Readiness record and the six closure steps: `docs/ARCHITECT_REVIEW.md` **§0.13.1**; checklist §0.13 — items 1–19 green, 20–23 consequences of BD-14. **Not closed, not tagged, not frozen** |

(M0 Foundation is complete and predates M1.)

**On "UAT Ready" being amber rather than green:** the pipeline runs end to end and UAT can begin, but **BD-2/BD-3 (reserved relaxation) is unsettled**, so any DPC where a reserved category has fewer eligible officers than vacancies returns a result the system marks **provisional**. That is correct behaviour — it declines to guess — but a provisional result cannot be published, so business sign-off on the calculation is partial until the rule is settled.

**On M7 — COMPLETE (closed 2026-08-14).** All four phases delivered and approved: the proceeding lifecycle, the S33 editable workspace with append-only corrections, `CALCULATE` against the frozen M5 engine with stale-calculation protection, the S34 result, `APPROVE BY CGPSC`, `CANCEL DPC`, held promotions (`CIR`), and the proceedings document and promotion list rendered, stored, signed and published — 169 tests. **No file in M4, M5 or M6 was modified**, verified by full-repository diff at every phase boundary.

**On M8 — COMPLETE (2026-08-14).** A Review DPC has an origin, a chain (`chain_uuid`, `review_number`, `chain_depth`, the original's version), coded reasons and a chart copied from the original — with the original never touched. It runs the full M6/M7 pipeline, calculates under **the original's configuration snapshot** (AQ-11), and on approval **supersedes** the DPC it corrects: recorded on both timelines, notified to the department, and shown on the superseded DPC's documents as a `SUPERSEDED` watermark **applied on delivery so the stored artefact and its hash stay exactly as issued**. 83 feature tests. **BD-11 is deferred and blocks nothing** — a **Deferred Business Decision — Required before production activation of the Review supersession policy** (architect ruling 2026-08-14). The system records the supersession and tells the department; it asserts nothing about the promotion itself, and must not until CGPSC rules.

**M8 is complete; four business questions remain open and none of them blocks it.** Four carried-forward questions change what a published promotion list says: **BD-2** and **BD-3** (reserved relaxation), **BD-9** (which waiting list an officer joins when their category has no vacancy) and **BD-10** (what a pending court case does). They constrain what may be *published*, not what is built, and **M7 is not reopened for them** (architect ruling): a result affected by BD-2/BD-3 or BD-10 is marked provisional and cannot be published. **BD-9 is the exception and the one to chase** — it raises no flag, so it can produce a confidently wrong published list rather than a blocked one. BD-9 and BD-10 were found by comparing the system's output against the customer's own worked answer on slide 34, row by row. All are put to business users in plain language in `docs/UAT_BUSINESS_VALIDATION.md`. **M5 is not to be modified for BD-9** (architect ruling) — it is raised to CGPSC instead.

### Repository baseline

Tagged **`v0.7.0-m7-complete`** / `6f52f71` on branch `feature/m0-setup`, 2026-08-14 — M7 complete, pushed. The previous baseline `v0.6.0-m6-complete` / `c2deae4` remains the reference for the M4/M5/M6 freezes.

**Freeze verification runs at every phase boundary** and has never been non-empty:

```
git diff v0.6.0-m6-complete -- app/Domain/{Dpc,Eligibility,Cgpsc}   # M4/M5/M6
git diff v0.7.0-m7-complete -- app/Domain/Proceeding                # M7, from M8 onward
git diff v0.8.0-m8-phase3  -- app/Domain/Review                     # M8, from M9 onward
```

**The M8 check must also cover its views**, because M8's UI deliberately sits outside M4's frozen screens: `resources/views/dpc/reviews/`, `resources/views/documents/review-context.blade.php`, `resources/views/documents/superseded-watermark.blade.php` and `resources/views/partials/review-banner.blade.php`. A diff scoped to `app/` alone would miss them.

Until the first of these tags existed there was no commit at any milestone boundary, so no freeze breach could have been detected by diff (**TD-14**, closed).

**The branch name `feature/m0-setup` is eight milestones stale** and describes none of what it contains. Renaming it is a one-line change but rewrites the remote ref, so it is left for a decision rather than done silently.

### Previously

**M4 — DPC Proposal (detailed): COMPLETE.** All 7 phases shipped 2026-08-13: Post Selection (S14), Committee (S15), Working Officers + Vacancies (S16), Seniority List (S17), Evaluation/Tabulation Chart (S18), Excel Import (S16–S18), and Closeout (S19–S25 AH review + single approval). Stopped per instruction to wait for architect sign-off before M5.

**M4 is ARCHITECTURE FROZEN** (ruled 2026-08-13): bug fixes, security fixes, performance improvements, VAPT findings and production hardening only. No new features, workflow changes, schema changes, or refactoring. Enhancements go to a future milestone or the M11 Enhancement Backlog.

**Feature Flags & Provider Pattern established** (2026-08-13, before M5): every external integration is switched by a `FEATURE_*` environment variable and defaults to **off**. The whole M4 workflow — draft through signed approval — is tested to run with every integration disabled. Live state is visible to the CGPSC Admin at `/admin/integrations`.

**Demo Readiness Rule adopted** (2026-08-13, permanent): a phase is complete only when independently demoable to the customer — 14-point checklist, verified for M4 in `docs/ARCHITECT_REVIEW.md`. Applying it caught **three P1 defects that six consecutive green gates had missed** (an empty proposal could be submitted and signed; the signed document contained only a header; the audit trail had never been tested anywhere in the project). All three are fixed and covered.

**Roadmap update:** a **UAT Readiness Review** checkpoint was inserted between M6 and M7 in `docs/design/05-milestones.md` (approved 2026-08-13) — the full proposal → evaluation → allotment pipeline gets demoed to business users before the higher-stakes proceeding/legal-output work (M7+) begins. M4's own phasing is unchanged.

## Overall Progress

**~91% by milestone count** — M0 through **M9 Circulation DPC** are built. Ten of eleven milestone slots; **M10 (Self-Inspection & Objection)** and **M11 (Reports, Audit UI, Hardening)** remain. Two milestones are built but not closed for business reasons only: **M8 pending BD-11** (deferred) and **M9 pending BD-14**, which blocks publication of a circulation note and nothing else.

This is a milestone-count estimate, not effort-weighted. M4 and M5 were the two largest and are both frozen. M8 was the smallest of the workflow milestones by new code and the largest by leverage — it added **no** new workflow, engine, renderer or proceeding.

## Completed Modules

- ✅ Foundation & platform, Authentication & RBAC, Department Registration, Master Data & Rule Configuration, User Management, Pre-DPC Proposal
- ✅ **DPC Proposal (M4)** — all five sections, Excel import, AH review walkthrough, single approval with full-proposal digital signature; every section immutable outside DRAFT/RETURNED at Policy and Service layers; all three officer snapshot tables carry the same standard ten fields
- ✅ **Excel Import** — staged Preview → Validation → Error Report → Commit pipeline; imports reuse the manual-entry service and validation layers, so neither path can drift
- ✅ **Scoring & Eligibility Engine (M5)** — engine frozen; runs addressed by UUID only, immutable, recalculation creates a new run
- ✅ **CGPSC Workflow (M6)** — allotment, re-allotment, acceptance, scheduling, re-scheduling; append-only, state derived from the current allotment
- ✅ **DPC Proceedings & Results (M7)** — the S33 workspace with append-only corrections, `CALCULATE` with stale-calculation protection, the S34 result, `APPROVE BY CGPSC`, `CANCEL DPC`, held promotions (`CIR`), proceedings document and promotion list rendered, stored, signed and published
- ✅ **Review DPC (M8) — engineering complete** — origin and correction chain, coded reasons, copy-on-create, calculation under the original's configuration snapshot, full M6/M7 pipeline reuse, supersession at approval, Review documents stating what they correct, `SUPERSEDED` watermark on delivery, publication
- ✅ **External Integration Architecture** — every integration behind a `FEATURE_*` flag (default off) and the Provider Pattern
- 🟡 CSC DigiSign (Mock working; real CSC integration pending CGPSC's API — BD-4)
- 🟡 Notifications (mechanism + OTP delivery working; live SMS gateway not connected — TD-6)
- 🟡 Audit trail (owen-it audit + Activity Timeline live; no browser UI yet — scoped to M11)

## Current Work

**2026-08-18 — Pre-M10 verification sweep. No milestone opened or closed; all five freezes verified empty. Suite 759 → 782.**

A six-item client brief was answered by **verification, not implementation**: four of the six were already built. Full findings in `docs/ARCHITECT_REVIEW.md` §0. What matters at this level:

- **One genuine gap in the Recruitment Rule screen** — `DR % + PR %` had no total validation. Now enforced in the master **and** in the Pre-DPC that projects into it, write-path only so no stored Government Order is rewritten. Labels and widths fixed on both screens.
- **Four role-based UI defects fixed**, all at entry points rather than in the workflow screens: the AH was offered *+ New Proposal* on both the DPC list (403) and the Pre-DPC list (**worse — the backend allowed it, producing a draft the AH could neither edit, submit nor cancel**); Members saw the Chairman's allotment tile and counter; and *Open Workspace* appeared to a re-allotted Member who could not open it. **No backend check was weakened** — 14 new tests assert both halves of every case.
- **Review DPC (PPT S35–S37) had no UI entry point.** Delivered in M8 with 83 tests, and unreachable from any screen. Now linked from the DPC list behind the same Policy.
- **Excel upload (S16/S17/S18) needed no work** — implemented since M4 Phase 6, all three templates, staged Preview → Validation → Error Report → Commit.

**Later the same day — the Pre-DPC screen became master-sourced.** Post Name, Feeder Cadre and Minimum Qualification are now dropdowns from the Cadre and Qualification masters, and the feeder grid — which had **no header row at all** — has five labelled columns. Two of the three foreign keys already existed and were unused by the form; only `qualification_id` is new. Backward compatible by construction: `PreDpcWorkflowTest` passes unmodified and `demo:seed` rebuilds with all seven DPC IDs unchanged. Suite **782 → 798**. **⚠ Raised as BD-18:** department registration creates no cadres and the Pre-DPC used to create them by name, so **a newly registered department can no longer file one** until a CGPSC Admin populates its master. Existing departments are unaffected; the screen says so and disables the form rather than appearing broken.

**⚠ The finding that needs a decision: `dpc_proposals` references neither a Pre-DPC nor a Recruitment Rule.** `SaveDpcProposalRequest` never checks that a rule links `post_from` to `post_to`, so **a department can create, sign and forward a DPC for a promotion path no approved Pre-DPC ever sanctioned.** Raised as **AD-25 (proposed)** and **BD-16**; it needs a **Freeze Exception under AD-22** against M4 and was deliberately **not** implemented. **Recommended before M10 opens** — M10's self-inspection screen would otherwise render an authoritative view of a proposal with no Government Order behind it. **BD-17** additionally asks what standing the SRS now has, since AD-1 says it must not be implemented from.

---

**M8 — Review DPC: COMPLETE (all three phases, 2026-08-14).** BD-11 is a **Deferred Business Decision — Required before production activation of the Review supersession policy** and, by architect ruling of 2026-08-14, **does not block M8 completion**.

A Review DPC has an origin, a chain (`chain_uuid`, `review_number`, `chain_depth`, the original's content fingerprint) and coded reasons; the chart arrives pre-filled and **the original is never touched**. It runs the full M6/M7 pipeline — allotted, accepted, scheduled, opened, calculated and approved exactly as a Fresh DPC, which is what S35's *"exactly the same process"* promised. It calculates under **the original's configuration snapshot**, never today's rules (AQ-11). On approval it **supersedes** the DPC it corrects (AQ-9): recorded on both timelines, notified to the department with both DPC codes, and shown on the superseded DPC's documents as a `SUPERSEDED` watermark **applied on delivery, so the stored artefact and its hash stay exactly as issued**.

**83 feature tests for M8. No file in M4, M5, M6 or M7 was modified** — verified by full-repository diff at every phase boundary, and notable because M8 changes how M7 approves a result, renders a document and serves one. It does that with **four decorators** bound over M7 in the container (**AD-20**), each returning the parent's behaviour untouched for a Fresh DPC, each with a test asserting so.

**Phase 3 specifically** delivered supersession taking legal effect at approval, the Review documents' provenance block (AQ-13), the `SUPERSEDED` watermark, the derived configuration-snapshot UUID, and publication — 19 new tests.

**BD-11 is deferred, not outstanding work.** Architect ruling, 2026-08-14: what supersession means for an officer already promoted under the reviewed DPC is a **Deferred Business Decision — Required before production activation of the Review supersession policy**, and it does not block M8 completion. Nothing in M8's engineering scope waits on it.

**What the deferral does constrain.** Until CGPSC rules, the system records the supersession, notifies the department with both DPC codes, and **asserts nothing about the promotion itself** — no withdrawal, no reverted rank, no arrears. That behaviour is correct and shippable, but it is not a *policy*. **The ruling is required before the Review supersession policy is activated in production**, because the three plausible answers — the promotion stands and only the paper is corrected; it is withdrawn from the date of the Review; it is withdrawn retrospectively with recovery of pay — differ in what they do to a serving officer's substantive rank and pay. See `docs/ARCHITECT_REVIEW.md` §7.

**M9 Phase 1 is complete (2026-08-16).** The circulation aggregate and the department's side: a note raised against a concluded DPC with promotions still held, the officers and their resolution evidence, AO submit → AH approve with signature, and its own workflow, policy and identifier. **A Circulation is its own aggregate and is not a DPC proposal (AD-24), and it performs no DPC execution (AD-23)** — 46 tests, and an architecture check that enforces both. **CR-ground holds are refused with a structured *Pending Business Decision (BD-15)* response**: detected, recorded, and neither calculated nor escalated. **Phase 2 is complete (2026-08-16).** The Commission's side: a decision per held officer, `APPROVE BY CGPSC`, return-for-evidence, and the signature — **which is the operative act (AQ-17)**. Holds are released inside the signature transaction and only for officers consented to; a note that is never signed leaves every promotion reserved. **Only the Member who conducted the original DPC may act (AQ-20), and the Chairman is not exempt.** S48's before-and-after is **one stored result rendered twice**, with the `CIR` annotation from hold state — a test asserts the run, results, rows and proceeding are byte-identical after signature. **Phase 3 is complete (2026-08-16).** The prescribed form S45 calls *निर्धारित प्रारुप*: the circulation note and the revised promotion list, rendered → stored → SHA-256 → signed → verifiable, with download, verification and full document history. **M9 owns its document type, table and renderer (AQ-22)** — M7's `DocumentType`, `ProceedingDocumentService`, renderer and views are untouched, verified by diff.

**Publication is refused while BD-14 is unresolved (§23.1)**, displaying **"Pending Business Decision (BD-14)"**, inventing no effective date, and leaving rendering, signing, storage and verification fully working. The note states the origin's promotion-as-on year *and* the release date, and **asserts neither as governing** — that is BD-14. The answer will be applied as **configuration** (`dpc.circulation.effective_date_basis`), with no code change.

**M9 is CLOSURE-READY. Confirmed 2026-08-18 against the running repository, not carried forward.**

| Confirmation | Result |
|---|---|
| Engineering complete | ✅ Nothing in M9's scope is unbuilt or untested |
| Milestone documentation complete | ✅ Five mandatory documents synchronised; they agree with each other and with the code |
| Quality gates complete | ✅ **798 / 2404** · Pint pass · PHPStan L6 **0 errors** · `composer audit` clean |
| Milestone deliverables complete | ✅ Checklist items 1–19; **all five freeze diffs empty** |
| Waiting only on business approval | ✅ **BD-14 alone** |

`ARCHITECT_REVIEW.md` **§0.13.1** holds the readiness record and the **six closure steps** to run once BD-14 is answered — apply the configuration, confirm publication, re-run the gate, tag `v0.9.0-m9-complete`, declare the M9 freeze, synchronise the documents. **Step 1 is a configuration value, not code.**

**Closure is pending BD-14** — the same shape as M8's closure pending BD-11, with one difference worth stating: BD-11 was ruled not to gate M8, whereas **BD-14 does gate M9**, because it decides what a published circulation note says. **No engineering task, test, migration or document remains in M9's scope.** **M9 is currently the only built milestone with no freeze baseline**, so "has M9 changed?" is the one such question that cannot be answered by diff — which is what closure step 5 fixes.

**All freezes hold, and there are now five.** M4 architecture-frozen, M5 engine-frozen, M6 frozen, M7 frozen from M8 onward, and **M8 frozen from 2026-08-14 (AD-21)** — the strictest of the five. Freeze verification runs at every phase boundary and has never been non-empty.

## Next Milestone

**M9 — Circulation DPC (S45–S48, C3, C7). Design approved 2026-08-16; ALL THREE PHASES COMPLETE. Closure pending BD-14.**

M9 resolves a held promotion (`CIR`) from an earlier DPC — an officer missing 2+ years of CR or 1+ years of immovable-property declaration is included in the proceeding but their promotion decision is held, and CGPSC later signs a circulation note to finalise it. M7 already produces the held rows; M9 acts on them.

**Two things remain to be settled, now for Phases 2 and 3 rather than for Phase 1:**

1. **BD-11.** M9 faces the same supersession question M8 raised, from the other direction — a circulation note finalises a promotion within a DPC that could itself later be reviewed. BD-11's answer informs the design. *(BD-11 gates no milestone — neither M8's completion nor M9's start. It gates production activation of the supersession policy, and M9 inherits the same deferral.)*
2. **Q12** — the circulation-trigger wording ambiguity ("2+ years CR missing" vs. "CR ≤ 4"), the same ambiguity that affects the eligibility engine.

**Recommended before M9, at the M8 closure boundary:** one authorised freeze opening to batch **TD-19, TD-20 and TD-21** (see `docs/ARCHITECT_REVIEW.md` §8). All three are small, well-understood changes blocked only by AD-15, and TD-20 now has three copies of the same query.

**Before production, not before UAT:** a government SMS gateway (`TD-6` — without it OTP delivery fails, so no officer can complete verification), the evidence-table permission grants (`TD-1`), and a capacity plan for permanently retained import files and signed documents (`TD-8`).

**Remaining roadmap:** M9 (Circulation DPC) → M10 (Self-Inspection & Objection) → M11 (Reports, Audit-trail UI, Security & Performance Hardening) → **UAT & Security Hardening sprint** → production deployment.

## Open Architect Decisions

**Engineering questions** are in `docs/ARCHITECT_REVIEW.md`: whether to batch the three freeze-blocked debt items at M8's boundary, whether `legal_stay` carries forward into a Review, and whether `ARCHITECTURE_BASELINE_v0.7.md` should record that the decorator ceiling was reached at four. **M8 is complete** — BD-11 is deferred, not blocking.

**Architect decisions taken in M8** are recorded permanently in `docs/ARCHITECTURE_DECISIONS.md`: **AD-19** (stored rules are re-applied through the configuration mechanism, never a second code path) and **AD-20** (behaviour is added to a completed milestone by decoration, never by edit — now the standing pattern). Both are summarised with their rejected alternatives in `docs/ARCHITECT_REVIEW.md` §4.

**Known limitations and deferred items** are in `docs/TECHNICAL_DEBT.md` — permanent, and not reset at a phase boundary.

**Business questions are in `docs/PENDING_BUSINESS_DECISIONS.md`.** Five are open, and the ones that matter for a publishable result are:

- **BD-11 — what happens to an officer already promoted under a reviewed DPC?** P1, and now a **Deferred Business Decision — Required before production activation of the Review supersession policy** (architect ruling 2026-08-14). **Blocks nothing in the build.** The three plausible answers differ in what they do to a serving officer's substantive rank and pay, so the system records the supersession and decides nothing — which is shippable behaviour but not a settled policy.
- **BD-2 / BD-3 — reserved relaxation:** automatic or committee discretion, and does relaxation to 10 apply beyond Class-I→I? A result they could have changed is marked **provisional** and **cannot be published**. This fires on Reviews too — US3's case is exactly it.
- **BD-9 — which waiting list does an officer join when their own category has no vacancy?** **The one to chase**, because it raises no flag: it can produce a confidently wrong *published* list rather than a blocked one. **M5 is not modified for it** (ruling) — it is raised to CGPSC.
- **BD-10 — does a pending court case annotate the remark?** Marks the result provisional; publication blocked.
- **BD-1 — does `PUNISHMENT` disqualify an officer?** Ruled a business rule, not an engineering one. Not implemented; current behaviour pinned by test.

While any of these is open, the engine applies the printed rule, flags every case the unruled rule could have changed, and never guesses. Flagged runs are marked provisional on screen and blocked from publication.

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
| **M7** | DPC Proceedings & Result Generation — 4 phases | **2026-08-14** | **Frozen from M8 onward.** Extended only by decoration (AD-20); bug fixes only. Verified by `git diff v0.7.0-m7-complete -- app/Domain/Proceeding`. |
| **M8** | Review DPC — 3 phases | **2026-08-14** | **FROZEN — ACTIVE from 2026-08-14 (AD-21).** No production code changes inside M8; bug fixes only, with Architect approval; documentation may continue; M9 must not modify M8; any other change requires a formal Freeze Exception. Verified by `git diff v0.8.0-m8-phase3 -- app/Domain/Review`. |

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


### M7 closure detail

Closed on architect approval of Phase 4, 2026-08-14, at tag `v0.7.0-m7-complete` / `6f52f71`. Delivered across four phases:

1. Proceeding lifecycle — opening on the scheduled date, the allottee alone conducting the sitting, the department's submission fingerprinted at opening
2. The **S33 editable workspace** — `EDIT` / `TRIBAL` / `SAVE`, append-only corrections that supersede rather than overwrite, each with a stated reason and a per-proceeding sequence, validated by M4's own row rules and never modifying M4 data
3. **`CALCULATE`** against the frozen M5 engine on the corrected chart, with stale-calculation protection by correction sequence; the **S34** Main and Waiting lists; `APPROVE BY CGPSC`; `CANCEL DPC`; held promotions (`CIR`)
4. The **proceedings document** and **promotion list** rendered, stored, signed and published; full PPT verification

**Closure evidence:** Pint clean · PHPStan (Larastan level 6) 0 errors · `composer audit` no advisories · 169 M7 tests within a green suite · `migrate:fresh --seed` OK · Security, Performance, VAPT and full S31–S34 PPT reviews completed · 14-point Demo Readiness checklist verified · **freeze verification empty at all four phase boundaries** — including Phase 3, where the freeze was most at risk because `CALCULATE` is M5's own entry point.

**Business rulings gate publication, not the build:** BD-9 and BD-10 were found by comparing the engine's output against the customer's own worked answer on slide 34, row by row. A result they could have changed is blocked from publication rather than merely flagged.

### M8 status detail — engineering complete, closure withheld

**Complete as of 2026-08-14.** All three phases are built, tested and gate-green. **BD-11 is deferred** by architect ruling and does not block completion.

1. **Phase 1** — Review proposal creation with origin and multiple coded reasons, copy-on-create, the correction chain with append-only bidirectional links, the "THIS IS A REVIEW DPC" banner, and supersession *intent* recorded with `superseded_at` deliberately null
2. **Phase 2** — calculation under the **original's configuration snapshot** (AQ-11) via the configuration mechanism M5 itself built (AD-19), added by **decoration** (AD-20); `chain_depth` distinguished from `review_number`; three chain roles; the original's content fingerprint persisted; full M6 reuse confirmed end to end
3. **Phase 3** — supersession taking **legal effect at approval** (AQ-9), Review documents stating what they correct (AQ-13), the `SUPERSEDED` watermark applied **on delivery** so the stored bytes and hash stay exactly as issued, the derived configuration-snapshot UUID, and publication

**Evidence (gate re-run in full 2026-08-14):** Pint clean · PHPStan (Larastan level 6) 0 errors · `composer audit` no advisories · **620 passed / 1902 assertions** · `migrate:fresh --seed` OK (**62 tables**, 10 seeders) · Security, Performance, VAPT and full S44 PPT reviews completed · Demo readiness verified · **freeze verification empty at all three phase boundaries** for M4, M5, M6 **and M7**.

**Corrected:** this line previously read *607 passed / 1865 assertions* and *61 tables*. Neither was reproducible — the suite was killed by a circular container dependency before it finished, and `migrate:fresh` aborted on M8's last migration under MySQL. Both are fixed (`docs/ARCHITECT_REVIEW.md` §0.3). Two P0 defects, found by running the gate rather than by reading the code.

**Four additive migrations, nothing altered or dropped:** `dpc_review_links`, `dpc_proposal_review_reasons`, `dpc_review_calculations`, `dpc_document_review_references`.

**BD-11, and what deferring it means:** it decides whether a superseded promotion is withdrawn or merely superseded on paper — a service-law question about a serving officer's substantive rank and pay, not an engineering one. Ruled a **Deferred Business Decision — Required before production activation of the Review supersession policy** on 2026-08-14. M8 ships without it; **the Review supersession policy is not activated in production until CGPSC rules.** Full statement in `docs/ARCHITECT_REVIEW.md` §7.

**M8 FREEZE — DECLARED AND ACTIVE.** Architect decision, **2026-08-14**. Baseline `v0.8.0-m8-phase3` / `a60689d`. Recorded permanently as **AD-21**.

| # | Rule |
|---|---|
| **1** | No production code changes inside M8 |
| **2** | Only bug fixes, and only with Architect approval |
| **3** | Documentation may continue |
| **4** | M9 development must not modify the M8 implementation |
| **5** | Any future change requires a formal **Freeze Exception** |

**This is the strictest freeze in the project.** M4, M5, M6 and M7 accept bug fixes, security fixes, performance work, VAPT findings and hardening without per-change approval (AD-15). M8 accepts **bug fixes only, each individually approved**, and anything else needs a Freeze Exception. All four earlier freezes remain in force alongside it.

**One thing the declaration names but does not define: the Freeze Exception itself.** Nothing in the project says who grants one, what record it leaves, or whether it reopens the freeze for a single change or for a window. Best settled while no exception is pending.

## Build Status

Permanent four-part gate (ruled 2026-08-13) — every phase must pass all four before completion. Run with `composer gate`.

**Re-run in full at M9 Phase 3 finalisation, 2026-08-16.** `composer audit` was clean on the **second** attempt; the first hit a network timeout and fell back to local cache (**TD-2**), which is recorded rather than reported as a clean first run. **Three consecutive finalisations have now needed a retry on `composer audit`** — two network timeouts falling back to a stale cache (TD-2), and one usage-output response. That is a reliability problem in the gate, not an intermittent annoyance.

*Earlier history, kept because it is the reason these figures are now re-derived rather than copied: the figures once carried here (*607 passed / 1865 assertions*, *61 tables*) were **not reproducible** — the suite could not reach its end and `migrate:fresh` could not build M8's last table on MySQL. Both causes were fixed on 2026-08-14.*

| Check | Result |
|---|---|
| Laravel Pint (PSR-12 + strict types) | **Passed** |
| PHPStan / Larastan level 6 | **0 errors** |
| `composer audit` | **No advisories** — second attempt; the first timed out to local cache (**TD-2**) |
| PHPUnit | **718 passed / 2178 assertions, 0 failing** |

Plus `php artisan migrate:fresh --seed`: **OK** — **45 migrations, 65 tables**, 10 seeders.

**Two P0 defects were found by the 2026-08-14 gate run, both in M8, both fixed:** a **circular container dependency** among the four M8 decorators, which crashed the PHP process on every document generation (and, under this environment's `memory_limit=-1`, exhausted the host — the reported machine hang); and a **foreign-key identifier one character over MySQL's 64-character limit**, which meant `dpc_document_review_references` had never existed in the database at all. The second passed 84 tests because the suite runs on in-memory SQLite, which does not enforce that limit — raised as **TD-24**, and the most valuable process fix now available.

**The gate is necessary, not sufficient (AD-17).** Six consecutive green gates once missed three P1 defects that the Demo Readiness Rule caught in an afternoon. Every phase since is checked against the PPT and demoed, not just gated.

## Test Status

**718 passed / 2178 assertions, 0 failing**, verified at M9 Phase 3 finalisation (2026-08-16). M8 contributed 83; **M9 contributed 98 — 46 in Phase 1, 26 in Phase 2, 26 in Phase 3**.

**Run bounded** (`php -d memory_limit=2G vendor/bin/phpunit`). CLI `memory_limit` is `-1` in this environment, so a runaway takes the host down instead of reporting a fault — which is exactly what the container cycle did before it was fixed. A bounded limit is how a defect of that shape names the test it died on.

Golden cases run against the *shipped* configuration, so a rule change that would alter a promotion outcome fails the build. **All 13 configurable business rules have at least one golden test** — a permanent project rule as of 2026-08-13. M8 adds no business rule of its own; it reuses M5's, which is the point of it.

**The M8 tests worth naming**, because each pins a decision that would otherwise erode:

- A later notification lowers the Class-I threshold from 15 to 14; US3, who scored 14 and was refused, **stays refused** — a Review calculates under the original's rules.
- A Fresh DPC passes straight through all four decorators and still uses **today's** rules — a decorator must be invisible to everything that is not a Review.
- A superseded document is served watermarked, its **stored bytes and hash are unchanged**, and it **still verifies**.
- Reordered snapshot keys produce the **same** configuration UUID; a changed rule version produces a different one.
- A provisional Review result **cannot be published** — and it fires in practice, because US3's case is exactly BD-2/BD-3. The companion test shows the other side: a Review that corrects the very CR it was raised for produces a clean result and publishes.
