# Sample data — DPC-2026-SE-0001

**e-DPC Portal · CGPSC** · regenerated **2026-09-07**

| | |
|---|---|
| **DPC** | `DPC-2026-SE-0001` · Fresh DPC · Draft |
| **Department** | School Education Department |
| **Promotion** | Block Education Officer *(Class II, L12)* → Additional Director, Education *(Class I, L13A)* |
| **Proposal date** | 06-09-2026 · DPC Year 2026 |
| **Sanctioned strength** | **10** on the promotion post |

Three files, in the order they are uploaded. Each has been imported into this DPC and
the result verified — the figures below are what the engine actually produced, not
what the files were designed to produce.

---

## 1 · `demo-working-officers.csv` — S16, Working Officers

Ten rows, one per sanctioned chair on **Additional Director, Education**.

| Rows | What they are |
|---|---|
| 1–6 | Working, substantively holding the post |
| 7 | Working, **on deputation** to Tribal Welfare from 01-08-2025 to 31-07-2027 |
| 8–10 | **Vacant** chairs — `STATUS = vacant`, carrying only a category and a roster point |

**Vacancy = 4.** Ten sanctioned, six chairs substantively held. The deputed officer's
chair is open, which is the rule the seventh row exists to show.

> `DEPUTATION TO` is a **date** — the day the deputation ends — not a place. The
> column name is misleading and the template's help line now says so.

### How a vacant chair is known to be a promotion or a direct vacancy

**Not from the row.** A `vacant` row carries no quota, and its `SOURCE` cell is
ignored — `VacancyEngine::chairsHeld()` skips vacant rows outright. Leave it blank.

The split is arithmetic across the whole post, in three steps:

1. **The Recruitment Rule divides the sanctioned strength.** Additional Director,
   Education is 100% promotion, so all **10** chairs are promotion chairs and **0**
   are direct chairs.
2. **`SOURCE` on the *working* rows says which quota each sitting officer occupies.**
   Six officers arrived by promotion, so six promotion chairs are held. (A deputed
   officer holds neither quota — that is the seventh row.)
3. **Vacancy per quota is chairs minus chairs held.** Promotion `10 − 6 = 4`;
   direct `0 − 0 = 0`.

So all four vacancies here are the DPC's to fill. Had the rule been 50/50, there
would have been 5 promotion chairs and 5 direct, and this DPC would fill only what
was left of the promotion five.

**A department cannot mark one particular empty chair as "this one is for direct
recruitment".** The quota is a property of the post, not of the chair.

The `VACANCY TYPE` on the derived rows is a different question — *how the seat came
to be free*, not who fills it:

| Type | Means |
|---|---|
| `clear` | The chair was already standing empty |
| `from_promotion` | A promotion-quota officer left it |
| `from_retirement` | The holder retires inside the vacancy window |
| `from_deputation` | The holder went, or came, on deputation |

On this data: `from_promotion` SC ×1 (the deputed officer's chair) and `clear` ×3
(UR, ST, OBC).

---

## 2 · `demo-seniority-list.csv` — S17, Final Seniority List

Ten Block Education Officers, in seniority order.

* `DATE OF POSTING ON US` is when each took charge as BEO — the date the five-year
  qualifying service is counted from. Nine are 2018–2020; **SN 10 is 01-04-2024**, and
  is the officer who fails on service.
* `DATE OF POSTING ON PROMOTION TO POST` is **blank on every row**, and correctly so:
  nobody has been promoted yet.
* Categories are mixed UR / SC / ST / OBC so the reserved lists have something in them.

The Evaluation Chart is projected from this file. Uploading it a second time
re-projects the chart.

---

## 3 · `demo-evaluation-chart.csv` — S18, Evaluation Chart

The pre-filled chart with CR, Loyalty, Immovable Property and Tribal Area entered for
**2021–2025** — the five years the engine scores. 2026 is left blank, which is what a
year with no CR on record looks like.

### The result the engine produces

Qualifying mark for Class II → Class I is **13**. Marks are क+ 4 · क 3 · ख 2 · ग 1 ·
घ 0, plus one for tribal-area service in the same CR year.

| SN | Officer | Cat | Score | Outcome |
|---:|---|---|---:|---|
| 1 | Shri Ramesh Chandra Verma | UR | 20 | **Main** · Super Seat — क+ in all five years |
| 2 | Smt. Sunita Rani Patel | OBC | 18 | **Main** |
| 3 | Shri Jagdish Prasad Netam | ST | 18 | **Main** — 17 on CR, +1 tribal area |
| 4 | Smt. Kavita Bhaskar | SC | 15 | **Main** |
| 5 | Shri Prakash Kumar Yadav | OBC | 13 | Waiting |
| 6 | Shri Naresh Kumar Markam | ST | 13 | Waiting — 12 on CR, +1 tribal area |
| 7 | Smt. Anjali Shrivastava | UR | 14 | Waiting |
| 8 | Shri Suresh Kumar Sahu | OBC | 7 | Not eligible — below the qualifying mark |
| 9 | Shri Dinesh Kumar Kashyap | SC | 12 | Not eligible — **घ in 2023**, refused outright |
| 10 | Smt. Priya Dubey | UR | 15 | Not eligible — **under five years** on the feeder post |

**Main 4** — exactly the four vacancies. **Waiting 3. Not eligible 3**, each for a
different reason, which is the point of showing all three.

Note SN 9: a score of 12 is computed and then set aside. An adverse CR anywhere in the
window is a gate, not a deduction.

---

## Filling the CR without typing 150 boxes

The Evaluation Chart screen offers **Download pre-filled Excel template**. Every
officer is already on it with their identity columns filled; only Loyalty, Immovable
Property, CR and Tribal Area are blank. Fill those, upload, done — which is how
`demo-evaluation-chart.csv` was made.

Editing on screen still works, officer by officer, for a correction to one row.

---

## Every template explains itself

A template downloaded from an upload screen now carries two lines beginning with `#`
under the header:

1. **What each column is**, when to fill it and in what format — and, for the three
   columns that have a fixed set of answers, *the answers themselves*: the promotion
   post as a single value, the feeder posts the Recruitment Rule allows, and the
   Qualification master's list.
2. **A worked example row**, with this DPC's own post names already in it.

The importer skips `#` lines, so leaving them in the file is harmless.

The same table is on the upload screen under **What goes in each column**.

---

## Loading them

Draft → **Working Officers & Vacancies** → Upload → preview → confirm.
Then **Seniority List**, then **Evaluation Chart**, then **Calculate**.

Manual typing is switched off on the first two screens; the file is the way in.
`DPC_MANUAL_WORKING_OFFICERS=true` and `DPC_MANUAL_SENIORITY_LIST=true` bring the
grids back.
