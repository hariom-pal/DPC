# Demo documents and sample data — 2026-09-07

**Asked:** re-align the demo documents on the current dataset, regenerate the
missing Circulation CSV, and make sure every account's password is `Demo@2026`
without deleting anybody.
**Left green:** `pint` passed · `phpstan` No errors · **1451 passed**, 2 skipped,
4851 assertions.

---

## 1 · Passwords — 50 accounts, nobody deleted

`php artisan demo:credentials` sets one password on **every** user, clears
`must_change_password`, and stamps `email_verified_at` / `mobile_verified_at`.

| | |
|---|---|
| Accounts | **50** *(48 existing + 2 missing demo roles it created)* |
| Password `Demo@2026` verified by `Hash::check` | **50 of 50** |
| `must_change_password` still set | 0 |
| Unverified e-mail / mobile | 0 / 0 |

**The six role-less accounts were kept** and now sign in like everyone else —
including the developer's own name and the duplicated committee record. Verifying
both channels matters as much as the password: without it a correct password lands
on `/verify`, and there is no gateway behind the OTP.

## 2 · `demo-evaluation-chart-with-hold.csv` — regenerated and verified

The file three documents referenced and disk did not have. It is the base chart with
**one cell blanked** — SN 4's `CR 2023` — same BOM, same CRLF endings, diff of
exactly one line.

**The hold ground had to be corrected first.** A seeder comment described the
demonstration's hold as a *property*-ground hold. `RemarkCode` is explicit that a
missing Property Return is **`RJ` — rejected**, and that **`CIR` — held** is reached
by *"a mandatory CR or Loyalty record missing but justified with a reason and a
PDF"*. A property gap would have refused the officer outright, not held them.

Verified by importing, both halves:

| State | SN 4 | Counts |
|---|---|---|
| Chart imported, gap not accounted for | score 12, **`NE`** — four CRs against the five required | Main 3 · Waiting 3 · Not eligible 4 |
| Reason + `demo-missing-record-justification.pdf` recorded | **`CIR`** — held, not refused | Main 3 · Waiting 3 · **CIR 1** · Not eligible 3 |

SN 4 stays off the Main List either way, because a held promotion must not fill a
seat — and nobody is promoted up from the Waiting List to take it.

## 3 · The documents — five were stale, not one

`SAMPLE_DATA_GUIDE.md` was already correct. Everything else in `docs/demo/`
described the dataset that preceded the 2026-09-07 regeneration.

**What the engine actually produces**, now asserted rather than asserted-in-prose:

| | |
|---|---|
| DPC | Block Education Officer *(II, L12)* → Additional Director, Education *(I, L13A)* |
| Sanctioned / chart rows / working | 10 / 10 / 7 |
| Promotion chairs held | 6 — the deputed officer holds no quota |
| **Total Vacancy** | **4** — one each in UR · ST · OBC · SC |
| Zone | no formula for Class II → Class I |
| Candidate accounts | 10, under `SE/BEO/1001`–`SE/BEO/1010` |
| Evaluation records | **50** CR · 50 loyalty · 50 property — five years, not six |
| Lists | **Main 4 · Waiting 3 · Not eligible 3**, Super Seat SN 1 |

| Document | Was | Now |
|---|---|---|
| `CURRENT_DEMO_FLOW.md` | 4 rows, vacancy 3, Main 3 · Waiting 3 · Not Eligible 4, officers from the *seeded* journey DPCs, `CG200001`, 60/60/60, "Class-III to Class-II" | re-aligned throughout — §2, §3, §4, §5.8, §5.9, §5.10, §5.11, §5.18, §5.19, §5.20, §7 and the two checklists |
| `DEMO_DATA_GUIDE.md` | same stale figures; Part 4 a hand-kept upload log | figures corrected; Part 4 now points at the test that keeps them true |
| `DEMO_USERS.md` | 34 accounts, *"9 of 34 can sign in"*, 23 hand-listed candidates each marked ❌ | 50 accounts, all able to sign in; the per-candidate roster **removed** in favour of the generated sheet |
| `REFERENCE-department-posts.csv` | Additional Director sanctioned **3** | **10** |
| `REFERENCE-pre-dpc-and-recruitment-rule.csv` | described a Head Master → BEO Pre-DPC | describes the live BEO → Additional Director order, and says the promotion post needs strength 10 first |
| `REFERENCE-self-inspection.csv` | three identity factors, wrong field | mobile + `DEMO_OTP`, with the factor set as the alternative |
| `DEMO_VERIFICATION_REPORT.md` | read as current fact | kept as evidence of its own pass, with a superseded table at the top |

**Three errors found that were not staleness:**

* **§5.18 named the wrong identification method.** The shipped driver is `otp` —
  mobile, then `DEMO_OTP` — not the three identity factors. And where the factor
  driver is used, the configured factor is the *promotion-**from*** post date; the
  document named the *to* post, which for a candidate is **empty by definition**,
  because not having been posted to the promotion post is what makes them a
  candidate.
* **`Explain` was pointed at SN 5.** The adverse `घ` is on **SN 9**.
* **The zone note said Class III → Class II.** It is Class II → Class I.

## 4 · The guard

`tests/Feature/Demo/SampleDataTest.php` — two tests, 25 assertions. It seeds the
demo world, raises the Pre-DPC the presenter raises, composes a committee, and puts
all three CSVs through `ExcelImportService` as the screens do, then asserts every
figure above plus the Circulation variant's two states.

**Numbers are pinned here, having been deliberately left unpinned in
`DemoSeederTest`.** The distinction is what the assertion protects: there, the thing
that must stay true is *"the seeder builds a proposal the portal accepts"*, and
pinning figures would fail on every legitimate change to the demo data. Here the
thing that must stay true is *"the number on the page is the number on the screen"* —
a contract between a document and a dataset, and the only way it stays honest is for
a change to either side to go red.

It also surfaced something the documents had never stated, and which was then ruled
on — see §5.

## 5 · The sample record is Block Education Officer → Additional Director

**Ruled 2026-09-07.** The three CSVs were built for this pair, but nothing else in
the demonstration agreed with them:

* **§5.4 had the presenter raise the wrong order.** It gave Promotion To Post
  *Block Education Officer* and From *Head Master, Middle School* — an order for
  filling BEO posts, when the sample data fills **Additional Director** *from* BEO.
  Following the page produced an order the CSVs could not be imported against.
* **§5.3 pointed at the wrong post**, checking a sanctioned strength of 4 on Block
  Education Officer instead of **10 on Additional Director**, which is the number the
  chart is built for and the only one it balances on.
* **The seeded ladder did not sanction the pair at all.** Additional Director's rung
  named one feeder, Joint Director.
* **The seeded pipeline DPC on Additional Director set the post's strength to 3.**
  Sanctioned strength is one master record shared by every DPC on the post, so a
  fresh `demo:seed` left 3 in the master and the sample data would not have balanced.

Now:

| | |
|---|---|
| `DemoMasterSeeder` | Additional Director's rung carries **two feeders** — Joint Director 60%, **Block Education Officer 40%**, five years' experience. Both promotions are real, so the order sanctions both and each DPC names the one it runs |
| `DemoPipelineSeeder` | the Additional Director DPC is **10 sanctioned, nine held** — one vacancy, zone 2(1)+4 = 6, inside its six officers |
| `CURRENT_DEMO_FLOW.md` | §5.3, §5.4 and §5.5 name Block Education Officer → Additional Director; §5.5 explains why the feeder is asked when an order may sanction several |
| `REFERENCE-pre-dpc-and-recruitment-rule.csv` | the live order's values, and that minimum experience `5` is load-bearing |
| `SampleDataTest` | raises the DPC from the **seeded** order instead of building its own Pre-DPC, and asserts the strength is 10 rather than forcing it |

**That last change is the one that matters.** The test previously sanctioned its own
promotion, which proved only that the test could; it now proves the seeded world
supports the sample record. Every figure held unchanged — vacancy 4, Main 4 ·
Waiting 3 · Not eligible 3, SN 10 refused on the five-year rule.

**On a reset database no order exists at all**, which is the state the walkthrough
starts from and the reason §5.4 exists: the AO raises the order live and the
Recruitment Rule appears without anybody typing it. The seeded order matters for a
`demo:seed --fresh` database and for the master ladder being honest about the pair.

## 6 · Still open

* **The whole working tree is uncommitted** — 300-plus tracked files changed, 190
  untracked, and now this work on top. Unchanged from this morning and still the
  highest exposure in the project.
* **No `package-lock.json`**, so `npm audit` cannot run. `npm install
  --package-lock-only` needs registry access, which this host does not have.
* **TD-1 evidence grants** remain unproven — blocker B-3, and only demonstrable on a
  host with the `odpc_app` account.
