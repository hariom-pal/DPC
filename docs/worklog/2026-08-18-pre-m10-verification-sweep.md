# Pre-M10 Verification Sweep

**Date:** 2026-08-18
**Scope:** A six-item client brief. **Four items needed no code.** One UI gap was
closed, four role-visibility defects were fixed, and the largest finding was
documented rather than built. No milestone was opened or closed; no business
rule, calculation, workflow or DPC processing was changed.

---

## Audit first — what was asked for that already existed

The brief reads as six new requirements. It is worth stating plainly how much of
it was already delivered, because the honest answer changes what the session was
for:

| # | Item | State found | Action |
|---|---|---|---|
| **1 A–E** | Target Cadre from master; Feeder **Cadre** searchable/FK/multiple; Promotion Share (%) + 100% total; Minimum Experience + Years/Months; Qualification Master | **Built 2026-08-17** | none |
| **1 F** | DR % / PR % width, labels, **DR + PR = 100** | labels yes, **validation no**, fields too narrow | **built** |
| **2** | Every PPT screen and business rule | 51 of 52 slides done; **S49–S51 is M10** | documented |
| **3** | DPC references Approved Pre-DPC | **it does not** | documented + recommended |
| **4** | Excel upload at DPC creation | **built M4 Phase 6**, all three templates | none |
| **6** | Role-based UI visibility | mostly right; **four defects** | **fixed** |

Items 1 A–E were **re-verified against the running application**, not taken from
the previous day's worklog. A worklog is a claim; the screen is the evidence.

---

## 1. What was built

### 1.1 DR % + PR % must total exactly 100%

Enforced server-side in **two** places:

| Where | Why there |
|---|---|
| `RecruitmentRuleRequest::validateRecruitmentSplit()` | the screen the client named |
| `SavePreDpcProposalRequest::withValidator()` | **because approving a Pre-DPC projects its posts into that same master** |

The second is the one that matters. Validating only at the master would let a
department approve a Pre-DPC that silently writes a rule the admin screen then
refuses to re-save — the exact drift that `RecruitmentRuleService::normaliseShares()`
had to be introduced to prevent for the feeder Promotion Share column. One rule
enforced at both ends is the only version of this that cannot rot.

**Traceability.** The PPT states the rule by worked example rather than in words:
every row of **S9** and **S11** reads DR 0 / PR 100, and the seeded Schedule-IV
ladder runs 100/0, 50/50, 25/75 and 0/100. **No worked example anywhere in either
deck leaves a remainder.** SRS Screen 4 lists *Promotional Percentage* with no
counterpart and states no total. So: **PPT by example + explicit business
instruction of 2026-08-18.**

**Write-path only, deliberately.** Existing rows are never rewritten. One
development row — `Junior Assistant`, DR 0 / PR 1, left over from last session's
browser driving — does not satisfy the rule. It still reads, lists and opens; it
is refused only **on save**. Silently rescaling a Government Order's own
percentages would be inventing policy, the same reason the Promotion Share
migration refused to invent a total for an all-zero set.
`test_an_existing_unbalanced_rule_is_still_readable` pins both halves: unchanged
on disk, refused on update.

**The error has its own key**, `recruitment_split`, rather than being attached to
either field. Neither number is wrong on its own — it is the pair that does not
add up, and blaming one of them tells the user to change the field they may not
have meant to. The message names the actual total and the shortfall:
*"They currently total 80% — 20% of the posts is unaccounted for."*

**Tolerance is one hundredth of a percent**, identical to the Promotion Share
rule, so the screen's two "must equal 100%" rules cannot disagree about what
*exactly* means. `33.33 + 66.67` passes.

### 1.2 Width and labels

- `Direct Recruitment (%)` → **`Direct Recruitment — DR (%)`**; `Promotion (%)` →
  **`Promotion — PR (%)`**; both gained the required-marker they lacked and a `%`
  input-group affix.
- Widened from `col-md-3` to `col-md-4 col-lg-3` and moved onto **their own row**,
  with a live **DR + PR** total beside them shown exactly like the feeder
  Promotion Share total — the number, then ✓ or ✗. They are the only two fields
  on the screen that constrain each other; showing them together is what makes
  the constraint legible.

Screenshots of both states — `after-form-split-balanced.png` (100% ✓) and
`after-form-split-unbalanced.png` (80% ✗) — are in
[`docs/design/recruitment-rule/`](../design/recruitment-rule/README.md).

### 1.3 The same fields on the Pre-DPC screen

`resources/views/pre-dpc/form.blade.php` carried DR % and PR % as **`col-md-1`
boxes too narrow to display `100.00`**, labelled only by abbreviation. Since the
new validation fires on that screen, a user has to be able to read the numbers it
is complaining about. Widened to `col-md-3`, full labels, `%` affix, per-card live
total. **No field name changed, so no API changed.**

---

## 2. Role-based UI — four defects

**The audit contradicts the premise, and that is worth saying.** The complaint was
that users are offered buttons returning *Unauthorized*. That is not true of the
codebase as a whole:

- **M9 (Circulation) is exemplary** — every action behind `@can`, asking the same
  Policy the controller enforces.
- **M6/M7 (Commission)** gate on `$isChairman`, `$isAllottee`, `$canAct`, all
  Policy-derived.
- **M3/M4 detail screens** gate on `$isAO`/`$isAH` **combined with workflow state**.

**The defects were at the entry points — index screens and the dashboard — which
nobody had gated because they are "just lists".**

| # | Defect | Consequence | Fix |
|---|---|---|---|
| 1 | `dpc-proposals/index` — *+ New Proposal* shown to the AH; `create` is AO-only | **403 on click.** The reported bug, exactly | `@can('create', DpcProposal::class)` |
| 2 | `pre-dpc/index` — same button, **and the backend allowed it** | Worse than a 403: a draft the AH could then neither edit, submit nor cancel — all three are `$isAO`. **A dead-end record** | button gated **and** `authorizeAuthoring()` added |
| 3 | Dashboard — *Allotments* tile and *Awaiting allotment* counter shown to Members | A Member sent to a screen whose one action is hidden, and shown a number they cannot act on | both Chairman-only |
| 4 | `cgpsc/proceedings/index` — *Open Workspace* shown whenever a proceeding exists | `view` is Chairman **or** the conductor; **after a re-allotment those differ from the current allottee**, who is who the row is listed for → 403 | `@can('view', …)`, else *"Conducted by …"* |

**Defect 2 needed a backend change, not just a hidden button.** PPT S7 is explicit
— *"यह प्रविष्ठी डी.पी.सी. हेतु अधिकृत अधिकारी द्वारा की जाएगी एवं प्रशासकीय प्रमुख
द्वारा प्रविष्ठी का अनुमोदन किया जाएगा"* — the entry is made by the Authorized
Officer and approved by the Administrative Head. An AH who authored the proposal
would be approving their own work.

### The one that was not a permission problem at all

**Review DPC (PPT S35–S37) had no UI entry point.** `dpc-proposals.reviews.create`
has a route, controller, form request, service, view and 83 tests, all shipped in
M8 — and `grep -rn "reviews.create" resources/views/` returned **nothing**. The
feature was unreachable from any screen in the application. A **+ Review DPC**
button now sits beside **+ New Proposal**, behind the same Policy.

This is completing delivered scope, not adding scope. It is also the reason a
visibility sweep was worth doing at all: the same pass that finds buttons which
should not exist finds features which have none.

### Tone, not only visibility

The AO and AH now read **different descriptions of the same tile** — *"Prepare and
submit"* versus *"Approve or return the proposal the Authorized Officer
submitted"* — because the same screen is a different job to each, and a tile that
promises an action the reader cannot take is the problem this work exists to
remove.

### No backend check was weakened

`tests/Feature/RoleBasedUiVisibilityTest.php` — 14 tests — asserts **both halves of
every case**: the control is absent for the role that cannot use it, **and** the
route still refuses that role. Asserting only the absence would let a later change
quietly drop the backend check and stay green, which is what makes hiding a button
security theatre rather than usability.

---

## 3. What was found and deliberately not built

**`dpc_proposals` references neither a Pre-DPC nor a Recruitment Rule.**

- No `pre_dpc_proposal_id` column; `grep -rn "pre_dpc\|PreDpc" app/Domain/Dpc/
  app/Http/Controllers/Dpc/` returns nothing.
- `DpcProposalController::formData()` offers **every active cadre in the department**.
- `SaveDpcProposalRequest` checks existence, `active` and department — **never that
  a Recruitment Rule links `post_from` to `post_to`**.

So a department can create, complete, sign and forward a DPC for a promotion path
**no approved Pre-DPC ever sanctioned**. Two details make it worse: the Cadre
master is **cumulative and never pruned**, so a post dropped in a later Pre-DPC
version stays selectable forever; and a department with **no approved Pre-DPC at
all** can still create a DPC if cadres exist from any other source.

**Recommendation — bind the DPC to the active Recruitment Rule, not to the Pre-DPC
document.** Approving a Pre-DPC projects it into the masters (architect ruling Q1),
so an approved Pre-DPC *is* the Recruitment Rule; binding to the rule binds to the
sanction **and to the version in force on the proposal date**, which a direct FK
does not. Full option comparison in `docs/ARCHITECT_REVIEW.md` §0.7.2, recorded as
**AD-25 (proposed)** and **BD-16**.

**Why it was not implemented.** It is a schema and workflow change to **M4, which
is architecture-frozen**, and permanent rule 2 forbids changing a business workflow
without explicit approval. Item 3 of the brief asks to *verify* and *recommend*; it
does not ask to build. It needs a **Freeze Exception under AD-22**, and is
**recommended before M10 opens** — M10's self-inspection screen shows an officer
the record held for a specific DPC, so the gap gets more expensive once M10 exists.

---

## 4. Excel upload — verified, nothing built

**The PPT requires it at three points**, with the same wording each time —
*"विवरण प्रविष्ट करें **अथवा** विवरण अपलोड करें"*, entry **or** upload, with an
`UPLOAD` button drawn on the slide: **S16** (officers under consideration),
**S17** (final seniority list), **S18** (tabulation chart). The SRS requires it at
Screen 5: *"Upload Seniority List — File Upload — Excel file format"*.

**All three have existed since M4 Phase 6.** `ImportTemplate` has exactly three
cases, named for the slides they serve; the pipeline is staged **Preview →
Validation → Error Report → Commit**; per-row rules come from `DpcRowRules`
**shared with manual entry**, so the two paths cannot drift; headers accept both
the PPT's own Hindi-context English and this system's field names.

Nothing was implemented. Building it again would have duplicated a working,
tested pipeline.

---

## 5. Documentation updated

| Document | What changed |
|---|---|
| `docs/ARCHITECT_REVIEW.md` | §0 replaced in full — the six-item verification, both comparison tables, the Pre-DPC→DPC finding and recommendation, and the pre-M10 ordering |
| `docs/ARCHITECTURE_DECISIONS.md` | **AD-25 added, status PROPOSED** — recorded here rather than only in the review because that file is replaced every phase, and a recommendation needing a freeze exception must outlive the session that made it |
| `docs/PENDING_BUSINESS_DECISIONS.md` | **BD-16** and **BD-17** added, both P1 |
| `docs/WORKFLOWS.md` | Role matrix gained the two **`create`** rows — its blind spot, because creation is not a transition and so sat outside the table and outside the checks that reference it |
| `docs/CHANGELOG.md` | Session entry |
| `docs/PROJECT_STATUS.md` | Current Work; last-updated date |
| `docs/design/recruitment-rule/README.md` | Two new screenshots |

---

## Verification

- **782 tests pass** (2351 assertions), up from 759 — **23 new, none modified.**
- **Pint pass · PHPStan (Larastan L6) 0 errors · `composer audit` no advisories.**
- **All five freezes verified empty** — `git diff v0.6.0-m6-complete -- app/Domain/{Dpc,Eligibility,Cgpsc}`,
  `v0.7.0-m7-complete -- app/Domain/Proceeding`, `v0.8.0-m8-phase3 -- app/Domain/Review`.
- **Driven over HTTP against the running application**, not asserted only in
  tests: posting DR 30 / PR 50 returns 302, renders *"…currently total 80% — 20%
  of the posts is unaccounted for"*, and **writes no row**.
- **Nine role-visibility cases checked over HTTP** as the real AO, AH, Chairman and
  Member demo accounts — all nine as expected.
- **Photographed in a real browser**: both DR + PR states render as intended,
  green ✓ at 100% and red ✗ with the shortfall named otherwise.

## What is still open

**AD-25 is proposed, not decided — nothing in §3 has been built.** BD-16 and BD-17
are new and unanswered; BD-17 in particular has never been put to anyone, and it
asks what standing the SRS now has, since **AD-1 rules that it must not be
implemented from** while this brief commissioned an SRS-vs-code gap analysis. The
previously recorded BD-2/3/8/9/10/11/14/15 are unchanged by this session.
