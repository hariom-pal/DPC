# Seniority List and Working Officers Upload: Validation Rules

Both lists are uploaded on the **DPC Proposal**, not on the Pre-DPC. They share one pipeline: upload → preview (validation) → error report → commit (`app/Domain/Import/Services/ExcelImportService.php`).

| | Working Officers (S16) | Seniority List (S17) |
|---|---|---|
| Template value | `currently_working_us` | `final_seniority_list_us` |
| Who these people are | Officers now holding the **Promotion To Post** | Candidates on the **Promotion From Post** (feeder) |
| Dummy file | `dummy_working_officers.csv` | `dummy_seniority_list.csv` |

The two lists are **different people**:
- one holds the post being filled;
- the other is the feeder cadre considered for it.

The code makes **no identity match** between them. Their only link is numerical: Working Officers → vacancy → zone → required Seniority List count. For that reason the dummy files share an ID *format* (`SE/<post>/<number>`) but no person appears in both.

---

## 1. Maximum file size

**5 MB** (`DPC_IMPORT_MAX_FILE_KB`, default 5120; `config/dpc.php:1813`; enforced in `UploadImportFileRequest::rules` :47-52).

## 2. Maximum rows

**500 data rows** (`DPC_IMPORT_MAX_ROWS`; `config/dpc.php:1818`). More rows than that fail the batch with "This file has more than 500 data rows…" (`ExcelImportService::parse` :709-711).

**Accepted file types and how the file is read**
- Accepted extensions: `xlsx`, `xls`, `csv`, `txt` (`config/dpc.php:1810`). This is an extension check only.
- **Use CSV.** An `.xlsx` with several sheets is read from the **last** sheet (`RawSheetImport`).
- **Header row:** the header must be **row 1**. Rows whose first cell starts with `#` are skipped, and so are blank rows.
- **Encoding:** UTF-8, with or without a BOM. The template itself has a BOM.
- **Hindi:** Hindi is fine **inside cells**, for example names. **Headers must be English**, because a Devanagari header normalises to nothing and is ignored.
- **Header matching:** by **name**, not position. Matching ignores case, spaces and punctuation (`normalizeHeading` :1058). Column order does not matter, and unknown columns are ignored.

**Formats that apply to both lists:**
- **Dates:**
  - **`DD-MM-YYYY`, zero-padded** (e.g. `05-01-1999`).
  - Also accepted: `DD/MM/YYYY`, `YYYY-MM-DD`, `DD.MM.YYYY`, `DD-MM-YY`, `DD/MM/YY`.
  - `5-1-1999` is **rejected**, because the text must round-trip exactly.
  - A bare number in a date column is read as an Excel serial number (`parseDate` :1072-1106).
- **Employee ID:** keep at least one letter or `/`, e.g. `SE/BEO/2001`. An all-digit ID may arrive as a number and fail the `string` rule *(inferred)*.
- **Mobile, if given:** 10 digits starting with 6–9. The dummy files leave it blank, so no phone number appears.

## 3. Mandatory columns

**Working Officers** (`DpcRowRules::workingOfficer` :86-187):

| Column | Rule |
|---|---|
| `SN` | required, integer ≥ 1 |
| `STATUS` | `working` or `vacant`, **lower-case** (`Working` is rejected); blank means `working` |
| `SOURCE` | for a working row: `promotion`, `direct` or `deputation` (any case; blank means `promotion`). Must be **blank** on a vacant row. |
| `NAME`, `DOB` | required on a working row; must be **blank** on a vacant row; DOB before today |
| `CAT` | required only when category-wise is on (`DPC_RESERVATION_CATEGORY_WISE`). With it off (the dev default) CAT is ignored and the pool category is used. The dummy file fills `UR`, which passes either way. |

**Seniority List** (`DpcRowRules::seniorityEntry` :194-227, `checkSeniorityRows` :596-613):

| Column | Rule |
|---|---|
| `SENIORITY NO` | required, integer 1–32767, unique in the file |
| `EMPLOYEE ID` | required, text ≤ 255, unique in the file (case-insensitive) |
| `EMPLOYEE NAME` | required |
| `DATE OF BIRTH` | required, before today |
| `DATE OF APPOINTMENT` | required, before today |
| `CURRENT POST` | required; must equal the proposal's **Promotion From Post** name (case and spacing ignored) |

**Upload form field:** **List as on**. It must be **1 April of the year before the DPC year**; for DPC 2026 that is `01-04-2025` (`DpcProposal::seniorityReferenceDate` :484).

## 4. Optional columns

**Working Officers:**
- `EMPLOYEE NO`, `MOBILE`, `QUAL`;
- `PROMOTION TO POST`, `PROMOTION FROM POST` (free text; not checked);
- `SEAT CATEGORY`, `DATE OF JOINING IN SERVICE`;
- `DATE OF POSTING ON PROMOTION TO POST`, `DATE OF POSTING ON PROMOTION FROM POST`;
- `ROSTER POSITION` (integer ≥ 1);
- `DEPUTATION DATE`, `DEPUTATION TO` (on or after the deputation date);
- `DATE OF RETIREMENT`.

**Seniority List:**
- `DATE OF POSTING ON CURRENT POST`;
- also accepted: `MOBILE`, `QUALIFICATION`, `CAT`, `PROMOTION TO POST` (filled from the proposal when blank).

Accepted header aliases: `ImportTemplate::columnMap` :186-325. For example, `EMPLOYEE NO` and `EMP ID` both mean Employee ID on the Seniority List.

## 5. Duplicate officer detection

| | Working Officers | Seniority List |
|---|---|---|
| Within the file | **None.** No duplicate SN or employee-number check | Duplicate **Seniority No** or duplicate **Employee ID** rejects every row involved (`checkSeniorityRows` :544-586) |
| Same file uploaded again | Warning only (SHA-256 of the file) | same |
| Database | non-unique index on (proposal, SN) | unique (proposal, seniority number) |

## 6. Existing officer: updated or rejected?

**Neither: the whole list is replaced on every commit.**
- Working Officers: `DpcProposalService::saveWorkingOfficers` :383.
- Seniority List: `saveSeniorityEntries` :466.

On the Seniority List, the details already entered for an officer (CR, IPR, PH and so on) are **kept** when the new file carries the same **Employee ID**, matched exactly. An officer whose ID changed or who is no longer in the file loses those details (`EvaluationCandidateService::keyOf` :376).

## 7. Preview and validation screen

Yes. **Upload & Preview** opens a preview with:
- valid and rejected rows, 25 per page each;
- a duplicate-file warning;
- for the Seniority List, a kept / added / removed summary.

Rejected rows can be downloaded as a CSV error report with the columns `Row Number, Name, Errors`. Then press **Commit** or **Cancel**.

Batch statuses: `pending` → `previewed` → `committed` / `cancelled` / `failed`.

## 8. What happens to error rows

| | Working Officers | Seniority List |
|---|---|---|
| Rule | **Partial commit**: only valid rows are saved. Because commit replaces the list, a rejected officer is **dropped**. | **Whole file blocked** while any row is rejected: "This Seniority List has :count rejected row(s)…" |
| Checks at commit | Valid rows ≤ sanctioned strength. On a Vacancy Management proposal, the working counts must reconcile with the finalized record before the Seniority List is allowed. | List-as-on date. Zone count (Class I→I only). Working list reconciled. |
| Not checked on import (checked only on manual save) | Retirement inside the vacancy period; deputation overlap | — |

**Dummy file check (2026-10-23).** Both files went through the real HTTP routes `dpc-proposals.imports.store` → `imports.commit`, signed in as the AO, on the **in-memory test database**. The fixture copied dev:
- department SE;
- Block Education Officer (II) → Additional Director, Education (I), 2026;
- Recruitment Rule 100 % promotion;
- finalized record: sanctioned 10, working 7 by promotion.

Results:

| File | Category-wise off | Category-wise on |
|---|---|---|
| `dummy_working_officers.csv` | 10 rows, **0 rejected**, committed; reconcile working 7 / promotion 7 / direct 0 / deputation 0 = record | same |
| `dummy_seniority_list.csv` (as on 01-04-2025) | 10 rows, **0 rejected**, committed | same |

The check script was a throwaway test outside the repository. **The dev MySQL database was not touched.**

## 9. Where the data is saved

| | Tables written on commit |
|---|---|
| Both | `import_batches` (file, hash, counts, who, when; kept permanently) and `import_rows` (each row's payload, errors and validity) |
| Working Officers | `dpc_working_officers`; vacancy rows in `dpc_vacancies` for chart-source proposals |
| Seniority List | `dpc_seniority_entries`, `dpc_proposals.seniority_list_as_on`, then `dpc_evaluation_rows` (projection) and Promotion Candidate login accounts |

The uploaded file is stored at `imports/{proposal_id}/…` on the imports disk.

## 10. Replace or re-upload

Yes, any number of times while the proposal is **Draft** or **Returned**, by the department **AO** only. The AH gets 403. Each commit replaces the list. After submission the lists are locked.

**Templates:**
- Dashboard → **Upload Templates** (`/dpc-templates`).
- From the upload widget: **Download blank template** (`/{proposal}/dpc-template/{template}`), which fills in the proposal's post names.

---

## The dummy files

**`dummy_seniority_list.csv`:**
- 10 Block Education Officers.
- Seniority 1–10.
- IDs `SE/BEO/2001`–`2010`.
- DOB 1971–1977, appointment 1996–2000, posting 2012–2016.
- Every row's CURRENT POST is "Block Education Officer".

**`dummy_working_officers.csv`:**
- 10 rows for the To Post "Additional Director, Education":
  - rows 1–7: **working, promotion**, IDs `SE/ADE/1001`–`1007`;
  - rows 8–10: **vacant**.
- All 19 template columns are present.

Why 7 + 3: the working counts must equal the finalized vacancy record (here 7/7/0/0), and the sanctioned strength is 10. Ten *working* officers against a sanctioned strength of 10 would leave **zero vacancy**, and the proposal stops with "no promotion vacancy". For a different record, change the number of working and vacant rows so the counts match it.

The names are invented. Mobile and Aadhaar are not used. Neither file contains real personal data.
