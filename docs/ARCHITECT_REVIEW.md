# Architect Review — M8 Phase 3

**Date:** 2026-08-14
**Subject:** M8 Phase 3 — supersession takes legal effect, Review documents, SUPERSEDED watermark, publication
**Design:** `docs/design/09-m8-review-dpc.md` (approved)
**Baseline:** `v0.7.0-m7-complete` / `6f52f71`

Phase 3 only. **M9 has not begun.**

---

<!-- CURRENT SESSION — START. This section is REPLACED in full on every prompt. Never appended. Everything below §0 is historical architect review and must be preserved verbatim. -->

## 0. Current Session

**Updated:** 2026-08-16 · **M9 Phase 1 finalisation — full gate run, committed, pushed** · Section replaced in full, not appended.

### 0.1 Prompt received

> M9 Phase 1 Finalization. Run the complete quality gate: Pint, PHPStan (Larastan level 6), the **complete** PHPUnit suite (not only Circulation tests), `composer audit`, `php artisan migrate:fresh --seed`. Fix only issues that are discovered. Do not implement Phase 2. Do not modify architecture. After all gates pass, verify the freeze against M4–M8, update `ARCHITECT_REVIEW.md` with the **actual verified results**, and commit everything outstanding in one commit — `feat(m9): Circulation DPC Phase 1` — then push to origin. Stop and wait for Architect review. Update all documents per the project rule.

### 0.2 Quality gate — actual verified results

**All five gates run in the directed order. All five pass. Nothing was fixed, because nothing failed.**

| # | Gate | Result |
|---|---|---|
| **1** | Laravel Pint | ✅ **Pass** — PSR-12 + strict types, no violations |
| **2** | PHPStan / Larastan level 6 | ✅ **0 errors** |
| **3** | **Complete** PHPUnit suite | ✅ **666 passed / 2020 assertions, 0 failing** — exit code 0, 275s |
| **4** | `composer audit` | ⚠️→✅ **No advisories** — see §0.3 |
| **5** | `php artisan migrate:fresh --seed` | ✅ **OK** — 44 migrations, **64 tables**, 10 seeders |

**666 = 620 + 46.** The full suite gained exactly M9 Phase 1's 46 tests and lost none, which is the number that matters: **Phase 1 changed no existing test's outcome.** Warning 1 of the previous review — that the 620 figure predated this phase and was inherited rather than verified — is now closed by measurement.

### 0.3 One gate needed a second attempt, and it is recorded rather than smoothed over

**`composer audit` failed to reach the network on the first attempt** and fell back to local cache:

```
curl error 28 while downloading https://repo.packagist.org/packages.json:
Resolving timed out after 10000 milliseconds
package information was loaded from the local cache and may be out of date
```

It still printed *"No security vulnerability advisories found"* — **from a cache that may be stale.** That is exactly **TD-2**, already on the register.

**The second attempt reached the network and returned clean.** Recorded as *"clean on the second attempt"* rather than *"clean"*, because a cached advisory database answering a security question is the kind of detail that reads as a pass and is not one. This project has withdrawn three figures for being asserted rather than re-derived; this is the same discipline applied before the fact instead of after.

### 0.4 Freeze verification — all five milestones

**Every diff empty.** Run after the gate, on the finalised tree.

| Milestone | Command | Result |
|---|---|---|
| **M4 / M5 / M6** | `git diff --name-only v0.6.0-m6-complete -- app/Domain/{Dpc,Eligibility,Cgpsc}` | **0 files** |
| **M7** | `git diff --name-only v0.7.0-m7-complete -- app/Domain/Proceeding` | **0 files** |
| **M8** | `git diff --name-only v0.8.0-m8-phase3 -- app/Domain/Review` **+ all four AD-21 view paths** | **0 files** |
| **M4–M8 schema** | `git diff --name-only v0.8.0-m8-phase3 -- database/migrations` | **0 files** |

**The M8 check covers `resources/views/dpc/reviews/`, `documents/review-context.blade.php`, `documents/superseded-watermark.blade.php` and `partials/review-banner.blade.php`**, because AD-21 requires it: M8's UI deliberately lives outside M4's frozen screens, and a diff scoped to `app/` alone would report clean while the views had changed.

**AD-21 rule 4 — *M9 development must not modify the M8 implementation* — held with nothing to enforce it but the design.** M9 owns its aggregate and decorates nothing, so the temptation the rule anticipated never arose.

### 0.5 What is in the commit

`feat(m9): Circulation DPC Phase 1` — one commit, everything outstanding, five prompts' worth.

| | |
|---|---|
| **M9 Phase 1** | 1 migration, 2 models, 1 enum, 1 value object, 1 exception, 3 services, 1 policy, 1 form request, 1 controller, 4 views, 4 test files |
| **Approved design** | `docs/design/10-m9-circulation-dpc.md` |
| **Architecture decisions** | **AD-23** (Circulation is Decision Resolution, not DPC Execution) and **AD-24** (Circulation is its own aggregate) |
| **Wiring** | `dpc_circulation` workflow in configuration, routes, policy registration — all additive |
| **Documentation** | `ARCHITECT_REVIEW.md`, `CHANGELOG.md`, `PROJECT_STATUS.md`, `PROJECT_PROGRESS.md`, `PENDING_BUSINESS_DECISIONS.md` (BD-12 … BD-15) |

**No source file inside any frozen milestone appears in the commit.**

### 0.6 Phase 1 delivered — the substance

**A Circulation is its own aggregate (AD-24) and performs no DPC execution (AD-23), and both are now enforced by test rather than by intention.**

- The department raises a note against a concluded DPC whose promotions are still held, records **what was supplied and when** against each hold, submits, and the Administrative Head approves and signs it (AQ-23). It earns **`HE202600001/C1`** — deliberately not a DPC ID, and not drawn from `dpc_id_sequences`, which would have consumed DPC serials for something that is not a DPC.
- **Nothing releases a hold.** `dpc_proceeding_holds.released_at` is M7's declared seam and Phase 2 writes it at signature (AQ-17). Three tests pin it, including that the department's own approval does not.
- **A superseded DPC is refused** (AQ-18), naming the operative DPC in the chain.
- **The origin is provably untouched** — proposal, chart, calculation run, results and rows byte-identical after a full circulation, asserted field by field.

### 0.7 BD-15 — detected, refused, recorded, and nothing decided

The instruction was precise and each clause has its own test: *detect the condition; return a structured "Pending Business Decision (BD-15)" response; record an audit event; do not calculate, re-score or escalate automatically.*

**Detection reads `reason_code` and scores nothing** — it is admissibility, not outcome, which is the only reason it can exist at all: deciding whether the mark *would* change is a scoring operation AD-23 forbids. The refusal carries the affected officers, the question, and **what the system deliberately did not do**; screens get a panel, API callers a `409` with the same shape. It is **not** a `WorkflowException` — one says the caller may not, this says the system cannot yet decide — and the timeline event is written **even though nothing else is**, because a case blocked on CGPSC must be distinguishable from one nobody raised.

**BD-15 blocks nothing in Phase 1.** It is answered by refusal, not by waiting.

### 0.8 Two findings from the implementation worth keeping

**Writing AD-23's check found a flaw in the obvious version of it.** A `str_contains` over raw source flags `CirculationService`'s own docblock, which names all ten forbidden classes in prose precisely so the next reader knows why they are absent. **That check would train people to delete the explanation in order to go green.** It now strips comments with `token_get_all` and reads what the parser sees, with a companion test that fails if stripping ever breaks — so a future reader is told *that*, rather than being told the rule is violated. It tests AD-23's full list, including the two routes a name-based check misses: **`ProceedingCalculationService`** (M7's own entry point into the engine) and **`ReviewAwareCalculationService`** (M8's container-bound decorator, which a type-hint on the M7 class resolves to).

**PHPStan caught a defect the migration had already hidden.** `cascadeOnDelete()`, `nullOnDelete()` and `restrictOnDelete()` take **no** constraint-name argument; one was passed to all five. **PHP ignores extra arguments silently**, so the migration ran, reported DONE, and produced auto-generated names while the source appeared to set explicit ones — and the docblock claimed they were explicit. Corrected to `constrained(table, column, indexName)`, rolled back, re-run. **46 green tests would have shipped it**; the gate that caught it was PHPStan.

### 0.9 Two deviations from the approved design, both narrowing, both flagged

1. **`dpc_circulation_documents` was not created.** §11 lists three Phase 1 tables; the third is used only by Phase 3, which renders and signs the note. The standing rule since M6 is that a phase declares only what it implements.
2. **`CirculationStatus` declares five states, not seven.** `consented` and `signed` arrive with the transitions that perform them. The columns that will hold their data *are* created — a nullable column asserts no behaviour.

**Both are additive to reverse.** §11 of the design still lists three tables; narrowing it is the architect's call, not mine, and it has not been edited beyond the phase-status row.

### 0.10 Prohibitions honoured

**Phase 2 was not implemented** — nothing consents, signs, releases a hold or produces a document. **No architecture was modified** — AD-21, AD-23 and AD-24 stand as ruled; nothing was added to `ARCHITECTURE_DECISIONS.md` this prompt. **Only issues discovered by the gate were fixed, and the gate discovered none** — the two defects in §0.8 were found and fixed during Phase 1 implementation, before this prompt.

### 0.11 Current git status

| | |
|---|---|
| **Commit** | `feat(m9): Circulation DPC Phase 1` |
| **Branch** | `feature/m0-setup` → pushed to `origin/feature/m0-setup` |
| **Tags** | `v0.6.0-m6-complete`, `v0.7.0-m7-complete`, `v0.8.0-m8-phase3` — **unmoved**. **No M9 tag**: Phase 1 is not a milestone boundary |
| **Working tree** | Clean, apart from this file recording its own commit |
| **Frozen milestones** | **Untouched** — all four diffs empty (§0.4) |

**The five-prompt backlog is cleared.** The approved design, AD-23, AD-24 and Phase 1 now exist in the repository rather than in one working tree.

### 0.12 Pending business decisions

**Four are now in the register with full statements.** None was resolved this prompt; none was invented.

| # | Question | Severity | Status |
|---|---|---|---|
| **BD-15** | May a **CR-ground** hold be routed to a Review instead of a Circulation? | **P1** | **Live.** CR-ground circulations are **refused** today with a structured response. Officers held on that ground have **no route to resolution** until it is answered |
| **BD-14** | From what date does a **circulated promotion** take effect? | **P1** | Blocks **Phase 3**. Retrospective seniority reaches every officer below |
| **BD-12** | What if the supplied record is **adverse**? | P1 | Blocks **Phase 2's** decision set |
| **BD-13** | Time limit on a held promotion? | P2 | Blocks nothing; AQ-24 makes the age visible |
| **BD-11** | Supersession and an officer already promoted | P1 | Deferred — required before production activation of the Review supersession policy |
| **BD-2 / BD-3 / BD-9 / BD-10** | Reserved relaxation; waiting-list placement; court case | P1/P2 | Open. Inherited through the origin's result |

### 0.13 Technical debt

| # | Status after this phase |
|---|---|
| **TD-2** | **Fired today** (§0.3). `composer audit` timed out to local cache on the first attempt. Recorded, not smoothed over |
| **TD-24** | **Still open**, and implicated twice now. M9 added 2 tables and 11 foreign keys. **Close it before Phase 2** |
| **TD-20** | **Untouched — because Phase 1 sends no notifications.** The listener work lands in Phase 2, which is when the fourth copy appears. **Extract the resolver before then** |
| **TD-19** | **Avoided outright**, as AD-24 predicted — M9 creates no proposal row |
| **TD-23** | **Not repeated.** Phase 1's tests drive the real path: a genuinely concluded DPC with genuinely detected holds, built through M6, M7 and `HoldService` |
| **TD-21 / TD-22** | Untouched by construction — M9 never invokes the engine |

### 0.14 Warnings

1. **P1 — TD-24 is unclosed and M9 just added 11 foreign keys.** It has been implicated in one shipped P0 and one near miss. It needs no Freeze Exception. **Close it before Phase 2 writes any more schema.**
2. **P1 — BD-15 is holding real cases.** Officers held on a CR ground have no route to resolution today. The refusal is correct and it is not free.
3. **P2 — TD-20 becomes a fourth copy the moment Phase 2 adds notifications.** Cheaper to extract before than after.
4. **P2 — nine architect questions remain unruled** (AQ-16 … AQ-24). Five of them — AQ-16, AQ-17, AQ-20, AQ-21, AQ-22 — shape Phase 2 and should be ruled together.
5. **P2 — the design's §11 still lists three Phase 1 tables** (§0.9). Narrowing it is the architect's call.
6. **P2 — BD-9 still fails silently**, and M9 inherits the origin's result wholesale.

### 0.15 Exact next recommended prompt

Copy-paste, verbatim:

> Close **TD-24**: run the test suite against MySQL rather than in-memory SQLite. No Freeze Exception is required — it is a CI and configuration change touching no production code inside a frozen boundary. Then extract the notification recipient resolver (**TD-20**) before M9 Phase 2 makes it a fourth copy; that one is a **window Freeze Exception against M6** under AD-22, and it would be the mechanism's first live use on deliberately low-risk work.

Then, before Phase 2:

> Rule on **AQ-16**, **AQ-17**, **AQ-20**, **AQ-21** and **AQ-22** — the five that shape M9 Phase 2 — recording each ruling with its reason.

> Put **BD-15**, **BD-14**, **BD-12** and **BD-13** to CGPSC through `docs/UAT_BUSINESS_VALIDATION.md`, in plain language with a worked example each. **BD-15 first** — it is the only one currently blocking real officers.

**M9 Phase 2 has not begun and does not begin without approval.** Phase 1 stops at the department's approval.

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
