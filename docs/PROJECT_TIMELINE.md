# Project Timeline

What happened, when, and why — the decisions that shaped the system, in the order they were taken.

**Created 2026-08-14.** **Appended at each PHASE boundary** — not at each milestone closure (changed 2026-08-18, TD-27: closure is an event that may never arrive, and M9 proved it). For *what* each milestone delivered, see `docs/MILESTONE_SUMMARY.md`; this records *when and why*.

---

## 2026-07-29 — Sources received

The two source decks arrive: `ODPC_NEW_FINAL.pptx` (52 slides) and `CONVENTIONAL DPC PROCESS.pptx` (7 slides). They become the **only** source of business requirements — a rule that has since resolved several conflicts and has never been relaxed.

## 2026-07-30 — Project reset, and the decisions that outlived it

The project is reset to Laravel 12 (now 13) + MySQL + Redis + Blade/Bootstrap, with **no Node build step**. Sixteen ambiguities (Q1–Q16) are raised from the decks; six are ruled immediately.

**Q1 — Aadhaar login replaced.** Every login screen in the deck shows Aadhaar number + Aadhaar OTP. Replaced with e-mail/mobile + password and **OTP step-up at each legally significant signing action**, logged as a record of intent. This single ruling makes a dozen later slides documented Category-B differences rather than defects.

**M0–M2 delivered.** Foundation, authentication and RBAC, master data and user management.

## 2026-08-13 — The day the discipline changed

Eight of the project's standing rules were established on this one day, and every one of them was a response to something that had just gone wrong or nearly had.

**M3 closes.** Pre-DPC Proposal — the first workflow, on the config-driven engine every later milestone reuses.

**M4 closes, across seven phases.** During its closeout the newly adopted **Demo Readiness Rule** — *a phase is complete only when independently demoable* — caught **three P1 defects that six consecutive green gates had missed**. The worst was not either functional bug: it was that **the audit trail had never been exercised by a single test since M1**, because `audit.console` was hardcoded false and PHPUnit runs in console. Every audit assertion in the suite had been inert for four milestones.

> **What it changed.** The gate is necessary, not sufficient. Every milestone since has carried mandatory Security, Performance, VAPT and full-PPT reviews alongside it.

**Feature Flags and the Provider Pattern established**, before M5 rather than after: every external integration behind a `FEATURE_*` flag, defaulting off, with business logic never calling an external system directly. A test asserts the entire M4 workflow completes with every integration disabled.

**M4 architecture-frozen.**

**M5 closes, across four phases.** The mandated full-PPT comparison at closure finds **the customer's own answer key on slide 33** — MARK and REMARK filled in for all ten officers — correcting an earlier claim of ours that no worked answer existed. The engine reproduces **9 of 10 marks exactly**. Two rows diverge; they become **BD-1** rather than being implemented.

> **What it changed.** *Never invent a business rule.* Two officers who "should" be ineligible stayed eligible, flagged, for a year rather than being quietly disqualified on an inference.

**M5 engine-frozen**, with unresolved business rules explicitly excluded from the freeze.

**A business-decisions register created** — business questions get their own home, separate from engineering review. (Moved to `docs/PENDING_BUSINESS_DECISIONS.md` on 2026-08-14.)

**M6 closes, across four phases.** Two defects are found not by testing the feature but by **implementing an architect directive properly**: a failing notification could roll back a completed workflow transition, and every timeline read was non-deterministically ordered within a second.

> **What it changed.** Directives that read as documentation tasks have repeatedly turned out to be live bugs. That observation is now recorded permanently rather than in a superseded review.

**M6 frozen. UAT documentation set produced** — integration roadmap, UAT checklist, demo script.

**`docs/TECHNICAL_DEBT.md` created.** Until then, every risk raised over six milestones lived in `ARCHITECT_REVIEW.md`, which is **replaced in full at each phase** — so each one survived only by being manually re-copied forward. The item most likely to be dropped was never a fresh P1; it was a two-milestone-old P2 nobody had thought about in a while.

## 2026-08-13 (late) — The repository baseline

`git status` reveals that the last commit is `3133ac4 M3`. **M4, M5 and M6 — three closed, frozen milestones, 133 changed paths — had never been committed**, with no stash.

The loss risk was the obvious half. The half that mattered more: **a freeze is a claim about a commit**, and with no commit at any milestone boundary there was nothing to diff a suspected breach against. Three freeze policies existed with no mechanical way to detect a breach of any of them.

**Tag `v0.6.0-m6-complete` / `c2deae4`.** From here, `git diff v0.6.0-m6-complete -- <path>` answers "has the frozen code changed?" mechanically. It has been run at every phase boundary since, and has never once been non-empty.

## 2026-08-14 — M7: design

The design is written against S31–S34. Reading the deck closely produces two findings that shape the whole milestone:

- **Slide 33 contains a *second* worked answer key.** Its three renderings of the same chart are a before/after sequence: US3 scores 14 and is `NE`; after `TRIBAL 2021` is recorded, 15 and waiting. The frozen M5 engine reproduces it exactly. That single row establishes that Commission-side correction is real and must trigger recalculation.
- **Ambiguity Q12 dissolves.** "2 or more CR years missing" and "CR ≤ 4" are the same condition stated from opposite ends, because the chart has six CR columns.

**Eight blocking questions raised (AQ-1 … AQ-8); all eight ruled.** The decisive one, AQ-1: corrections are **append-only overrides**, and M4 is never modified. The obvious implementation — update the evaluation row — was also the one that would have destroyed the evidential model.

A conflict with the archived SRS is documented rather than silently resolved: it describes per-member voting, meeting minutes and a separate promotion-order module, none of which appears in the PPT.

## 2026-08-14 — M7: four phases

| Phase | What it settled |
|---|---|
| **1** | The proceeding as a first-class record. The department's submission is fingerprinted at opening — S31's rule was already structurally enforced; what was missing was the *evidence*. Two defects found by our own security and performance reviews before the gate ran |
| **2** | Corrections recorded **beside** the department's entry, never over it. Validation reuses M4's own `DpcRowRules`, so manual entry, import and correction cannot drift |
| **3** | `CALCULATE` on the corrected chart with **the frozen engine unchanged** — corrected rows are materialised in memory and substituted on the loaded relation. Stale protection by **correction sequence, not timestamp**, because second granularity would have made the failure silent |
| **4** | `CIR`, and the two documents. `Model::preventSilentlyDiscardingAttributes()` enabled after a silent write-loss bug appeared twice |

**The Phase 4 full-PPT comparison finds two divergences from slide 34's stated result.** US4 is an SC officer in a DPC with no SC vacancy: the deck places them on the **unreserved** waiting list, the system on `SC_W`. And US4's `C.CASE` note is composed onto the deck's remark. **M7 closure is not requested.**

> **What it changed.** The milestone was held open on the strength of two rows in a worked example, rather than closed on an assumption. Both became BD-9 and BD-10.

## 2026-08-14 — M7 closes

Ruled: **raise BD-9 and BD-10 to CGPSC, implement no assumption, do not modify M5.** Business questions are tracked only in `docs/PENDING_BUSINESS_DECISIONS.md`, and M7 is **not reopened** for them.

`docs/UAT_BUSINESS_VALIDATION.md` puts the four blocking questions to CGPSC officials in plain language, each with the worked example that exposes it.

**Tag `v0.7.0-m7-complete`.** 537 tests, 1682 assertions, 58 tables.

## 2026-08-14 — M8 design

Written against S35–S44 immediately after the M7 tag. The deck settles more than usual: S35 states that the Review process is identical to Fresh, and S35 also answers who may initiate one — *"on the department's request **or** the Commission taking cognizance itself"* — a power the deck grants but never shows a screen for.

**The first design to carry a "Deferred Because of Business" section**, under the rule adopted the same day. M7 learned the need for it the hard way: BD-9 and BD-10 surfaced in its final phase's PPT comparison, after four phases were built. M8 states what it cannot settle *before* anything is built.

**BD-11 raised** — what happens to an officer already promoted under a DPC that is later reviewed. At the time it was recorded as blocking M8's last phase. **It was reclassified on 2026-08-14** (see below) and blocks nothing.

## 2026-08-14 — M8: three phases

| Phase | What it settled |
|---|---|
| **1** | The **origin** and the chain — `chain_uuid`, `review_number`, `chain_depth`, the original's content fingerprint — with append-only bidirectional links, coded S37 reasons, copy-on-create, and the "THIS IS A REVIEW DPC" banner rendered from the layout by a view composer, because a Review traverses M4's frozen screens and they could not be edited. The milestone's central guarantee is its first test: **editing a Review never touches the original** |
| **2** | Calculation under **the original's configuration snapshot**, never today's rules (AQ-11) — a correction raised to fix a mis-typed CR must not silently re-decide eligibility under thresholds that did not exist when the DPC was held. The seam was already there, built by M5 itself: `fromConfig()` and `fromSnapshot()` share one `build()`, so **no second scoring path exists**. Added by **decoration** (**AD-20**), which became the standing pattern for extending a completed milestone |
| **3** | Supersession taking legal effect at **approval, not at raising** (AQ-9) — a Review that is cancelled, returned or never approved must leave the original standing. Review documents state what they correct (AQ-13). The `SUPERSEDED` watermark is applied **on delivery, never in storage**, so a superseded document stays byte-identical to what was issued and still verifies |

**No file in M4, M5, M6 or M7 was modified** across any of the three phases — notable because M8 changes how M7 approves a result, renders a document and serves one. Four decorators, each with a test asserting a Fresh DPC is unaffected.

## 2026-08-14 — M8: the gate run that found two P0s

The full gate was run in the directed order. Gates 1, 2 and 4 passed first time. **Gates 3 and 5 each failed, and M8 Phase 3 was not deployable.**

- **A circular container dependency spanning M7 and M8** killed the PHP process. Three of the four decorators sit in each other's dependency graph, and M7's renderer already depended on the approval service, so Phase 3's constructor closed the ring. **The baseline document had warned that a *fifth* decorator was the signal to re-examine the boundary; the fourth was already enough.** It was also the cause of the development machine hanging — CLI `memory_limit` is `-1` here, so the recursion took 15 GB of RAM and 1.3 GB of swap before the kernel intervened, which presents as a hang rather than a test failure. The suite now peaks at 95 MB.
- **A 65-character foreign-key identifier against MySQL's 64-character limit** meant `dpc_document_review_references` **had never existed in the database**. Eighty-three green M8 tests never caught it, because the suite runs on in-memory SQLite, which does not enforce the limit (**TD-24**).

> **What it changed.** The project's standing observation is that the gate has never proved the system *right* — and this is the counter-example that sharpens it rather than overturning it. Both defects were invisible to every discipline that had been applied, and visible the moment the gate actually ran to completion against the real database. **A green suite is not evidence the schema deploys.** Running the suite against the engine the system deploys on is now the highest-value process change available.

**And a third finding, about documentation rather than code.** *607 passed / 1865 assertions* and *61 tables* appeared in four documents, and **no run could have produced either** — the suite could not reach its end, and the migration could not complete. The failure mode is **a number written once, propagated by copy, and never re-derived**. Corrected to the run that actually happened, with the previous figures named so the change is auditable.

## 2026-08-14 — M8 closes, and BD-11 is reclassified

**Ruled: BD-11 does not block M8.** It is a **Deferred Business Decision — Required before production activation of the Review supersession policy.** The register had said it blocked M8's final phase; what the answer actually gates is narrower and later — switching the supersession policy on in production.

> **What it changed.** A milestone gate is invisible outside this project; **production activation is a checkpoint someone can be held to.** Deferring BD-11 also accepts something knowingly: until CGPSC rules, anyone reading a superseded promotion list is reading a document whose legal effect is genuinely undetermined.

**Committed, pushed and tagged.** `1c8a8e1` *docs: finalize M8 documentation* and `a60689d` *feat(m8): Review / Re-DPC chain (Phases 1-3)*, pushed to `origin/feature/m0-setup`, tagged **`v0.8.0-m8-phase3`** and confirmed on the remote. **M9's freeze verification now has its baseline** — which is the whole reason the tag exists.

**M8 frozen — AD-21, ACTIVE.** Five rules, and **stricter than AD-15's**: where M4–M7 accept security, performance and hardening work without per-change approval, M8 accepts **bug fixes only, each individually approved**. M8 is now the most tightly held milestone in the project. Rule 4 — *M9 must not modify M8* — is the one that will be tested first, because M9 faces the same supersession question and the temptation will be to reach into M8 rather than decorate it.

> **What it changed.** The freeze was declared at the moment it cost nothing: nothing on the debt register sits inside M8 except TD-22, which is already open by directive. **That is the right moment to declare one** — a freeze adopted while it is inconvenient is a freeze that gets argued with.

## 2026-08-14 — The Freeze Exception mechanism (AD-22)

**AD-21 rule 5 required a Freeze Exception that did not exist**, so no change to M8 was possible by any route. **AD-22** defines it — and defines it for **all five freezes**, because five freezes with five procedures is how one of them quietly stops being enforced.

Four rulings worth recording, each of which had an easier alternative:

- **A request must precede the work.** An exception granted afterwards is not an exception; it is a request to ratify a breach. The freeze diff cannot tell "approved beforehand" from "explained afterwards" — only the record can, so the record has to come first.
- **The gate re-runs across *every* frozen milestone, not just the one opened.** The likeliest rule to be argued down as overkill, and the one with direct evidence behind it: the gate run above found a container cycle **spanning M7 and M8** that no single-milestone check would have located.
- **The freeze diff is compared against the declared blast radius, not merely read.** After an approved exception a non-empty diff is *expected*. Whether it contains **only** what was authorised is unanswerable unless the request named the files in advance — which is why blast radius is a recorded field rather than a nicety.
- **The record lives in `ARCHITECTURE_DECISIONS.md`, not in the architect review**, which is replaced in full at every phase. That is the same failure that caused `TECHNICAL_DEBT.md` and the decisions register to exist at all. **Git carries the tamper-evident half** — a register entry can be edited later; a commit trailer and a tag can be checked independently years afterwards.

**Three things it deliberately does not settle**, recorded as open rather than invented: no delegate for the Architect (so while they are unavailable, no frozen milestone can be changed for any reason, including a production security incident); whether a *security* fix inside M8 counts as a rule-2 bug fix, which on a strict reading makes the newest milestone harder to patch than the oldest; and no emergency bypass. **The second is the one to answer first** — it is already live.

---

## Where the project stands

**Nine milestones complete** (M0–M8), the last of them released and tagged. **Five frozen — M4, M5, M6, M7 and M8 — every one verified frozen by diff**, and all five now openable only through **AD-22**, whose register is empty. The full pipeline — registration → Pre-DPC → DPC proposal → Excel import → approval → CALCULATE → allotment → scheduling → proceeding → result → signed documents → **Review DPC and supersession** — runs end to end **with every external integration switched off**, which is the configuration UAT will use.

**M9 (Circulation DPC) has not started.** Its baseline for freeze verification exists — `v0.8.0-m8-phase3` — which is what M8's tag was for.

**What actually gates production**, in order of how long each takes to fix:

1. **A government SMS gateway** (**TD-6**). Not an engineering task — a procurement one, and therefore the longest lead time. Without it OTP delivery fails and **no officer can log in**.
2. **BD-9** — the one open business question that fails *unsafely*. The others mark a result provisional and block publication; BD-9 raises no flag at all.
3. **BD-2 / BD-3** — reserved relaxation. Blocks publication of any DPC short of reserved-category officers.
4. **BD-4 / AQ-3** — whether CSC eSign wants raw bytes or a PDF, which decides whether the HTML documents need a PDF renderer.
5. **Evidence-table permission grants** (**TD-1**) — a deployment control, not code.
6. **BD-11** — required before **production activation of the Review supersession policy**, specifically. It gates no milestone and does not hold up UAT of anything else; it holds up switching supersession on for real officers.

**And one thing to keep doing.** Every significant defect in this project has been found by applying a discipline, not by running the gate: the Demo Readiness Rule, a directive implemented properly, a full PPT comparison, or an assertion about an outcome rather than a rendering. The gate has never once proved the system was *right*.
