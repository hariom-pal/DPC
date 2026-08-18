# Recruitment Rule Screen — Clarity, Usability, Data Integrity

**Date:** 2026-08-17
**Scope:** The Recruitment Rule screen and the masters behind it. No workflow, DPC
processing, calculation or business rule was changed.

---

## What was already done, and what was new

Feeder Cadre and the Qualification master were built earlier the same day (see
`2026-08-17-recruitment-rule-master-data-integrity.md`): the feeder was already an
FK to the Cadre master with multiple feeders, no duplicates, no self-reference,
and Minimum Qualification was already an FK dropdown. Those were left alone.

Genuinely new in this round:

| Item | Status before | Action |
|---|---|---|
| **Promotion Share (%)** — label + helper + **total must equal 100%** + live total | Unnamed `Promotion %` box, no total rule | built |
| **Minimum Experience** — value **+ unit** (Years / Months) | Bare number, unit implied | built |
| Table layout with an **Action** column and *+ Add Feeder Cadre* | Checkbox list, no add/remove | rebuilt |
| **Searchable** dropdowns | Plain `<select>` | built |
| 10th, 12th, ITI, Diploma in the master | Absent | added |
| Step-by-step labelling and helper text throughout | Minimal | rewritten |

---

## 1. Database changes

| Change | Table | Notes |
|---|---|---|
| **New column** `min_experience_unit` | `recruitment_rule_feeders` | `varchar(10)`, default `years`. Backed by `ExperienceUnit` enum (Years, Months) |
| **Data change** `promotion_percentage` rescaled | `recruitment_rule_feeders` | Now a share of the **promotion quota**, totalling 100 per rule — see §2 |
| **New rows** in `qualifications` | — | 10th, 12th, ITI, Diploma (via seeder) |

No column was renamed, retyped or dropped. `promotion_recruitment_pct` on the rule
itself is **untouched** — how much of a cadre is filled by promotion is unchanged.

**Why this is calculation-safe, verified not assumed:** `grep` across `app/` shows
`promotion_percentage` and `min_experience_years` are read only inside the Master
module and the Pre-DPC projection. No eligibility, scoring, zone, proceeding,
review or circulation code path reads either. A semantic change here cannot move a
promotion result.

## 2. Migration changes

`2026_08_17_310003_add_experience_unit_and_normalise_promotion_share`

**The experience unit** is additive with an exact backfill: every existing row is
set explicitly to `years`, which is what every stored value already meant.

**The share rescale** is the substantive part, and it exists because the column
was being written two incompatible ways:

- as a share of *all* posts, mirroring the rule's own promotion quota — the
  Lecturer rule had two feeders at **25 each against a quota of 50**; and
- as a share of the *promotion quota*, which is what the field now means and what
  the screen validates: the shares must total exactly 100.

Only the second can be validated, because only the second has a fixed total. The
migration rescales `share / sum(shares) × 100` per rule, which **preserves the
ratio between feeders exactly** — the only information the old numbers carried:

| Rule | Before | After | Ratio |
|---|---|---|---|
| Lecturer (quota 50%) | Teacher 25, Head Master 25 | **50, 50** | 1 : 1 — unchanged |
| Principal (quota 75%) | Lecturer 75 | **100** | sole feeder — unchanged |
| The other 6 rules with feeders | already 100 | untouched | — |
| Teacher (quota 0%) | no feeders | no feeders | — |

The last row is scaled to the remainder rather than multiplied, so rounding cannot
leave a total at 99.99. A set whose shares are all zero is left alone: there is no
ratio to preserve, and inventing 100 would fabricate a policy decision.

`down()` drops the column but deliberately does **not** un-rescale — the original
sum is no longer recorded anywhere, and the ratio it preserved is intact, so
pretending the arithmetic is reversible would be worse than saying it is not.

**The Pre-DPC projection normalises the same way.** The department's Pre-DPC
screens still capture the feeder percentage against all posts, and those screens
are not being changed. `RecruitmentRuleService::normaliseShares()` translates on
the way into the master, so an approved Pre-DPC cannot write a rule the admin
screen would then refuse to save. Verified: a fresh `demo:seed` produces 100%
totals through the projection path, not just through the migration.

## 3. Seeder changes

| File | Change |
|---|---|
| `QualificationCatalogue` | Added `10th`, `12th`, `ITI`, `Diploma`; reordered school-leaving upwards; documented why the punctuated forms (`B.E.`, `LL.B.`) are kept |
| `QualificationSeeder` | Unchanged mechanism — upserts by code, so it is safe to re-run |

Existing deployments pick the four new entries up with
`php artisan db:seed --class=QualificationSeeder`. They are deliberately **not**
forced by a migration: the master is CRUD-managed by the admin, who may have
renamed or deactivated entries, and a migration that overwrote those choices would
be wrong. The earlier migration seeds only what its own backfill needs.

## 4. UI changes

Screenshots — real ones, of the running app — are in
[`docs/design/recruitment-rule/`](../design/recruitment-rule/README.md).

The form is now three numbered steps: **1 · The post this rule is for → 2 · How
the vacancies are filled → 3 · Feeder cadres**, each with a sentence explaining
what it is for.

The feeder grid is the requested table:

| Feeder Cadre | Promotion Share (%) | Minimum Experience | Minimum Qualification | Action |
|---|---|---|---|---|
| searchable dropdown, Cadre master | number + `%`, helper *"Percentage of promotion quota allocated to this feeder cadre."* | number + **Years / Months** | searchable dropdown, Qualification master | Remove |
| **Total** | **100% ✓** *"The promotion quota is fully allocated."* | | | |

- **+ Add Feeder Cadre** adds a row; **Remove** takes one away.
- The **running total** updates as you type, green with ✓ at 100%, red with ✗
  otherwise, and names the shortfall: *"30% still unallocated"*.
- The cadre dropdown offers **only** cadres in the target's department, **minus the
  target itself** and **minus cadres already chosen on another row** — a duplicate
  or a self-reference is hard to create, not merely rejected afterwards.
- An empty grid says what to do: *"No feeder cadre yet. Press + Add Feeder Cadre,
  or leave this empty if the post is filled entirely by direct recruitment."*
- The rule list shows feeder → share → experience → qualification inline, so the
  references are visible without opening a rule.

**The searchable dropdown is a progressive enhancement** (`public/js/searchable-select.js`,
applied to any `<select data-searchable>`). The native select keeps the name, the
value and the validation and is only visually replaced once the script has taken
over — with JS blocked the field degrades to an ordinary working dropdown. That
matters more than the widget: this is a government portal and the form behind it
is a legal record. It is dependency-free by choice; the project self-hosts its
assets and ships no bundler for admin screens, so a select library would mean
vendoring one and owning its upgrades for the sake of one filter box. ARIA combobox
roles, arrow keys, Enter, Escape and click all work.

## 5. Validation summary

| Requirement | Where | Test |
|---|---|---|
| Target Cadre cannot be its own feeder | `different:cadre_id`, plus the option is not offered | ✓ |
| Duplicate feeder not allowed | `distinct` + DB unique index + not offered twice | ✓ |
| Promotion Share total must equal 100% | `validateShareTotal()` — names the actual total and the shortfall | ✓ ×5 |
| Qualification must exist in the master | `exists` on Active + `whereNull('deleted_at')` | ✓ ×4 |
| Experience cannot be negative | `min:0` | ✓ |
| Experience unit must be Years or Months | `Rule::in(ExperienceUnit::values())` | ✓ |
| At least one feeder | `requiredIf(promotion quota > 0)` | ✓ ×2 |
| Feeder must be in the same department | `validateSameDepartment()` | ✓ |

Client-side checks mirror these but are **advisory only** — every one is enforced
again server-side. The browser copy exists so the user is told before pressing
Save, not so the server can trust the page.

### The one deliberate deviation, restated

**"At least one feeder" and "total exactly 100%" are enforced only when the rule
has a promotion quota above 0%.**

A cadre filled 100% by direct recruitment has no feeder post by definition, and
the seeded `Teacher` rule is exactly that: DR 100 / PR 0, zero feeders. Enforcing
either rule absolutely would make an existing, correct record impossible to save
and would break the Pre-DPC projection that creates it — which contradicts this
same request's "existing data must remain compatible". Scoped to the promotion
quota, both rules still catch what they are aimed at. The screen says so out loud
rather than hiding it: *"No feeder cadre needed — this post is filled entirely by
direct recruitment."* Both branches are tested.

## Backward compatibility

- **`min_experience_unit` is not required on the wire.** It is defaulted to `years`
  in `prepareForValidation()`. Making it `required` broke the pre-existing
  `RecruitmentRuleTest`, which is exactly the class of caller the instruction "do
  not change existing APIs" protects. `RecruitmentRuleTest` now passes
  **unmodified**.
- The `present`/`selected` marker introduced for the previous checkbox grid was
  removed with it; `prepareForValidation()` now simply discards rows with no cadre
  chosen, which is what an add-row grid needs.
- Row keys are preserved through validation rather than re-indexed, so an inline
  error lands on the row that caused it after a failed submit.

## Verification

- **759 tests pass** (2291 assertions) — 25 in `RecruitmentRuleIntegrityTest`, of
  which 12 are new this round. `RecruitmentRuleTest` unmodified.
- Pint pass · PHPStan no errors · `composer audit` clean.
- **`migrate` on the existing database**: all 9 rules end at 100%, Lecturer's 1:1
  ratio intact, all 9 feeders `years`.
- **Fresh `demo:seed`**: 100% totals via the Pre-DPC projection; the full demo
  journey rebuilds and **34 demo screens still render** for the correct roles.
- **Driven in a real browser** (`drive.js`, 18 checks): add/remove rows, combobox
  enhancement and filtering, the total flipping 70% ✗ → 100% ✓ with the right
  hint, the target excluded from its own feeder list, and a chosen cadre
  disappearing from the other row.

### Two defects the screenshots caught that assertions had missed

1. **The combobox rendered `hidden` options.** It filtered only on empty value, so
   the target cadre still appeared in its own feeder dropdown — visible in a
   screenshot, while the test passed because it checked `option.hidden` on the
   native select rather than the rendered list. Fixed in `searchable-select.js`,
   and the browser check now asserts on what is actually shown.
2. **The dropdown was clipped by the scroll container.** Bootstrap's
   `.table-responsive` sets `overflow-x: auto`, which creates a clipping context.
   Replaced with a `.feeder-grid` wrapper that is `overflow: visible` from the `lg`
   breakpoint up, where the table needs no horizontal scroll anyway.

A third, smaller one: `portal.css` sets `white-space: nowrap` on all table
headers, so the new column notes overflowed into neighbouring cells. Fixed with a
`.th-note` class that restores normal prose wrapping, and a fixed table layout with
an explicit `colgroup` so every column honours its width.
