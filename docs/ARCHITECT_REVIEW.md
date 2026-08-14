# Architect Review — M7 Closure

**Date:** 2026-08-14
**Subject:** M7 closed · three registers separated · milestone summary and timeline · repository tagged
**Status:** **M7 COMPLETE.** M8 at design stage — nothing implemented.
**Tag:** `v0.7.0-m7-complete`

---

## 1. Directives — status

| # | Directive | Status |
|---|---|---|
| **1** | `PROJECT_STATUS.md` → M7 COMPLETE | ✅ |
| **2** | Create `docs/MILESTONE_SUMMARY.md` | ✅ One section per milestone — delivered, governing freeze, left behind |
| **3** | Create `docs/PROJECT_TIMELINE.md` | ✅ What happened when, and **why** |
| **4** | Every future milestone carries a "Deferred Because of Business" section | ✅ Adopted as a standing rule; **§14 of the M8 design is the first** |
| **5** | Keep Business Decisions / Technical Debt / Architecture Decisions as separate documents | ⚠️ Two existed. **The third was created** — see below |
| — | Gate, commit, tag, push | ✅ §3 |
| — | Begin M8 **design only** | ✅ §5 |

---

## 2. On Directive 5 — the register that did not exist

Business Decisions and Technical Debt were already separate files. **Architecture Decisions was not a document at all.**

Architectural rulings lived in `docs/ARCHITECT_REVIEW.md` — which is **replaced in full at every phase**. A ruling made in M4 Phase 5 and not restated since survives only in a git history nobody reads. That is precisely the failure mode `TECHNICAL_DEBT.md` was created to fix, applied to a different kind of information, and it had been sitting unnoticed for four milestones.

`docs/ARCHITECTURE_DECISIONS.md` records 18 decisions (AD-1 … AD-18), each with **the consequence as well as the decision** — a decision whose cost is unwritten gets re-litigated by whoever next finds it inconvenient.

Three are worth naming here:

- **AD-10 (pure engines).** The payoff arrived two milestones after the decision. Because the M5 engine reads only what it is handed, M7 could score a *corrected* chart without changing one line of frozen code. An engine that fetched its own data could not have been reused that way.
- **AD-15 (freezes).** Records what the freeze **forecloses**: TD-16, TD-19, TD-20 and TD-21 are all small improvements visibly blocked by it, left undone rather than taking the freeze quietly. They should be batched into the next legitimate opening rather than each justifying one.
- **AD-17 (the gate is necessary, not sufficient).** Every significant defect in this project was found by applying a discipline — the Demo Readiness Rule, a directive implemented properly, a full PPT comparison, an assertion about an outcome rather than a rendering. All of them passed Pint, PHPStan, PHPUnit and `composer audit` while broken.

---

## 3. Repository state

| Gate | Result |
|---|---|
| Laravel Pint | **Pass** |
| PHPStan (Larastan level 6) | **0 errors** |
| PHPUnit | **537 passed / 1682 assertions** |
| `composer audit` | **No advisories** |
| `php artisan migrate:fresh --seed` | **OK** — 58 tables, 10 seeders |

**Freeze verification:** `git diff --name-only v0.6.0-m6-complete` shows no file under `app/Domain/{Dpc,Eligibility,Cgpsc}`. M4, M5 and M6 came through all four M7 phases untouched.

Committed and tagged **`v0.7.0-m7-complete`**, pushed with the branch. Working tree clean apart from the M8 design documents, which are new and uncommitted by design.

---

## 4. What M7 leaves open, and where it is tracked

**Nothing in implementation.** Four business questions remain, tracked **only** in `docs/PENDING_BUSINESS_DECISIONS.md`, and M7 is not reopened for them.

| # | Question | Failure mode while open |
|---|---|---|
| **BD-2 / BD-3** | Reserved relaxation — automatic or discretionary, and its scope | **Safe.** Result marked provisional; publication refused |
| **BD-10** | What a pending court case does | **Safe.** The remark omits `CC`; nothing else changes |
| **BD-9** | Which waiting list, when a category has no vacancy | ⚠️ **Unsafe.** No flag is raised — the system places the officer under their own category code, considers the calculation complete, and **would publish it** |

**BD-9 is the one to chase.** It is the only open question that can produce a confidently wrong published document rather than a blocked one.

---

## 5. M8 — Review DPC: design submitted

`docs/design/09-m8-review-dpc.md`. **Nothing implemented.**

Headline: **M8 is the smallest milestone since M2, and deliberately so.** S35 states the rule outright — *"the Review DPC process will be exactly the same as the Fresh DPC process"* — so the milestone is mostly **reuse**, not construction. The design's job was to find the places where "exactly the same" is not quite true, and there are four.

**Six blocking questions raised (AQ-9 … AQ-14).** The two that decide the shape of the milestone:

- **AQ-9 — what does a Review DPC do to the original?** Superseded, annotated, or untouched? This determines whether a promotion already acted on is withdrawn, and it is the difference between a correction and a second, contradictory promotion list.
- **AQ-11 — does a Review DPC re-run the engine, or re-run the *original's* rules?** A Review of a 2017 DPC conducted in 2027 could legitimately mean either. Getting it wrong means correcting a clerical error and silently re-deciding the case under rules that did not exist at the time.

**Risk assessment, database design and workflow design** are §§10–13 as directed, and **§14 is the first "Deferred Because of Business"** section — stating at design time what M8 cannot settle, rather than discovering it at closure as M7 did.

---

## 6. Status

**M7 COMPLETE**, closed and tagged. **M8 design submitted; implementation not started.**

Waiting for Architect approval.
