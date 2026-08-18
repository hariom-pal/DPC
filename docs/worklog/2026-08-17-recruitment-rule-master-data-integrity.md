# Recruitment Rule — Master-Data Integrity

**Date:** 2026-08-17
**Scope:** Architecture only. No workflow, business rule, calculation or DPC
processing was changed.

---

## Audit finding first

Half of what was asked for was **already built**. `recruitment_rule_feeders`
already carried `feeder_cadre_id` as a non-nullable foreign key to `cadres`, and
the admin form already selected feeders from the Cadre master, one row per
feeder. Multiple feeders per rule already worked — the seeded `Lecturer` rule has
two.

What was actually missing:

| Asked for | Status before | Action |
|---|---|---|
| Feeder from Cadre master, FK not text | **Already done** | left alone |
| Multiple feeders per rule | **Already done** | left alone |
| Searchable multi-select | Missing — one plain `<select>` per added row | built |
| No duplicate feeder | Not enforced anywhere | validation + unique index |
| Target ≠ feeder | Not enforced | validation |
| Non-empty feeder list | Not enforced | validation, conditionally — see below |
| Qualification master | Did not exist | built |
| Qualification as FK | Free-text `min_qualification` | added `qualification_id` |

The pre-existing data was audited before any constraint was written: **no
duplicate feeders, no self-references, no cross-department feeders**, so all
three are safe to enforce. One row did conflict — see the deviation below.

---

## 1. Database changes

| Change | Table | Notes |
|---|---|---|
| **New table** `qualifications` | — | `code` (unique), `name_en`, `name_hi`, `description`, `status`, audit columns, soft deletes — the same shape as the other lookup masters |
| **New column** `qualification_id` | `recruitment_rule_feeders` | nullable FK → `qualifications`, **ON DELETE SET NULL** |
| **New unique index** `rr_feeders_rule_cadre_unique` | `recruitment_rule_feeders` | `(recruitment_rule_id, feeder_cadre_id)` — one feeder cadre per rule, enforced at the database, not only in validation |
| **Retained** `min_qualification` | `recruitment_rule_feeders` | **not dropped** — see §6 |

Nothing was renamed, retyped or removed. `feeder_cadre_id` was already correct
and is untouched.

## 2. Migration plan

Two migrations, both additive:

1. `2026_08_17_310001_create_qualifications_table`
2. `2026_08_17_310002_add_qualification_id_to_recruitment_rule_feeders`
   — adds the FK, **seeds the catalogue**, backfills, then adds the unique index.

**Why the migration seeds master data.** On a fresh database seeders run *after*
migrations, so a backfill that waited for `QualificationSeeder` would have
nothing to match against and every row would come out null. Seeding inside the
migration makes `php artisan migrate` sufficient on its own — which is the whole
point of a backward-compatible migration. The catalogue lives in one shared class
(`App\Domain\Master\Data\QualificationCatalogue`) that both the migration and the
seeder read, so the two cannot drift.

**The backfill** matches a normalised form of the free text (case-folded,
punctuation and whitespace stripped) against both the `code` and the `name_en`,
because the text was typed by hand — `Post-Graduate`, `post graduate` and
`Post Graduate.` are one qualification. Text that matches nothing keeps a null
`qualification_id` **and its original text**, and is reported by
`php artisan qualifications:unmapped` (`--fix` re-runs the match after the missing
qualification is added). Unmapped data is a finding to be surfaced, never a
guess to be made.

**The unique index refuses to half-apply.** It checks for duplicates first and
throws with the offending rule/cadre pairs named, rather than adding a constraint
that only binds future writes.

`down()` is implemented for both.

## 3. Seeder changes

| File | Change |
|---|---|
| `QualificationSeeder` (new) | Upserts the catalogue by `code` — idempotent, safe after the migration has already seeded it |
| `DatabaseSeeder`, `DemoSeeder` | Registered after `LookupMasterSeeder` |
| `QualificationCatalogue` (new) | The single shared list: the 8 examples requested, plus `Graduate`, `Post-Graduate`, `Post-Graduate with B.Ed.`, `M.Com.`, `M.A.` |

The three extra broad requirements are **not padding** — they are the
qualifications the seeded Schedule-IV rules actually use. Shipping only the eight
textbook degrees would have left all nine live rows unmapped.

`DemoMasterSeeder` needed **no change**: it passes qualifications as text through
the Pre-DPC, and the projection now resolves that text to the master.

## 4. UI changes

**Recruitment Rule form** — restructured into the requested order:

```
1 · Target Cadre   (searchable; selecting it filters the feeder list to its department)
      ↓
2 · Feeder Cadres  (searchable multi-select — filter box + checkbox list,
                    per-feeder %, experience and qualification revealed on tick)
      ↓
    Minimum Qualification  (dropdown, sourced from the Qualification master)
```

- The free-text qualification input is **gone**; it is a `<select>` of Active
  qualifications only.
- The multi-select is a **filterable checkbox list**, not a JS widget. That is
  deliberate: this project self-hosts its assets with no CDN, and native
  checkboxes with real `<label>`s keep the screen accessible on a government
  portal. It also matches the requested shape literally (`☑ Teacher`
  `☑ Head Master`). Extras: a live count, a "show selected only" filter, and
  ineligible cadres (self, other departments) never offered.
- Rows are hidden, never created, so a row can never be half-built.
- **Recruitment Rule index** gained a *Feeder Cadres → Min. Qualification*
  column, so the references are visible without opening a rule. Legacy text with
  no master row is badged `unmapped`.
- **New screen** Administration → **Qualifications**, with a *Used by* column and
  Delete disabled for a qualification that rules cite.

## 5. Validation

| Rule | Where |
|---|---|
| Deleted qualification refused | `Rule::exists(...)->where('status','active')->whereNull('deleted_at')` |
| Duplicate feeder cadre refused | `distinct` + DB unique index + array keyed by cadre id |
| Target ≠ feeder | `different:cadre_id` |
| Feeder list non-empty | `Rule::requiredIf(promotion_recruitment_pct > 0)` |
| Invalid qualification refused | `exists` on the master |
| Feeder must be same department | `withValidator()` — cadres are department-scoped |

`whereNull('deleted_at')` is **not** redundant, and a test proves it: `Rule::exists`
runs on the query builder, so the SoftDeletes global scope never applies and a
trashed qualification validated happily — its `status` column is still `active`.
That hole was found by the test, not by reading the code.

### One deliberate deviation

**"Prevent empty feeder list" is enforced only when Promotion Recruitment % > 0.**

A cadre filled 100% by direct recruitment has no feeder post by definition, and
the seeded `Teacher` rule is exactly that: `DR 100% / PR 0%`, zero feeders.
Enforcing the rule absolutely would have made an existing, correct record
impossible to save and broken the Pre-DPC projection that creates it. The
conditional form still catches the case the requirement is aimed at — a promotion
rule that forgets to say where officers are promoted from. Both branches are
tested.

## 6. Backward compatibility — confirmed

Verified by running `php artisan migrate` against the **existing** development
database (9 recruitment rules, 9 feeders, all with free-text qualifications):

```
feeders total          : 9
with legacy text       : 9
UNMAPPED (must be 0)   : 0
legacy text preserved  : yes — column intact
```

Every one of the nine mapped: `B.Ed.` → `[BED]`, `Post-Graduate with B.Ed.` →
`[PG_BED]`, `Post-Graduate` → `[PG]`. Then verified again on a **fresh**
`demo:seed`, where the same nine resolve through the Pre-DPC projection instead
of the backfill. `php artisan qualifications:unmapped` reports clean on both.

**`min_qualification` was deliberately not dropped.** Three reasons: it records
what the Government Order actually said and this project does not rewrite
evidence; a value that matches no master row must stay readable, because dropping
the column would delete the data-quality finding along with the data; and the
migration discipline for this repo is additive-only. `qualification_id` is now
what the application reads and writes, `min_qualification` is the legacy record
behind it, and `RecruitmentRuleFeeder::qualificationLabel()` prefers the master
and falls back to the text — so no screen that displays a qualification can
regress. It resolves `withTrashed()`, so a withdrawn qualification still displays
on the rules that cite it.

**The programmatic request shape is unchanged.** The form posts a `present`
marker per row so an unticked checkbox can be told apart from a deliberate
feeder; requests without that marker — the existing test suite, any API caller —
are treated exactly as before. `RecruitmentRuleTest` passes **unmodified**.

## 7. What was explicitly not touched

No DPC workflow, no eligibility or scoring logic, no business rule, no
calculation, no proceeding, no review, no circulation. The Pre-DPC **screens** are
unchanged — the department still enters a qualification as text on its own form;
resolution to the master happens at the moment of projection into the
Recruitment Rule master, which is where the integrity requirement applies.

## Verification

- **747 tests pass** (2258 assertions) — up from 727; **20 new**, none changed.
- Pint pass · PHPStan no errors · `composer audit` clean.
- `migrate` on existing data: 9/9 mapped, 0 unmapped, legacy text intact.
- `demo:seed --force` from scratch: full journey rebuilds, **34 demo screens
  still render** for the correct roles, all seven DPC IDs unchanged.
- New screens verified over HTTP: Qualification master list/create, the nav link,
  the rule index feeder column, and the rebuilt form (searchable picker present,
  free-text qualification input confirmed **absent**, qualification rendered as a
  `<select>`).
