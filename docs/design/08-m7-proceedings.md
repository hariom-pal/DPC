# M7 — DPC Proceedings & Result Generation: Design

**Status:** Design only. Nothing implemented. Awaiting architect approval.
**Date:** 2026-08-14
**PPT source:** `ODPC_NEW_FINAL.pptx` S31–S34 (+ `CONVENTIONAL DPC PROCESS.pptx` C3, C5, C6)
**Depends on:** M4 (frozen), M5 (engine frozen), M6 (frozen)
**Baseline:** `v0.6.0-m6-complete` / `c2deae4`

This milestone reuses the Workflow Engine, Activity Timeline, audit trail, Event Catalog, notification listener, signature abstraction, feature flags, storage abstraction and queue. **It introduces no new framework and no new cross-cutting mechanism.** The one genuinely new piece of infrastructure is the project's first queued job, and it follows the notification listener's existing failure doctrine rather than inventing one.

---

## 1. Objective

Let the Commission conduct the online DPC sitting on the scheduled date, produce the Main List and Waiting List from the frozen eligibility engine, approve them as CGPSC, and issue the resulting proceedings and result documents as signed, immutable, retrievable evidence.

This is the milestone where the system stops recording a process and starts producing **the legal artefact a promotion is later challenged on**. Every design choice below is subordinate to one question: three years from now, in a tribunal, can this system show what was decided, by whom, on what data, under which rules — and prove none of it changed since?

## 2. Scope

PPT **S31–S34**:

| Slide | Content | M7 delivers |
|---|---|---|
| **S31** | The proceeding is conducted at CGPSC on the scheduled date and time. Department entries altered on the DPC date are invalid unless made between proposal submission and the DPC date. | The proceeding lifecycle, and verification that the data-freeze rule is already structurally enforced |
| **S32** | Chairman / Member enters the proceeding by DPC ID | Proceeding entry point and access control |
| **S33** | The **ACTION** workspace — the tabulation chart with `SAVE`, `CALCULATE`, `EDIT`, `CANCEL DPC`, `TRIBAL <year>` | The workspace, Commission-side corrections, CALCULATE via the frozen M5 engine, cancellation |
| **S34** | **APPROVAL** — Main List and Waiting List, `APPROVE BY CGPSC` | The result screen, signed CGPSC approval, document generation and publication |

Concretely, M7 adds:

1. **`DpcProceeding`** — the sitting itself, as a first-class record.
2. **Commission-side corrections** during the sitting, as append-only overrides that never mutate M4's frozen proposal.
3. **Held cases (`CIR`)** — officers included in the DPC whose promotion action is reserved.
4. **CGPSC approval** of the result, signed.
5. **`CANCEL DPC`**.
6. **Proceedings and result documents**, generated on the queue, stored through the storage abstraction, signed, hashed and published to the department.

## 3. Out of Scope

| Excluded | Belongs to |
|---|---|
| Review DPC — proposal, correction of tabulation, AH approval, re-run (S35–S44) | **M8** |
| Circulation DPC — the process that *resolves* a held case and releases the promotion (S45–S48) | **M9** |
| Self-Inspection, individual login, PRINT, objections (S49–S51) | **M10** |
| Dashboards, reports, audit-trail UI, full notification matrix | **M11** |
| Any change to the M5 scoring, eligibility, zone or Super Seat algorithms | **Frozen** |
| Any change to M4's proposal schema or M6's allotment/scheduling behaviour | **Frozen** |

**M7 marks a case as held; M9 resolves it.** The distinction matters and is easy to get wrong: S33 shows `CIR` being produced *during* the proceeding and S34 carries it into the result (`ST SS CIR`), so the marking cannot be deferred to M9 without making M7's legal output incomplete.

Also excluded, and called out because a secondary document describes them (see §4): per-member voting, free-text meeting minutes, uploading an externally signed proceedings file, and a separate promotion-order generation and distribution module.

## 4. PPT Mapping

### S31 — Process of Online DPC

> "The online DPC process will be completed at the office of the Chhattisgarh Public Service Commission on the prescribed date and time. Changes made by the department in the entries on the date of the DPC will under no circumstances be valid, if those changes are not between the sending of the detailed DPC proposal and the date of the DPC."

**This rule is already structurally enforced and needs no new mechanism.** M4 freezes every section of the proposal on approval, at both the Policy and Service layers; M6 forwards only approved proposals. A department therefore *cannot* alter entries after forwarding, let alone on the DPC date. M7's obligation is to prove it: the proceeding records the proposal content fingerprint it acted on (M5's `proposal_version` SHA-256), so a later claim that the data changed between forwarding and sitting is answerable from stored evidence rather than by argument.

### S32 — Login (Hon'ble Chairman / Member)

The mockup shows entry by **DPC ID + OTP**, with `M1_CGPSC` as the account. Under approved decision **Q1** the project replaced Aadhaar-OTP login with email-or-mobile + password and OTP step-up at signing, so this screen is not reproduced literally. What is preserved is the substance: **the DPC ID is the handle by which a Commission member enters a specific proceeding**, and the entry point is a list of DPCs allotted to that member.

Documented difference — **Category B (documentation difference)**, not a defect.

### S33 — Action

Three successive renderings of the same chart, which read as a sequence:

| Stage | What the slide shows | Source |
|---|---|---|
| 1 | Chart as the department forwarded it — `MARK` and `REMARK` blank | M4 (frozen) |
| 2 | `MARK` and `REMARK` filled: `EL`, `NE`, `CC`, `CIR` | M5 engine + M7 holds |
| 3 | `REMARK` becomes a **seat allocation**: `UR`, `ST_W`, `UR_W CC`, `ST SS CIR` | M5 (already implemented) |

Controls: `SAVE`, `CALCULATE`, `EDIT`, `CANCEL DPC`, and a `TRIBAL <year>` control.

**Two findings from reading stage 2 against stage 3, both load-bearing for this design:**

**(a) The `TRIBAL` control changes the outcome, and the frozen engine already computes it correctly.** In stage 2, US3 carries no note, scores **14**, and is marked `NE`. In stage 3, US3 carries note `TRIBAL 2021`, scores **15**, and is placed `ST_W`. Verifying against the engine: US3's six CR grades are क, ख, क, क, क, क; the five most recent are ख, क, क, क, क = 2+3+3+3+3 = **14**; the tribal-area bonus of **+1** (C4) gives **15**, which meets the ≥15 Class-I→I threshold (C5). **The PPT's own worked example is reproduced exactly by the frozen M5 engine, provided the tribal input is supplied at proceeding time.** That single row establishes both that Commission-side correction is real and that it must trigger recalculation.

**(b) The `CIR` trigger is confirmed by the deck, and it dissolves ambiguity Q12.** Q12 records an apparent conflict — the Conventional deck (C3) says "immovable-property details missing **or** CR unavailable for 2 or more years", while the S14 label says "CR ≤ 4". S33 shows both cases:

- **US6** carries only four CR grades (क+, क, क, क+) and is marked `CIR` with `MARK = -`.
- **US8** scores 20 and qualifies for a Super Seat, yet is still `CIR` — its 2016 immovable-property return is `N`.

The two Q12 formulations **do not conflict**: the chart holds six CR columns, so "2 or more years missing" and "4 or fewer present" are the *same condition* stated from opposite ends. Recorded here as an observation; the ruling remains M9's, and M7 implements the trigger configurably either way.

US8 also shows that **a hold is orthogonal to eligibility**: an officer can be top-scoring, Super-Seated and still held. `CIR` is a procedural reservation of the promotion action, not a verdict on merit — which is precisely why it does not belong inside the frozen engine.

### S34 — Approval

Main List and Waiting List under the heading **"PROMOTION AS ON 2017 ON DS FROM US"**, titled *"अवर सचिव पद से उप सचिव पद पर पदोन्नत अधिकारीयों की मुख्य सूचि तथा अनुपूरक सूचि"*, with a single **`APPROVE BY CGPSC`** button.

The worked result: Main = US1 (`UR`), US8 (`ST SS CIR`); Waiting = US3 (`ST_W`), US4 (`UR_W CC`). This becomes M7's acceptance test.

**The "as on" year has no home in the current schema.** No `as_on_year`, `vacancy_year` or equivalent column exists on `dpc_proposals` or the vacancy tables — verified against the migrations. S16 says "2026 की स्थिति में" while S34 says "PROMOTION AS ON 2017", so the deck itself mixes example years and cannot settle it. See **AQ-7**.

### Conflict with the SRS

`docs/archive/DPC- SRS.docx` describes a **materially different proceeding**:

| SRS (archived) | PPT (authoritative) |
|---|---|
| "DPC Proceedings (Screen 10)": Committee Remarks free-text meeting minutes; Recommendation radio — Recommended / Deferred / Rejected; **Signed Proceedings file upload** | S33: chart + `SAVE` / `CALCULATE` / `EDIT` / `CANCEL DPC` / `TRIBAL`. No minutes, no per-member recommendation, no upload |
| "DPC Committee Member → View Proposals → Evaluate → **Vote/Recommend**" | No voting anywhere in the deck |
| "Admin → Conduct Meeting → Record Minutes → Approve Decisions" | The allotted Member conducts; a single `APPROVE BY CGPSC` |
| "Promotion Order Generation (Screen 12)"; "Final Promotion Order — Publication & Distribution" | No promotion-order screen exists in the deck |

Per the project's standing rule the **PPT governs**, and `docs/archive/` is explicitly marked "do not implement from". The conflict is documented rather than silently resolved because it changes M7's size by a large factor: voting, minutes and a separate promotion-order module would roughly double the milestone. Confirmation requested — **AQ-8**.

## 5. Workflow

A new workflow, `dpc_proceeding`, declared in `config/dpc.php` and executed by the existing `WorkflowEngine`. **No new engine, no new state machinery.**

Following M6's precedent exactly, the **subject is `DpcProposal`**, not the proceeding record. The reason is evidential rather than technical: every CGPSC-side event — allotment, acceptance, scheduling, proceeding, approval — then lands on a single subject thread, so "show me everything that happened to this DPC" is one ordered query over the timeline. Splitting the subject would split the legal record.

| Transition | From | To | Role | Signature | Notifies |
|---|---|---|---|---|---|
| `open` | `scheduled` | `in_proceeding` | `member` | no | AO, AH |
| `approve_result` | `in_proceeding` | `result_approved` | `member` | **yes** | AO, AH |
| `cancel_dpc` | `scheduled`, `in_proceeding` | `cancelled` | `member` | no | AO, AH |

**`CALCULATE` is deliberately not a transition.** It does not change the DPC's state — it can be run repeatedly, and M5 already records each run as an immutable, audited, timeline-recorded artefact. Modelling it as a transition would invent a state machine the PPT does not describe and would make the ledger claim a status change that did not occur.

`SAVE`, `EDIT` and `TRIBAL` are likewise not transitions; they append override records (§9) and timeline entries within `in_proceeding`.

`approve_result` carries `signature: true`, matching M4's `approve`. This is the point at which the Commission attests the promotion list, and it is the strongest signature requirement in the system.

Role is `member` in the config because that is the Spatie role the allottee holds. **Being *the allottee* is enforced by policy, not by role** — exactly as M6 does for `accept` and `schedule`, since a role cannot express "this specific member". See **AQ-6** for whether the Chairman may also act.

## 6. State Diagram

```
                            (M6 — frozen)
   unallotted ──allot──> allotted ──accept──> accepted ──schedule──> scheduled
                            │  ▲                                        │
                            └──┘ reallot / reschedule                   │
                                                                        │
   ─────────────────────────────── M7 ───────────────────────────────── │
                                                                        │
                                              ┌─────────── open ────────┘
                                              ▼
                                      ┌───────────────┐
                     SAVE / EDIT ────>│ in_proceeding │<──── CALCULATE (M5)
                     TRIBAL / HOLD    └───────┬───────┘      (repeatable,
                                              │              never a state change)
                          ┌───────────────────┴───────────────────┐
                          │                                       │
                   approve_result                            cancel_dpc
                    (signature)                              (reason required)
                          │                                       │
                          ▼                                       ▼
                 ┌─────────────────┐                       ┌───────────┐
                 │ result_approved │  ── TERMINAL ──       │ cancelled │  ── TERMINAL ──
                 └────────┬────────┘                       └───────────┘
                          │
                          │ (queued, after commit)
                          ▼
                 documents generated ──> signed ──> hashed ──> published
```

Both terminal states are genuinely terminal in M7. Re-opening a concluded DPC is **Review DPC (M8)**, which creates a *new* proposal referencing the original — the mechanism M4 already built (`original_dpc_proposal_id`, `review_reason_id`).

**State is derived, never stored on the frozen proposal.** `DpcProceedingStatus::workflowState()` returns the workflow state, mirroring `AllotmentStatus::workflowState()`. M4's `DpcProposalStatus` enum is not touched and no column is added to `dpc_proposals`.

## 7. Role Matrix

| Action | AO | AH | Chairman | Member (allottee) | Member (other) | CGPSC Admin | Individual |
|---|---|---|---|---|---|---|---|
| See the proceeding queue | — | — | see AQ-6 | ✅ own only | — | — | — |
| Open the proceeding | — | — | see AQ-6 | ✅ | — | — | — |
| View the chart in the workspace | — | — | see AQ-6 | ✅ | — | — | — |
| Record a correction (`EDIT` / `TRIBAL`) | ❌ | ❌ | see AQ-6 | ✅ | ❌ | ❌ | ❌ |
| `CALCULATE` | ❌ | ❌ | see AQ-6 | ✅ | ❌ | ❌ | ❌ |
| Mark / clear a hold (`CIR`) | ❌ | ❌ | see AQ-6 | ✅ | ❌ | ❌ | ❌ |
| `APPROVE BY CGPSC` | ❌ | ❌ | see AQ-6 | ✅ | ❌ | ❌ | ❌ |
| `CANCEL DPC` | ❌ | ❌ | see AQ-6 | ✅ | ❌ | ❌ | ❌ |
| View the approved result | ✅ own dept | ✅ own dept | ✅ | ✅ | ❌ | ❌ | M10 |
| Download proceedings / result document | ✅ own dept | ✅ own dept | ✅ | ✅ | ❌ | ❌ | M10 |
| Edit anything after approval | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

**The last row is the important one.** No role, including CGPSC Admin, may alter an approved result. This continues M5's rule for calculation runs and M6's for allotments and schedules, and it is enforced at the model layer.

`DpcProceedingPolicy` follows `DpcAllotmentPolicy`: **no department check**, because CGPSC users hold no department; the check is allottee identity. Department-side read access reuses the existing department-scoping used throughout M4.

## 8. Database Design

Four new tables. **All additive.** No existing column is modified or removed, in line with the standing change-control rule.

### `dpc_proceedings`

One row per sitting. Immutable after conclusion.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | internal only |
| `uuid` | uuid unique | **the public identifier** — every route and document reference |
| `dpc_proposal_id` | FK → `dpc_proposals` | |
| `dpc_schedule_id` | FK → `dpc_schedules` nullable | the slot it was held under; nullable because a schedule may later be superseded |
| `status` | string(20) index | `in_proceeding` / `result_approved` / `cancelled` |
| `conducted_by` | FK → `users` nullOnDelete | |
| `conducted_by_name` | string | **actor snapshot** — who conducted it *then*, per the M6 directive |
| `promotion_as_on_year` | smallint nullable | the S34 heading year — see **AQ-7** |
| `opened_at` | timestamp | |
| `proposal_version` | char(64) | SHA-256 content fingerprint at opening — the S31 evidence |
| `final_calculation_run_id` | FK → `dpc_calculation_runs` nullable | **exactly one run is pinned at approval** |
| `approved_at` / `signature_id` | timestamp / FK → `signatures` nullable | |
| `cancelled_at` / `cancellation_reason` | timestamp / text nullable | |
| `timestamps` | | |

Indexes: `(dpc_proposal_id, opened_at)`, `status`.

### `dpc_proceeding_overrides`

Append-only Commission corrections. **This table is why M4 stays frozen.**

| Column | Type | Notes |
|---|---|---|
| `id`, `uuid` | | uuid public |
| `dpc_proceeding_id` | FK cascade | |
| `dpc_evaluation_row_id` | FK → `dpc_evaluation_rows` nullOnDelete | the M4 row being corrected — **read, never written** |
| `sn` | smallint | serial number snapshot, so the record survives the row |
| `officer_name` | string | snapshot |
| `field` | string(50) | e.g. `tribal_area_service_years`, `cr_grade`, `property_flag` |
| `year` | smallint nullable | for per-year fields |
| `previous_value` / `new_value` | string nullable | **both recorded** — the previous value is what makes the correction auditable without replaying M4 |
| `reason` | text | **required** |
| `applied_by` / `applied_by_name` | FK / string | actor + snapshot |
| `superseded_by_id` | self-FK nullable | a later correction supersedes rather than overwrites |
| `timestamps` | | |

### `dpc_proceeding_holds`

`CIR` determinations — the officer participates but the promotion action is reserved.

| Column | Type | Notes |
|---|---|---|
| `id`, `uuid` | | |
| `dpc_proceeding_id` | FK cascade | |
| `dpc_evaluation_row_id` | FK nullOnDelete, `sn`, `officer_name` | link + snapshot |
| `reason_code` | string(30) | `cr_years_missing` / `property_missing` / `manual` |
| `detail` | string nullable | e.g. which years |
| `is_automatic` | boolean | detected by rule vs recorded by the Commission |
| `released_at` | timestamp nullable | **written by M9**, declared here so M9 is additive |
| `recorded_by` / `recorded_by_name`, `timestamps` | | |

### `dpc_proceeding_documents`

Generated artefacts. Append-only; never regenerated in place.

| Column | Type | Notes |
|---|---|---|
| `id`, `uuid` | | |
| `dpc_proceeding_id` | FK cascade | |
| `type` | string(20) | `proceedings` / `result` |
| `status` | string(20) | `pending` / `generated` / `failed` |
| `disk` / `path` | string nullable | via the storage abstraction |
| `content_hash` | char(64) nullable | SHA-256, matching the import-batch evidence precedent |
| `signature_id` | FK nullable | |
| `generated_at`, `failure_reason`, `timestamps` | | |

**Why a row exists before the file does:** the document is produced on the queue after approval commits. Creating the row in `pending` at approval means a job that never runs leaves visible evidence of an expected-but-absent legal document, instead of silence. See §27.

## 9. Entities

| Model | Mutability | Notes |
|---|---|---|
| `DpcProceeding` | Mutable only while `in_proceeding`; **frozen on approval or cancellation** | `HasUuids`. `booted()` throws on update/delete once terminal, following `DpcCalculationRun` |
| `DpcProceedingOverride` | **Append-only** | Permitted updates limited to `['superseded_by_id','updated_at']`, following `DpcAllotment` |
| `DpcProceedingHold` | **Append-only** | Permitted updates limited to `['released_at','updated_at']` — the M9 seam |
| `DpcProceedingDocument` | **Append-only after generation** | Permitted updates limited to `['status','disk','path','content_hash','signature_id','generated_at','failure_reason','updated_at']` |
| `DpcProceedingStatus` (enum) | — | `workflowState()`, `label()`, `badgeClass()`, `isTerminal()` |
| `HoldReason` (enum) | — | Only codes that can actually be produced, per the RemarkCode precedent |

**Known limitation, restated rather than assumed away:** model-layer immutability cannot stop a query-builder mass update. These four tables must be added to `docs/deployment/EVIDENCE-TABLE-PERMISSIONS.md` (TD-1) — with the same column-level-grant care, since `dpc_proceeding_documents` legitimately updates after insert.

## 10. Relationships

```
Department 1─* DpcProposal 1─* DpcAllotment      (M6)
                    │        1─* DpcSchedule      (M6)
                    │        1─* DpcCalculationRun 1─* DpcCalculationResult   (M5)
                    │
                    1─* DpcProceeding
                            ├─ 1  DpcSchedule            (the slot held under)
                            ├─ 1  DpcCalculationRun      (final_calculation_run_id — pinned at approval)
                            ├─ 1  Signature              (CGPSC approval)
                            ├─ 1─* DpcProceedingOverride ─* DpcEvaluationRow   (M4 — read only)
                            ├─ 1─* DpcProceedingHold     ─* DpcEvaluationRow   (M4 — read only)
                            └─ 1─* DpcProceedingDocument
```

**Every arrow into M4 and M5 is read-only.** M7 reads `dpc_evaluation_rows` and pins a `dpc_calculation_run`; it writes to neither. That is the structural expression of both freezes, and it is checkable by diff against `v0.6.0-m6-complete`.

## 11. Service Layer

Thin controllers → Domain Services → Eloquent models, under a new `app/Domain/Proceeding/` namespace (alongside `Cgpsc`, not inside it — M6 is frozen).

| Service | Responsibility |
|---|---|
| `ProceedingService` | `open()`, `state()`, `historyFromTimeline()`. Owns the lifecycle and the workflow transitions |
| `ProceedingCorrectionService` | `record()` an override, `supersede()`, and assemble the **effective chart** — M4 data with overrides applied in order. **Pure assembly; writes nothing to M4** |
| `HoldService` | `detect()` (configurable Q12 trigger), `record()`, `clear()` |
| `ResultApprovalService` | `approve()` — pins the calculation run, signs, transitions, creates `pending` document rows, dispatches generation |
| `ProceedingDocumentService` | Renders proceedings and result documents from stored data only |
| `CancellationService` | `cancel()` — reason required |

**The critical seam is `ProceedingCorrectionService::effectiveChart()`.** It produces the `OfficerRecord` value objects M5 already consumes, with overrides applied. `DpcCalculationService` is then called **unchanged** — it neither knows nor cares that its input came from a proceeding rather than directly from M4. This is what lets the Commission correct data and recalculate without touching one line of frozen engine code, and it is the single most important structural decision in this design.

**Purity discipline is preserved.** M5's engines stay pure — no DB, no clock, no `config()` reads. `ProceedingCorrectionService` sits on the M7 side of the boundary, exactly where `DpcCalculationService` already sits for M5.

## 12. Controller Layer

| Controller | Actions |
|---|---|
| `Cgpsc\ProceedingController` | `index` (queue), `show` (S33 workspace), `open`, `history` |
| `Cgpsc\ProceedingCorrectionController` | `store` (EDIT / TRIBAL), `destroy` (supersede) |
| `Cgpsc\ProceedingCalculationController` | `store` (CALCULATE — delegates to M5) |
| `Cgpsc\ProceedingHoldController` | `store`, `destroy` |
| `Cgpsc\ProceedingResultController` | `show` (S34), `approve` |
| `Cgpsc\ProceedingCancellationController` | `store` |
| `Dpc\ProposalResultController` | `show`, `download` — department-side, department-scoped |

Controllers validate through Form Requests, authorise through Policies, delegate to Services, and return views. No business logic, per `04-laravel-architecture-and-structure.md`.

## 13. Blade Screens

| View | Slide | Purpose |
|---|---|---|
| `cgpsc/proceedings/index` | S32 | The member's proceeding queue, by DPC ID |
| `cgpsc/proceedings/show` | **S33** | The workspace: full chart, effective values with corrections marked, `SAVE` / `EDIT` / `TRIBAL` / `CALCULATE` / `CANCEL DPC` |
| `cgpsc/proceedings/_chart` | S33 | Partial — the chart, reused by the result and document views so one renderer serves all three |
| `cgpsc/proceedings/result` | **S34** | Main List and Waiting List, `APPROVE BY CGPSC` |
| `cgpsc/proceedings/history` | — | Every proceeding event from the timeline alone |
| `dpc/proposals/result` | — | Department-side result and document download |
| `documents/proceedings`, `documents/result` | — | Document templates, rendered server-side |

Bootstrap 5.3, vendored. **No Node, no Vite, no new front-end dependency.**

Corrections are shown **old-beside-new with the previous value struck through**, exactly as M6 renders superseded allottees and schedule slots. The convention already exists; reusing it means the Commission reads corrections the same way it reads re-allotments.

## 14. Routes

Inside the existing `cgpsc` prefix (`routes/web.php`), joining M6's group:

```
GET    /cgpsc/proceedings                              proceedings.index
POST   /cgpsc/proceedings/{dpcProposal}/open           proceedings.open
GET    /cgpsc/proceedings/{proceeding}                 proceedings.show
GET    /cgpsc/proceedings/{proceeding}/history         proceedings.history
POST   /cgpsc/proceedings/{proceeding}/corrections     proceedings.corrections.store
DELETE /cgpsc/proceedings/{proceeding}/corrections/{c} proceedings.corrections.destroy
POST   /cgpsc/proceedings/{proceeding}/calculate       proceedings.calculate
POST   /cgpsc/proceedings/{proceeding}/holds           proceedings.holds.store
DELETE /cgpsc/proceedings/{proceeding}/holds/{hold}    proceedings.holds.destroy
GET    /cgpsc/proceedings/{proceeding}/result          proceedings.result.show
POST   /cgpsc/proceedings/{proceeding}/approve         proceedings.result.approve
POST   /cgpsc/proceedings/{proceeding}/cancel          proceedings.cancel

GET    /dpc/proposals/{dpcProposal}/result             proposals.result.show
GET    /dpc/proposals/{dpcProposal}/result/{document}  proposals.result.download
```

**`{proceeding}` binds by `uuid`**, per the standing UUID-only directive. `DELETE` on a correction supersedes; it never deletes.

## 15. Validation Rules

Form Requests only. Every message bilingual, through `lang/en` and `lang/hi`.

**`OpenProceedingRequest`**
- The proposal is `approved` and forwarded, has a current allotment, and has been accepted **and** scheduled.
- The actor is the current allottee.
- No non-terminal proceeding already exists for this proposal.
- Whether opening before the scheduled date/time is permitted — **AQ-5**.

**`RecordCorrectionRequest`**
- `field` is in an allow-list drawn from config, never free-form.
- `year`, when the field is per-year, is within the proposal's declared years.
- `new_value` is validated by the same rules M4 uses for that field — reusing `DpcRowRules`, so the manual, import and correction paths cannot drift. That reuse is the point: three code paths validating one field differently is how a bad grade eventually enters a promotion list.
- `reason` required, min length configurable.
- The proceeding is `in_proceeding`.

**`RecordHoldRequest`** — `reason_code` in enum; `detail` required when `manual`.

**`ApproveResultRequest`**
- A completed calculation run exists for the **current** effective chart. **If any correction post-dates the latest run, approval is refused** — naming the officers affected. Approving a list that does not reflect the last correction is the single most damaging defect available in this milestone.
- Every held case is either recorded or explicitly acknowledged.
- `promotion_as_on_year` present (subject to AQ-7).
- OTP step-up, per the Q1 signing rule.

**`CancelDpcRequest`** — reason required; proceeding not terminal.

## 16. Audit Requirements

Reuses `owen-it/laravel-auditing`, already configured. All four models are audited.

| Event | Audited |
|---|---|
| Proceeding opened | actor, IP, user agent, proposal fingerprint |
| Correction recorded / superseded | field, year, previous value, new value, reason, actor |
| Hold recorded / cleared | reason code, actor |
| Calculation run | already audited by M5 |
| Result approved | actor, signature id, pinned run uuid |
| DPC cancelled | actor, reason |
| Document generated / failed | type, hash, disk, path |

**Auditing must be asserted by test, not assumed.** `config/audit.php` had `console => false` hardcoded for five milestones, which silently disabled every audit assertion in the suite since M1 — found only at M4 closeout. `AUDIT_CONSOLE=true` is now set in `phpunit.xml`; M7's tests assert audit rows exist rather than trusting the configuration.

## 17. Timeline Events

Recorded through `ActivityTimelineService`, inheriting schema version, actor UUID, actor-name snapshot and deterministic `(occurred_at, uuid)` ordering. **No new timeline mechanism.**

| Event | Properties |
|---|---|
| `dpc_proceeding.open` | `from`, `to`, `proceeding_uuid`, `schedule_uuid`, `proposal_version` |
| `dpc_proceeding.correction` | `sn`, `officer_name`, `field`, `year`, `previous_value`, `new_value`, `reason` |
| `dpc_proceeding.correction_superseded` | `override_uuid`, `superseded_by_uuid` |
| `dpc_proceeding.hold` / `.hold_released` | `sn`, `officer_name`, `reason_code`, `detail` |
| `dpc_proceeding.calculate` | `run_uuid`, `rule_version`, `proposal_version`, counts |
| `dpc_proceeding.approve_result` | `from`, `to`, `run_uuid`, `signature_id`, `main_count`, `waiting_count`, `held_count`, `promotion_as_on_year` |
| `dpc_proceeding.cancel` | `from`, `to`, `reason` |
| `dpc_proceeding.document_generated` / `.document_failed` | `type`, `document_uuid`, `content_hash` / `failure_reason` |

**Replayability is a hard requirement.** Each correction event carries `previous_value` **and** `new_value`, so the full correction history can be reconstructed from the timeline without walking `superseded_by_id` through business tables — the same reason M6 records `previous_dpc_date` on a reschedule. A test must replay open → correct → calculate → approve and rebuild the result summary and a notification **without reading a business table**.

## 18. Notifications

Reuses `SendWorkflowNotification` — queued, `afterCommit`, per-recipient try/catch, dual recipient resolution. `WorkflowTransitioned` payload version stays **1**: M7 adds properties but changes no existing property's meaning, and the version rule is that adding a key never bumps it.

| Event | Recipients | Channel |
|---|---|---|
| Proceeding opened | AO, AH | SMS + e-mail |
| Result approved | AO, AH | SMS + e-mail |
| DPC cancelled | AO, AH | SMS + e-mail |
| Documents available | AO, AH | SMS + e-mail |

**"Documents available" fires on job completion, not on approval** — a message telling a department its result is ready, linking to a document that does not exist yet, is worse than a message thirty seconds later.

**A failed notification can never roll back a transition.** Already enforced in `WorkflowEngine::record()`; M7 inherits it and must not bypass it.

## 19. Feature Flags

**No new feature flag is introduced.** M7 needs none: it adds no external integration, and inventing a flag for an internal capability would dilute the meaning of the existing seven.

| Existing flag | Effect on M7 |
|---|---|
| `FEATURE_CSC_DIGISIGN` | Off → non-production signs the result through the mock; **production refuses to approve** and reports a business error. A promotion list carrying a development signature would look valid while being legally worthless |
| `FEATURE_S3_STORAGE` | Off → documents on the local disk; on → S3. Nothing in M7 knows which |
| `FEATURE_NOTIFICATIONS` | Off → no workflow notifications; the proceeding is unaffected |
| `FEATURE_SMS_GATEWAY` / `FEATURE_EMAIL_GATEWAY` | Off → delivery is logged |

**M7 must be fully demonstrable with every flag off**, and a test must assert an end-to-end proceeding completes in that configuration — the M4 precedent.

## 20. Configuration

Additive keys in `config/dpc.php`. No value is hardcoded.

```php
'proceeding' => [
    'correctable_fields'      => ['tribal_area_service_years', 'cr_grade', 'loyalty_flag', 'property_flag'],
    'correction_reason_min'   => 10,
    'allow_open_before_scheduled_time' => false,   // AQ-5
    'require_promotion_year'  => true,             // AQ-7
    'hold' => [
        'enabled'             => true,
        'min_cr_years'        => 5,   // fewer than this → held (Q12: "2+ missing" == "≤4 present")
        'property_required'   => true,
    ],
    'documents' => [
        'disk'   => env('DPC_PROCEEDING_DISK', 'local'),
        'format' => env('DPC_PROCEEDING_FORMAT', 'html'),   // AQ-3
        'queue'  => env('DPC_PROCEEDING_QUEUE', 'documents'),
    ],
],
'workflows' => ['dpc_proceeding' => ['transitions' => [ /* §5 */ ]]],
```

Every one of these is a **business rule expressed as configuration**, so a government notification changes a setting rather than source code — the M5 freeze doctrine applied to M7. Each gets a golden test, per the standing rule that all configurable business rules are pinned.

## 21. Future Integrations

No new integration. M7 **strengthens the case** for two already documented:

- **CSC DigiSign (BD-4)** — the result document is the most legally significant artefact the system produces. Whether the eSign API signs raw bytes or requires a PDF now directly constrains §20's `format`, because a text document cannot be sent to an API that requires a PDF. BD-4 and AQ-3 should be answered together.
- **Government SMS (TD-6)** — S26 requires the department to be informed on its registered mobile. Result publication is the notification that matters most, and it currently goes to a log file.
- **Calendar (INTEGRATION_ROADMAP §6)** — `dpc_proceeding.open` and `.approve_result` are natural subscribers. Adding them to `EVENT_CATALOG.md` costs nothing now and avoids retrofitting later.

## 22. Security Considerations

| Concern | Control |
|---|---|
| A member acting on another member's DPC | Policy enforces allottee identity, as `DpcAllotmentPolicy` does. **Tested with the negative case** |
| A department reading another department's result | Existing department scoping on every read |
| Guessing neighbouring records | **UUID-only** routing; sequential ids never leave the database |
| Tampering with an approved result | Model-layer immutability + evidence-table grants (TD-1) |
| Forged corrections | Reason mandatory, actor + snapshot recorded, append-only, on the timeline and in the audit trail |
| Signature bypass in production | `FEATURE_CSC_DIGISIGN` off in production → **refuse to approve**, never fall back to mock |
| Document tampering | SHA-256 `content_hash` at generation; verified on download |
| Unauthorised document access | Documents streamed through a policy-checked controller, **never a public disk URL** |
| CSRF / session | Framework defaults; unchanged |
| OTP step-up at signing | Existing mechanism, per Q1 |

## 23. Performance Considerations

The chart is bounded by the zone of consideration (2 × vacancies + 4), so the working set is small — tens of officers, not thousands. The risks are structural rather than volumetric:

| Risk | Mitigation |
|---|---|
| N+1 when assembling the effective chart | Eager-load rows with CR/loyalty/property children and all overrides in **one** keyed query, following M6's single-keyed-query pattern for superseded rows |
| Recalculating on every keystroke | CALCULATE is explicit and user-initiated, as the slide shows |
| Document generation blocking the request | **Queued**, `afterCommit` |
| Large timeline reads on the history screen | Paginate; ordering already indexed by intent, though see TD-4 |
| Repeated identical calculation runs | Runs are cheap and immutable; retention is intentional evidence |

Ordering by `(occurred_at, uuid)` is not covered by a composite index (**TD-4**). M7 adds the first routine timeline export, which moves TD-4 from theoretical to worth measuring.

## 24. VAPT Considerations

| Check | Expectation |
|---|---|
| IDOR on `{proceeding}`, `{document}`, `{correction}` | UUID + policy on every route. **Explicitly tested** |
| Forced browsing to `/result` before approval | Refused |
| Privilege escalation via role vs. allottee | Role alone is insufficient; identity is checked |
| Mass assignment on corrections | Form Request allow-lists; `field` from config |
| Path traversal in document download | Path never taken from user input; resolved from the stored record |
| Stored XSS via `reason` / `detail` | Blade escaping; no `{!! !!}` in M7 |
| Signature replay | Existing signature record binds signer, signable and timestamp |
| Race: two approvals of one proceeding | `lockForUpdate()` re-fetch before the status read — the M4 pattern, **not** M3's (see TD-5) |
| Race: correction during approval | Same lock; approval refuses if a correction post-dates the pinned run |
| Rate limiting | Existing middleware |

## 25. Testing Strategy

**The acceptance test is the PPT's own worked example.** S33 and S34 contain a complete, customer-authored answer key for ten officers, and reproducing it end to end is the strongest evidence available that M7 is correct:

1. Seed the S33 chart (M4 data).
2. Open the proceeding; CALCULATE → stage 2 marks and remarks: US1 15 `EL`, US3 **14 `NE`**, US6 `- CIR`, US8 20 `SS`, …
3. Record `TRIBAL 2021` on US3 → recalculate → **US3 becomes 15 and moves to `ST_W`**.
4. Assert holds: US6 (four CR years), US8 (2016 property `N`).
5. Assert the S34 result exactly: Main = US1 `UR`, US8 `ST SS CIR`; Waiting = US3 `ST_W`, US4 `UR_W CC`.

That single scenario exercises the correction pipeline, the frozen engine, the hold rules, seat allocation and the result — against the customer's own numbers rather than fixtures of our own devising.

Beyond it:

| Layer | Tests |
|---|---|
| Golden | Every configurable rule in §20 pinned against the **shipped** configuration |
| Workflow | Each transition from each legal state; each illegal transition refused |
| Policy | Allottee-only; other-member refused; cross-department refused |
| Immutability | Update and delete attempts on all four models, **including superseded rows** — the row a tamperer would target |
| Replay | Rebuild the result summary and a notification from timeline records alone |
| Audit | Audit rows actually written (see §16) |
| Notification | Asserted at the **listener**, resolving real users — not merely that the event carried recipients |
| Integrations off | Full proceeding with every `FEATURE_*` disabled |
| Signature | Production refuses to approve without CSC DigiSign |
| Concurrency | Two simultaneous approvals; correction racing approval |
| Determinism | Five events in one frozen second return an identical order on repeated reads |

Target: **60–75 new tests**, comparable to M6's 77 across four phases.

## 26. Quality Gates

Unchanged and mandatory per phase: **Pint · PHPStan (Larastan level 6) · PHPUnit · `composer audit` · `migrate:fresh --seed`**, plus Security, Performance, VAPT and PPT reviews and the 14-point Demo Readiness checklist.

Two M7-specific additions:

1. **Freeze verification, now that it is mechanically possible.** At every phase boundary:
   ```
   git diff v0.6.0-m6-complete -- app/Domain/Eligibility app/Domain/Cgpsc app/Domain/Dpc
   ```
   must be **empty** except for approved bug fixes. M7 is the first milestone that depends on two frozen milestones at once — the exact condition under which a freeze quietly gets breached.
2. **`composer audit` must be retried** on network failure (TD-2).

## 27. Risks

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| R1 | **Approving a result that does not reflect the last correction.** The single most damaging defect available here — a legally published promotion list contradicting the evidence beside it | **High** | Approval refuses when any correction post-dates the pinned run, naming affected officers; covered by test |
| R2 | **Document generation fails after approval**, leaving an approved DPC with no legal artefact | **High** | `pending` document row created at approval; retries; failures visible and alertable; publication notification waits for generation |
| R3 | **Correction pressure on the freeze.** The obvious implementation of `EDIT` is to update the M4 row. It is also the one that destroys the evidential model | **High** | Overrides are append-only and M4 is read-only; enforced by test and by freeze diff |
| R4 | Q7 unanswered → the legal output has no agreed format | **High** | **AQ-3**; phase 4 is separable so phases 1–3 proceed regardless |
| R5 | BD-2/BD-3 unsettled → an M7 result can be provisional | **High** | Provisional results are flagged and **must not be published**; escalation recommended before UAT |
| R6 | `CIR` scope creep into M9 | Medium | M7 marks; M9 resolves. `released_at` is the declared seam |
| R7 | SRS-derived expectations (voting, minutes, promotion order) surfacing during UAT | Medium | Conflict documented in §4; **AQ-8** |
| R8 | Chairman-vs-allottee authority wrong | Medium | **AQ-6** before the policy is written |
| R9 | Evidence-table grants still not applied (TD-1) | Medium | Four new tables added to the deployment document |
| R10 | First queued job in the project — queue not exercised in production | Medium | Redis queue already configured; job tested synchronously and queued |
| R11 | Result published while a hold is unresolved, read as a final promotion | Medium | Held officers rendered distinctly on the result and in the document |

## 28. Known Limitations

To be carried into `docs/TECHNICAL_DEBT.md` on approval — recorded now so they are not discovered at closeout:

1. **Model-layer immutability cannot stop a query-builder mass update** (TD-1). Four new evidence tables inherit this.
2. **A result document is generated once and never regenerated.** Correct for evidence; it means a template improvement does not reach historical results. Deliberate.
3. **No proceeding-level audit UI** — the record is complete but readable only from the database until M11 (TD-7).
4. **Document storage is unbounded**, like import evidence (TD-8). Capacity planning before go-live.
5. **`promotion_as_on_year` is captured at proceeding time**, not derived, pending AQ-7. If it is later derivable, historical rows keep the captured value — correct, but it means two sources of the same fact.
6. **Timeline ordering is still unindexed** (TD-4). M7 makes it routine.
7. **The proceeding cannot be re-opened.** By design — correction is Review DPC (M8) — but it means a mistaken `APPROVE BY CGPSC` is fixable only through a full Review DPC.

## 29. Open Business Questions

Existing, unchanged, in `docs/PENDING_BUSINESS_DECISIONS.md`:

| # | Question | M7 impact |
|---|---|---|
| **BD-2 / BD-3** | Reserved relaxation — automatic or committee discretion; scope beyond Class-I→I | **Highest.** In M5 an unsettled rule only marked a calculation provisional. **In M7 it decides what a published promotion order contains.** The cost of it being open rises sharply at this boundary |
| **BD-1** | Does `PUNISHMENT` disqualify? | S33 marks US2 and US10 `NE` at 15 marks; the engine passes them. The divergence becomes visible in a signed document |
| **BD-4** | CSC eSign — raw bytes or PDF? | Directly constrains the document format (AQ-3) |
| **Q7** | Proceedings/order PDF — generated? what layout? | **AQ-3** |
| **Q12** | Circulation trigger | §4 shows the two formulations are equivalent given six CR columns. Recorded as an observation; the ruling stays M9's |

**No new business rule is invented anywhere in this design.** Where the PPT is silent, an architect question is raised instead.

## 30. Implementation Phases

Four phases, matching the project's established rhythm. Each ends at a quality gate, documentation update and architect approval.

| Phase | Scope | Depends on |
|---|---|---|
| **1 — Proceeding lifecycle** | `dpc_proceedings`, status enum, policy, `open` transition, the S33 workspace **read-only**, CALCULATE via the frozen M5 engine, timeline, audit, notifications, history screen | AQ-5, AQ-6 |
| **2 — Commission corrections** | `dpc_proceeding_overrides`, `ProceedingCorrectionService`, effective-chart assembly, `EDIT` and `TRIBAL`, old-beside-new rendering, recalculation. **Acceptance: the US3 14 → 15 flip** | AQ-1, AQ-2 |
| **3 — Holds, result and approval** | `dpc_proceeding_holds`, configurable trigger, the S34 result screen, `APPROVE BY CGPSC` with signature, `CANCEL DPC`, result immutability. **Acceptance: the full S34 list** | AQ-4, AQ-7 |
| **4 — Documents and publication** | `dpc_proceeding_documents`, queued generation, storage abstraction, hashing, signing, department-side view and download, publication notification, closeout | **AQ-3**, BD-4 |

**Phases 1–3 do not depend on AQ-3**, so a delayed ruling on document format does not stall the milestone — only its final phase. If AQ-3 is answered late, phase 4 is the natural place to absorb it.

**Recommended sequencing note:** BD-2/BD-3 should be settled before **phase 3**, not before phase 1. Phases 1 and 2 are unaffected by reserved relaxation; phase 3 is where an unsettled rule starts determining what a signed document says.
