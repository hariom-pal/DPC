# Architect Review

**Date:** 2026-08-18
**Subject:** Pre-M10 verification sweep — Recruitment Rule screen (item 1F), PPT/SRS gap analysis, Pre-DPC→DPC linkage, Excel upload, role-based UI; then the Pre-DPC screen made master-sourced (§0.11a)
**Baseline:** `v0.8.0-m8-phase3`; M9 engineering complete, **closure pending BD-14**

**No milestone was opened or closed.** §0 is this session; §1 onward is the historical record from M8 Phase 3 and earlier, preserved verbatim.

---

<!-- CURRENT SESSION — START. This section is REPLACED in full on every prompt. Never appended. Everything below §0 is historical architect review and must be preserved verbatim. -->

## 0. Current Session

**Updated:** 2026-08-18 · **Pre-M10 verification sweep — Recruitment Rule screen, PPT/SRS gap analysis, Pre-DPC→DPC linkage, Excel upload, role-based UI** · Section replaced in full, not appended.

### 0.1 Prompt received

> Six items, in the client's own numbering: **(1)** Recruitment Rule UI improvements A–F; **(2)** verify complete PPT implementation with a comparison table; **(3)** verify the Pre-DPC → DPC relationship against business's latest requirement that *"Approved Pre-DPC should be used while creating DPC"*; **(4)** verify whether the PPT requires Excel upload during DPC creation and implement it if missing; **(5)** deliverables — UI improvements, PPT vs code gap, SRS vs code gap, missing business requirements, recommendation before M10 freeze; **(6)** role-based UI cleanup so no user is offered an action that returns Unauthorized. *"Do NOT blindly implement. First verify… Then implement only the changes that are valid."*

### 0.2 The short answer

**Four of the six items were already built.** Verification, not implementation, was the substance of this session. What was actually built is small and is listed in §0.4. What was *found* is larger and is the reason this section is long.

| # | Item | Verified state | Action taken |
|---|---|---|---|
| **1 A–E** | Target Cadre from master; Feeder **Cadre** searchable, FK, multiple; Promotion Share (%) labelled + totals 100%; Minimum Experience + Years/Months; Qualification Master | **Already built** 2026-08-17 | None — left alone |
| **1 F** | DR % / PR % width, labels, **DR + PR = 100** | Labels present, **validation absent**, fields too narrow | **Built** |
| **2** | Every PPT screen and business rule | 51 of 52 slides implemented; **S49–S51 not started (M10)** | **Documented** — §0.6 |
| **3** | DPC references Approved Pre-DPC | **It does not.** Real functional gap | **Documented + recommended; not implemented** — §0.7 |
| **4** | Excel upload at DPC creation | **Already built** (M4 Phase 6) for all three PPT upload points | None — verified only, §0.8 |
| **6** | Role-based UI visibility | Mostly correct; **four concrete defects** | **Fixed** — §0.9 |

**One item was deliberately not implemented.** Item 3 is a schema and workflow change to **M4, which is architecture-frozen (AD-15/AD-16)**, and the client's own permanent rule 2 forbids changing a business workflow without explicit approval. It is specified in full, costed, and put to the Architect as **AD-25 (proposed)** and to business as **BD-16**. Implementing it silently would have broken two standing rules to satisfy a third.

### 0.3 Audit before code — what item 1 asked for that already existed

The client's item 1 reads as six new requirements. Five had been delivered the previous day and are recorded in `docs/worklog/2026-08-17-recruitment-rule-master-data-integrity.md` and `…-screen-usability.md`. **They were re-verified against the running application rather than taken from the worklog**, because a worklog is a claim and the screen is the evidence:

| Asked for | Verified in code | Verdict |
|---|---|---|
| **A** Target Post from Cadre Master | `form.blade.php` — `<select data-searchable>` over `$cadres`; `cadre_id` → `Rule::exists('cadres','id')` | ✅ present |
| **B** Rename to "Feeder Cadre", searchable, no free text, multiple | Column header reads *Feeder Cadre*; `feeder_cadre_id` non-nullable FK; add/remove rows; `distinct` + DB unique index; `different:cadre_id`; same-department check | ✅ present |
| **C** "Promotion Share (%)" label on every row + total = 100% | Header label, per-column helper text, live running total, `validateShareTotal()` | ✅ present |
| **D** "Minimum Experience" + Years/Months, default Years | `min_experience_unit` + `ExperienceUnit` enum; defaulted in `prepareForValidation()` | ✅ present |
| **E** Qualification Master, no free text | `qualifications` table, CRUD screen, `qualification_id` FK, free-text input **absent** from the form | ✅ present |
| **F** DR/PR width, labels, **DR + PR = 100** | Labels present; **no sum validation anywhere**; both fields `col-md-3`, too narrow for `100.00` | ❌ **gap** |

**Only F was missing, and only the validation half of F was substantive.** That is the whole of what item 1 required this session.

### 0.4 What was built

#### F.1 — DR % + PR % must total exactly 100%

Enforced server-side in **two** places, deliberately:

| Where | Why there |
|---|---|
| `RecruitmentRuleRequest::validateRecruitmentSplit()` | The screen the client named |
| `SavePreDpcProposalRequest::withValidator()` | **Because approving a Pre-DPC projects its posts into that same master.** Validating only at the master would let a department approve a Pre-DPC that silently writes a rule the admin screen then refuses to re-save — the exact drift that `RecruitmentRuleService::normaliseShares()` had to be introduced to prevent for the feeder Promotion Share column. One rule, enforced at both ends, is the only version of this that cannot rot |

**Traceability (permanent rule 17).** The PPT states the rule by example rather than in words: **every row of S9 and S11 reads DR 0 / PR 100**, and the seeded Schedule-IV ladder runs 100/0, 50/50, 25/75 and 0/100. **No worked example anywhere in either deck leaves a remainder.** SRS Screen 4 lists *Promotional Percentage* with no counterpart and states no total. So the rule is: **PPT by worked example + explicit business instruction of 2026-08-18.** Recorded here rather than assumed.

**It is a write-path rule only, and that is deliberate.** Existing rows are never rewritten. One development row (`Junior Assistant`, DR 0 / PR 1, created during last session's browser driving) does not satisfy it; it is still read, listed and editable, and is only refused **on save**. Silently rescaling a Government Order's own percentages would be inventing policy — the same reason the Promotion Share migration refused to invent a total for an all-zero set. A test pins this: `test_an_existing_unbalanced_rule_is_still_readable`.

**The error is reported against its own key, `recruitment_split`, not against either field.** Neither number is wrong on its own; it is the pair that does not add up, and blaming one of them tells the user to change the field they may not have meant to. The message names the actual total and the shortfall — *"They currently total 80% — 20% of the posts is unaccounted for."*

**Tolerance is one hundredth of a percent**, identical to the Promotion Share rule, so the two "must equal 100%" rules on the screen cannot disagree about what *exactly* means. `33.33 + 66.67` passes.

#### F.2 — Width and labels

- `Direct Recruitment (%)` → **`Direct Recruitment — DR (%)`**, `Promotion (%)` → **`Promotion — PR (%)`**, both with the required-marker they lacked and a `%` input-group affix.
- Both widened from `col-md-3` to `col-md-4 col-lg-3` and moved onto **their own row** with a live **DR + PR** total beside them, shown exactly like the feeder Promotion Share total — number, then ✓ or ✗. They are the only two fields on the screen that constrain each other; showing them together is what makes the constraint legible.

#### F.3 — The same fields on the Pre-DPC screen

`resources/views/pre-dpc/form.blade.php` carried DR % and PR % as **`col-md-1` boxes too narrow to display `100.00`**, labelled only by abbreviation. Since the new validation fires on that screen, a user has to be able to see the numbers it is complaining about. Widened to `col-md-3`, full labels, `%` affix, per-card live total. **No field name changed**, so no API changed.

#### 0.4.1 Verification of the built work

- **782 tests pass** (2351 assertions) — up from 759; **23 new**, none modified.
- **Pint pass · PHPStan (Larastan L6) 0 errors · `composer audit` no advisories.**
- **All five freezes verified empty** — `git diff v0.6.0-m6-complete -- app/Domain/{Dpc,Eligibility,Cgpsc}`, `v0.7.0-m7-complete -- app/Domain/Proceeding`, `v0.8.0-m8-phase3 -- app/Domain/Review`. Nothing.
- **Driven over HTTP against the running application**, not asserted only in tests: posting DR 30 / PR 50 returns 302, renders *"…currently total 80% — 20% of the posts is unaccounted for"*, and **writes no row** (`GO/SMOKE/1` count = 0).

### 0.5 Documents read for this review

`docs/ODPC_NEW_FINAL.pptx` (52 slides, via `docs/design/reference/odpc_new_final.extracted.txt`), `docs/CONVENTIONAL DPC PROCESS.pptx` (7 slides), and **`docs/archive/DPC- SRS.docx` extracted afresh** — the SRS had not previously been quoted field-by-field in this file, and item 5 asks for an SRS-vs-code gap specifically.

### 0.6 PPT vs Code — the comparison table item 2 asked for

**Format as requested: PPT Requirement → Implemented → Missing → Different Implementation.**

#### 0.6.1 By screen

| PPT | Requirement | Implemented | Missing | Different implementation |
|---|---|---|---|---|
| S5–S6 | Department registration; AO + AH credentials by SMS/email | ✅ M1 | — | Live SMS gateway not connected (**TD-6**) — mechanism works, delivery does not |
| S7–S9 | Pre-DPC: posts sanctioned for promotion — pay scale, DR %, PR %, feeder cadres, per-cadre %, experience **in years** | ✅ M3 | — | **Experience now carries a Years/Months unit** — a superset of S9, business-requested 2026-08-18. **Minimum Qualification exists and is not on S9 at all** — see §0.6.3 |
| S10–S11 | AH approves the Pre-DPC entry | ✅ M3 | — | — |
| S12–S14 | DPC proposal: post to/from + class, DPC type, legal-stay declaration, proposal date | ✅ M4 | — | Post dropdowns list **all active cadres**, not the Pre-DPC's sanctioned posts — **this is item 3, §0.7** |
| S15 | DPC committee details | ✅ M4 | — | — |
| S16 | Officers under consideration + sanctioned count + vacancy breakdown; **"प्रविष्ट करें अथवा अपलोड करें"** | ✅ M4 | — | — |
| S17 | Final seniority list + zone `2×V+4`; entry **or** upload | ✅ M4 / M5 | — | — |
| S18 | Tabulation chart — loyalty, immovable property, year-wise CR, note; entry **or** upload | ✅ M4 | — | — |
| S19–S25 | AH reviews all five sections and approves; digital signature | ✅ M4 Phase 7 | — | Aadhaar-OTP e-sign is the **mock provider**; real CSC pending **BD-4** |
| S26–S28 | Chairman allotment + re-allotment + allotment report | ✅ M6 | — | — |
| S29–S30 | Member acceptance, scheduling, re-scheduling | ✅ M6 | — | — |
| S31–S33 | The sitting: editable workspace, CALCULATE, marks and remarks | ✅ M7 | — | Corrections are **append-only** rather than in-place edits — stronger than S33, and required for an auditable record |
| S34 | Main List + Waiting List; APPROVE BY CGPSC | ✅ M7 | — | A result affected by **BD-2/BD-3/BD-10** is marked *provisional* and cannot be published — declines to guess |
| S35–S40 | Review DPC: reasons, chart correction, AH approval | ✅ M8 | — | **Had no UI entry point until this session** — §0.9 |
| S41–S43 | Action on Review DPC | ✅ M8 | — | — |
| S44–S48 | Circulation DPC: note, per-officer consent, revised list, prescribed form | ✅ M9 | — | **Publication blocked on BD-14**; CR-ground holds refused pending **BD-15** |
| **S49–S51** | **Self-inspection: officer views own data, PRINT, RAISE OBJECTION before the deadline** | ❌ | **Entire feature — M10, not started** | — |
| S52 | Closing slide | n/a | — | — |

#### 0.6.2 By business rule (Conventional DPC deck, C4–C6)

| Rule | Implemented | Where |
|---|---|---|
| CR grades क+/क/ख/ग/घ = 4/3/2/1/0 | ✅ | `ScoringService` |
| Tribal-area service **+1** | ✅ | `ScoringService` |
| No CR may be **घ (0)** in the last 5 years | ✅ | `EligibilityService` |
| Class-I→I: last year's CR may not be **ग (1)** | ✅ | `EligibilityService`, configuration-driven per class pair |
| Thresholds ≥15 / ≥13 / ≥11 / ≥10 by class pair | ✅ | `RuleSet` — configuration, not code |
| Zone: 1 vacancy → 5 officers; else **2×V+4** | ✅ | `ZoneOfConsiderationService` |
| Non-Class-I→I: all officers meeting the experience bar | ✅ | `ZoneOfConsiderationService` |
| Super-seat at 20/20, actual seniority decides ties | ✅ | `SuperSeatService` |
| Reserved zone ×7 expansion | ✅ | `ZoneOfConsiderationService` |
| **Reserved relaxation to 10 marks** | ⛔ **Blocked, not missing** | **BD-2 / BD-3** — refuses to guess; result marked provisional |
| 5 most recent available CR years | ✅ | M5 engine rule |

**Every rule the two decks state is implemented, except the one that is deliberately blocked on a business ruling.** That is the correct state, not a gap.

#### 0.6.3 Two places where the code exceeds the PPT — declared, not hidden

Permanent rule 12 requires a mismatch to be documented before it is implemented. Both of these were implemented before this session; both are recorded here because neither had been stated as a *deviation* in this file.

| Item | PPT reference | SRS reference | Current code | Reason for change | Recommendation |
|---|---|---|---|---|---|
| **Minimum Qualification** on a feeder cadre | **Absent.** S9/S11 columns are: पद नाम, वेतनमान, DR %, PR %, संवर्ग, %, अनुभव | **Absent.** SRS Screen 4 lists Promotion Post, Feeder Post, Minimum Service, Recruitment Rule No, Promotional Percentage | `qualifications` master + `qualification_id` FK on `recruitment_rule_feeders`; free text retained as the legacy record | Explicit business instruction, 2026-08-17 ("Create a Qualification Master… Qualification must be selected from Qualification Master") | **Keep.** It is optional (`nullable`), so a Schedule-IV rule that imposes no qualification is still expressible, and it does not enter any calculation. **No eligibility path reads it** — verified by grep, not assumed |
| **Experience unit** (Years / Months) | S9 header reads **"अनुभव वर्षों में"** — years only | *"Minimum Service Required — Numeric — Required years of service"* — years only | `min_experience_unit`, default `years`, backfilled explicitly | Explicit business instruction, 2026-08-17 | **Keep.** Additive, defaulted, and not required on the wire, so every pre-existing caller is unaffected — `RecruitmentRuleTest` passes unmodified |

**Neither changes a promotion outcome.** `promotion_percentage`, `min_experience_years` and `qualification_id` are read only inside the Master module and the Pre-DPC projection; no eligibility, scoring, zone, proceeding, review or circulation path reads any of them.

### 0.7 Item 3 — Pre-DPC → DPC. **The finding of this session.**

#### 0.7.1 The five questions, answered

**1 · What does the PPT specify?**

S7 makes the Pre-DPC the register of *"विभाग में उपलब्ध पदोन्नति के पदों का विवरण… संवर्ग जिनसे पदोन्नति हो सकती है"* — the posts on which promotion is available **and the cadres from which promotion may occur**. S14 then offers the DPC's *"पद नाम जिस पर पदोन्नति होनी है"* and *"जिससे"* as **dropdowns whose options are exactly the three posts entered on S9** — DEPUTY SECRETARY, UNDER SECRETARY, SECTION OFFICER. **The PPT shows the dependency by construction: the DPC's post list is the Pre-DPC's post list.** It nowhere states the constraint in words.

**2 · What does the SRS specify?**

Screen 4, *Feeder Cadre Management* — Promotion Post, Feeder Post, Minimum Service Required, Recruitment Rule No, Promotional Percentage — establishes the feeder relationship as master data. §3.3 requires *"auto-validation for eligibility criteria"* on proposal submission. **The SRS neither states nor forbids the linkage.** It is silent.

**3 · What does the current code implement?**

Approving a Pre-DPC calls `PreDpcProposalService::projectToMasters()`, which upserts each post into `cadres` and each feeder into `recruitment_rules` / `recruitment_rule_feeders` (architect ruling Q1 — the masters are the single source of truth). **So the Pre-DPC's content does reach the DPC — but only by having been dissolved into the Cadre master.**

**4 · Does DPC reference Approved Pre-DPC?**

**No — and not indirectly either, in the way that matters.**

- `dpc_proposals` has **no `pre_dpc_proposal_id`** column. Verified against the migration.
- `grep -rn "pre_dpc\|PreDpc" app/Domain/Dpc/ app/Http/Controllers/Dpc/` returns **nothing**.
- `DpcProposalController::formData()` offers **every active cadre in the department**.
- `SaveDpcProposalRequest` validates only that both cadres exist, are `active`, and share the department — **it never checks that a Recruitment Rule links `post_from` to `post_to`.**

**5 · Is it a functional gap?** **Yes, and it is a correctness gap rather than a cosmetic one.**

A department can create, complete, sign and forward a DPC for a post pair **that no approved Pre-DPC ever sanctioned** — for example promoting Teacher → Principal when the approved Schedule-IV ladder says Teacher → Head Master → Lecturer → Principal. Nothing refuses it at any stage. The Commission would then allot, schedule and conduct a sitting on a promotion path with **no Government Order behind it**, and the first place the defect could surface is a court challenge to the promotion.

**Two aggravating details found while verifying:**

- The Cadre master is **cumulative and never pruned**. A post that appeared in Pre-DPC v1 and was dropped in v2 remains an `active` cadre, so it stays selectable indefinitely.
- A department with **no approved Pre-DPC at all** can still create a DPC, provided cadres exist from any other source (the Admin's Cadre screen, or a seeder). The Pre-DPC stage is, today, skippable.

#### 0.7.2 Recommended architecture

**Three options were considered. The middle one is recommended.**

| | Option A — validate only | **Option B — reference the rule (recommended)** | Option C — FK to the Pre-DPC |
|---|---|---|---|
| **Change** | Add a `withValidator()` check that an active Recruitment Rule links the pair; filter the dropdowns to sanctioned pairs | A, **plus** persist `recruitment_rule_id` on `dpc_proposals`, resolved at creation | Persist `pre_dpc_proposal_id` |
| **Schema** | none | +1 nullable FK | +1 nullable FK |
| **Answers "was this sanctioned?"** | at creation only | **at creation and forever after** | yes |
| **Survives a later Pre-DPC amendment** | ❌ | **✅ — the rule version is pinned** | ⚠️ points at a superseded version |
| **Fits the existing architecture** | ✅ | **✅ — consistent with the Q1 ruling that the masters are the source of truth** | ❌ **contradicts Q1** — reintroduces the Pre-DPC as a runtime dependency after it was deliberately dissolved into the masters |
| **Backfillable** | n/a | ✅ — existing DPCs resolve by post pair, or stay null and are reported | ⚠️ ambiguous where several versions match |

**Recommendation: Option B.** The business requirement is *"Approved Pre-DPC should be used while creating DPC."* An approved Pre-DPC **is** the Recruitment Rule, by the Q1 ruling — so binding a DPC to the rule binds it to the Pre-DPC, and binds it to the *version in force on the day*, which a direct FK does not. Concretely:

1. `SaveDpcProposalRequest` gains a rule that an **active Recruitment Rule for `post_to_cadre_id`** must list `post_from_cadre_id` among its feeders, effective on `proposal_date`. Message names the sanctioned feeders for that post rather than saying "invalid".
2. `formData()` offers only sanctioned pairs; picking the target filters the source list, as the Recruitment Rule screen already does.
3. `dpc_proposals.recruitment_rule_id` — **nullable** FK, `ON DELETE RESTRICT`, set at creation. Nullable because pre-existing rows have no answer and **inventing one would be worse than recording that it is unknown**.
4. A backfill **report**, not a backfill: `php artisan dpc:unlinked-proposals`, in the shape of the existing `qualifications:unmapped`.
5. The empty-state says what to do — *"No promotion path is sanctioned for this post. An approved Pre-DPC must list it first."* — rather than presenting an empty dropdown.

**Estimated cost: 1 migration, 2 request classes, 1 controller method, 1 command, ~10 tests.** Small. **It is the approval, not the work, that is the obstacle.**

#### 0.7.3 Why it was not implemented in this session

Three standing rules point the same way, and they are the client's own:

- **M4 is architecture-frozen** (AD-15/AD-16) — *"no new features, workflow changes, schema changes."* This is all three.
- **Permanent rule 2** — *"Never change business workflow unless explicitly approved."* Refusing a post pair that is accepted today changes what a department may submit.
- **Permanent rule 12** — *"If any mismatch exists: do NOT guess. Document it first. Then recommend the correct implementation."* Item 3 asks to *verify* and *recommend*; it does not ask to build.

**Raised as `AD-25` (proposed, awaiting Architect approval) and `BD-16` (awaiting business confirmation).** It needs a **Freeze Exception under AD-22** to proceed. **Recommended for M10** — see §0.11.

### 0.8 Item 4 — Excel upload. Already built; nothing to implement.

**Does the PPT require it?** **Yes, explicitly, at three points**, and the wording is the same each time — *"विवरण प्रविष्ट करें **अथवा** विवरण अपलोड करें"*, entry **or** upload, with an `UPLOAD` button drawn on the slide:

| PPT | Section |
|---|---|
| **S16** | Officers under consideration |
| **S17** | Final seniority list |
| **S18** | Tabulation / evaluation chart |

**Does the SRS require it?** **Yes.** Screen 5, *Seniority List Management*: *"Upload Seniority List — File Upload — **Excel file format**"*. §3.7 additionally requires export to PDF/Excel.

**Does the code support it?** **Yes — all three, and it predates this session (M4 Phase 6).**

- `App\Domain\Import\Enums\ImportTemplate` has exactly three cases, named for the slides they serve: `CurrentlyWorkingUs` (S16), `FinalSeniorityListUs` (S17), `EvaluationChartUs` (S18).
- Staged pipeline — **Preview → Validation → Error Report → Commit**. Nothing is written until the officer confirms.
- Per-row rules come from `DpcRowRules`, **shared with manual entry**, so the two paths cannot drift.
- Headers accept **both** the PPT's own Hindi-context English (`NAME OF US`, `DATE OF POSTING ON US`, `CAT`) and this system's field names, so a file exported from the system re-imports unedited.
- `reservation_category` is matched as a **code**, not an id — a department filling in a spreadsheet has no access to primary keys.
- Upload widget is on all three section screens; `maatwebsite/excel ^3.1` is a first-class dependency.

**Nothing was implemented for item 4.** Building it again would have duplicated a working, tested pipeline.

**One residual gap, for completeness:** SRS §3.7 also asks for **export** to Excel/PDF. Export exists for signed documents (PDF) but there is **no Excel export** of a promotion list or a status report. That belongs to **M11 (Reports)** and is recorded in §0.10.

### 0.9 Item 6 — Role-based UI. Four defects found, four fixed.

**The audit first.** The complaint was that users are offered buttons that return *Unauthorized*. That is **not** true of the codebase as a whole, and saying so precisely matters more than agreeing:

- **M9 (Circulation) is exemplary** — every action is behind `@can`, asking the same Policy the controller enforces.
- **M6/M7 (Commission)** gate on `$isChairman`, `$isAllottee`, `$canAct`, all Policy-derived.
- **M3/M4 detail screens** gate on `$isAO` / `$isAH` **combined with workflow state**, which already satisfies the client's rule 11.

**The defects were at the entry points — the index screens and the dashboard — which nobody had gated because they are "just lists".**

| # | Defect | Consequence before | Fix |
|---|---|---|---|
| **1** | `dpc-proposals/index` — **+ New Proposal** shown to everyone who can see the list. `DpcProposalPolicy::create` is **AO-only** | **An AH clicking it got a 403.** This is precisely the reported bug | `@can('create', DpcProposal::class)` — the same Policy the controller calls |
| **2** | `pre-dpc/index` — **+ New Proposal** shown to the AH, and the **backend allowed it** | Worse than a 403: the AH created a draft they could then neither edit, submit nor cancel — every one of those is `$isAO` on the show screen. **A dead-end record.** | Button gated **and** `PreDpcProposalController::authorizeAuthoring()` added. **PPT S7 is explicit** — the entry is made by the AO and approved by the AH; an AH authoring it would be approving their own work |
| **3** | Dashboard — **Allotments** tile and **"Awaiting allotment"** counter shown to Members. Allotment is the **Chairman's alone** (PPT S28) | A Member was sent to a screen whose only action is hidden from them, and shown a number they can do nothing about | Tile and counter are now Chairman-only. A Member's own queue is *"Allotted to you, not yet accepted"*, which was always there |
| **4** | `cgpsc/proceedings/index` — **Open Workspace** shown whenever a proceeding exists | `DpcProceedingPolicy::view` is Chairman **or** the conductor. **After a re-allotment those differ from the current allottee**, who is who the row is listed for → 403 | `@can('view', $proceeding)`; otherwise the row says *"Conducted by …"*, which is more useful than a button |

**Also fixed, and found only because of this sweep:** `dpc-proposals.reviews.create` — **Review DPC (PPT S35–S37), delivered in M8, had no link anywhere in the UI.** `grep -rn "reviews.create" resources/views/` returned nothing. The route, controller, request, service, view and 83 tests all existed; **the feature was unreachable.** A **+ Review DPC** button now sits beside **+ New Proposal**, gated by the same Policy. This is completing delivered scope, not new scope.

**Tone was corrected alongside visibility.** The AO and AH now read different descriptions of the same tile — *"Prepare and submit"* vs *"Approve or return the proposal the Authorized Officer submitted"* — because the same screen is a different job to each, and a tile that promises an action the reader cannot take is the problem this work exists to remove.

**Backend authorization was not weakened anywhere.** `tests/Feature/RoleBasedUiVisibilityTest.php` (14 tests) asserts **both halves of every case** — the button is absent for the role that cannot use it, **and** the route still refuses that role. Asserting only absence would let a later change quietly drop the backend check and stay green, which is what makes hiding a button security theatre rather than usability.

### 0.10 Deliverable 4 — missing business requirements, consolidated

**Ranked by consequence, not by document order.**

| # | Requirement | Source | Status | Consequence today |
|---|---|---|---|---|
| **1** | **DPC must be created from an Approved Pre-DPC** | Business, 2026-08-18; PPT S14 by construction | ❌ **Gap — §0.7** | A DPC can run on an unsanctioned promotion path |
| **2** | **Self-inspection & objection** (S49–S51) — officer views own data, PRINT, RAISE OBJECTION | PPT S49–S51 | ❌ **M10, not started** | Officers cannot see or contest their own record before the DPC date |
| **3** | Reserved relaxation to 10 marks | Conventional deck C5 | ⛔ **Blocked — BD-2/BD-3** | Affected results marked provisional, cannot be published |
| **4** | Reports & analytics; **Excel/PDF export** of lists and status reports | SRS §3.7, Screen 13 | ❌ **M11, not started** | No management reporting |
| **5** | Audit-trail **UI** | SRS §3.7 | 🟡 Data captured; no browser UI | Auditors cannot self-serve — **M11** |
| **6** | Real CSC eSign (Aadhaar OTP) | PPT S8/S10/S13 etc. | 🟡 Mock provider works | **BD-4** — never mock-sign in production |
| **7** | Live SMS gateway | PPT S5 | 🟡 Mechanism works | **TD-6** — OTP undeliverable, so no officer can verify |
| **8** | HRMS import of service records | SRS §3.2 | 🟡 Provider + flag, mock only | **BD-5** — manual entry and Excel cover it |
| **9** | **Video conferencing** (WebRTC / Teams / Zoom) | SRS §3.5 | ❌ **Not implemented, and not in the PPT** | See below |
| **10** | **Real-time voting** during the meeting | SRS §3.5 | ❌ **Not implemented, and not in the PPT** | See below |
| **11** | 2FA on login | SRS §3.1 | 🟡 OTP exists; not a second factor over a password | Hardening — **M11** |

**On the SRS column generally, and on rows 9 and 10 in particular — a standing-of-the-document question, raised by this session's own brief.**

**AD-1 (standing) rules that the two PPT decks are the only source of business requirements, and that `docs/archive/` — including the SRS — *"is historical reference and must not be implemented from."*** This session was nevertheless asked for an **SRS vs Code gap analysis** as a named deliverable. **Those two positions cannot both be right.** The analysis above was produced **under AD-1**: an SRS-only item is reported as a *finding*, never as a defect. That reading should be confirmed, not assumed — **raised as BD-17.**

Concretely, rows 9 and 10 are where it bites. The SRS describes a **live meeting** — video conferencing, digital note-taking, real-time voting. The PPT describes a **document-and-signature process**: the Member opens a workspace, corrects, calculates, approves and signs, and **S31 states that no change made by the department on the DPC date is valid**, which is the opposite of a live deliberation. The build follows the PPT, correctly under AD-1 — **but it means two named SRS features will never be built under the current design, and nobody has said so out loud.** Rows 4, 5 and 11 are the softer case: SRS-only, but *scheduled* (M11) rather than refused, so they need no ruling.

### 0.11 Deliverable 5 — recommendation before the M10 freeze

**Ordered. Items 1 and 2 should be settled before M10 opens, not during it.**

1. **Rule AD-25 (Pre-DPC → DPC linkage) and confirm BD-16.** M10's officer self-inspection screen shows an officer the record held *for a specific DPC*. If that DPC can exist on an unsanctioned promotion path, M10 renders an authoritative-looking screen about a proposal with no Government Order behind it. **The gap gets more expensive once M10 exists, not less.** Recommended: approve Option B, take it as a **Freeze Exception under AD-22 at the M9→M10 boundary**, batched with the debt below.
2. **Answer BD-17 (video conferencing / real-time voting).** It is the only open question that could change the *shape* of the system rather than a value in it. Cheap to answer now; very expensive to discover during UAT.
3. **Close BD-14** — the single thing between M9 and closure. **Do not open M10 with M9 unclosed**; that is how two milestones end up half-closed at once.
4. **Batch the standing debt into the same freeze exception** — **TD-19, TD-20, TD-21** (all small, all blocked only by AD-15), plus **TD-2** (`composer audit` has now needed a retry at three consecutive finalisations, and a gate that answers from a stale cache is not a gate).
5. **Then open M10 (S49–S51).** Note it inherits **BD-8 (objection handling)** — S51's *RAISE OBJECTION* is its central action and there is no ruling yet on what happens to one. **BD-8 should be answered before M10 Phase 2**, not at its end.
6. **Not before UAT, but before production:** TD-6 (SMS gateway), TD-1 (evidence-table grants), TD-8 (retention capacity plan), BD-4 (real eSign).

### 0.11a Follow-on, same day — the Pre-DPC screen becomes master-sourced

**Requested after the sweep above:** on `/pre-dpc/create`, Minimum Qualification must be a dropdown from the master, Post Name and Feeder Post must come from the Cadre master, and every feeder field must carry a label. **Built.** Detail in `docs/worklog/2026-08-18-pre-dpc-master-sourced-fields.md`.

**It reverses a position taken the previous day, deliberately.** The 2026-08-17 round made the Recruitment Rule master referential and left the Pre-DPC as text, resolving it at projection — *"the Pre-DPC screens are unchanged… resolution happens at the moment of projection, which is where the integrity requirement applies."* Business has now said where it applies. **Resolving text afterwards is a guess about what somebody meant; capturing a reference is a record of what they chose.** The guess mapped 11 of 15 existing rows; the four it missed are the argument — `12rth`, `10tth`, hand-typed and unmappable. **Traceable to** explicit business instruction, corroborated by **SRS Screen 4** (*Promotion Post — Dropdown*, *Feeder Post — Dropdown*) and **permanent rule 7**.

**Two of the four fields needed no migration.** `pre_dpc_posts.cadre_id` and `pre_dpc_post_feeders.feeder_cadre_id` have existed since M3, and `feeder_cadre_id` was already validated — the form never offered them. Only `qualification_id` is new: additive, nullable, backfilled by the same normalised match the recruitment-rule backfill uses, `min_qualification` kept as the legacy record.

**Backward compatibility was the substantive engineering.** `post_name` / `feeder_name` stay `required` and stored; the request derives them from the chosen cadres, so the screen posts references only while a text-only caller — the demo seeder, `PreDpcWorkflowTest`, any existing API client — is untouched. `PreDpcWorkflowTest` passes **unmodified**; `demo:seed --force` rebuilds with all seven DPC IDs unchanged. `amend()` carries the references forward; without that an amendment would quietly downgrade a referenced proposal to a text one **and look correct**, because the names travel with it.

**Three new set-level rules — not tidiness.** No self-feeder, no duplicate feeder per post, no duplicate post per proposal. `recruitment_rule_feeders` carries a unique index on (rule, feeder cadre), so without them a department could approve a Pre-DPC whose projection **fails on a database constraint inside the approval transaction, after the proposal was digitally signed.** Same argument as the DR + PR rule in §0.4.

**⚠ The consequence, raised as BD-18.** Department registration creates no cadres, and **the Pre-DPC was itself what created them** — `projectToMasters()` upserted a cadre per post name on approval. A reference cannot be chosen from an empty list, so **a newly registered department can no longer file a Pre-DPC** until a CGPSC Admin populates its master. The screen states this and disables the form rather than appearing broken; existing departments are unaffected. **Whether first-time cadre registration is now the Commission's job is a business decision**, and it is a workflow change that follows necessarily from what was asked for — so it is implemented and flagged, not blocked.

**798 tests** (2404 assertions), 16 new, none modified · Pint · PHPStan 0 · audit clean · all five freezes empty · **driven and saved in a real browser**, storing `cadre_id`, `feeder_cadre_id` and `qualification_id` with `min_qualification` null.

### 0.12 What is still unresolved, stated plainly

- **AD-25 is proposed, not decided.** Nothing in §0.7 has been built.
- **BD-16, BD-17 and BD-18 are new and unanswered.** BD-17 in particular has never been put to anyone. **BD-18 has a live operational effect today** — onboarding a new department now needs a CGPSC Admin to register its cadres first, and that belongs in the onboarding runbook whichever way it is answered.
- **BD-2, BD-3, BD-8, BD-9, BD-10, BD-11, BD-14, BD-15** remain as previously recorded. This session changed none of them.
- **Three technical findings from these two sessions are now filed as debt**, per the Milestone Execution Rule of 2026-08-18 — they are recorded, not actioned: **TD-25** (a Pre-DPC feeder whose name matches no cadre is *silently dropped* at projection — pre-existing, found by a failing test), **TD-26** (Pre-DPC authorization is inline rather than a Policy, so views re-derive the rule the controller enforces), **TD-27** (`MILESTONE_SUMMARY.md` updates only at closure, so a milestone stalled on a business decision never gets a status — M9 read "Not started" three phases after the fact).
- **The `Junior Assistant` DR 0 / PR 1 development row** is left as it is. It is dev data, it is now visible, and rewriting it would have destroyed the only evidence that the write-path-only decision behaves as intended.

### 0.13 M9 closure checklist

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

### 0.14 For the Architect — copy-paste

**In priority order. The first item is the only one that unblocks a milestone; the rest are backlog and none of them should be picked up before M9 closes.**

> **Get BD-14 answered.** It is the sole outstanding item on M9's closure checklist (§0.13) — items 1 to 19 are green, and 20 to 23 are all consequences of this one question. It is drafted for CGPSC as **Question 6** of `docs/UAT_BUSINESS_VALIDATION.md`. **The answer costs no engineering:** it lands as `dpc.circulation.effective_date_basis`, no code change, no freeze exception, no re-test. Until it lands, signed and verifiable circulation documents cannot reach the departments they were produced for.

> **Then close M9:** confirm §0.13, tag `v0.9.0-m9-complete`, and declare the M9 freeze as an AD in the shape of AD-21. **M9 is currently the only built milestone with no freeze baseline**, so "has M9 changed?" is the one such question that cannot be answered by diff.

> **Then, and only then, open the freeze window** under AD-22 for the accumulated backlog — TD-19, TD-20, TD-21, plus **TD-28** (the absent-allottee deadlock, which needs a standing rule across M7/M8/M9 rather than a fix inside any one of them) and **AD-25** if BD-16 has been confirmed.

---

*Backlog, recorded and not to be actioned before the above:*

> Rule **AD-25**: bind a DPC proposal to the **active Recruitment Rule** that sanctions its post pair (Option B, §0.7.2) — validation, filtered dropdowns, and a nullable `recruitment_rule_id` pinned at creation. It requires a **Freeze Exception under AD-22** against M4. **Recommended before M10 opens**, because M10's self-inspection screen inherits the defect.

> Confirm **BD-16** with business: is *"Approved Pre-DPC should be used while creating DPC"* satisfied by binding to the Recruitment Rule the Pre-DPC produced (recommended), or does business require the DPC to name the Pre-DPC document itself?

> Answer **BD-17**, which is really two questions and the first governs the second. **(a) Does AD-1 still stand?** It says the SRS *"must not be implemented from"*, yet an SRS-vs-code gap analysis was commissioned on 2026-08-18; if the SRS has been promoted to a requirements source, **AD-1 must be superseded by a new AD** and the roadmap re-scoped. **(b) If AD-1 stands, confirm that video conferencing and real-time voting are withdrawn** — the PPT specifies a document-and-signature process and the build follows it.

**M9 is still not closed, and no engineering work remains in it.** Its closure documentation was completed on 2026-08-18 — the five mandatory documents now agree with each other and with the code, and §0.13 records the checklist M9 never had. **Closure itself remains pending BD-14.** No milestone was opened.

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
