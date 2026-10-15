# Which departments can actually run a DPC — 2026-10-05

**Asked:** a written statement of what is missing, department by department, and what
the demo needs. **Nothing in the database was changed to produce this.**

**Bottom line:** of nine departments, **one** — School Education — can carry a DPC.
Higher Education has an Authorized Officer and an Administrative Head but **no posts
and no recruitment rules**, so a presenter who signs in as `ao.he@cg.gov.in` reaches a
dead end at the first screen. This is master data; none of it was affected by the
transactional reset of 2026-10-05.

> **Updated 2026-10-05, after the recruitment-rule cleanup.** Gaps 1 and 2 as first
> written no longer exist: the two recruitment rules they described were **last demo's
> workflow output**, not master data, and were deleted. See §3. The database now matches
> the baseline `CURRENT_DEMO_FLOW.md` documents.

---

## 1 · What a department needs before a DPC is possible

Read off the code and the schema, not off a document. Every one of these is master
data that the Administrator or the department maintains **before** any workflow runs.

| # | Requirement | Where it lives | Who maintains it |
|---|---|---|---|
| 1 | Department exists and is `registered` | `departments.status` | Administrator |
| 2 | An **Authorized Officer** assigned to it | `user_department_assignments` | Administrator |
| 3 | An **Administrative Head** assigned to it | `user_department_assignments` | Administrator |
| 4 | **At least two Department Posts** — a target and a feeder | `department_posts` | Department |
| 5 | A **Government Order** behind each post mapping | `department_post_orders` | Department |
| 6 | A **Recruitment Rule** on the target post, `active` and in date | `recruitment_rules` | Department |
| 7 | **At least one promotion source** on that rule, naming the feeder post | `recruitment_rule_sources` | Department |
| 8 | **Qualifications** accepted by that source | `rr_source_qualifications` | Department |

Items 9 onward — the CGPSC Chairman, committee configuration, reservation categories
and policies, districts, designations, pay scales, record reasons, review reasons,
vacancy types and DPC types — are **state-wide, already seeded and complete**. They
are not a per-department concern and are not listed below.

## 2 · Department-by-department

| Dept | Status | AO | AH | Posts | GOs | Recr. rules | Can run a DPC? |
|---|---|---|---|---|---|---|---|
| **SE** — School Education | registered | ✔ | ✔ | 9 | 9 | 0 — by design | **Yes** — the Pre-DPC creates the rule |
| **HE** — Higher Education | **unregistered** | ✘ | ✘ | **0** | **0** | **0** | **No** |
| GAD — General Administration | unregistered | ✘ | ✘ | 0 | 0 | 0 | No |
| FIN — Finance | unregistered | ✘ | ✘ | 0 | 0 | 0 | No |
| HLT — Health | unregistered | ✘ | ✘ | 0 | 0 | 0 | No |
| AGR — Agriculture | unregistered | ✘ | ✘ | 0 | 0 | 0 | No |
| ME — Medical Education | unregistered | ✘ | ✘ | 0 | 0 | 0 | No |
| LAB — Labour | unregistered | ✘ | ✘ | 0 | 0 | 0 | No |
| PWD — Public Works | unregistered | ✘ | ✘ | 0 | 0 | 0 | No |

**Both departments that work are Education departments.** There is no non-Education
department with any data at all.

## 3 · School Education — what exists, and what was cleaned up

Nine posts, each with a pay scale, a class level and a Government Order
(`F-2-14/2025/20-Three`), effective 2026-01-01:

| # | Post | Class | Pay scale | Promotion yrs | Sanctioned |
|---|---|---|---|---|---|
| 1 | Teacher | III | Level-10 | 5 | — |
| 2 | Head Master, Middle School | III | Level-10 | 4 | 4 |
| 3 | Lecturer | II | Level-11 | 5 | 4 |
| 4 | Principal, Higher Secondary School | II | Level-12 | 5 | 9 |
| 5 | Block Education Officer | II | Level-12 | — | 4 |
| 6 | District Education Officer | I | Level-12 | 5 | 4 |
| 7 | Joint Director, Education | I | Level-13 | 4 | 12 |
| 8 | Additional Director, Education | I | Level-13A | 3 | 10 |
| 9 | Director, Public Instruction | I | Level-14 | — | 10 |

### Gap 1 and Gap 2 — resolved on 2026-10-05, and they were not gaps

As first written, this section reported two active recruitment rules —
`GOVOD1394/F/2026` on Additional Director and `GR34/N/2026` on Director, Public
Instruction — and called them implausible: both fed from Block Education Officer,
both skipping levels, both setting the minimum qualification at *10th / B.Ed.* for a
Class-I post.

**They were not master data at all.** Both were created by
`ah.schooleducation@cg.gov.in` on 2026-09-07 — nobody typed them. An approved Pre-DPC
creates its recruitment rule automatically (`PreDpcProposalService` →
`RecruitmentRuleService`), so these were **the previous demo's output**, left behind
because `recruitment_rules` sits in the reset command's `PRESERVED` list.

They were deleted on 2026-10-05, with their 2 promotion sources and 4 qualification
links. **Hard-deleted, not soft-deleted:** `RecruitmentRuleService::nextVersion()`
reads `MAX(version)`, so a soft-deleted row would have made the demo's new rule **v2**
where `CURRENT_DEMO_FLOW.md` §5.4 expects **v1**.

The master is now empty, which is the documented baseline — and the point §5.4 makes to
the audience (*"Nobody typed that rule. This is why the master was empty in §5.3."*)
lands again.

**The real finding is the classification.** A recruitment rule is both master data (an
Administrator may type one) and workflow output (an approved Pre-DPC generates one).
The reset command treats it only as the first, so every demo leaves rules behind that
the next demo's baseline does not expect. Whether `recruitment_rules` should move to
`TRANSACTIONAL`, or the command should gain a flag for generated rules, is an open
change-control item — see §7.

### Gap 3 — rosters exist on two posts, and nothing reads them

Two `100-point Roster (Illustrative)` rosters exist, on **Principal, Higher Secondary
School** and **Joint Director, Education** — neither of which is the post the demo
runs. Since the cleanup above, no post carries a recruitment rule at all.

This does not block anything, for a reason worth knowing: **nothing reads a roster.**
See §5.

## 4 · Higher Education — what is missing

The accounts exist and work; everything they would operate on does not.

| Requirement | State |
|---|---|
| Department registered | ✔ (`HE`, registered) |
| Authorized Officer | ✔ Shri Ramesh Kumar Sinha — `ao.he@cg.gov.in` |
| Administrative Head | ✔ Smt. Vandana Shukla — `ah.he@cg.gov.in` |
| Department Posts | **✘ zero** |
| Government Orders | **✘ zero** |
| Recruitment Rules | **✘ zero** |
| Promotion sources | **✘ zero** |

**To make HE demo-able**, the business must supply — for a real Chhattisgarh Higher
Education cadre — at least:

1. A **target post** and a **feeder post**: name, class level (I / II / III), pay scale
   (one of the six seeded Levels, 10 / 11 / 12 / 13 / 13A / 14), minimum promotion
   duration in years, and sanctioned strength.
2. The **Government Order number and date** that sanctions each post.
3. The **recruitment rule** on the target post: GO number, effective date, and the
   promotion / direct recruitment split (the two SE rules are 100 / 0).
4. The **promotion source**: which feeder post, minimum experience in years, and which
   of the 17 seeded qualifications it accepts.

The usual Higher Education ladder — Assistant Professor → Associate Professor →
Professor → Principal, Government College → Joint Director, Higher Education — is the
obvious shape, but **the post names, pay levels, experience bars and GO numbers are
business facts and are not invented here.** Supplying the four items above is enough
to build it; nothing in the code needs to change.

The same four items are what any of the seven unregistered departments would need,
plus registration and an AO / AH pair.

## 5 · A separate finding — the reservation roster is maintained but never read

`ReservationRoster` and `ReservationRosterPoint` are referenced **only** by their own
admin CRUD controllers and by a count on the Administrator dashboard. No service in
`app/Domain` consumes them — `VacancyCalculationService` does not mention a roster, and
neither does the eligibility engine.

The 200 seeded roster points therefore have **no effect on any calculation**. Two
consequences:

- Gap 3 above does **not** block the demo. A DPC on Additional Director or Director,
  Public Instruction will calculate normally despite having no roster.
- Reservation in the output comes from the **officer's own category** on the snapshot
  (`reservation_categories`, used by `DpcSeniorityEntry`, `DpcWorkingOfficer`,
  `DpcEvaluationRow` and `DpcVacancy`) together with the five seeded
  `reservation_policies` — SC 13%, ST 32%, OBC 27%, EWS 10%, UR 0%. That path is
  complete and works.

Whether roster-point-wise reservation was meant to drive the vacancy calculation is a
**business question, not a defect report**. It is recorded here because a reviewer
looking at the Administrator's Reservation Roster screen will reasonably assume it does.

## 6 · What this means for the demo as it stands today

**Verified baseline, 2026-10-05** — matches `CURRENT_DEMO_FLOW.md` §2 exactly:

| | |
|---|---|
| Transactional rows | **0** across every table; 0 stored files |
| Accounts | **9**, all `Demo@2026` — see §8 |
| Departments / posts / GOs | 9 / 9 / 9 |
| Recruitment Rules | **0** — as the script expects |
| Audit trail and timeline | **0** — the cleanup was done in SQL, so it left no noise |
| Next rule version on Additional Director | **v1** |

- Run the whole walkthrough in **School Education**, as `ao.schooleducation@cg.gov.in`.
- The pair is **Block Education Officer → Additional Director, Education**. All three
  sample CSVs are built for it; any other pair imports nothing.
- First identifiers will be `PDPC-2026-SE-0001` and `DPC-2026-SE-0001`.
- **`ao.he@cg.gov.in` no longer exists** — it was deleted in the prune of 2026-10-05,
  along with every account the walkthrough does not use. See §8.
- If the audience asks about other departments, the honest answer is that department
  masters are per-department data the department itself loads, and only School
  Education has been loaded so far.

## 7 · Open items this exercise raised

Neither is a demo blocker. Both are recorded here rather than acted on.

| # | Item | Needs |
|---|---|---|
| 1 | **Roster-point attribution** — should a promotion vacancy answer to the roster point the chair sits at, rather than to the category of the officer who vacated it? Today the engine walks the Currently Working chart in serial order and takes the vacating officer's own category; `VacancyEngine.php:264` records this as a deliberate deferral, but it was **never escalated to the pending-business-decisions register**. | A business decision from CGPSC. Proposed as **BD-22**. |
| 2 | **`recruitment_rules` classification** in `ResetTransactionalDataCommand` — see §3. Every reset currently leaves the previous demo's generated rules behind. | A change-control decision: move the table to `TRANSACTIONAL`, or add a flag. |
| ~~3~~ | ~~`DemoCredentialsCommand` recreates the Higher Education pair.~~ **Fixed 2026-10-05** — `createMissingDemoUsers()` removed; the command now reports the department count instead of widening it. `HE` was also returned to `unregistered` (the old command had force-flipped it; `registered_at` was never set). | Done. |

## 8 · The account prune of 2026-10-05

**41 of the 50 accounts were deleted**, on the instruction that the demonstration
should carry one department and nothing else. Nine remain — exactly the nine
`CURRENT_DEMO_FLOW.md` §3 names as *"the accounts the walkthrough is driven from"*:
the Administrator, the CGPSC Chairman, five Members, and School Education's Authorized
Officer and Administrative Head.

| Deleted | Count | Why |
|---|---|---|
| Higher Education's AO and AH | 2 | The department has no posts and no rules; the pair could reach nothing |
| Promotion Candidates | 33 | **The demonstration creates these itself.** §5.9 commits the Seniority List and provisions all ten — *"All ten are new"*. Pre-existing accounts under the same employee numbers would have been matched instead of created, and the panel would have reported nothing provisioned |
| Role-less accounts | 6 | Registration attempts and committee records that never completed |

Safe to do: every foreign key pointing at `users` is `SET NULL` or `CASCADE` — none is
`RESTRICT` — so the masters were untouched and the CGPSC Chairman record still resolves
to its user. Backed up first to
`storage/app/backups/users-before-prune-20261005-122852.sql`.

`demo:credentials` was then fixed and run normally; it reports *"One department: School
Education Department."* and creates nobody.

---

*State as at 2026-10-05, read directly from `dpc_dev`. Credentials: `docs/demo/DEMO_LOGIN_CREDENTIALS.md`.*
