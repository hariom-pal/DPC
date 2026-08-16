# M9 — Circulation DPC: Design

**Status:** **APPROVED 2026-08-16. Phase 1 implemented** (46 tests, gate green). Phases 2 and 3 not started.
**Date:** 2026-08-16
**PPT source:** `ODPC_NEW_FINAL.pptx` S45–S48 (+ C3, C7)
**Depends on:** M4 (frozen), M5 (engine frozen), M6 (frozen), M7 (frozen), M8 (frozen — AD-21)
**Governed by:** **AD-23** — Circulation is Decision Resolution, not DPC Execution · **AD-24** — Circulation is its own aggregate
**Baseline:** `v0.8.0-m8-phase3` / `a60689d`

> **AD-23 (2026-08-16) makes §6 a rule, not a design property.** A Circulation implementation **shall never** invoke `DpcCalculationService`, `EligibilityService`, `ZoneOfConsiderationService` or `ProceedingService`, and shall never perform recalculation, eligibility determination, zone determination, DPC proceedings or scoring. **Violation is an Architecture Defect.** The full enforceable class list — including the indirect routes a name-based check would miss — is in `docs/ARCHITECTURE_DECISIONS.md` AD-23.
>
> **AD-24 (2026-08-16) rules AQ-15.** **Circulation SHALL be its own aggregate. It SHALL NOT reuse, extend or specialize `dpc_proposals`** — a Circulation is not a DPC lifecycle; it is an independent Commission decision workflow that resolves an already-decided held case. §11's schema is confirmed as designed. **AD-23 and AD-24 are the same principle at two layers:** one forbids a Circulation from *doing* what a DPC does, the other from *being* what a DPC is.

---

## 1. Objective

Resolve a promotion that a DPC **held** (`CIR`) because the officer's record was incomplete — CR unreported for two or more years, or the immovable-property return not recorded for one or more years — and obtain the Commission's consent, in the prescribed form, once the missing record is supplied.

## 2. The sentence that shapes this milestone

S45 states the whole rule in one paragraph:

> **"उक्त में से कोई एक अथवा अधिक टीप अंकित होने पर संबंधित अधिकारी/कर्मचारी को डी.पी.सी. की प्रक्रिया में शामिल तो किया जाता है, परंतु उस अधिकारी/कर्मचारी के संबंध में पदोन्नति की कार्यवाही को सुरक्षित कर लिया जाता है। जब संबंधित अधिकारी/कर्मचारी के संबंध में दर्शित टीप के अनुरुप निराकरण प्राप्त हो जाता है, तब उक्त अधिकारी/कर्मचारी की पदोन्नति के संबंध में निर्णय लेकर आयोग से निर्धारित प्रारुप में सहमति प्राप्त की जाती है।"**
>
> *"Where one or more such notes is recorded, the officer **is** included in the DPC process, but the promotion action in their respect is **reserved**. When the resolution corresponding to the recorded note is received, a decision is taken on that officer's promotion and the Commission's **consent is obtained in the prescribed form**."*

And C3/C7 name the artefact:

> *"…the **circulation note** (परिभ्रमण टीप) is sent to the Commission **for signature**."*

**Three things follow, and they are the whole design.**

| | What the deck says | Consequence |
|---|---|---|
| **1** | The officer *was* included and *was* evaluated. Only the **promotion action** was reserved | M9 resolves a **decision**, not a calculation. The score already exists and was already approved |
| **2** | What arrives later is a **resolution of the note** — the missing record | The trigger is evidence, not a re-run |
| **3** | The output is a **note signed by the Commission** | M9 produces one document and one signature. It is not a sitting |

**M9 is therefore not a DPC, despite its name.** M8 could be summarised as *"exactly the same process as Fresh"*; M9 is the opposite — it is the one promotion artefact in the system that **has no proposal, no chart, no committee, no zone, no allotment, no scheduling and no calculation.** Everything it needs was decided at the original sitting; what it adds is a decision on a reserved action and the Commission's signature on it.

**The single largest risk in this milestone is building it as though it were a DPC.** That is §10 R1 — **now closed by AD-24**, which rules that Circulation is its own aggregate and shall not reuse, extend or specialize `dpc_proposals`, because a Circulation is not a DPC lifecycle.

## 3. Scope

| Slide | Content | Delivered by |
|---|---|---|
| **C3 / C7** | Circulation DPC defined — the note goes to the Commission for signature | §2 |
| **S45** | The two hold grounds; the officer participates; the promotion action is reserved; resolution → decision → Commission's consent | Already half-built — M7 records the hold. **M9 resolves it** |
| **S46** | Chairman/Member login for Circulation ("ROTATION DPC") | M1, unchanged (approved Q1 deviation) |
| **S47** | Circulation action screen — post from/to, class, **type = CIRCULATION**, **fresh DPC date**, **circulation proposal date**, **DPC ID** of the origin | **New — §7** |
| **S48** | The origin's result **before** (US8: `ST SS CIR`) and **after** (US8: `ST SS`), with `APPROVE BY CGPSC` | **New — §8**, rendering M7's stored result |

## 4. Out of Scope

| Excluded | Belongs to |
|---|---|
| Self-Inspection and objections (S49–S51) | **M10** |
| Dashboards, reports, audit UI, full notification matrix | **M11** |
| **Any recalculation of the origin DPC** | Structurally excluded — §6, **AQ-16** |
| Changing any other officer's position, list or remark | Structurally excluded — §6 |
| Any change to M4, M5, M6, M7 or M8 **source** | Frozen. AD-15, AD-21 |
| A withdrawal or reversion document for a promotion already made | Pending **BD-11** (deferred), inherited unchanged |
| Placing a *new* hold after the sitting has concluded | Not in the deck. A new ground is a **Review** |

## 5. What is reused, unchanged

Stated first, because the failure mode M8 warned about (§5, R7 of `09-m8-review-dpc.md`) applies here with more force: M9 is small, and the temptation is to reach for M7's proceeding pipeline and edit a copy of it.

| Reused | From | Note |
|---|---|---|
| `dpc_proceeding_holds`, its append-only guard and its `released_at` seam | **M7** | The migration comment states outright: *"`released_at` is the declared seam for M9, which resolves a hold; M7 only marks it."* M9 writes **data** to it, never code |
| `HoldReason`, `HoldService::activeBySn()`, `isHeld()` | **M7** | Read-only use |
| The concluded proceeding, its approved calculation run and result | **M7** | Read-only. **Never recalculated** |
| Document render → store → hash → sign → publish pipeline | **M7** | Reused as a *pipeline*, with M9's own document type — see **AQ-22** |
| `SignatureService` and the Provider pattern behind `FEATURE_CSC_DIGISIGN` | M1 / AD-13 | Never mock-sign in production |
| `WorkflowEngine`, driven by configuration | M3 / **AD-11** | M9 adds a `dpc_circulation` workflow **as configuration**, not as an engine |
| `ActivityTimelineService`, versioned, actor-snapshotted, `(occurred_at, uuid)` ordered | M6 / AD-6, AD-7 | |
| Notification listeners, queued, `afterCommit`, never failing the workflow | M6 / **AD-12** | |
| The correction chain, `ChainRole`, `isSuperseded()` | **M8** | Read-only — to refuse a circulation against a superseded DPC (**AQ-18**) |
| Decoration as the way to extend a completed milestone | **AD-20** | With its stated ceiling — see §10 R8 |

**M9 adds no engine, no scoring, no zone logic, no chart and no proceeding.** If the implementation finds itself adding any of those, something has been misread.

## 6. The structural guarantee: a circulation cannot re-decide anything — **AD-23**

**Ruled 2026-08-16 as AD-23.** What follows was the design's central assertion; it is now a standing architecture decision, and **a breach is an Architecture Defect**, not a review comment.

> **AD-23 — the rule as ruled.** A Circulation implementation SHALL NEVER invoke `DpcCalculationService`, `EligibilityService`, `ZoneService` *(the class is `ZoneOfConsiderationService`)* or `ProceedingService`. A Circulation resolves an already-decided held case and obtains Commission consent. It must never perform **recalculation**, **eligibility determination**, **zone determination**, **DPC proceedings** or **scoring**.

**The forbidden set is larger than the four names**, because the ruling forbids the *acts* and two of the acts are reachable without naming their class. The full list is in AD-23; the two that a name-based check would miss:

- **`ProceedingCalculationService`** — M7's own entry point into `DpcCalculationService`. Forbidding the callee alone leaves the caller open.
- **`ReviewAwareCalculationService`** — M8's decorator, **bound over M7's service in the container**. A type-hint on the M7 class resolves to this one, so a container binding can defeat a rule written against a class name.

**What a Circulation does instead**, since a prohibition without an alternative gets worked around rather than obeyed: read `DpcCalculationRun` / `DpcCalculationResult` and `DpcProceeding` **as models** — reading a stored decision is not making one — and use `HoldService`'s read-only queries. Releasing the hold is a direct write to `released_at` at signature (AQ-21), never `HoldService::release()`, which asserts an open proceeding.

Everything else in this section is arranged to make the rule true by construction rather than by discipline.

**A circulation reads the origin's approved result and writes exactly one thing: whether a reserved promotion now proceeds.** It has no path to the scoring engine, no chart to correct, and no way to alter another officer's mark, rank, list or remark.

Why this matters more than it may appear: the officers below a held officer on the seniority list have a direct interest in whether that officer is promoted, and they were never party to the circulation. **A circulation that could recalculate would be a Review conducted without any of a Review's safeguards** — no reasons stated (S37), no AH approval of a corrected chart, no sitting, no supersession of the earlier list, and no notice to anyone affected.

**Consequences, each of which is a testable property:**

- M9 holds **no reference to `DpcCalculationService`** and no code path reaches it.
- The origin's `dpc_calculation_runs` row, its results, its documents and their hashes are **unchanged** by any circulation.
- The origin's `dpc_evaluation_rows` and every M4 section are **unchanged**. The supplied record is recorded as *evidence against the hold*, in M9's own table — not written back onto the frozen chart.
- The updated list S48 shows is **the same approved result with the `CIR` annotation removed** for the resolved officer. Nothing else on the page differs.

**The one case this rules out deliberately** is a `cr_years_missing` hold whose resolution would change the officer's mark. That is **AQ-16**, and the recommendation there is that such a case is a **Review**, not a circulation.

## 7. Raising a circulation (S47)

S47 collects six things. Five are display-only context and one is the actual input.

| Field on S47 | Source |
|---|---|
| Post to which promotion was proposed (`DEPUTY SECRETARY`) | From the origin proposal — **display** |
| Post from which (`UNDER SECRETARY`), Class (I/II/III) | From the origin proposal — **display** |
| `TYPE OF DPC` — Fresh / Review / **Circulation** | Fixed to `circulation` on this screen |
| **फ्रेश DPC की तिथि** — Fresh DPC date (`30/03/2018`) | From the origin proposal — **display** |
| **परिभ्रमण पदोन्नति प्रस्ताव तिथि** — circulation proposal date (`6 July 2026`) | **Entered** |
| **DPC ID** dropdown (`HE201711111`) | **Selected** — the origin DPC |

**The deck labels the date field "Fresh DPC date", and that label is too narrow.** S44 — the *Review* result screen — shows US8 carrying `ST SS CIR`. A Review can hold an officer exactly as a Fresh DPC can, so a circulation must be raisable against either. The field is *"the date of the DPC that held the officer"*; the label is a **Category-B documentation difference**, recorded, not implemented as a restriction.

**Candidate origins** are the department's own proposals that reached `result_approved`, whose concluded proceeding has **at least one hold still in force**, and which have **not been superseded** by a Review (**AQ-18**). Scoped by department, addressed by UUID (AD-8).

**Selecting the officers.** S47 does not show officer selection and S48 shows the whole chart, but a DPC may hold several officers whose records are resolved at different times. The design therefore treats the circulation as **one note covering one or more held officers** (**AQ-19**), each with its own resolution evidence — the note is per-DPC, matching the singular *परिभ्रमण टीप*, while the items are per-officer, matching reality.

## 8. The Commission's side (S46, S48)

S46 is the Chairman/Member login — M1, unchanged. S48 is the substance, and its structure is a **before-and-after pair**: the same result table twice, the first with `ST SS CIR` against US8, the second with `ST SS`.

That pairing is the screen's meaning: *this is what the DPC decided, and this is what it decides once the reserved action is released.* The design renders exactly that — both from M7's stored result, with the `CIR` annotation computed from hold state rather than from a second calculation.

**Per officer, the Commission records a decision**, and the honest set is two, not three:

| Decision | Effect |
|---|---|
| **Consent** — the promotion proceeds | The hold is resolved at signature; the officer's remark loses `CIR` |
| **Return for further evidence** | Back to the department. The hold stands, the officer stays held |

**There is deliberately no "refuse" that permanently denies the promotion.** S45 describes a decision taken *once the resolution is received*; it does not describe the Commission refusing a promotion on the strength of a circulation, and inventing that path would be inventing a business rule (**AD-2**). What happens when the supplied record is itself adverse — a CR of `घ`, which is a hard disqualifier — is a genuine question and is raised as **BD-12**, not designed.

**`APPROVE BY CGPSC` on S48 is the Commission's consent, and the operative act is the signature on the note** (C3: *sent to the Commission for signature*). See **AQ-17**.

## 9. Actors

| Actor | Role | What they do in M9 | Source |
|---|---|---|---|
| **Authorized Officer** | `authorized_officer` | Raises the circulation against a concluded DPC, selects the held officers, records the resolution evidence, submits | S45 (*"निराकरण प्राप्त हो जाता है… निर्णय लेकर"* — the department acts first) |
| **Administrative Head** | `administrative_head` | Approves and **signs** the circulation before it reaches the Commission; may return it | **AQ-23** — not shown in the deck; recommended by consistency with every other department-originated artefact |
| **Member (the origin DPC's allottee)** | `member` | Opens the circulation, records consent per officer, approves, **signs the circulation note** | S46, S48 |
| **Chairman** | `chairman` | May act **only after re-allotting the origin DPC to himself** — AQ-6's ruling reused verbatim, so exactly one actor is accountable | S46 (*"CHAIRMAN/ MEMBER"*), **AQ-20** |
| **Individual officer** | `individual` | Read-only visibility of their own hold and its resolution | M10 territory; **out of scope here** |

## 10. Risk Assessment

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| **R1** | **Building M9 as a DPC.** `dpc_types` already seeds `circulation`, and S47 looks proposal-shaped. Creating a `dpc_proposals` row drags in M4's submission gate (*a proposal cannot be submitted until S14→S18 is complete*), which a circulation can never satisfy — so it would be met either by copying the origin's chart, making it a Review in all but name, or by bypassing the gate, which is **exactly TD-23's defect** | **High** | **CLOSED by AD-24** (AQ-15 ruled 2026-08-16). Circulation is its own aggregate and shall not reuse, extend or specialize `dpc_proposals`. A Circulation found extending `DpcProposal` is an **Architecture Defect**, not a design preference |
| **R2** | **Recalculation back door.** Any reachable path from M9 to the scoring engine turns a clerical resolution into an unannounced re-decision affecting officers who were never told | **High** | **Ruled out by AD-23** — a breach is an **Architecture Defect**. Verified three ways at every phase boundary (§25): a dependency assertion, a byte-identity assertion on the origin's run/results/documents/hashes, and an every-other-row-unchanged assertion |
| **R3** | **A CR resolution changes the mark.** Supplying two missing CR years necessarily changes the score, and may change eligibility, rank, or displace another officer. The deck's only worked example (S48) is a **property** hold, where the score does not move — the CR case is **not shown anywhere** | **High** | **AQ-16.** Recommendation: refuse it in a circulation and direct it to a Review. Do not implement either branch before the ruling |
| **R4** | **Circulation against a superseded DPC.** M8 lets a Review supersede the origin. A circulation resolving a hold on a list that no longer governs would confirm a promotion under a superseded decision | **High** | **AQ-18.** Recommendation: refuse, naming the operative DPC in the chain. M8's `isSuperseded()` and `ChainRole` already answer it, read-only |
| **R5** | **Indefinite holds.** S45 sets no time limit. A promotion may remain reserved for years with nothing in the system saying so | Medium | **BD-13.** Meanwhile: make held promotions and their age **visible** rather than silently pending. Visibility is not a business rule |
| **R6** | **BD-11 inherited in full.** A circulation confirms a promotion; a later Review may supersede the DPC that promotion rests on. What happens to the officer is the same unanswered question | Medium | Recorded, not solved. BD-11 is deferred and blocks no milestone — it blocks **production activation** of the supersession policy, and M9 inherits that deferral unchanged |
| **R7** | **TD-20 reaches a fourth copy.** M9 needs department-scoped notification recipients; three copies of that query already exist | Medium | Do not add a fourth silently. §16 makes closing TD-19/20/21 a **precondition**, not a recommendation |
| **R8** | **A fifth decorator.** `ARCHITECTURE_BASELINE_v0.7.md` names five as the point to re-examine the M7/M8 boundary — and the gate run of 2026-08-14 proved **four was already enough**, with a container cycle that killed the PHP process | Medium | M9 owns its aggregate and its document type, so it **decorates nothing**. If a phase finds itself adding a fifth decorator, stop and raise it |
| **R9** | **MySQL identifier limit.** `dpc_document_review_references_dpc_proceeding_document_id_foreign` was 65 characters and cost a P0 that 83 green SQLite tests missed | Medium | Every FK constraint name in §11 is given explicitly and is ≤ 64 characters. **TD-24 must be closed before M9 begins** (§16) |
| **R10** | S48's first chart appears to show `C.CASE` against **US8**, where S44 shows that note against **US4** | Low | Verify against the rendered slide before implementing. Recorded as a deck observation, not a rule |

**Of the four that decide the milestone, two are now closed by ruling.** **R1** is closed by **AD-24** and **R2** by **AD-23**. **R3** (a score-changing CR resolution) and **R4** (a superseded origin) remain open on AQ-16 and AQ-18.

## 11. Database Design

**Confirmed by AD-24 (AQ-15 ruled 2026-08-16): Circulation is its own aggregate.** The three tables below are its own. **`dpc_proposals` is referenced by foreign key and never written** — no row of type `circulation` is created, no subclass of `DpcProposal` exists, and there is no shared base, single-table-inheritance discriminator or polymorphic proposal interface. The circulation carries its own `circulation_code`, never a `dpc_code`.

**Additive only. No M4, M5, M6, M7 or M8 table is altered, and no column is repurposed** (AD-16).

**One write reaches a frozen milestone's table:** `dpc_proceeding_holds.released_at`, which M7's migration declares as M9's seam and whose model guard already permits exactly that column and no other. **That is a data write, not a source change** — AD-15 and AD-21 freeze code, not rows — so it needs no Freeze Exception. Confirmed as **AQ-21**.

### `dpc_circulations` (new)

The note. One per raising, per origin DPC.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | uuid unique | the only public identifier (AD-8) |
| `department_id` | FK → `departments` | scoping, denormalised for the department's own index |
| `origin_dpc_proposal_id` | FK → `dpc_proposals` | the DPC that held the officers — Fresh **or** Review |
| `origin_dpc_proceeding_id` | FK → `dpc_proceedings` | the concluded sitting the holds belong to |
| `origin_dpc_code` | string | snapshot of the origin's DPC ID, as S47 displays it |
| `origin_dpc_date` | date | snapshot — S47's *"फ्रेश DPC की तिथि"* |
| `circulation_code` | string nullable unique | the note's own identifier, minted at department approval |
| `proposal_date` | date | S47's *"परिभ्रमण पदोन्नति प्रस्ताव तिथि"* |
| `status` | string(20) index | `draft` · `submitted` · `returned` · `approved` · `consented` · `signed` · `cancelled` |
| `raised_by`, `raised_by_name`, `raised_at` | | actor snapshot (AD-5) |
| `approved_by`, `approved_by_name`, `approved_at`, `approval_signature_id` | | department side (AH) |
| `consented_by`, `consented_by_name`, `consented_at` | | Commission side |
| `signature_id` | FK → `signatures` nullable | the Commission's signature — the operative act (**AQ-17**) |
| `timestamps`, `softDeletes` | | |

Indexes: `(department_id, status)`, `(origin_dpc_proposal_id)`.
Constraint names: `fk_dpc_circ_origin_proposal`, `fk_dpc_circ_origin_proceeding`, `fk_dpc_circ_department`, `fk_dpc_circ_signature` — all well inside 64 characters (R9).

### `dpc_circulation_items` (new)

One per held officer covered by the note. **This is where the resolution evidence lives** — never on the frozen chart.

| Column | Type | Notes |
|---|---|---|
| `id`, `uuid` | | |
| `dpc_circulation_id` | FK → `dpc_circulations` cascade | |
| `dpc_proceeding_hold_id` | FK → `dpc_proceeding_holds` restrict | the hold being resolved. **Restrict, not cascade** — a hold is a permanent record |
| `sn`, `officer_name` | | snapshots, so the item stays readable (AD-5) |
| `reason_code` | string(30) | snapshot of `HoldReason` at raising — what was resolved, as it stood |
| `resolution_detail` | text | what the department supplied, in words |
| `resolution_reference` | string nullable | the department's own file/letter reference |
| `resolution_received_on` | date | S45's *"निराकरण प्राप्त हो जाता है"* |
| `decision` | string(20) nullable | `consented` · `returned` — set by the Commission (§8) |
| `decided_by`, `decided_by_name`, `decided_at` | | actor snapshot |
| `hold_released_at` | timestamp nullable | mirrors what M9 wrote to the hold, so the item is self-contained |
| `timestamps` | | |

Unique `(dpc_circulation_id, dpc_proceeding_hold_id)`. Constraint names `fk_dpc_circ_item_circulation`, `fk_dpc_circ_item_hold`.

**No two open circulations may cover the same hold** — enforced in the service, not by a partial index, since MySQL and SQLite differ there (the same engine gap that produced TD-24).

### `dpc_circulation_documents` (new)

M7's `DocumentType` enum is **frozen** and cannot gain a `circulation_note` case — an enum case cannot be decorated, so this would be a source edit inside a frozen milestone. M9 therefore carries its own document type and table, reusing M7's render → store → hash → sign → publish *pipeline* without modifying it (**AQ-22**).

| Column | Type | Notes |
|---|---|---|
| `id`, `uuid` | | |
| `dpc_circulation_id` | FK → `dpc_circulations` cascade | |
| `document_type` | string(30) | `circulation_note` (the prescribed form), `revised_promotion_list` |
| `document_version`, `generator_version`, `generated_at` | | mirrors M7's Directive 3 |
| `disk`, `path`, `content_hash` | | SHA-256 over stored bytes, immutable (AD-4) |
| `signature_id` | FK → `signatures` nullable | |
| `published_at` | timestamp nullable | |

Constraint names `fk_dpc_circ_doc_circulation`, `fk_dpc_circ_doc_signature`.

### Reused, unchanged

| Existing | Provides |
|---|---|
| `dpc_proceeding_holds` (+ `released_at`) | the holds M9 resolves, and the declared seam |
| `dpc_proceedings`, `dpc_calculation_runs`, `dpc_calculation_results` | the origin's approved result — **read-only** |
| `dpc_proposals`, `dpc_review_links` | the origin, its code and its chain position |
| `dpc_types.circulation` | already seeded (M2) — used as S47's type label |
| `signatures`, `activity_timelines`, `audits`, `workflow_transitions` | signature, timeline, audit, workflow ledger |

## 12. Workflow and state transitions

**No new workflow engine.** M9 adds a `dpc_circulation` definition to `config/dpc.php` — configuration, not code (AD-11, AD-3), running on the circulation as subject.

```
                       ┌──────── return ────────┐
                       ▼                        │
   draft ──submit──> submitted ──approve(sig)──┴─> approved ──consent──> consented ──sign(sig)──> signed
     │  ▲                 │                                    │                                     │
     │  └──── return ─────┘                                    └──── return_to_department ───────────┤
     │                                                                     (back to `returned`)      │
     └── cancel ──> cancelled                                                                        ▼
                                                                                            holds resolved,
                                                                                            note published
```

| Transition | From → To | Role | Signature | Notify |
|---|---|---|---|---|
| `submit` | `draft`, `returned` → `submitted` | `authorized_officer` | no | `administrative_head` |
| `approve` | `submitted` → `approved` | `administrative_head` | **yes** | `authorized_officer` + CGPSC |
| `return` | `submitted` → `returned` | `administrative_head` | no | `authorized_officer` |
| `cancel` | `draft`, `returned` → `cancelled` | `authorized_officer` | no | `administrative_head` |
| `consent` | `approved` → `consented` | `member` (the origin's allottee) | no | `authorized_officer`, `administrative_head` |
| `return_to_department` | `approved` → `returned` | `member` | no | `authorized_officer`, `administrative_head` |
| `sign` | `consented` → `signed` | `member` | **yes** | `authorized_officer`, `administrative_head` |

**Terminal states:** `signed`, `cancelled`. **`signed` is where the holds are resolved** — nothing before it changes a promotion (**AQ-17**, and the same reasoning as M8's AQ-9: an artefact that is never signed must leave the original standing).

**`consent` and `sign` are separate transitions on purpose.** The Commission's decision per officer and the signature on the prescribed form are different acts, and C3 names the signature as what the note is sent for. Collapsing them would make the per-officer decisions unrecorded intent.

## 13. Sequence diagrams

### 13.1 Raising and approving, department side (S45 → S47)

```
 AO            CirculationService      Holds(M7)     Workflow      Timeline    AH
  │                    │                   │            │             │         │
  │ open S47           │                   │            │             │         │
  ├───────────────────>│                   │            │             │         │
  │                    │ eligibleOrigins() │            │             │         │
  │                    ├──────────────────>│ concluded + held + not superseded  │
  │                    │<──────────────────┤            │             │         │
  │<─ origins, held officers ──────────────┤            │             │         │
  │                    │                   │            │             │         │
  │ raise(origin, items[], evidence)       │            │             │         │
  ├───────────────────>│                   │            │             │         │
  │                    │ assert: concluded, own dept, hold active,    │         │
  │                    │         not superseded (AQ-18), no open      │         │
  │                    │         circulation on the same hold         │         │
  │                    ├── create dpc_circulations + items ──────────>│         │
  │                    ├── record 'dpc_circulation.raised' ──────────>│         │
  │<─ circulation ─────┤                   │            │             │         │
  │                    │                   │            │             │         │
  │ submit             │                   │            │             │         │
  ├───────────────────>├── transition ────────────────> │             │         │
  │                    │                   │            ├── notify ─────────────>│
  │                    │                   │            │             │         │
  │                    │            approve + OTP step-up signature   │<────────┤
  │                    │<──────────────────────────────────────────── ┤         │
  │                    ├── mint circulation_code, forward to CGPSC ──>│         │
```

### 13.2 The Commission's decision and signature (S46 → S48)

```
 Member        CirculationService     Proceeding(M7)   Holds(M7)   Documents   Signature   Dept
   │                  │                     │             │            │           │        │
   │ open (S48)       │                     │             │            │           │        │
   ├─────────────────>│ approvedResult(origin) ──────────>│            │           │        │
   │                  │<── stored result, unchanged ──────┤            │           │        │
   │<─ before/after view (CIR from hold state, not a calculation) ─────┤           │        │
   │                  │                     │             │            │           │        │
   │ consent(item, …) │                     │             │            │           │        │
   ├─────────────────>├── item.decision = consented ──────────────────>│           │        │
   │                  ├── 'dpc_circulation.item_consented' ───────────>│           │        │
   │                  │        ** no hold released yet **              │           │        │
   │                  │                     │             │            │           │        │
   │ sign (APPROVE BY CGPSC + OTP step-up)  │             │            │           │        │
   ├─────────────────>│                     │             │            │           │        │
   │                  ├─ render circulation note + revised list ──────>│           │        │
   │                  │<─ bytes ───────────────────────────────────────┤           │        │
   │                  ├─ store, SHA-256, sign ────────────────────────────────────>│        │
   │                  ├─ holds.released_at = now()  (declared M7 seam, AQ-21) ────>│        │
   │                  ├─ 'dpc_circulation.hold_resolved' per item ────>│           │        │
   │                  ├─ 'dpc_circulation.signed' ────────────────────>│           │        │
   │                  ├─ CirculationSigned event ─── queued, afterCommit ─────────────────> │
```

### 13.3 What resolution does, and does not, touch

```
                    ┌──────────────────────────────────────────────┐
                    │  ORIGIN DPC (M4/M5/M7 — frozen, read-only)   │
                    │  proposal · chart · calculation run ·        │
                    │  results · documents · hashes                │
                    └──────────────────────────────────────────────┘
                                        ▲
                                        │ read only — no write, ever
                                        │
   ┌─────────────────────┐   resolves   │              ┌──────────────────────────┐
   │  dpc_circulations   ├──────────────┴─────────────>│  dpc_proceeding_holds    │
   │  dpc_circulation_*  │   writes released_at only   │  (append-only; the guard │
   └─────────────────────┘   at `sign`                 │   permits this column)   │
                                                       └──────────────────────────┘

   Everything the officer's promotion now rests on is the ORIGIN's approved
   result. The circulation adds a decision and a signature. It adds no marks.
```

## 14. Permissions

Enforced at three layers, as every milestone since M4 does: route middleware → **Policy** → Service assertion. A role alone is never sufficient.

| Ability | Rule |
|---|---|
| `viewAny` | `authorized_officer` / `administrative_head` — own department only; `chairman` / `member` — all |
| `create` | `authorized_officer`, **own department**, origin concluded, at least one hold in force, origin not superseded |
| `update` | `authorized_officer`, own department, status `draft` or `returned` only |
| `submit` | as `update` |
| `approve` / `return` | `administrative_head`, own department, status `submitted` |
| `consent` / `return_to_department` | **the origin DPC's allottee**, status `approved`. Holding the `member` role is not enough — the same distinction M7's `DpcProceedingPolicy` already makes |
| `sign` | the origin DPC's allottee, status `consented`. A **Chairman must re-allot the origin to himself first** (AQ-6 reused, **AQ-20**) |
| `viewDocument` | own department, or any CGPSC role. Published documents remain downloadable |

**Cross-department access is refused at the Policy layer and asserted again in the Service** — the pattern M4 established and every milestone has kept.

## 15. Notifications

Queued, dispatched `afterCommit`, and **a failing notification can never fail the workflow** (**AD-12** — the defect M6 found the hard way). All delivery sits behind `FEATURE_SMS_GATEWAY` / mail, off by default (AD-13).

| Event | Recipients | Content |
|---|---|---|
| `circulation.submitted` | AH of the department | A circulation awaits approval, with the origin DPC code |
| `circulation.approved` | AO; CGPSC (chairman + the origin's allottee) | Forwarded for the Commission's consent |
| `circulation.returned` | AO, AH | Returned, with the reason |
| `circulation.consented` | AO, AH | The Commission has consented; the note awaits signature |
| **`circulation.signed`** | AO, AH | **The operative message.** Names the origin DPC code, the circulation code, and **each officer whose promotion now proceeds** — because that is what the department must act on |

**`circulation.signed` must name the officers, not just the DPC.** A department holding a promotion list with `CIR` against two officers needs to know which of them the note released; a message that names only the DPC leaves them to work it out.

**TD-20 becomes four copies here.** The department-scoped recipient query is already duplicated three times. §16 makes extracting it a precondition rather than adding a fourth.

## 16. Audit trail

Two independent records, as since M1: `owen-it/laravel-auditing` on the models, and the **Activity Timeline** as the legal workflow history (**AD-6**), versioned, UUID-identified, actor-snapshotted and ordered by `(occurred_at, uuid)` (**AD-7**).

**Timeline events are recorded on the origin `DpcProposal` as subject**, exactly as M6, M7 and M8 do, so *"show me everything that happened to this DPC"* remains one ordered query — the circulation is part of that DPC's history, not a separate thread.

| Event | Recorded when | Key properties |
|---|---|---|
| `dpc_circulation.raised` | AO raises | circulation uuid, origin code, items (sn, name, reason, evidence reference) |
| `dpc_circulation.submitted` / `.returned` / `.cancelled` | workflow | actor snapshot, remarks |
| `dpc_circulation.approved` | AH signs | signature id, circulation code |
| `dpc_circulation.item_consented` | per officer | sn, name, hold uuid, decision, actor |
| `dpc_circulation.signed` | Commission signs | signature id, document uuids, content hashes |
| `dpc_circulation.hold_resolved` | per hold, at signature | hold uuid, sn, name, reason, `released_at` |
| `dpc_circulation.document_published` | publication | document uuid, type, hash |

**`hold_resolved` is written per hold and not folded into `signed`.** A hold is the reason a promotion did not take effect; its release is the reason one did, and each release must be answerable on its own.

**Replayability is a required property, not an aspiration:** a test must rebuild the `circulation.signed` notification from timeline records alone, without reading a business table — the standard M6 set and every milestone since has met.

## 17. Reuse opportunities from M4–M8

| From | Reused | How |
|---|---|---|
| **M4** | AO/AH two-step with a single signed approval; department scoping; Form Request validation idiom | Same shape for the circulation's department side |
| **M4** | `dpc_types.circulation`, already seeded | S47's type label **only**. **Not** a proposal row and not a proposal discriminator — **AD-24** |
| **M5** | Nothing at run time, by design | §6. M9 must not reach the engine |
| **M6** | `WorkflowEngine` by configuration; append-only allotment; timeline as legal ledger; notification-at-listener testing | `dpc_circulation` workflow definition |
| **M6** | The allottee distinction — role ≠ being *this* DPC's member | `consent` / `sign` permissions |
| **M7** | `dpc_proceeding_holds` + the `released_at` seam; `HoldReason`; hold queries | The milestone's entire input |
| **M7** | The approved result, read-only | S48's before/after |
| **M7** | render → store → SHA-256 → sign → publish | The circulation note, with M9's own type (AQ-22) |
| **M7** | AQ-6 (the allottee conducts; a Chairman re-allots first), AQ-7 (`promotion_as_on_year` captured, never derived) | Reused verbatim; AQ-7 bears directly on **BD-14** |
| **M8** | `ChainRole`, `isSuperseded()`, `chain()` | Refusing a circulation on a superseded DPC (AQ-18) |
| **M8** | AQ-9's *effect-at-approval* reasoning | AQ-17 — effect at signature, for the same reason |
| **M8** | AD-20 decoration, **and its documented ceiling** | Used as a warning, not a tool — M9 decorates nothing (R8) |
| **M8** | Copy-on-create's field-by-field equality testing idiom | Asserting the origin is unchanged |

## 18. Security review

| Concern | Design answer |
|---|---|
| Resolving a hold on another department's DPC | Department scoping at Policy **and** Service; origin resolved from the record, never from request input |
| Forging a resolution | The hold is loaded from `dpc_proceeding_holds` by the circulation item; no client-supplied hold id is trusted without an ownership check through the origin proceeding |
| Releasing a hold without a signature | `released_at` is written **only** inside the `sign` transaction, after the signature succeeds. Nothing earlier in the workflow touches it |
| Un-resolving a hold | Refused. The hold model's guard permits `released_at` transitions only, and M9 writes null → timestamp once |
| **Recalculation as an escalation path** | §6: no code path from M9 reaches the engine. Tested by asserting the origin's run, results, documents and hashes are unchanged |
| Circulating a superseded DPC | Refused at raising and re-checked at signature — a Review may be approved in between (**AQ-18**) |
| Two circulations racing on the same hold | Uniqueness per `(circulation, hold)`, a service-level check for any other open circulation on that hold, and `lockForUpdate()` on the circulation at `consent` and `sign` — M4's lock discipline, which M3 never got (TD-5) |
| Document substitution | SHA-256 over stored bytes; the note is immutable once signed (AD-4) |
| Replay of the signed notification | The event carries the circulation UUID and the origin DPC code, so a subscriber can deduplicate — the M8 pattern |
| Mock signature reaching production | `FEATURE_CSC_DIGISIGN` off by default; **never mock-sign in production** (AD-13, standing rule) |
| Cross-site / CSRF, rate limiting | Unchanged from the platform baseline; the two signing endpoints inherit the OTP step-up |

## 19. Performance considerations

| Concern | Assessment |
|---|---|
| Finding eligible origins | One indexed query — `dpc_proposals` by department and status, joined to a `dpc_proceeding_holds` existence check. Bounded by a department's own DPC count |
| Rendering S48's before/after | **Two renderings of one stored result**, not two calculations. The `CIR` annotation is a hold-state lookup, already indexed by `(proceeding, sn, reason)` |
| Resolution at signature | One update per hold plus one timeline row per hold, inside the signature transaction. Bounded by held officers in a single DPC — single digits in practice |
| Document generation | Two documents per circulation, generated once, stored, never regenerated (AD-4) |
| Notification fan-out | Queued; department-scoped role query, the same shape as the existing three |
| N+1 exposure | Items eager-load their hold and the hold's evaluation row; the before/after view loads results once and indexes them by `sn` in memory |
| **What is deliberately absent** | No scoring, no zone computation, no chart assembly. M9 is the cheapest milestone in the project, and if a phase finds itself optimising a calculation, §6 has been breached |

## 20. PPT mapping

| Slide | Element | Status |
|---|---|---|
| **C3 / C7** | Circulation defined; note sent to the Commission for signature | §2, §12 — `sign` is the operative act |
| **S45** | Two hold grounds: CR unreported 2+ years; property not recorded 1+ years | **Already built** — `HoldReason::CrYearsMissing`, `PropertyMissing` (M7) |
| **S45** | Officer participates; promotion action reserved | **Already built** — M7 holds |
| **S45** | Resolution received → decision → Commission's consent in the prescribed form | **M9** — §7, §8, §12 |
| **S46** | Chairman/Member login ("ROTATION DPC") | M1, unchanged. Category-B: Aadhaar OTP replaced per Q1 |
| **S47** | Post from/to, class, type = CIRCULATION, fresh DPC date, circulation proposal date, DPC ID | **M9** — §7 |
| **S47** | Field labelled *"Fresh DPC date"* | **Category B.** A Review can also hold an officer (S44 shows `ST SS CIR`), so the origin may be either. Label narrower than the rule |
| **S48** | Before/after result, `CIR` removed for the resolved officer | **M9** — §8, from M7's stored result |
| **S48** | `APPROVE BY CGPSC` | **M9** — `consent`, then `sign` |
| **S48** | Apparent `C.CASE` against US8 where S44 shows it against US4 | **To verify** against the rendered slide — R10 |
| **S48** | US8 remains `20 / ST SS` before and after | Confirms the deck's worked example is a **property** hold with no score change — the CR case is unshown (**AQ-16**) |

## 21. Business questions — Deferred Because of Business

*Per the standing directive: a milestone states at design time what it cannot settle, rather than discovering it at closure.*

| # | Question | Effect on M9 | Status |
|---|---|---|---|
| **BD-14** *(new)* | **From what date does a circulated promotion take effect?** The origin DPC says *"PROMOTION AS ON 2017"*; the circulation is raised in 2026. Is the promotion retrospective to the original DPC's year — with the seniority and pay consequences that carries for everyone below — or effective from the date of the Commission's consent? | **Decides what the note says and what the revised list shows.** M7 captures `promotion_as_on_year` at approval and never derives it (AQ-7), so the field exists; what it should hold for a circulated promotion is unruled | **Raise to CGPSC. The defining business question of this milestone** |
| **BD-12** *(new)* | **What if the supplied record is adverse?** A CR of `घ` is a hard disqualifier. Does the Commission refuse the promotion, and if so does that change anyone else's position on a list already published? | Decides whether §8's two-decision set needs a third. **Do not invent it** (AD-2) | **Raise to CGPSC** |
| **BD-13** *(new)* | **Is there a time limit on a held promotion?** S45 sets none | Nothing blocks. But an unbounded reservation of a promotion should be a decision, not an oversight | **Raise to CGPSC** |
| **BD-11** | What supersession means for an officer already promoted | Inherited unchanged. A circulated promotion sits inside a DPC a Review may later supersede | **Deferred** — *Required before production activation of the Review supersession policy.* Blocks no milestone |
| **BD-2 / BD-3** | Reserved relaxation | The origin's result is used as approved. If it was provisional, the circulation inherits that and **cannot be published** | Open |
| **BD-9 / BD-10** | Waiting-list placement; pending court case | Inherited through the origin's result | Open |

**What M9 can build regardless:** §§7, 8, 11, 12, 14, 15 and 16 in full. Only the note's statement of the effective date (BD-14) and the adverse-record path (BD-12) are blocked, and both sit in Phase 3.

## 22. Technical debt

| # | Item | Bearing on M9 |
|---|---|---|
| **TD-24** | Suite runs on in-memory SQLite; the application runs on MySQL | **Must be closed before M9 begins.** It already cost a P0 that 83 green tests missed, and M9 adds three tables with foreign keys (R9). No Freeze Exception needed — it is a CI change |
| **TD-20** | Department-scoped notification recipient query duplicated | M9 would make it **four** copies (R7). Extract first |
| **TD-19** | `DpcProposal` has no `HasUuids` | **Avoided outright by AD-24.** M9 creates no proposal row, so it adds no fourth caller to the direct-update workaround. The item stays open for M4; M9 simply does not touch it |
| **TD-23** | M8's end-to-end test bypasses M4's submission gate | Must **not** be repeated. M9's end-to-end test drives the real path from a genuinely concluded DPC with a genuine hold |
| **TD-21** | Engine version absent from `dpc_calculation_runs` | Unchanged. M9 records no calculation, so it adds nothing here |
| **TD-22** | Rule-reuse config override is process-global | Untouched — M9 never invokes the engine, so it never sets the override |
| **TD-18** | The approval transition carries no signature | The `approve` and `sign` transitions here declare `signature: true`; whether the *transition* records it is the same open item |
| **TD-6** | No SMS or e-mail gateway | §15 is untestable end-to-end in production until this lands. Unchanged |
| **New, if AQ-22 is ruled otherwise** | Two document tables and two document-type enums | If the ruling is instead to open M7 for a `circulation_note` enum case, that is a **single-change Freeze Exception under AD-22** and must be recorded as such |

## 23. Phase breakdown

| Phase | Scope | Deliverable | Blocked by |
|---|---|---|---|
| **1** ✅ **DONE 2026-08-16** | **The circulation aggregate and the department's side.** Delivered: `dpc_circulations` + `dpc_circulation_items`; `CirculationService` (`eligibleOrigins()`, `outstandingHolds()`, `assessGrounds()`, `raise()`); `CirculationWorkflowService`; the `dpc_circulation` workflow definition; `CirculationCodeService`; S47 and the index/show screens; AO submit → AH approve with signature; policy; timeline; **BD-15 detection and structured refusal**; and the AD-23/AD-24 architecture check | A department raises and approves a circulation against a concluded DPC with holds in force. The origin is provably untouched. **46 tests / 118 assertions** | Delivered under AD-24, AQ-18, AQ-19, AQ-23 as recommended |
| **2** | **The Commission's side.** S46 entry; S48's before/after rendered from M7's stored result; per-officer `consent`; `return_to_department`; `sign` with OTP step-up; **hold resolution at signature**; notifications; replay test | A held promotion is released by a signed circulation, and the release is answerable per hold | AQ-16, AQ-17, AQ-20, AQ-21 |
| **3** | **The prescribed form and closeout.** The circulation note document and the revised promotion list — rendered, stored, hashed, signed, published; the effective-date statement; full PPT verification; security, performance and VAPT reviews; demo readiness | The note S45 calls *"निर्धारित प्रारुप"* exists and is signed | **BD-14** (what the note says about the effective date), **BD-12**, AQ-22 |

**Phase 1 is the largest and is unblocked once the architect questions in §24 are ruled.** Phase 3 is small and is the only one holding business dependencies — stated here rather than discovered at closure, as M7 discovered BD-9 and BD-10.

## 24. Architect questions requiring approval

**Numbering continues from M8 (AQ-1 … AQ-14). Nothing is implemented until these are ruled.**

| # | Question | Recommendation | Why it matters |
|---|---|---|---|
| **AQ-15** ✅ **RULED — AD-24** | **Is a Circulation a `dpc_proposals` row of type `circulation`, or its own aggregate?** | **RULED 2026-08-16: its own aggregate.** *"Circulation SHALL be its own aggregate. It SHALL NOT reuse, extend or specialize `dpc_proposals`"* — a Circulation is not a DPC lifecycle; it is an independent Commission decision workflow resolving an already-decided held case. Recorded as **AD-24** | Settled. §11's schema is confirmed as designed: no proposal row, no subclass, no shared base, no polymorphic proposal interface. `dpc_types.circulation` stays as S47's label only, and the circulation carries its own `circulation_code`, never a `dpc_code` |
| **AQ-16** | **May a `cr_years_missing` hold be resolved by circulation when the supplied CR would change the officer's mark?** | **No.** Refuse it and direct the department to a Review. A property resolution changes no score and stays in M9 | A recalculation can change eligibility, rank, and displace another officer who was never party to the circulation and has no notice of it. The deck's only worked example (S48) is a property hold; the CR case is shown nowhere. **This is the M9 analogue of AQ-11** |
| **AQ-17** | **Does the circulation take effect at the Commission's consent, or at signature on the note?** | **At signature.** C3 says the note is sent *for signature*; consent is recorded intent | Same reasoning as AQ-9: a circulation that is consented but never signed must leave the promotion reserved. Otherwise an unsigned note releases a promotion |
| **AQ-18** | **May a circulation be raised against a DPC that a Review has superseded?** | **No.** Refuse, naming the operative DPC in the chain. Re-check at signature, since a Review may be approved in between | Confirming a promotion under a list that no longer governs is exactly what the supersession watermark exists to prevent. M8 already answers it read-only |
| **AQ-19** | **One circulation per officer, or one per DPC covering several held officers?** | **One per DPC, many items.** The note is per-DPC (singular *परिभ्रमण टीप*); the items are per-officer | Holds on one DPC are resolved at different times. One-per-officer would multiply notes; one-per-DPC without items could not record a partial resolution |
| **AQ-20** | **Who signs on the Commission's side — the Chairman, or the origin DPC's allottee?** S46 says *"CHAIRMAN/ MEMBER"* | **The origin's allottee**, with the Chairman able to act only after re-allotting the origin to himself | **AQ-6 reused verbatim** — exactly one actor accountable for a DPC, and the person who held the promotion is the person who releases it |
| **AQ-21** | **May M9 write `dpc_proceeding_holds.released_at`, a column in frozen M7?** | **Yes**, at signature only. It is a **data** write, not a source change; AD-15 and AD-21 freeze code, and M7's migration declares this column as M9's seam while its model guard permits that column and no other | If the answer is no, M9 needs a parallel resolution flag and the hold's own `active` scope stops telling the truth — two sources for one fact |
| **AQ-22** | **Documents:** M7's `DocumentType` enum is frozen and an enum case cannot be decorated. Does M9 get its own document type and table, or does M7 open for a new case? | **M9's own**, reusing M7's render → store → sign → publish pipeline unmodified | Adding an enum case is a source edit inside a frozen milestone — a single-change **Freeze Exception under AD-22** if ruled the other way, and it must then be recorded as one |
| **AQ-23** | **Does the department's side require AH approval and signature, or does the AO raise straight to the Commission?** The deck shows neither | **AH approval with signature**, mirroring M4 | Every department-originated artefact in this system is signed by the AH before it reaches CGPSC. A confirmation that a promotion now proceeds is at least as significant as the proposal that produced it |
| **AQ-24** | **Should a held promotion's age be surfaced** — a list of holds outstanding, with how long? | **Yes**, as visibility only, in Phase 1 | BD-13 (a time limit) is a business rule and is not being invented. **Showing** how long a promotion has been reserved is not a rule, and without it an indefinite hold is invisible |

**AQ-15 is ruled (AD-24) and the schema is settled.** **AQ-16 is the one that remains undeferrable** — it decides whether Phase 2 has a refusal path to build, and **AD-23 already blocks it being ruled the other way without a decision that supersedes AD-23.** The remaining eight are Phase 2 and Phase 3 concerns.

## 25. Testing strategy

| Layer | Tests |
|---|---|
| **AD-23 — dependency assertion** | No class under the Circulation namespace names any class in AD-23's forbidden list — in a constructor, a type-hint, a container resolution or a facade call. **Including `ProceedingCalculationService` and `ReviewAwareCalculationService`**, which are how the engine is reached without being named |
| **AD-23 — byte identity** | After a full circulation, the origin's proposal, chart, calculation run, results, documents **and their SHA-256 hashes** are byte-identical. Asserted field by field, not by row count |
| **AD-23 — every other row unchanged** | Mark, remark, list and position identical for every officer except the resolved one, whose row differs **only** by the `CIR` annotation |
| Raising | Only a concluded DPC with a hold in force; own department only; superseded origins refused (AQ-18); no second open circulation on the same hold |
| Workflow | Every transition in §12, including `return` from both sides; terminal states refuse further transitions |
| **Effect at signature** | A consented-but-unsigned circulation leaves `released_at` null and the officer still held. **The mirror of M8's supersession test** |
| Hold resolution | Per hold: released exactly once, at signature, with a `hold_resolved` timeline event carrying the hold UUID |
| Worked example | S48's US8 — a **property** hold, mark unchanged at 20, remark `ST SS CIR` → `ST SS`, everyone else's row identical |
| CR case | Whichever AQ-16 rules, pinned by test — including the refusal path if that is the ruling |
| Permissions | Cross-department refused at Policy and Service; a `member` who is not the allottee refused; a Chairman refused until re-allotment |
| Notifications | Asserted **at the listener**, not at the event (the M6 standard); `circulation.signed` names the officers |
| Replay | The `circulation.signed` notification rebuilt from timeline records alone |
| Documents | Immutable after signing; hash verifies; published documents remain downloadable |
| **Freeze** | `git diff --name-only` empty for M4/M5/M6 (`v0.6.0-m6-complete`), M7 (`v0.7.0-m7-complete`) and **M8** (`v0.8.0-m8-phase3` — `app/Domain/Review` plus its four view paths, AD-21) |
| Schema | **Run against MySQL**, not only SQLite — TD-24, and R9's identifier limit |

Estimated **70–90 tests**, comparable to M8 — the workflow is small but the isolation and permission surfaces are wide.

## 26. Quality gates

Unchanged and all mandatory: Laravel Pint · PHPStan (Larastan level 6) · PHPUnit · `composer audit` · `php artisan migrate:fresh --seed`, plus Security, Performance, VAPT and full-PPT reviews and the 14-point demo-readiness checklist. **The gate is necessary, not sufficient (AD-17).**

Four M9-specific additions:

1. **Freeze verification across five milestones**, including M8's view paths — AD-21's verification clause, and AD-22 §5's requirement that the diff be run for *every* milestone under freeze.
2. **An AD-23 check at every phase boundary** — all three assertions in §25. The freeze diff will not catch a breach: adding a call to `DpcCalculationService` from a *new* M9 file modifies no frozen milestone and leaves every freeze diff empty. **A breach is an Architecture Defect, and this is the only check that finds one.**
3. **A duplication check**, as M8 required: the freeze diff catches modification, not a copy (R1, R8).
4. **`migrate:fresh --seed` against MySQL**, with every new foreign-key constraint name confirmed ≤ 64 characters (R9). TD-24 should be closed before Phase 1 rather than worked around.

---

## 27. Summary for approval

**M9 is the smallest milestone since M2 and the one most likely to be built wrong**, because its name says "DPC" and almost nothing about it is one. It has no chart, no committee, no zone, no allotment, no scheduling, no sitting and no calculation. It resolves a promotion the Commission already reserved, on evidence the department supplies later, and obtains a signature on a prescribed form.

**Approved 2026-08-16, and §6 was raised to a standing architecture decision — AD-23.** A Circulation implementation shall never invoke the calculation, eligibility, zone or proceeding services, and shall never recalculate, determine eligibility or a zone, conduct a proceeding, or score. **Violation is an Architecture Defect.** AD-23 also records the two indirect routes a name-based check would miss — M7's `ProceedingCalculationService` and M8's container-bound `ReviewAwareCalculationService`.

**AQ-15 is ruled — AD-24: Circulation is its own aggregate and shall not reuse, extend or specialize `dpc_proposals`.** §11's schema is confirmed as designed, and R1 — the milestone's dominant risk — is closed. **AD-23 and AD-24 together mean a Circulation can neither do what a DPC does nor be what a DPC is**, and a breach of either is an Architecture Defect.

**Nine architect questions remain** (§24). **AQ-16** — is a score-changing CR resolution a circulation or a Review? — is the one that still cannot be deferred, because it decides whether Phase 2 has a refusal path to build. **AD-23 already blocks it being ruled the other way without a decision that supersedes AD-23** — deliberately, since the alternative is case-by-case exceptions, which is how a rule of this kind stops being one.

**Three new business questions go to CGPSC** (§21), of which **BD-14** — from what date a circulated promotion takes effect — is the defining one, because retrospective seniority reaches every officer below the one being promoted.

**Two debt items should be closed before Phase 1 begins**, and neither requires a Freeze Exception: **TD-24** (run the suite on MySQL — it has already cost one P0) and **TD-20** (extract the recipient resolver before M9 makes it a fourth copy).

**Nothing is implemented. Awaiting Architect approval.**
