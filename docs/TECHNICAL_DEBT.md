# Technical Debt & Known Limitations

Every known limitation, deferred improvement and engineering recommendation, in one permanent place.

**Created 2026-08-13.** Maintained per the Permanent Documentation Rule.

---

## Why this file exists

`docs/ARCHITECT_REVIEW.md` is **replaced in full at each phase**. Risks recorded there survive only because they are manually re-copied forward, which is a process that works until the one time it doesn't — and the item most likely to be dropped is an old P2 nobody has thought about in five phases.

This register is append-only in spirit: items move to **Resolved** rather than disappearing. The architect review remains the place a risk is *raised* and discussed; this is the place it is *kept*.

**One of three registers**, kept separate by architect directive: business questions live in `docs/PENDING_BUSINESS_DECISIONS.md`, and the rulings that constrain future work live in `docs/ARCHITECTURE_DECISIONS.md`. Several items here are *blocked by* a decision recorded there — TD-16, TD-19, TD-20 and TD-21 all exist because AD-15 forbids touching a frozen milestone for a small improvement.

---

## Open items

### P1 — Business, blocking

These are not engineering debt. They are recorded here because they constrain what the system can correctly produce, and they are tracked in full in `docs/PENDING_BUSINESS_DECISIONS.md`.

| # | Item | Impact | Since |
|---|---|---|---|
| **BD-1** | Whether `PUNISHMENT` disqualifies an officer | Slide 33 marks two officers NE who the engine passes. Not implemented unilaterally. | M5 Ph4 |
| **BD-2/BD-3** | Reserved relaxation — automatic or committee discretion, and its scope | **Blocks publishable results.** Any DPC where a reserved category has fewer eligible officers than vacancies produces a provisional list, and a provisional list cannot be published. | M5 Ph1 |
| **BD-9** | Which waiting list an officer joins when their category has no vacancy | **Blocks M7 closure, and fails *unsafely*.** The other open rules mark a result provisional and refuse publication; this one raises no flag at all — the system places the officer under their own category code, considers the calculation complete, and would publish it. It is the only open question that can produce a confidently wrong document rather than a blocked one. Ruled: **do not modify M5**; raised to CGPSC. | M7 Ph4 |
| **BD-10** | What a pending court case (`C.CASE`) does | Blocks M7 closure. The published remark omits `CC`. Ruled: raise to CGPSC, implement no assumption. | M7 Ph4 |
| **BD-11** | What happens to an officer already promoted under a DPC later reviewed and superseded | **Blocks no milestone.** Deferred Business Decision — Required before production activation of the Review supersession policy. (architect ruling 2026-08-14). M8 is complete and tagged without it. The system records the supersession, notifies the department with both DPC codes, and asserts nothing about the promotion — an abstention, not a policy. | M8 design |

### P2 — Engineering

| # | Item | Detail | Recommendation | Since |
|---|---|---|---|---|
| ~~**TD-17**~~ | ~~`CIR` not yet implemented~~ — **closed M7 Ph4** | The design placed holds in M7 Phase 3; the Phase 3 directive's "implement only" list did not include them, so they were not built. **The S34 result therefore cannot show that a promotion is reserved** — in the PPT's own example US8 is `ST SS CIR`, top-scoring, Super-Seated and held on a missing 2016 property return; today's result shows `ST SS` and omits the hold. A gap in the legal output, not a cosmetic one. | Add to Phase 4 before documents (documents must render the hold anyway), or run a short Phase 3b. Deferring to M9 is not viable — M9 *resolves* holds; M7 marks them (AQ-4). | M7 Ph3 |
| **TD-18** | The approval *transition* carries no signature | **Largely closed in Phase 4:** both documents are signed through `SignatureService`, so an approved result is no longer unsigned. `approve_result` still declares `signature => false`, which is now correct rather than provisional — per Directive 3 the signature attests the **document**, not the transition. What remains is that the **real** CSC DigiSign provider is unimplemented, so production still refuses to sign. | Implement the CSC provider once BD-4 answers whether the API signs raw bytes or requires a PDF. | M7 Ph3 |
| **TD-24** | The test suite runs on a different database engine from the application | `phpunit.xml` uses **in-memory SQLite**; the application runs on **MySQL**. M8's `2026_08_14_290004` migration passed **84 green tests** and could not be created by `migrate:fresh` at all — its derived foreign-key name was 65 characters and MySQL refuses any identifier over 64 (error 1059), which SQLite does not enforce. **`dpc_document_review_references` had never existed in the development database**, and four documents recorded the gate as green with a table count taken before the failure. Identifier length is one of several places the two engines differ. | Run the suite — or at minimum a schema build — against **MySQL** in `composer gate` and in CI. **This is the item that has already cost a P0**, and unlike TD-19/20/21 no freeze blocks it. Close it before M9. | M8, gate run 2026-08-14 |
| **TD-23** | M8's end-to-end test bypasses M4's submission gate | `test_a_review_runs_the_full_m6_and_m7_pipeline` advances a Review with `forceFill(['status' => Approved])->saveQuietly()` instead of calling `submit()` and the AH approval. Design §13 requires the real path — *submit → approve → allot → schedule → proceeding → calculate → approve → documents* — and it is the one row of that table not actually driven. **It is what hid a P1:** `sanctioned_post_count` was not copied onto a Review, so M4's completeness gate refused every Review DPC, and no test noticed because no test submitted one. | Drive the test through `submit()` and the AH approval. Test-only change; no production code and no frozen milestone involved. | M8, review 2026-08-14 |
| **TD-22** | The rule-reuse config override is process-global | A Review calculates by setting `config('dpc.scoring')` to the original's snapshot for the duration of the call (AD-19). It is restored in a `finally`, and PHP-FPM serves one request per process, so two calculations cannot interleave today. | If calculation is ever queued or run in a long-lived worker handling concurrent jobs, this becomes real. Revisit then — either serialise, or petition to open the M5 freeze so `calculate()` can accept a `RuleSet` directly. | M8 Ph2 |
| **TD-19** | `DpcProposal` has no `HasUuids` | Directive 2 (M7) requires an approved result to reference the proposal by UUID. The column was added and backfilled by migration, but new proposals get their UUID minted by direct update because adding `HasUuids` to the frozen `DpcProposal` would modify M4. **Now two callers** — `ProceedingService::open()` and `ReviewDpcService::create()` — and each new one is another place the workaround must be remembered. | One line in `DpcProposal` when the M4 freeze is next legitimately opened. It is the cheapest of the four freeze-blocked items and has the most callers. | M7 Ph4 |
| **TD-20** | Notification recipient query duplicated | `SendDocumentPublicationNotification` repeats `SendWorkflowNotification`'s department-scoped role query. Extracting a shared resolver would be a functional change to frozen M6. | Extract `NotificationRecipientResolver` when that freeze is next opened. Two copies of "who should be told" is how one of them quietly stops matching the other. | M7 Ph4 |
| **TD-21** | Calculation Engine Version is not on the run row | Directive 1 asks for it "for every calculation run". It is recorded on every calculate/recalculated timeline event, on the proceeding, and on every document — but not on `dpc_calculation_runs`, because that would mean editing frozen M5 source or writing to M5's table behind its own immutability guard. | Add the column properly when the M5 freeze is next opened; batch it rather than opening the freeze for this alone. | M7 Ph4 |
| **TD-16** | Two content fingerprints of the same chart | M5's `DpcCalculationService::proposalFingerprint()` is `private` on a **frozen** class, so M7 computes its own in `ProceedingService::chartFingerprint()`. They are not identical by design — M5 fingerprints the *scoring input*, M7 the *whole department submission* including loyalty and property returns, which change no score but decide whether an officer is held — but they overlap, and two hashes of overlapping data can drift. | Either accept them as answering different questions (current position, documented in both docblocks), or approve extracting a shared, behaviour-preserving value object when M5's freeze is next opened for an approved reason. Raised for a ruling rather than resolved unilaterally. | M7 Ph1 |
| **TD-15** | Archived SRS describes a different DPC proceeding | `docs/archive/DPC- SRS.docx` specifies per-member voting, free-text minutes, upload of an externally signed proceedings file, and a separate promotion-order generation and distribution module. **None appears in the PPT.** The PPT governs by standing rule, but the gap is large enough to surface as a UAT finding. | Confirm PPT precedence (M7 AQ-8), and ask CGPSC during UAT whether the Commission wants to record remarks against a proceeding — a small additive change if yes, a redesign if discovered after M7 ships. | M7 design |
| **TD-1** | Evidence-table permissions not yet applied | Model-layer immutability cannot stop a query-builder mass update. Closing it is a deployment control, not code. | Apply `docs/deployment/EVIDENCE-TABLE-PERMISSIONS.md` at production rollout, **including the column-level `UPDATE` grant on `dpc_calculation_runs.status`** — revoking `UPDATE` outright breaks every calculation. **M7 adds four more evidence tables** (proceedings, overrides, holds, documents); `dpc_proceeding_documents` legitimately updates after insert, so it needs the same column-level care. | M5 Ph3 |
| **TD-2** | `composer audit` fails on network timeout | `curl error 28` reaching packagist. **Observed twice** — including on the first gate run after this register was written. Not a finding; passes on retry. | CI must retry the audit step, or a build fails for reasons unrelated to the code — and the failure will look like a security gate failing. | M6 Ph4 |
| **TD-3** | UUID backfills are row-by-row | MySQL and SQLite share no UUID function, so the migrations loop in PHP. Trivial at current volumes; `users` was the largest table touched. | If a future migration adds a UUID to a large table, write per-driver batched SQL instead. | M6 Ph2 |
| **TD-4** | Timeline sort not covered by an index | Reads order by `(occurred_at, uuid)`; there is no composite index for it. Immaterial now. | Add `(subject_type, subject_id, occurred_at, uuid)` if the timeline grows large or export becomes routine. | M6 Ph4 |
| **TD-5** | M3 lock-ordering | The Pre-DPC service does not re-fetch with `lockForUpdate()` before reading status, so a narrow double-submit race exists. M4 onward do. | Backport the M4 pattern during hardening. Low likelihood — it needs two simultaneous submits of the same proposal. | M4 Ph1 |
| **TD-6** | No SMS or e-mail gateway | OTP delivery and every S26 notification are logged, not sent. **In production this means no officer can complete verification.** | Contract a government SMS gateway before production. Highest-value outstanding integration — see `docs/INTEGRATION_ROADMAP.md`. | M1 |
| **TD-7** | Audit trail has no UI | Audits, workflow ledger and timeline are recorded but readable only from the database. The first time anyone wants them is a contested promotion. | In M11 scope; bring forward if UAT asks for it. | M4 Ph7 |
| **TD-8** | Unbounded evidence storage | Import batches, uploaded files, error reports and signed documents are retained permanently by ruling — correct for evidence, but sized for nothing. | Capacity plan and backup scope before go-live. | M4 Ph6 |

### P3 — Deferred by decision

| # | Item | Detail | Since |
|---|---|---|---|
| **TD-9** | Excel template export | A "download blank template" button would remove the commonest cause of rejected imports. Moved to the **M11 Enhancement Backlog** by architect decision. | M4 Ph6 |
| **TD-10** | Replay Dry Run | Designed and documented in `docs/EVENT_CATALOG.md`; not built. Needed before enabling any subscriber against historical data. | M6 Ph4 |
| **TD-11** | Calendar integration | Designed; the events already carry everything needed. Awaiting a product decision on which calendar. | M6 Ph4 |
| **TD-12** | Time-zone support | Prepared, not implemented. Default `Asia/Kolkata`; the zone is recorded on every scheduling event so past events stay interpretable. | M6 Ph3 |
| **TD-13** | Workflow Version is documentation-only | Unlike Schema Version and Payload Version, it has no constant, so it can drift from the code it describes. Correct while nothing branches on it. | M6 Ph4 |

---

## Documented interpretations

Not debt — decisions made where the PPT was silent or ambiguous, recorded so they are not mistaken for oversights. Each was raised in review and approved.

| Interpretation | Reasoning |
|---|---|
| Ragged S18 rows read as the most recent years | Later confirmed by slide 33: US4's blank cell still yields 15, only reachable if the window reaches back a year |
| Loyalty stored per-year though S18 shows one column | Per-year is the superset; a single flag cannot represent per-year data |
| Six CR year columns, five scored | The five-year window is a scoring rule, not a data-entry limit |
| "Proposal Version" as a SHA-256 content fingerprint | DPC proposals are not sequentially versioned; the fingerprint answers the question a recalculation actually raises |
| Default DPC date optional | Requiring it would invent a rule the PPT does not state |
| Scheduling not validated against the default date | Same reasoning; the default is guidance |
| M1–M5 member columns treated as layout | Confirmed by architect as a UI representation, not a limit of five |
| **Q12's two circulation triggers are the same rule** — "2 or more CR years missing" (Conventional deck C3) and "CR ≤ 4" (S14 label) | The chart carries **six** CR columns, so "2+ missing" and "4 or fewer present" are one condition stated from opposite ends. S33 shows both live: US6 has four CR grades and is `CIR`; US8 scores 20, qualifies for a Super Seat, and is still `CIR` on a missing 2016 property return. Recorded as an observation — the Q12 ruling remains M9's |
| A hold (`CIR`) is a procedural reservation, not an eligibility verdict | S33's US8 scores 20, Super-Seats, and is held anyway. Merit and procedure are independent, which is why the hold sits outside the frozen engine (M7 design AQ-4) |

---

## Resolved

Kept so a fixed problem is not re-reported, and so the pattern that caused it stays visible.

| Item | Resolution | Closed |
|---|---|---|
| Empty proposals could be submitted and signed | Completeness gate at the service layer; broke 13 tests that had encoded the defect as expected behaviour | M4 Ph7 |
| Signed document contained only a header | Now renders all five sections | M4 Ph7 |
| Audit trail never exercised by any test | `audit.console` was hardcoded false and PHPUnit runs in console, so every audit assertion since M1 was inert | M4 Ph7 |
| 6 dependency advisories in `league/commonmark` | Upgraded 2.8.3 → 2.10.0 | M4 Ph6 |
| Generic validator message clobbered the specific import error | Parse errors now take precedence | M4 Ph6 |
| A failing notification could roll back a workflow transition | Dispatch isolated in `WorkflowEngine::record()`; the obvious fix (queued + `afterCommit`) was insufficient | M6 Ph2 |
| `dpc_time` read differently per database driver | Normalised to `H:i:s` on write | M6 Ph2 |
| Timeline order non-deterministic within one second | Sort by `(occurred_at, uuid)` at every read site | M6 Ph4 |
| Two simultaneous corrections could both be recorded with nothing superseding | The `(proceeding, sequence)` unique index added for Directive 1 makes a race fail loudly instead of quietly producing two corrections numbered 4. Raised in the M7 Phase 2 VAPT review and closed in Phase 3 as a side effect of an unrelated directive. | M7 Ph3 |
| **TD-14** — M4, M5 and M6 uncommitted; last commit was `3133ac4 M3` | Committed and tagged **`v0.6.0-m6-complete`**. The loss risk is gone, and — the part that mattered more — the three freezes are now verifiable: `git diff v0.6.0-m6-complete -- <path>` mechanically answers whether frozen code has changed. Until the tag existed there was no commit at any milestone boundary to diff against, so a freeze breach during M7 would have been indistinguishable from M7's own work. | Baseline 2026-08-13 |

**A pattern worth noting:** four of these were found by *applying a directive properly* rather than by testing the feature that directive concerned — the audit gap, the notification rollback, the driver-dependent time, and the ordering defect. Directives that look like documentation tasks have repeatedly turned out to be live bugs.

---

## Maintaining this file

- A new limitation goes here **when it is found**, not when it is fixed.
- An item moves to **Resolved** with how it was closed — never deleted.
- Anything that would otherwise live only in a terminal or a superseded review belongs here.
- Business questions live in `docs/PENDING_BUSINESS_DECISIONS.md`, and are put to business users in `docs/UAT_BUSINESS_VALIDATION.md`; only their engineering impact is summarised here.
