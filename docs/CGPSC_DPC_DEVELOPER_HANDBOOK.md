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

Five parts, all must pass: Pint → PHPStan level 6 → security audit → **MySQL schema build** → PHPUnit. Expect **1006 tests / 3290 assertions** and **54 migrations / 68 tables**. Run the suite bounded — `php -d memory_limit=2G vendor/bin/phpunit` — because CLI `memory_limit` is `-1` here and a runaway takes the host down instead of naming the test it died on.

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
tests/{Unit,Feature}/      82 test classes
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

73 tables. The groups:

| Group | Tables |
|---|---|
| Auth & people | `users` (`employee_number` added 2026-08-24), `user_department_assignments`, `roles`, `permissions`, `model_has_*`, `otp_verifications`, `departments` |
| Masters (20) | `districts`, `designations`, `pay_scales`, `department_posts` *(renamed by migration `360001` on 2026-08-24)*, `department_post_orders` *(replaced `department_post_documents` on 2026-08-26 — one row per Government Order)*, `service_classes`, `qualifications`, `reservation_categories`, `reservation_policies`, `reservation_rosters`(+`_points`), `committee_types`, `committee_configurations`, `cgpsc_chairmen` *(new 2026-08-26; `commission_members` was withdrawn on the same date)*, `recruitment_rules`(+`_sources`, +`rr_source_qualifications`), `review_reasons`, `vacancy_types`, `dpc_types`, `portal_notices` |
| Pre-DPC | `pre_dpc_proposals` (`uuid` added 2026-08-22 — see the Architecture Handbook §4.2) + `pre_dpc_promotion_sources`. `pre_dpc_posts` and `pre_dpc_post_feeders` were dropped on 2026-08-24 in favour of a single post pair; **`pre_dpc_promotion_sources` restored the list of Promotion From Posts on 2026-08-25**, because Schedule-IV fills most posts from several feeders under one Government Order. One order, one Promotion To Post, many sources |
| Proposal | `dpc_proposals`, `dpc_proposal_revisions`, `dpc_committee_members`, `dpc_working_officers`, `dpc_vacancies`, `dpc_seniority_entries`, `dpc_evaluation_rows` + 3 per-year children |
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

**Three more shapes arrived with the change register of 2026-08-24.**

*The nullable-flag unique index.* `user_department_assignments.active_flag` and `pre_dpc_proposals.active_flag` are `1` or `NULL`, never `0`. A unique index that includes such a column reads as *at most one active row per key, any number of superseded ones* — because NULLs do not collide, in MySQL or SQLite. Writing `0` instead would make the second superseded row a constraint violation. Use `whereNotNull('active_flag')`, never `where('active_flag', 1)`, so the intent survives a reader.

*A department is a row, not a column.* `users` has no `department_id`. Read `$user->department` (an accessor over `activeAssignment`) and write through `DepartmentAssignmentService`. A query that needs to filter by department joins the assignment: `whereHas('assignments', fn ($q) => $q->whereNotNull('active_flag')->where('department_id', $id))`. Three demo seeders were still querying the dropped column and died on a fresh database — if you find a fourth, that is the shape it should take.

*A Department Post Mapping is effective-dated.* `department_posts` carries
`government_order_number`, `government_order_date`, `effective_from`, `effective_to`
and the nullable `active_flag`, and its unique index is
`(department_id, name, active_flag)` — so one mapping is in force per post and any
number are superseded. **A new Government Order writes a new row**; the previous one
is closed and kept, which is what lets a concluded DPC be audited against the order
that authorised it. `DepartmentPostMappingService::supersede()` is the only way to do
it, and `active_flag` is derived from `status` by a model hook rather than set at each
call site.

*The orders became plural on 2026-08-26* (change register item 10). `department_post_documents`
held **versions of one PDF** with `unique(department_post_id, is_current)`, so a
mapping amended by a corrigendum could record it only by retiring the original
order's scan. `department_post_orders` is **one row per Government Order** — its
number, its date and its own PDF — and none of them retires another.
`DepartmentPostOrder` refuses to be edited or deleted, permitting only `sort_order`
to change. Note the distinction it preserves: superseding a *mapping* is still a new
`department_posts` row; adding an *order* leaves the mapping alone.

*Two names for one thing, retired.* `recruitment_rule_feeders` is `recruitment_rule_sources`, its model is `RecruitmentRuleSource` and its relation is `promotionSources()` — change item 6 renamed the columns and the screens, and this finishes the job. **The posted field is still `feeders`**: that is the screen's wire format, not the schema, and renaming it churns the view, the JavaScript, the request and every test for nothing. The name is `_sources` rather than `_promotion_sources` because the longer one produces a 65-character constraint name and MySQL stops at 64.

*Identifier series.* `dpc_id_sequences` gained a `series` column, so the same per-department, per-year counter machinery issues both `DPC-…` and `PDPC-…` numbers under one lock. `IdentifierSequenceService::next($department, $series, $year)` is the only writer.

**Twelve of these tables are evidence** and are append-only by intent: the two calculation tables, the allotment and schedule, the proceeding with its overrides, holds and documents, the three review-chain tables, and the circulation document. Each model refuses `update` outside a narrow column set and refuses `delete` outright — and each list is mirrored in `config/dpc.php` → `security.evidence_tables`, from which `php artisan security:evidence-grants` generates the production grants. **If you add a model with an immutability guard, add it to that config**; `EvidenceGrantsTest` fails until you do, because the guard alone does not stop a query-builder mass update.

---

## 6. Controllers, services, policies

**Controllers** (`app/Http/Controllers/`) are grouped `Admin/`, `Auth/`, `Candidate/`, `Cgpsc/`, `Dpc/`, `PreDpc/`, `SelfInspection/`, plus `DashboardController`, `NotificationController`, `ReportController`, `HealthController`. Every one is thin.

**The routes added on 2026-08-24**, all UUID-keyed (AD-8):

| Route | Middleware | Notes |
|---|---|---|
| `admin/service-classes` | `role:admin` | Class Master. The `code` is read-only after creation |
| `admin/commission-members` | `role:admin` | Item 13's master |
| `admin/users/{user}/assignments` (GET / POST / DELETE) | `role:admin` | Post, transfer, release. DELETE is a release, not a deletion |
| `pre-dpc/post-attributes` | departmental | Answers item 5's derivation **per post** — `post_ids[]` or a single `post_id`, returning `{"posts": [...]}`. It answered for a *pair* until 2026-08-25, which put the promotion post's Pay Scale beside a feeder. **Declared before `pre-dpc/{preDpc}`** or the literal is swallowed by the wildcard |
| `my-dpc` (index, show) | `role:promotion_candidate` | Item 17. `show` takes a **string**, resolved only from the candidate's own proposals — no route-model binding to be talked out of |

**Services** hold everything. The ones you will meet first:

| Service | Owns |
|---|---|
| `PreDpc\PreDpcProposalService` | The Pre-DPC lifecycle and projection into masters. `applySources()` rewrites the order's Promotion From Posts on save — deleted and recreated, refusing a non-live post, a self-promotion, a duplicate or an empty list. `projectToMasters()` composes the Recruitment Rule for the To Post from that order's own sources |
| `UserManagement\DepartmentAssignmentService` | The only way a user is posted, transferred or released |
| `UserManagement\PromotionCandidateProvisioner` | Candidate accounts, keyed on `employee_number`, reused never duplicated |
| `Committee\CommitteeSelectionService` | The exact set-cover optimiser. Proposes only; `CommitteeComplianceService` is what refuses |
| `Dpc\DpcRevisionService` | Revision numbers and the round-trip history. Writes no `dpc_code` — that is the point |
| `Master\PostAttributeResolver` | What **one** post's mapping determines — its Pay Scale, its Class, its Promotion Duration — as a `PostAttributes`. `resolveMany()` answers a form full of feeder rows in one query. It answered per *pair* until 2026-08-25, reading Pay Scale and Class off the To post, which stated the scale of the post nobody had been promoted into yet |
| `Dpc\DpcProposalService` | All five proposal sections and the submission gate |
| `Import\ExcelImportService` | The staged Preview → Validate → Commit pipeline |
| `Dpc\VacancyCalculationService` | Working Officers, Total Vacancy and the Zone of Consideration — all **derived, never stored**. Delegates the zone to M5's pure engine rather than re-implementing it (AD-19) |
| `Dpc\EvaluationCandidateService` | Projects the Evaluation Chart from the Seniority List and holds the officer-set invariant. **Read `keyOf()` before touching it** — how an officer is recognised across two versions of a list decides whether CR grades follow the right person |
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

### The Evaluation Chart is projected, not entered

Since 2026-08-22 (Architecture Handbook §7b) `saveEvaluationRows()` **does not write employee identity.** The chart's officers and their ten identity fields are projected from the Seniority List when that list is saved; the method updates CR grades, loyalty, property, tribal-area service and the note, and refuses a payload whose officer set is not exactly the list's.

If you are adding to this area:

- **Identity in a payload is ignored, not rejected** — the pre-filled template carries those columns and the officer uploads it back unchanged. `employee_number` is the exception: a mismatch is refused.
- **Fixtures must seed the Seniority List first.** Use `SeedsEvaluationCharts`; do not create `dpc_evaluation_rows` directly unless you are deliberately constructing an already-approved proposal, and say so if you are.
- **The projection is idempotent** and carries evaluation data across a re-import. It has to be: `saveSeniorityEntries()` calls it on every save, and a spelling fix must not cost a department its CR grades.

**Two traps if you add a rule of this shape.**

1. **A Form Request is validated before the controller authorises.** Both requests bail out when `! $proposal->status->isEditable()`, or a submitted proposal answers an edit attempt with "your list is the wrong length" (302) instead of "this proposal is closed" (403) — quietly replacing the immutability refusal with a validation one. `test_seniority_list_is_immutable_once_submitted` caught exactly that.
2. **Sections are no longer independent.** Saving the sanctioned strength changes the zone, which changes whether the seniority list is long enough. `CompletesDpcProposals` fills the checklist in **passes** for that reason, and throws rather than looping forever if a fixture cannot be satisfied.

---

## 9. Configuration

`config/dpc.php` is the most important file in the repository. Blocks: `otp`, `sms`, `signature`, `rate_limiting`, `directory`, `documents`, `admin`, `workflows`, `proceeding`, `dpc_id`, `evaluation`, `committee`, `security`, `notifications`, `self_inspection`, `timezone`, `circulation`, `application`, `scoring`, `limits`, `imports`.

**`scoring` is where every business rule lives** — marks, thresholds, bonuses, zone rules, super-seat logic. Bump `rule_version` whenever any value changes: it is the label a stored calculation is explained by, and leaving it stale makes two rule sets indistinguishable.

**Values that stand in for an unmade decision are null or off, and say so in a comment.** `circulation.effective_date_basis` is null: while it is null, circulation documents are produced, signed and verifiable but **publication is refused**. Do not give it a default to make a test pass.

Feature flags live in `config/features.php` via the `Feature` enum, and every one defaults to **off**.

---

## 10. Testing

**`Tests\TestCase` does two things for you, and both are there because doing them per test does not work.** It fakes the `local` disk, so no test writes into `storage/app/private` — six test files used to, and a document test could then be satisfied by a file the *previous* run left behind. And it flushes the Class Master's static memo, which would otherwise carry one test's labels into the next while `RefreshDatabase` rolled the rows back underneath it. **Do not call `Storage::fake('local')` in a test**; it is already done, and a second call only invites the reader to think it was needed.

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
| `qualifications:unmapped` | Recruitment-rule rows whose qualification text matches no master row (`--fix` re-matches) |
| `notifications:replay --dry-run` | What a replay of the ledger *would* send. Sends nothing; `--dry-run` is required anyway |
| `dpc:reset-transactional-data` | Clear every Pre-DPC/DPC workflow record, keeping all masters and all users. `--dry-run` lists what would go. **Refuses in production** — the list includes the audit trail. Every table in the schema must appear in one of its two lists, and it refuses to run if one does not |
| `dpc:return-expired-allotments` | Return DPCs not concluded by their completion date to the CGPSC Chairman. `--dry-run` lists them; `--as-of=YYYY-MM-DD` treats another date as today. Idempotent — a row already carrying `returned_at` is skipped. **Scheduled daily at 00:15**, so it is normally not run by hand |
| `demo:credentials` | Reset every demo password to one known value, create any missing demo user, and write `docs/demo/DEMO_LOGIN_CREDENTIALS.{md,pdf}`. **Refuses in production** |
| `lang:check` | Missing, extra and identical-in-both keys per locale — the checks `BilingualCoverageTest` runs, with output a translator can read. `--locale=` defaults to `hi` |
| `security:evidence-grants` | Print the production `GRANT`/`REVOKE` SQL for the twelve evidence tables, derived from `dpc.security.evidence_tables`. **`--verify`** instead attempts each write the grants should refuse, as whichever account the application connects as, and reports what the database did — every probe carries `WHERE id = -1` so no row can match. Run `--verify` at every release, not only at first deployment |

### The scheduler

**One entry, and it needs a system cron.** `routes/console.php` registers
`dpc:return-expired-allotments` daily at 00:15, `withoutOverlapping()` and
`onOneServer()`. Nothing runs it unless the host has:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Without that line the registration is inert, which is the exact failure the
scheduling was added to fix — the command existed and was tested for a fortnight
while never once running. `php artisan schedule:list` is the check.

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

## 12b. What the change register of 2026-08-26 moved, for a maintainer

Read this before touching a committee, an allotment or an authorisation check.
Everything here changed on 2026-08-26 and the old shape is still described in
comments and older documents.

### Renamed, moved or removed

| Was | Is now | Note |
|---|---|---|
| `CommissionMember` (model + master) | *gone* | Attributes live on `dpc_committee_members` |
| `CommitteeSelectionService` | *gone* | Nothing to select from; the validator covers it |
| `MemberLevelRule` | *gone* | Unenforceable without a registered member post — **BD-29** |
| `CommitteeProposal` (value object) | *gone* | Belonged to the optimiser |
| `DepartmentPostDocument` | `DepartmentPostOrder` | One row per Government Order, not per file version |
| `DpcProposalService::saveCommittee()` | `DpcCommitteeService::compose()` | Four operations, three actors |
| `CommitteeCapacity::Chairman` | `CommitteeCapacity::CgpscChairman` | Plus `is_dpc_chairman` as a separate flag |
| `MemberCategory::Bhar` | `MemberCategory::BharSadhakSachiv` | And `VibhagSachiv` is new |
| `recruitment_rule_sources.qualification_id` | `rr_source_qualifications` | Many-to-many |
| `pre_dpc_promotion_sources.qualification_id` | `pre_dpc_source_qualifications` | Many-to-many |
| `department_posts.government_order_number` / `_date` | `department_post_orders` | Plural |

### Three traps

**1. `hasRole()` no longer means "was granted".** `ResolveActiveRole` constrains the
loaded `roles` relation to the role the session is *acting as*. So:

- **Authorisation** — use `hasRole()` / `hasAnyRole()`. This is the acting-capacity
  question, and it is what every policy asks.
- **"Does this account hold X at all"** — use `holdsRole()` or `grantedRoles()`.
  These query the pivot. The role switcher, user listings and the credential sheet
  need this one.

Getting it backwards in a policy would widen a permission; getting it backwards in
the switcher produces a switcher that offers only the current role.

**2. `$proposal->committeeMembers` is scoped to serving members.** Replaced members
are excluded, because every caller wants that reading — the completeness check counts
these against the size bounds, the documents print them, the review chain copies
them. Use `committeeHistory()` for the substitutions.

**3. The committee's Chairman seat is not in the payload.** `compose()` places it and
discards any `cgpsc_chairman` row that was submitted. A fixture or seeder that lists
one will silently have it dropped; supply ordinary members only, and enough of them
to satisfy `CommitteeRuleResolver::forProposal()->minMembers`.

### The new fixture precondition

`Tests\TestCase::setUp()` seeds a serving `CgpscChairman`. Item 9 makes the seat
mandatory and `DpcCommitteeService` refuses to compose a committee without one, so
this is a precondition of the **whole** DPC workflow rather than of the committee
screen alone — a fixture that omitted it would fail at submission with a message
about a master nobody was testing. A test about the Chairman itself deletes or
replaces the row, which `CgpscChairmanTest` and `DpcChairmanDesignationTest` both do.

### The new command

`php artisan dpc:return-expired-allotments` sends DPCs past their completion date
back to the Chairman. `--dry-run` lists them, `--as-of=YYYY-MM-DD` is for
verification. It is idempotent — a returned row carries `returned_at` and is
excluded — because the natural failure mode of a scheduled task is running more
often than intended.

**It is registered in `routes/console.php` since 2026-09-05.** It was not before,
so for a fortnight it existed, was tested, and never ran. If you add a command
whose whole value is that it happens by itself, the registration is part of the
feature — `php artisan schedule:list` is how you find out it is not.

### Writing a test that allots

Since the clarification of 2026-09-05 the Chairman names who presides **as part of
allotting**, and `dpc_chairman_member_id` is required — so a proposal with no
committee can no longer be allotted through HTTP at all. `Tests\Concerns\SeatsDpcCommittees`
seats a minimal committee and returns the seat id, so a test about scheduling or a
proceeding does not become a test about committee composition:

```php
use SeatsDpcCommittees;

$this->seatCommittee($this->proposal);

$this->post(route('cgpsc.allotments.store', $this->proposal), [
    'allotted_to' => $member->id,
    'dpc_chairman_member_id' => $this->presidingSeatId($this->proposal),
    'complete_by' => '2026-09-30',
]);
```

It writes the rows directly rather than calling `DpcCommitteeService::compose()`,
which would also require a `cgpsc_chairmen` master the test never mentions. The
service-layer `allot()` still takes the presiding member as an **optional** last
argument, so tests that drive the service rather than the form need no committee.

---

## 12c. What the change register of 2026-08-27 moved, for a maintainer

Read this before touching a vacancy figure, an eligibility verdict or an evaluation-chart
fixture. Everything here changed on 2026-08-27.

### Renamed, moved or removed

| Was | Is now | Note |
|---|---|---|
| `saveVacancies($proposal, int $sanctioned, array $rows)` | `saveVacancies($proposal, array $rows, ?User $actor)` | Sanctioned strength comes from the master; **the parameter order changed** |
| `dpc_proposals.sanctioned_post_count` (entered) | `department_posts.sanctioned_strength` (master) | The proposal column survives as a **snapshot**, written by the service |
| `sanctioned_post_count` on the working-officers form | *gone* | Item 1 — the officer must not be able to type it |
| `dpc_working_officers.deputation_date` | `deputation_from` (+ new `deputation_to`) | A deputation is a period, not a date |
| `OfficerRecord::$experienceYearsOnFeederPost` | `$serviceMonthsOnFeederPost` | **Months.** See the trap below |
| `OfficerRecord::$requiredExperienceYears` | `$requiredExperienceMonths` | Via `RecruitmentRuleSource::minExperienceInMonths()` |
| `OfficerRecord::$requiredPromotionDurationYears` | `$requiredPromotionDurationMonths` | And it is read from **`dpc_proposals`**, not from the rule source |
| `RemarkCode` — 3 cases | 5 cases (`EL`, `NE`, `SS`, `CIR`, `RJ`) | Item 24 |
| `$result->is_eligible` in a view | `$result->status()` | Returns `RemarkCode`; the boolean cannot say four things |

### New

| Thing | Purpose |
|---|---|
| `VacancyEngine` | Pure. The nine figures of item 8 |
| `VacancyBreakdown` | Readonly VO carrying them, plus the period and the contributors |
| `VacancyCalculationRecorder` | Persists to the append-only `dpc_vacancy_calculations` |
| `PromotionHierarchyService` | Item 11 — Class, Pay Scale level, Grade Pay |
| `CandidateGateService` | Pure. Items 12–20, run **before** the formula |
| `CandidateGateVerdict` | Status + reasons + citations + **advisories** |
| `RecordReason` (master) | Item 16. Admin CRUD at `admin/record-reasons` |
| `DpcEvaluationRecordGap` | Items 14/15/19. One row per (officer, kind, year) |
| `RecordGapService` | Records a gap with its reason and its PDF, or nothing |
| `dpc_evaluation_cr_grades.tribal_area` | Item 18 — the benefit attributed to its CR year |
| `pay_scales.level` / `.grade_pay` | What makes a scale *comparable* |

### Five traps

**1. Experience is compared in months, and the message is not.** `min_experience_unit`
exists because a rule may publish 18 *months* — the enum's own docblock says rounding
those up to a year changes who is eligible. Both sides of the comparison are therefore in
months. If you add a third service check, convert it: comparing a month count against a
year count silently refuses officers who qualify, and the arithmetic looks fine.

The *reported* figure still uses whichever unit reads naturally —
`CandidateGateService::duration()` says "5 year(s)" for 60 and "18 month(s)" for 18 —
because an officer told "60 month(s) required, 47 month(s) served" has to do the
arithmetic themselves.

**2. A gate overrides the score; nothing overrides a gate.** The composition lives in one
method, `EligibilityVerdict::overriddenBy()`. Do not re-derive a status anywhere else, and
do not infer one from `is_eligible` — `is_eligible` is false for CIR, and CIR is *not* a
refusal (item 19 says the candidate continues).

**3. Only a gap with a document counts.** `DpcEvaluationRecordGap::hasDocument()` gates
whether the gap reaches `OfficerRecord::$justifiedCrGapYears` at all. Three layers check
it — the request, the service and the value object — and that is not redundancy: the
failure mode is a gap the screen shows as accounted for and the engine reads as
unaccounted for, which nobody can debug from the outside.

**4. Re-projecting a chart deletes and rewrites the per-year rows.** So a field left out
of `EvaluationCandidateService::carriedEvaluationData()` is a field **silently erased the
next time the Seniority List is saved**, and nothing errors. `tribal_area` was added there
at the same time as the column; if you add a fifth per-year field, add it in three places
— the migration, `writeEvaluationData()` and the carry-forward map — and write the test
that saves the seniority list twice.

**5. A fixture with fewer than five Property Returns now describes a rejected officer.**
Item 13 rejects a candidate whose window has a hole, so any fixture representing a
*promotable* officer must carry the full window. `CompletesDpcProposals::evaluationDataFor()`
and `SeedsEvaluationCharts::seedOneOfficer()` both do. **Property defaults to the full
window; loyalty defaults to the CR years** — not the same default, and the difference is
item 15's: a certificate belongs to a CR year, but the Property Return window is
continuous and independent of the CR chart.

### Configuration added

```
dpc.vacancy.period                  { from: {month,day}, to: {month,day} }  # BD-34
dpc.vacancy.promotion_rounding      floor | ceil | round                    # BD-33
dpc.merit.property_return_years     5                                       # BD-37
dpc.merit.required_cr_count         5
dpc.merit.rule_failure_status       NE | RJ                                 # BD-32
dpc.merit.qualification_gate        advise | reject                         # BD-39
dpc.documents.max_kb                10240
```

`qualification_gate` defaults to `advise`, and that default is a business position, not a
convenience: the Recruitment Rule names a qualification *level* and the chart carries a
*degree*, so the comparison cannot be made. An unmatched qualification lands on the run's
pending decisions, aggregated into one entry naming every officer affected, and changes no
status. Do not flip it to `reject` without BD-39.

### Where to look when a merit outcome surprises you

1. `dpc_calculation_results.verdict` — `remark`, `failure_reasons`, `gate_citations`. The
   stored verdict is the answer; the screens read it rather than recomputing.
2. `dpc_calculation_runs.pending_decisions` — anything the engine declined to apply.
3. `dpc_evaluation_record_gaps` for the officer — and check `path` is not null.
4. `dpc_vacancy_calculations` with `superseded_by_id IS NULL` — the nine figures with the
   inputs they were computed from.
5. The **Explain Result** screen — `dpc-proposals/{proposal}/calculations/{run}/officers/{sn}`,
   served by `ExplainCalculationService` — narrates one officer end to end from the stored
   verdict alone, and now states which of the four statuses they reached, on what ground,
   and which record is awaited.

---

## 13. PPT mapping

**All 52 slides of `ODPC_NEW_FINAL.pptx` and all 7 of `CONVENTIONAL DPC PROCESS.pptx` are accounted for**, and all 36 traceability rows are implemented. Re-verified slide by slide on 2026-08-22 by extracting the deck text and checking each against the code — which is how the four rows below marked *(added 2026-08-22)* came to be here: they were implemented but had never been written into the mapping, so nothing said whether they had been looked at.

| Slides | Feature | Where |
|---|---|---|
| S1, S52 | Title and closing slides | Nothing to implement *(added 2026-08-22)* |
| S2–S4 | **The six stages of ODPC**, stated as a narrative index: registration → Pre-DPC → DPC proposal → workflow/allotment → date and time → the sitting and its proceedings | All six implemented as M1, M3, M4, M6, M6, M7 *(added 2026-08-22)* |
| S5–S6 | Department registration | `Domain/Department`, `Auth/RegistrationController` |
| S7–S11 | Pre-DPC proposal | `Domain/PreDpc` |
| S12 | **The eight entries a DPC proposal must carry**: post-to + class, post-from + class, the no-legal-stay declaration, nominated committee members, officers working on the target post, sanctioned post count, the Promotion From Post's final seniority list, and its evaluation chart | All eight present — `dpc-proposals/form` (post pair with class, `legal_stay`), then the four section screens *(added 2026-08-22)* |
| S13–S17 | DPC proposal: committee, officers, seniority | `Domain/Dpc` |
| S18 | Evaluation Chart — **projected from S17 since 2026-08-22**; the AO supplies CR, loyalty, property, tribal service and a note, nothing else | `Domain/Dpc\EvaluationCandidateService` |
| S16–S18 | Excel upload (3 templates). **The S18 template is pre-filled per proposal** — a blank one is now useless, because the importer accepts exactly the Seniority List's officers | `Domain/Import` |
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
