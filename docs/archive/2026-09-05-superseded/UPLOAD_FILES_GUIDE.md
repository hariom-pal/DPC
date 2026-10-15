> # ⚠ SUPERSEDED — do not use for the demonstration of 2026-09-05 onward
>
> This document describes a **seeded** database that no longer exists. Its DPC
> numbers (`SE-0001` … `SE-0009`), row counts, user counts, Self-Inspection
> details and base URL are all out of date, and following it will not work.
>
> The database was cleaned of all transactional data on **2026-09-05** and the
> demonstration is now given from an empty workflow. Use these instead:
>
> | For | Use |
> |---|---|
> | Logins and account status | `docs/demo/DEMO_USERS.md` |
> | The step-by-step flow | `docs/demo/CURRENT_DEMO_FLOW.md` |
> | Upload screens and sample files | `docs/demo/DEMO_DATA_GUIDE.md` |
>
> Kept for reference only.

---

# Upload Files Guide — e-DPC Portal (CGPSC)

**Prepared:** 2026-09-01 · **For the demonstration on:** 2026-09-02
**Files:** `docs/demo/sample-data/` · **Companion:** `DEMO_FLOW.md`, `DEMO_CHEAT_SHEET.md`

Every file listed here was **uploaded through the running application** and imports with **zero validation errors**. Verification ran inside a rolled-back transaction, so the demonstration database is untouched.

---

## The complete upload surface

The application has **six** upload screens — three spreadsheet imports and three PDF document uploads. There are no others.

| # | Upload | Kind | File provided |
|---|---|---|---|
| 1 | Currently Working Officers (S16) | Spreadsheet | `demo-working-officers.csv` |
| 2 | Final Seniority List (S17) | Spreadsheet | `demo-seniority-list.csv` |
| 3 | Evaluation / Tabulation Chart (S18) | Spreadsheet | `demo-evaluation-chart.csv` |
| 4 | Recruitment Rule — Government Order | PDF | `demo-recruitment-rule-go.pdf` |
| 5 | Department Post — order document | PDF | `demo-department-post-order.pdf` |
| 6 | Evaluation missing record — supporting document | PDF | `demo-missing-record-justification.pdf` |

### Uploads that do not exist

Three modules commonly assumed to have an upload do **not**, so no file is provided. Each absence is a design decision, and each is worth stating if asked:

| Module | Why there is no upload |
|---|---|
| **Promotion Candidates** | Candidates are *derived* from the approved Seniority List by `EvaluationCandidateService`. An import would let the candidate set disagree with the list it is supposed to come from. |
| **Vacancy Import** | Vacancy is stated on the Currently Working chart itself — a row whose `STATUS` is `vacant` — and the breakdown is derived from it. There is nothing separate to import, and since 2026-09-02 nothing separate to type either. |
| **Recruitment Rule Import** | Rules are entered on a form. Only the **Government Order PDF** is uploaded — that file *is* provided, as upload #4. |

---

## Rules that apply to all three spreadsheet imports

| | |
|---|---|
| **Accepted extensions** | `xlsx`, `xls`, `csv`, `txt` |
| **Maximum size** | 5 MB |
| **Encoding** | UTF-8. The provided files carry a **BOM**, as the application's own templates do |
| **Date format** | **DD-MM-YYYY** — read day-first, matching the PPT templates |
| **Category column (`CAT`)** | A **code**, not an id: `UR`, `SC`, `ST`, `OBC`, `EWS`. Resolved against the master during parsing |
| **Proposal state** | The proposal must be **Draft** or **Returned**. An approved or submitted DPC refuses the upload |
| **Commit behaviour** | An import **replaces** its whole section — it does not append. Uploading twice leaves one copy, not two |
| **Preview ≠ commit** | Upload produces a preview. Nothing reaches the proposal until **Commit** is pressed |

**The database starts empty.** There is no Pre-DPC, no DPC and no Recruitment Rule until the demo creates them. The first Pre-DPC will be numbered `PDPC-2026-SE-0001` and the first DPC `DPC-2026-SE-0001`.

### What the three sheets assume

The provided files are built for **one specific promotion pair**. Raise the Pre-DPC with exactly these values and all three import with zero errors; change any of them and the sheets stop matching.

| Field | Value the sheets assume | Why it matters |
|---|---|---|
| **Promotion To Post** | **Block Education Officer** | Sanctioned strength **4** — the Base Vacancy arithmetic depends on it |
| **Promotion From Post** | **Head Master, Middle School** | Written into the `PROMOTION FROM POST` column of all three sheets |
| **Minimum experience** | **10 years** | Every candidate has held the feeder post since 2013 or earlier, so all nine clear it |
| **Qualifications** | **Post-Graduate**, **Post-Graduate with B.Ed.**, **Ph.D.** | The `QUAL` column uses only these three |
| **Promotion / Direct share** | **100% / 0%** | Promotion Vacancy then equals Total Vacancy exactly |
| **DPC Year** | **2026** | Fixes the vacancy window (01-01-2026 – 30-11-2026) and the CR window (2021–2026) |

A submitted or approved DPC refuses uploads with *"This proposal can no longer be edited."* — keep it in **Draft** until all three sections are filled.

---

# 1. Currently Working Officers (S16)

| | |
|---|---|
| **Navigation** | Sign in as **`ao.schooleducation@cg.gov.in`** → **DPC Proposals** → **DPC-2026-SE-0001** → **Officers Under Consideration** → **Upload from Excel** |
| **URL** | `/dpc-proposals/{proposal}/working-officers` |
| **Expected File Name** | `demo-working-officers.csv` |
| **Expected Format** | CSV (UTF-8 with BOM) · XLSX / XLS / TXT also accepted · max 5 MB |
| **Sample Record Count** | **4 rows** — 3 officers in post, 1 vacant post (all four sanctioned posts) |

### Required columns

| Column | Required? | Notes |
|---|---|---|
| `SN` | **Yes** | Integer ≥ 1 |
| `STATUS` | No | `working` (default) or `vacant` |
| `SOURCE` | **Yes*** | *On a `working` row: `promotion` / `deputation` / `direct`. **Must be blank** on a `vacant` row. Defaults to `promotion` if the column is absent |
| `EMPLOYEE NO` | No | Text, max 255 |
| `NAME` | **Yes*** | *Required on a `working` row; **must be blank** on a `vacant` one |
| `DOB` | **Yes*** | *Same rule. Date, must be before today |
| `QUAL` | No | Text |
| `PROMOTION TO POST` | No | Free text — *not* checked against the post master |
| `PROMOTION FROM POST` | No | Free text |
| `CAT` | **Yes** | Required on **both** kinds of row. On an officer it is theirs; on a vacant post it is the roster category the vacancy falls to, and it is what the reserved-category analysis reads |
| `DATE OF JOINING IN SERVICE` | No | Date |
| `DATE OF POSTING ON PROMOTION TO POST` | No | Date |
| `DATE OF POSTING ON PROMOTION FROM POST` | No | Date |
| `ROSTER POSITION` | No | Integer ≥ 1 |
| `DEPUTATION DATE` | No | Date — start of deputation (alias of `DEPUTATION FROM`) |
| `DEPUTATION TO` | No | Date — end of deputation. Optional by design; **blank means still away**, which is the case that frees a post. Must be on or after the start date |
| `DATE OF RETIREMENT` | No | Date. Accepted by the importer although the blank template omits the column |

### The chart lists posts, not only people

A chart row is either an **officer in post** or a **vacant post**, and it must account for **every sanctioned post** — four sanctioned means four rows. Two columns carry that:

| Column | On a `working` row | On a `vacant` row |
|---|---|---|
| `STATUS` | `working` | `vacant` |
| `SOURCE` | **`promotion` / `deputation` / `direct`** — how the officer came into this post | must be blank |

Omit either column and the row is read as `working` / `promotion`, so a file written before this directive still imports unchanged.

### How the vacancy is worked out

The Recruitment Rule's percentage divides the **posts**, not the vacancy:

```
promotion posts    = sanctioned × promotion %
direct posts       = sanctioned − promotion posts

promotion vacancy  = promotion posts − posts held by officers who came
                     by promotion and are still substantively in post
direct vacancy     = direct posts − the same for direct recruits
total vacancy      = sanctioned − posts substantively held
```

A post counts as **substantively held** only if somebody holds it through the whole DPC Year. Four things mean it is not:

- it is a **vacant** row;
- the officer came on **deputation** — he holds it temporarily and consumes no quota;
- he **retires inside the window** (1 Jan – 30 Nov of the DPC Year);
- he is **away on deputation** across the window.

**Only the promotion vacancy is this DPC's.** The zone of consideration, the allotment and the seats the engine fills all read that number. A direct-recruitment vacancy is shown on the screen and recorded in the audit row, but this committee does not fill it — *direct hai to DPC ka lena-dena nahi*.

### What the provided file produces

Block Education Officer is sanctioned **4** and its rule is **100% promotion**:

| SN | STATUS | SOURCE | Officer | Holds the post? |
|---|---|---|---|---|
| 1 | working | promotion | Shri Ramkumar Verma | **No** — retires 30-06-2026, inside the window |
| 2 | working | deputation | Smt. Nirmala Chandrakar | **No** — deputation consumes no quota |
| 3 | working | promotion | Shri Bhupendra Singh Thakur | **Yes** — retires 31-12-2027 and his deputation ended 31-12-2025, both outside the window |
| 4 | vacant | — | — | **No** |

```
promotion posts 4 × 100%  = 4        direct posts = 0
promotion posts held      = 1  (SN 3)
promotion vacancy         = 4 − 1 = 3   <- the DPC fills these
direct vacancy            = 0 − 0 = 0
total vacancy             = 4 − 1 = 3
```

**Total 3, and all 3 are the DPC's**, because a 100%-promotion post has no direct posts. Change the Pre-DPC to **50% promotion** and the same chart gives promotion 2 / direct 1 — the split only bites when the rule splits.

Derived breakdown, one row per ground × category:

| Vacancy Type | Category | Count | From |
|---|---|---|---|
| Expected from promotion | UR | 1 | SN 1 — came by promotion, retiring |
| Expected from deputation | OBC | 1 | SN 2 — holds it on deputation |
| Clear vacancy | SC | 1 | SN 4 — the empty post |

*Row 3 is the control case: it carries both a retirement date and a deputation period, and contributes nothing, because the engine tests the **window** rather than the presence of a date.*

### Two refusals this brings

> **"The chart does not account for every sanctioned post."**
> Fewer rows than sanctioned posts. The unlisted posts still count as vacant, but nothing can be said about their reservation category, so the section stays incomplete until a row exists for each.

> **"The chart contradicts the Recruitment Rule."**
> A `direct` row against a 100%-promotion post — an appointment the rule forbids. Either the row names the wrong mode or the rule is out of date.

### Validation rules

- **Sanctioned ceiling (enforced at commit).** The **whole chart** — officers and vacant posts together — may not exceed the target post's sanctioned strength, because together they describe the establishment. Block Education Officer is sanctioned **4**, so a file of 5 rows is refused: *"Working Officers cannot exceed the sanctioned strength."*
- Per-row rules as above. A row failing any of them is **rejected individually** — the valid rows still commit.
- A deputation **end** date is optional and deliberately so: an open-ended deputation is the commonest case and the one that frees a post.

### Expected result after upload

Preview shows **4 valid, 0 rejected**. After Commit the chart lists all four sanctioned posts and **Total Vacancy becomes 3**:

See the derivation above — **Total Vacancy 3**, all three the DPC's at 100% promotion.



---

# 2. Final Seniority List (S17)

| | |
|---|---|
| **Navigation** | Sign in as **`ao.schooleducation@cg.gov.in`** → **DPC Proposals** → **DPC-2026-SE-0001** → **Seniority List** → **Upload from Excel** |
| **URL** | `/dpc-proposals/{proposal}/seniority-list` |
| **Expected File Name** | `demo-seniority-list.csv` |
| **Expected Format** | CSV (UTF-8 with BOM) · max 5 MB |
| **Sample Record Count** | **9 officers**, seniority numbers 1–9 |

### Required columns

| Column | Required? | Notes |
|---|---|---|
| `SN` | **Yes** | The **seniority number**. Integer ≥ 1, and must be unique within the file |
| `EMPLOYEE NO` | No | Text. Strongly recommended — the Evaluation Chart is matched against it |
| `NAME OF US` | **Yes** | Text |
| `DOB` | **Yes** | Date, before today |
| `QUAL` | No | Text |
| `PROMOTION TO POST` | No | Free text |
| `PROMOTION FROM POST` | No | Free text |
| `CAT` | **Yes** | `UR`/`SC`/`ST`/`OBC`/`EWS` |
| `DATE OF JOINING IN SERVICE` | No | Date |
| `DATE OF POSTING ON PROMOTION TO POST` | No | Date |
| `DATE OF POSTING ON US` | No | Date — posting on the **feeder** post |

### Validation rules

- **Zone of Consideration (enforced at commit).** Where the deck gives a formula, the file must carry **exactly** the zone size — not at most, exactly. For this post pair the zone is **uncapped** (the deck's "other cases": every officer meeting the experience qualification), so the gate does not fire and 9 is a free choice. On a capped pair a mismatch is refused with the zone count.
- **Duplicate seniority numbers are rejected.**
- Per-row rules as above.

### Expected result after upload

Preview shows **9 valid, 0 rejected**. After Commit the Seniority List holds 9 officers **and the Evaluation Chart is automatically projected onto the same 9** — the chart's identity columns are copied from the list, not re-entered.

Composition, senior first:

| S.N. | Employee No | Name | Category | Qualification | Posted on feeder post |
|---|---|---|---|---|---|
| 1 | CG200001 | Shri Ashok Kumar Dwivedi | UR | Post-Graduate with B.Ed. | 12-04-2010 |
| 2 | CG200002 | Smt. Rekha Sinha | UR | Post-Graduate | 01-07-2010 |
| 3 | CG200003 | Shri Devendra Kumar Bhagat | SC | Post-Graduate with B.Ed. | 15-11-2010 |
| 4 | CG200004 | Smt. Sushma Chandrakar | OBC | Post-Graduate with B.Ed. | 08-03-2011 |
| 5 | CG200005 | Shri Jagdish Prasad Netam | ST | Post-Graduate with B.Ed. | 22-09-2011 |
| 6 | CG200006 | Shri Anand Kumar Mishra | UR | Ph.D. | 04-02-2012 |
| 7 | CG200007 | Smt. Priyanka Agrawal | EWS | Post-Graduate | 19-08-2012 |
| 8 | CG200008 | Shri Ramautar Khunte | SC | Post-Graduate | 27-01-2013 |
| 9 | CG200009 | Smt. Phoolwati Markam | ST | Post-Graduate with B.Ed. | 14-06-2013 |

All five reservation categories appear (UR ×3, SC ×2, ST ×2, OBC ×1, EWS ×1), and every qualification is one the approved Pre-DPC accepts — **Post-Graduate**, **Post-Graduate with B.Ed.** or **Ph.D.** Each officer has held the feeder post since 2013 or earlier, comfortably clearing the rule's **10 years'** minimum experience.

---

# 3. Evaluation / Tabulation Chart (S18)

| | |
|---|---|
| **Navigation** | Sign in as **`ao.schooleducation@cg.gov.in`** → **DPC Proposals** → **DPC-2026-SE-0001** → **Tabulation Chart** → **Upload from Excel** |
| **URL** | `/dpc-proposals/{proposal}/evaluation-chart` |
| **Expected File Name** | `demo-evaluation-chart.csv` |
| **Expected Format** | CSV (UTF-8 with BOM) — **35 columns** · max 5 MB |
| **Sample Record Count** | **9 rows**, matching the Seniority List exactly |

### Required columns

Seven fixed columns, then four blocks of six year-columns, then two trailing columns:

| Column | Required? | Notes |
|---|---|---|
| `SN` | **Yes** | Must match a seniority number on the committed list |
| `EMPLOYEE NO` | No* | *Optional, but if present it **must match** the Seniority List for that S.N. |
| `NAME OF US`, `DOB`, `QUAL`, `DOP`, `CAT` | No | Accepted and validated if present; the Seniority List governs regardless |
| `LOYALTY 2021` … `LOYALTY 2026` | No | `Y` / `N` |
| `IMMOVABLE PROPERTY 2021` … `2026` | No | `Y` / `N` |
| `CR 2021` … `CR 2026` | No | **Devanagari grades**: `क+`, `क`, `ख`, `ग`, `घ` |
| `TRIBAL AREA 2021` … `2026` | No | `Y` / blank, recorded **against that CR year** |
| `TRIBAL AREA SERVICE YEARS` | No | Integer 0–100 |
| `NOTE` | No | Text, max 255. Codes in use: `PUNISHMENT`, `C.CASE`, `CIR` |

### Validation rules

- **Seniority List must exist first.** Otherwise: *"The Seniority List must be uploaded before the Evaluation Chart can be filled in."*
- **The officer set must match the Seniority List exactly** — same count, same S.N. set. A missing or unexpected S.N. is named in the refusal.
- **Employee numbers cannot be changed on the chart.** Where the list recorded one, the chart must carry the same value or blank.
- CR grades must be one of the five configured grades; years must fall between 1950 and next year.

### The CR year window is per-proposal, not global

The per-year columns span `max_cr_years` (**6**) ending at the proposal's **anchor year**. For an empty chart the anchor is the proposal's own year, so **`DPC-2026-SE-0001` anchors on 2026 and its window is 2021–2026**. The provided file carries exactly those six years, so it matches the pre-filled template the Tabulation Chart screen offers.

This matters because a file whose years fall outside the window still **imports successfully** and then scores against the wrong CR years — a silent wrong answer, which is worse than a rejection. If the anchor ever differs, download the **pre-filled template from the Tabulation Chart screen** and fill that in rather than editing this file's headers.

### Expected result after upload

Preview shows **9 valid, 0 rejected**. After Commit the chart is complete and the DPC can be calculated and submitted.

The nine rows are varied on purpose, so the merit ranking is not flat and every branch of the engine has something to act on:

| S.N. | Officer | What it demonstrates |
|---|---|---|
| 1 | Shri Ashok Kumar Dwivedi | Strongest record — five `क+`. The top of the merit list |
| 2 | Smt. Rekha Sinha | Solid `क`/`क+` record with no special condition |
| 3 | Shri Devendra Kumar Bhagat | **Tribal-area service recorded against 2023 and 2024**, the same years as those CRs; `TRIBAL AREA SERVICE YEARS = 2` |
| 4 | Smt. Sushma Chandrakar | **A missing CR year (2021)** — only five years on record. The record-gap case a Circulation DPC exists for; `NOTE = CIR` |
| 5 | Shri Jagdish Prasad Netam | **Loyalty certificate not on record for 2024** (`N`), plus tribal-area service 2021–2023 |
| 6 | Shri Anand Kumar Mishra | **Immovable-property return not filed for 2025** (`N`); `NOTE = PUNISHMENT` |
| 7 | Smt. Priyanka Agrawal | Weak CRs including a `ग`; `NOTE = C.CASE` |
| 8 | Shri Ramautar Khunte | Strong record with tribal-area service in the three most recent years |
| 9 | Smt. Phoolwati Markam | Weakest record, and the only `घ` — the bottom of the merit list |

Between them the nine rows use **all five CR grades** (`क+`, `क`, `ख`, `ग`, `घ`), **all five reservation categories**, both flag values (`Y` and `N`) on Loyalty and on Immovable Property, tribal-area service attributed to specific CR years, and **all three Note codes** (`PUNISHMENT`, `C.CASE`, `CIR`).

---

# 4. Recruitment Rule — Government Order (PDF)

| | |
|---|---|
| **Navigation** | Sign in as **`admin@cgpsc.gov.in`** → **Administration** → **Recruitment Rules** → *edit a rule* → **Government Order PDF** |
| **URL** | `/admin/recruitment-rules/{rule}/edit` |
| **Expected File Name** | `demo-recruitment-rule-go.pdf` |
| **Expected Format** | PDF only · max 10 MB |
| **Required Columns** | Not applicable — a document, not a dataset |
| **Sample Record Count** | 1 document |
| **Validation rules** | `file`, `mimetypes:application/pdf`, `max:10240` KB. The MIME type is read from the file's actual content, not its extension |
| **Expected result** | The rule saves and a **GO PDF** download link appears on both the index and the edit form. **Before upload no link is rendered at all** — which is why the download route returns 404 if reached directly with no document attached |

Content: 100% by promotion from Head Master, Middle School; 10 years' service on the feeder post; Post-Graduate / Post-Graduate with B.Ed. / Ph.D.

---

# 5. Department Post — Order Document (PDF)

| | |
|---|---|
| **Navigation** | Sign in as **`admin@cgpsc.gov.in`** → **Administration** → **Department Posts** → *edit a post* → **Order document** |
| **URL** | `/admin/department-posts/{post}/edit` |
| **Expected File Name** | `demo-department-post-order.pdf` |
| **Expected Format** | PDF only · max 10 MB |
| **Required Columns** | Not applicable |
| **Sample Record Count** | 1 document |
| **Validation rules** | `file`, `mimes:pdf`, `mimetypes:application/pdf`, `extensions:pdf`, `max:10240` KB — extension **and** content are both checked, so a renamed file is rejected |
| **Expected result** | The post saves with the order attached and downloadable from its order list |

Content: sanction of **4** posts of Block Education Officer, Pay Matrix Level 12, effective 01-04-2026 — the same strength the vacancy engine uses.

---

# 6. Evaluation Missing Record — Supporting Document (PDF)

| | |
|---|---|
| **Navigation** | Sign in as **`ao.schooleducation@cg.gov.in`** → **DPC Proposals** → **DPC-2026-SE-0001** → **Tabulation Chart** → *an officer* → **Missing Records** |
| **URL** | `/dpc-proposals/{proposal}/officers/{sn}/missing-records` |
| **Expected File Name** | `demo-missing-record-justification.pdf` |
| **Expected Format** | PDF only · **required** on this screen · max 10 MB |
| **Required Columns** | Not applicable |
| **Sample Record Count** | 1 document |
| **Validation rules** | `required`, `file`, `mimes:pdf`, `max:10240` KB. A year (1950–2200) and a reason are also required |
| **Expected result** | The gap is recorded against that officer and year with the document attached; the chart shows the record as **explained** rather than simply absent |

Content: written for **S.N. 7, Shri Nandkumar Sahu, employee CG200007, CR year 2019** — the officer carrying the `CIR` note. Certifies the CR is unavailable for reasons not attributable to the officer.

---

# Upload order

Two of the three spreadsheet steps are **enforced by the application**, not merely conventional.

```
  1. demo-working-officers.csv    (S16, 3 rows)
             │  sets working strength → vacancy → zone
             ▼
  2. demo-seniority-list.csv      (S17, 8 rows)
             │  projects the candidate set onto the chart
             ▼
  3. demo-evaluation-chart.csv    (S18, 8 rows)
```

| Step | Enforced? | If you skip ahead |
|---|---|---|
| 1 → 2 | Soft | On a capped-zone post pair, the zone would be computed from stale working-officer data |
| 2 → 3 | **Hard** | *"The Seniority List must be uploaded before the Evaluation Chart can be filled in."* |

The three PDF uploads have no ordering requirement.

---

# Verification record

| File | Rows | Valid | Rejected | Upload | Commit |
|---|---|---|---|---|---|
| `demo-working-officers.csv` | 3 | **3** | **0** | 302 | committed |
| `demo-seniority-list.csv` | 8 | **8** | **0** | 302 | committed |
| `demo-evaluation-chart.csv` | 8 | **8** | **0** | 302 | committed |

| PDF | Rule set | Result |
|---|---|---|
| `demo-recruitment-rule-go.pdf` | `file, mimetypes:application/pdf, max:10240` | **PASS** (1.8 KB) |
| `demo-department-post-order.pdf` | `file, mimes:pdf, mimetypes:application/pdf, extensions:pdf, max:10240` | **PASS** (1.6 KB) |
| `demo-missing-record-justification.pdf` | `required, file, mimes:pdf, max:10240` | **PASS** (1.7 KB) |

Derived state after the three commits: sanctioned **4** · working **3** · **total vacancy 3** · seniority **8** · evaluation rows **8**.

Database state after verification: `WO=3 SN=6 EV=6 import_batches=0` — identical to before the run.

---

# If an upload fails on the day

| Symptom | Cause | Fix |
|---|---|---|
| *"This proposal can no longer be edited"* | Not a Draft/Returned proposal | Use **DPC-2026-SE-0001** — the Draft raised for this UAT |
| *"The Seniority List must be uploaded before the Evaluation Chart"* | Wrong order | Upload S17 first, then S18 |
| *"…must match the Seniority List"* | Chart officers differ from the list | Re-upload `demo-seniority-list.csv`, then the chart |
| *"Working Officers cannot exceed the sanctioned strength"* | More than 4 rows | Use the supplied 3-row file |
| CR grades appear as `????` | File saved as ANSI CSV from Excel | Re-copy the original from `docs/demo/sample-data/`; save as **CSV UTF-8** only |
| *"has a header row but no data rows"* | A blank template was uploaded | Upload the `demo-*.csv` file, not the downloaded template |
| Rows land in **Rejected** | A per-row rule failed | Open the **error report CSV** from the preview screen — it names the row and the reason |
