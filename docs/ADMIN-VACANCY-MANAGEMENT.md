# Admin Vacancy Management

> Development branch `feature/uat-cleanup-and-admin-audit`, 2026-10-17. Not deployed.

One authoritative vacancy record per **department + post + Vacancy Year**, owned by
the CGPSC Administrator. New DPC proposals read it; the Authorized Officer (AO) does
not enter vacancy figures.

## 1. Old workflow (until 2026-10-17)

| Who | Where | What |
|---|---|---|
| Admin | Department Management → department → *Vacancy Master* | year-wise record: sanctioned strength, working strength, reason-wise vacancies, finalize |
| Admin | *Vacancy Types* master (sidebar) | free CRUD and CSV import of `clear`, `from_promotion`, `from_retirement`, `from_deputation` |
| AO | Proposal → S16 *Working Officers & Vacancies* | uploads a Working Officers chart (one row per sanctioned post); the engine derives the vacancy and the `dpc_vacancies` rows from it |

The same facts were entered twice (Admin aggregate counts, AO officer chart), only
reconciled when the Admin's record happened to be finalized. The Vacancy Type master
let an Admin rename or delete a code the engine looks up by name, which silently
dropped vacancies from a proposal.

## 2. New workflow

**Admin — sidebar → Vacancy Management** (`admin.vacancy-management.index`)

1. **Select** Department → Post (only posts mapped to that department) → Year, then *Open*.
2. **Sanctioned strength** for that year, with order number, order date and PDF.
3. **Vacancy details**: working strength on 1 January (promotion / direct / deputation),
   existing and anticipated vacancies with reason and the quota of the post freed.
   Opening vacancy, promotion and direct quotas are calculated.
4. **Review and finalize.** Finalized figures are frozen; a correction is a new revision.

The same page lists every record (latest revision) with filters for department, year,
status and a post search; filtering by one post shows its year-wise history. Steps 2–4
are the existing Vacancy Master screens, now reached from this one entry.

**AO — proposal → S16** shows the finalized record **read-only**: sanctioned and working
strength, existing and anticipated vacancies, promotion share and posts, promotion and
direct vacancies, the sanction order, and each vacancy entry. No chart, no upload, no
breakdown entry. Without a finalized record for the department, post and DPC year the
screen says:

> Vacancy information for the selected Department, Post and Year has not yet been finalized by Admin.

and the submission gate refuses the proposal. No other year or department is ever used.

## 3. Year-wise sanctioned strength

`vacancy_position_sanctioned_strengths` (migration `470001`): every figure entered for a
record, with order and PDF, append-only; the record points at the one in force and the
finalized record freezes it in `vacancy_positions.sanctioned_strength`. Each year is its
own record, so 2024 = 100, 2025 = 120, 2026 = 110 stay independent.

**Year key.** The Vacancy Year is the calendar year, 1 January – 31 December (rule 1.5:
vacancies reckoned to 31 December of the proposed year). A proposal's **DPC year** is
matched to it. No financial year is used anywhere. `department_posts.sanctioned_strength`
(the old mapping figure) is kept and unread; it carries no year, so it was **not** mapped
into any year's record — that mapping is left to the Administrator.

## 4. Calculation source

`dpc_proposals.vacancy_source`, stamped when a proposal is created:

| Value | Figures from | Who |
|---|---|---|
| `chart` | the AO's Working Officers chart | every proposal raised before this change (backfilled) and the demo |
| `vacancy_master` | the finalized Vacancy Management record | every new proposal (`DPC_VACANCY_SOURCE`, default `vacancy_master`) |

For `vacancy_master`, `VacancyEngine::calculate()` returns the record's frozen breakdown
(`ProposalVacancyPosition`). The record was computed with the engine's own
`quotaPosition()` — same rounding (rule 1.3), same chair arithmetic:

```
promotion posts   = sanctioned × promotion % (rule 1.3 rounding); direct = the rest
held (promotion)  = working (promotion) − anticipated vacancies counted against promotion
promotion vacancy = promotion posts − held (promotion)        (direct likewise)
```

`occupant_quota` on an anticipated entry is the same fact as `source` on a chart row
(the quota of the post the leaving officer held); deputationists hold neither quota in
both. The legacy vacancy types are only the label of the stored rows: at submission the
promotion vacancy is written to `dpc_vacancies` as `clear` (the 1 January part) and
`from_promotion` (arising in the year), in the single reservation pool, and recorded in
`dpc_vacancy_calculations`.

**Equivalence is tested**, not assumed: `VacancyManagementIntegrationTest` builds the
same facts both ways — a retirement in the year, rule 1.3's .5 tie, a 100 % promotion
post — and asserts identical sanctioned, chairs, held, promotion, direct, total and
retirement figures and the same seniority-list size.

## 5. Ownership and authorization

- Vacancy Management routes are inside the `role:admin` group; writes also call
  `assertAdmin`. An AO gets 403.
- AO: `SaveWorkingOfficersRequest::authorize()` refuses a `vacancy_master` proposal (403);
  `DpcProposalService::saveWorkingOfficers()` and the Working Officers Excel import refuse
  it too. S16 is gated on `view` (same department only).
- Sanction and working-strength PDFs stay on the private disk, streamed only through the
  Admin routes.

## 6. History

- Proposals raised before the change keep `chart` and their charts, breakdowns, approved
  calculations and `vacancy_types` links unchanged.
- A submitted `vacancy_master` proposal pins the revision it was submitted with
  (`vacancy_position_id`); a later Admin revision never changes it. A Review DPC inherits
  the original's source and pinned revision.
- `vacancy_types` table and rows are kept; only its screen, routes and CSV import are removed.

## 7. Migrations

| Migration | Change |
|---|---|
| `2026_10_17_530001_add_vacancy_source_to_dpc_proposals` | nullable `dpc_proposals.vacancy_source`; existing rows set to `chart` |

Additive; no column or table dropped.

## 8. Remaining business-rule blockers

| # | Blocker | Effect today |
|---|---|---|
| V-1 | **Category-wise reservation.** The record has no reservation category per vacancy. | With `DPC_RESERVATION_CATEGORY_WISE=true` a `vacancy_master` proposal cannot be submitted (gate explains why). Single pool (today's rule) works. |
| V-2 | **Working Officers list (rules 1.1, 4.4).** The PDF has the department upload the officers on the promotion post and match the count 100 %. Under the Admin-owned decision the AO uploads no list for new proposals. | CI-32 is met by the Administrator's working strength; a named list is no longer collected. Decide whether the AO should still upload it as evidence (not as a figure). |
| V-3 | **Promotion share mismatch.** A record is finalized under the Recruitment Rule in force on 1 January; a proposal carries its Pre-DPC's rule. | If the shares differ the gate refuses the proposal and asks for a revised record, rather than choosing one. |
| V-4 | Old `department_posts.sanctioned_strength` values have no year. | Not migrated into any year (see §3). |
