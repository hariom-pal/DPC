# Architect Review

**Date:** 2026-08-18
**Subject:** **M9 closure readiness confirmed (§0.13.1)** · documentation consolidation plan retained as a completed deliverable (§0.1–§0.12). No code changed
**Baseline:** `v0.8.0-m8-phase3`; **M9 is CLOSURE-READY and not closed — held open by BD-14 alone**

**No milestone was opened or closed, and no file but this one was changed.** §0 is this session; **§0.13 is pinned** (M9's live closure checklist, referenced by section number from two other documents); §1 onward is the historical record from M8 Phase 3 and earlier, preserved verbatim.

**Previous sessions' §0 content is not lost** — it is in `git log` for this file, and its durable outputs were written to the permanent registers at the time: AD-25 (proposed), BD-16/17/18, TD-25/26/27/28, and the worklogs under `docs/worklog/`.

---

<!-- CURRENT SESSION — START. This section is REPLACED in full on every prompt. Never appended. Everything below §0 is historical architect review and must be preserved verbatim. §0.13 is PINNED — see the note there. -->

## 0. Current Session

**Updated:** 2026-08-18 · **M9 closure readiness confirmed (§0.13.1) and the repository is clean (§0.13.2). Read those two first.** §0.1–§0.12 hold the completed documentation consolidation plan, retained rather than replaced because it is a deliverable, not working notes. **No code touched; nothing merged, deleted or moved.**

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

### 0.14 For the Architect — copy-paste

**M9 is closure-ready. The first item below is the only one that unblocks a milestone; everything after it is backlog.**

> **Answer BD-14** — *from what date does a circulated promotion take effect?* It is **the sole outstanding item on M9's closure checklist**; items 1–19 are green and 20–23 are consequences of this one question. Drafted for CGPSC as **Question 6** of `docs/UAT_BUSINESS_VALIDATION.md`. The answer lands as `dpc.circulation.effective_date_basis` — **no code change, no freeze exception, no re-test.** Until it lands, signed and verifiable circulation documents cannot reach the departments they were produced for.

> **Then execute the six closure steps in §0.13.1** — apply the configuration, confirm publication, re-run the gate, tag `v0.9.0-m9-complete`, declare the M9 freeze, synchronise the documents.

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
