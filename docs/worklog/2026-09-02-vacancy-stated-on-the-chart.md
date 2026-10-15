# The Vacancy Breakdown Becomes Derived — 2026-09-02

**Business directive.** The Currently Working chart (S16) now lists **posts, not
only people**. A row is either an officer in post or a vacant post, and the
vacancy breakdown is derived from it instead of being typed beside it.

## Why

The screen asked for the same truth twice. `VacancyEngine` worked out the Total
Vacancy from the sanctioned strength and the chart; the officer then had to
reproduce that number by hand in a Vacancy Breakdown grid, and the section
refused to save until the two agreed:

> *The sum of all vacancy types must equal the Total Vacancy. Total is 3; the
> breakdown totals 0.*

Nothing in any upload filled that grid, so an imported chart passed preview,
wrote its officers, and still failed the section gate. That is where the UAT run
stalled.

## What changed

**A chart row now has a status.** `working` or `vacant`; absent means `working`,
so every file and every saved chart written before today still reads correctly.
A vacant row carries **only its reservation category** — name, date of birth,
retirement and deputation dates are refused on it, because an empty chair has
none of them and the chart is evidence.

**The arithmetic follows the chart rather than a subtraction.**

| | Before | Now |
|---|---|---|
| Working Strength | every chart row | rows whose status is `working` |
| Base Vacancy | `Sanctioned − Working` | rows whose status is `vacant` |
| Sanctioned ceiling | officers > sanctioned | **chart rows** > sanctioned |

**The breakdown is produced by `VacancyEngine::classification()`:** a vacant row
becomes a Clear vacancy in its own category, a retiring officer an Expected from
retirement in theirs, an officer on deputation an Expected from deputation in
theirs. *Expected from promotion* is never produced — nothing on the chart
evidences it, and the engine does not guess.

**The category of a derived vacancy is the officer's own** (business decision).
A roster-driven attribution would be the stricter reading, but this portal holds
no rule for which roster point a mid-year retirement falls to, and inventing one
would put an unapproved rule inside a calculation the Commission signs.

## Files

- `2026_09_02_420001_add_status_to_dpc_working_officers` — adds `status`,
  relaxes `name` and `dob` to nullable, back-fills every existing row to
  `working`.
- `DpcWorkingOfficer` — `status`, `isVacant()`, `isWorking()`, `displayName()`.
- `DpcRowRules::workingOfficer()` — `required_unless` / `prohibited_if` on
  status.
- `VacancyEngine` — the two counts, `chartRows`, and `classification()`.
- `VacancyBreakdown` — `chartRows`; `exceedsSanctionedStrength()` now tests it.
- `DpcProposalService::saveVacancies()` — derives the rows; no longer takes them.
- `SaveWorkingOfficersRequest` — the balance check and the whole `vacancies`
  array are gone; `prepareForValidation()` defaults the status.
- `ExcelImportService` — defaults the status, derives the breakdown on commit,
  and reads the sanctioned ceiling from the live mapping rather than the
  proposal's snapshot.
- `ImportTemplate` / `ImportTemplateBuilder` — `STATUS`, `DEPUTATION TO` and
  `DATE OF RETIREMENT` on the blank template.
- `working-officers.blade.php`, `dpc-working-officers.js` — the grid is replaced
  by a read-only derived table; a vacant row disables its person fields.
- `sections/working-officers.blade.php` — guarded against a null name and DOB.

## Two defects found while doing it

1. **`DpcRowRules::prefix()` did not rewrite sibling references.**
   `required_unless:status,vacant` keyed under `officers.*.name` resolved
   `status` at the root of the request, not on the row. The same latent fault
   made `after_or_equal:deputation_from` pass vacuously on every row. `prefix()`
   now rewrites a parameter that names a field of the same rule set, and leaves
   `before:today` and `min:1` alone.
2. **`saveVacancies()` had stopped recording the audit row.** The append-only
   `dpc_vacancy_calculations` entry is restored, and now guarded so an
   incomplete calculation is shown without being persisted.

## Verification

- `pint` clean · `phpstan` **0 errors** · `lang:check` complete.
- **Full suite: 1288 passed, 4249 assertions.**
- Fixtures updated rather than weakened: `SanctionsVacancies` and
  `CompletesDpcProposals` state their vacancy as vacant chart rows;
  `BuildsProceedings` does the same. The two tests that asserted a refusal the
  model no longer permits were replaced by stronger ones — that the breakdown
  *cannot* disagree with the total, and that a crafted `vacancies` payload is
  ignored.

## Demo state

Transactional data and the Recruitment Rules master cleared again, so the demo
starts empty. Masters, users, roles and department assignments untouched —
`users` now stands at 34 because the UAT run provisioned nine Promotion
Candidate accounts from its Seniority List; they are preserved by design and
re-acquire candidature when a new list names their employee number.

`docs/demo/sample-data/demo-working-officers.csv` carries the new `STATUS`
column — three officers and one vacant post against a sanctioned strength of 4,
producing Clear 1 (SC) + Retirement 1 (UR) + Deputation 1 (OBC) = **3**. All
three sheets re-validated through the real importer at **0 rejected rows**.

## Status

**Ready for a complete, from-scratch demo.**

Rollback: `…/scratchpad/dpc_dev_backup_pre_chart_model.sql`.
