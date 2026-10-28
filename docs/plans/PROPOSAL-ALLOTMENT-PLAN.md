# Pre-DPC / DPC Allotment to AO and AH — Plan

**Status:** plan only, awaiting go-ahead · **Date:** 2026-10-26
**Supersedes:** §10 "Future DPC assignment (designed for, not built)" of `DEPARTMENT-ADMIN-AND-CLEAN-INSTALL-PLAN.md`

## 1. Business decisions (2026-10-26)

| # | Decision |
|---|---|
| A1 | The **Department Admin opens a Pre-DPC** with only the DPC year and the promotion-to post, and allots one **AO** and one **AH** to it. The allotted AO fills everything else (promotion sources, percentages, qualifications, government order, effective date). |
| A2 | A **DPC proposal raised from a Pre-DPC inherits that Pre-DPC's AO and AH**. The Department Admin can reassign any DPC on its own, so parallel DPCs can have different officers. |
| A3 | An AO or AH who is **not allotted does not see the proposal at all**: not in listings, dashboards, reports, notifications or direct URLs (a direct URL returns 403). |
| A4 | **Existing proposals without an allotment are blocked** until the Department Admin allots them. The Department Admin's list shows them as "allotment pending". |
| A5 | **Reassignment is allowed at any time, with a recorded reason.** The previous allotment ends and is kept; nothing is deleted. Everything goes to the audit trail. |

## 2. Today (from the code survey)

- **Who does what:**
  - Any AO of the department creates, edits, submits and cancels a Pre-DPC or DPC.
  - Any AH approves or returns it.
  - These rules live in `PreDpcProposalPolicy`, `DpcProposalPolicy` and `DpcCirculationPolicy`, and again in the workflow engine's role gate (`config/dpc.php` `pre_dpc` and `dpc_proposal`).
- **Raw role checks** that bypass the policy:
  - `dpc-proposals/show.blade.php` (`$isAO` / `$isAH`)
  - `review.blade.php`
  - `sections/_action.blade.php`
  - `ReviewDpcComposer`
  - `DpcPromotionOrderController::canIssue`
  - `IssuePromotionOrderRequest`
- **Who sees what:** listings, `DashboardSummaryService::departmentGroup`, `DpcReportService::scopeFor` and `raisablePreDpcs()` select the whole department.
- **Notifications:** `NotificationRecipientResolver` sends to every AO or AH posted to the department. This covers the `pre_dpc`, `dpc_proposal`, `dpc_cgpsc`, `dpc_proceeding` and `dpc_circulation` workflows.
- **No per-proposal record:** nothing stores a per-proposal AO or AH. `user_department_assignments` is a posting, and `dpc_allotments` is the CGPSC Chairman → Member allotment, which is unrelated.

## 3. Design

### 3.1 Data
A new append-only table, `proposal_allotments`, modelled on the `dpc_allotments` supersede pattern:

| Column | Notes |
|---|---|
| `id`, `uuid` | |
| `allottable_type`, `allottable_id` | A Pre-DPC or a DPC proposal (morph) |
| `department_id` | Scope check and index |
| `ao_user_id`, `ah_user_id` | Each must hold an active posting in that department, with that role |
| `allotted_by` | The Department Admin |
| `reason` | Required on reassignment |
| `effective_from`, `ended_at`, `ended_by` | The current allotment is the one with `ended_at` null; a unique index allows one current allotment per proposal |
| timestamps | |

### 3.2 One service, one question
- `ProposalAllotmentService` has three operations: `allot(proposal, ao, ah, by)`, `reassign(proposal, ao, ah, reason, by)` and `current(proposal)`.
- `ProposalAllotmentService::isAllotted(user, proposal, role)` is **the one check**. Every policy, view flag, query scope and notification resolves through it.

### 3.3 Department Admin screens (`department.allotments.*`)
- **List** of every Pre-DPC and DPC in the department, showing:
  - number, post, year and status;
  - the current AO and AH;
  - an "allotment pending" badge where none exists.
- **Open Pre-DPC:** DPC year, promotion-to post, AO and AH. This creates the draft (`prepared_by` = AO) and the allotment in one transaction.
- **Reassign:** choose a new AO and/or AH and give a reason.
- **History:** every allotment of a proposal, with who allotted it, when and why.
- **Pickers** read `DepartmentUserAuthority::managedUsers()`: active AO and AH users of the department only.

### 3.4 Enforcement (A3, A4)
- **Policies:**
  - `PreDpcProposalPolicy` and `DpcProposalPolicy` add the allotment test to every method:
    - `view`, `update`, `submit` and `cancel` need the allotted AO;
    - `approve` and `return` need the allotted AH;
    - `view` also allows the allotted AH.
  - `create` for an AO becomes "raise a DPC from a Pre-DPC allotted to me". Opening a Pre-DPC moves to the Department Admin.
  - `DpcCirculationPolicy` applies the same allotment test.
- **Workflow engine:** keeps its role gate. The allotment check sits in the policies, which every controller already calls, and in the services' transition methods as a second line of defence.
- **Raw role checks:** the six raw checks in §2 are replaced with `@can` or the policy.
- **Unallotted proposals (A4):** every action is refused with "awaiting allotment by the Department Admin".

### 3.5 Visibility and notifications
- **Query scope:** `scopeAllottedTo(User)` on both models. It is used by:
  - both index screens;
  - `DashboardSummaryService` ("awaiting your approval" counts only what is allotted to that AH);
  - `DpcReportService::scopeFor`;
  - `raisablePreDpcs()`.
- **Recipients:** `NotificationRecipientResolver` gains a proposal-aware path. When a transition carries a proposal, AO and AH recipients are its allotted officers, not the whole department. The `dpc_cgpsc`, `dpc_proceeding` and `dpc_circulation` workflows follow the same rule.

### 3.6 Existing data (A4)
- **No automatic allotment.** A migration report command lists unallotted proposals per department.
- **UAT:** the Department Admin allots each one from the list. `dpc_fresh` has none.

## 4. Tests

- **New tests:**
  - only the allotted AO edits and submits, and only the allotted AH approves;
  - another AO or AH of the same department gets 403 and does not see the proposal in lists, the dashboard or reports;
  - after reassignment, the old officer is refused at once and the new one can act;
  - the history is kept;
  - an unallotted proposal is blocked;
  - the Department Admin cannot allot a user from another department or with another role;
  - notifications go only to the allotted officers;
  - DPC inheritance and an independent DPC reassignment work.
- **Updated tests:**
  - ~36 DPC test files: the fixtures `RaisesDpcProposals` and `CompletesDpcProposals` allot their AO and AH.
  - `PreDpcWorkflowTest`, `DpcProposalWorkflowTest` and `RoleBasedUiVisibilityTest`: the AO no longer opens a Pre-DPC.
  - the notification tests.
  - the end-to-end test: the Department Admin opens and allots the Pre-DPC.

## 5. Order of work

1. Table, model, service, and Department Admin screens (list, open, reassign, history).
2. Policies, raw-role-check replacement, and service second-line checks.
3. Scopes for listings, dashboard, reports and the DPC creation picker.
4. Notification recipients.
5. Test fixtures and new tests; full suite, PHPStan, Pint, `lang:check`.
6. Run on `dpc_fresh` only (migration); no other database.

## 6. Risks

- **The widest change since the Pre-DPC reshape.** Every DPC sub-screen authorizes through `update` or `view`. One missed raw role check would let a non-allotted officer act. Mitigation: grep-based inventory (§2) plus a test that walks every `dpc-proposals.*` route as a non-allotted AO and expects 403.
- **UAT data:** proposals in flight stop until they are allotted (decision A4). The Department Admin must be told before deployment.
