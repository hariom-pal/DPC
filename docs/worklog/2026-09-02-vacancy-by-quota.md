# Vacancy Becomes a Quota Subtraction — 2026-09-02 (third part)

**Business directive.** The Recruitment Rule's percentage now divides the
**posts**, not the finished vacancy. Each occupied post records how its holder
came into it, and the promotion vacancy — the only one this DPC fills — is a
subtraction rather than a multiplication.

```
promotion posts    = sanctioned × promotion %
direct posts       = sanctioned − promotion posts

promotion vacancy  = promotion posts − posts held by officers who came by
                     promotion and are still substantively in post
direct vacancy     = direct posts − the same for direct recruits
total vacancy      = sanctioned − posts substantively held
```

## Why the old arithmetic could not stand

`Total × promotion %` said *all* of a 100%-promotion post's vacancies were the
DPC's, even where a chair was held by a direct recruit — because nothing recorded
that fact. The figure had nothing behind it. Now every chair is accounted to a
quota by a row a department filled in, and a chair this committee has no claim on
is visibly not counted.

The two also disagreed in the live demo, not just in theory: three free chairs at
100% promotion gave 3 either way, but the moment a `direct` row existed the old
formula absorbed it silently and the new one refuses it.

## What a chart row now says

| Column | On a `working` row | On a `vacant` row |
|---|---|---|
| `STATUS` | `working` | `vacant` |
| `SOURCE` | `promotion` / `deputation` / `direct` | must be blank |

**A post counts as substantively held** only if somebody holds it through the
whole DPC Year. Four things mean it is not, and each is a vacancy somebody must
fill: it is vacant; the officer came on **deputation** and so consumes no quota;
he **retires inside the window**; or he is **away on deputation** across it.

**Only the promotion quota is stored** in `dpc_vacancies`. The scoring engine
fills seats from the sum of those rows, so a Direct Recruitment seat stored there
would be promoted against. It is reported on screen and in the audit row instead.

**The zone of consideration and the allotment now read the promotion vacancy**,
not the total. A committee is sized for the seats it is going to fill.

## Two new refusals

1. **"The chart does not account for every sanctioned post."** Unlisted chairs
   still count as vacant — `sanctioned − held` does not need the row — but nothing
   can be said about their reservation category, so the section stays incomplete.
2. **"The chart contradicts the Recruitment Rule."** A `direct` row against a
   100%-promotion post describes an appointment the rule forbids. It surfaces as a
   negative direct vacancy rather than being absorbed.

## Decisions taken, and the one assumption

- **The category of a derived vacancy is the officer's own.** A roster-driven
  attribution is the stricter reading, but the portal holds no rule for which
  roster point a mid-year retirement answers to, and inventing one would put an
  unapproved rule inside a calculation the Commission signs.
- **`from_promotion` is now produced**, as asked — an officer frees his chair on
  the ground he arrived by. No new vacancy type was created.
- **Assumption, stated in the code:** where several chairs are free at once, the
  promotion seats take their categories from the free chairs **in serial order**.
  Nothing in the register says otherwise and serial order is at least visible on
  the screen the department filled in. A roster rule would replace it.

## Files

`2026_09_02_420002_add_source_to_dpc_working_officers` · `DpcWorkingOfficer`
(`source`, `substantivelyOccupies()`, `vacancyGround()`) · `DpcRowRules` ·
`VacancyEngine` (`chairsByQuota()`, `chairsHeld()`, rewritten `calculate()` and
`classification()`; the old `split()` is gone) · `VacancyBreakdown` (chair
figures, `chartIsIncomplete()`, `quotaOverfilled()`) ·
`VacancyCalculationService` (zone on the promotion vacancy) ·
`DpcProposalCompletenessService` · `ExcelImportService` · `ImportTemplate` ·
`ImportTemplateBuilder` · `working-officers.blade.php` ·
`dpc-working-officers.js` · both language catalogues.

## Verification

- `pint` clean · `phpstan` **0 errors** · `lang:check` complete.
- **Full suite: 1295 passed, 4287 assertions.**
- Six new tests pin the arithmetic at both ends of the rule: the 50/50 worked
  example (promotion 2 / direct 1 / total 3), the 100/0 case (all three the
  DPC's), a `direct` row against 100% promotion reported as a fault, a
  deputationist consuming no quota, only the promotion quota being stored, and
  the zone sized on 2 rather than 3. A seventh covers the incomplete chart.
- Fixtures were corrected rather than weakened. Three of them had never
  registered a Recruitment Rule, which was harmless while the old formula
  multiplied a total and is not now — the rule is a required input, and a real
  proposal always has one because its Pre-DPC approval projects it.

## Demo

`demo-working-officers.csv` carries `STATUS` and `SOURCE` — four rows for four
sanctioned posts. At the demo's 100%-promotion rule: **total 3, all 3 the
DPC's**. Change the Pre-DPC to 50% and the same chart gives promotion 2 /
direct 1. All three sheets re-validated through the real importer at **0 rejected
rows**.

## Status

Green, and the demo database is clean.

Rollback: `…/scratchpad/dpc_dev_backup_pre_chart_model.sql`.
