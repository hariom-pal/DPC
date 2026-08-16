# Architect Review — M8 Phase 3

**Date:** 2026-08-14
**Subject:** M8 Phase 3 — supersession takes legal effect, Review documents, SUPERSEDED watermark, publication
**Design:** `docs/design/09-m8-review-dpc.md` (approved)
**Baseline:** `v0.7.0-m7-complete` / `6f52f71`

Phase 3 only. **M9 has not begun.**

---

<!-- CURRENT SESSION — START. This section is REPLACED in full on every prompt. Never appended. Everything below §0 is historical architect review and must be preserved verbatim. -->

## 0. Current Session

**Updated:** 2026-08-16 · **M9 Phase 3 finalisation — full gate, decisions verified, committed, pushed** · Section replaced in full, not appended.

### 0.1 Prompt received

> M9 Phase 3 finalisation. Run the complete quality gate: Pint, PHPStan (Larastan level 6), the complete PHPUnit suite, `composer audit`, `php artisan migrate:fresh --seed`. Verify AD-21, AD-23, AD-24, AQ-22. Verify freeze against M4, M5, M6, M7, M8. Update `ARCHITECT_REVIEW.md`, `CHANGELOG.md`, `PROJECT_STATUS.md`, `PROJECT_PROGRESS.md`. Create one commit — `feat(m9): Circulation DPC Phase 3` — push to origin. **Do NOT close M9.** Stop for Architect review.

### 0.2 Quality gate — actual verified results

**All five run in the directed order. All five pass. Nothing needed fixing.**

| # | Gate | Result |
|---|---|---|
| **1** | Laravel Pint | ✅ **Pass** |
| **2** | PHPStan / Larastan level 6 | ✅ **0 errors** |
| **3** | **Complete** PHPUnit suite | ✅ **718 passed / 2178 assertions, 0 failing** — exit 0, 135s |
| **4** | `composer audit` | ⚠️→✅ **No advisories** — second attempt; see §0.3 |
| **5** | `php artisan migrate:fresh --seed` | ✅ **OK** — **45 migrations, 65 tables**, 10 seeders |

**718 = 692 + 26.** The suite gained exactly Phase 3's 26 tests and lost none. **That closes the specific risk flagged before finalisation**: Phase 3 modified a shared Blade partial (`_pending-business-decision`), which is the kind of change that reaches other tests. It did not.

Schema **+1 migration, +1 table** — `dpc_circulation_documents`, as designed.

### 0.3 The audit gate has now needed a retry at three consecutive finalisations

**This time the symptom differed**, so it is recorded precisely rather than filed under the same heading:

- **M9 Phase 1 finalisation** — network timeout; fell back to a possibly-stale local cache and still printed *"no advisories"*. **TD-2.**
- **M9 Phase 2 finalisation** — the same.
- **M9 Phase 3 finalisation (this one)** — the first invocation returned **composer's usage output** rather than a result. The second returned clean, exit 0, with no cache warning.

**Three for three.** Whether all three share a cause is not something I can assert, and I am not going to file the third under TD-2 to make the pattern tidier. What is assertable: **the gate whose entire purpose is to be current has needed a retry every time it has been run at a milestone boundary**, and a gate that sometimes answers from a stale cache and sometimes does not answer at all is not yet a gate anyone should rely on.

### 0.4 The four decisions, verified rather than assumed

| Decision | How it was checked | Result |
|---|---|---|
| **AD-21** — M8 freeze | `git diff --name-only v0.8.0-m8-phase3 -- app/Domain/Review` **plus all four view paths** | ✅ **0 files** |
| **AD-23** — no DPC execution | **22** Circulation source files across `app/Domain/Circulation`, `app/Http/Controllers/Dpc` and `app/Http/Controllers/Cgpsc`, **comments stripped via `token_get_all`**, scanned for all ten forbidden classes | ✅ **No violation** |
| **AD-24** — own aggregate | No model extends `DpcProposal`; own tables, own workflow, own document layer | ✅ **Holds** |
| **AQ-22** — M9's own document layer | `git diff` on `DocumentType.php`, `ProceedingDocumentService.php`, M7's `Documents/` directory, and M7's `proceedings`/`promotion-list` views | ✅ **Byte-identical** |

**AD-23 is also verified behaviourally**, which is the stronger check: generating documents creates **no calculation run** and leaves the pinned run byte-identical. A scan proves nothing is *named*; only that test proves nothing was *done*.

**AQ-22 is verified three ways** — by diff, by a test asserting M7's enum still holds exactly `proceedings` and `promotion_list`, and by a test asserting M9's renderer is **not** an instance of M7's contract.

### 0.5 Freeze verified per milestone

| Milestone | Baseline | Result |
|---|---|---|
| **M4** — `app/Domain/Dpc` | `v0.6.0-m6-complete` | **0 files** |
| **M5** — `app/Domain/Eligibility` | `v0.6.0-m6-complete` | **0 files** |
| **M6** — `app/Domain/Cgpsc` | `v0.6.0-m6-complete` | **0 files** |
| **M7** — `app/Domain/Proceeding` **and its document views** | `v0.7.0-m7-complete` | **0 files** |
| **M8** — `app/Domain/Review` + four view paths | `v0.8.0-m8-phase3` | **0 files** |

**Checked individually rather than as one combined path list**, because a combined diff reports one number and cannot say which freeze held.

**M7 was checked more widely than usual this phase**, and deliberately: Phase 3 is the milestone most likely to have reached into M7's document layer, so `resources/views/documents/{proceedings,promotion-list,_header,_footer,_styles}.blade.php` were diffed as well as `app/Domain/Proceeding`. All unchanged.

The migrations differing from the M8 tag are **M9's own two** — Phase 1's and Phase 3's. **No M4–M8 migration changed.**

### 0.6 What Phase 3 delivered

All twelve scope items; the four that decide whether it was built correctly:

- **§23.1 to the letter.** Publication refused; the refusal's title **is** the literal string `Pending Business Decision (BD-14)`, asserted with `assertSame` and asserted to reach the screen; no effective date invented; **rendering, signing, storage and verification all work with BD-14 open** — the load-bearing test, because a guard that also blocked generation would satisfy "refuse" and ship an untested pipeline.
- **M9's own document layer (AQ-22)** — enum, status enum, table, renderer contract, HTML renderer, service, controller, views. M7's untouched.
- **The full pipeline works**: render → store → SHA-256 → sign → verify, with download, verification, and version history where regenerating never overwrites.
- **BD-14 as configuration, not a constant** (AD-3). Two tests show the far side: set `dpc.circulation.effective_date_basis`, and publication succeeds and the note states the basis. **CGPSC's answer will be applied with no code change.**

**Two real defects were found by the tests during the phase**, both in code written this phase and both mine: an immutability guard that left `content_hash` editable on a signed document, and authorisation gated on `sign` — true only while the note is `consented` — which made every document action unreachable the moment it became legitimate.

### 0.7 M9 is NOT closed

**Instructed, and correct.** M9's **engineering** is complete across all three phases. **Closure is pending BD-14**, exactly as M8's is pending BD-11.

**What that costs today, stated plainly:** the circulation note and revised promotion list are generated, signed, hashed and verifiable, and **they cannot reach the department**. The system is right to block them — publishing a promotion document whose effective date nobody has ruled is worse than not publishing it — but the block is not free, and the officers whose promotions were released by a signed note have no document to show for it.

**No tag was created.** M9 is not closed, so there is no milestone boundary to tag.

### 0.8 Current git status

| | |
|---|---|
| **Commit** | `feat(m9): Circulation DPC Phase 3` |
| **Branch** | `feature/m0-setup` → pushed to `origin/feature/m0-setup` |
| **Tags** | `v0.6.0-m6-complete`, `v0.7.0-m7-complete`, `v0.8.0-m8-phase3` — **unmoved. No M9 tag** |
| **Working tree** | Clean, apart from this file recording its own commit |
| **Frozen milestones** | **Untouched** — five diffs, all empty |

### 0.9 Pending business decisions

**None resolved, none invented.**

| # | Question | Severity | Status |
|---|---|---|---|
| **BD-14** | From what date does a circulated promotion take effect? | **P1** | **The only thing between M9 and closure.** Documents generate, sign and verify; publication alone is blocked. **Holding signed, verifiable documents out of departments' hands today** |
| **BD-15** | May a CR-ground hold be routed to a Review? | **P1** | Live since Phase 1; those officers have no route to resolution |
| **BD-12** | What if the supplied record is adverse? | P1 | Live since Phase 2; no refusal path exists |
| **BD-13** | Time limit on a held promotion? | P2 | Three causes feed it |
| **BD-11** | Supersession and an officer already promoted | P1 | Deferred; **M8's closure is pending it, exactly as M9's is pending BD-14** |
| **BD-2 / BD-3 / BD-9 / BD-10** | Reserved relaxation; waiting-list; court case | P1/P2 | Inherited through the origin's result |

**Two milestones are now built and unclosed for business reasons only.** That is the pattern to watch: the engineering keeps finishing ahead of the rulings, and each unclosed milestone carries real cases.

### 0.10 Technical debt

| # | Status after this phase |
|---|---|
| **TD-2** | **Three consecutive finalisations have needed a retry on `composer audit`** (§0.3). It should be made to fail loudly rather than answer from cache — and the third symptom differed, so the cause may not be one thing |
| **TD-24** | **Still open.** Phase 3 added the table the last review warned about; its three foreign-key names were checked against 64 characters **by hand**, which is exactly what step 1 would automate. Longest: `fk_dpc_circ_doc_generated_by` (28) |
| **TD-20** | Unchanged at three copies. Phase 3 added no listener |
| **TD-19 / TD-21 / TD-22 / TD-23** | Unchanged |

### 0.11 Warnings

1. **P1 — BD-14 is the only thing between M9 and closure**, and it is holding signed, verifiable documents out of departments' hands. **It should go to CGPSC first** of the four open business questions.
2. **P1 — `composer audit` has needed a retry three times out of three.** A security gate that answers from a stale cache, or does not answer at all, is not yet dependable. TD-2's priority should rise and its scope be re-examined.
3. **P2 — AQ-20's deadlock now reaches further.** If the origin DPC's allottee has left the Commission, nobody can sign the note **or** produce its documents. Still unruled.
4. **P2 — TD-24 step 1 would have automated a manual check Phase 3 just performed by hand.** Minutes of work.
5. **P2 — the immutability narrowing means a failed generation cannot be retried in place** — a retry produces a new version. I believe that is correct; it is worth confirming in review.
6. **P2 — BD-9 still fails silently**, and M9's revised promotion list reproduces the origin's result wholesale.

### 0.12 Exact next recommended prompt

Copy-paste, verbatim:

> Put **BD-14** to CGPSC through `docs/UAT_BUSINESS_VALIDATION.md` in plain language with a worked example: a circulated promotion resolved in 2026 against a DPC whose promotion-as-on year is 2017 — does it take effect retrospectively or from the Commission's consent, and what does that do to officers promoted in between? **It is the only thing between M9 and closure.** Raise **BD-15**, **BD-12** and **BD-13** alongside it.

Then, independently:

> Rule who may act on a circulation when the **origin DPC's allottee has left the Commission** — it now blocks signing *and* document production. Consider a standing rule about absent named actors, the same gap AD-22 §0.4 left open for the Architect. Record as an AD.

> Raise **TD-2**'s priority and make `composer audit` fail loudly rather than answering from a stale cache — it has needed a retry at three consecutive finalisations. Then take **TD-24 step 1**: a test asserting every foreign-key and index name is within MySQL's 64-character limit.

**M9 is not closed.** Its engineering is complete; closure is pending BD-14.

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
