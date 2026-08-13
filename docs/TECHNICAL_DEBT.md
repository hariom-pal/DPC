# Technical Debt & Known Limitations

Every known limitation, deferred improvement and engineering recommendation, in one permanent place.

**Created 2026-08-13.** Maintained per the Permanent Documentation Rule.

---

## Why this file exists

`docs/ARCHITECT_REVIEW.md` is **replaced in full at each phase**. Risks recorded there survive only because they are manually re-copied forward, which is a process that works until the one time it doesn't — and the item most likely to be dropped is an old P2 nobody has thought about in five phases.

This register is append-only in spirit: items move to **Resolved** rather than disappearing. The architect review remains the place a risk is *raised* and discussed; this is the place it is *kept*.

---

## Open items

### P1 — Business, blocking

These are not engineering debt. They are recorded here because they constrain what the system can correctly produce, and they are tracked in full in `docs/business/PENDING_BUSINESS_DECISIONS.md`.

| # | Item | Impact | Since |
|---|---|---|---|
| **BD-1** | Whether `PUNISHMENT` disqualifies an officer | Slide 33 marks two officers NE who the engine passes. Not implemented unilaterally. | M5 Ph4 |
| **BD-2/BD-3** | Reserved relaxation — automatic or committee discretion, and its scope | **Blocks publishable results.** Any DPC where a reserved category has fewer eligible officers than vacancies produces a provisional list. | M5 Ph1 |

### P2 — Engineering

| # | Item | Detail | Recommendation | Since |
|---|---|---|---|---|
| **TD-1** | Evidence-table permissions not yet applied | Model-layer immutability cannot stop a query-builder mass update. Closing it is a deployment control, not code. | Apply `docs/deployment/EVIDENCE-TABLE-PERMISSIONS.md` at production rollout, **including the column-level `UPDATE` grant on `dpc_calculation_runs.status`** — revoking `UPDATE` outright breaks every calculation. | M5 Ph3 |
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
| **TD-14** — M4, M5 and M6 uncommitted; last commit was `3133ac4 M3` | Committed and tagged **`v0.6.0-m6-complete`**. The loss risk is gone, and — the part that mattered more — the three freezes are now verifiable: `git diff v0.6.0-m6-complete -- <path>` mechanically answers whether frozen code has changed. Until the tag existed there was no commit at any milestone boundary to diff against, so a freeze breach during M7 would have been indistinguishable from M7's own work. | Baseline 2026-08-13 |

**A pattern worth noting:** four of these were found by *applying a directive properly* rather than by testing the feature that directive concerned — the audit gap, the notification rollback, the driver-dependent time, and the ordering defect. Directives that look like documentation tasks have repeatedly turned out to be live bugs.

---

## Maintaining this file

- A new limitation goes here **when it is found**, not when it is fixed.
- An item moves to **Resolved** with how it was closed — never deleted.
- Anything that would otherwise live only in a terminal or a superseded review belongs here.
- Business questions live in `docs/business/PENDING_BUSINESS_DECISIONS.md`; only their engineering impact is summarised here.
