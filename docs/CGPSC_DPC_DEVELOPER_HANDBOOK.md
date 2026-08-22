# CGPSC DPC Developer Handbook

**For:** a developer joining this codebase with no prior context, or taking handover of it
**Version 1.0 · 2026-08-22**

This document is meant to be sufficient on its own — read §1 and §2, get it running, then read §4 before you change anything.

Its companions: the **CGPSC DPC Architecture Handbook** for *why* the system is shaped this way, the **Pending Business Decisions** register for what the Commission has not settled, and **Blockers** for what stands between this repository and production.

---

## 1. Orientation in five minutes

The **e-DPC Portal** runs the Chhattisgarh Public Service Commission's Departmental Promotion Committee process. A department declares its sanctioned posts, files a promotion proposal with an officer-by-officer evaluation chart, the Commission allots and schedules a sitting, an engine scores every officer against statutory rules, and the result is signed, published and permanently verifiable.

**Three things will save you a lot of time.**

1. **The requirement is two PowerPoint decks**, not a specification: `docs/ODPC_NEW_FINAL.pptx` (slides referenced `S1`–`S52`) and `docs/CONVENTIONAL DPC PROCESS.pptx` (`C1`–`C7`). Code comments cite slide numbers throughout. When a comment says *"S18's DOP column carries the feeder-post posting date"*, that is where the fact came from and there is nowhere else to check it.
2. **The output is a legal instrument.** A promotion list decides a serving officer's rank and pay and may be challenged in court years later. That is why evidence is never overwritten, why a calculation stores the rules it used, and why the system refuses to guess at an unruled rule.
3. **Business rules are in `config/dpc.php`, never in classes.** If you find yourself writing a threshold or a mark into PHP, stop — that is the one rule this codebase enforces most consistently.

---

## 2. Getting it running

```bash
git clone <repo> && cd DPC
composer install
cp .env.example .env && php artisan key:generate
```

Configure in `.env`:

```
DB_CONNECTION=mysql
DB_DATABASE=dpc_dev
DB_USERNAME=root
DB_PASSWORD=root
REDIS_HOST=127.0.0.1
```

Then:

```bash
php artisan migrate
php artisan demo:seed          # drops and rebuilds dpc_dev with a full worked dataset
php artisan serve
```

`demo:seed` finishes by printing every credential you need, the DPC identifiers it created, the three facts that open the Self-Inspection screen, and where to find each screen. **It reads those from the database it just built**, so the cheat sheet cannot drift from the data. It refuses to run in production.

**No Node, no npm, no build step.** Bootstrap 5.3 is vendored in `public/vendor/bootstrap/`. There is no asset pipeline to learn and nothing to compile.

### Verify your setup

```bash
composer gate
```

Five parts, all must pass: Pint → PHPStan level 6 → security audit → **MySQL schema build** → PHPUnit. Expect **960 tests / 3135 assertions** and **54 migrations / 68 tables**. Run the suite bounded — `php -d memory_limit=2G vendor/bin/phpunit` — because CLI `memory_limit` is `-1` here and a runaway takes the host down instead of naming the test it died on.

---

## 3. Folder structure

```
app/
├── Console/Commands/      7 commands — see §12
├── Domain/                18 domains — the whole application lives here
│   └── <Domain>/
│       ├── Models/         Eloquent models
│       ├── Services/       ALL business logic
│       ├── Enums/          domain vocabulary (statuses, codes, drivers)
│       ├── Policies/       authorization
│       ├── Requests/       Form Requests (validation)
│       ├── Events/         domain events
│       ├── Listeners/      subscribers
│       ├── ValueObjects/   immutable value types
│       └── Documents/      renderers
├── Enums/                 cross-cutting: Role, Feature, MasterStatus, ServiceClass
├── Http/
│   ├── Controllers/       thin — authorise, delegate, render
│   ├── Middleware/        SetLocale, EnsurePasswordChanged, SecurityHeaders
│   └── View/Composers/    layout-level data (review banner, notification bell)
├── Integrations/          Providers behind contracts + mocks
├── Providers/             AppServiceProvider — bindings, policies, listeners, limiters
└── Support/Concerns/      shared traits (Blameable, IsMaster)

config/dpc.php             EVERY business rule and tunable. ~1000 lines, heavily commented
database/migrations/       54, additive only, every one has a working down()
database/seeders/          demo dataset built as a sequence of workflow events
resources/views/           125 Blade views — NONE containing inline script (§4)
public/js/                 9 files, no build step:
                             portal.js               shell behaviour + data-confirm
                             searchable-select.js    progressive <select> enhancement
                             self-inspection-print.js
                             forms/*.js              one per repeating form (6)
routes/web.php             223 routes, grouped by role
tests/{Unit,Feature}/      81 test classes
docs/                      the five final documents + the two source decks
```

---

## 4. Conventions you must follow

These are not style preferences. Each exists because breaking it caused a real defect.

| Rule | Why |
|---|---|
| **Business logic goes in a Domain Service.** Never a controller, never a model, never a view | Controllers authorise and render. That is all they do |
| **Business rules go in `config/dpc.php`** | A government notification changes a value, not a class. Every mark and threshold is already there |
| **Never invent a business rule.** If the decks do not say, refuse and record it | See `docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`. Use `Shared\ValueObjects\PendingBusinessDecision` for a structured refusal |
| **Migrations are additive.** Never alter or drop | 54 migrations, none destructive |
| **Evidence is never overwritten.** Corrections append and supersede | A recalculation creates a new run; the old one survives |
| **Actors are snapshotted, not just referenced** | The timeline records who somebody *was*. A renamed user must not rewrite history |
| **UUIDs are the only public identifier** | Never put a sequential id in a URL or a document. Give a new route-bound model a `uuid` column, `HasUuids`, `uniqueIds()` **and `getRouteKeyName(): 'uuid'`** — all four. `PublicIdentifierTest` fails if you forget the last, which is exactly what happened to four models for five milestones |
| **No inline script in a view** | No `<script>` block, no `onclick=`, no `javascript:`. The CSP is *enforced*, so an inline handler does not error — the control silently stops working, and no request-level test can see it. Put behaviour in `public/js/`, pass data on a `data-` attribute, and use `data-confirm` for a confirmation. `ViewsAreCspCleanTest` guards it |
| **A notification must never fail a workflow** | A failed SMS once rolled back a completed transition. Use `afterCommit` and swallow errors, logging them |
| **Take the write lock before deciding a transition** | Read state from the locked row. Locking after reading leaves the race where it was |
| **Add to a frozen milestone by decoration, never by edit** | Bind a decorator in the container, or add a second listener on the same event |
| **Write comments that say *why*** | The codebase is dense with them and it is why a five-milestone-old decision is still legible. Match that |

### Adding a workflow transition

1. Add the entry to `config/dpc.php` → `workflows.<name>.transitions`.
2. Call `WorkflowEngine::assertAllowed()` then `record()` from the Service, **inside a transaction**, having taken the lock.
3. Add a phrasing to `NotificationMessageFactory::TITLES` — not required (there is a working fallback), but the fallback reads mechanically.
4. **Bump `WorkflowEngine::WORKFLOW_VERSION`** if you changed what an existing transition means.
5. Add a test asserting both the happy path and the refusal.

### Adding a screen

1. Route in `routes/web.php`, inside the right role group.
2. Thin controller; `$this->authorize(...)` against a Policy.
3. Form Request for validation.
4. Blade view extending `layouts.app` (or `layouts.admin`).
5. **Link it from somewhere.** A delivered screen nobody can reach is a screen that was not delivered — this happened once and cost an afternoon.
6. Tests: the happy path, the refusal for every role that must not reach it, and — if it renders a list — that it shows only what the actor may see.

`ScreenSmokeTest` and `AuthorizationCoverageTest` will pick up your route automatically. The second **fails** if it is authenticated but neither role-gated nor on a reasoned allowlist; that is deliberate.

---

## 5. Database

68 tables. The groups:

| Group | Tables |
|---|---|
| Auth & people | `users`, `roles`, `permissions`, `model_has_*`, `otp_verifications`, `departments` |
| Masters (15) | `districts`, `designations`, `pay_scales`, `cadres`, `qualifications`, `reservation_categories`, `reservation_policies`, `reservation_rosters`(+`_points`), `committee_types`, `committee_configurations`, `recruitment_rules`(+`_feeders`), `review_reasons`, `vacancy_types`, `dpc_types` |
| Pre-DPC | `pre_dpc_proposals` (`uuid` added 2026-08-22 — see the Architecture Handbook §4.2), `pre_dpc_posts`, `pre_dpc_post_feeders` |
| Proposal | `dpc_proposals`, `dpc_committee_members`, `dpc_working_officers`, `dpc_vacancies`, `dpc_seniority_entries`, `dpc_evaluation_rows` + 3 per-year children |
| Import | `import_batches` and children |
| Engine | `dpc_calculation_runs`, `dpc_calculation_results` |
| Commission | `dpc_allotments`, `dpc_schedules` |
| Proceeding | `dpc_proceedings`, `dpc_proceeding_overrides`, `dpc_proceeding_holds`, `dpc_proceeding_documents` |
| Review | `dpc_review_links`, `dpc_proposal_review_reasons`, `dpc_review_calculations`, `dpc_document_review_references` |
| Circulation | `dpc_circulations`, items, `dpc_circulation_documents` |
| M10 / M11 | `self_inspection_accesses`, `user_notifications` |
| Cross-cutting | `workflow_transitions`, `activity_timelines`, `signatures`, `audits` |

**Two shapes to understand before you touch officer data.**

*The ten-field employee snapshot.* The three officer tables each carry the same ten fields as **plain columns with no live foreign key**. A historical proposal cannot change because an HR record was updated later. It is also why an officer has no contact channel — the cause of BD-19.

*Nullable per-year values.* CR, loyalty and property are stored per `(row, year)` and the value is **nullable**, because "recorded as absent" and "not recorded" are different facts. A Circulation DPC exists precisely because an officer is missing a year. Never coalesce a null to `false` here.

**Twelve of these tables are evidence** and are append-only by intent: the two calculation tables, the allotment and schedule, the proceeding with its overrides, holds and documents, the three review-chain tables, and the circulation document. Each model refuses `update` outside a narrow column set and refuses `delete` outright — and each list is mirrored in `config/dpc.php` → `security.evidence_tables`, from which `php artisan security:evidence-grants` generates the production grants. **If you add a model with an immutability guard, add it to that config**; `EvidenceGrantsTest` fails until you do, because the guard alone does not stop a query-builder mass update.

---

## 6. Controllers, services, policies

**Controllers** (`app/Http/Controllers/`) are grouped `Admin/`, `Auth/`, `Cgpsc/`, `Dpc/`, `PreDpc/`, `SelfInspection/`, plus `DashboardController`, `NotificationController`, `ReportController`, `HealthController`. Every one is thin.

**Services** hold everything. The ones you will meet first:

| Service | Owns |
|---|---|
| `PreDpc\PreDpcProposalService` | The Pre-DPC lifecycle and projection into masters |
| `Dpc\DpcProposalService` | All five proposal sections and the submission gate |
| `Import\ExcelImportService` | The staged Preview → Validate → Commit pipeline |
| `Dpc\VacancyCalculationService` | Working Officers, Total Vacancy and the Zone of Consideration — all **derived, never stored**. Delegates the zone to M5's pure engine rather than re-implementing it (AD-19) |
| `Eligibility\DpcCalculationService` | The **only** class that persists a calculation |
| `Cgpsc\{Allotment,Scheduling}Service` | The Commission's side |
| `Proceeding\ProceedingService` | The sitting, corrections, the result |
| `Review\ReviewDpcService` | The correction chain and supersession |
| `Circulation\CirculationService` | Held-promotion resolution |
| `Signature\SignatureService` | Sign, store, hash, verify |
| `Shared\Workflow\WorkflowEngine` | Every state transition |

**Policies** (5): `DpcProposalPolicy`, `PreDpcProposalPolicy`, `DpcAllotmentPolicy`, `DpcProceedingPolicy`, `DpcCirculationPolicy`. Registered in `AppServiceProvider`. **Views ask the same Policy the controller asks** — via `@can` — so a button and the backend cannot disagree. That was a real defect class: an Administrative Head could open an edit form the screen had never offered them.

---

## 7. Events and listeners

Five events, eight listeners.

| Event | Listeners |
|---|---|
| `Shared\Events\WorkflowTransitioned` | `SendWorkflowNotification` (SMS/mail), `RecordInAppNotification` |
| `Proceeding\Events\ProceedingDocumentsPublished` | `SendDocumentPublicationNotification`, `RecordPublicationNotification` |
| `Review\Events\DpcSupersededByReview` | `SendSupersessionNotification`, `RecordSupersessionNotification` |
| `Authentication\Events\OtpRequested` | `SendOtpNotification` |
| `Authentication\Events\CredentialsIssued` | `SendCredentialNotification` |

**Publication and supersession are deliberately not workflow transitions.** Neither changes a state — the decision was taken earlier — so each carries its own event. Modelling them as transitions would have the ledger claim a state change that never happened.

**The in-app listeners are second listeners, not edits.** M6's, M7's and M8's listeners are byte-identical to how they shipped. That is AD-20 applied to subscribers.

Every event carries a `PAYLOAD_VERSION`. **Bump it when the meaning of an existing field changes, never when a field is added** — a subscriber written against version 1 must keep working against an event that merely gained a field.

---

## 8. Validation

Two layers, deliberately:

1. **Form Requests** — shape, type, required-ness, cross-field rules. `SavePreDpcProposalRequest` also enforces that `DR % + PR %` totals correctly.
2. **Service-layer assertions** — immutability and workflow legality. A section is immutable outside `DRAFT`/`RETURNED` **at the Service layer as well as the Policy layer**, so a future caller bypassing the controller cannot mutate a submitted proposal.

**Row rules are shared between manual entry and Excel import** (`Dpc\Validation\DpcRowRules`), so the two paths cannot drift. The `ImportTemplate` enum maps a spreadsheet onto those rules; it does not restate them.

### The vacancy invariants live in three layers, and that is not redundancy

Since the directive of 2026-08-22, three arithmetic rules bind the DPC proposal (Architecture Handbook §7a). Each is checked in **three** places, for a different reason each time:

| Layer | Why it has to be there |
|---|---|
| `SaveWorkingOfficersRequest` / `SaveSeniorityEntriesRequest` | The manual path, and the only one that can put the message beside the field |
| `ExcelImportService::commit()` | **An import never sees a Form Request.** It hands rows straight to the Domain Service, so a rule living only in the request would be one upload away from being bypassed. Checked *before* the transaction, so a refused file leaves the section untouched |
| `DpcProposalCompletenessService` | A proposal can be broken by a *later* edit — lower the sanctioned strength after importing the chart and the ceiling is breached by a screen that never validated it. The submission gate is the one place every route passes |

**Two traps if you add a rule of this shape.**

1. **A Form Request is validated before the controller authorises.** Both requests bail out when `! $proposal->status->isEditable()`, or a submitted proposal answers an edit attempt with "your list is the wrong length" (302) instead of "this proposal is closed" (403) — quietly replacing the immutability refusal with a validation one. `test_seniority_list_is_immutable_once_submitted` caught exactly that.
2. **Sections are no longer independent.** Saving the sanctioned strength changes the zone, which changes whether the seniority list is long enough. `CompletesDpcProposals` fills the checklist in **passes** for that reason, and throws rather than looping forever if a fixture cannot be satisfied.

---

## 9. Configuration

`config/dpc.php` is the most important file in the repository. Blocks: `otp`, `sms`, `signature`, `rate_limiting`, `directory`, `documents`, `admin`, `workflows`, `proceeding`, `dpc_id`, `evaluation`, `pre_dpc`, `security`, `notifications`, `self_inspection`, `timezone`, `circulation`, `application`, `scoring`, `limits`, `imports`.

**`scoring` is where every business rule lives** — marks, thresholds, bonuses, zone rules, super-seat logic. Bump `rule_version` whenever any value changes: it is the label a stored calculation is explained by, and leaving it stale makes two rule sets indistinguishable.

**Values that stand in for an unmade decision are null or off, and say so in a comment.** `circulation.effective_date_basis` is null: while it is null, circulation documents are produced, signed and verifiable but **publication is refused**. Do not give it a default to make a test pass.

Feature flags live in `config/features.php` via the `Feature` enum, and every one defaults to **off**.

---

## 10. Testing

```bash
php -d memory_limit=2G vendor/bin/phpunit                    # everything
php -d memory_limit=2G vendor/bin/phpunit --testsuite Unit   # 54 tests
php -d memory_limit=2G vendor/bin/phpunit --filter Circulation
```

`composer gate` runs the whole thing and takes about four minutes; its process timeout is disabled, because the suite outgrew Composer's 300-second default and the gate began *timing out* rather than passing or failing.

**The suite runs on in-memory SQLite; the application runs on MySQL.** Know this. An M8 migration passed 84 green tests and could not be created by MySQL at all. Two checks now cover it — `SchemaIdentifierLengthTest` and `schema:verify-mysql` — and **`composer gate` runs the second every time.**

**Tests worth reading before you write your own:**

- `tests/Unit/Eligibility/` — the **golden tests**. All 13 configurable business rules, run against the *shipped* configuration, several taken from the deck's own worked answers. A rule change that would alter a promotion outcome fails the build.
- `ScreenSmokeTest` — every parameterless screen for every role, asserting no 500.
- `AuthorizationCoverageTest` — every authenticated route is role-gated or on a reasoned allowlist.
- `SchemaIdentifierLengthTest` — every identifier fits MySQL's 64 characters, on any engine.
- `tests/Feature/PreDpc/PreDpcTransitionLockTest` — how to test a race you cannot reproduce.
- `PublicIdentifierTest` — every route-bound model with a `uuid` binds on it, enumerated from the **router's own reflection**. Written after AD-8 turned out to be false in four models while 939 tests were green, because every test builds URLs with `route()` and `route()` emits whichever key the model names.
- `ViewsAreCspCleanTest` — reads Blade *source*, because a screen with an inline handler returns 200 and simply does not work.
- `EvidenceGrantsTest` — the config list of evidence tables against every model that guards itself. It found that the deployment runbook had gone stale by ten tables.

**The pattern in those last three is the one to copy.** Each asserts a property *across* the codebase, enumerated from the router, the filesystem or the schema — never from a list a person must remember to update. When a decision applies everywhere, that is the only kind of test that can hold it.

Fixtures live in `tests/Concerns/` and `tests/Feature/*/Concerns/`. Reuse them.

---

## 11. Known limitations

| | |
|---|---|
| **No SMS or e-mail gateway** | OTP delivery is logged, not sent. **In production no officer could complete verification.** Not an engineering task — procurement |
| **Documents are HTML, not PDF** | Rendered behind a contract, so a renderer swaps in without touching the approval path. Whether one is needed depends on BD-4 |
| **Real CSC signing unimplemented** | Needs the supplier's contract. The mock signs; production refuses rather than pretending |
| **Eight open business decisions** | See `docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`. Three block publishing a promotion list; one can publish a wrong list silently |
| **A circulation deadlocks if the origin allottee has left** | Only the Member who conducted the original may act, with no escape hatch. Affects no current case; needs a policy ruling |
| **Evidence tables rely on deployment grants** | Model-layer immutability cannot stop a query-builder mass update. `security:evidence-grants` emits the SQL and `--verify` checks it; a DBA still has to run it |
| **No online objection on the Self-Inspection screen** | S51 has two buttons, `CLOSE` and `PRINT`, and sends objections to the department in person. Not built — BD-20 asks whether it should be |

---

## 12. Console commands

| Command | Purpose |
|---|---|
| `demo:seed` | Rebuild the demonstration database and print the cheat sheet. Refuses in production |
| `schema:verify-mysql` | Build every migration on MySQL in a throwaway database. **In the gate** |
| `security:audit` | `composer audit` with retry; refuses a stale-cache pass. **In the gate** |
| `qualifications:unmapped` | Feeders whose qualification text matches no master row (`--fix` re-matches) |
| `pre-dpc:unmatched-feeders` | Feeders that matched no cadre and were omitted from a rule |
| `notifications:replay --dry-run` | What a replay of the ledger *would* send. Sends nothing; `--dry-run` is required anyway |
| `security:evidence-grants` | Print the production `GRANT`/`REVOKE` SQL for the twelve evidence tables, derived from `dpc.security.evidence_tables`. **`--verify`** instead attempts each write the grants should refuse, as whichever account the application connects as, and reports what the database did — every probe carries `WHERE id = -1` so no row can match. Run `--verify` at every release, not only at first deployment |

---

## 12a. Debugging guide

**Start by asking which layer is lying to you.** The three that account for most confusion here:

| Symptom | Almost always | How to confirm |
|---|---|---|
| A button on a screen does nothing at all — no error, no request | **An inline event handler**, refused by the enforced CSP | Open the browser console: it names the directive. Then run `ViewsAreCspCleanTest`. The fix is never `'unsafe-inline'` |
| A screen 404s at a URL you built by hand | You used the **sequential id**. Every route-bound model binds on `uuid` | `route('dpc-proposals.show', $proposal)` — never string-concatenate a URL |
| A write silently does nothing | **A non-fillable column.** Strict attribute mode throws outside production (AD-18), so if it is silent you are in `production` | Check `APP_ENV`, then the model's `$fillable` |
| `RuntimeException: … is immutable / cannot be edited` | You are updating an **evidence** row. That is the guard working | Append a new row, or supersede. Never widen the guard's permitted list to make a test pass |
| A test passes and the real screen 403s | The test used `actingAs` with a user whose **department or role** differs from the real actor's | Check the Policy *and* the `role:` middleware on the route — the two are separate gates and both must pass |
| Migration works in tests, fails on deploy | **SQLite vs MySQL** (§10) | `php artisan schema:verify-mysql`, which is in the gate |
| A calculation refuses to publish | A pending business decision, by design | The screen names the BD number. See the Pending Business Decisions register |
| Nobody received a notification | `FEATURE_SMS_GATEWAY` / `FEATURE_EMAIL_GATEWAY` are off by default | The message is in `storage/logs/laravel.log`. **The in-app bell always works** — it is deliberately outside the flag |
| No OTP arrived in development | Same | Read the code out of `storage/logs/laravel.log` |

**Reading what happened to a record.** Three independent trails, and they answer different questions:

- **`workflow_transitions`** — *what state changes were legal and were made*, with the workflow version that defined them.
- **`activity_timelines`** — *the legal history*, ordered by `(occurred_at, uuid)` and never by id. This is the one to quote.
- **`audits`** — *which columns changed on which model*, from the auditing package.

`/admin/audit-trail` reads all three; it is read-only by design and a test enforces that.

**Explaining a promotion.** Every calculation run stores its rule version, engine version, a full snapshot of the configuration it used, the proposal content fingerprint, the git commit and the actor. `ruleSet()` on the run rebuilds exactly the rules it executed under. **Never reason about a stored result from today's config** — that is what the snapshot is for.

**Reproducing anything.** `php artisan demo:seed` rebuilds the whole worked dataset as a sequence of real workflow events and prints every credential. It is faster than constructing state by hand and it exercises the same code paths a user would.

**Driving the browser.** For anything CSP-, JavaScript- or link-related, run the app and drive it. Chromium headless with `--remote-debugging-port` and a CDP script over Node's built-in `WebSocket` needs no packages, and it is how three defects in this project were found that the suite could not see.

---

## 13. PPT mapping

**All 52 slides of `ODPC_NEW_FINAL.pptx` and all 7 of `CONVENTIONAL DPC PROCESS.pptx` are accounted for**, and all 36 traceability rows are implemented. Re-verified slide by slide on 2026-08-22 by extracting the deck text and checking each against the code — which is how the four rows below marked *(added 2026-08-22)* came to be here: they were implemented but had never been written into the mapping, so nothing said whether they had been looked at.

| Slides | Feature | Where |
|---|---|---|
| S1, S52 | Title and closing slides | Nothing to implement *(added 2026-08-22)* |
| S2–S4 | **The six stages of ODPC**, stated as a narrative index: registration → Pre-DPC → DPC proposal → workflow/allotment → date and time → the sitting and its proceedings | All six implemented as M1, M3, M4, M6, M6, M7 *(added 2026-08-22)* |
| S5–S6 | Department registration | `Domain/Department`, `Auth/RegistrationController` |
| S7–S11 | Pre-DPC proposal | `Domain/PreDpc` |
| S12 | **The eight entries a DPC proposal must carry**: post-to + class, post-from + class, the no-legal-stay declaration, nominated committee members, officers working on the target post, sanctioned post count, the feeder cadre's final seniority list, and the feeder cadre's evaluation chart | All eight present — `dpc-proposals/form` (post pair with class, `legal_stay`), then the four section screens *(added 2026-08-22)* |
| S13–S18 | DPC proposal: committee, officers, seniority, chart | `Domain/Dpc` |
| S16–S18 | Excel upload (3 templates) | `Domain/Import` |
| S19–S25 | AH review and single approval | `Domain/Dpc`, `views/dpc-proposals/review` |
| S26–S30 | Allotment, acceptance, scheduling | `Domain/Cgpsc` |
| S31–S33 | The sitting, corrections, CALCULATE | `Domain/Proceeding` |
| S34 | The result — main and waiting lists | `Domain/Eligibility` + `Proceeding` |
| S35–S44 | Review DPC and supersession | `Domain/Review` |
| S45–S48, C3, C7 | Circulation DPC | `Domain/Circulation` |
| S49–S51 | Self-Inspection | `Domain/SelfInspection` |
| C1–C2 | The conventional (paper) DPC and the proforma it used | Reference, not a specification of this system. Its per-officer items all have a home: सन्निष्ठा → `dpc_evaluation_loyalty_flags`, अचल संपत्ति → `dpc_evaluation_property_flags`, court case → the `C.CASE` note code, departmental enquiry → `PUNISHMENT`. Its **regular / ad-hoc / PwD-reserved vacancy categories are master data** (`vacancy_types`, editable at `/admin/vacancy-types`), so the Commission adds them without a code change *(added 2026-08-22)* |
| C4–C6 | Marks, thresholds, zone, super seat | `config/dpc.php` → `scoring`; `Domain/Eligibility` |
| C5 | Every printed rule, verified line by line: I→I ≥ 15, II→I ≥ 13, II→II ≥ 11, all others ≥ 10; relaxation floor 10 for reserved categories (**off pending BD-2/BD-3**); super seat on all-top-grade with actual seniority as tie-break; I→I last-year CR must not be `ग`; no CR in the window may be `घ`; grade marks `क+`=4 … `घ`=0 | `config/dpc.php` → `scoring.qualifying_scores`, `gates`, `super_seat`, `cr_grade_marks`; one golden test each |
| Login screens (12 slides) | One authentication flow | `Domain/Authentication` |
| Implied | Audit trail | `Domain/Audit`, `/admin/audit-trail` |
| Secondary | Dashboards and reports | `Domain/Report`, `/reports` |

**One thing the roadmap listed and the deck does not contain:** an online objection on S51. That slide has two buttons, `CLOSE` and `PRINT`, and directs objections to the department in person. It was not built — recorded as BD-20.

---

## 14. SRS mapping

**The decks govern (AD-1).** An older SRS exists in the archive and describes features that appear nowhere in either deck: per-member voting, free-text minutes, upload of an externally signed proceedings file, and a separate promotion-order generation and distribution module.

**None of it was built.** The gap is recorded as TD-15 and BD-17 asks what standing the SRS has. Do not implement from it without a ruling — that is what AD-1 exists to prevent.

---

## 15. Where to start

Read in this order:

1. `config/dpc.php` — the whole business domain, heavily commented.
2. `app/Domain/Shared/Workflow/WorkflowEngine.php` — every state change goes through it.
3. `app/Domain/Eligibility/Services/DpcCalculationService.php` — the engine's persistence point, and the shape of immutable evidence.
4. `tests/Unit/Eligibility/` — the golden tests, which is the domain stated as assertions.
5. `docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` — what the system deliberately does not decide, and why.

Then run `demo:seed` and click through the process as each role. It takes about twenty minutes and is worth more than any amount of further reading.
