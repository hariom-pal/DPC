# M8 — Review DPC: Design

**Status:** Design only. Nothing implemented. Awaiting architect approval.
**Date:** 2026-08-14
**PPT source:** `ODPC_NEW_FINAL.pptx` S35–S44 (+ C3, C7)
**Depends on:** M4 (frozen), M5 (engine frozen), M6 (frozen), M7 (complete)
**Baseline:** `v0.7.0-m7-complete` / `6f52f71`

---

## 1. Objective

Let a completed DPC be corrected when an error is discovered in it — a name left off the seniority list, a wrong seniority order, a wrong roster point, a mis-entered CR, tribal-area service not counted, or a reservation rule not followed — by running a **new DPC that references the original** and states why.

## 2. The sentence that shapes this milestone

S35 ends with:

> **"रिव्यू डी.पी.सी. की प्रक्रिया ठीक उसी प्रकार होगी, जिस प्रकार फ्रेश डी.पी.सी. की प्रक्रिया होती है।"**
> *"The Review DPC process will be exactly the same as the Fresh DPC process."*

That is unusually direct, and it means **M8 is mostly reuse rather than construction.** S36–S44 confirm it: the AO logs in (S36) and prepares a proposal (S37), enters the tabulation chart (S38), the AH approves (S39–S40), the Commission conducts a sitting on a scheduled date (S41–S42), reviews (S43) and approves (S44). Every one of those screens is a screen this system already has.

**So the design's job is not to build a milestone. It is to find the places where "exactly the same" is not quite true.** There are four, and each is a question rather than a construction task:

| | Where Fresh and Review differ | Resolved by |
|---|---|---|
| **1** | A Review has an **origin** — it references a specific earlier DPC and carries coded reasons (S37) | Design, §6 |
| **2** | A Review **starts from the original's data**, not from an empty chart (S38 is titled *Correction in Tabulation Chart*) | Design, §7 |
| **3** | A Review **changes a decision already made**. A Fresh DPC creates one | **AQ-9 / BD-11** |
| **4** | A Review may run **years after** the DPC it corrects, under rules that have since changed | **AQ-11** |

Points 3 and 4 are the milestone. Points 1 and 2 are wiring.

## 3. Scope

| Slide | Content | Delivered by |
|---|---|---|
| **S35** | Review DPC exists; six coded reasons; process identical to Fresh | Master data (M2, seeded) + §6 |
| **S36** | AO login | M1 (approved Q1 deviation) |
| **S37** | Review proposal — type, **original DPC ID**, fresh DPC date, **review reasons** | **New — §6** |
| **S38** | Correction in Tabulation Chart — pre-populated, `SAVE AND NEXT`, `SHOW DETAILS`, zone shown | **New — §7** (reuses M4's chart) |
| **S39–S40** | AH review and `APPROVE` | M4, unchanged |
| **S41–S42** | Sitting at CGPSC on the scheduled date; Chairman/Member entry | M6 + M7, unchanged |
| **S43** | The Commission's review workspace | M7, unchanged |
| **S44** | Main and Waiting lists, `APPROVE BY CGPSC` | M7, unchanged |

## 4. Out of Scope

| Excluded | Belongs to |
|---|---|
| Circulation DPC — resolving a held promotion (S45–S48) | **M9** |
| Self-Inspection and objections (S49–S51) | **M10** |
| Dashboards, reports, audit UI, full notification matrix | **M11** |
| Any change to M4, M5, M6 or M7 behaviour | **Frozen / complete** |
| A promotion-order or withdrawal document distinct from M7's two | Pending **BD-11** |

## 5. What is reused, unchanged

Stated explicitly, because the most likely way to get M8 wrong is to treat "exactly the same" as licence to fork the Fresh DPC code and edit it.

| Reused | From |
|---|---|
| Proposal sections S14–S18, their services, Form Requests, validation and immutability | M4 |
| Excel import for the chart | M4 |
| AH review walkthrough and single signed approval | M4 |
| `dpc_proposal` workflow | M4 |
| Scoring, eligibility, zone, Super Seat, calculation runs | M5 |
| Allotment, acceptance, scheduling | M6 |
| Proceeding, corrections, CALCULATE, result, approval, holds, documents | M7 |

**M8 adds no new workflow, no new engine, no new document renderer and no new proceeding.** If the implementation finds itself adding any of those, that is the signal something has been misread.

## 6. Origin and reasons (S37)

The Review proposal screen is the Fresh proposal screen plus three fields: the **original DPC**, the **fresh DPC date** (displayed, not entered), and the **reasons for review**.

**Two of the three already exist.** `dpc_proposals` carries `original_dpc_proposal_id` and `review_reason_id` from M4, and `dpc_types` is seeded with `fresh` / `review` / `circulation`. `review_reasons` is seeded with the six S35 codes: `name_omitted`, `wrong_order`, `wrong_roster`, `wrong_cr`, `reservation_rule`, `tribal_service`.

**One does not, and it is a real finding.** S37 renders the reasons as **checkboxes** — several may apply at once, and in practice usually do: a name omitted from the seniority list is frequently also a roster-point error. `review_reason_id` is **singular**. M4 prepared for one reason; the slide shows many.

Since M4 is frozen, the fix is additive and lives outside it: a pivot `dpc_proposal_review_reasons`. The singular column stays, unused for reviews, rather than being repurposed — quietly redefining a frozen column's meaning is worse than leaving it idle. Recorded in §11.

**Selecting the original.** S37 shows a DPC ID dropdown. The candidates are the department's own proposals that reached `result_approved` — a Review corrects a *concluded* DPC, not one in progress. Scoped by department, addressed by UUID.

## 7. Starting from the original's data (S38)

S38 is titled **"Correction in Tabulation Chart"**, not "Enter Tabulation Chart", and shows a fully populated chart. A Review that made the department retype ten officers to fix one CR entry would guarantee new transcription errors while fixing an old one.

**Proposed: copy on creation, then treat as ordinary M4 data.** When a Review proposal is created, its sections are populated from the original's — post selection, committee, working officers, vacancies, seniority list, evaluation chart — as **new rows on the new proposal**. From that moment the Review is an ordinary M4 proposal: the AO edits it with the existing screens, the existing validation and the existing immutability rules.

Three properties follow, and they are why this is preferable to referencing the original's rows:

- **The original is never read at edit time**, so it cannot be mutated by accident, and it stays frozen exactly as M4 requires.
- **The Review is self-contained**, like every other evidence record in this system (AD-4).
- **What changed is answerable** by comparing the two snapshots, which is precisely what a tribunal asks of a Review.

**What is deliberately not copied:** `dpc_code` (the Review earns its own DPC ID at approval), approval and signature fields, and anything from the original's proceeding. A Review inherits *data*, not *decisions*.

## 8. Who may initiate (S35)

S35 is explicit, and answers what would otherwise be a question:

> *"…on the department's request **or** the Commission taking cognizance of the fact itself."*

**Both.** The department-initiated path is the AO creating a Review proposal — S36–S37, and entirely M4's existing flow.

**The Commission-initiated path has no screen anywhere in the deck.** CGPSC users hold no department and no proposal-creation UI, and S36 shows an *Authorized Officer* logging in even in the Review flow. The mechanism is genuinely undefined — see **AQ-10**.

## 9. Workflow Design

**No new workflow.** A Review proposal traverses the same three workflows a Fresh one does, in the same order:

```
   dpc_proposal (M4)            dpc_cgpsc (M6)                dpc_proceeding (M7)
   ─────────────────            ──────────────                ───────────────────
   draft ─submit─> submitted    unallotted ─allot─> allotted   scheduled ─open─> in_proceeding
     │  ▲            │                       │  ▲                              │
     │  └──return────┘                       └──┘ reallot          ┌───────────┴──────────┐
     │               │                        │                    │                      │
     └──cancel       └─approve(signed)──> approved            approve_result          cancel_dpc
                                    │      ─accept─> accepted       │                      │
                                    │      ─schedule─> scheduled    ▼                      ▼
                                    │                        result_approved          cancelled
                                    └── DPC ID generated,
                                        forwarded to CGPSC
```

The **only** workflow question M8 raises is what this does to the *original* proposal's state — which is AQ-9, and which the design deliberately does not answer.

**Where the Review differs from Fresh, in workflow terms:** nowhere. That is the finding, and it is the reason this milestone is small.

## 10. Risk Assessment

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| **R1** | **Two contradictory promotion lists in circulation.** The original's documents are signed, published and in a department's hands. The Review produces a second set. Nothing currently marks the first as superseded | **High** | **AQ-9 / BD-11.** Until ruled, do not build a supersession mechanism on an assumption. Whatever is ruled must reach the *documents*, not just a database column |
| **R2** | **Silently re-deciding under different rules.** A Review of a 2017 DPC run in 2027 would, by default, apply 2027's configuration. A clerical correction would then also re-decide eligibility under rules that did not exist at the time | **High** | **AQ-11.** M5 already stores the complete rule set on every run, so re-running the original's rules is *possible* — the question is whether it is right |
| **R3** | **Copy-on-create drifts from the original.** If §7's copy misses a field, the Review silently starts from data the department never submitted | Medium | Copy through the M4 services rather than raw inserts; assert field-by-field equality between original and copy in a test; record the original's content fingerprint on the Review |
| **R4** | **Review of a Review of a Review.** S37's dropdown lists DPC IDs without saying whether a Review may itself be reviewed | Medium | **AQ-14.** Whatever is ruled, the chain must be traversable — `original_dpc_proposal_id` gives a linked list, so depth is a policy question, not a modelling one |
| **R5** | **BD-9 and BD-10 are inherited in full.** M8 produces the same result screen and the same two documents as M7, so both open questions affect it identically | **High** | Nothing new to do. Recorded in §14 so it is not rediscovered at M8 closure the way it was at M7's |
| **R6** | **The world has moved on.** Officers in a years-old DPC may have retired, been promoted, or left. The Review recalculates against a snapshot of who they were | Medium | This is *correct* — a Review corrects what should have been decided *then*. But it must be visible: the Review's documents should state the original's date prominently. Raised as **AQ-13** |
| **R7** | **Scope creep by forking.** "Exactly the same process" invites copying M4/M7 code and editing it | Medium | §5 lists what is reused. A code review at each phase boundary should confirm no M4/M5/M6/M7 logic was duplicated; the freeze diff will not catch a *copy* |
| **R8** | S43's chart differs cosmetically from S33's — different qualifications, a different tribal year for US3 | Low | Documentation noise in the deck, not a rule. Recorded, not implemented |

**The two that decide the milestone are R1 and R2**, and both are blocked on rulings rather than on engineering.

## 11. Database Design

**Additive only. No M4/M5/M6/M7 table is modified.**

Most of what M8 needs already exists. The full list of what does not:

### `dpc_proposal_review_reasons` (new)

S37's checkboxes — several reasons may apply at once.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `dpc_proposal_id` | FK → `dpc_proposals` cascade | |
| `review_reason_id` | FK → `review_reasons` | |
| `detail` | string nullable | free text against a coded reason, where the department wants to be specific |
| `timestamps` | | |

Unique `(dpc_proposal_id, review_reason_id)`.

### `dpc_review_links` (new) — **only if AQ-9 requires it**

If the ruling is that a Review supersedes the original, the relationship needs a home. **It cannot be a column on the original**: writing to `dpc_proposals` from M8 would mean either editing the frozen M4 model or writing behind its immutability guard — the same trap TD-19 and TD-21 already record. So the link is expressed **from the Review's side**, in its own table.

| Column | Type | Notes |
|---|---|---|
| `id`, `uuid` | | |
| `review_dpc_proposal_id` | FK → `dpc_proposals` | the Review |
| `original_dpc_proposal_id` | FK → `dpc_proposals` | the DPC being corrected |
| `effect` | string(20) | `supersedes` / `annotates` — **decided by AQ-9** |
| `established_at`, `established_by`, `established_by_name` | | actor snapshot (AD-5) |

**Not designed further until AQ-9 is ruled.** Designing both branches in detail would be designing one of them for nothing.

### Already present, reused

| Existing | Provides |
|---|---|
| `dpc_proposals.original_dpc_proposal_id` | the origin link (M4) |
| `dpc_proposals.dpc_type_id` → `dpc_types` | `fresh` / `review` / `circulation`, seeded (M2) |
| `review_reasons` | the six S35 codes, seeded (M2) |
| `dpc_proposals.review_reason_id` | **left unused for reviews** — see §6 |
| Every M4 section table | the copied chart (§7) |
| `dpc_calculation_runs.configuration_snapshot` | makes AQ-11's "original rules" option *possible* |

## 12. Service and Screen Design

| Component | Responsibility |
|---|---|
| `ReviewDpcService` | `create()` — a Review proposal from an original, copying sections through the existing M4 services; `eligibleOriginals()`; `reasons()` |
| `CreateReviewProposalRequest` | original required and `result_approved`; at least one reason; department scoping |
| `Dpc\ReviewProposalController` | the S37 screen and creation |
| `dpc/reviews/create` | S37 — type, original DPC ID, fresh DPC date, reason checkboxes |

Everything after creation uses M4's existing controllers and screens, with the chart screen (S38) gaining a heading that names the original. **Four new files, plus one view.**

## 13. Testing Strategy

| Layer | Tests |
|---|---|
| Creation | A Review copies every section faithfully — asserted field by field, not by row count |
| Isolation | **Editing the Review does not alter the original.** The single most important test in the milestone |
| Validation | Only a `result_approved` proposal may be reviewed; at least one reason; cross-department refused |
| Reasons | Several reasons recorded together (S37) |
| End to end | A Review runs the full pipeline — submit → approve → allot → schedule → proceeding → calculate → approve → documents — reusing M7 |
| Worked example | S38's chart differs from S33's by `TRIBAL 2021` on US3; the Review's result should match S44's, which matches S34's |
| Rules | Whichever AQ-11 rules, pinned by golden test |
| Freeze | `git diff v0.7.0-m7-complete -- app/Domain/{Dpc,Eligibility,Cgpsc,Proceeding}` empty |

Estimated 45–60 tests — smaller than M7's 169, because most of the behaviour is already tested where it lives.

## 14. Deferred Because of Business

*First use of the standing section required by architect directive (2026-08-14): a milestone states at design time what it cannot settle, rather than discovering it at closure — as M7 did with BD-9 and BD-10.*

| # | Question | Effect on M8 | Status |
|---|---|---|---|
| **BD-11** *(new)* | **What happens to officers already promoted under the original DPC?** Is the promotion withdrawn, allowed to stand, or is the Review prospective only? | Decides what AQ-9 must model, and whether M8 needs a withdrawal document at all | **Raise to CGPSC** |
| **BD-9** | Which waiting list, when a category has no vacancy | M8 produces the same lists as M7 — inherited in full | Open |
| **BD-10** | What a pending court case does | Same | Open |
| **BD-2 / BD-3** | Reserved relaxation | Same. Note that `reservation_rule` is one of the six *reasons* for a Review, so a DPC may be reviewed **precisely because** a reservation rule was misapplied — and the rule in question may be one of the unsettled ones | Open |

**BD-2/BD-3 deserve emphasis here.** "Reservation rule not followed" is a listed ground for review. A Review raised on that ground, decided by a rule that is itself unsettled, would produce a provisional correction of a provisional result. **M8 can be built while they are open; its output cannot be published.**

**What M8 delivers regardless:** everything in §§6, 7 and 12. The copy-on-create pipeline, the reasons, the origin link and the end-to-end reuse are unaffected by all five questions. Only the *effect on the original* (BD-11/AQ-9) and *which rules apply* (AQ-11) are blocked, and both can be answered after implementation begins.

## 15. Proposed Phasing

| Phase | Scope | Blocked by |
|---|---|---|
| **1** | Review proposal creation — origin, reasons, copy-on-create, S37 and S38 headings, isolation tests | — |
| **2** | End-to-end reuse — the full pipeline on a Review; rule selection | AQ-11 |
| **3** | Effect on the original — the link, its expression in the documents, closeout | **AQ-9 / BD-11** |

**Phase 1 is unblocked and is most of the milestone.** Phase 3 is small but cannot be designed further, let alone built, until BD-11 is answered — which is exactly why it is stated here rather than found later.

## 16. Quality Gates

Unchanged: Pint · PHPStan (Larastan level 6) · PHPUnit · `composer audit` · `migrate:fresh --seed`, plus Security, Performance, VAPT and full-PPT reviews and the demo-readiness checklist.

Two M8-specific additions:

1. **Freeze verification extended to M7.** `git diff v0.7.0-m7-complete -- app/Domain/{Dpc,Eligibility,Cgpsc,Proceeding}` must be empty. M7 is complete, and M8 depends on it more heavily than any milestone has depended on its predecessor.
2. **A duplication check at each phase boundary.** The freeze diff catches modification; it does not catch a *copy*. R7 is the risk this milestone is most exposed to.
