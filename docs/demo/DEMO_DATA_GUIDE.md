# Demo Data Guide — e-DPC Portal (CGPSC)

**Every upload screen in the portal, what it accepts, and the file to use.**
Verified against `dpc_dev` on **2026-09-05** by actually uploading each file
through the application.

**Sample data folder:** `docs/demo/sample-data/`

```
docs/demo/sample-data/
├── demo-working-officers.csv                 10 rows   S16
├── demo-seniority-list.csv                   10 rows   S17
├── demo-evaluation-chart.csv                 10 rows   S18
├── demo-evaluation-chart-with-hold.csv       10 rows   S18 (Circulation variant)
├── demo-recruitment-rule-go.pdf               1 page   Government Order
├── demo-department-post-order.pdf             1 page   Post mapping order
└── demo-missing-record-justification.pdf      1 page   Missing-record evidence
```

All seven files already exist and are current. Nothing needs to be generated.

---

## The complete upload surface — 6 screens, and no others

| # | Screen | Who | What it takes |
|---|---|---|---|
| 1 | DPC → **Working Officers & Vacancies (S16)** | Authorized Officer | Spreadsheet |
| 2 | DPC → **Seniority List (S17)** | Authorized Officer | Spreadsheet |
| 3 | DPC → **Evaluation / Tabulation Chart (S18)** | Authorized Officer | Spreadsheet |
| 4 | Admin → **Recruitment Rules** → new / new version | Administrator | PDF |
| 5 | Admin → **Department Posts** → order row, and **Supersede** | Administrator | PDF |
| 6 | DPC → Evaluation Chart → officer row → **Missing records** | Authorized Officer | PDF |

> **Sanctioned Strength is typed, not uploaded** (corrected 2026-09-06). It is a
> required field on **Administration → Department Posts → Create/Edit**, and it is
> carried onto the replacement when a mapping is superseded. There is no importer
> for Department Posts. Before 2026-09-06 the field had no screen at all and the
> only writer was a seeder — see `docs/uat/TERMINAL_SUGGESTIONS.md` UAT-1.

**Modules that accept no upload at all** — worth knowing so nobody hunts for a
button that is not there: Pre-DPC (typed and derived), DPC Committee (typed or
picked from existing users), Vacancy Calculation (**entirely derived — there is
nothing to fill in**), Allotment, Scheduling, Proceedings, Circulation
(documents here are *generated* by the system, never uploaded), Reports, Audit
Trail, Self-Inspection, Candidate view.

---

# Part 1 — The three spreadsheet imports

## Rules that apply to all three

| Rule | Value |
|---|---|
| Accepted extensions | `.xlsx` `.xls` `.csv` `.txt` |
| Maximum file size | **5 MB** (`DPC_IMPORT_MAX_FILE_KB`, default 5120 KB) |
| Maximum data rows | **500** per file |
| Date format | `DD-MM-YYYY` |
| Reservation category | the **code** — `UR` / `SC` / `ST` / `OBC` / `EWS` — never an id |
| Column order | **does not matter**; unrecognised columns are ignored and reported |
| Encoding | **UTF-8** — mandatory for the S18 Hindi CR grades |
| Proposal state | the DPC must be in **Draft** or **Returned** |

**Nothing is saved on upload.** Every import is a two-step act: **Upload &
Preview** stages the file and shows Total / Valid / Rejected with the file's
SHA-256 and *integrity verified*; only **Commit** writes. Committing
**replaces** that section — it never appends. A rejected row can be inspected
through the **error report CSV** on the preview screen, which names the file row
and the reason.

### Upload order — one step is enforced

```
  1. demo-working-officers.csv    (S16)
             │  sets working strength → vacancy → zone
             ▼
  2. demo-seniority-list.csv      (S17)
             │  projects the officer set onto the chart, provisions logins
             ▼
  3. demo-evaluation-chart.csv    (S18)
```

| Step | Enforced? | If you skip ahead |
|---|---|---|
| 1 → 2 | Soft | On a capped-zone post pair, the zone would be sized from stale working-officer data |
| 2 → 3 | **Hard** | *"Upload the Seniority List first — the chart is generated from it."* |

The three PDF uploads have no ordering requirement.

---

## Screen 1 — Currently Working Officers (S16)

**Navigation path**
`Login as ao.schooleducation@cg.gov.in` → **Dashboard** → *DPC proposals being
drafted* → open `DPC-2026-SE-0001` → **Working Officers & Vacancies (S16)** →
**Upload from Excel**

**File name** `demo-working-officers.csv`
**Location** `docs/demo/sample-data/demo-working-officers.csv`
**Format** CSV (UTF-8, comma separated, one header row)
**Sample record count** **4 data rows** — 3 working officers and 1 vacant post

**Required columns** *(exactly as the screen lists them)*

| # | Column | Required | Notes |
|---|---|---|---|
| 1 | `SN` | ✔ | serial number |
| 2 | `STATUS` | — | `working` or `vacant`; absent means `working` |
| 3 | `SOURCE` | for `working` | `promotion` / `direct` / `deputation` — which quota this chair consumes |
| 4 | `EMPLOYEE NO` | for `working` | blank on a `vacant` row |
| 5 | `NAME` | for `working` | |
| 6 | `DOB` | for `working` | `DD-MM-YYYY` |
| 7 | `QUAL` | for `working` | must match a Qualification master name |
| 8 | `PROMOTION TO POST` | ✔ | the post being filled |
| 9 | `PROMOTION FROM POST` | ✔ | the feeder post |
| 10 | `CAT` | ✔ | `UR`/`SC`/`ST`/`OBC`/`EWS` — **also required on a vacant row**, because a vacant chair becomes a Clear vacancy *in its category* |
| 11 | `DATE OF JOINING IN SERVICE` | for `working` | |
| 12 | `DATE OF POSTING ON PROMOTION TO POST` | for `working` | |
| 13 | `DATE OF POSTING ON PROMOTION FROM POST` | for `working` | |
| 14 | `ROSTER POSITION` | — | integer |
| 15 | `DEPUTATION DATE` | — | start of deputation |
| 16 | `DEPUTATION TO` | — | end of deputation |
| 17 | `DATE OF RETIREMENT` | — | **drives the Retirement Vacancy** |

**Accepted aliases.** `EMP NO` / `EMPLOYEE NUMBER`; `NAME OF US` /
`NAME OF OFFICER`; `S.N.` / `SERIAL NO`; `DOP` / `DATE OF POSTING`;
`CATEGORY`; `DOR` / `RETIREMENT DATE`; `DEPUTATION FROM`; and the pre-rename
headers `CURRENT POST`, `FEEDER POST`, `DATE OF POSTING ON POST`. A workbook
downloaded before the 2026-08-24 rename still imports.

**Validation to expect**
- Rows may not exceed the Sanctioned Strength (**10** for Additional Director,
  Education — the *promotion* post, which is the one this chart is about).
- The chart must account for **every** sanctioned chair — 10 chairs, 10 rows.
- Rows recorded against one mode of appointment may not exceed what the
  Recruitment Rule's percentage allows.
- Total vacancy must be at least 1.

**Expected result**

> *"Currently Working Officers (S16) imported: 10 rows committed, 0 rejected."*

and the Vacancy Summary, **entirely derived**:

| | |
|---|---|
| Sanctioned Strength | **10** |
| Rows on the chart | **10** |
| Working Strength | **7** |
| Promotion % (from the Recruitment Rule) | 100.00% |
| Promotion chairs / Direct chairs | 10 / 0 |
| Promotion chairs held / Direct chairs held | **6** / 0 |
| Clear vacancies *(rows 8–10, `STATUS = vacant`)* | 3 |
| From deputation *(row 7 — he holds neither quota)* | 1 |
| Retirement Vacancy | 0 — *nobody on this chart retires inside the window* |
| **Total Vacancy** | **4** |
| Promotion Vacancy / Direct Vacancy | 4 / 0 |
| Zone of Consideration | **No formula** *(Class II → Class I is not given one)* |
| Vacancy period | 01-01-2026 to 30-11-2026 |

**Vacancy Breakdown** — derived, read-only, one row per category:
`UR · 1` · `ST · 1` · `OBC · 1` · `SC · 1` · **Total 4**. Three are `clear` — the
empty chairs — and one is `from_promotion`, the chair of the officer on deputation.

---

## Screen 2 — Final Seniority List (S17)

**Navigation path**
DPC detail → **Seniority List (S17)** → **Upload from Excel**

**File name** `demo-seniority-list.csv`
**Location** `docs/demo/sample-data/demo-seniority-list.csv`
**Format** CSV (UTF-8)
**Sample record count** **10 data rows** — employee numbers `SE/BEO/1001`–`SE/BEO/1010`

**Required columns**

| # | Column | Required | Notes |
|---|---|---|---|
| 1 | `SN` | ✔ | the **seniority number** |
| 2 | `EMPLOYEE NO` | ✔ | unique; also the officer's login id |
| 3 | `NAME OF US` | ✔ | |
| 4 | `DOB` | ✔ | `DD-MM-YYYY` |
| 5 | `QUAL` | ✔ | |
| 6 | `PROMOTION TO POST` | ✔ | |
| 7 | `PROMOTION FROM POST` | ✔ | |
| 8 | `CAT` | ✔ | |
| 9 | `DATE OF JOINING IN SERVICE` | ✔ | |
| 10 | `DATE OF POSTING ON PROMOTION TO POST` | ✔ | **fill this in** — it is one of the three facts Self-Inspection matches on |
| 11 | `DATE OF POSTING ON US` | ✔ | the feeder-post posting date |

> **Why column 10 matters.** Self-Inspection's default identity factors are
> **name + date of posting on promotion to post + date of birth**. If column 10
> is blank, no officer can ever match and the Self-Inspection step of the
> demonstration cannot be shown. The supplied file carries it, matching the
> feeder posting date — the same convention the project's own seeder uses.

**Validation to expect**
- Seniority numbers unique.
- On a class pair with a zone, the list must carry **exactly** the zone's
  officer count. Here the zone is uncapped, so 9 is accepted.

**Expected result**

> *"Seniority list saved."*

Three things happen on this one commit:

1. **9 seniority entries** recorded.
2. **9 evaluation rows projected** onto S18, identity copied across. The
   Authorized Officer can no longer add, remove or rename an officer on the
   chart.
3. **Promotion Candidate accounts provisioned.** The nine employee numbers in
   this file already exist as accounts, so they are **reused, not duplicated**
   — the user count stays at **34**. The panel *"Hand these over to the officers
   named"* lists any that were newly created, with their one-time temporary
   password.

---

## Screen 3 — Evaluation / Tabulation Chart (S18)

**Navigation path**
DPC detail → **Evaluation / Tabulation Chart (S18)** → **Upload from Excel**

**File name** `demo-evaluation-chart.csv`
**Location** `docs/demo/sample-data/demo-evaluation-chart.csv`
**Format** CSV — **UTF-8 is mandatory**, the CR grades are Hindi (`क+`, `क`, `ख`)
**Sample record count** **10 data rows** — the same ten officers as S17

**Required columns**

| Column | Required | Notes |
|---|---|---|
| `SN` | ✔ | must match the seniority list |
| `EMPLOYEE NO` | ✔ | must match the seniority list |
| `NAME OF US` | ✔ | |
| `DOB` | ✔ | |
| `QUAL` | ✔ | |
| `DOP` | ✔ | the feeder-post posting date |
| `CAT` | ✔ | |
| `PROMOTION TO POST` / `PROMOTION FROM POST` | ✔ | |
| `LOYALTY <year>` × 6 | ✔ | `Y` or `N` — one column per year |
| `IMMOVABLE PROPERTY <year>` × 6 | ✔ | `Y` or `N` |
| `CR <year>` × 6 | ✔ | Hindi grade, or blank if no CR that year |
| `TRIBAL AREA <year>` × 6 | — | `Y` or `N` |
| `TRIBAL AREA SERVICE YEARS` | — | integer |
| `NOTE` | — | a configured note code |

The supplied file uses years **2021–2026**. The per-year columns are matched by
pattern, not by fixed header, so a different span works — the engine uses the
**five most recent available CR years**.

**Validation to expect**
- **The officers must be exactly the Seniority List's.** Three faults are
  reported distinctly: a different number of rows; the same number but a
  different set of serial numbers; or the right officer with a different
  employee number.
- `Y` / `N` only in the loyalty, property and tribal-area columns.
- CR grades must be one of the configured values.

**Expected result**

> *"Evaluation Chart (S18) imported: 10 rows committed, 0 rejected."*

**53** CR grades, **54** loyalty flags and **54** property flags recorded. The
checklist reads *"9 officer(s) on the chart, matching the Seniority List"*, all
five sections turn green, and **Submit for Approval** becomes available.

> **A better template than the blank one.** On this screen press **Download this
> DPC's chart** — it produces a CSV pre-filled with exactly these nine officers
> and the right year columns. A blank S18 template is of little use now, because
> the importer will accept only the officers the Seniority List holds.

---

# Part 2 — The three PDF uploads

Common rules: **PDF only** (checked by extension *and* MIME type), **maximum
10 MB**. Each supplied sample is a valid 1-page PDF of about 2 KB and passes.

## Screen 4 — Recruitment Rule → Government Order

| | |
|---|---|
| **Navigation path** | `admin@cgpsc.gov.in` → **/admin** → **Recruitment Rules** → **+ New Rule** (or **New Version** on an existing rule) → **Government Order document** |
| **File name** | `demo-recruitment-rule-go.pdf` |
| **Location** | `docs/demo/sample-data/demo-recruitment-rule-go.pdf` |
| **Format** | PDF, max 10 MB |
| **Field rules** | `nullable · file · mimetypes:application/pdf · max:10240` — **optional** |
| **Sample record count** | 1 document |
| **Expected result** | The rule saves with the PDF attached; a **Download** link appears on the rule row |

> **Note for the demonstration.** In the normal flow you will not use this
> screen at all: Recruitment Rule **v1 is projected automatically** when the
> Administrative Head approves the Pre-DPC (Step 6 of the flow), with no PDF.
> This screen exists for the Administrator to attach the Government Order
> afterwards, or to raise a version by hand.

## Screen 5 — Department Post Mapping → Order document

| | |
|---|---|
| **Navigation path** | **/admin** → **Department Posts** → **+ New Post** *(order row → GO document)*, **Edit**, or **Supersede** |
| **File name** | `demo-department-post-order.pdf` |
| **Location** | `docs/demo/sample-data/demo-department-post-order.pdf` |
| **Format** | PDF, max 10 MB |
| **Field rules** | `file · mimes:pdf · mimetypes:application/pdf · extensions:pdf · max:10240`. **Required when superseding** a mapping; optional on an order row |
| **Sample record count** | 1 document per order |
| **Expected result** | The order is recorded against the mapping and the PDF becomes downloadable from the post's detail screen. Superseding closes the previous mapping and keeps it on record for the DPCs it sanctioned |

> **A file input cannot be repopulated.** If the form fails validation the
> browser drops the chosen file and it must be picked again — the screen says so
> rather than appearing to have kept it.

## Screen 6 — Evaluation Chart → Missing record → Supporting document

| | |
|---|---|
| **Navigation path** | DPC detail → **Evaluation / Tabulation Chart (S18)** → the officer's row → **Missing records** → **Add** |
| **File name** | `demo-missing-record-justification.pdf` |
| **Location** | `docs/demo/sample-data/demo-missing-record-justification.pdf` |
| **Format** | PDF, max 10 MB |
| **Field rules** | `required · file · mimes:pdf · max:10240` — **the document is required, full stop** |
| **Other fields** | **Record type** (which record is missing), **Year**, **Reason** (from the Record Reasons master — 7 rows seeded), free-text detail |
| **Sample record count** | 1 document per gap |
| **Expected result** | The gap is recorded against that officer's row and the chart shows a count of justified gaps beside their name. The PDF is downloadable from the gap |

**Why this screen matters in the demonstration.** The supplied evaluation chart
leaves **SN 6 — Shri Anand Kumar Mishra** without an immovable-property record
for 2025. That is exactly what the sitting later detects as an automatic
**hold**, which is what the Circulation DPC then resolves. If you want to show
the *other* branch — the department accounting for the gap up front rather than
having it held — record it here with this PDF before submitting.

---

# Part 3 — What the whole set produces, end to end

| Stage | Figure |
|---|---|
| Working officers chart | 10 rows — 6 working, 1 deputed, 3 vacant |
| Sanctioned / Working | 10 / 7 |
| **Total Vacancy** | **4** — Promotion 4, Direct 0 |
| Zone of Consideration | No formula for Class II → Class I |
| Seniority List | 10 officers · 10 candidate accounts created |
| Evaluation Chart | 10 officers · 50 CR grades · 50 loyalty · 50 property |
| Calculation | 10 assessed · 7 eligible · **Main 4** · Waiting 3 · Not listed 3 |
| Holds | none on the base chart — the Circulation variant holds **SN 4** |
| Documents produced | Proceedings + Promotion List |

The **Main List is 4 — exactly the Total Vacancy the engine derived from the
working-officers file.** That the two agree without anyone typing either number
is the point of the whole exercise.

---

# Part 4 — Verification record

**This part is no longer maintained by hand.** It used to record one person
uploading each file on one date, and it went stale the moment the sample data was
regenerated — the entry for a four-row working-officers file outlived the file
itself, which is the failure mode a dated record has.

`tests/Feature/Demo/SampleDataTest.php` is the record now. It seeds the
demonstration world, raises the Pre-DPC, composes the committee, and puts all three
CSVs through `ExcelImportService` exactly as the screens do, then asserts:

| Asserted | Value |
|---|---|
| Every file imports clean | 10 rows, **0 rejected**, on each of the three |
| Sanctioned / chart rows / working | 10 / 10 / 7 |
| Promotion chairs held | 6 — the deputed officer holds no quota |
| **Total Vacancy** | **4**, one in each of UR · ST · OBC · SC |
| Candidate accounts provisioned | 10, under `SE/BEO/1001`–`SE/BEO/1010` |
| Evaluation records | 50 CR · 50 loyalty · 50 property |
| Lists | **Main 4 · Waiting 3 · Not eligible 3**, Main = Total Vacancy |
| Super Seat | SN 1 |
| Circulation variant | SN 4 is `NE` unjustified, **`CIR`** once a reason and a PDF are recorded |

So a change to any sample file, or to the figures this guide quotes, fails the build
instead of surprising a presenter.

The three PDFs are unchanged and still validate:

| PDF | Rule set | Result |
|---|---|---|
| `demo-recruitment-rule-go.pdf` | `file, mimetypes:application/pdf, max:10240` | ✅ valid 1-page PDF |
| `demo-department-post-order.pdf` | `file, mimes:pdf, mimetypes:application/pdf, extensions:pdf, max:10240` | ✅ valid 1-page PDF |
| `demo-missing-record-justification.pdf` | `required, file, mimes:pdf, max:10240` | ✅ valid 1-page PDF |

> **On Self-Inspection and the seniority list.** An earlier note here said the
> `DATE OF POSTING ON PROMOTION TO POST` column had been filled so officers could
> match the Self-Inspection factors. That column is **empty again, and correctly so**
> — a candidate has not been posted to the promotion post yet, which is the whole
> reason they are a candidate. The screen does not need it: the shipped driver is
> `otp` (mobile plus `DEMO_OTP`), and the `identity_factors` driver reads the
> *promotion-from* post date, which the list does carry.

---

# Part 5 — If an upload fails on the day

| Symptom | Cause | Fix |
|---|---|---|
| *"This proposal can no longer be edited"* | The DPC is not in Draft or Returned | Raise a fresh DPC, or have the AH return this one |
| *"Upload the Seniority List first — the chart is generated from it"* | S18 attempted before S17 | Upload the seniority list, then the chart |
| *"…must match the Seniority List"* | Chart officers differ from the list | Re-upload `demo-seniority-list.csv`, then the chart |
| *"Working Officers cannot exceed the sanctioned strength"* | More than 10 rows | Use the supplied 10-row file |
| *"The chart does not account for every sanctioned post"* | Fewer than 10 rows | Add a row per remaining chair — an officer if one holds it, a `vacant` row if not |
| *"The Department Post Mapping for this post records no Sanctioned Strength"* | Master gap | Admin → Department Posts → set Sanctioned Strength |
| *"No vacancies recorded"* | Every chair is occupied | A DPC needs at least one vacancy to fill |
| CR grades show as `????` | Saved as ANSI from Excel | Re-copy the original and save as **CSV UTF-8** only |
| *"has a header row but no data rows"* | A blank template was uploaded | Upload the `demo-*.csv` file, not the downloaded template |
| Rows land in **Rejected** | A per-row rule failed | Open the **error report CSV** on the preview — it names the row and the reason |
| *"No recognisable column headers were found"* | Wrong file, or headers mangled | The message lists the expected headers; compare against the supplied file |
| *"No record under consideration matches those details"* (Self-Inspection) | A factor is blank or mistyped, or the sitting has already approved its result | Check the three factors; show the screen **before** Step 19 |

---

*Verified 2026-09-05 against `dpc_dev`. Every row count, message and derived
figure above is a transcription of what the application actually returned.*
