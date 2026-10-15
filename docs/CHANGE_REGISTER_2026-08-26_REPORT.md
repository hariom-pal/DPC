# Change Register of 2026-08-26 — Implementation & Testing Report

**e-DPC Portal · Chhattisgarh Public Service Commission**

Thirteen items were requested in writing. Six more were added in clarification
during the work, and three of the written items **reverse or reshape decisions taken
two days earlier**. This report states what was done, what it changed, what it cost
elsewhere, and what remains for the Commission to decide.

---

## 1. Status in one page

| | |
|---|---|
| **Register items** | 13 written + 6 clarified · **19 implemented** · 0 outstanding |
| **Migrations** | 6 new, all reversible — `up` and `down` verified on MySQL, `up` on SQLite through the suite |
| **Tables** | 75 (was 73): 4 created, 2 dropped |
| **Tests** | **1,210 passing**, 4,013 assertions — 0 failures, 0 skipped (was 1,159) |
| **PHPStan** | clean at the configured level, no baseline, no suppressions |
| **Pint** | clean |
| **`composer audit`** | no advisories |
| **`schema:verify-mysql`** | 74 migrations, 75 tables, built from zero |
| **`demo:seed`** | rebuilds from an empty database |
| **Language catalogue** | English and Hindi at full parity, `lang:check` clean |
| **Business questions raised** | 3 (BD-29 to BD-31) — none blocks a demonstration |

**Nothing was implemented beyond what the register asked for.** One improvement was
identified and deliberately left unbuilt: searching committee members by Employee ID,
recorded as item 15 in `SUGGESTIONS_AND_IMPROVEMENTS.md` on instruction.

---

## 2. Completed items

| # | Item | How it is enforced |
|---|---|---|
| 1 | Transactional data cleared; masters, users and configuration kept | `dpc:reset-transactional-data`; table lists re-classified for the new schema; `ResetTransactionalDataTest` |
| 2 | Demo user report with every named field | `demo:credentials`; `docs/demo/DEMO_LOGIN_CREDENTIALS.{md,pdf}` |
| 3 | Pay Scale and Class shown for the **Promotion From Post** | `dpc-proposals/form`, `pre-dpc/show`; the source rows already carried it |
| 4 | Multiple feeder posts | `pre_dpc_promotion_sources` (restored 2026-08-25); verified end to end |
| 5 | Commission Member Master removed | Migration `400003`; model, controller, request, views, routes, seeder and 2 test classes deleted |
| 6 | Member search by e-mail/mobile, with duplicate detection | `CommitteeMemberDirectory`; `committee/search` endpoint; `dpc-committee.js`; resolved again server-side |
| 7 | Committee membership is per-DPC and grants no role | No `assignRole()` on any committee path; `test_sitting_on_a_committee_grants_no_role` |
| 8 | Six composition attributes on the assignment | `dpc_committee_members.is_*`; `CommitteeComplianceService::uncovered()` |
| 9 | CGPSC Chairman mandatory and automatic | `capacity = cgpsc_chairman`, seated by `DpcCommitteeService`; AO cannot submit or remove it |
| 9b | Admin maintains the CGPSC Chairman, audited | `cgpsc_chairmen` (effective-dated); `CgpscChairmanService`; `Auditable` + blameable + timeline |
| 9c | CGPSC Chairman designates the DPC Chairman | `is_dpc_chairman`; `unique(dpc_proposal_id, is_dpc_chairman)` |
| 9d | DPC Chairman allots; deadline; return on expiry | `assertMayAllot()`, `complete_by`, `AllotmentStatus::Returned`, `dpc:return-expired-allotments` |
| 9e | Admin replaces a member at any stage | `replaces_id` / `replaced_at` / `replaced_by` / `replacement_reason`; `active_flag` |
| 9f | Multi-role users; active role gates the session | `ActiveRoleService` + `ResolveActiveRole` middleware |
| 10 | Multiple Government Orders per mapping | `department_post_orders`; repeater with Add More |
| 11 | Multiple qualifications | `rr_source_qualifications`, `pre_dpc_source_qualifications` |
| 12 | Documentation updated | 4 handbooks: §13c, §12b, §9b, BD-29 to BD-31 |
| 13 | This report | — |
| — | Employee ID search **recorded, not built** | `SUGGESTIONS_AND_IMPROVEMENTS.md` §15 |

---

## 3. Database changes

### New tables (4)

| Table | Purpose | Notes |
|---|---|---|
| `cgpsc_chairmen` | Item 9b | Effective-dated chain; `unique(active_flag)` means *at most one serving* |
| `department_post_orders` | Item 10 | One row per Government Order — number, date and its own PDF |
| `rr_source_qualifications` | Item 11 | Recruitment-rule source ↔ qualification |
| `pre_dpc_source_qualifications` | Item 11 | Pre-DPC source ↔ qualification |

### Dropped tables (2)

`commission_members` (item 5) and `department_post_documents` (item 10, replaced by
`department_post_orders`).

### Modified tables (4)

| Table | Change |
|---|---|
| `dpc_committee_members` | `+ is_sc, is_st, is_obc, is_female, is_bhar_sadhak_sachiv, is_vibhag_sachiv, is_dpc_chairman, active_flag, replaced_at, replacement_reason, replaced_by, replaces_id`; `− commission_member_id`; unique becomes `(dpc_proposal_id, user_id, active_flag)` plus `(dpc_proposal_id, is_dpc_chairman)` |
| `dpc_allotments` | `+ complete_by, returned_at, return_reason`; `− commission_member_id`; index on `(status, complete_by)` |
| `department_posts` | `− government_order_number, government_order_date` (moved to the orders table) |
| `recruitment_rule_sources`, `pre_dpc_promotion_sources` | `− qualification_id` (moved to the pivots); `min_qualification` kept |

### Two shapes a reader needs

**`active_flag` is `1` or NULL, never `0`** — on `cgpsc_chairmen` and on
`dpc_committee_members`, as it already was on three other tables. A unique index
including it reads as *at most one active row, any number of retired ones*, because
NULLs do not collide.

**`is_dpc_chairman` is a flag, not a capacity.** The register lets the CGPSC Chairman
conduct a DPC themselves; as a capacity that person would need two contradictory
values on one row.

---

## 4. Migration impact

All six are reversible and both directions were run on MySQL; `up` runs on SQLite
through the suite.

| Concern | How it was handled |
|---|---|
| MySQL errno 1553 (dropping an index a foreign key needs) | The replacement unique index is created **before** the incumbent is dropped, in both directions |
| MySQL's 64-character identifier limit | Both new pivots name their foreign keys explicitly. `pre_dpc_source_qualifications_pre_dpc_promotion_source_id_foreign` is exactly 64 and its sibling is over — caught by `SchemaIdentifierLengthTest`, which is why that test exists |
| Carrying the old single Government Order forward | `400005` folds the mapping's number/date and every document version into order rows, handling three cases explicitly: number+file, number without file, and file without number (which gets a row saying so rather than an invented citation) |
| Carrying the old single qualification forward | `400006` writes one pivot row per existing `qualification_id`, then drops the column |
| Reading the old `chairman` capacity | `400001` maps it to `member` + `is_dpc_chairman`, which is what it actually meant — writing those rows up to `cgpsc_chairman` would have put a person on a seat they never held |
| Reversibility that cannot be complete | `down()` on `400003` rebuilds `commission_members` but does **not** repopulate it, and says so: the rows were a list of people, and reconstructing them from per-DPC facts would invent standing. `400005` and `400006` restore one order and one qualification respectively, and state the loss |

**Deployment order matters.** `400001` moves the attributes before `400003` removes
the table they came from. The numeric prefixes enforce it.

---

## 5. Screens modified

| Area | Screens |
|---|---|
| **New (3)** | CGPSC Chairman (list + form) · Replace committee member |
| **Removed (2)** | Commission Members (list, form) |
| **Reshaped (3)** | DPC Committee — search/create, six attributes, read-only Chairman seat, substitution history · Department Post Mapping form — Government Orders repeater with Add More · Department Post Mapping details — every order with its own integrity check |
| **Changed (5)** | Allotment screen — completion date, overdue badge · Committee section — attributes, DPC Chairman badge, designate and replace controls · DPC form — Pay Scale/Class of the From Post · Pre-DPC show — feeder figures · Recruitment rule + Pre-DPC forms — multi-select qualifications |
| **Header** | *Acting as* switcher, shown only to accounts holding more than one role |

Catalogue: `dpc.committee.*` rewritten, `admin.cgpsc_chairman.*` added,
`admin.commission_members.*` removed, plus keys for the deadline, the orders repeater
and the role switch. English and Hindi at parity throughout.

---

## 6. API changes

The portal exposes no public API. Internal endpoints:

| Endpoint | Change |
|---|---|
| `GET dpc-proposals/{dpcProposal}/committee/search?q=` | **New.** Item 6's lookup. Returns only what the screen shows, not a serialised user |
| `POST cgpsc/dpc-proposals/{dpcProposal}/committee/{member}/designate-chairman` | **New.** `role:chairman` — narrower than the group it sits in |
| `GET/POST admin/dpc-proposals/{dpcProposal}/committee/{member}/replace` | **New.** `role:admin`, any stage |
| `POST acting-as` | **New.** Role switch. A POST, so it cannot be triggered by following a link |
| `POST cgpsc/allotments/{dpcProposal}` and `…/reallot` | `complete_by` now **required** |
| `GET admin/department-posts/{post}/orders/{order}` | Renamed from `…/documents/{document}` |
| `admin/commission-members/*` | **Removed** |

---

## 7. Validation changes

| Request | Change |
|---|---|
| `SaveDpcCommitteeRequest` | Capacity limited to `member` — the Chairman's seat cannot be submitted · six attribute booleans · duplicates checked on `user_id`, **normalised** mobile and lower-cased e-mail · compliance run against the list *plus* the seat the system will add |
| `ReplaceCommitteeMemberRequest` | **New.** Reason required (min 5 chars); attributes optional and inherited when omitted |
| `CgpscChairmanRequest` | **New.** Appointment accepts `user_id` and `effective_from`; amendment accepts neither — a succession must open a new tenure rather than rewrite one |
| `AllotDpcRequest` | `complete_by` **required**, `after_or_equal:today`, `after_or_equal:default_dpc_date` |
| `DepartmentPostRequest` | `orders[]` repeater, max 20; number and date `required_with` each other, per row; PDF checked three ways per row |
| `RecruitmentRuleRequest`, `SavePreDpcProposalRequest` | `qualification_ids[]` replaces `qualification_id` |

Two guards worth naming: `SchedulingService::assertWithinCompletionDate()` refuses a
sitting past the deadline (rescheduling is bounded, not unbounded), and
`AllotmentService::assertMayAllot()` refuses a Member who is not this DPC's Chairman.

---

## 8. Testing

**1,210 passing, 4,013 assertions.** Baseline was 1,159. The net is +51 across
+63 new tests and −24 that covered removed behaviour
(`CommissionMemberMasterTest`, `CommitteeSelectionTest`).

### New test classes (5)

| File | What it pins |
|---|---|
| `Committee/CgpscChairmanTest` | Appointment, succession closing the incumbent's tenure, `unique(active_flag)`, back-dating refused before the predecessor, future appointment refused, amendment unable to change person or start date, `servingOn()` for a past date, **appointing grants no role**, no destroy route |
| `Auth/RoleSwitchTest` | That the active role **gates** — the same account is refused a departmental screen while acting as a Member and granted it after switching back; a stale session naming a withdrawn role falls back rather than re-opening the union; POST-only; switching audited; a no-op switch records nothing |
| `Dpc/DpcChairmanDesignationTest` | Designation from this committee only, the CGPSC Chairman taking it themselves, designation moving rather than accumulating, surviving a re-compose, refused for a replaced member, refused to a Member and to a department officer |
| `Dpc/CommitteeReplacementTest` | Outgoing member kept and marked, successor inheriting attributes, reason required, replacement **after submission**, replaced rows surviving a re-compose, admin-only |
| `Cgpsc/AllotmentDeadlineTest` | Deadline required and immutable, only this DPC's Chairman may allot, one member holding several DPCs, expiry returning the DPC, a **concluded** DPC never returned however late, the sweep idempotent, scheduling bounded by the deadline |

### Existing classes rewritten

`DpcCommitteeTest` (16 tests, was 8) and `DepartmentPostMappingTest` (23, was 19)
were rewritten for the new shapes; four tests moved from asserting a **policy** to
asserting the **refusal**, because item 9 moved which layer refuses.

### Two defects found while building, and fixed

| Defect | Consequence had it shipped |
|---|---|
| `DpcCommitteeService::resolveMember()` **ignored the posted `user_id`**, so item 6's "Search Existing User" path silently discarded the account the officer had chosen and created a second one | The exact duplicate item 6 exists to prevent, produced by the feature meant to prevent it |
| `DpcAllotment::scopeCurrent()` matched only `superseded_by_id`, so a **returned** allotment still read as current | A returned DPC would be simultaneously back with the Chairman and already allotted, and `allot()` would refuse to re-allot the proposal the return exists to free up |

Both were found by tests written to pin the register's own wording rather than by
reading the code.

### One integration break the suite could not catch

`demo:seed` still wrote `department_posts.government_order_number` after item 10
dropped it, and no test runs the demo seeder. Found by running it against an empty
database, which is now part of the gate. The demo also had to be extended so it
actually exercises the new features: 12 qualification links (3 sources accepting more
than one), 9 Government Orders, 9 Chairman seats, 27 members carrying attributes, and
allotments with real deadlines.

---

## 9. Files modified

Roughly **120 modified · 20 added · 9 deleted**, excluding the pre-existing
uncommitted work in the tree. By area:

| Area | Added | Modified | Deleted |
|---|---|---|---|
| Migrations | 6 | — | — |
| Domain models | 2 | 8 | 2 |
| Domain services | 4 | 6 | 1 |
| Requests | 2 | 5 | 1 |
| Enums | — | 3 | 1 |
| Value objects | — | 1 | 1 |
| Controllers, middleware, routes | 3 | 8 | 1 |
| Views | 3 | 12 | 2 |
| JavaScript | 1 | 1 | — |
| Language files | — | 10 | — |
| Console commands | 1 | 3 | — |
| Seeders | — | 4 | — |
| Tests | 5 | 12 | 2 |
| Docs | 1 | 5 | — |

**One file was lost and rebuilt.** `lang/en/admin.php` was truncated by a scripting
error during this work — `open(path, 'w')` evaluated before the function that read the
file — and, being untracked, had no git history to restore from. It was reconstructed
from the Hindi catalogue's key structure (386 leaf keys, which `lang:check` confirms
at parity) and the slugified key names, then corrected where tests and views revealed
the original wording and placeholders. **Two keys are known to have been reworded**
rather than restored exactly: `audit_trail.show.actor_snapshot` and the
`committee_configurations.index.drift_*` group, both verified against the views that
render them. Any other English wording in that file may differ in phrasing from what
was there on 2026-08-25; no key, placeholder or behaviour differs.

---

## 10. Business questions requiring clarification

Three, recorded in full in `CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`. **None blocks
a demonstration and none blocks a published promotion list.**

| # | Question | Current behaviour |
|---|---|---|
| **BD-29** | Must a committee member out-rank the post being filled? | **The rule is no longer in force.** It ranked the member's post as recorded on the withdrawn master; a committee row now has no registered post, so it cannot be evaluated. Removed rather than left returning true for everybody |
| **BD-30** | Which attributes *must* a committee represent? | `required_categories` ships empty — nothing is refused on a rule nobody has stated |
| **BD-31** | Should an open sitting earn a grace period past the completion date? | No. The register's condition is "not concluded by the date", read literally. A *concluded* sitting is never returned, however late |

BD-29 is the one that deserves a decision: it is a rule that **used to be enforced**
and now is not, as a direct consequence of item 5.

---

## 11. What was deliberately not changed

- **The scoring engine.** `DpcCalculationService` reads `class_level` from the two
  post models directly, so item 3's display correction cannot move a single mark.
- **`Role::Member`.** Item 7 forbids committee membership from *granting* a role; it
  does not abolish the Commission officer who receives allotments and conducts
  proceedings. Deleting it would have taken M6/M7 with it.
- **A DPC still runs one Promotion From Post.** Restoring the plural on the order did
  not make a DPC plural (BD-28, unchanged).
- **`pre_dpc_proposals.pay_scale_id` / `class_level`.** These describe the post being
  filled and are inherited by the DPC. Item 3 asked for a display change and got one;
  the stored columns are untouched.
- **The `to_post_attributes` hint** under the Pre-DPC's To Post selector still shows
  that post's Pay Scale and Class, because it is explicitly labelled as the To
  Post's and is what an officer needs while choosing it. Item 3's complaint was about
  figures presented *unlabelled* as the exercise's, and those are corrected.

---

## 12. Remaining items

**None.** All 19 items are implemented, tested and documented. What is outstanding is
outstanding by design: three business decisions, each with a stated recommendation,
and one improvement recorded and deliberately unbuilt on instruction.

---

*Prepared 2026-08-26. Gate at time of writing: 1,210 tests passing, 4,013 assertions,
PHPStan clean, Pint clean, no security advisories, MySQL schema built from zero
(74 migrations, 75 tables), and `demo:seed` rebuilding from an empty database.*
