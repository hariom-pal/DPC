# Implementation Guide — Blueprint

**Created:** 2026-08-18
**Status:** Blueprint only. **This is not the Implementation Guide.**
**Purpose:** the table of contents and traceability map from which `docs/IMPLEMENTATION_GUIDE.md` will later be written.

---

## How to read this document

**Every Part below specifies what the corresponding chapter of the Implementation Guide must contain, and names where its source material already lives. It does not contain that content.**

That distinction is the whole discipline of this document. Part 3 is called *System Architecture* and contains **no architecture** — it contains the specification for the architecture chapter. Part 7 lists every slide and contains **no explanation of any slide**. Writing content here would produce a second Implementation Guide and re-create the duplication problem the consolidation plan (`ARCHITECT_REVIEW.md` §0.4) exists to prevent.

**Source authority.** The two PPT decks govern (**AD-1**). Everything the Guide will state is derived from them, and the extracted text at `docs/design/reference/` is the citable evidence.

**Scope of this blueprint.** 52 slides of `ODPC_NEW_FINAL.pptx` (`S1`–`S52`) and 7 of `CONVENTIONAL DPC PROCESS.pptx` (`C1`–`C7`) — **59 slides, every one accounted for in Part 7**, including the ones that will carry no implementation content.

**Legend, used throughout and to be carried into the Guide**

| Symbol | Meaning |
|---|---|
| ✅ | Implemented and tested |
| 🟡 | Implemented, blocked on a business decision for publication or activation |
| ⚪ | Not built |
| ⛔ | Blocked — cannot be built until a decision lands |
| ➖ | Not applicable — cover, agenda or closing slide, no implementable content |
| ↗ | Covered once in a cross-cutting chapter (Part 8); the slide entry is a pointer |

---

## Part 1 — Project Overview

**Chapter specification. Target length: 2–3 pages.**

| § | Content the chapter must carry | Source material |
|---|---|---|
| 1.1 | What the system is, and the problem it replaces | `README.md`; `DEMO_GUIDE.md` §1–2 |
| 1.2 | Who commissioned it, and who operates it | `S1`, `C1`–`C2` |
| 1.3 | Scope boundary — what is in V1 and what is deliberately excluded, with the authority for each exclusion | `PROJECT_PROGRESS.md`; **BD-17** |
| 1.4 | The source-of-truth rule and why it has never been relaxed | **AD-1** |
| 1.5 | Technology stack, stated once, referenced everywhere after | `docs/design/04-laravel-architecture-and-structure.md` |
| 1.6 | How to read the Guide; the legend; the document map | This blueprint |

**Rule:** no history in this chapter. *When and why* decisions were taken belongs to `PROJECT_TIMELINE.md`, which stays a separate permanent document.

---

## Part 2 — Business Overview

**Chapter specification. Target length: 4–6 pages.**

| § | Content the chapter must carry | Source material |
|---|---|---|
| 2.1 | What a Departmental Promotion Committee is, in business terms | `C3`; `DEMO_GUIDE.md` |
| 2.2 | The three DPC types — Fresh, Review, Circulation — and what distinguishes them | `C3`, `C7`, `S14` |
| 2.3 | The promotion decision: what the Commission actually decides, and what it does not | `C4`–`C6` |
| 2.4 | The legal weight of the output — why documents are signed, hashed and never rewritten | `S34`, `S48` |
| 2.5 | The business vocabulary, Hindi and English, as one glossary | Both decks |
| 2.6 | What "held" (`CIR`), "superseded", "provisional" and "main / waiting list" mean to a department | `S33`, `S34`, `S45` |

**Rule:** business language only. No table names, no class names, no framework terms.

---

## Part 3 — System Architecture

**Chapter specification. Target length: 4–5 pages. This chapter must reference, never restate.**

| § | Content the chapter must carry | Source material |
|---|---|---|
| 3.1 | The layering the codebase follows, and the boundary each layer owns | `docs/design/04-laravel-architecture-and-structure.md` |
| 3.2 | Domain-per-milestone structure, and why `app/Domain/*` is organised the way it is | `ARCHITECTURE_BASELINE_v0.7.md` |
| 3.3 | The configuration-driven rule mechanism — what changes by setting rather than by code | **AD-3**, **AD-19** |
| 3.4 | The workflow engine as a shared mechanism, not per-module code | **AD-11**; `WORKFLOWS.md` |
| 3.5 | The freeze model: five freezes, what each permits, and how a freeze is verified | **AD-15**, **AD-21**, **AD-22** |
| 3.6 | The decoration pattern, what it costs, and the ceiling it has already hit | **AD-20**; `ARCHITECTURE_BASELINE_v0.7.md` |
| 3.7 | Aggregate boundaries — why a Circulation is not a proposal | **AD-23**, **AD-24** |
| 3.8 | Evidence and immutability: what is never rewritten, and what enforces that | **AD-14**, **AD-18**; `TD-1` |
| 3.9 | The provider pattern and feature flags for every external system | **AD-13** |
| 3.10 | An index of every AD and AQ, with a one-line summary and a link | `ARCHITECTURE_DECISIONS.md` |

**Rule:** §3.10 is an index, not a copy. `ARCHITECTURE_DECISIONS.md` remains the authoritative register.

---

## Part 4 — User Roles

**Chapter specification. Target length: 2–3 pages.**

| § | Content the chapter must carry | Source material |
|---|---|---|
| 4.1 | The six roles, their PPT origin, and which are additions with their authority | `app/Enums/Role.php` docblock; `S5`–`S6` |
| 4.2 | What each role may do, may see, and may never do | `WORKFLOWS.md` §4 role matrix |
| 4.3 | Department roles vs Commission roles — the department boundary and why CGPSC users hold none | `WORKFLOWS.md` |
| 4.4 | Single-actor accountability: the allottee conducts, and the Chairman is not exempt | **AQ-6**, **AQ-20**; **TD-28** |
| 4.5 | Role-based UI visibility, and why hiding a control is never the security measure | `WORKFLOWS.md` §4 note |
| 4.6 | Role → slide matrix: which role acts on which slide | Part 7 of this blueprint |

---

## Part 5 — High Level Workflow

**Chapter specification. Target length: 3–4 pages, diagram-led.**

| § | Content the chapter must carry | Source material |
|---|---|---|
| 5.1 | End-to-end flow, registration → published promotion list, as one diagram | `S2`–`S4` |
| 5.2 | The Fresh DPC state machine | `WORKFLOWS.md` §1–3 |
| 5.3 | Where Review and Circulation branch off, and where they rejoin | `S35`, `S45` |
| 5.4 | Every hand-off point: who passes what to whom, and what is signed at each | `WORKFLOWS.md` §4–5 |
| 5.5 | The states in which nothing can be edited, and what enforces that | `WORKFLOWS.md` |
| 5.6 | Where the flow can stall, and what unblocks it | `PENDING_BUSINESS_DECISIONS.md` |

**Rule:** the state machines are defined in `WORKFLOWS.md`, which stays permanent. This chapter narrates and diagrams them; it does not redefine them.

---

## Part 6 — Stage Mapping

**Stages 1–6 are the PPT's own** — `S2`–`S4` name them as *"the six stages of ODPC"*. **Stages 7–9 are not**: Review, Circulation and Self-Inspection appear in the deck as separate processes after the six. The distinction is preserved because the six stages are the customer's own vocabulary.

| Stage | Slides | Business Objective | Implemented Modules | Status |
|---|---|---|---|---|
| **1 — Registration of Departments** | `S5`–`S6` | A department and its two authorised officers become known to the Commission and receive credentials | Registration · Authentication · RBAC · Notifications | ✅ M1 |
| **2 — Pre-DPC Proposal** | `S7`–`S11` | The department registers the posts it may promote into, the cadres they are promoted from, and the conditions — approved by the Administrative Head | Pre-DPC · Cadre master · Recruitment Rule master · Qualification master | ✅ M3 (+ M2 masters) |
| **3 — DPC Proposal (detailed)** | `S12`–`S25` | The department submits a complete, signed promotion case: posts, committee, officers, seniority and evaluation chart | DPC Proposal · Excel Import · Digital Signature | ✅ M4 |
| **4 — Action by CGPSC** | `S26`–`S30` | The Commission takes custody: the Chairman allots a Member, who accepts and schedules the sitting | CGPSC Workflow — Allotment · Scheduling | ✅ M6 |
| **5 — The Online DPC Proceeding** | `S31`–`S34` | The sitting is conducted, the result calculated under published rules, approved and issued as signed documents | Proceedings · Scoring & Eligibility Engine · Document Generation | ✅ M7 (engine M5) |
| **6 — Review DPC** | `S35`–`S44` | A concluded DPC found to be in error is corrected without altering the original, and supersedes it on approval | Review DPC | ✅ M8 |
| **7 — Circulation DPC** | `S45`–`S48` | A promotion held for a missing record is finally settled by the Commission's signed consent | Circulation DPC | 🟡 M9 — closure-ready, **BD-14** |
| **8 — Self-Inspection & Objection** | `S49`–`S51` | An officer inspects the record held about them and may object before the DPC date | Self-Inspection · Objection | ⚪ M10 — not started |
| **9 — Scoring rules (reference deck)** | `C3`–`C7` | The arithmetic and the gates that decide who is promoted | Scoring & Eligibility Engine | ✅ M5 · ⛔ relaxation **BD-2/BD-3** |
| **— Front and back matter** | `S1`–`S4`, `S52`, `C1`–`C2` | Cover, agenda and closing | ➖ | ➖ |

**Coverage: 8 of 9 stages implemented. One stage (`S49`–`S51`) is unbuilt.** Stage 7 is built and awaiting a business decision.

---

## Part 7 — Slide Inventory

**One row per slide. 59 rows. This is the Guide's navigation index and the checklist for writing it.**

**Column key** — *Mod.* Business Module · *Status* implementation status · *In* milestone · *Deps* dependencies · *BD* business decisions · *AD* architect decisions and questions · *TD* technical debt · *Pri* documentation-writing priority · *Cx* documentation complexity · *Pg* estimated pages in the Guide.

**Priority** — `P1` a reader needs it to understand the system · `P2` supporting detail · `P3` boilerplate or cover.
**Complexity** — `L` mechanical · `M` needs care · `H` needs judgement, deviations or worked examples.

### Front matter and introduction

| Slide | Title | Mod. | Status | In | Deps | BD | AD | TD | Pri | Cx | Pg |
|---|---|---|---|---|---|---|---|---|---|---|---|
| S1 | Odpc — Online Departmental Promotion Committee | ➖ Cover | ➖ | — | — | — | — | — | P3 | L | 0 |
| S2 | Introduction — 6 stages of ODPC (registration, Pre-DPC) | ➖ Agenda | ➖ | — | — | — | AD-1 | — | P1 | L | 0.5 |
| S3 | Introduction — 6 stages (DPC proposal contents) | ➖ Agenda | ➖ | — | — | — | — | — | P1 | L | 0.5 |
| S4 | Introduction — 6 stages (workflow, scheduling, proceeding) | ➖ Agenda | ➖ | — | — | — | — | — | P1 | L | 0.5 |

> `S2`–`S4` carry no screen but define the stage vocabulary used by Part 6 and by the whole Guide. They are documented as the introduction to Part A, not as slide entries.

### Stage 1 — Registration of Departments

| Slide | Title | Mod. | Status | In | Deps | BD | AD | TD | Pri | Cx | Pg |
|---|---|---|---|---|---|---|---|---|---|---|---|
| S5 | Registration of Departments — narrative | Registration | ✅ | M1 | — | — | — | TD-6 | P1 | M | 1 |
| S6 | Registration of Departments — form | Registration | ✅ | M1 | Department master | **BD-18** | — | TD-6 | P1 | M | 2 |

### Stage 2 — Pre-DPC Proposal

| Slide | Title | Mod. | Status | In | Deps | BD | AD | TD | Pri | Cx | Pg |
|---|---|---|---|---|---|---|---|---|---|---|---|
| S7 | Details regarding all posts sanctioned for promotion — narrative | Pre-DPC | ✅ | M3 | — | — | — | — | P1 | L | 0.5 |
| S8 | Posts sanctioned — proposal login (AO) | ↗ Auth | ✅ | M1 | — | — | — | — | P3 | L | 0.1 |
| S9 | Posts sanctioned — proposal entry | Pre-DPC · Masters | ✅ | M3 | Cadre, Pay Scale, Qualification masters | **BD-18** | AD-25 *(proposed)* | TD-25 | **P1** | **H** | 3 |
| S10 | Posts sanctioned — approval login (AH) | ↗ Auth | ✅ | M1 | — | — | — | — | P3 | L | 0.1 |
| S11 | Posts sanctioned — approval | Pre-DPC | ✅ | M3 | S9 | — | AD-14 | TD-5, TD-26 | P1 | M | 1.5 |

> **S9 is one of the highest-complexity entries in the Guide.** It carries two declared deviations from the deck — a Qualification master and a Years/Months experience unit, neither present on the slide — and both must be written in the five-field deviation form.

### Stage 3 — DPC Proposal (detailed)

| Slide | Title | Mod. | Status | In | Deps | BD | AD | TD | Pri | Cx | Pg |
|---|---|---|---|---|---|---|---|---|---|---|---|
| S12 | DPC Proposal — narrative | DPC Proposal | ✅ | M4 | — | — | — | — | P1 | L | 0.5 |
| S13 | DPC Proposal — AO login | ↗ Auth | ✅ | M1 | — | — | — | — | P3 | L | 0.1 |
| S14 | Selection of posts (to / from, class, DPC type, legal stay, date) | DPC Proposal | ✅ | M4 | S9 projection | **BD-16** | **AD-25** *(proposed)* | — | **P1** | **H** | 2.5 |
| S15 | DPC Committee | DPC Proposal | ✅ | M4 | Committee config master | — | — | — | P1 | M | 1.5 |
| S16 | Uploading officers under consideration | DPC Proposal · ↗ Excel | ✅ | M4 | Vacancy, Reservation masters | — | — | TD-8 | P1 | M | 2 |
| S17 | Uploading seniority list | DPC Proposal · ↗ Excel | ✅ | M4 | S16 | — | — | TD-8 | P1 | M | 2 |
| S18 | Uploading tabulation chart | DPC Proposal · ↗ Excel | ✅ | M4 | S17 | BD-1 | — | TD-8 | **P1** | **H** | 3 |
| S19 | Approval of DPC proposal — narrative | DPC Proposal | ✅ | M4 | — | — | — | — | P2 | L | 0.5 |
| S20 | AH approval — login | ↗ Auth | ✅ | M1 | — | — | — | — | P3 | L | 0.1 |
| S21 | AH approval — selection of posts | DPC Proposal | ✅ | M4 | S14 | — | — | — | P2 | L | 0.5 |
| S22 | AH approval — DPC Committee | DPC Proposal | ✅ | M4 | S15 | — | — | — | P2 | L | 0.5 |
| S23 | AH approval — officers under consideration | DPC Proposal | ✅ | M4 | S16 | — | — | — | P2 | L | 0.5 |
| S24 | AH approval — seniority list | DPC Proposal | ✅ | M4 | S17 | — | — | — | P2 | L | 0.5 |
| S25 | AH approval — tabulation chart, and forward to CGPSC | DPC Proposal · ↗ Signature | ✅ | M4 | S18 | BD-4 | AD-14 | TD-18 | P1 | M | 1.5 |

> **`S21`–`S25` are the same five sections as `S14`–`S18` in read-only review.** Each entry is short and points at its authoring twin. Writing them at full length would double Stage 3 for no reader benefit.

### Stage 4 — Action by CGPSC

| Slide | Title | Mod. | Status | In | Deps | BD | AD | TD | Pri | Cx | Pg |
|---|---|---|---|---|---|---|---|---|---|---|---|
| S26 | Action by CGPSC on DPC proposal — narrative | CGPSC Workflow | ✅ | M6 | — | — | — | TD-6 | P1 | L | 0.5 |
| S27 | Chairman login | ↗ Auth | ✅ | M1 | — | — | — | — | P3 | L | 0.1 |
| S28 | Allotment, re-allotment and allotment report | CGPSC Workflow | ✅ | M6 | S25 | — | AQ-6 | TD-28 | P1 | M | 2 |
| S29 | Member login | ↗ Auth | ✅ | M1 | — | — | — | — | P3 | L | 0.1 |
| S30 | Acceptance of DPC and (re-)scheduling | CGPSC Workflow | ✅ | M6 | S28 | — | AQ-6 | TD-12 | P1 | M | 2 |

### Stage 5 — The Online DPC Proceeding

| Slide | Title | Mod. | Status | In | Deps | BD | AD | TD | Pri | Cx | Pg |
|---|---|---|---|---|---|---|---|---|---|---|---|
| S31 | Process of Online DPC — narrative, and the no-late-change rule | Proceedings | ✅ | M7 | — | — | — | — | P1 | M | 1 |
| S32 | Chairman / Member login (DPC ID) | ↗ Auth | ✅ | M1 | — | — | — | — | P3 | L | 0.1 |
| S33 | The sitting: workspace, corrections, `CALCULATE` | Proceedings · ↗ Engine | ✅ | M7 | S18, M5 | BD-1, BD-10 | AQ-4 | TD-16, TD-22 | **P1** | **H** | 4 |
| S34 | Main list and waiting list; `APPROVE BY CGPSC` | Proceedings · ↗ Documents | ✅ | M7 | S33 | **BD-2, BD-3, BD-9, BD-10** | AD-14, AQ-7 | TD-18, TD-21 | **P1** | **H** | 4 |

> **`S33` and `S34` are the two heaviest entries in the Guide.** They carry the customer's own worked example, four open business decisions, and the results that a contested promotion would be argued from. Budget accordingly.

### Stage 6 — Review DPC

| Slide | Title | Mod. | Status | In | Deps | BD | AD | TD | Pri | Cx | Pg |
|---|---|---|---|---|---|---|---|---|---|---|---|
| S35 | Review DPC — narrative and the six grounds | Review DPC | ✅ | M8 | — | — | — | — | P1 | M | 1.5 |
| S36 | Review proposal — AO login | ↗ Auth | ✅ | M1 | — | — | — | — | P3 | L | 0.1 |
| S37 | Review proposal by authorised officer; reasons | Review DPC | ✅ | M8 | S34 | — | AQ-9 | — | P1 | M | 2 |
| S38 | Correction in tabulation chart | Review DPC | ✅ | M8 | S37 | — | AQ-11 | TD-23 | **P1** | **H** | 2.5 |
| S39 | Review proposal — AH login | ↗ Auth | ✅ | M1 | — | — | — | — | P3 | L | 0.1 |
| S40 | Review proposal — AH approval | Review DPC | ✅ | M8 | S38 | — | AD-14 | — | P2 | L | 0.5 |
| S41 | Action on Review DPC — narrative | Review DPC | ✅ | M8 | — | — | — | — | P2 | L | 0.5 |
| S42 | Action on Review DPC — Chairman / Member login | ↗ Auth | ✅ | M1 | — | — | — | — | P3 | L | 0.1 |
| S43 | Action on Review DPC — review | Review DPC | ✅ | M8 | S41 | — | AQ-11 | — | P2 | M | 1 |
| S44 | Action on Review DPC — result and supersession | Review DPC · ↗ Documents | ✅ | M8 | S43 | **BD-11** | **AQ-9**, AQ-13, AD-20 | — | **P1** | **H** | 3 |

### Stage 7 — Circulation DPC

| Slide | Title | Mod. | Status | In | Deps | BD | AD | TD | Pri | Cx | Pg |
|---|---|---|---|---|---|---|---|---|---|---|---|
| S45 | Circulation DPC — narrative and the two grounds | Circulation DPC | 🟡 | M9 | S34 holds | **BD-15**, BD-6 | AD-23 | — | P1 | M | 1.5 |
| S46 | Circulation — Chairman / Member login | ↗ Auth | ✅ | M1 | — | — | — | — | P3 | L | 0.1 |
| S47 | Circulation — action, type and origin DPC selection | Circulation DPC | 🟡 | M9 | S45 | BD-12, BD-13 | **AD-24** | TD-28 | P1 | M | 2 |
| S48 | Circulation — before/after list, consent and signature | Circulation DPC · ↗ Documents | 🟡 | M9 | S47 | **BD-14** | **AQ-17**, AQ-20, AQ-21, AQ-22 | TD-28 | **P1** | **H** | 3 |

> **Stage 7 is written but its publication is blocked on BD-14.** Every entry's *Implemented Status* must say so, and must not read as a defect.

### Stage 8 — Self-Inspection & Objection

| Slide | Title | Mod. | Status | In | Deps | BD | AD | TD | Pri | Cx | Pg |
|---|---|---|---|---|---|---|---|---|---|---|---|
| S49 | Self-inspection and assessment — narrative | Self-Inspection | ⚪ | M10 | — | — | — | — | P1 | L | 0.5 |
| S50 | Self-inspection — individual login | ↗ Auth | ⚪ | M10 | — | — | — | — | P2 | M | 0.5 |
| S51 | Self-inspection — action: view, print, raise objection | Self-Inspection · Objection | ⚪ | M10 | S18, S33 | **BD-8** | — | — | **P1** | **H** | 2.5 |

> **The only unbuilt stage.** These three entries are written as *specification* — what the slide requires and what remains to be built — not as a record of implementation. **Writing them before M10 gives M10 a specification to build against.**

### Closing

| Slide | Title | Mod. | Status | In | Deps | BD | AD | TD | Pri | Cx | Pg |
|---|---|---|---|---|---|---|---|---|---|---|---|
| S52 | Thank you / contact | ➖ Closing | ➖ | — | — | — | — | — | P3 | L | 0 |

### Conventional DPC Process deck — the scoring rules

| Slide | Title | Mod. | Status | In | Deps | BD | AD | TD | Pri | Cx | Pg |
|---|---|---|---|---|---|---|---|---|---|---|---|
| C1 | CGPSC — Conventional DPC (cover) | ➖ Cover | ➖ | — | — | — | — | — | P3 | L | 0 |
| C2 | Conventional DPC — required proposal contents | Pre-DPC · DPC Proposal | ✅ | M3, M4 | — | — | — | — | P2 | M | 1 |
| C3 | Types of departmental promotion — Fresh, Review, Circulation | All DPC types | ✅ | M4, M8, M9 | — | BD-6 | AD-23, AD-24 | — | P1 | L | 1 |
| C4 | CR grade marks (क+/क/ख/ग/घ) and the tribal-area bonus | ↗ Engine | ✅ | M5 | — | — | AD-3 | — | **P1** | **H** | 2 |
| C5 | Qualifying scores, hard gates, super seat, reserved relaxation | ↗ Engine | ✅ / ⛔ | M5 | C4 | **BD-2, BD-3**, BD-1 | AD-3, AD-19 | TD-21 | **P1** | **H** | 3 |
| C6 | Zone of consideration (`2×V+4`; 7× reserved expansion) | ↗ Engine | ✅ | M5 | — | BD-2, BD-3 | AD-3 | — | **P1** | **H** | 2 |
| C7 | Types of departmental promotion (repeat of C3) | All DPC types | ✅ | M4, M8, M9 | C3 | — | — | — | P3 | L | 0.25 |

> **`C4`–`C6` state the business rules that no single ODPC slide states in full.** They are documented once, in the cross-cutting engine chapter (**X3**), and these rows point there.

### Inventory totals

| | Count | Est. pages |
|---|---:|---:|
| Implemented, substantive | 33 | ~52 |
| Login slides (↗ pointer to **X1**) | 12 | ~1.2 |
| Unbuilt (`S49`–`S51`) | 3 | ~3.5 |
| Cover / agenda / closing | 8 | ~2 |
| Repeat (`C7`) | 1 | ~0.25 |
| **Total** | **59** | **~59 pages** |

**Twelve of the 59 slides are the same login screen for a different actor.** Giving each a full thirteen-heading entry would add roughly 150 lines of near-identical text and bury the slides that carry real business rules. **One implementation in X1, twelve pointers** — this is the single largest structural saving in the plan.

---

## Part 8 — Cross-Cutting Chapters

**Content belonging to no single slide, written once and referenced from every slide that touches it. Each entry names the slides it serves, so the pointer relationship is verifiable in both directions.**

| # | Chapter | Serves slides | Status | Pri | Pg |
|---|---|---|---|---|---|
| **X1** | **Authentication, session and e-signing statement** | S8, S10, S13, S20, S27, S29, S32, S36, S39, S42, S46, S50 | ✅ M1 | **P1** | 3 |
| **X2** | **Roles, RBAC and role-based visibility** | all actor slides | ✅ M1 | **P1** | 3 |
| **X3** | **Scoring & Eligibility Engine** | C4–C6, S17, S18, S33, S34 | ✅ M5 · ⛔ BD-2/BD-3 | **P1** | 6 |
| **X4** | **Workflow engine** | every stage transition | ✅ M3 onward | **P1** | 3 |
| **X5** | **Master data & configuration** | S9, S15, S16, C4–C6 | ✅ M2 | P1 | 4 |
| **X6** | **Excel import pipeline** | S16, S17, S18 | ✅ M4 | P1 | 3 |
| **X7** | **Digital signature** | S11, S25, S34, S40, S44, S48 | 🟡 mock; **BD-4** | **P1** | 3 |
| **X8** | **Document generation, storage and verification** | S34, S44, S48 | ✅ M7, M8, M9 | **P1** | 4 |
| **X9** | **Audit, workflow ledger and activity timeline** | all | 🟡 no UI — TD-7 | P1 | 3 |
| **X10** | **Notifications** | S5, S26, S30, S44 | 🟡 no gateway — TD-6 | P2 | 2 |
| **X11** | **Integrations & feature flags** | S8-family, S16 | 🟡 mock — BD-4, BD-5 | P2 | 2 |
| **X12** | **Vacancies, reservation categories and roster** | S16, S34 | ✅ M2, M4 | P1 | 3 |
| **X13** | **Immutability & evidence model** | S18, S33, S34, S44, S48 | ✅ | **P1** | 3 |
| **X14** | **Testing strategy & the quality gate** | all | ✅ | P2 | 3 |
| **X15** | **Deployment, environments & operations** | — | 🟡 TD-1, TD-8 | P2 | 3 |
| **X16** | **Reports & dashboards** | — | ⚪ M11 — BD-17 | P3 | 1 |
| **X17** | **Internationalisation (Hindi / English)** | all screens | ✅ | P3 | 1 |

**Total cross-cutting: ~50 pages.**

**Rule for every X chapter:** it is written **once**, and the slide entries that use it carry a one-line pointer and nothing more. A cross-cutting chapter that gets partially restated in a slide entry has failed, and is the mechanism by which this Guide would become the next duplication problem.

**X16 is listed but nearly empty on purpose.** Reports are unbuilt and gated on **BD-17**; the chapter exists so the gap is visible in the table of contents rather than absent from it.

---

## Part 9 — Appendices

| # | Appendix | Content | Source | Pg |
|---|---|---|---|---|
| **A** | Slide index | All 59 slides, one line each: number, title, status, page — the fast lookup | Part 7 | 3 |
| **B** | Conventional deck rule map | Each `C`-slide rule mapped to the engine service that implements it | X3 | 2 |
| **C** | Deviations register | Every place the build differs from the decks, in the five-field form: PPT reference, SRS reference, current code, reason, recommendation | Slide entries | 4 |
| **D** | Open items | Every ⛔ and 🟡 in one table, with its blocking BD or TD | Registers | 2 |
| **E** | Business decisions index | BD-1 … BD-18: one line each, status, what it blocks | `PENDING_BUSINESS_DECISIONS.md` | 2 |
| **F** | Architect decisions index | AD-1 … AD-25 and every AQ: one line each | `ARCHITECTURE_DECISIONS.md` | 3 |
| **G** | Technical debt index | TD-1 … TD-28: one line each, status | `TECHNICAL_DEBT.md` | 2 |
| **H** | Milestone map | M0 … M11: scope, status, freeze, tag | `MILESTONE_SUMMARY.md` | 2 |
| **I** | Glossary | Hindi / English business vocabulary | Part 2 | 2 |
| **J** | Register index | Which document owns which kind of fact, and what the Guide deliberately does not contain | Consolidation plan | 1 |
| **K** | Test map | Slide or chapter → the test file that proves it | Slide entries | 3 |

**Total appendices: ~26 pages.**

**Appendices E, F and G are indexes, never copies.** The registers stay authoritative. An appendix that inlines a register is finding **F1** of the consolidation plan repeating itself at a larger scale.

---

## Estimated total

| Part | Pages |
|---|---:|
| Parts 1–5 (overview chapters) | ~18 |
| Part 6 stage introductions | ~5 |
| Part 7 slide entries | ~59 |
| Part 8 cross-cutting chapters | ~50 |
| Part 9 appendices | ~26 |
| **Total** | **≈ 158 pages** |

**This is a reference, not a document anyone reads end to end.** It must be built to be *indexed*: if it cannot answer *"what implements slide 23?"* in under ten seconds, it has failed regardless of its completeness. Appendix A and the Part 7 tables exist for exactly that.

---

## Writing order, when the Guide is authorised

Recorded so the work is systematic rather than sequential-by-slide-number.

1. **Cross-cutting chapters first** — X1, X2, X4, X13. Every slide entry points at them, so writing slides first means rewriting the pointers.
2. **Then X3** (the engine) — `S33`, `S34` and `C4`–`C6` all depend on it and are the heaviest entries.
3. **Then Stage 1 → Stage 6 slide entries**, in stage order. These are settled and frozen; nothing about them will move.
4. **Then Stage 7** (`S45`–`S48`), marking BD-14 rather than waiting for it.
5. **Then Stage 8** (`S49`–`S51`) as **specification**, before M10 begins.
6. **Then Parts 1–5**, which summarise what the body established — writing an overview first is how an overview stops matching its own document.
7. **Appendices last**, generated from the finished body.

**Prerequisite, from the consolidation plan:** **F1 must be resolved first** — the Q-register and BD-register hold the same questions under two numbers, and every slide entry's *Business Decision* heading would inherit that ambiguity.

---

## What this blueprint deliberately does not contain

- **No implementation content.** No table, class, method or route is described.
- **No slide explanations.** Titles and classifications only.
- **No architecture.** Part 3 specifies the architecture chapter; it contains none.
- **No code.**
- **No merged documents.** Nothing was merged, moved, archived or deleted.
- **No register content.** BD, AD and TD appear as identifiers only.

**Page counts are estimates for planning, not commitments.** They exist so the Guide's scale is known before it is started rather than discovered halfway through.
