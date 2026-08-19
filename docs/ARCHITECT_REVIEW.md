# Architect Review

**Date:** 2026-08-18
**Subject:** **M9 closure readiness confirmed (§0.13.1)** · documentation consolidation plan retained as a completed deliverable (§0.1–§0.12). No code changed
**Baseline:** `v0.8.0-m8-phase3`; **M9 is CLOSURE-READY and not closed — held open by BD-14 alone**

**No milestone was opened or closed, and no file but this one was changed.** §0 is this session; **§0.13 is pinned** (M9's live closure checklist, referenced by section number from two other documents); §1 onward is the historical record from M8 Phase 3 and earlier, preserved verbatim.

**Previous sessions' §0 content is not lost** — it is in `git log` for this file, and its durable outputs were written to the permanent registers at the time: AD-25 (proposed), BD-16/17/18, TD-25/26/27/28, and the worklogs under `docs/worklog/`.

---

<!-- CURRENT SESSION — START. This section is REPLACED in full on every prompt. Never appended. Everything below §0 is historical architect review and must be preserved verbatim. §0.13 is PINNED — see the note there. -->

## 0. Current Session

**Updated:** 2026-08-19 · **DEMO COMPLETION MODE (§0.13.7) — M10 built and four M11 features built; read that first.** M9 closure readiness (§0.13.1) and the cleared backlog (§0.13.4–§0.13.5) still stand. §0.1–§0.12 hold the completed documentation consolidation plan, retained rather than replaced because it is a deliverable, not working notes.

### 0.1 Prompt received

> The project now has many documentation files. Before M10 starts we need to consolidate. Review all project documentation and identify duplicate, outdated, overlapping and unnecessary documents, and documents that can later be merged. **Do not merge them yet. Do not delete anything. Prepare only a consolidation plan** answering: which documents are permanent, temporary, to be archived, append-only, and which should eventually become a single Implementation Guide. **Also propose the structure of one final document**, organised by PPT slide, with per-slide headings (Business Requirement, Implementation, Database, Controllers, Services, Policies, Workflow, Validation, Remarks, Implemented Status, Deviation, Business Decision, Architect Decision). **Do not generate the document now — only the structure.** Update only `architect_review.md`.

### 0.2 What this session did, and did not

**Did:** enumerated every Markdown file in the repository with its size and last-commit date, read the headers and update-cadence statements of each, and traced the specific overlaps named in §0.4. **This is a documentation inventory, not a repository or code review** — no source file was opened, no test was run, no PPT or SRS was re-read.

**Did not:** merge, delete, move or rename anything; create any file; change any code; open M10; or generate the Implementation Guide.

**One file changed: this one.**

### 0.3 The inventory — what actually exists

**19 top-level documents in `docs/`, 3,251 lines**, plus a design set, three sub-libraries and an archive.

| Document | Lines | Last touched | Stated update cadence |
|---|---:|---|---|
| `CHANGELOG.md` | 878 | 2026-08-18 | Reverse-chronological, appended |
| `ARCHITECT_REVIEW.md` | 626 | 2026-08-18 | **§0 replaced in full every prompt**; §1+ historical |
| `PROJECT_PROGRESS.md` | 508 | 2026-08-18 | *"Replaced in full at each phase boundary"* |
| `ARCHITECTURE_DECISIONS.md` | 507 | 2026-08-18 | Append; supersede by adding, never editing |
| `WORKFLOWS.md` | 418 | 2026-08-18 | On workflow change |
| `PENDING_BUSINESS_DECISIONS.md` | 358 | 2026-08-18 | Append; items move to Closed |
| `UAT_BUSINESS_VALIDATION.md` | 348 | 2026-08-18 | On new business question |
| `DEMO_CHEAT_SHEET.md` | 293 | 2026-08-18 | On demo-data change |
| `EVENT_CATALOG.md` | 288 | 2026-08-14 | On event change |
| `PROJECT_STATUS.md` | 283 | 2026-08-18 | *"Single page to read first"* |
| `DEMO_GUIDE.md` | 262 | 2026-08-18 | On feature change |
| `DEMO_SCRIPT.md` | 213 | **2026-08-13** | — |
| `MILESTONE_SUMMARY.md` | 208 | 2026-08-18 | *"Updated at each milestone closure"* |
| `INTEGRATION_ROADMAP.md` | 184 | 2026-08-13 | On integration change |
| `PROJECT_TIMELINE.md` | 162 | 2026-08-16 | *"Appended at each milestone closure"* |
| `UAT_CHECKLIST.md` | 156 | **2026-08-13** | — |
| `TECHNICAL_DEBT.md` | 116 | 2026-08-18 | Append; items move to Resolved |
| `ARCHITECTURE_BASELINE_v0.7.md` | 108 | 2026-08-14 | — |
| `project-documentation-audit.md` | 95 | **2026-08-13** | One-off report |

Plus: `docs/design/` (11 files — `01`–`06` foundational, `07`–`10` per-milestone design for M6–M9, `README`), `docs/design/reference/` (2 extracted PPT texts), `docs/design/recruitment-rule/` and `docs/design/pre-dpc/` (screenshots + README), `docs/worklog/` (8), `docs/archive/` (SRS, an old Word module doc, `screen-specs.md`, `technical-proposal.md`, and 8 archived worklogs), `docs/deployment/` (1), `docs/integration/` (2), the two `.pptx` sources, and the root `README.md`.

### 0.4 Findings — what actually duplicates, and what has gone stale

**Six findings. The first is the only one that can produce a wrong answer rather than a stale one.**

#### F1 — Two registers hold the same open questions under different numbers ⚠ **highest risk**

`docs/design/06-ambiguities.md` maintains **Q1–Q16**. `docs/PENDING_BUSINESS_DECISIONS.md` maintains **BD-1–BD-18**. They overlap directly:

| Q | BD | Subject |
|---|---|---|
| Q5 | BD-2 | Reserved relaxation — automatic or committee discretion |
| Q6 | BD-3 | Relaxation to 10 — Class-I→I only, or all class pairs |
| Q7 | BD-7 | Proceedings/order PDF layout |
| Q11 | BD-8 | Objection handling |
| Q12 | BD-6 | Circulation trigger wording |
| Q16 | BD-17 | Out-of-scope confirmation (video conferencing, voting, HRMS) |

The ambiguities file **acknowledges the overlap in its own text** — *"That includes Q5, Q6, Q7, Q11 and Q12 from the table above"* — without resolving it. **This is the one duplication that can produce a wrong answer:** answering BD-2 does not update Q5, so a reader consulting the design set sees "open" for a question that has been ruled. It is also the register most likely to be consulted by someone new, because `docs/design/` is where the requirements live.

**Nothing is being changed now.** Recorded so the merge, when approved, starts from a known map.

#### F2 — Four documents describe milestone state, on three different cadences

`PROJECT_STATUS.md` (replaced), `PROJECT_PROGRESS.md` (*"replaced at each phase boundary"*), `MILESTONE_SUMMARY.md` (*"updated at each milestone closure"*) and `PROJECT_TIMELINE.md` (*"appended at each milestone closure"*) all carry milestone status. Three of the four update only on an **event that may never arrive**.

**This already caused a real failure and is filed as TD-27.** M9 finished its engineering on 2026-08-16 with no closure event, and two days later `PROJECT_PROGRESS.md` still asserted *"No M9 code, migration, route, view or test exists in this working tree"* twelve lines below its own correct *"Circulation DPC 🟡 Engineering 100%"*, `MILESTONE_SUMMARY.md` had no M9 page at all, and `MILESTONE_SUMMARY.md`'s gate line reported 620 tests against an actual 798. **A milestone that stalls is exactly the one these documents are guaranteed to get wrong**, and stalling is now the project's normal state — two milestones are built and unclosed for business reasons.

#### F3 — Three roadmaps

`docs/design/05-milestones.md` (the original M0–M11 plan, with per-milestone blockers), `MILESTONE_SUMMARY.md` §"At a glance", and `PROJECT_PROGRESS.md` §2 "Remaining milestones" each carry a milestone table. The design one still lists blockers as `⛔ Q4, Q9, Q10` for milestones long since delivered.

#### F4 — Three demonstration documents, deliberately layered but now overlapping

`DEMO_GUIDE.md` (narrative + a 16-step script), `DEMO_SCRIPT.md` (a slide-by-slide script), `DEMO_CHEAT_SHEET.md` (credentials and record identifiers) total **768 lines**. The layering is intentional and the cheat sheet cross-references the other two correctly. **The overlap is between the two scripts:** `DEMO_SCRIPT.md` was last touched 2026-08-13 — before M8, M9 and the current demo dataset — while `DEMO_GUIDE.md` §13 now carries its own current 16-step script. Two scripts, one current.

#### F5 — Two UAT documents, one current

`UAT_BUSINESS_VALIDATION.md` (348 lines, current, nine questions for CGPSC) and `UAT_CHECKLIST.md` (156 lines, **2026-08-13**, predating M7, M8 and M9 — so its scenario list cannot cover proceedings, Review or Circulation).

#### F6 — Three documents are stale by construction, not by neglect

- **`project-documentation-audit.md`** — a documentation audit dated 2026-08-13. It is the direct ancestor of this plan, and **this plan supersedes it.** Its own recommendation (restore 7 deleted worklogs into `docs/archive/worklog/`) **was carried out** — they are there.
- **`ARCHITECTURE_BASELINE_v0.7.md`** — the version is in the filename. The project is past `v0.8.0-m8-phase3` and heading for `v0.9.0`. A filename that pins a version guarantees either a stale name or a proliferation of near-identical files.
- **`docs/design/02-traceability-matrix.md`** — feature → PPT slide → milestone, **last updated 2026-07-30**, so it stops before M6. It is nonetheless the single most valuable file in this inventory, because **it is the seed of the Implementation Guide** (§0.10).

**What is NOT a problem, and should not be "consolidated":** `CHANGELOG.md` being the largest file (it is append-only history and is supposed to grow); the four `docs/design/07`–`10` per-milestone design documents (each is the approved design for one milestone and is referenced by its architect review); `docs/archive/` (already doing its job); and the three specialist registers — AD, BD, TD — which are deliberately separate by architect directive and **must stay that way.**

### 0.5 Answer 1 — Documents that should remain PERMANENT

**Permanent = survives every milestone, is never archived, and is the authoritative home for its content.**

| Document | Why permanent |
|---|---|
| `docs/ODPC_NEW_FINAL.pptx`, `docs/CONVENTIONAL DPC PROCESS.pptx` | **The only source of business requirements (AD-1).** Everything else is derived |
| `docs/design/reference/*.extracted.txt` | The evidence behind every requirement; auditable, diffable, greppable |
| `ARCHITECTURE_DECISIONS.md` | The AD register. Constrains all future work; supersede by adding, never by editing |
| `PENDING_BUSINESS_DECISIONS.md` | The BD register. Only CGPSC can close an item |
| `TECHNICAL_DEBT.md` | The TD register. Exists precisely because §0 of this file is replaced |
| `CHANGELOG.md` | The permanent record of what changed and why |
| `PROJECT_TIMELINE.md` | The permanent record of *when and why* decisions were taken — distinct from what was delivered |
| `WORKFLOWS.md` | State machines, transitions and the role matrix — the reference a developer needs before touching a workflow |
| `EVENT_CATALOG.md` | Event names, payload versions and replay semantics; a published contract |
| `ARCHITECT_REVIEW.md` | The architect's working desk **and** the historical review record |
| **The Implementation Guide** (§0.10) | Once written, the primary permanent artefact — see §0.9 |
| `README.md` (root) | Project entry point |
| `docs/design/01-requirements.md`, `03-database-design.md`, `04-laravel-architecture-and-structure.md` | Foundational design reference, still load-bearing |
| `docs/design/07`–`10-*.md` | The approved design for M6, M7, M8, M9. **One per milestone — the pattern should continue for M10 and M11** |
| `docs/deployment/`, `docs/integration/` | Operational runbooks; needed at deployment, not before |

**Three registers stay three registers.** AD (what constrains future work), BD (what only CGPSC can answer) and TD (what we know is imperfect) answer different questions for different audiences on different timescales. Merging them would produce one register nobody can scan.

### 0.6 Answer 2 — Documents that are TEMPORARY

**Temporary = tied to an event or a milestone; expected to be superseded, not maintained forever.**

| Document | Temporary because | Disposal |
|---|---|---|
| `docs/worklog/*.md` (8 files) | Each records one task on one day. Never re-read after its milestone closes | Move to `docs/archive/worklog/` at each milestone closure — the convention already exists and already holds 8 files |
| `project-documentation-audit.md` | A one-off report, superseded by this plan | Archive on approval of this plan |
| `DEMO_SCRIPT.md` | Superseded in practice by `DEMO_GUIDE.md` §13 (F4) | Merge the still-useful slide mapping into the Implementation Guide, then archive |
| `UAT_CHECKLIST.md` | Predates M7–M9; a UAT artefact for a scope that has since tripled | Rewrite once against the final scope, or fold into the Implementation Guide's per-slide *Implemented Status* |
| `ARCHITECTURE_BASELINE_v0.7.md` | Version-pinned filename (F6) | Content is permanent; **the filename is not.** Fold into `04-laravel-architecture-and-structure.md` or rename without the version |
| `docs/design/recruitment-rule/`, `docs/design/pre-dpc/` | Before/after screenshots for two specific screens | Keep while the screens are recent; archive once the Implementation Guide carries the current UI description |

### 0.7 Answer 3 — Documents to be ARCHIVED (later, not now)

**Archive = moved to `docs/archive/`, never deleted.** The convention exists and works: `docs/archive/` already holds the SRS, the old Word module document, `screen-specs.md`, `technical-proposal.md` and 8 recovered worklogs.

| Document | Archive when |
|---|---|
| `project-documentation-audit.md` | On approval of this plan |
| `DEMO_SCRIPT.md` | Once its slide mapping is absorbed by the Implementation Guide |
| `UAT_CHECKLIST.md` | Once rewritten against final scope, or absorbed |
| `docs/design/02-traceability-matrix.md` | **Only after** the Implementation Guide supersedes it — it is the Guide's seed and must not be archived before |
| `docs/design/05-milestones.md` | At project completion. Until then it is the historical roadmap; its stale blocker column should be marked, not removed |
| `docs/design/06-ambiguities.md` | **Only after** F1 is resolved — every Q mapped to its BD or marked closed. Archiving it with unmapped Qs would strand ruled decisions |
| `docs/worklog/*` per milestone | At that milestone's closure |
| `ARCHITECTURE_BASELINE_v0.7.md` | On absorption into the architecture document |

**Nothing is archived until the Implementation Guide exists and has been checked to carry what the archived file carried.** Archiving first is how a project loses a requirement.

### 0.8 Answer 4 — Documents that should become APPEND-ONLY

**Append-only = entries are added and marked superseded; existing text is never edited.** This is the discipline that makes a document trustworthy in a contested promotion.

| Document | Current | Should be |
|---|---|---|
| `ARCHITECTURE_DECISIONS.md` | Already append-only *in spirit* (its own closing note says superseding means adding an entry, never editing) | **Formalise it.** Add a one-line rule at the top and an explicit `Status: ACTIVE / SUPERSEDED BY AD-nn` field on every entry |
| `PENDING_BUSINESS_DECISIONS.md` | Items move to a **Closed items** table — already correct | Keep. Add the **date answered and by whom** to each closed row; today a closed item records the resolution but not its authority |
| `TECHNICAL_DEBT.md` | Items move to **Resolved** — already correct | Keep as is |
| `CHANGELOG.md` | Reverse-chronological, appended | Keep as is |
| `PROJECT_TIMELINE.md` | *"Appended at each milestone closure"* | **Change the trigger to each phase**, not each closure — F2's failure mode applies to it too |
| `ARCHITECT_REVIEW.md` §1+ | Historical, preserved verbatim | Keep. **This is the correct model** and the rest of §0 should learn from it |

**The one that should NOT be append-only:** `PROJECT_STATUS.md`. Its whole value is being short and current. Making it append-only would make it a second changelog.

**⚠ A structural flaw in this very file, found while writing this plan.** `ARCHITECT_REVIEW.md` §0 is *"REPLACED in full on every prompt"*, but it accumulates **live state** that is not the current session's — the M9 closure checklist below is referenced by `PROJECT_STATUS.md` and `PROJECT_PROGRESS.md` **by section number**, and replacing §0 would delete a live artefact and break two cross-references. It has already bitten twice: **TD-28** (the absent-allottee deadlock) was raised in the M9 Phase 3 review, lost when §0 was replaced, and had to be recovered from git; and the M9 closure checklist survives this session only because it was **manually pinned**. Recommended: split §0 into **§0-A Current Session** (genuinely replaced) and **§0-B Live State** (carried forward until its milestone closes). Recorded here for ruling; **not implemented** — it changes a documentation convention, which is the Architect's to change.

### 0.9 Answer 5 — Documents that should become the single Implementation Guide

**These are the candidates for merge. Merging them is a later, separately approved task.**

| Document | What it contributes | Fate after merge |
|---|---|---|
| `docs/design/02-traceability-matrix.md` | **The spine.** Already feature → PPT slide → milestone | Archived |
| `docs/design/01-requirements.md` | Per-slide business requirement, field inventory, the 35-screen inventory | Retained as the requirements reference; the Guide quotes it |
| `PROJECT_PROGRESS.md` (module sections) | Per-module: purpose, workflow, DB tables, backend components, frontend, APIs, business rules, dependencies, tests. **Already 80% of the requested per-slide block, organised by module instead of by slide** | Archived — this is the largest single contribution |
| `docs/design/03-database-design.md` | The per-slide **Database** heading | Retained; the Guide references rather than copies |
| `WORKFLOWS.md` | The per-slide **Workflow** heading | **Retained permanent** — it is the state-machine reference; the Guide links to it |
| `MILESTONE_SUMMARY.md` | Per-milestone narrative and what each decision cost | Folded into the Guide's stage introductions; archived |
| `DEMO_SCRIPT.md` | The slide-by-slide demonstration path | Absorbed into **Remarks** / a per-slide *"How to demonstrate"* line; archived |
| `UAT_CHECKLIST.md` | Per-scenario expected results | Absorbed into **Implemented Status**; archived |
| `docs/design/06-ambiguities.md` | Q-register | **Resolve F1 first**, then the Q numbers appear only as historical aliases in **Business Decision** |
| `docs/design/07`–`10-*.md` | Per-milestone design rationale | **Retained permanent.** The Guide states *what* was built; these state *why* |

**The Guide replaces `PROJECT_PROGRESS.md` and `02-traceability-matrix.md` outright.** It does **not** replace the three registers, `WORKFLOWS.md`, `CHANGELOG.md`, `PROJECT_TIMELINE.md`, the design set or `ARCHITECT_REVIEW.md` — it *references* them. A guide that inlines a register becomes a second copy of it, which is F1 all over again.

### 0.10 Proposed structure of the Implementation Guide

**Working title:** `docs/IMPLEMENTATION_GUIDE.md`. **Not generated. Structure only.**

#### Front matter

```
Title, purpose, and the one-line rule: the PPT governs; this document records
      what was built against it (AD-1)
How to read this document — and what it deliberately does NOT contain
      (no register content; links only)
Source decks, slide counts, and the extracted-text references
Legend — ✅ Implemented · 🟡 Partial · ⛔ Blocked · ⚪ Not built · ➖ Not applicable
Coverage summary — one table, 59 slides, one row per slide, status only
Document map — which register or reference owns each kind of fact
```

#### Body — Part A, by stage; within each stage, by slide

Grouped so the Guide reads in the order the business works. **Stages 1–6 are the PPT's own** — S2–S4 name them as *"the six stages of ODPC"*. **Stages 7–8 are not**: Review, Circulation and Self-Inspection are presented in the deck as separate processes after the six, and are grouped here for navigation only. The distinction is worth keeping, because the six stages are the customer's own vocabulary:

```
Stage 1 — Registration of Departments            S5–S6
Stage 2 — Pre-DPC Proposal                       S7–S11
Stage 3 — DPC Proposal (detailed)                S12–S25
Stage 4 — Action by CGPSC (allotment, schedule)  S26–S30
Stage 5 — The Online DPC Proceeding              S31–S34
Stage 6 — Review DPC                             S35–S43
Stage 7 — Circulation DPC                        S44–S48
Stage 8 — Self-Inspection & Objection            S49–S51
```

Each stage opens with a half-page introduction — what the stage achieves in business terms, which milestone delivered it, and its freeze status — then one entry per slide:

```
### Slide <n> — <slide title, as printed>

  1.  Business Requirement    What the slide asks for, in business language.
                              Hindi text quoted verbatim where it is the
                              operative wording, with a translation.
  2.  Implementation          What was built, in two or three sentences.
  3.  Database                Tables and the columns that carry this slide's
                              data. Links to 03-database-design.md; does not
                              restate the schema.
  4.  Controllers             Class + method, and the route name.
  5.  Services                The domain service(s) that hold the logic.
  6.  Policies                The Policy and the abilities that gate it.
  7.  Workflow                States and transitions this slide participates
                              in. Links to WORKFLOWS.md.
  8.  Validation              The Form Request, and the rules that are
                              business rules rather than type checks.
  9.  Remarks                 Anything a reader would otherwise have to
                              discover: N+1 avoidance, an immutability guard,
                              why an obvious approach was refused. Also how
                              to demonstrate the slide.
  10. Implemented Status      ✅ / 🟡 / ⛔ / ⚪, the milestone, and the test
                              file that proves it.
  11. Deviation               Where the build differs from the slide, with
                              PPT reference, SRS reference, current code,
                              reason, and recommendation — the five-field
                              form the project's own rules require.
  12. Business Decision       BD-nn references only, with one-line summaries.
                              Never the BD text itself.
  13. Architect Decision      AD-nn / AQ-nn references only, likewise.
```

#### Body — Part B, cross-cutting

Content that belongs to no single slide and would otherwise be repeated:

```
B1  Authentication and e-signing         S8, S10, S13, S20, S27, S29, S32,
                                         S36, S39, S42, S46, S50 — twelve
                                         login slides, one implementation.
                                         Written ONCE here; each slide entry
                                         is a one-line pointer.
B2  Roles, RBAC and role-based visibility
B3  The scoring and eligibility engine   C4–C6 — the Conventional deck's
                                         rules, which no single ODPC slide
                                         states in full
B4  Master data and configuration        Config-driven rules; what changes by
                                         setting rather than by code
B5  Documents, signing and verification
B6  Excel import (S16–S18)               One pipeline, three templates
B7  Audit, timeline and evidence
B8  Notifications and integrations
```

**B1 is the structural point worth making now.** Twelve of the 52 slides are the same login screen for a different actor. Giving each a full thirteen-heading entry would add roughly 150 lines of near-identical text and bury the eight slides that carry real business rules. **One implementation, twelve pointers.**

#### Back matter

```
Appendix A  Conventional DPC deck (C1–C7) — the business rules, mapped to
            the engine services that implement them
Appendix B  Slides deliberately not implemented, with the reason and the
            authority for it
Appendix C  Open items — every ⛔ and 🟡 in one table, with its blocking
            BD or TD reference
Appendix D  Register index — which document owns which kind of fact
```

#### Rules the Guide must follow, so it does not become the next duplication problem

1. **Never inline register content.** BD, AD and TD appear as references with a one-line summary. The register is the truth; the Guide is the map.
2. **Every "Implemented Status" cites a test file.** A status with no test behind it is an opinion.
3. **Updated per phase, never per closure.** F2 is the failure this rule prevents.
4. **The slide is the unit, and slide numbers never change.** Milestones are renumbered and rescoped; the customer's deck is not. **This is why organising by slide is the right call** — it is the only index that is stable over the project's life and shared with the customer.
5. **Deviations are written in the five-field form** the project's own rules require, or not written at all.

### 0.11 Sequencing — the order this must happen in

**Nothing here is authorised by this session; this is the proposed order when it is.**

1. **Resolve F1 first** — map every Q to its BD, or mark it closed. **It is the only finding that can make a reader wrong rather than merely out of date**, and everything downstream inherits the confusion if it is not fixed first.
2. **Fix the cadence rules (F2)** — change `PROJECT_PROGRESS.md`, `MILESTONE_SUMMARY.md` and `PROJECT_TIMELINE.md` to update **per phase**, not per closure. One line each. This is TD-27 and it is the cheapest fix on this list.
3. **Rule on the §0 split (§0.8)** — it governs how this document behaves during the work that follows.
4. **Write the Implementation Guide**, seeded from `02-traceability-matrix.md` and `PROJECT_PROGRESS.md`'s module sections. **Slides first, cross-cutting second.**
5. **Verify before archiving** — for each candidate, confirm the Guide carries what it carried. Then archive.
6. **Archive, never delete.**

**Recommended timing: after M9 closes, before M10 opens.** M10 adds S49–S51, the only stage with no implementation — writing the Guide first gives M10 a specification to build against rather than a document to retrofit.

### 0.12 Risks in this plan, stated rather than discovered later

- **The Guide is large.** 52 + 7 slides; realistically 1,500–2,500 lines even with B1 collapsing the login slides. **It is not a document anyone reads end to end** — it is a reference, and it must be built to be *indexed*, not read. If it cannot answer "what implements slide 23?" in ten seconds, it has failed.
- **It will go stale exactly like the four status documents**, unless rule 3 is enforced. The only structural defence is that the per-slide status is short and mechanical.
- **Merging is where requirements get lost**, which is why §0.11 step 5 exists and why nothing is deleted.
- **The three registers must not be absorbed.** The pressure to inline them will be constant, because a reader wants the answer in front of them. Doing it recreates F1 at a larger scale.

### 0.13 M9 closure checklist — **PINNED**

> **This subsection is deliberately carried across the §0 replacement.** It is not this session's work: it is **M9's live closure state**, it is referenced by section number from `PROJECT_STATUS.md` and `PROJECT_PROGRESS.md`, and replacing it would delete a live artefact and break two cross-references. It stays, unchanged, until M9 closes. That it has to be pinned by hand is the flaw described in §0.8.


**Added 2026-08-18 under the Milestone Execution Rule.** §10 below is **M8's** checklist and is preserved as history; M9 had none, which is part of why its closure state was recorded inconsistently across four documents. This is M9's.

| # | Item | Evidence | Status |
|---|---|---|---|
| 1 | Design approved before implementation | `docs/design/10-m9-circulation-dpc.md`, approved 2026-08-16 | ✅ |
| 2 | All three phases implemented | Aggregate + department side · Commission consent + hold release · prescribed form and documents | ✅ |
| 3 | Architect decisions in the permanent register | **AD-23**, **AD-24** in `docs/ARCHITECTURE_DECISIONS.md` | ✅ |
| 4 | Architect questions ruled | AQ-17, AQ-20, AQ-21, AQ-22 | ✅ |
| 5 | Laravel Pint | Re-run 2026-08-18 | ✅ Pass |
| 6 | PHPStan / Larastan level 6 | Re-run 2026-08-18 | ✅ 0 errors |
| 7 | PHPUnit | Re-run 2026-08-18 | ✅ **798 / 2404**; **98 / 276** are M9's |
| 8 | `composer audit` | Re-run 2026-08-18 | ✅ No advisories |
| 9 | Migrations additive only (AD-16) | Two migrations, three new tables; nothing altered or dropped. The single write into a frozen table is `dpc_proceeding_holds.released_at`, the seam M7 declared (AQ-21) | ✅ |
| 10 | Freeze verification — M4/M5/M6/M7/M8 untouched | Five diffs against `v0.6.0-m6-complete`, `v0.7.0-m7-complete`, `v0.8.0-m8-phase3`, **including M8's four view paths** | ✅ Empty |
| 11 | AD-23 verified behaviourally, not only by scan | Generating documents creates **no** calculation run and leaves the pinned run byte-identical | ✅ |
| 12 | Full PPT comparison (S45–S48) | Performed at Phase 3 finalisation — no divergence | ✅ |
| 13 | Business questions in their own register | BD-14, BD-15, BD-12, BD-13 in `docs/PENDING_BUSINESS_DECISIONS.md` | ✅ |
| 14 | Business questions put to business in plain language | **Questions 6–9** of `docs/UAT_BUSINESS_VALIDATION.md`, added 2026-08-18 | ✅ |
| 15 | Technical debt recorded, not silently carried | TD-2, TD-24 re-stated at Phase 3; TD-25/26/27 added 2026-08-18 | ✅ |
| 16 | Demo readiness — independently demoable | Raise a circulation against a concluded DPC with a held officer → AH approve → Commission consent → sign → hold released → before/after list. Seeded as `SE202600003/C1` | ✅ |
| 17 | Five mandatory documents synchronized | `PROJECT_STATUS.md`, `PROJECT_PROGRESS.md`, `CHANGELOG.md`, `MILESTONE_SUMMARY.md`, this file | ✅ **2026-08-18** |
| 18 | Milestone boundary committed | Phases 1–3 committed 2026-08-16 (`a716dcf`, `f23c118`, `1852fa1`); closure documentation 2026-08-18 | ✅ |
| 19 | **M9 engineering complete** | **Yes.** Nothing in M9's scope is unbuilt or untested | ✅ |
| 20 | **BD-14 answered** | **No.** The only outstanding item. It decides what a published note *says*; the answer lands as configuration (`dpc.circulation.effective_date_basis`), not code | ⛔ **Blocked** |
| 21 | **M9 tagged** | **No** — a tag marks a closed milestone, and closure waits on item 20 | ⛔ Blocked by 20 |
| 22 | **M9 freeze declared** | **No** — a freeze needs a baseline tag. **M9 is the only built milestone whose "has it changed?" cannot be answered by diff** | ⛔ Blocked by 21 |
| 23 | **M9 closed** | **No** | ⛔ **Blocked by BD-14 alone** |

**Items 1–19 are green. Items 20–23 are one business decision in a trench coat.** There is no engineering task, no test, no migration and no document left in M9's scope — verified against §0.7 and §0.11 of the Phase 3 review, which listed six warnings of which **none was engineering work**: two business decisions (BD-14, BD-9), two recorded debts (TD-2, TD-24), one unruled architecture question (the absent-allottee deadlock), and one request to confirm a design choice in review.

**One item from that list is still genuinely unruled and is recorded here rather than actioned:** if the origin DPC's allottee has **left the Commission**, nobody can sign the circulation note *or* produce its documents (AQ-20's deadlock, Phase 3 warning 3). It affects no seeded or current case, it is not new work introduced by closure, and under the Milestone Execution Rule it is recorded, not implemented. It wants a standing rule about absent named actors — the same gap AD-22 left open — and belongs in the same freeze-exception window as the rest of the backlog.

---

#### 0.13.1 Closure readiness — re-verified 2026-08-18

**All five confirmations requested were checked against the running repository today, not carried forward from an earlier run.**

| # | Confirmation | Result | How it was checked |
|---|---|---|---|
| 1 | **All engineering work complete** | ✅ **Confirmed** | Checklist items 1–2 and 19. §0.7 of the M9 Phase 3 review states it; §0.11's six warnings contain **no engineering item** |
| 2 | **All milestone documentation complete** | ✅ **Confirmed** | Item 17. The five mandatory documents were synchronised on 2026-08-18 and now agree with each other and with the code — the defect that caused **TD-27** is closed for M9 |
| 3 | **All mandatory quality gates complete** | ✅ **Confirmed** | Re-run today: **798 passed / 2404 assertions** · Pint **pass** · PHPStan (Larastan L6) **0 errors** · `composer audit` **no advisories** |
| 4 | **All mandatory milestone deliverables complete** | ✅ **Confirmed** | Items 1–19 green: design approved, three phases built, AD-23/AD-24 registered, AQ-17/20/21/22 ruled, migrations additive, **all five freeze diffs empty**, AD-23 verified *behaviourally* not only by scan, PPT S45–S48 compared, registers updated, business questions put to CGPSC in plain language, demo-ready as `SE202600003/C1` |
| 5 | **Waiting ONLY on business approval** | ✅ **Confirmed** | **BD-14 is the sole blocker.** Items 20–23 are all consequences of it |

**Freeze verification, today:** `v0.6.0-m6-complete` → 0 files · `v0.7.0-m7-complete` → 0 · `v0.8.0-m8-phase3` → 0 · M8's four view paths → 0.

**M9's own tests:** 98 passed / 276 assertions across seven files in `tests/Feature/Circulation/`.

**Verdict: M9 is CLOSURE-READY and is not closed.** Every engineering, documentation, gate and deliverable obligation is discharged. The milestone is held open by one unanswered business question and by nothing else.

**What closure will consist of, once BD-14 is answered** — recorded now so it is a checklist rather than a fresh decision later:

1. Apply the answer as configuration — `dpc.circulation.effective_date_basis`. **No code change, no freeze exception, no re-test of the engine.**
2. Lift the publication block and confirm a circulation note publishes end to end.
3. Re-run the full gate and all five freeze diffs.
4. Tag **`v0.9.0-m9-complete`**.
5. Declare the **M9 freeze** as a new AD in the shape of AD-21, at that baseline. **Until this exists, M9 is the only built milestone whose "has it changed?" cannot be answered by diff.**
6. Update items 20–23 above, `PROJECT_STATUS.md`, `PROJECT_PROGRESS.md`, `MILESTONE_SUMMARY.md` and `CHANGELOG.md`.

#### 0.13.2 Repository state — clean, and waiting on BD-14 alone

**Verified 2026-08-18, after the closure-readiness commit.**

| Check | Result |
|---|---|
| Tracked changes | ✅ **None** — `git status --short` empty |
| Untracked files | ✅ **None** — 0, counted with `--untracked-files=all` |
| Stashes, merge/rebase/cherry-pick in progress | ✅ **None** |
| Last commit | `4bc5215` *docs(m9): confirm closure readiness; record documentation consolidation plan* |
| Branch | `feature/m0-setup`, **5 commits ahead of `origin`** — see below |
| Closure checklist (§0.13) | ✅ **19 green, 4 blocked** — unchanged |
| Full gate, re-run before the commit | ✅ 798 / 2404 · Pint pass · PHPStan L6 0 errors · `composer audit` clean |
| Five freeze diffs | ✅ **All empty** |

**The housekeeping item flagged in the readiness record is closed.** The consolidation plan and this readiness record were uncommitted when §0.13.1 was written; they are now committed, so **M9's boundary is anchored to a commit rather than to a working tree**. That was the failure mode **TD-14** already cost this project once, and it is the precondition for a freeze diff meaning anything.

**One item is deliberately left undone: the branch is 5 commits ahead of `origin` and has not been pushed.** Pushing was not requested and is not required for closure readiness — every verification above is local and reproducible. It is recorded because an unpushed boundary is a single-machine boundary, and M8's own checklist treated "committed" and "pushed" as two separate rows for exactly that reason.

**Nothing else remains. M9 is not tagged, not frozen and not closed** — all three are blocked on **BD-14**, and each is blocked by the one before it. **No engineering, documentation, gate or repository-hygiene task is outstanding.**

#### 0.13.4 Unblocked backlog cleared — TD-23, TD-24 step 1, TD-26, TD-27

**2026-08-18, on the directive to maximise completed work without waiting on BD-14.** Every item below was already specified and already unblocked. **Suite 798 → 801 (2455 assertions). All five freezes empty. No M9 code touched. `demo:seed` unchanged.**

| Item | Result |
|---|---|
| **TD-24 step 1** | ✅ **Closed.** `SchemaIdentifierLengthTest` — every identifier against MySQL's 64 characters, on any engine. **Step 2 (run the suite on MySQL) remains open** |
| **TD-23** | ✅ **Closed.** The Review now runs the real submit → AH-approve path |
| **TD-26** | ✅ **Closed.** `PreDpcProposalPolicy` extracted; controller and views ask it |
| **TD-27** | ✅ **Closed.** Three cadence statements changed to per phase |

**Two findings, both from doing the work rather than from reviewing it:**

1. **An authorization gap in Pre-DPC, found by TD-26 and fixed.** `create`/`store` were gated to the AO earlier the same day; **`edit`, `update` and `amend` checked only the department**, so an **Administrative Head could open the edit form and save over a draft** the show screen had never offered them. The UI was right, the backend was wider. This is the concrete form of the drift TD-26 described in the abstract, and it was invisible for as long as the rule was stated twice.
2. **A fixture defect, found by TD-23.** `BuildsProceedings` hardcoded the original's `dpc_code`, leaving the per-department sequence at zero — so the first proposal approved *for real* collided on `HE202600001`. Unreachable in production; invisible until a test approved a second proposal, which none ever had.

**Both were found because a bypass was removed, not because anything was re-reviewed.** That is the argument for closing TD-24 step 2 as well: the remaining engine gap is the same shape.

**Deliberately not done, and why** — each recorded rather than attempted:

| Item | Why not |
|---|---|
| **AD-25** | Needs **BD-16** confirmed *and* a Freeze Exception against M4 |
| **TD-19 / TD-20 / TD-21 / TD-16** | Blocked by AD-15; need a Freeze Exception, which is the Architect's to grant. **None requested** |
| **TD-22** | Open **by directive** — do not solve a concurrency residual before concurrent workers exist |
| **TD-25** | The register offers **two** fixes (report, or refuse the approval). Choosing between them is an Architect decision, and refusing changes workflow |
| **TD-28** | Needs a standing rule about absent named actors across M7/M8/M9 |
| **TD-5** | Explicitly scheduled for the hardening pass |
| **TD-1 / TD-6 / TD-8 / TD-2** | Deployment, external gateway, capacity and CI — none is a code change in this repository |
| **S49–S51** | M10. Not opened |

#### 0.13.3 Implementation Guide blueprint — complete

**Created 2026-08-18: `docs/IMPLEMENTATION_GUIDE_BLUEPRINT.md`. One file. No code, no repository change, no milestone change, nothing merged or deleted.**

**This is the blueprint, not the Guide.** Its governing discipline: **every Part specifies what the corresponding chapter must contain and where its source material lives — it contains none of that content.** Part 3 is titled *System Architecture* and contains no architecture; Part 7 lists all 59 slides and explains none of them. Writing content there would have produced a second Implementation Guide and re-created the duplication the consolidation plan exists to prevent.

| Part | Delivered |
|---|---|
| 1–5 | Chapter specifications for Project Overview, Business Overview, System Architecture, User Roles and High-Level Workflow — each a section table naming required content and its existing source |
| 6 | **Stage mapping, 9 stages.** Stages 1–6 are the PPT's own six; **7–9 (Review, Circulation, Self-Inspection) are explicitly flagged as not the PPT's**, grouped for navigation only, because the six stages are the customer's vocabulary |
| 7 | **Slide inventory — 59 rows, all 12 requested columns.** `S1`–`S52` and `C1`–`C7`, every slide accounted for including covers and agenda slides |
| 8 | **17 cross-cutting chapters**, each naming the slides it serves so the pointer relationship is verifiable in both directions |
| 9 | **11 appendices**, of which E/F/G are explicitly indexes and never copies of the three registers |

**Three findings from building it, worth the Architect's attention:**

1. **Twelve of the 59 slides are the same login screen for a different actor** — `S8, S10, S13, S20, S27, S29, S32, S36, S39, S42, S46, S50`. Written once as chapter **X1** with twelve pointers, this is the single largest structural saving in the plan; written out individually it would add ~150 lines of near-identical text and bury the slides carrying real business rules.
2. **`S21`–`S25` are `S14`–`S18` again in read-only review.** Same saving, smaller scale.
3. **Scale is now known before starting rather than discovered halfway: ≈158 pages.** The heaviest entries are `S33`, `S34`, `S44`, `S48`, `S9` and `C5` — the ones carrying worked examples, open business decisions or declared deviations.

**A writing order is recorded** so the work is systematic rather than sequential-by-slide-number: cross-cutting chapters first (every slide points at them), then the engine, then stages 1–6, then stage 7 marking BD-14 rather than waiting for it, then **stage 8 as specification before M10 begins**, then the overview parts, then appendices generated from the finished body.

**One prerequisite is flagged: F1 must be resolved before the Guide is written.** The Q-register and the BD-register hold the same questions under two numbers, and every slide entry's *Business Decision* heading would inherit that ambiguity.

**The Guide itself was not started, and M9 is unaffected** — this changed no milestone, no gate and no code.

#### 0.13.5 Unblocked backlog cleared to exhaustion — TD-24 closed, TD-25 half-closed

**2026-08-19, on the directive to complete every approved and available task not blocked by BD-14 or any other Business Decision.** Suite **801 → 809** (2482 assertions). Pint clean · PHPStan L6 0 errors · **all five freeze diffs empty** · `demo:seed` rebuilds on MySQL with all seven DPC IDs and the circulation unchanged. No M4/M5/M6/M7/M8/M9 code touched.

**First, the previous session's four closures were committed.** They existed only as an uncommitted working tree — TD-23, TD-24 step 1, TD-26 and TD-27, verified green at 801 tests, and one machine failure away from being lost. Four commits, one per item, plus the documentation.

| Item | Result |
|---|---|
| **TD-24 step 2** | ✅ **Closed. TD-24 is now closed entirely.** `schema:verify-mysql` builds all 49 migrations on MySQL in a throwaway database, reports what MySQL built, and drops it. **Added to `composer gate`** |
| **TD-25** | 🟡 **Half-closed.** The silence is gone: the projection logs the feeder it dropped, and `pre-dpc:unmatched-feeders` finds the cases already stored. **The behavioural half — refuse the approval — is untouched and remains the Architect's** |

**TD-24 step 2, and why it is in the gate rather than in the suite.** The suite runs on in-memory SQLite; the application runs on MySQL. Step 1 catches the one difference that had already cost a P0 — identifier length — on any engine. Step 2 catches **the differences nobody has thought of yet**, by letting MySQL do the enumerating rather than enumerating them itself.

It never touches `dpc_dev`: a scratch database is created, migrated, counted and dropped, which is what makes it safe in `composer gate` where `migrate:fresh` never could be. Verified — `dpc_dev` still held its 66 tables and 9 demo proposals after a run. **It fails rather than skipping** when MySQL is unreachable or the connection is not MySQL; a schema check that passes without running is the false green TD-24 is about, and three tests pin both refusals. **Verified to bite:** removing the explicit foreign-key name from `2026_08_14_290004` reproduces error 1059 verbatim and exits 1. Current MySQL baseline — **49 migrations, 66 tables** on MySQL 8.0.46.

**The gate is now five steps, not four.** That is the register's own prescription for TD-24 (*"run the suite — or at minimum a schema build — against MySQL in `composer gate` and in CI"*), not a redefinition of AD-17's gate.

**TD-25, and the line drawn through it.** The register offers two fixes — report the unmatched feeder, or refuse the approval — and says the current silent behaviour is the worst of the options. Reporting changes no behaviour and no stored data; refusing is a workflow change. **The reporting half is done and the behavioural half is recorded as still open**, with a test that pins the omission, so if refusal is ever ruled it is that test which has to be revisited rather than a behaviour changing unnoticed. Both the projection and the report ask one shared `FeederCadreResolver`, so the report cannot drift from the thing it reports on — the TD-20 argument.

#### 0.13.6 M10 readiness — it cannot be opened, and **not** for the reason the roadmap says

**M10 (Self-Inspection & Objection, S49–S51) was assessed against the deck itself, slide text in hand.** No code was written. Two findings, and the second is the blocker.

**⚠ F3 — S51 has no *RAISE OBJECTION*. The PPT's self-inspection screen has exactly two buttons: `CLOSE` and `PRINT`.**

S51's notice reads: *"यदि उक्त डाटा के सम्बन्ध में आपको किसी प्रकार की आपत्ति हो तो दिनांक 25 जून 2026 या उसके पूर्व **विभाग में स्वयं उपस्थित होकर** समाधान करवा लेवें. छत्तीसगढ़ लोक सेवा आयोग द्वारा उक्त सम्बन्ध में **किसी भी प्रकार के अभ्यावेदन पर विचार नहीं किया जाएगा** और ऐसे अभ्यावेदन सीधे **नस्तीबद्ध** कर दिए जाएँगे."*

That is: appear **in person at the department** on or before the stated date; **CGPSC will not consider any representation** in this matter, and such representations are **consigned to file**. S49 describes the facility as *स्वयं के डाटा अवलोकन* — viewing one's own data. The deck contains **no online objection at all**.

**So `05-milestones.md`'s M10 scope — *"time-boxed objection (late auto-filed)"* — describes a screen the deck does not have.** "Late auto-filed" appears to be a reading of *नस्तीबद्ध* as applying to late objections; in the sentence it applies to **every** representation made to CGPSC, early or late. **`BD-8`/`Q11` therefore asks three questions about a feature that does not exist in the requirement** — is the deadline configurable, does raising an objection notify the department, block the DPC, or only log.

Two ways to resolve it, and both are the Architect's:

1. **M10's scope narrows to the deck**: individual login, own-data view, the deadline notice, `CLOSE`, `PRINT`. **BD-8 closes as not-applicable** and the objection line is struck from the roadmap.
2. **The online objection is a scope addition** — a genuine improvement on an in-person process, and defensible — in which case it needs approving as a deviation the way **Q1** (Aadhaar → password + OTP) and **Q2** (the Admin role) were, and BD-8 is then the right question about it.

**⚠ F4 — the blocker: an individual has no way to log in, and no field exists to give them one.**

S50 authenticates the individual with **Aadhaar No. + Aadhaar OTP**, replaced project-wide by **Q1** with identifier + password and OTP step-up. `01-requirements.md` proposes **Name + Date of Joining Current Cadre (+ OTP)** for this screen. The first two are in the snapshot. **The third has nowhere to go:** an individual is not a registered user — they exist only as a row in `dpc_evaluation_rows` / `dpc_seniority_entries` / `dpc_working_officers`, and **none of the three ten-field employee snapshots carries a mobile number or an email**. That is deliberate: they are proposal-time snapshots with no live FK to any employee record, which is what stops a historical proposal changing when upstream data does. **There is no channel to send an OTP to.**

Three candidate answers, all decisions rather than engineering, and the middle one is the tempting one:

| Option | What it costs |
|---|---|
| **(a) Add a contact field to the chart** | A schema change to **frozen M4** — needs a **Freeze Exception under AD-22** — plus a business rule on who supplies it and whether it is verified |
| **(b) Knowledge factors only** — name + date of joining + DOB | **Not recommended.** Weaker than every other actor's login, and S51 displays year-wise CR grades, reservation category and immovable-property compliance. Anyone holding a seniority list holds all three factors for **every** officer on it |
| **(c) The department provisions individual accounts** | A new workflow, in neither deck |

**Recorded as `BD-19`, and it is the real M10 blocker — not BD-8.** M10 was **not** opened and no self-inspection code was written: the view is straightforward, but a screen with no reachable entry point is precisely the defect the 2026-08-18 sweep spent its afternoon fixing (Review DPC, delivered with 83 tests and unreachable). **Nothing in M10 can be delivered demoable until F4 is ruled.**

**With M10 blocked, the backlog is exhausted.** Every remaining item needs a named decision, and each is recorded with which one: TD-19/20/21/16 need a Freeze Exception (AD-15); TD-28 needs the standing rule on absent named actors; **TD-25's second half** and TD-22 are Architect calls; AD-25 needs BD-16 plus a Freeze Exception; TD-18 needs BD-4; TD-1/2/6/8 are deployment, gateway, CI and capacity rather than code; TD-5 is scheduled for the hardening pass; **M11 is a later milestone and its order is not this session's to change.**

**`docs/IMPLEMENTATION_GUIDE_BLUEPRINT.md` was left uncommitted.** It was produced on 2026-08-18 and the standing rule since forbids documentation blueprints and the Implementation Guide. It is neither committed nor deleted, pending a ruling on whether it is kept as a completed deliverable or discarded.

#### 0.13.7 DEMO COMPLETION MODE — M10 built, four M11 features built

**Directive received 2026-08-19:** maximise implementation before the business review; never stop for a pending Business Decision — record the assumption, make it configurable, keep building; where a requirement admits several readings, take the safest and most configurable one; documentation limited to three files.

**Suite 809 → 899 (2832 assertions). Pint clean · PHPStan L6 0 errors · `composer audit` clean · MySQL 51 migrations / 68 tables · all five freezes empty · `demo:seed` unchanged (seven DPC IDs + the circulation).**

**§0.13.6 recommended *not* opening M10. That recommendation is superseded by this directive**, and M10 was built — with both open questions expressed as configuration rather than guessed.

| Delivered | Notes |
|---|---|
| **M10 — Self-Inspection (S49–S51)** | Three screens, two buttons. **BD-19's three answers are the three drivers** of `dpc.self_inspection.authentication.driver`; the two the data cannot support return a structured *Pending Business Decision*. **BD-20 resolved by following the deck**: no online objection was invented |
| **M11 — audit-trail screen** | All three records, labelled as distinct. UNION-paginated. Read-only, enforced by test. Streamed CSV |
| **M11 — in-app notification centre** | Second listener on `WorkflowTransitioned` (AD-20's pattern). Outside `FEATURE_NOTIFICATIONS` on purpose |
| **M11 — reports** | One screen, scoped by role. Counted from the run each proceeding pinned at approval — never recalculated (AD-19) |
| **M11 — publication & supersession notifications** | The two events that are deliberately not transitions. Supersession wording constrained by **BD-11**, pinned by test |

**Four decisions taken under the directive's "safest, configurable" rule, each recorded because each could reasonably have gone the other way:**

1. **Three identity factors, not S50's two.** The deck asks for name and date of joining the current cadre. The screen those two open shows year-wise CR grades, reservation category and immovable-property compliance — and all three of those facts appear on a departmental seniority list, which circulates. Date of birth is added by default; `dpc.self_inspection.authentication.factors` reduces it to the deck's two without a code change. **The safest reading was taken and the deck's reading is one config line away.**
2. **No deadline is invented.** S51 shows *"दिनांक 25 जून 2026"* and says nothing about where the date comes from. It is derived as N days before the scheduled sitting; **when no sitting is scheduled the notice renders with no date**, while still telling the officer to raise a correction with the department in person. The alternative — "30 days from today" — would tell an officer a date nobody had decided, on the one screen whose purpose is to show them what the record says.
3. **The in-app channel is not behind `FEATURE_NOTIFICATIONS`.** That flag gates *external* delivery, which needs a procured gateway (TD-6) and a phased rollout. An in-app record calls nothing outside the application. Gating it behind the integration flag would have switched off the only channel that works today, which is the entire reason the channel was built.
4. **The supersession notification says nothing about consequence.** BD-11 is open, and its three plausible answers differ in what they do to a serving officer's substantive rank and pay. The notification names both DPC codes and says which promotion list is no longer current. A test asserts the *absence* of "withdrawn", "reverted", "arrears" and six similar words, because that is the sort of thing a later well-meaning edit adds.

**Three findings worth the Architect's attention:**

1. **⚠ The unread-count cache had to be removed, and the reason generalises.** Caching the notification badge for 30 seconds made a page issue a different number of queries on its *second* render than on its first, and the N+1 detector on the DPC proposal detail screen correctly flagged it as "a section is not eager loaded". It was a false positive that time — and would have been a **permanent** source of them, on the one test in the suite that can catch a real N+1 regression on the heaviest screen in the system. The cache is off by default. **The general point: a cache on anything the layout renders blunts every query-count assertion in the suite.**
2. **TD-20's resolver now exists, and TD-20 got cheaper.** The register says the two frozen copies of the notification-recipient query cannot be merged until a freeze window opens. A third copy for the new channel would have made that worse, so `NotificationRecipientResolver` was written and only the new channel uses it. The frozen copies are untouched. **TD-20's remaining work is now "repoint two callers" rather than "write a resolver and repoint two callers."**
3. **One M11 item is blocked by a freeze rather than by a decision.** The *"Download blank template"* button on the three Excel upload screens (S16–S18) — recorded in the M11 Enhancement Backlog as the most likely cause of a rejected import today — is a **new feature on M4's frozen screens** and needs a **Freeze Exception under AD-22**. It was deliberately not built. It is small, self-contained and high-value for a demo.

**One deliberate duplication, recorded rather than left to be discovered.** `App\Domain\SelfInspection\ValueObjects\PendingBusinessDecision` is a copy of M9's, not a reuse of it: moving M9's into `Shared` is a refactor of a built milestone awaiting closure, which needs approval and would have been the wrong thing to do quietly mid-build. **The right home is `app/Domain/Shared/`, and it should be extracted in the next freeze window** — the same shape as TD-20.

**What remains in M11:** the hardening pass — Redis cache tuning, the security and performance review, final QA — plus **TD-1**, **TD-6** and **TD-8**, none of which is code in this repository.

### 0.14 For the Architect — copy-paste

**M9 is closure-ready. The first item below is the only one that unblocks a milestone; everything after it is backlog.**

> **Answer BD-14** — *from what date does a circulated promotion take effect?* It is **the sole outstanding item on M9's closure checklist**; items 1–19 are green and 20–23 are consequences of this one question. Drafted for CGPSC as **Question 6** of `docs/UAT_BUSINESS_VALIDATION.md`. The answer lands as `dpc.circulation.effective_date_basis` — **no code change, no freeze exception, no re-test.** Until it lands, signed and verifiable circulation documents cannot reach the departments they were produced for.

> **Then execute the six closure steps in §0.13.1** — apply the configuration, confirm publication, re-run the gate, tag `v0.9.0-m9-complete`, declare the M9 freeze, synchronise the documents.

**Two rulings are now needed for the *next* milestone rather than this one (§0.13.6). The backlog is otherwise exhausted — every remaining item names the decision it waits on.**

> **Rule on F4 / BD-19 — how does an individual officer log in?** **This is what blocks M10, not BD-8.** S50 uses Aadhaar + Aadhaar OTP, replaced by Q1; the individual is not a registered user, and **none of the three employee-snapshot tables carries a mobile or an email**, so there is nothing to send an OTP to. Options: (a) add a contact field to M4's chart — **Freeze Exception under AD-22** plus a rule on who supplies and verifies it; (b) knowledge factors only — **not recommended**, since S51 shows CR grades, category and property compliance to anyone holding a seniority list; (c) the department provisions individual accounts — a new workflow in neither deck.

> **Rule on F3 — is the online objection in scope at all?** **S51 has two buttons, `CLOSE` and `PRINT`.** The deck sends objections to the department **in person** and says CGPSC will consider no representation. Either M10 narrows to the deck and **BD-8 closes as not-applicable**, or the objection is approved as a scope addition the way Q1 and Q2 were — and only then is BD-8 the right question.

> **Rule on TD-25's second half.** The silence is fixed; the behaviour is not. Should an unmatched feeder **refuse** the Pre-DPC approval? That is a workflow change, so it was not taken. A test pins the current omission either way.

> **Rule on `docs/IMPLEMENTATION_GUIDE_BLUEPRINT.md`** — produced 2026-08-18, left **uncommitted** because the standing rule since forbids blueprints and the Guide. Kept as a completed deliverable, or discarded?

**Under DEMO COMPLETION MODE the two M10 questions are no longer blocking — both are configuration (§0.13.7). What is now worth ruling:**

> **Grant a Freeze Exception for the Excel "Download blank template" button** (S16–S18). Recorded in the M11 Enhancement Backlog as the most likely cause of a rejected import today; small, self-contained, and the only remaining M11 feature blocked by a freeze rather than by a decision.

> **Confirm the M10 defaults, or override them by configuration.** Three identity factors rather than S50's two, and no invented objection deadline. Both are one config line from the deck's literal reading; the safest reading was taken deliberately (§0.13.7).

> **Extract `PendingBusinessDecision` to `app/Domain/Shared/` in the next freeze window** — M10 holds a deliberate copy of M9's, recorded rather than left to be found. Same shape as TD-20, which is now one step from done.

---

*The documentation consolidation plan below is a completed deliverable, retained deliberately — it is not this session's working notes. Three decisions are needed before any of it starts, and the first two are one line each.*

> **Approve or amend the consolidation plan** in §0.5–§0.11. Nothing has been merged, moved, archived or deleted. **Approving it authorises no work by itself** — each step in §0.11 is separately actionable.

> **Rule on F1 (§0.4), the only finding that can make a reader wrong.** `docs/design/06-ambiguities.md` holds **Q1–Q16** and `PENDING_BUSINESS_DECISIONS.md` holds **BD-1–BD-18**, and six pairs are the same question under two numbers — Q5/BD-2, Q6/BD-3, Q7/BD-7, Q11/BD-8, Q12/BD-6, Q16/BD-17. **Answering a BD does not update its Q**, so the design set can show "open" for a decision already ruled. **Recommended: the BD register is authoritative; every Q becomes a historical alias.**

> **Rule on the cadence fix (F2 / TD-27).** `PROJECT_PROGRESS.md`, `MILESTONE_SUMMARY.md` and `PROJECT_TIMELINE.md` update *"at each phase boundary"* or *"at each milestone closure"* — events that may never arrive, and did not for M9. **Recommended: change all three to update per phase.** One line each, and it is the cheapest fix on the list.

> **Rule on splitting §0 of this file** into **§0-A Current Session** (replaced every prompt) and **§0-B Live State** (carried forward until its milestone closes). §0's replace-in-full rule has already destroyed one open item — **TD-28**, recovered from git — and §0.13 below survives this session only because it was pinned by hand.

---

*Backlog, unchanged, and not to be actioned before M9 closes:*

> **Get BD-14 answered** — the sole outstanding item on M9's closure checklist (§0.13). Drafted as **Question 6** of `docs/UAT_BUSINESS_VALIDATION.md`. The answer lands as `dpc.circulation.effective_date_basis` — no code change, no freeze exception, no re-test.

> **Then close M9:** confirm §0.13, tag `v0.9.0-m9-complete`, declare the M9 freeze as an AD in the shape of AD-21. **M9 is the only built milestone with no freeze baseline**, so "has M9 changed?" cannot be answered by diff.

> **Then open the freeze window** under AD-22 for TD-19, TD-20, TD-21, **TD-28** (absent-allottee deadlock — needs a standing rule across M7/M8/M9) and **AD-25** if **BD-16** is confirmed. **BD-17** (what standing the SRS has) should be answered in the same pass, since it decides whether two named SRS features are withdrawn.

**M9 is not closed, no engineering work remains in it, and this session opened no milestone and wrote no code.**

<!-- CURRENT SESSION — END. Historical architect review follows; preserve verbatim. -->

---

## 1. Directives — status

| # | Directive | Status |
|---|---|---|
| **1** | Persist **Configuration Snapshot UUID** for every Review calculation | ✅ `dpc_review_calculations`. **Derived from the snapshot's content**, not random — see §3 |
| **2** | Persist **Original Calculation Run UUID** | ✅ Same table, same row |
| **3** | Document the Review calculation decorator in `docs/ARCHITECTURE_BASELINE_v0.7.md` | ✅ Created; documents all four decorators, what the pattern costs, and where it stops |
| **4** | Keep **TD-22** open; do not solve it until concurrent workers exist | ✅ Open, and the baseline document records *why* it is deliberately unsolved |
| **5** | Freeze verification before every review | ✅ §5 |
| **AQ-9** | Supersession | ✅ Takes effect at the Review's **approval**, not at its raising — see §2 |
| **AQ-13** | Review documents show original DPC, original date, review DPC, review date, reason | ✅ All five, on every document a Review produces |
| **Ph1 D5** | Superseded documents remain downloadable, watermarked | ✅ Watermarked **on delivery**, never in storage — see §3 |

---

## 2. Supersession takes effect at approval, not at raising

Phase 1 recorded the *intent* — this DPC corrects that one — when the Review was raised, and deliberately left `superseded_at` null. Phase 3 sets it when the Review's **own result is approved**.

The timing is the substance of AQ-9, not a detail of it. **A Review that is cancelled, returned, or simply never approved must leave the original standing.** If raising a Review superseded the original immediately, a department could invalidate a signed promotion list by filing a correction it never pursued.

Recorded on **both** timelines, as Phase 1 established: `dpc_review.supersession_effective` on the Review, `dpc_review.superseded` on the original. A correction chain has to be readable from either end — someone opening the original needs to know it no longer governs.

**The department is notified**, and the message names both DPC codes. A department holding a signed promotion list needs to know which list replaces it, and it knows them by code.

---

## 3. Two decisions where the obvious implementation is wrong

### The watermark is applied on delivery, never in storage

Directive 5 (Phase 1) asks that superseded documents remain downloadable, watermarked `SUPERSEDED`. The obvious implementation regenerates or rewrites the stored file.

That would break two things at once. A generated document is **immutable** (AD-4), and its SHA-256 is what proves a department's copy is the copy the Commission issued — rewriting the bytes invalidates every copy already in circulation.

So the file on disk stays exactly as signed, and the watermark is prepended when the document is **served**. `contents()` is what a reader gets; `storedContents()` and `verify()` are what was issued. Three tests pin the split: the watermark appears on delivery, the stored bytes and hash are unchanged, and a superseded document **still verifies**.

It is also the honest model of what happened. The document *was* issued as it stands and was *later* superseded. Baking the watermark in would assert it had been superseded at the moment of issue.

### The configuration snapshot UUID is derived, not generated

Directive 1 asks for a snapshot UUID per Review calculation. A random UUID would satisfy the letter of it and record nothing useful — only that a snapshot existed.

It is a **UUIDv5 over the canonical snapshot** instead, with keys sorted so ordering cannot change identity. Two calculations that ran under identical rules therefore carry the **same** UUID, which turns *"were these two results decided under the same rules?"* into an equality check rather than a JSON comparison. Tested both ways: reordered keys produce the same UUID, a changed rule version produces a different one.

---

## 4. Architect decisions

Decisions taken in M8 and carried into the permanent register (`docs/ARCHITECTURE_DECISIONS.md`). Nothing here is a matter of taste; each one had an obvious alternative that was rejected for a stated reason.

| # | Decision | Rejected alternative | Why the alternative is wrong |
|---|---|---|---|
| **AD-19** | Stored rules are re-applied **through the configuration mechanism**, never a second code path — a Review restores the original's `configuration_snapshot` into `config('dpc.scoring')` for the duration of the call, restored in a `finally` | A parallel "historic rules" scoring implementation | A second engine is a second thing to keep correct, and the two would diverge on the first rule change. Under AD-3 business rules *are* configuration, so the seam M5 already built is the seam to use |
| **AD-20** | Behaviour is added to a completed milestone **by decoration, never by edit** — four decorators now sit over M7 in the container | Editing M7's services to branch on "is this a Review?" | It breaches the M7 freeze, and it puts M8's concerns inside M7's code where the next milestone finds them. Decoration keeps `git diff v0.7.0-m7-complete -- app/Domain/Proceeding` empty while M7's behaviour is genuinely extended |
| **AQ-9** | Supersession takes effect at the Review's **approval**, not at its raising | Supersede on raising | A department could invalidate a signed promotion list by filing a correction it never pursued. A Review that is cancelled, returned or never approved must leave the original standing — see §2 |
| **AQ-11** | A Review calculates under the **original's** configuration snapshot | Calculate under today's rules | A correction raised to fix a mis-typed CR would also silently re-decide eligibility under thresholds that did not exist when the DPC was held |
| **AQ-13** | Every Review document states what it corrects — original DPC, original date, review DPC, review date, reasons — before anything else on the page | Leave the provenance to the file name or the covering note | A promotion list dated today that silently corrects one from years ago is misleading on its face, and the reader most likely to be misled is the one reading it long afterwards with nobody left to ask |
| **Ph1 D5** | The `SUPERSEDED` watermark is applied **on delivery**, never in storage | Regenerate or rewrite the stored file | A generated document is immutable (AD-4) and its SHA-256 is what proves a department's copy is the copy the Commission issued — see §3 |
| **Ph3 D1** | The configuration snapshot UUID is a **UUIDv5 derived from the canonical snapshot**, keys sorted | A random UUID per calculation | A random UUID satisfies the letter of the directive and records nothing useful. Derived, it turns *"were these two results decided under the same rules?"* into an equality check |
| **Ph2 D2** | `chain_depth` is stored separately from `review_number` | Store one value under two names | Depth counts DPCs in the chain including the original; the number counts corrections. The first Review is depth 2, review number 1 |
| **Ph2 D4** | **Three** chain roles — Original, Superseded Review, Operative | Two roles derived from `superseded_at` | The original is what the department first decided; a superseded Review is a correction that was itself corrected. Collapsing them loses the distinction a tribunal asks about first |

**Standing recommendation on the pattern.** Four decorators is close to the limit I would want to defend. A fifth is the point to stop and ask whether the boundary between M7 and M8 is in the right place, rather than adding it and deciding later. This is stated in `docs/ARCHITECTURE_BASELINE_v0.7.md` so it survives this review being replaced.

---

## 5. Quality gates

**Re-run in full 2026-08-14 (see §0). The figures below are that run, and they are not the figures this section carried before it.** The previous entry recorded *607 passed / 1865 assertions* and *61 tables* — neither was reproducible: the suite could not complete (a container cycle killed the PHP process) and `migrate:fresh` could not build M8's last table on MySQL (identifier over 64 characters). Both are fixed; the CHANGELOG entry *"M8 quality gate run in full: two P0 defects found and fixed"* has the detail. (It was §0.3 when written; §0 is replaced each prompt, so the pointer is to the permanent record instead.) The corrected figures:

| Gate | Result | Was recorded as |
|---|---|---|
| Laravel Pint | **Pass** | Pass — unchanged |
| PHPStan (Larastan level 6) | **0 errors** | 0 errors — unchanged |
| PHPUnit | **620 passed / 1902 assertions, 0 failing** (95 MB peak, 1m19s) | 607 / 1865 — **the suite never reached the end** |
| `composer audit` | **No advisories** (first attempt) | No advisories — unchanged |
| `php artisan migrate:fresh --seed` | **OK** — 43 migrations, **62 tables**, 10 seeders | OK, 61 tables — **migration 290004 failed on MySQL** |

**Run bounded (`php -d memory_limit=2G`).** CLI `memory_limit` is `-1` in this environment, which is why the cycle exhausted the machine instead of failing: PHP never self-limited. A bounded limit is how a defect of that shape reports itself rather than taking the host down.

**Freeze verification (Directive 5):**

```
git diff --name-only v0.7.0-m7-complete | grep app/Domain/(Dpc|Eligibility|Cgpsc|Proceeding)/
→ PASS — M4/M5/M6/M7 untouched
```

Worth noting what that covers this phase: M8 changed how M7 approves a result, how it renders a document, and how it serves one — **with no M7 file modified.** Four decorators now sit over M7, each returning the parent's behaviour untouched for a Fresh DPC, each with a test asserting so.

---

## 6. Reviews

### Security review

| Concern | Finding |
|---|---|
| Superseding a DPC without approving anything | Impossible — `superseded_at` is written only inside the approval path, after M7's own checks pass |
| Un-superseding | Refused at the model layer; only a null → timestamp transition is permitted, once |
| A watermark hiding tampering | `verify()` reads **stored** bytes, never served ones. Tested |
| A department losing access to a superseded record | Superseded documents remain downloadable; the watermark is added, nothing is withdrawn |
| Publishing a provisional Review result | Refused, inheriting M7's Directive 5. Tested on a Review — it fires, because US3's case is exactly BD-2/BD-3 |
| Cross-tenant leakage | Unchanged from Phase 1; department scoping on every path |

### Performance review

| Concern | Finding |
|---|---|
| Watermark check per document served | One indexed query on `original_dpc_proposal_id`; only on delivery, not on generation |
| Provenance write | One insert per calculation |
| Supersession | One update plus two timeline writes, inside the approval transaction |
| Decorator overhead on a Fresh DPC | One link lookup that returns null and short-circuits |

### VAPT self-review

| Check | Result |
|---|---|
| Forged supersession via request | No input reaches the link; it is resolved from the proposal |
| Document substitution | Content hash unchanged and verified against stored bytes |
| Replay of the supersession notification | Event carries the chain UUID and review number; a subscriber can deduplicate |
| Global state | `finally` restore unchanged from Phase 2; TD-22 remains the only residual, deliberately |

### PPT comparison — S44

| Element | Status |
|---|---|
| S44 — Main and Waiting lists after review | ✅ M7's result, unchanged |
| S44 — `APPROVE BY CGPSC` | ✅ M7's approval, decorated to supersede |
| S44 — the Review's own promotion list | ✅ With the original's identity on it (AQ-13) |

**No divergence.** S44's expected output is identical to S34's and inherits **BD-9** and **BD-10** exactly as the design predicted — a Review's promotion list is produced by the same code as a Fresh DPC's.

---

## 7. Open business decisions

Full register: `docs/PENDING_BUSINESS_DECISIONS.md`. Put to business users in plain language in `docs/UAT_BUSINESS_VALIDATION.md`. **Nothing here is an engineering task** and none of it is resolved unilaterally.

### BD-11 — deferred, and what the deferral commits us to

**Question:** what happens to an officer who was already promoted under a DPC that is later reviewed and superseded?

**Raised:** 2026-08-14, at M8 design. **Severity:** P1. **Status:** **Deferred Business Decision — Required before production activation of the Review supersession policy**.

**Architect ruling, 2026-08-14: BD-11 does not block M8 completion.** It is reclassified from *blocking M8 closure* to a deferred business decision. **What it now gates is narrower and later, and it is worth stating precisely: the activation of the Review supersession policy in production.** M8 may be built, gated, committed, tagged and demonstrated without it. What may not happen without it is the Commission relying on a superseded promotion list to settle an officer's rank and pay.

**What the system does today, and why that is the correct behaviour while the question is open:**

- On approval of a Review, the DPC it corrects is marked superseded, on **both** timelines, and the department is notified with both DPC codes.
- The superseded DPC's documents remain downloadable and carry a `SUPERSEDED` watermark on delivery.
- **The system asserts nothing about the promotion itself.** It does not withdraw it, does not annotate the officer's record as reverted, and does not compute arrears or a revised date of promotion.

That is deliberate. The three plausible answers — the promotion stands and only the paper is corrected; the promotion is withdrawn from the date of the Review; the promotion is withdrawn retrospectively with recovery of pay — differ in what they do to a serving officer's substantive rank and pay. Choosing one silently would be the system deciding a service-law question. Recording the supersession and telling the department is the most the software can honestly do until CGPSC rules.

**What the ruling is still needed for.** Not for M8, which is complete. For **production activation**: until CGPSC answers, the system's silence about the promotion is a *deliberate abstention*, not a policy. The answer may turn out to be nothing more than a documented confirmation that supersession corrects the record and leaves the promotion undisturbed — but that confirmation is what turns today's abstention into a defensible position, and a department acting on a superseded list needs it in writing rather than inferred from what the software declines to do.

**One consequence of deferral worth naming.** A `SUPERSEDED` watermark tells a reader the list no longer governs; it does not tell them what happened to the promotion recorded on it. Anyone reading a superseded promotion list between now and the ruling is reading a document whose legal effect is genuinely undetermined. That is a documentation and communication risk, not a code defect, and deferring BD-11 accepts it knowingly.

### The four carried forward

| # | Question | Effect while open |
|---|---|---|
| **BD-2 / BD-3** | Reserved relaxation — automatic or committee discretion, and does relaxation to 10 apply beyond Class-I→I? | Any DPC where a reserved category has fewer eligible officers than vacancies returns a **provisional** result, which **cannot be published**. Fires on Reviews too — US3's case is exactly this |
| **BD-9** | Which waiting list does an officer join when their own category has no vacancy? | **The one to chase.** It raises no flag, so it can produce a confidently wrong *published* list rather than a blocked one. M5 is not to be modified for it (standing ruling) — it is raised to CGPSC |
| **BD-10** | Does a pending court case annotate the remark? | Marks the result provisional; publication blocked |

**BD-1** (does `PUNISHMENT` disqualify?) remains ruled a business rule, not implemented, with current behaviour pinned by test.

---

## 8. Technical debt

Full register: `docs/TECHNICAL_DEBT.md` — permanent, not reset at a phase boundary. The four items below are the ones this phase touched or deliberately left alone.

| # | Item | Status after Phase 3 | Recommendation |
|---|---|---|---|
| **TD-19** | `DpcProposal` has no `HasUuids`; new proposals get their UUID minted by direct update because adding the trait would modify frozen M4 | **Open. Now two callers** — `ProceedingService::open()` and `ReviewDpcService::create()` | One line in `DpcProposal` when the M4 freeze is next legitimately opened. Cheapest of the four and the most callers |
| **TD-20** | The department-scoped notification recipient query is duplicated across listeners | **Open. Now three copies** — Phase 3's `SendSupersessionNotification` is the third | Extract `NotificationRecipientResolver` when the M6 freeze is next opened. Two copies of "who should be told" is how one of them quietly stops matching the other; three is worse |
| **TD-21** | Calculation Engine Version is not on `dpc_calculation_runs` — it is on the timeline, the proceeding and every document, but not the run row | **Open, unchanged.** M8 records its own provenance in `dpc_review_calculations` rather than writing to M5's table | Add the column when the M5 freeze is next opened |
| **TD-22** | The rule-reuse config override is process-global (AD-19) | **Open by directive.** Restored in a `finally`; PHP-FPM serves one request per process, so two calculations cannot interleave today | **Do not solve it yet.** It becomes real only if calculation is queued or run in a concurrent worker. `ARCHITECTURE_BASELINE_v0.7.md` records why solving a concurrency residual before concurrent workers exist would be designing against a hypothesis |

**All four are blocked by a freeze, not by effort.** TD-19, TD-20 and TD-21 are each a small, well-understood change whose only obstacle is AD-15. **Recommendation: batch them into one authorised freeze opening** — with the gate and the freeze-verification diff run around it — rather than letting each wait for a reason of its own to arrive. That opening is best scheduled at a milestone boundary, and M8's closure is the obvious candidate.

---

## 9. Notes carried forward

- **BD-11 is deferred and no longer gates M8** (architect ruling 2026-08-14). It gates **production activation of the Review supersession policy**. Everything M8 was scoped to build exists, is gate-verified, committed and tagged.
- **`docs/ARCHITECTURE_BASELINE_v0.7.md` is now the document to read before extending a frozen or completed milestone.** It documents all four decorators, the rule-reuse mechanism, what the pattern costs (indirection — the class that runs is not the class the caller names) and where it stops.
- **The branch name `feature/m0-setup` is eight milestones stale** and describes none of what it contains. Renaming rewrites the remote ref, so it is left for a decision rather than done silently.
- **M9 (Circulation DPC) faces the same supersession question M8 raised** — it resolves a held promotion (`CIR`) from an earlier DPC. BD-11's answer will inform it. **BD-11 gates neither M8's completion nor M9's start**; it gates production activation of the supersession policy, which means M9 can be designed while the answer is outstanding but inherits the same deferral.

---

## 10. Architect approval checklist

| # | Item | Evidence | Status |
|---|---|---|---|
| 1 | Design approved before implementation | `docs/design/09-m8-review-dpc.md`; all 14 architect questions ruled | ✅ |
| 2 | Every Phase 3 directive addressed | §1 — Directives 1–5, AQ-9, AQ-13, Ph1 D5 | ✅ |
| 3 | Architect decisions recorded in the permanent register | §4; `docs/ARCHITECTURE_DECISIONS.md` AD-19, AD-20 | ✅ |
| 4 | Laravel Pint (PSR-12 + strict types) | §5 | ✅ Pass |
| 5 | PHPStan / Larastan level 6 | §5 | ✅ 0 errors |
| 6 | PHPUnit | §5, re-run 2026-08-14 | ✅ **620 passed / 1902 assertions** *(was recorded as 607/1865; the suite never completed)* |
| 7 | `composer audit` | §5 | ✅ No advisories, first attempt |
| 8 | `migrate:fresh --seed` | §5, re-run 2026-08-14 | ✅ **62 tables**, 10 seeders *(was recorded as 61; migration 290004 failed on MySQL)* |
| 9 | Freeze verification — M4/M5/M6/M7 untouched | §5, by diff against `v0.7.0-m7-complete`, re-run after this prompt's fixes | ✅ Empty |
| 10 | Migrations additive only (AD-16) | Four new tables/columns; nothing altered or dropped | ✅ |
| 11 | Security review | §6 | ✅ Complete |
| 12 | Performance review | §6 | ✅ Complete |
| 13 | VAPT self-review | §6 | ✅ Complete |
| 14 | Full PPT comparison (S44) | §6 — no divergence | ✅ Complete |
| 15 | Golden test per business rule | Unchanged — M8 adds no business rule; it reuses M5's | ✅ |
| 16 | Demo readiness — independently demoable to the customer | Raise a Review against a concluded DPC → correct → approve → superseded original watermarked on download | ✅ |
| 17 | Business questions in their own register, not resolved unilaterally | §7; `docs/PENDING_BUSINESS_DECISIONS.md` | ✅ |
| 18 | Technical debt recorded, not silently carried | §8; `docs/TECHNICAL_DEBT.md` | ✅ |
| 19 | Four mandatory documents synchronized | `PROJECT_STATUS.md`, `PROJECT_PROGRESS.md`, `CHANGELOG.md`, this file (+ `README.md`) | ✅ Committed in `1c8a8e1` |
| 20 | Milestone boundary committed | Two commits: `1c8a8e1` *docs: finalize M8 documentation* (13 files) and `a60689d` *feat(m8): Review / Re-DPC chain (Phases 1-3)* (31 files, +4454/-2) | ✅ Done |
| 21 | Milestone boundary pushed | `6f52f71..a60689d` pushed to `origin/feature/m0-setup`. Local and remote `HEAD` both `a60689d…`, verified by `git rev-parse` | ✅ Done |
| 22 | Working tree clean | Clean at the moment of tagging. **Three documents modified since**: this file (§0 and these rows record the commit — a document cannot describe its own commit from inside it), plus three stale closure phrases found in `PROJECT_STATUS.md` / `PROJECT_PROGRESS.md` after `1c8a8e1` and corrected on disk. See §0.5 | ⚠️ Three documents, no code |
| 23 | No temporary or scratch files in the working tree | Verified — `docs/AI_SESSION.md` (created and deleted 2026-08-14) was the only one, and it is gone from index and worktree | ✅ |
| 24 | M8 tag created | Annotated tag **`v0.8.0-m8-phase3`** at `a60689d`, pushed. Confirmed on the remote by `git ls-remote --tags`. M9's freeze verification now has its baseline | ✅ Done |
| 25 | **M8 complete** | **Yes** — architect ruling 2026-08-14 reclassified BD-11 as deferred; all five gates green; committed and tagged | ✅ |
| 25a | **M8 freeze declared** | **Architect decision 2026-08-14 — ACTIVE** at baseline `v0.8.0-m8-phase3`. Five rules, recorded permanently as **AD-21**; verified by `git diff v0.8.0-m8-phase3 -- app/Domain/Review` | ✅ Declared |
| 25b | **Freeze Exception mechanism defined** | **AD-22**, 2026-08-14 — approver, admissibility, the five recorded fields, three scopes, mandatory full gate plus all-milestone freeze diff, automatic re-arm at a new baseline, and a three-artefact audit record. Applies to all five freezes. Register empty; no exception requested | ✅ Defined |
| 26 | **M9 started** | **No** | ⛔ Not started |

**Filled in from what happened, 2026-08-14.** Items 19–24 are now recorded against commit hashes, a pushed ref and a tag confirmed on the remote, each verified by command. Earlier in this session items 20 and 21 read ✅ on the evidence "This commit, on `feature/m0-setup`" and "`git status` clean after commit" while **no commit had been made and nothing had been pushed** — both written in anticipation. Item 21's two distinct claims (pushed / tree clean) have been split into items 21 and 22 so neither can pass on the strength of the other, and items 24–26 renumbered accordingly. Items 4–9 remain ✅: those gates genuinely executed during the implementation session, and no source file has changed since (see §0.6 for the caveat that they have not been *re*-run).

**Items 19–24 are done.** The one row not fully green is item 22, and it is green-by-construction rather than outstanding: recording a commit necessarily happens after it.

---

## 11. Status

**M8 is COMPLETE.** All three phases exist, are tested, and pass all five gates — Pint, PHPStan level 6, **620 passed / 1902 assertions**, `composer audit`, `migrate:fresh --seed` (62 tables). M4, M5, M6 and M7 are untouched, verified by diff against `v0.7.0-m7-complete`. Committed on `feature/m0-setup`, pushed, and tagged **`v0.8.0-m8-phase3`**.

**BD-11 is deferred** (architect ruling 2026-08-14) and no longer gates the milestone. It is a **Deferred Business Decision — Required before production activation of the Review supersession policy**. Until CGPSC rules, the system records the supersession, notifies the department, and asserts nothing about the promotion itself.

**M8 is FROZEN.** Architect decision, 2026-08-14, ACTIVE at baseline `v0.8.0-m8-phase3` / `a60689d`, recorded permanently as **AD-21**: no production code changes inside M8; bug fixes only, each with Architect approval; documentation may continue; M9 must not modify the M8 implementation; anything else requires a formal Freeze Exception. **Five freezes are now in force, and M8's is the strictest** — the other four accept security, performance and hardening work without per-change approval.

**The register contradiction is closed.** `docs/PENDING_BUSINESS_DECISIONS.md`, `docs/UAT_BUSINESS_VALIDATION.md` and `docs/TECHNICAL_DEBT.md` now carry BD-11's deferred classification in the same words as this document, and TD-23 and TD-24 are on the debt register.

**One thing the freeze names but does not define: the Freeze Exception.** See §0.4.

**M9 has not begun**, and will not begin without approval.
