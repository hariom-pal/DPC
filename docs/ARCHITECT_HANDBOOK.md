# e-DPC Portal — Architect Handbook

**For:** Software Architect / Technical Lead
**Date:** 2026-08-19
**Status:** Engineering complete; development frozen pending business review.

---

## 1. What this system is

The **e-DPC Portal** conducts the Chhattisgarh Public Service Commission's Departmental Promotion Committee process online, end to end: a department declares its sanctioned posts, files a promotion proposal, the Commission allots and schedules a sitting, the sitting evaluates officers against statutory rules, and the result is signed, published and permanently verifiable.

**Two things about the domain shape everything below.**

1. **The output is a legal instrument.** A promotion list decides a serving officer's substantive rank and pay, is challengeable in court, and must be explicable years later — including under rules that have since changed. That is why evidence is immutable, why every calculation stores the rule set it used, and why the system refuses to guess.
2. **The requirement is two PowerPoint decks.** `ODPC_NEW_FINAL.pptx` (52 slides, referenced `S1`–`S52`) and `CONVENTIONAL DPC PROCESS.pptx` (7 slides, `C1`–`C7`). They are screen mock-ups and worked examples, not a specification. Several rules exist only as an expected output in a table — which is how the open business decisions were found.

### Scale

| | |
|---|---|
| Milestones | **M0–M11**, all implemented |
| PHP files under `app/` | 288 |
| Blade views | 125 |
| Database tables | **68** across **53** migrations |
| Domains | 18 |
| Routes | 223 |
| Tests | **939 passing / 3018 assertions**, 78 test classes |
| Static analysis | PHPStan (Larastan) **level 6**, zero errors |

---

## 2. Stack and platform

| Layer | Choice | Why it is worth knowing |
|---|---|---|
| PHP | 8.4 | Enums used heavily as domain vocabulary |
| Framework | Laravel 13 | Standard structure; no custom framework layer |
| Database | MySQL 8 | SQLite in tests — **see §11, this difference cost a P0** |
| Cache / queue / session | Redis | Queue is `sync` in development |
| UI | Blade + Bootstrap 5.3, **vendored** | **No Node, no build step, no CDN.** An M0 decision, and the reason a strict Content-Security-Policy is achievable |
| RBAC | `spatie/laravel-permission` | Six roles |
| Auditing | `owen-it/laravel-auditing` | Model-level change history |
| Excel | `maatwebsite/excel` | Import only; templates are emitted as CSV |

**There is no repository layer and no service container abstraction over Eloquent.** Domain Services own behaviour and talk to Eloquent models directly (AD-9). This was deliberate: an extra indirection would have bought nothing here, because there is one database and no second implementation of anything.

---

## 3. The twenty-five architecture decisions

These are the load-bearing ones. Each was recorded when taken; the full register with rejected alternatives was maintained throughout construction and is archived.

### Requirements and business rules

| # | Decision | Consequence |
|---|---|---|
| **AD-1** | The two decks are the **only** source of business requirements | An older SRS describes per-member voting, free-text minutes and a separate promotion-order module. **None is in the decks and none was built** (see §12) |
| **AD-2** | **Never invent a business rule** | The whole of the Business Decision Register exists because of this one. When a rule is unruled, the system flags and refuses rather than deciding |
| **AD-3** | Business rules live in **configuration and master data**, never in source | Every mark, threshold, bonus and multiplier is in `config/dpc.php`. A government notification changes a value, not a class |
| **AD-19** | A stored rule set is re-applied **through the configuration mechanism**, never a second code path | A Review DPC calculates under the original's rules by *restoring the snapshot into configuration*, not by a parallel implementation. There is exactly one scoring engine |

### Evidence and identity

| # | Decision | Consequence |
|---|---|---|
| **AD-4** | Nothing that constitutes evidence is ever overwritten | Corrections are append-only and supersede. Recalculation creates a new run; the old one survives |
| **AD-5** | Actors are **snapshotted**, not referenced | The timeline records who somebody *was* at the time. A renamed or removed user does not rewrite history |
| **AD-6** | The **timeline is the legal workflow history**; business tables hold current state | "What happened" and "where is it now" are different questions with different sources |
| **AD-7** | Timeline ordering is `(occurred_at, uuid)`, never the sequential id | `occurred_at` has second granularity and one transaction writes two rows. Ordering on time alone was non-deterministic — a real defect, found in M6 |
| **AD-8** | **UUID is the only public identifier** | Sequential ids never appear in a URL or a document |

### Structure

| # | Decision | Consequence |
|---|---|---|
| **AD-9** | Thin controllers → Domain Services → Eloquent models | Controllers authorise and render. Business logic is never in a controller |
| **AD-10** | Engines are **pure**; exactly one class touches the database | The scoring and eligibility engines are functions of their inputs. `DpcCalculationService` is the only persistence point |
| **AD-11** | **One** workflow engine, driven by configuration | Five workflows, one engine. A transition is a config entry, not a method |
| **AD-12** | A failing notification can **never** fail the workflow | A failed SMS once rolled back a completed transition. Every notification path is now `afterCommit` and swallows its own errors |
| **AD-13** | Every external system sits behind a **Provider**, flagged off by default | The whole pipeline runs and demonstrates with every integration disabled |
| **AD-14** | **Approval creates the decision; documents render it** | A document can be regenerated. The decision cannot be re-made |

### Process

| # | Decision | Consequence |
|---|---|---|
| **AD-15** | Freezes, and **freeze verification by diff** at every phase boundary | See §4 |
| **AD-16** | Migrations are **additive**; approved phases are not refactored without approval | 53 migrations, none destructive |
| **AD-17** | **The gate is necessary, not sufficient** | Six consecutive green gates once missed three P1 defects. Every phase is also demoed and checked against the deck |
| **AD-18** | **Silent failures are converted into loud ones** | Strict attribute mode outside production: a write to a non-fillable column throws instead of being discarded. This caught two real data-loss bugs |
| **AD-20** | Behaviour is added to a completed milestone **by decoration, never by edit** | M8 extends M7 through four container-bound decorators, each invisible to a non-Review DPC. M11's notification channel is a second listener on the same event |
| **AD-22** | The **Freeze Exception** mechanism | See §4 |
| **AD-23** | Circulation is **Decision Resolution, not DPC Execution** | A circulation resolves a held promotion; it performs no DPC step. An architecture test enforces this |
| **AD-24** | Circulation is **its own aggregate**; it never reuses `dpc_proposals` | Its own table, code, policy and workflow |
| **AD-25** | A DPC proposal is bound to the Recruitment Rule that sanctions its post pair | **Proposed, not implemented** — needs BD-16. Today a department can forward a DPC for a promotion path no approved Pre-DPC sanctioned |

---

## 4. Freezes, and the exception taken on 2026-08-19

**Five milestones were frozen as they closed**, and freeze verification ran by `git diff` at every subsequent phase boundary. It was never non-empty until the final phase.

```
git diff v0.6.0-m6-complete -- app/Domain/{Dpc,Eligibility,Cgpsc}   # M4, M5, M6
git diff v0.7.0-m7-complete -- app/Domain/Proceeding                # M7
git diff v0.8.0-m8-phase3  -- app/Domain/Review                     # M8
```

The M8 check must also cover its **views** (`resources/views/dpc/reviews/`, `documents/review-context.blade.php`, `documents/superseded-watermark.blade.php`, `partials/review-banner.blade.php`), because M8's UI deliberately sits outside M4's frozen screens. A diff scoped to `app/` alone misses them.

**Freeze levels differed.** M4 (architecture-frozen), M5 (engine-frozen), M6, M7 accepted bug fixes, security fixes, performance work and hardening without per-change approval. **M8 was the strictest** — bug fixes only, each individually approved.

### The Freeze Exception of 2026-08-19

The final-phase directive required completing all technical debt not requiring a Business decision. Four debt items were blocked **only** by a freeze — an Architect decision, not a business one — so the directive was taken as granting the exception. **Every change was additive or behaviour-preserving, and the footprint is exactly seven files:**

| File | Change | Why it is safe |
|---|---|---|
| `Dpc/Models/DpcProposal.php` | `+ HasUuids` | Overrides `newUniqueId()`/`uniqueIds()` only. `id` remains the primary key; existing UUIDs untouched |
| `Proceeding/Services/ProceedingService.php` | UUID mint becomes a safety net | Same guarantee, one mechanism instead of two |
| `Review/Services/ReviewDpcService.php` | Stops overwriting a model-issued UUID | Removes a write, adds none |
| `Eligibility/Models/DpcCalculationRun.php` | `+ calculation_engine_version` fillable | Additive column |
| `Eligibility/Services/DpcCalculationService.php` | Writes that column | Additive |
| `Proceeding/Listeners/SendDocumentPublicationNotification.php` | Uses the shared recipient resolver | Identical query — same roles, same scope, same exclusion of inactive accounts |
| `Review/Listeners/SendSupersessionNotification.php` | As above | As above |

**Nothing in the scoring or eligibility engines was touched.** The M5 engine freeze holds in substance: the only M5-area change is one additional column recorded on a run.

**One behavioural change was made deliberately** and is not covered by the above: an unmatched Pre-DPC feeder now **refuses the approval** rather than silently producing a recruitment rule with no feeder cadre. It is configurable (`dpc.pre_dpc.refuse_unmatched_feeder`, default on) and it is in unfrozen M3.

---

## 5. Domain map

```
app/Domain/
├── Authentication/    M1  login, OTP, verification, credential issuance
├── Department/        M1  registration, department master
├── Master/            M2  15 masters; recruitment rules (versioned, never overwritten)
├── UserManagement/    M2  Chairman/Member/Admin provisioning
├── PreDpc/            M3  sanctioned-post declaration → projection into masters
├── Dpc/               M4  the proposal: committee, vacancies, seniority, chart   [FROZEN]
├── Import/            M4  staged Excel pipeline; M11 blank templates
├── Eligibility/       M5  scoring, eligibility, zone, super seat, calculation runs [FROZEN]
├── Cgpsc/             M6  allotment, acceptance, scheduling, re-allotment        [FROZEN]
├── Proceeding/        M7  the sitting, corrections, result, documents            [FROZEN]
├── Review/            M8  correction chain, supersession                         [FROZEN]
├── Circulation/       M9  held-promotion resolution (own aggregate, AD-24)
├── SelfInspection/    M10 the officer's own-data view
├── Notification/      M11 in-app channel, recipient resolver, replay dry run
├── Report/            M11 pipeline, outcomes, per-department
├── Audit/             M11 unified read over all three records
├── Signature/         cross-cutting  sign, store, hash, verify
└── Shared/            cross-cutting  workflow engine, timeline, value objects
```

Each domain holds `Models/`, `Services/`, and where it needs them `Enums/`, `Policies/`, `Requests/`, `Events/`, `Listeners/`, `ValueObjects/`, `Documents/`.

---

## 6. The workflow engine

**One engine, five workflows, all defined in `config/dpc.php` → `workflows`.** A transition declares the states it is legal from, the state it produces, the role permitted, whether a signature is required, and who is notified:

```php
'submit' => [
    'from' => ['draft', 'returned'], 'to' => 'submitted',
    'role' => 'authorized_officer', 'signature' => false,
    'notify' => ['administrative_head'],
],
```

`WorkflowEngine::assertAllowed()` validates legality and role; `record()` writes the ledger row, the timeline entry and the notification event **inside the caller's transaction**.

**The five workflows:** `pre_dpc`, `dpc_proposal`, `dpc_cgpsc`, `dpc_proceeding`, `dpc_circulation`. Three of them run on `DpcProposal` as subject under different workflow names — which is how the Commission-side state lives in the ledger and the allotment record **without adding a column to M4's frozen status enum**.

### Three independent version numbers

Because three different things can change without the others:

| Version | Where | Bump when |
|---|---|---|
| **Schema Version** | `ActivityTimeline::SCHEMA_VERSION` = 1 | How a stored timeline row reads changes |
| **Payload Version** | on each event class = 1 | An event's meaning changes (**not** when a field is added) |
| **Workflow Version** | `WorkflowEngine::WORKFLOW_VERSION` = 2 | A transition is added, removed, or its meaning changes |

Workflow Version existed only in prose until the final phase; it is now a constant, and it is **recorded on every ledger row**, so a stored transition says which definition of the state machine produced it. Two rows reading `approve` are not necessarily the same act.

### Transitions take a write lock

Every transition re-fetches its row with `lockForUpdate()` **inside** the transaction and reads state from the locked row. Reading state first and locking after leaves the race exactly where it was. Without this, two simultaneous approvals could both pass `assertAllowed()` against the same state and both write — producing, on approval, **two signatures on one proposal**.

---

## 7. The scoring engine

**Frozen. Changes only through configuration, master data or a government notification — never source.**

- **Pure functions.** `ScoringService`, `EligibilityService`, `ZoneOfConsiderationService`, `SuperSeatService` take inputs and return verdicts. None touches the database.
- **One persistence point.** `DpcCalculationService` writes the run and its results.
- **Every run is immutable and self-describing**: rule version, **calculation engine version**, a full snapshot of the configuration used, the proposal content fingerprint, the application version, the git commit, the build timestamp, and the actor. A promotion computed in 2026 is explicable in 2036 under rules that have since changed.
- **Recalculation creates a new run.** Nothing is updated in place.
- **All 13 configurable business rules have at least one golden test**, run against the *shipped* configuration — so a rule change that would alter a promotion outcome fails the build.
- **Provisional results.** Where an unruled business decision could have changed the outcome, the run records `has_pending_decisions` and the specific cases. A provisional result is fully visible and **cannot be published**.

---

## 8. Data architecture

68 tables. The shapes that matter:

**Employee snapshots.** `dpc_working_officers`, `dpc_seniority_entries` and `dpc_evaluation_rows` each carry the same **standard ten fields** as plain columns with **no live foreign key to any employee record**. A historical proposal therefore cannot change because somebody's HR record was updated later. This is also why an officer has no contact channel on a chart — the cause of BD-19.

**Per-year child tables.** CR grades, loyalty flags and property flags are stored per `(row, year)` with **nullable values**, because "year present but value not recorded" is a distinct fact from "year absent" — a Circulation DPC exists precisely because an officer is missing a year.

**Evidence tables.** Calculation runs, results, workflow transitions, timeline entries, signatures, documents, import batches and self-inspection accesses are append-only by intent. Model-layer immutability cannot stop a query-builder mass update, so **database-level grants are a deployment control** (§13).

**Indexing note.** The timeline's real read is *"everything about this subject, in order"* — filtered on `(subject_type, subject_id)`, ordered by `(occurred_at, uuid)`. It now has one composite index covering all four columns. The `uuid` tie-break is in the index because without it the deterministic order is re-established by a sort on every read.

---

## 9. Security

| Control | Implementation |
|---|---|
| Authentication | Email-or-mobile + password; dual email+mobile OTP at registration; forced change of system-issued passwords; session regeneration on login |
| Step-up | OTP at each legally significant signing action, logged as record of intent |
| Authorization | Policies per aggregate, plus `role:` middleware. **A test enumerates every authenticated route and fails if one is neither role-gated nor on a reasoned allowlist** |
| Rate limiting | Config-driven limiters on login, OTP verification, CALCULATE and self-inspection. The self-inspection limiter is the strictest in the application |
| Response headers | CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS over TLS only |
| CSV injection | Neutralised in the import error report and both CSV exports |
| Open redirect | A stored notification URL is followed only if it is a path within the application |
| Audit | Three independent records — workflow ledger, activity timeline, model changes — with a read-only UI |

### The CSP is report-only, and that is a finding

The policy is correct and strict (`script-src 'self'`, no inline scripts). It ships as **`Content-Security-Policy-Report-Only`** because the application has **7 views with inline `<script>` blocks and ~22 inline event handlers** (`onsubmit="return confirm(...)"`, `onchange="this.form.submit()"`), several on M4's frozen screens.

**No test can see this**, because CSP is enforced by the browser: a screen returns 200 either way. Enforcing it would have silently broken the add-a-row buttons on five forms and every confirmation before a destructive action. Adding `'unsafe-inline'` instead would have made the header decorative while looking strict.

**Enforcement is one flag** (`dpc.security.headers.enforce_csp`) once the inline handlers are converted to delegated listeners — recorded as **TD-29**, the largest single remaining engineering item. The one screen written after the policy existed is already compliant, with a test keeping it that way.

---

## 10. Integrations

Every external system sits behind a contract, chosen in one place, flagged off by default (AD-13).

| Integration | Flag | State | Fallback while off |
|---|---|---|---|
| CSC DigiSign | `FEATURE_CSC_DIGISIGN` | **Mock working; real provider unimplemented** (BD-4) | Mock signs; production **refuses** rather than pretending |
| SMS gateway | `FEATURE_SMS_GATEWAY` | **Not procured** (TD-6) | Messages written to the log |
| Notifications | `FEATURE_NOTIFICATIONS` | Off by default | **The in-app channel is deliberately outside this flag** — it calls nothing external, and gating it would switch off the only channel that works |
| HRMS | `FEATURE_HRMS` | Mock | Mock provider |
| LG Directory | `FEATURE_LGD_SYNC` | Not implemented | Seeded district list |
| S3 storage | `FEATURE_S3_STORAGE` | Off | Local disk |

Live state is visible to the CGPSC Admin at `/admin/integrations` — a flag nobody can see is a flag nobody can trust.

---

## 11. The quality gate

Five parts, all mandatory, run with `composer gate`:

1. **Laravel Pint** — PSR-12 + strict types
2. **PHPStan / Larastan level 6** — zero errors, no baseline, no ignores
3. **`security:audit`** — `composer audit` with retry
4. **`schema:verify-mysql`** — builds every migration on MySQL in a throwaway database
5. **PHPUnit** — 939 tests / 3018 assertions

### Why steps 3 and 4 exist, and what they cost to learn

**Step 4 is the most important lesson in this project.** The suite runs on in-memory SQLite; the application runs on MySQL. An M8 migration whose *derived* foreign-key name came out at 65 characters **passed 84 green tests and could not be created by MySQL at all** — MySQL refuses any identifier over 64 characters, SQLite has no such limit. The table had never existed in the development database, and four documents recorded the gate as green on a table count taken before the failure.

Two checks now close it: `SchemaIdentifierLengthTest` catches identifier length **on any engine** (reading foreign keys from the migration source, because SQLite reports inline constraints as unnamed), and `schema:verify-mysql` catches **everything else** by letting MySQL do the enumerating. It never touches the development database.

**Step 3** exists because three consecutive milestone finalisations needed a manual retry on `composer audit`. It retries a transport failure, **never** retries a finding, and **refuses a pass composer served from its local cache** — a green security gate that checked nothing current is worse than a failing one.

### Two tests worth knowing about

- **`ScreenSmokeTest`** renders all 61 parameterless screens as all 7 identities and asserts **no 500**. It catches what per-screen tests cannot: a renamed variable, a moved partial, a composer that throws. Verified to bite.
- **`DpcProposalDetailPerformanceTest`** is an N+1 detector on the heaviest screen. **It is why the notification badge is not cached** — a cached value the layout renders makes a page issue a different query count on its second render, which the detector correctly flags. Blunting it to save one indexed `count()` was the wrong trade.

---

## 12. Technical debt

**Closed in the final phase:** TD-2 (audit retry), TD-4 (timeline index), TD-5 (Pre-DPC lock ordering), TD-7 (audit UI), TD-9 (Excel templates), TD-10 (replay dry run), TD-13 (workflow version constant), TD-19 (proposal UUIDs), TD-20 (recipient resolver), TD-21 (engine version on the run), TD-23, TD-24, TD-25, TD-26, TD-27.

### Open, with the reason each is open

| # | Item | Why it is still open |
|---|---|---|
| **TD-29** | **~22 inline event handlers and 7 inline `<script>` blocks block CSP enforcement** | The largest remaining engineering item. Converting them to delegated listeners is mechanical but touches frozen M4 screens and needs browser verification |
| **TD-6** | **No SMS or e-mail gateway** | Procurement, not engineering. **Without it no officer can complete OTP verification in production** — the highest-value outstanding item of any kind |
| **TD-1** | Evidence-table database grants not applied | A deployment control (§13), not code |
| **TD-8** | Unbounded evidence storage | Needs a capacity plan and backup scope; retention is permanent by ruling |
| **TD-18** | Real CSC signing provider unimplemented | Needs BD-4 |
| **TD-15** | The archived SRS describes a different proceeding | Needs BD-17 |
| **TD-16** | Two content fingerprints of overlapping data | **Accepted, not deferred.** M5 fingerprints the scoring input; M7 the whole submission including loyalty and property returns, which change no score but decide whether an officer is held. They answer different questions and both docblocks say so. Merging them would lose that distinction |
| **TD-22** | The rule-reuse config override is process-global | **Open by directive.** PHP-FPM serves one request per process, so it cannot interleave today. Revisit when calculation is queued — do not solve a concurrency residual before concurrent workers exist |
| **TD-28** | **A circulation deadlocks if the origin DPC's allottee has left the Commission** | Needs a standing rule on absent named actors. Only the Member who conducted the original DPC may act (AQ-20) and the Chairman is not exempt — right for accountability, but with no escape hatch. Affects no current case. **Implementing an unruled accountability rule would be inventing service law** |
| **TD-3, TD-11, TD-12** | UUID backfill batching, calendar integration, time-zone conversion | Guidance and product decisions. Nothing broken |

---

## 13. Deployment architecture

**Before production, in order of lead time:**

1. **Procure a government SMS gateway** (TD-6). Longest lead time and hardest blocker: OTP delivery fails without it.
2. **Obtain the CSC eSign contract** (BD-4). Decides whether a PDF renderer is needed.
3. **Apply the evidence-table grants** (TD-1). The application user must not hold blanket `UPDATE` on the evidence tables — **including the column-level `UPDATE` grant on `dpc_calculation_runs.status`**; revoking `UPDATE` outright breaks every calculation. The runbook is in the archived `docs/deployment/`.
4. **Capacity plan** (TD-8) for permanently retained import files and signed documents.
5. **Turn on the integrations** one flag at a time, confirming each at `/admin/integrations`.
6. **Consider enforcing the CSP** (TD-29) once the inline handlers are gone.

**Environment.** MySQL 8, Redis, PHP 8.4, no Node. `php artisan optimize` caches config, events, routes and views; `optimize:clear` reverses it. `migrate --force` is additive throughout, and every migration has a working `down()`.

**Do not run `demo:seed` in production** — it refuses there, because it creates accounts with a published shared password.

---

## 14. Future architecture

Recorded as directions, not commitments.

- **The decorator ceiling was reached at four.** M8 extends M7 through four container-bound decorators. That is the practical limit before the indirection costs more than the freeze protects; a fifth should prompt opening the freeze instead.
- **Calculation is the only plausible queue candidate.** If it moves to a worker, TD-22 becomes real and `calculate()` should accept a rule set directly rather than swapping configuration.
- **Documents are HTML.** A PDF renderer belongs behind the existing `DocumentRenderer` contract; the approval path must not learn about it.
- **The audit trail is read-only by design, and must stay so.** A test enforces it. An audit record editable from the application that writes it is not an audit record.
- **`PendingBusinessDecision` lives in `Shared`.** It is the pattern for any future refusal that needs a business ruling — structured, named, and carrying what the system deliberately did *not* do.

---

## 15. If you read one thing

**Every significant defect in this project was found by applying a discipline, not by running the gate.** The Demo Readiness Rule caught three P1 defects that six consecutive green gates had missed. A full comparison against the deck's own worked answer found two business rules nobody had noticed were unruled. Removing a test's shortcut found a P1 that had refused every Review DPC in the real application. Running the gate on the right database engine found a table that had never existed.

The gate has never once proved the system was *right*. Keep both.
