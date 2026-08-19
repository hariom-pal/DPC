# Architecture Baseline — v0.7

The shape of the system at tag **`v0.7.0-m7-complete`**, and the extension pattern M8 builds on top of it.

**Created 2026-08-14 by architect directive** (M8 Phase 3, Directive 3).

---

## Why a baseline document

Three milestones are frozen and a fourth is complete. Everything M8 and later add must sit **on top of** that, not inside it — and the mechanism by which new behaviour reaches old code is now load-bearing enough to be written down rather than inferred from container bindings.

This document is the reference for that mechanism. For *why* individual decisions were taken, see `docs/ARCHITECTURE_DECISIONS.md`; for what each milestone delivered, `docs/MILESTONE_SUMMARY.md`.

---

## The baseline at v0.7

| Layer | Owner | State |
|---|---|---|
| Platform, auth, RBAC, master data | M0–M2 | Complete |
| Pre-DPC proposal | M3 | Complete |
| DPC proposal, sections, Excel import, AH approval | M4 | **Architecture frozen** |
| Scoring, eligibility, zone, Super Seat, calculation runs | M5 | **Engine frozen** |
| Allotment, acceptance, scheduling | M6 | **Frozen** |
| Proceeding, corrections, CALCULATE, result, approval, holds, documents | M7 | Complete |
| Review DPC | M8 | In progress |

**Freeze verification, run before every architect review:**

```
git diff --name-only v0.6.0-m6-complete -- app/Domain/{Dpc,Eligibility,Cgpsc}     # M4 / M5 / M6
git diff --name-only v0.7.0-m7-complete -- app/Domain/Proceeding                  # M7
```

Both must be empty. They have been at every phase boundary since the tags existed.

---

## The extension pattern: decoration, not edit

M8 needed four behavioural changes inside code it may not touch:

| Need | Where the behaviour lives | How M8 reaches it |
|---|---|---|
| A Review calculates under the **original's** rules | M7 `ProceedingCalculationService`, calling frozen M5 | `ReviewAwareCalculationService` |
| Approving a Review **supersedes** the original | M7 `ResultApprovalService` | `ReviewAwareApprovalService` |
| A Review's documents state **what they correct** | M7 `HtmlDocumentRenderer` | `ReviewAwareDocumentRenderer` |
| A superseded DPC's documents are served **watermarked** | M7 `ProceedingDocumentService` | `ReviewAwareDocumentService` |

Each is a **subclass bound over its parent in the container** (`AppServiceProvider::register()`). The caller still asks for the M7 class; the container returns the M8 one. Every decorator begins by asking whether the proposal is a Review, and **returns the parent's behaviour unchanged if it is not** — so a Fresh DPC behaves exactly as it did at v0.7, and a test asserts that for each.

### The one that carries the most weight

`ReviewAwareCalculationService` is worth reading in full, because it is the pattern at its most useful.

**The problem.** A Review must calculate under the rules in force when the original was decided (AQ-11). `DpcCalculationService::calculate()` builds its rule set from `RuleSet::fromConfig()` and accepts no rule set — and M5 is engine-frozen, so it cannot be given one.

**The solution was already in M5's design.** `fromConfig()` and `fromSnapshot()` share a single `build()`, so a stored `configuration_snapshot` has *exactly* the shape of `config('dpc.scoring')`. Under **AD-3**, business rules *are* configuration. So:

```
resolve the original's pinned run  →  its configuration_snapshot
set config('dpc.scoring') to it (preserving engine_version)
parent::calculate(...)
restore the previous configuration in a finally
```

**Three details that would be wrong if left implicit:**

- **`finally`, always.** Configuration is process-global; a failed calculation leaving a 2017 rule set in place would silently corrupt the *next* one.
- **`engine_version` survives the override.** The rules are the original's; the engine doing the work is this build. Replacing `dpc.scoring` wholesale would blank it, and every Review document would claim an unknown engine.
- **No second scoring path exists.** A parallel "historic rules" implementation would be a second engine to keep correct, and the two would diverge the first time a rule changed.

**Provenance is recorded on the M8 side.** `dpc_review_calculations` stores the run UUID, the **configuration snapshot UUID** and the **original calculation run UUID** for every Review calculation (Directives 1 and 2), because `dpc_calculation_runs` belongs to frozen M5. The snapshot UUID is a **UUIDv5 over the canonical snapshot**, not a random value — two calculations under identical rules therefore share one UUID, which turns *"were these decided under the same rules?"* into an equality check.

### The one with the subtlest requirement

`ReviewAwareDocumentService` has to satisfy two rules that pull against each other:

- a superseded DPC's documents must be **visibly** superseded (Directive 5, M8 Phase 1);
- a generated document is **immutable**, and its SHA-256 proves a department's copy is the copy the Commission issued (AD-4).

**Resolved by watermarking on delivery, never in storage.** The file on disk stays exactly as signed. `contents()` — what a reader is served — prepends the watermark when the DPC has been superseded; `storedContents()` and `verify()` read the bytes as issued.

That split is also the honest model of what happened: the document *was* issued as it stands and was *later* superseded. Baking the watermark into the file would assert it had been superseded at the moment of issue, which is false.

---

## What the pattern costs, and where it stops

**It costs indirection.** The class that runs is not the class the caller names. A developer reading `ProceedingCalculationService` will not see the Review behaviour until they check the container binding. Mitigated by a test asserting which implementation resolves, so a binding cannot be removed silently — but it is a real cost, and it grows with every decorator.

**It is not a way around the freeze process.** A bug *in* M7 is fixed *in* M7, with approval. Decoration adds behaviour for a new milestone; it does not repair the old one. If M8 ever finds itself decorating to work around a defect rather than to extend, that is the signal to open the freeze properly instead.

**Four decorators is close to the limit.** A fifth would be a reason to ask whether the boundary is in the right place — at which point the honest move is to petition for the freeze to open and refactor once, rather than to keep layering.

---

## Known residuals at this baseline

| # | Residual | Status |
|---|---|---|
| **TD-22** | The rule-reuse configuration override is process-global. Restored in a `finally`, and PHP-FPM serves one request per process, so two calculations cannot interleave today | **Deliberately left open** (architect Directive 4, M8 Phase 3). Solve it when concurrent calculation workers actually exist — not before |
| **TD-19** | `DpcProposal` lacks `HasUuids`; UUIDs are minted by direct update in two places | Blocked by the M4 freeze |
| **TD-20** | The notification recipient query is now duplicated three times | Blocked by the M6 freeze; the case for extraction is stronger with each copy |
| **TD-21** | The calculation engine version is not on the M5 run row | Blocked by the M5 freeze |

All four are blocked by a freeze rather than by difficulty. They should be **batched into the next legitimate opening** rather than each justifying one of its own.
