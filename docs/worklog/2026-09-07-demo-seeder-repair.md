# Demo seeder repair — 2026-09-07

**Asked:** check the flow is right for the demonstration.
**Found:** the application is sound. `php artisan demo:seed` was not — it stopped
part-way, so the demonstration database could not be rebuilt at all.
**Left green:** `pint` passed · `phpstan` No errors · **1449 passed**, 2 skipped.

---

## 1 · What was actually wrong

`demo:seed` builds the demo as a sequence of workflow events rather than a set of
rows, which is its strength and was its exposure: every business rule added since
the seeder was last exercised gets applied to it, and **six of them refused the
data it was still producing.** It threw on the first and would have thrown on each
of the rest in turn.

| | Break | Rule that refused it | Arrived |
|---|---|---|---|
| 1 | The Working Officer chart never wrote **vacant chairs**, so a post with 12 sanctioned and 7 officers accounted for 7 chairs | The vacancy breakdown is *derived* from the chart, and balances only when the chart accounts for every sanctioned chair | 2026-09-02 |
| 2 | The chart never emitted **`status`** or **`source`** | Both columns are what the engine reads to decide which quota a chair consumes; omitted, they default to `working` / `null` — a chair held by nobody, so a whole post held no quota and the breakdown came out empty | 2026-09-02 |
| 3 | The chart never emitted **`seat_reservation_category_id`** | The roster point a chair *is*, beside the category of its occupant | 2026-09-07 |
| 4 | A DPC was allotted to a Member with **no seat on its own committee** | `AllotmentService` refuses that allotment outright | 2026-08-26 |
| 5 | The pipeline committees represented SC, OBC and a woman but not **ST, Bhar Sadhak Sachiv or Vibhag Sachiv** | Committee composition rules require all six; three seats can only carry six requirements if some carry several | 2026-08-26 |
| 6 | Every seated officer was treated as a **promotion** appointee | On a post that is not 100% promotion this overfills the promotion quota, and filling promotion first leaves that quota full and the DPC with no vacancy it is permitted to fill | 2026-09-02 |

**None of this was visible to the quality gate, and that is the part worth
keeping.** The suite was 1447 tests green throughout, because **not one test
seeded `DemoSeeder`**. The seeder is the only code path that walks the whole
process end to end, and nothing exercised it.

## 2 · What was changed

Seeders only. No migration, no domain service, no controller, no view.

* **`DemoChart::workingOfficers()`** now takes the post's sanctioned strength and
  the Recruitment Rule's promotion share. It states `status` and `source` on every
  row, adds `seat_reservation_category_id`, and **pads the chart with vacant
  chairs** up to the sanctioned strength — a category and a roster point only,
  because `DpcRowRules` marks every other column `prohibited_if:status,vacant`.
* **The source split is arithmetic, not a list to maintain.** Promotion chairs are
  filled first and the remainder called direct, which is the same calculation
  `VacancyEngine::chairsByQuota()` performs, floor rounding included. A spec may
  still name a source and override it.
* **`DemoJourneySeeder` and `DemoPipelineSeeder`** seat the Member the DPC will be
  allotted to. The Authorized Officer held that seat before; he proposes a DPC and
  does not decide it, so the seat was wrong on the business as well as on the
  check. The pipeline committees also carry the full attribute spread the
  composition rules ask for.
* **Two mixed-quota posts state their sources explicitly** — the seven Principals
  (75/25) and the three Lecturers (50/50) — because on a shared post *who arrived
  by which route* is business data, and filling promotion first would leave the
  promotion quota full and the DPC with nothing to fill.

## 3 · The guard that was missing

`tests/Feature/Demo/DemoSeederTest.php`, two tests:

* **the demonstration database builds** — seeds `DemoSeeder` and asserts a DPC is
  left at each stage of the workflow. Any throw fails it.
* **every seeded proposal would pass the completeness gate** — walks every seeded
  proposal through `DpcProposalCompletenessService` and reports, by post pair and
  screen, any section the portal would refuse.

**The assertions are about the gates, not the numbers.** Pinning *"vacancy 4"*
would make every legitimate change to the demo data a failing test. Pinning *"the
proposal the seeder built would be accepted"* is the thing that must never stop
being true, and it is what a presenter otherwise finds out in the room.

## 4 · Still open, and not touched here

* **`docs/demo/CURRENT_DEMO_FLOW.md` is one dataset behind.** `SAMPLE_DATA_GUIDE.md`
  (regenerated 2026-09-07) is current: `DPC-2026-SE-0001`, Block Education Officer →
  Additional Director, sanctioned **10**, vacancy **4**, **Main 4 · Waiting 3 · Not
  eligible 3**. The flow document still states 4 working rows, vacancy 3, Main 3 ·
  Waiting 3 · Not Eligible 4, and names officers from the *seeded* journey DPCs
  (Ashok Kumar Dwivedi and others) rather than the CSVs' — so it is internally
  inconsistent as well as stale. Counts appear at lines 27, 406, 414, 428, 567,
  701, 919 and 932.
* Two reference sheets disagree with the live DPC:
  `REFERENCE-department-posts.csv` gives Additional Director a sanctioned strength
  of **3** where the live DPC needs **10**, and
  `REFERENCE-pre-dpc-and-recruitment-rule.csv` describes a Head Master → BEO
  Pre-DPC rather than the BEO → Additional Director one.
* **`demo-evaluation-chart-with-hold.csv` is absent from disk** but referenced by
  `CURRENT_DEMO_FLOW.md` (§2, §5.20, §7) and `DEMO_DATA_GUIDE.md`.
* **§3's user counts are stale** — the document says 23 candidates of 34 users with
  2 role-less; the database holds **33 of 48 with 6**, and those six include a
  developer's own name and a duplicated committee record.
* **No `package-lock.json`**, so `npm audit` cannot run (`ENOLOCK`) and the asset
  build is not reproducible. Blocked on registry access from this host.
