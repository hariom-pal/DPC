# Change Register of 2026-08-24 — Implementation & Testing Report

*Updated 2026-08-25: the fourteen improvements this work raised have since been implemented too — §13 records what that changed and what it found. **§17 records a regression this register introduced, and its correction.***

**e-DPC Portal · Chhattisgarh Public Service Commission**

Twenty-three changes, requested as one register, implemented across the database, the backend, the frontend, validation, permissions, reports, tests and documentation. This report states what was done, what it changed, and what remains for the Commission to decide.

---

## 1. Status in one page

| | |
|---|---|
| **Register items** | 23 requested · **23 implemented** · 0 outstanding |
| **Migrations** | 14 new, all reversible — verified `up` and `down` on MySQL and SQLite |
| **Tables** | 73 (was 68): **7 created**, 2 dropped, 2 renamed |
| **Files** | 236 modified · 69 added · 7 deleted · 7 renamed |
| **Views** | 112 of 133 touched |
| **Language keys** | 1,466 per locale, 13 groups, English and Hindi at full parity |
| **Tests** | **1,159 passing**, 3,881 assertions — 0 failures, 0 skipped, and the suite leaves the disk unchanged |
| **PHPStan** | clean at the configured level, no baseline, no suppressions |
| **Pint** | clean |
| **`composer audit`** | no advisories |
| **`schema:verify-mysql`** | 63 migrations, 70 tables, built from zero |
| **Business questions raised** | 7 (BD-22 to BD-28) — none blocks a demonstration |

**Nothing in the register was implemented beyond what it asked for.** The fourteen improvements identified along the way were recorded in `SUGGESTIONS_AND_IMPROVEMENTS.md` and left unbuilt — then built on 2026-08-25 on instruction. See §13.

---

## 2. Completed items

| # | Item | How it is enforced |
|---|---|---|
| 1 | One Administrator, named **CGPSC Administrator**; Department Administrator removed | `Role::Admin` label; `Role::departmental()`; `RoleCoverageTest` |
| 2 | Users never belong permanently to a department; assignment carries Department, Role, Effective From, Effective To, Active Flag; transfer = deactivate + create; never duplicate a user | `user_department_assignments` + `unique(user_id, active_flag)`; `DepartmentAssignmentService`; `UserDepartmentAssignmentTest` |
| 3 | "Cadre" removed everywhere; the term is **Post** | Migration `360001`; zero occurrences in code, views, catalogue or docs |
| 4 | Department Post mapping: Department → Post → Pay Scale → Class → Promotion Duration → Active | `department_posts` + `promotion_duration_years`; `DepartmentPostRequest` |
| 5 | Pay Scale, Class and Promotion Duration auto-populate from the posts — **each from the post it describes** | `PostAttributeResolver` → `PostAttributes`; `pre-dpc/post-attributes`; request ignores posted values. Corrected 2026-08-25, see §17 |
| 6 | **Promotion From Post** / **Promotion To Post** replace Current/Feeder Post | Migration `360002` (columns); catalogue and every screen |
| 7 | Entire application bilingual; nothing hardcoded | `lang/{en,hi}` × 13 groups; `BilingualCoverageTest` (6 checks) |
| 8 | Every Pre-DPC has a DPC Year | `pre_dpc_proposals.dpc_year`, required, bounded to next year |
| 9 | One active approved Pre-DPC per Department + To Post + DPC Year; previous becomes inactive; history never deleted | `pre_dpc_active_exercise_unique_v2`; `PreDpcProposalService::supersedeIncumbent()`. **The From Post left the key on 2026-08-25** — see §17 |
| 10 | Unique Pre-DPC Number, `PDPC-2026-HED-0001` | `IdentifierSequenceService` + `series`; issued at draft |
| 11 | No standalone New DPC; only from an active approved Pre-DPC, inheriting eight facts | `pre_dpc_proposal_id` RESTRICT; `createFromPreDpc()` is the only path |
| 12 | Proposal Date always auto-generated | `Carbon::today()` in the service; not a request field |
| 13 | Commission Members master, with one member satisfying several categories | `commission_members`; `CommissionMemberMasterTest` |
| 14 | DPC starts with the CGPSC Chairman, who nominates a Commission Member as DPC Chairman | `chairman_eligible` + `scopeChairmanEligible()`; committee screen |
| 15 | Optimised selection, min 3 max 6 besides the Chairman, one member may cover several categories | `CommitteeSelectionService` (exact set cover); `CommitteeComplianceService` refuses on save |
| 16 | Configurable member level — equal to, or higher than, the promotion post | `MemberLevelRule` + `dpc.committee.member_level` |
| 17 | Promotion Candidate login for every Seniority List officer; reused if it exists; sees only own DPC, status, timeline, notifications and published result | `PromotionCandidateProvisioner`; `CandidateDpcController`; `PromotionCandidateTest` |
| 18 | Revisions: number never changes on return; full revision history; previous revisions read-only | `dpc_proposal_revisions` + `DpcProposalRevision::booted()`; `DpcRevisionService` |
| 19 | A genuinely new exercise gets a new DPC Number at revision 1 | Falls out of `createFromPreDpc()` — no separate rule |
| 20 | Documentation updated; all Cadre references removed | 4 handbooks; §13a added to the Architecture Handbook, §9a to the User Guide |
| 21 | This report | — |
| 22 | Suggestions & Improvements | `docs/SUGGESTIONS_AND_IMPROVEMENTS.md`, 14 items |
| 23 | Dead code, unused routes, obsolete columns, unused views, duplicate validation and logic removed | §7 below |

---

## 3. Database changes

### New tables (4)

| Table | Purpose | Notes |
|---|---|---|
| `user_department_assignments` | Item 2 — a posting is a dated row | `unique(user_id, active_flag)` with `active_flag` nullable; blameable; timeline-recorded |
| `service_classes` | Item 1 — Class Master | Seeded from `ServiceClass` in the migration itself, so a deployment that skips seeders does not blank existing labels. `code` validated against the enum |
| `commission_members` | Item 13 | Five independent category flags; nullable unique `user_id`; `chairman_eligible`; soft deletes |
| `dpc_proposal_revisions` | Item 18 | `unique(dpc_proposal_id, revision_number)`; model refuses updates outside `PERMITTED_UPDATES` and refuses deletion |

### Dropped tables (2)

`pre_dpc_posts` and `pre_dpc_post_feeders`. A Pre-DPC is now one post pair, so both child tables held exactly one row each. Existing rows were split into one Pre-DPC per pair before the drop — no data was discarded.

### Renamed table (1)

`cadres` → `department_posts`. MySQL keeps a constraint's derived name across a rename, so the migration also re-derives the table's own foreign keys and indexes; otherwise the migrated schema carries `cadres_created_by_foreign` on a table called `department_posts`.

### Modified tables (9)

| Table | Change |
|---|---|
| `users` | `+ employee_number` (unique). **Dropped `department_id` and `level`** — both replaced by the assignment |
| `departments` | none structurally; `users()` is now `HasManyThrough` the assignment |
| `recruitment_rules` | `cadre_id` → `post_id` |
| `recruitment_rule_feeders` | `feeder_cadre_id` → `promotion_from_post_id` |
| `reservation_rosters` | `cadre_id` → `post_id` |
| `department_posts` | `+ promotion_duration_years` |
| `pre_dpc_proposals` | `+ dpc_year, promotion_from_post_id, promotion_to_post_id, pre_dpc_number, active_flag`, terms columns; `− is_current` |
| `dpc_proposals` | `+ pre_dpc_proposal_id, dpc_year, pay_scale_id, class_level, promotion_duration_years, recruitment_rule_id, revision_number`; `post_to_cadre_id` → `promotion_to_post_id`; `post_from_cadre_id` → `promotion_from_post_id` |
| `dpc_seniority_entries`, `dpc_working_officers`, `dpc_evaluation_rows` | `feeder_post` → `promotion_from_post`, `current_post` → `promotion_to_post`, and the two posting-date columns to match |
| `dpc_committee_members`, `dpc_allotments` | `+ commission_member_id` |
| `dpc_id_sequences` | `+ series`; unique becomes `(department_id, series, year)` |

### Updated ER relationships

```
Department ─┬─< UserDepartmentAssignment >─┬─ User ─── CommissionMember (0..1)
            │        (active_flag)          └── DpcProposal.raised_by …
            │
            ├─< DepartmentPost ─┬─< RecruitmentRule (as post_id)
            │                   ├─< RecruitmentRuleFeeder (as promotion_from_post_id)
            │                   ├─< ReservationRoster
            │                   └─┬ PreDpcProposal.promotion_from_post_id
            │                     └ PreDpcProposal.promotion_to_post_id
            │
            └─< PreDpcProposal ──< DpcProposal ──< DpcProposalRevision
                 (one active per                    (one open at a time)
                  Dept+From+To+Year)                DpcCommitteeMember >── CommissionMember
                                                    DpcAllotment       >── CommissionMember

ServiceClass  — a label table. `class_level` columns still store the code; no foreign key,
                deliberately: turning 119 reads into joins would be churn on the scoring path.
```

**Two shapes a reader needs.** `active_flag` is `1` or `NULL`, never `0`, so a unique index including it reads as *at most one active row, any number of superseded ones* — NULLs do not collide. And `users` has no `department_id`: read `$user->department` (an accessor over the active assignment) and write through `DepartmentAssignmentService`.

---

## 4. Migration impact

**All nine migrations are reversible, and both directions were run on both engines.** MySQL is what production uses; SQLite is what the suite uses, and it implements several operations by rebuilding the table, which is where the differences bite.

| Concern | How it was handled |
|---|---|
| MySQL errno 1553 (dropping an index needed by a foreign key) | The replacement index is always created **before** the incumbent is dropped, in both directions. `down()` restores the old unique before dropping the new ones |
| Constraint names surviving a table rename | `renameOwnConstraints($on, from:, to:)` re-derives them; `$on` is the name the table answers to *now*, which in `down()` is still the new one |
| SQLite refusing `dropColumn` alongside other work | Three separate `Schema::table()` calls: drop the foreign key, then the index, then the column |
| Backfilling a link that cannot be known | `dpc_proposals.pre_dpc_proposal_id` is left **null** for pre-existing proposals. A guess would be authoritative-looking and unfalsifiable. Raised as improvement #3 |
| Existing sequence counters | `seedNumberSequences()` hands the highest existing serial to `dpc_id_sequences`, so no number is ever reissued |

**Deployment order matters in one place.** `360006` (Pre-DPC reshape) splits existing orders into one row per pair and must run before `360007` links DPCs to them. The numeric prefixes enforce this.

**Downtime.** The renames are metadata operations; the backfills touch one row per existing proposal. On a database of this size the whole set runs in a few seconds. No table is rewritten.

---

## 5. Screens modified

| Area | Screens |
|---|---|
| **New (4)** | Class Master (list, form) · Commission Members (list, form) · User Department Assignments · My DPC (candidate list, candidate detail) |
| **Reshaped (3)** | Pre-DPC form — one post pair, DPC Year, derived attributes, terms · DPC form — Pre-DPC selector replaces the post pickers · DPC Committee — draws from the Commission Members master, states the rules in force, offers the optimiser's proposal |
| **Retermed (12)** | Every screen showing a post pair or a posting date: seniority list, working officers, evaluation chart, review walkthrough, proceedings, circulations, self-inspection, reports, recruitment rules, imports |
| **Rebuilt (1)** | Public landing page — every string from the catalogue, role cards iterate the catalogue, "Department Administrator" removed, Promotion Candidate added |
| **Translated (113)** | Every view that renders text, except the 13 under `resources/views/documents/` (BD-24) |

**Removed:** the Pre-DPC post/feeder repeater (`public/js/forms/pre-dpc-posts.js` and its partials) — there is no list to repeat.

---

## 6. Validation changes

| Request | Change |
|---|---|
| `SavePreDpcProposalRequest` | `dpc_year` required, integer, ≤ next year · both post ids required, must be **active posts of the caller's department**, and must differ · derived attributes **prohibited** from the payload · shares must total exactly 100 |
| `SaveDpcProposalRequest` | `pre_dpc_proposal_id` required on create, must be an **active approved** order of the caller's department · every inherited field prohibited · `proposal_date` prohibited · on update only `dpc_type_id` and `legal_stay` are accepted |
| `SaveDpcCommitteeRequest` | Size checked against the **committee type's** configuration (was mistakenly the DPC type's) · exactly one Chairman · no duplicate user, member, mobile or e-mail · member level enforced · required categories enforced · `commission_member_id` resolved server-side, so a spoofed display name is ignored |
| `CommissionMemberRequest` | Name required · `user_id` unique across the master and limited to Commission accounts · a post must belong to the named department · at least one of post or post title |
| `AssignUserRequest` | Department and role required · role must be departmental · refuses re-posting to the same department in the same role, naming it · effective-from cannot precede the current posting |
| `ClassMasterRequest` | `code` validated against `ServiceClass` and immutable after creation · bilingual name · sort order |
| `LoginRequest` | Identifier accepts e-mail, a 10-digit mobile, or an employee number |

**One duplicate removed:** the committee size rule existed in both the request and the compliance service, disagreeing about whether the bound included the Chairman. `CommitteeRuleResolver::besidesChairman()` is now the single answer and both call it.

---

## 7. Dead code removed (item 23)

| Removed | Why it was dead |
|---|---|
| `app/Enums/UserLevel.php` | Level was a column on `users`; the assignment carries the role |
| `DpcIdGeneratorService` | Superseded by `IdentifierSequenceService`, which serves both series |
| `PreDpcPost`, `PreDpcPostFeeder`, `FeederPostResolver` | Their tables are gone |
| `ReportUnmatchedPreDpcFeedersCommand` + its test | A Pre-DPC now names registered posts by id; nothing can fail to match |
| `config('dpc.pre_dpc.refuse_unmatched_feeder')` | The behaviour it governed no longer exists. Read by nothing |
| `public/js/forms/pre-dpc-posts.js` | The repeater it drove is gone |
| `users.department_id`, `users.level` | Replaced by the assignment |
| `pre_dpc_proposals.is_current` | Replaced by `active_flag`, which the unique index can use |
| Stale docblock on `SendDocumentPublicationNotification` | Claimed a query was "deliberately duplicated"; TD-20 closed that in August |
| 2 unused catalogue keys, 4 stopgap keys | Added during extraction, then superseded |

**Verified as *not* dead** and therefore left alone: five console commands (registered by signature, not referenced by name), `x-status-badge` (a Blade component), the legacy import column aliases (improvement #11), and every route — a check confirmed each route's action resolves to a real method.

**Storage — and the reason it kept refilling.** 2,262 files were examined. All 2,171 under `storage/app/private` were orphans: `dpc_proceeding_documents`, `dpc_circulation_documents`, `signatures` and `import_batches` are all empty in the development database, so no row referenced any of them. They were removed, the framework caches cleared, and the 22 MB development log truncated.

**Then they came back**, which turned out to be the more useful finding. Ten test files faked the `local` disk and six did not, so every run of a document, signature or notification test wrote real files into `storage/app/private` — a hundred per run of one class. `Storage::fake('local')` now lives in `Tests\TestCase::setUp()` alongside the Class Master memo flush, for the reason already written there: a thing that has to be remembered per test is a thing that will be forgotten. The sixteen now-redundant per-test calls were removed so there is one mechanism rather than two.

The disk leak mattered for more than tidiness. A test asserting that a document exists could be satisfied by a file the *previous* run left behind — a false pass in exactly the tests that guard evidence integrity. After the fix the full suite leaves `storage/app/private` byte-for-byte as it found it, and `storage` holds only the framework's compiled views and one empty log.

**No tracked file and no referenced document was touched.**

---

## 8. API changes

The portal exposes no public API. Two internal endpoints changed:

| Endpoint | Change |
|---|---|
| `GET pre-dpc/post-attributes` | **New.** Given a from-post and a to-post, answers with the Pay Scale, Class and Promotion Duration item 5 derives. Departmental roles only; answers nothing for a post of another department. Declared **before** `pre-dpc/{preDpc}` or the wildcard swallows it |
| `GET my-dpc`, `GET my-dpc/{uuid}` | **New.** `role:promotion_candidate`. `show` takes a string and resolves it **only** from the candidate's own forwarded proposals — there is no route-model binding to be talked out of, and anything else is a 404 |

Every aggregate is addressed by UUID (AD-8), including the new ones.

---

## 9. Testing

### The suite

**1,087 tests, 3,586 assertions, all passing.** Ten test files were added:

| File | What it pins |
|---|---|
| `Admin/UserDepartmentAssignmentTest` | Posting, transfer, release; the unique index; role re-projection; a back-dated transfer refused |
| `Admin/ClassMasterTest` | Code immutability, validation against the enum, bilingual labels, the per-request cache |
| `PreDpc/PreDpcExerciseTest` | Year bounds, number format and sequence, the active-exercise index, amendment supersession, post-pair validation, the attributes endpoint |
| `Committee/CommissionMemberMasterTest` | The master, its validation and its scopes |
| `Committee/CommitteeSelectionTest` | That the optimiser is exact rather than greedy; that it prefers one member covering three requirements; the level rule in all three settings; that a member with no post is never excluded; enforcement on save |
| `Candidate/PromotionCandidateTest` | Provisioning and reuse; that a candidate sees own DPC, status and timeline; that another candidate's DPC is a 404; that a result appears only after publication. **All five things item 17 forbids are now pinned by a rendered-HTML assertion** — other candidates, the committee, anybody's marks, internal remarks, and every departmental or Commission screen |
| `Dpc/DpcSeniorityListReuploadTest` | That re-uploading a list does not orphan a candidate account |
| `BilingualCoverageTest` | Six checks — see below |
| `Concerns/RaisesDpcProposals` | The shared helper for raising a DPC under a Pre-DPC |

**`BilingualCoverageTest` is the one worth describing**, because it took three attempts to make honest:

1. Key parity between locales — a missing Hindi key silently falls back to English.
2. No key a view renders resolves to an array — that once turned every 403 page into a 500.
3. Every view compiles — a Blade template can parse and still be invalid PHP.
4. No element text is a hardcoded sentence.
5. No prose literal in a `@php` block or directive argument.
6. **No screen renders a word that did not come from the catalogue** — the view is compiled, every PHP island deleted, and what is left inspected. What is left is exactly the text the page prints without asking the catalogue.

Checks 4 and 5 passed while **283 strings were still hardcoded**, because they read the template rather than its output and could not see a label ending in a required-field asterisk, a nav link, or a sentence broken across a `<strong>`. A check that certifies unfinished work as finished is worse than no check; check 6 is what replaced that certainty with a fact.

**One assertion set was added after the register was otherwise complete**, and the reasoning is worth recording. Item 17 forbids five things by name; three were pinned by asserting the rendered page does *not* contain them, and two — the committee and anybody's marks — were not. On today's code `CandidateDpcController::show()` cannot leak either: it loads the proposal, the candidate's own entry, a whitelisted timeline and the published result, and never touches `dpc_committee_members` or a calculation result. That is the argument *for* the test rather than against it — the two unpinned categories were the two nobody would notice becoming visible, and a `with()` added for a status badge would do it.

The test builds a DPC that genuinely holds a committee and two officers' scores, renders the candidate's page, and asserts none of it appears. **It was then verified to fail**: temporarily echoing the committee into the view turned it red, and removing the echo turned it green. A negative assertion that has never been seen to fail is not evidence of anything.

### The full regression

Every module, workflow, role, approval flow, report and permission was exercised:

| Area | Result |
|---|---|
| Authentication, OTP, password change, three-way identifier | pass |
| Registration and Commission approval | pass |
| Department assignment, transfer, release | pass |
| All masters, including the two new ones | pass |
| Pre-DPC lifecycle: draft → submit → approve → amend | pass |
| DPC lifecycle: raise → five sections → submit → return → approve → forward | pass |
| Revisions across a return | pass |
| Committee composition, optimiser and compliance | pass |
| Imports: preview → validate → commit, all three templates | pass |
| Scoring engine, zone of consideration, Super Seat | pass — untouched, and verified untouched |
| Allotment, scheduling, proceeding, corrections, result | pass |
| Review DPC chain and supersession | pass |
| Circulation and held-promotion release | pass |
| Documents, hashing, signing, publication | pass |
| Candidate portal, including negative cases | pass |
| Self-Inspection | pass |
| Reports and CSV export | pass |
| Notifications and the bell | pass |
| Permission matrix — every authenticated route against every role | pass |
| Every parameterless screen, **in both locales** | pass |
| CSP cleanliness | pass |
| Evidence-table immutability and the generated grants | pass |
| Performance budgets on the proposal detail screen | pass |

### Defects found during this work, and fixed

| Defect | Consequence had it shipped |
|---|---|
| Three demo seeders still queried the dropped `users.department_id` | `demo:seed` died on a fresh database — the demonstration was unbuildable |
| `CommitteeRuleResolver` keyed the configuration on the **DPC type** rather than the committee type | Committee size validated against the wrong master row |
| Seeded committee bounds were 1–5, not the register's 3–6 | Item 15's rule not in force on a fresh installation |
| The landing page still named a **Department Administrator** | Item 1 contradicted on the portal's most-read page |
| Class Master label cache was per-process, not per-request | A stale label across requests under Octane or a queue worker |
| `CandidateDpcController` read a non-existent `DocumentStatus::Published` | A candidate shown their result the moment the document existed, before publication |
| `ui.dashboard` was both a leaf and a branch | Every 403 page rendered a 500 |
| Two views held HTML inside a PHP string literal | Templates that parsed as Blade and failed as PHP |
| An HTML entity double-escaped in a button label | `Approve, Sign &amp; Forward` shown to the Administrative Head |
| Hindi timeline labels rendered as raw keys | A dot in a key is a nesting step; the keys now use `_` |
| 283 strings hardcoded across 65 views, invisible to two passing tests | Item 7 incomplete while reported complete |
| Six test files wrote **real files** into `storage/app/private` — 100 per run of one class | A document test could be satisfied by a file the *previous* run left behind. An evidence-integrity test passing on yesterday's evidence is not testing anything |

---

## 13. The improvement register (2026-08-25)

All fourteen proposals in `SUGGESTIONS_AND_IMPROVEMENTS.md` were implemented on instruction. Each carries an **As built** note there. Four differ from what was proposed, and two live defects came out of building them.

### Two defects found

| Defect | Consequence had it shipped |
|---|---|
| Matching a candidate account on `employee_number` alone **re-posted the officer**: a second department's upload retired the officer's real posting and stripped their departmental role, and let one account see both officers' Seniority List entries | A serving officer transferred out of their own department by another department's data entry, and the disclosure item 17 exists to prevent |
| `saveSeniorityEntries()` **discarded** the provisioning result, so every candidate's temporary password was generated, hashed and thrown away | Item 17's login was unusable in production. Delivered, tested, documented and dead — no test noticed, because every fixture sets its own password rather than using a generated one |

### Four that could not be built as written

- **#1** — the proposed `unique(department_id, employee_number)` contradicts items 2 and 17, which both forbid duplicate users. The portal cannot tell a transfer from a collision by looking at a number, so it now assumes neither and reports (**BD-27**).
- **#4** — the proposed OTP reset had no channel: a candidate account has no mobile and no e-mail, because a Seniority List carries neither. The department reissues instead, guarded by the proposal's own list rather than by a role.
- **#7** — `recruitment_rule_promotion_sources` yields a 65-character constraint name against MySQL's 64. The table is `recruitment_rule_sources`.
- **#14** — was "no change", and remains none.

### What it added

| | |
|---|---|
| **New tables (1)** | `portal_notices` — the landing page's notice board, as data |
| **Renamed tables (1)** | `recruitment_rule_feeders` → `recruitment_rule_sources` |
| **New screens (2)** | Notice Board (list, form) · Sanctioning orders not recorded |
| **New commands (1)** | `lang:check` — which found three dead catalogue keys on its first run |
| **New routes (5)** | notice board resource, `pre-dpc-links` (2), committee `align-bounds`, candidate `reissue-password` |
| **Catalogue** | 64 dual-language captions split; 3 mastheads deliberately left bilingual |
| **Tests** | +37 (1,087 → 1,124) |

### Business question raised

**BD-27** — is the same employee number in two departments one transferred officer, or two officers? Recorded with a recommendation; the code assumes neither and nothing is blocked on the answer.

---

## 14. Business questions requiring clarification

Seven, recorded in full in `CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`. **None blocks a demonstration and none blocks a published promotion list.** In each case the code does the safe thing and says which thing it did.

| # | Question | Current behaviour |
|---|---|---|
| **BD-22** | Which member categories *must* a DPC committee represent? | `required_categories` ships **empty** — the optimiser advises, nothing is refused on a rule nobody has stated |
| **BD-23** | Must a member's post be *equal to* or *higher than* the promotion post? | `equal`. A member with no registered post is permitted under every setting, because there is no class to compare |
| **BD-24** | Should the **signed** documents be bilingual? | English, as before. `views/documents/` is exempt from translation — the language a government order is issued in is the Commission's decision |
| **BD-25** | May a Promotion Candidate see their **own** marks? | No. Item 17 forbids other officers' marks and is silent on the candidate's own; a list of exclusions that omits a thing is not permission |
| **BD-26** | Should the service class **codes** be administrable? | No. The scoring engine keys zone rules on class pairs; a runtime code would fall through to the uncapped default |
| **BD-27** | Is the same employee number in two departments one officer, or two? | Neither is assumed. Nothing is created, nothing is moved, and the clash is reported for the Administrator |
| **BD-28** | When an order sanctions several feeder posts, is that one DPC or several? | One DPC per feeder — unchanged from before the order became plural, and the only reading the engine's class-pair zone rules can express |

Two earlier questions are also affected: **BD-18** (who populates a department's posts first) is now half-answered — the master belongs to the CGPSC Administrator — and **BD-19** (how an individual officer signs in) now has a second answer alongside Self-Inspection, because a candidate has a real account.

---

## 15. Files modified

**236 modified · 69 added · 7 deleted · 7 renamed.** By area:

| Area | Added | Modified |
|---|---|---|
| Migrations | 9 | — |
| Domain models | 4 | 14 |
| Domain services and support | 10 | 15 |
| Requests | 3 | 8 |
| Enums | 2 | 5 |
| Value objects and concerns | 2 | — |
| Controllers | 4 | 13 |
| Routes, config, providers, middleware | — | 6 |
| Views | 8 | 107 |
| JavaScript | 2 | 5 |
| Language files | 24 | — |
| Seeders | — | 8 |
| Tests | 9 | 51 |
| Docs | 2 | 5 |

**Deleted (7).** `UserLevel`, `DpcIdGeneratorService`, `PreDpcPost`, `PreDpcPostFeeder`, `FeederPostResolver`, `ReportUnmatchedPreDpcFeedersCommand`, and two tests that covered removed behaviour.

**Renamed (7).** `Cadre` → `DepartmentPost` in the model, request, controller, seeder and its two views; `pre-dpc-posts.js` removed with the repeater it drove.

**The whole language catalogue is new** — 24 files, 1,466 keys per locale — because the portal had no `lang/` directory before item 7.

Two archive files in the repository root — `app.zip` and `review.zip` — predate this work, are untracked, and were left alone.

---

## 16. Remaining items

**None.** All 23 register items and all 14 improvements are implemented, tested and documented.

What is outstanding is outstanding by design: **six business decisions** (BD-22 to BD-27), each with a stated recommendation, and five of the six applied by changing a setting rather than by writing software.

---

## 17. A regression this register introduced, and its correction (2026-08-25)

Two faults, reported together by the Commission, both traceable to how item 9's
uniqueness key was read while implementing items 8–11. They are recorded here in full
because a register that only lists what it delivered is not a record of what happened.

### 17.1 What was lost

Item 9 states the key as *Department + From Post + To Post + DPC Year*. That was read as
**one post pair per Pre-DPC**, and the two child tables that carried the alternative —
`pre_dpc_posts` and `pre_dpc_post_feeders` — were collapsed into a single pair of columns
on the order.

**Schedule-IV does not work that way.** A post is routinely filled from several feeders
with a share each: *Deputy Director* from Assistant Director, Senior Lecturer **and**
Research Officer, under one Government Order. After the reshape such an order could not
be recorded at all — the second feeder was refused by the very index item 9 asked for.

The second fault was its display half. `PostPairAttributes` answered *"what do these two
posts determine together"* and read the Pay Scale and Class off the **Promotion To
Post** — so the figure printed beside a feeder described the post nobody had been
promoted into yet. Promotion eligibility is judged on the officer's **current** post,
which makes the feeder's own scale and class the ones a reader needs.

### 17.2 What was restored

| | |
|---|---|
| **New table** | `pre_dpc_promotion_sources` — one row per Promotion From Post, carrying its share, its qualifying service, its minimum qualification and its own promotion duration |
| **Exercise key** | `(department, promotion_to_post, dpc_year)` — `pre_dpc_active_exercise_unique_v2`, and `pre_dpc_exercise_version_unique_v2` for versions |
| **Columns moved off the order** | `promotion_from_post_id`, `min_experience_years`, `experience_unit`, `qualification_id`, `promotion_duration_years` |
| **Columns deliberately left on the order** | `pay_scale_id`, `class_level` — they describe the post being *filled*, and `MemberLevelRule` reads `class_level` as the promotion post's class (item 16). Moving them would have silently changed who may sit on a committee |
| **Renamed** | `PostPairAttributes` → `PostAttributes`; `PostAttributeResolver::resolve()` now answers **per post**, with `resolveMany()` for a form full of them |
| **Endpoint** | `pre-dpc/post-attributes` takes `post_ids[]` (or a single `post_id`) and returns `{"posts": [...]}`, each post answering from its own Department Post Mapping |
| **Form** | a repeating **Promotion From Posts** table — add, remove, and each row showing that post's own Pay Scale and Class, rendered server-side first so it is right with JavaScript disabled |
| **DPC creation** | a Promotion From Post selector, filtered to the chosen order's sources. A DPC still runs **one** feeder — the seniority list, the qualifying service and the scoring engine are all per feeder — so it is chosen when the DPC is raised, and `DpcProposalService::sourceFor()` refuses to guess when an order names several |

### 17.3 Three defects found while correcting it

| Defect | Consequence had it shipped |
|---|---|
| The migration dropped `promotion_from_post_id` while `pre_dpc_exercise_version_unique` still named it. **MySQL silently removes a dropped column from a composite index; SQLite refuses the drop outright** — so the migration ran in development and failed in the test suite. Both indexes are now swapped explicitly | A migration that passes on the developer's machine and fails on any SQLite deployment, discovered at deploy time |
| `PreDpcProposal::$fillable` had been reconstructed after an editing accident and lost `approved_at`, `approved_by`, `signature_id` and the other workflow fields. Production paths were unaffected — the service sets every one of them with `forceFill` after a `lockForUpdate` and a signature — but it went unnoticed because no test asserted it | Latent. Surfaced as 246 fixture failures, all from one line; the narrow `$fillable` was kept and the fixtures made to say `forceCreate`, because *the fields that give an order the force of a Government Order must not be settable from a payload* |
| `amend()` mass-assigned `active_flag`, which is not fillable and must not be | Amending an approved Pre-DPC threw. Now set with `forceFill`, matching how the rest of the service treats the item 9 flag |

### 17.4 One business question this raised

**BD-28 — when an order sanctions several feeder posts, is that one DPC or several?** The portal does what it has always done: one DPC per feeder, chosen when the DPC is raised. That was not a decision taken here — the seniority list, the qualifying-service test and the scoring engine are all defined per post, and nothing in the decks describes ranking two feeder posts against each other. Recorded in full in `CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`, with a recommendation. Nothing is blocked on the answer.

### 17.5 What was **not** changed

No business rule was added, removed or reinterpreted. In particular:

- **The scoring engine was not touched.** `DpcCalculationService` reads `class_level`
  from the two post models directly, not from the proposal, so the display correction
  cannot move a single mark.
- **A DPC still runs exactly one Promotion From Post.** Restoring the plural on the
  *order* does not make a DPC plural; it makes the order able to say what the Government
  Order says.
- **The shares-total-100 rule is not new.** It is the rule the Recruitment Rule master
  already enforces, applied to the sources of an order for the same reason: the engine
  fills promotion seats from these shares.

### 17.6 Evidence

| | |
|---|---|
| **Migration** | `2026_08_25_390001_restore_multiple_promotion_sources_on_pre_dpc` — `up` and `down` both verified on MySQL, `up` on SQLite through the suite |
| **New tests** | `PreDpcExerciseTest`: shares must total 100, a post cannot be named twice, an order must name at least one, each source keeps its own post's terms, two feeders are one order · `PreDpcWorkflowTest::test_one_order_may_name_several_promotion_from_posts` — end to end over HTTP, through to the projected Recruitment Rule |
| **Demo** | `demo:seed` rebuilt from an empty database: `PDPC-2026-SE-0002` (Lecturer) names **two** feeders at 50% each, and each shows its own Class III / Level-10 while Lecturer itself is Class II / Level-11 |
| **Gate** | 1,159 tests passing · PHPStan clean · Pint clean · `composer audit` clean |

---

*Prepared 2026-08-24, updated 2026-08-25. Gate at time of writing: 1,159 tests passing, PHPStan clean, Pint clean, no security advisories, MySQL schema built from zero, and `demo:seed` rebuilding from an empty database.*
