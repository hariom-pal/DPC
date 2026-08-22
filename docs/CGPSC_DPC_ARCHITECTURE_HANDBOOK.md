# CGPSC DPC Architecture Handbook

**For:** Architects and Technical Leads
**Version 1.0 · 2026-08-22**
**Status:** Engineering complete; development frozen pending business review.

This document explains **why** the system is built the way it is. For how to work on it, see the **CGPSC DPC Developer Handbook**; for what the Commission still has to decide, the **Pending Business Decisions** register; for what stands between here and production, **Blockers**.

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
| PHP files under `app/` | 289 |
| Blade views | 125, **none carrying inline script** |
| JavaScript files | 9, all under `public/js/`, no build step |
| Database tables | **68** across **54** migrations |
| Domains | 18 |
| Routes | 223, all named, all role-gated or on a reasoned allowlist |
| Tests | **1006 passing / 3290 assertions**, 82 test classes |
| Static analysis | PHPStan (Larastan) **level 6**, zero errors, no baseline |
| Content-Security-Policy | **enforced** (`script-src 'self'`), browser-verified |

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
| **AD-8** | **UUID is the only public identifier** | Sequential ids never appear in a URL or a document. **This was recorded from M6 onward and was not actually true until 2026-08-22 — see §4.2** |

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
| **AD-16** | Migrations are **additive**; approved phases are not refactored without approval | 54 migrations, none destructive, every one with a working `down()` |
| **AD-17** | **The gate is necessary, not sufficient** | Six consecutive green gates once missed three P1 defects. Every phase is also demoed and checked against the deck |
| **AD-18** | **Silent failures are converted into loud ones** | Strict attribute mode outside production: a write to a non-fillable column throws instead of being discarded. This caught two real data-loss bugs |
| **AD-20** | Behaviour is added to a completed milestone **by decoration, never by edit** | M8 extends M7 through four container-bound decorators, each invisible to a non-Review DPC. M11's notification channel is a second listener on the same event |
| **AD-22** | The **Freeze Exception** mechanism | See §4 |
| **AD-23** | Circulation is **Decision Resolution, not DPC Execution** | A circulation resolves a held promotion; it performs no DPC step. An architecture test enforces this |
| **AD-24** | Circulation is **its own aggregate**; it never reuses `dpc_proposals` | Its own table, code, policy and workflow |
| **AD-25** | A DPC proposal is bound to the Recruitment Rule that sanctions its post pair | **Proposed, not implemented** — needs BD-16. Today a department can forward a DPC for a promotion path no approved Pre-DPC sanctioned |

---

## 4. Freezes, and the two exceptions taken to close the project

**Five milestones were frozen as they closed**, and freeze verification ran by `git diff` at every subsequent phase boundary. It was never non-empty until the final phase.

```
git diff v0.6.0-m6-complete -- app/Domain/{Dpc,Eligibility,Cgpsc}   # M4, M5, M6
git diff v0.7.0-m7-complete -- app/Domain/Proceeding                # M7
git diff v0.8.0-m8-phase3  -- app/Domain/Review                     # M8
```

The M8 check must also cover its **views** (`resources/views/dpc/reviews/`, `documents/review-context.blade.php`, `documents/superseded-watermark.blade.php`, `partials/review-banner.blade.php`), because M8's UI deliberately sits outside M4's frozen screens. A diff scoped to `app/` alone misses them.

**Freeze levels differed.** M4 (architecture-frozen), M5 (engine-frozen), M6, M7 accepted bug fixes, security fixes, performance work and hardening without per-change approval. **M8 was the strictest** — bug fixes only, each individually approved.

### 4.1 The Freeze Exception of 2026-08-19

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

### 4.2 The Freeze Exception of 2026-08-22 — and the finding that forced it

The final phase asked for every remaining implementation not needing a business ruling. Two items qualified, and pursuing the first turned up something worse than either.

#### AD-8 was recorded, documented as delivered, and false

**`AD-8` says the UUID is the only public identifier and a sequential id never appears in a URL.** Thirteen models implemented it. **Four did not**, and they were the four that matter:

| Model | Was addressed by | Routes affected |
|---|---|---|
| `DpcProposal` | `id` | 26 — every DPC screen: `/dpc-proposals/4` |
| `PreDpcProposal` | `id` (**no `uuid` column at all**) | 6 — `/pre-dpc/1` |
| `User` | `id` | 4 — `/admin/users/7` |
| `UserNotification` | `id` | 1 |

The history is legible and each step was defensible on its own. `dpc_proposals.uuid` arrived in M7 to keep sequential ids out of *signed documents*, and its migration says in terms that the route key was left alone "so no relation and no URL changes" — because changing it would have touched frozen M4. `users.uuid` arrived in M6 for timeline actor snapshots with the same note. **TD-19 then recorded "proposal UUIDs" as closed** when the `HasUuids` trait was added, which minted the value but changed no URL. At no point did anyone write down that half of AD-8 was outstanding, and two of the four final documents asserted it as done.

**It was not only a disclosure problem.** With `id` as the route key, MySQL resolves the binding by coercing the URL segment to an integer. A request for `/dpc-proposals/01a0282e-b21f-…/committee` therefore returned **proposal 1** — a different record — with a 200 rather than a 404. A mistyped or guessed identifier silently produced somebody else's DPC. The department policy still applied, so this was not privilege escalation; it was a request for one record being answered with another.

**Why the whole suite was green either way.** Every test builds its URLs with `route()`, and `route()` emits whichever key the model names. 939 tests passed before the change and 939 after it. **No amount of that suite could have found this**, which is why the fix ships with a *structural* test — `PublicIdentifierTest` reads the router's own binding reflection and fails if any route-bound model with a `uuid` column does not bind on it. A future aggregate that forgets fails on the commit that adds it.

**Footprint** — four route-key overrides, one additive migration, and the AD-8 test:

| File | Change |
|---|---|
| `Dpc/Models/DpcProposal.php` | `+ getRouteKeyName(): 'uuid'` |
| `PreDpc/Models/PreDpcProposal.php` | `+ HasUuids`, `uniqueIds()`, `getRouteKeyName()` |
| `Authentication/Models/User.php` | `+ getRouteKeyName(): 'uuid'` |
| `Notification/Models/UserNotification.php` | `+ getRouteKeyName(): 'uuid'` |
| `…350001_add_uuid_to_pre_dpc_proposals.php` | Additive column, backfilled, then made unique |
| `tests/Feature/Security/PublicIdentifierTest.php` | The structural check, 5 cases |

`id` remains the primary key throughout, so every foreign key, the Pre-DPC version lineage and every actor reference are untouched. **Only the public URL changed.** Nothing else in the codebase needed editing, because nowhere builds a URL by hand.

#### TD-29 — the CSP is now enforced

The second item. Detail in §9; the freeze relevance is that six of the inline scripts were on frozen M4 and M3 screens, and the conversion is behaviour-preserving view-layer work.

#### Why these were taken as within the directive

Both were blocked by a freeze, which is an **Architect** decision, not a business one — so the directive that asked for all remaining non-business implementation was read as granting the exception, on the same reasoning as 2026-08-19. AD-8 additionally had a defect behind it, and AD-15 admits security fixes to a frozen milestone without per-change approval in any case.

### 4.3 One more defect, found by neither the gate nor the freeze diff

The **Review DPC banner offered a "Review Chain" button that 403'd for Commission users.** The banner is rendered by a view composer on any route that binds a proposal, and the Commission side has four such routes (`/cgpsc/allotments/{dpcProposal}/history` and the schedule equivalents). The chain screen lives under `dpc-proposals/*`, gated by middleware to the two departmental roles. So a Chairman or Member conducting a Review saw the button, pressed it, and got an access-denied page.

**It was found by driving the application in a browser as each of the five roles and reading the console** — 89 screens as the Authorized Officer, 50 as the Chairman, and so on. Nothing in the suite saw it: every test that renders the banner renders it as a departmental user, and **a link that is never followed cannot fail.** The fix is a `can_view_chain` check in the composer, and `ReviewDpcTest` now asserts the pair the house style requires — the link is absent for the role that cannot use it, *and* the route still refuses that role.

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

## 7a. Derived vacancy and the Zone of Consideration (business directive, 2026-08-22)

Three numbers on the DPC proposal are now **derived, not typed**:

```
Working Officers = rows on the Currently Working chart
Total Vacancy    = Sanctioned Post Count − Working Officers
Zone             = the M5 zone rule for this class pair, applied to Total Vacancy
```

The vacancy-type fields (Clear / Retirement / Promotion / Deputation) no longer *produce* the total — they **classify** it — so their sum must equal it. Three gates enforce this, each switchable in `dpc.vacancy`:

| Rule | Refusal |
|---|---|
| `working ≤ sanctioned` | *"Working Officers cannot exceed the sanctioned strength."* |
| `Σ vacancy rows = sanctioned − working` | *"The sum of all vacancy types must equal the calculated total vacancy."* |
| `seniority count = zone size` | *"The uploaded Seniority List must contain exactly N officers based on the calculated Zone of Consideration."* |

### Nothing is stored, and the zone is not reimplemented

All three are computed on read by `Dpc\Services\VacancyCalculationService`. Storing them would create a second version of the truth that a later edit could leave stale.

**The service does not contain the zone formula.** It delegates to `Eligibility\Services\ZoneOfConsiderationService` — the pure engine M5 already scores with — because AD-19 says a rule is re-applied through the existing mechanism and never through a second code path. The zone on the proposal screen and the zone a calculation runs under are therefore the same number *by construction*. Writing `(vacancy * 2) + 4` into M4 would have been three lines and would have drifted the first time a notification changed the multiplier; a test pins this by changing the configured multiplier and asserting the proposal screen moves with it.

### Two places where the directive's flat formula and the deck disagree

The directive states the zone as `(Total Vacancy × 2) + 4`. That is exactly the deck's Class-I → Class-I rule, and it matches the directive's worked example (vacancy 3 → 10). It differs from the deck in two cases, and **the deck was followed**, because AD-1 makes the decks the source of business rules and AD-2 forbids inventing one:

| Case | Deck | Flat formula | Implemented |
|---|---|---|---|
| **One vacancy** | top **5** (`single_vacancy_size`) | 2×1+4 = 6 | **5** |
| **Any pair other than Class-I → Class-I** | no formula — every officer meeting the experience qualification, uncapped | 2V+4 | **uncapped, and the seniority gate does not apply** |

Both are `dpc.scoring.zone.rules` configuration, so a ruling that the flat formula should govern everywhere is an environment change plus a golden-test update — not a code change. Both are pinned by named tests so that overriding the deck later is a visible edit rather than a silent drift. **These two cases are the item to put in front of CGPSC**; everything else in the directive is implemented as written.

### Why the balance rule is worth more than it looks

`DpcCalculationService` computes its total vacancy as `array_sum($vacanciesByCategory)` — the sum of the very rows this rule constrains. Before the rule, a department could record four classified vacancies against a derived total of three and **the engine would have promoted four officers.** The invariant makes the number M5 fills seats against equal the number the screen shows.

### Enforced in three places, deliberately

The Form Request, the Excel commit, and the submission checklist. Not redundancy:

- the **import** hands rows straight to the Domain Service and never sees a Form Request, so a rule living only there would be one file upload away from being bypassed;
- a proposal can be **broken by a later edit** — lower the sanctioned strength after importing the officer chart and the ceiling is breached by a screen that never validated it. The checklist is the one place every route passes before an Administrative Head is asked to sign.

The import check runs *before* its transaction, so a refused file leaves the section as it was rather than half-replaced.

### What this narrowed

`DpcProposalCompletenessService` used to state that it would never size a zone, because M4 does no calculation. That now holds for everything except these three checks, and the docblock says so rather than quietly contradicting itself. Nothing there scores or ranks an officer, and the zone is asked of M5 rather than computed.

### A consequence worth stating plainly

`Total Vacancy = Sanctioned − Working` means **a department must list every officer in post.** A unit with 40 sanctioned posts, 37 officers serving and 3 vacancies must file all 37; recording 3 officers and 3 vacancies against 40 sanctioned posts is now a refusal rather than a passable approximation. The demonstration dataset itself failed on this when the rule landed — it carried 40 sanctioned posts, 3 working officers and 3 vacancies — which is the clearest evidence that the previous screens permitted a chart nobody could reconcile.

## 7b. The Seniority List is the source of truth for who is evaluated (business directive, 2026-08-22)

S16, S17 and S18 each used to carry the same **ten employee fields**, so a department entered or uploaded the same officer three times. Nothing reconciled them. An officer could be `US4` with one date of birth on the seniority list and another on the evaluation chart — and **the chart is what the scoring engine reads**, so the least-verified copy was the one that decided the promotion.

**The chart is now projected.** Saving the Seniority List creates one evaluation row per officer with all ten identity fields copied across; `sn` takes the seniority number, so "S.N. 4" means the same officer on both screens. The Authorized Officer then supplies only what evaluation is about:

| Supplied by the officer | Projected from the Seniority List |
|---|---|
| CR grade (year-wise) | Employee No., Name, DOB, Qualification |
| Loyalty (year-wise) | Current Post, Feeder Post, Category |
| Immovable Property (year-wise) | Date of Joining Service |
| Tribal-area service, Note | Date of Posting on Current / Feeder Post |

### Identity is still *copied*, not read through a relation

A foreign key would have made the chart's identity follow the seniority list forever, including after approval — losing the property the ten-field snapshot standard exists to provide, that a historical proposal cannot change because a record was edited later. Copying keeps the evidence immutable **and needs no migration**: the columns were already there.

### What the screen can no longer express

Identity renders as *text*, not as disabled inputs, so there is no field to re-enable. There is no "add officer" button and no "remove officer" button, because both could only ever be refused. A screen that cannot express an invalid state is better than one that expresses it and is then corrected.

Three refusals back that up, at the request, the import and the submission gate:

- an **extra, missing or renumbered** officer → *"The Evaluation Chart must contain exactly the same officers as the approved Seniority List."*
- an **employee number that disagrees** with the list → refused by name, because an edited spreadsheet that reassigns one is how an officer's confidential record ends up filed against somebody else;
- identity fields in the payload → **ignored**. Not an error: the pre-filled template contains those columns and the officer uploads it back unchanged.

### The pre-filled template

`GET /dpc-proposals/{proposal}/evaluation-chart/template` emits a CSV with every officer already on it and only the evaluation columns blank. The blank Evaluation Chart template on the Downloads screen is now of no use, because the importer accepts exactly the Seniority List's officers.

The builder's standing rule is *headers only, no sample row* — a specimen officer is one a department forgets to delete. That reasoning does not apply here: these rows are **the exact set the importer will insist on**, and deleting one is a refusal rather than a silent success.

**One data-loss bug was found and fixed while building it.** The per-year columns span `max_cr_years` ending at an anchor year. Anchored on the *proposal date*, a 2026 proposal emitted 2021–2026 while a chart recording 2012–2016 produced a file with its CR grades **missing** — the department would have filled in the blanks and uploaded a chart that silently wiped five years of records. The anchor is now the chart's own latest recorded year, so the round trip is lossless. Two tests pin it.

### What re-importing a Seniority List does

The candidate set is rebuilt, and evaluation data is carried across for officers still on the list. **Recognition is by employee number first, serial number only as a fallback**, and that ordering is the important part: a serial number is a *position*, not a person. Re-order the list, or drop one officer from the middle, and S.N. 4 is somebody else — carrying CR grades across on serial alone would move one officer's record onto another's row, which is both invisible and decisive. Where no employee number exists, `sn|name` is the best key available; it fails towards *discarding* data rather than misattributing it.

### Where the fixtures had to change, and what that revealed

Every test that built a chart directly now seeds the Seniority List and lets the projection produce it (`SeedsEvaluationCharts`). One consequence is worth recording: the proceedings fixture declares two to six officers to make a scoring point, and no officer count satisfies the zone rule for all of them (the zone takes 5, 8, 10 … and never 2, 3, 4 or 6). Left on, the submission helper would rewrite the seniority list — and therefore replace the chart a Review had inherited, which is exactly what several of those tests assert about. The zone rule is stood down in that fixture, with the reason in the file, and covered directly elsewhere.

### Future: HRMS makes all three automatic

Recorded as a direction, **not implemented and not scheduled**. When an HRMS integration exists:

| Field | Today | With HRMS |
|---|---|---|
| Sanctioned Posts | typed by the Authorized Officer | from the Establishment master |
| Working Officers | derived from the uploaded chart | from HRMS |
| Total Vacancy | derived | derived, unchanged |

The Authorized Officer would then enter **nothing** on this screen — only classify the vacancies and upload documents. **The architecture already points that way and needs no rework to get there:** the two inputs are read in one service, `Working Officers` is already a *count of rows* rather than a typed figure, HRMS already sits behind a Provider contract (`FEATURE_HRMS`, off, mock implemented), and the derived fields carry no `name` attribute, so no submitted form can set them. Replacing the officer chart's source is a change to where those rows come from, not to any rule built on them. See BD-5: no HRMS integration is scheduled.

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
| Response headers | CSP **enforced**, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS over TLS only |
| Public identifiers | Every route-bound aggregate is addressed by UUID; a structural test enforces it (§4.2) |
| Evidence at rest | Twelve append-only tables, with the database grants that back the application guards emitted by `security:evidence-grants` (§13) |
| CSV injection | Neutralised in the import error report and both CSV exports |
| Open redirect | A stored notification URL is followed only if it is a path within the application |
| Audit | Three independent records — workflow ledger, activity timeline, model changes — with a read-only UI |

### 9.1 The CSP is enforced, and how the switch was thrown matters more than the switch

The policy is strict and now **enforced**: `default-src 'self'`, `script-src 'self'`, `object-src 'none'`, no CDN, no inline script. Inline *styles* are permitted and inline *scripts* are not — several views set element widths inline, the report bars among them, while an inline-script allowance is precisely what turns a reflected string into executable code.

For most of the project it shipped `Content-Security-Policy-Report-Only`, and that was recorded as a finding rather than a preference. The application had **6 views with inline `<script>` blocks and 21 inline event handlers** across 20 views (`onsubmit="return confirm(…)"`, `onchange="this.form.submit()"`), several on M4's frozen screens. Under an enforced `script-src 'self'` a browser silently refuses all of them: the add-a-row buttons on six repeating forms stop working, and every confirmation before a destructive action stops appearing — the form just submits.

**The test suite cannot see any of that.** CSP is enforced by the browser, not the server; a screen returns 200 either way. **So the switch was not thrown on a green suite** — that is the whole point of this section:

1. **The six inline blocks became six files** under `public/js/forms/`, one per screen, logic unchanged. Their seed rows now arrive on a `data-seed` attribute, which a browser reads as data and never as code, so there is nothing for `script-src` to refuse. (A `<script type="application/json">` block would very probably also have worked; a `data-` attribute is exempt *by construction* and needs no argument about browser behaviour, which is worth more here than elegance.)
2. **The 21 handlers became two delegated listeners** in `portal.js`, declared in markup as `data-confirm` and `data-submit-on-change`. The confirm listener is registered *before* the busy-state listener and the latter now checks `event.defaultPrevented`, so a cancelled submission does not leave a spinner on a button going nowhere.
3. **The result was then driven in headless Chromium with the policy enforced** — 12 targeted checks across the committee, seniority, working-officers, evaluation-chart, Pre-DPC and recruitment-rule forms, the confirmation dialogues, the Pre-DPC rehydration path, the submit guard and the accessibility control; then a **full crawl of 89 screens as the Authorized Officer, 89 as the Administrative Head, 89 as the Admin, 50 as the Chairman, 27 as a Member**, plus the anonymous portal and the whole Self-Inspection journey including the print view. **Zero violations, zero console errors, zero 5xx.** That crawl is also what found the Review-banner defect in §4.3.
4. **Only then** did `enforce_csp` default to `true`.

`ViewsAreCspCleanTest` now fails the build if an inline handler, an inline `<script>`, or a `javascript:` URL reappears anywhere under `resources/views` — and, in both directions, if a view loads a script that is not on disk or a script exists that no view loads. That second pair matters: mistyping an extracted script's path leaves a screen that renders perfectly, returns 200, and has no working buttons.

**Retreat is one environment variable.** `DPC_CSP_ENFORCE=false` returns to report-only without a deploy, because a header that breaks a page in production and cannot be turned off is worse than an absent one. `'unsafe-inline'` is still not offered.

**One improvement came out of the extraction rather than being aimed at.** The Pre-DPC script built an option element by interpolating a cadre name into an HTML string; rewritten, it sets `textContent`. That was the one place on the screen where a database value would have been parsed as markup.

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
5. **PHPUnit** — **1006 tests / 3290 assertions**

### Why steps 3 and 4 exist, and what they cost to learn

**Step 4 is the most important lesson in this project.** The suite runs on in-memory SQLite; the application runs on MySQL. An M8 migration whose *derived* foreign-key name came out at 65 characters **passed 84 green tests and could not be created by MySQL at all** — MySQL refuses any identifier over 64 characters, SQLite has no such limit. The table had never existed in the development database, and four documents recorded the gate as green on a table count taken before the failure.

Two checks now close it: `SchemaIdentifierLengthTest` catches identifier length **on any engine** (reading foreign keys from the migration source, because SQLite reports inline constraints as unnamed), and `schema:verify-mysql` catches **everything else** by letting MySQL do the enumerating. It never touches the development database.

**Step 3** exists because three consecutive milestone finalisations needed a manual retry on `composer audit`. It retries a transport failure, **never** retries a finding, and **refuses a pass composer served from its local cache** — a green security gate that checked nothing current is worse than a failing one.

**A footnote on the gate itself, found on 2026-08-22.** `composer gate` had begun **timing out rather than failing**: the suite now takes about three minutes and Composer's default process timeout is 300 seconds for the whole script, so step 5 was killed mid-run with a Composer stack trace. A developer seeing that would reasonably read it as a broken test rather than a broken harness. `Composer\Config::disableProcessTimeout` is now the first line of both `gate` and `test`, as it already was of `dev`. Worth knowing because it is the failure mode a gate should never have: **not red, and not green.**

### Five tests worth knowing about

- **`ScreenSmokeTest`** renders every parameterless screen as all 7 identities and asserts **no 500**. It catches what per-screen tests cannot: a renamed variable, a moved partial, a composer that throws. Verified to bite.
- **`DpcProposalDetailPerformanceTest`** is an N+1 detector on the heaviest screen. **It is why the notification badge is not cached** — a cached value the layout renders makes a page issue a different query count on its second render, which the detector correctly flags. Blunting it to save one indexed `count()` was the wrong trade.
- **`AuthorizationCoverageTest`** enumerates every authenticated route and fails if one is neither role-gated nor on a reasoned allowlist. A new route is gated or the build is red.
- **`PublicIdentifierTest`** (new, §4.2) reads the router's own binding reflection and fails if a route-bound model with a `uuid` column does not bind on it. It exists because AD-8 was asserted in two documents while being false in four models, and nothing in a 939-test suite could see it.
- **`ViewsAreCspCleanTest`** (new, §9.1) reads Blade source, because the thing it guards is invisible to a request: a screen with an inline handler returns 200 and the button just does not work.

**The shape those last three share is worth naming.** Each asserts a property *across* the codebase rather than a behaviour *within* it, and each was written because a per-behaviour test could not have caught the defect that prompted it. When a decision applies everywhere, test it everywhere — enumerate from the router or the filesystem, never from a list somebody has to remember to add to.

---

## 12. Technical debt

**Closed 2026-08-19:** TD-2 (audit retry), TD-4 (timeline index), TD-5 (Pre-DPC lock ordering), TD-7 (audit UI), TD-9 (Excel templates), TD-10 (replay dry run), TD-13 (workflow version constant), TD-20 (recipient resolver), TD-21 (engine version on the run), TD-23, TD-24, TD-25, TD-26, TD-27.

**Closed 2026-08-22:** **TD-29** (CSP enforced — §9.1) and **TD-19** *properly* (AD-8 delivered in URLs — §4.2; it had been recorded as closed when only the column was filled).

**TD-1 is as closed as code can make it** — see below.

### Open, with the reason each is open

| # | Item | Why it is still open |
|---|---|---|
| **TD-6** | **No SMS or e-mail gateway** | Procurement, not engineering. **Without it no officer can complete OTP verification in production** — the highest-value outstanding item of any kind, and the one true blocker |
| **TD-1** | Evidence-table grants not yet *applied* | The control is a `GRANT` on a production database and cannot be applied from inside the application. **What was engineerable is now done**: the table list lives in `dpc.security.evidence_tables`, `php artisan security:evidence-grants` emits the SQL and `--verify` reports whether the running database actually refuses the writes. What remains is a DBA running it. See §13 |
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

1. **Procure a government SMS gateway** (TD-6). Longest lead time and the hardest blocker: OTP delivery fails without it, and OTP is how a signature is taken.
2. **Obtain the CSC eSign contract** (BD-4). Decides whether a PDF renderer is needed.
3. **Apply the evidence-table grants** (TD-1) — now a command, not a runbook:

   ```bash
   php artisan security:evidence-grants --user=odpc_app --host='10.%' --database=odpc
   #   … a DBA runs the emitted SQL, then:
   php artisan security:evidence-grants --verify
   ```

   **The one mistake to avoid is tightening it too far.** Revoking `UPDATE` outright on `dpc_calculation_runs` breaks every calculation, because completing a run is a single `UPDATE` of `status`; the same applies to a document, which legitimately gets published after it is generated. So `update` in the config is a **list of columns**, not a flag, and each list mirrors that table's own model guard.

   **The command exists because the runbook had already gone stale and nothing said so.** It was written in M5 and named two tables. M6 added allotments and schedules, M7 the proceeding with its corrections, holds and documents, M8 the review chain, M9 the circulation note: **twelve evidence tables, of which the runbook covered two.** Ten would have gone to production unprotected with the deployment checklist ticked, because a document cannot notice a migration. `EvidenceGrantsTest` now compares the list against every model that guards itself against `update`, so the next one cannot be forgotten.

   `--verify` is the half to run repeatedly rather than once. It attempts each write the grants should refuse, as whichever account the application actually connects as, and reports what the database did. Every probe carries `WHERE id = -1`, so no row can match — MySQL checks privileges before matching rows, which makes a refusal proof and a success harmless. Run it at every release, not only at first deployment: a grant can be widened later by an unrelated change.
4. **Capacity plan** (TD-8) for permanently retained import files and signed documents.
5. **Turn on the integrations** one flag at a time, confirming each at `/admin/integrations`.
6. **Point `DPC_CSP_REPORT_URI` at a collector.** The policy is enforced, so a violation now means a control has stopped working for a real user — the case you most want to hear about. Unset, violations go nowhere.

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

**The final phase added three more, and they rhyme.**

- **Driving the application in a real browser** found the Review-chain button that 403'd for the Commission (§4.3) — invisible to the suite, because a link that is never followed cannot fail.
- **Trying to reach a screen by its documented identifier** found that AD-8 was false in the four models that matter, and that a wrong identifier was silently answered with a different DPC (§4.2) — invisible to the suite, because every test builds its URLs with `route()`.
- **Writing a structural test for a deployment control** found that the control's own runbook had gone stale by ten tables (§13) — invisible to everything, because a document cannot notice a migration.

**The pattern in all three: the thing that was wrong was the thing everybody had already written down as right.** A recorded decision is a claim, and a claim nobody checks decays quietly. So when a decision applies across the codebase, spend the extra hour making a test enumerate it from the router, the filesystem or the schema — not from a list. And at least once before you ship, use the system the way a person will, in the thing a person will use.

The gate has never once proved the system was *right*. Keep both.
