# Project Timeline

What happened, when, and why — the decisions that shaped the system, in the order they were taken.

**Created 2026-08-14.** Appended at each milestone closure. For *what* each milestone delivered, see `docs/MILESTONE_SUMMARY.md`; this records *when and why*.

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

**`docs/business/PENDING_BUSINESS_DECISIONS.md` created** — business questions get their own register, separate from engineering review.

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

---

## Where the project stands

**Eight milestones complete.** Three frozen and verified frozen by diff. The full pipeline — registration → Pre-DPC → DPC proposal → Excel import → approval → CALCULATE → allotment → scheduling → proceeding → result → signed documents — runs end to end **with every external integration switched off**, which is the configuration UAT will use.

**What actually gates production**, in order of how long each takes to fix:

1. **A government SMS gateway** (**TD-6**). Not an engineering task — a procurement one, and therefore the longest lead time. Without it OTP delivery fails and **no officer can log in**.
2. **BD-9** — the one open business question that fails *unsafely*. The others mark a result provisional and block publication; BD-9 raises no flag at all.
3. **BD-2 / BD-3** — reserved relaxation. Blocks publication of any DPC short of reserved-category officers.
4. **BD-4 / AQ-3** — whether CSC eSign wants raw bytes or a PDF, which decides whether the HTML documents need a PDF renderer.
5. **Evidence-table permission grants** (**TD-1**) — a deployment control, not code.

**And one thing to keep doing.** Every significant defect in this project has been found by applying a discipline, not by running the gate: the Demo Readiness Rule, a directive implemented properly, a full PPT comparison, or an assertion about an outcome rather than a rendering. The gate has never once proved the system was *right*.
