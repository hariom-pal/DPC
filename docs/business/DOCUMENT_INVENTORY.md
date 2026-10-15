# DPC Business Document Inventory — `/home/hariom/Downloads`

**Scope of scan:** `/home/hariom/Downloads`, recursive, **read-only**. No file was created, moved, renamed or modified inside Downloads.
**Date of scan:** 2026-10-08
**Files examined:** 6,594 total; 222 document-type files (after excluding the two bundled code trees `csc-dsp-prod-main/` and `cscconnect/`, which contain no business documents) plus all images at depth ≤ 3.
**Method:** filename keyword match **and** full-text content extraction (`pdftotext` for PDF; OOXML part extraction for DOCX/XLSX/PPTX/ODT; raw read for CSV/MD/TXT), scored against the keyword set requested (DPC, promotion, recruitment rule, vacancy, seniority, CR/ACR, reservation, roster, sealed cover, CGPSC, committee, cadre, feeder, pay scale, and the Hindi equivalents पदोन्नति / वरिष्ठता / आरक्षण).
**OCR:** **not available** — no `tesseract` or equivalent OCR engine is installed on this machine. Images were therefore triaged by filename, date and **direct visual inspection** of the plausible candidates rather than by OCR. See §6.

---

## 1. Inventory — DPC-related documents

Priority key: **P1** = authoritative business input, must be read before coding; **P2** = working draft carrying live open questions; **P3** = superseded or derived, keep for history; **P4** = incidental/contextual, no business rules.

### P1 — Authoritative business sources

| # | File Name | Absolute Path | Type | Last Modified | Size | One-line Description | Business Category | Priority | Supersedes? |
|---|---|---|---|---|---|---|---|---|
| 1 | `Understanding of DPC with Coder Instructions.pdf` | `/home/hariom/Downloads/Understanding of DPC with Coder Instructions.pdf` | PDF, 6 pages (printed from `Microsoft Word - DPC_UNDERSTANDING`, authored 2026-09-25) | 2026-10-08 10:16 | 354 KB | Bilingual rule book: each Hindi रूल (1.1–4.4) is followed by a bracketed English **coder instruction**; covers vacancy engine pre-requisites, rounding, PH/disability reservation, sealed cover, CR/ACR evaluation and missing-CR handling, IPR audit, multi-cadre promotion quotas, undertakings. | Promotion Policy · Vacancy · Reservation · CR/ACR · Sealed Cover · Recruitment Rules · Government Orders | **P1** | **Yes — supersedes items 5 and 6, and overrides the CR-marks and vacancy-period numbers in items 3 and 4.** It is the only document that both states a rule and tells the developer what to build. |
| 2 | `CONVENTIONAL DPC PROCESS(1).pptx` | `/home/hariom/Downloads/CONVENTIONAL DPC PROCESS(1).pptx` | PPTX, 7 slides | 2026-07-29 12:57 | 200 KB | CGPSC's own deck ("Proposed and designed by G. Rathod, Officer In-Charge, IT Section, CGPSC"): proposal contents checklist, the three DPC types (Fresh / Review / Circulation), CR grade→mark table, qualifying-mark thresholds, zone-of-consideration formula. | DPC · Departmental Promotion Committee · CGPSC · CR/ACR · Committee · Seniority | **P1** | No. It is the **origin** document that items 3 and 4 quote as "the PPT". |

### P2 — Working drafts with open questions to CGPSC

| # | File Name | Absolute Path | Type | Last Modified | Size | One-line Description | Business Category | Priority | Supersedes? |
|---|---|---|---|---|---|---|---|---|
| 3 | `Mark Calculation Query.docx` | `/home/hariom/Downloads/Mark Calculation Query.docx` | DOCX | 2026-08-26 11:32 | 11 KB | "DPC Calculation & Eligibility Business Rules (Draft v1.0)" — promotion-hierarchy validation, five-year IPR rule, five-CR rule, loyalty certificate, reason master, tribal-area benefit, Circulation DPC, auto-rejection, candidate statuses EL/NE/CIR/REJ; ends with 7 decisions pending from CGPSC. | Promotion Policy · CR/ACR · Sealed Cover · Recruitment Rules · Reservation | **P2** | Partially supersedes the PPT (item 2) **by proposal only** — every deviation it proposes is explicitly marked "requires CGPSC approval" and none is approved in this folder. |
| 4 | `vacancy calculation rule.docx` | `/home/hariom/Downloads/vacancy calculation rule.docx` | DOCX | 2026-08-26 11:43 | 10 KB | "Vacancy Identification & Promotion Vacancy Calculation Business Rules — Draft v1.0 for Discussion & Validation with CGPSC": sanctioned/working/base/retirement/deputation vacancy, promotion quota from Recruitment Rule, audit trail, plus 8 clarifications sought (incl. cascade vacancy). | Vacancy · Recruitment Rules · Promotion Policy | **P2** | No — it is a *question paper*, not a ruling. Item 1 answers several of its questions differently (see §4). |

### P3 — Superseded / derived artefacts

| # | File Name | Absolute Path | Type | Last Modified | Size | One-line Description | Business Category | Priority | Supersedes? |
|---|---|---|---|---|---|---|---|---|
| 5 | `DPC_Requirements_Analysis.md` | `/home/hariom/Downloads/DPC_Requirements_Analysis.md` | Markdown, 283 lines | 2026-07-28 15:32 | 24 KB | Consolidated requirements analysis of the SRS + 14 UI screens: actors, 29 modules, workflow map, state transitions, draft permission matrix, ambiguity list. | DPC · Committee · Promotion Policy (requirements) | **P3** | Superseded by the repo's `docs/CGPSC_DPC_ARCHITECTURE_HANDBOOK.md` and by item 1. |
| 6 | `DPC_Architecture_Database_Design_V1.md` | `/home/hariom/Downloads/DPC_Architecture_Database_Design_V1.md` | Markdown, 497 lines | 2026-07-28 15:39 | 34 KB | V1 architecture & DB design built on a **Prisma / React** stack, with a 7-role RBAC seed, configurable eligibility-rule engine and approval workflow. | DPC (technical design) | **P3** | **Superseded.** Written for a stack the project no longer uses (the project reset to Laravel 12 + MySQL + Blade). It supersedes item 5 within its own lineage. |
| 7 | `dpc-template-currently-working-us(1).csv` | `/home/hariom/Downloads/dpc-template-currently-working-us(1).csv` | CSV, 3 lines (header + help + example) | 2026-09-07 15:56 | 3.2 KB | **Latest** Working-Officers upload template generated by the DPC application — adds `MOBILE` and `SEAT CATEGORY` columns and corrects the `DEPUTATION TO` help text to a date. Promotion post: *Director, Public Instruction*. | Seniority · Vacancy · Reservation (roster/seat category) | **P3** | **Yes — supersedes item 8.** |
| 8 | `dpc-template-currently-working-us.csv` | `/home/hariom/Downloads/dpc-template-currently-working-us.csv` | CSV, 3 lines | 2026-09-07 01:40 | 2.4 KB | Earlier same-day Working-Officers template; no `MOBILE`, no `SEAT CATEGORY`, `DEPUTATION TO` still documented as a place. Promotion post: *Additional Director, Education*. | Seniority · Vacancy | **P3** | Superseded by item 7 (14 hours older). |
| 9 | `dpc-template-final-seniority-list-us.csv` | `/home/hariom/Downloads/dpc-template-final-seniority-list-us.csv` | CSV, 3 lines | 2026-09-07 01:45 | 1.7 KB | Final Seniority List upload template generated by the application (11 columns, no status/roster/retirement fields). | Seniority | **P3** | No. Belongs to the same generation as item 8; **no refreshed counterpart exists in Downloads**, so it may be stale against item 7's column conventions. |
| 10 | `dpc-pipeline-20260822-075404.csv` | `/home/hariom/Downloads/dpc-pipeline-20260822-075404.csv` | CSV, 9 lines | 2026-08-22 13:24 | 299 B | A point-in-time pipeline export: 8 workflow stages with their PPT slide references (S12–S25…) and counts. | DPC (operational export) | **P3** | No — a dated snapshot, superseded by the live system. |

### P4 — Contextual / incidental (no business rules; listed for completeness)

| # | File Name | Absolute Path | Type | Last Modified | Size | One-line Description | Business Category | Priority | Supersedes? |
|---|---|---|---|---|---|---|---|---|
| 11 | `approval.pdf` | `/home/hariom/Downloads/approval.pdf` | PDF | 2026-09-09 14:19 | 113 KB | Travel-approval mail trail whose subject line is **"Minutes of Meeting – DPC Module Discussion with CGPSC \| 23 August 2026"**. The mail body is travel logistics; the MoM itself is an attachment that is **not present in Downloads**. | DPC (meeting reference) | **P4** | No — but see §5, it is the only trace of a missing MoM. |
| 12 | `approval-for-travel.pdf` | `/home/hariom/Downloads/approval-for-travel.pdf` | PDF | 2026-08-27 09:00 | 391 KB | 22-mail thread approving the CSC technical/PM team visit to the CGPSC office, 21–24 August 2026. Establishes the date of the requirements workshop; contains no rules. | CGPSC (engagement context) | **P4** | No. |
| 13 | `MoM-IC-20260821091529-CNZ5.pdf` | `/home/hariom/Downloads/MoM-IC-20260821091529-CNZ5.pdf` | PDF — **corrupt / empty** | 2026-08-21 09:27 | 69 B | A 69-byte PDF containing only a catalog object and trailer — **zero pages, no content**. The filename pattern (`MoM-…-20260821`) and its date place it inside the CGPSC visit week. | Unknown (likely Minutes of Meeting) | **P4** | Cannot be determined — the file has no recoverable content. See §5. |
| 14 | `WhatsApp Image 2026-08-31 at 10.29.38.jpeg` …`10.30.05`, `10.30.13`, `10.30.25`, `10.30.33`, `11.02.30` (6 files) | `/home/hariom/Downloads/` | JPEG, 120–154 KB each | 2026-08-31 10:31–11:02 | 810 KB total | Phone photographs of a laptop screen during the **end-to-end DPC workflow UAT** (Higher Education: Assistant Professor → Associate Professor, DPC Year 2026): the 26-step scenario, the demo role/credential table, and UAT observations OBS-005/OBS-006. Visually inspected (first and last of the series); no OCR engine available. | DPC (UAT evidence) | **P4** | No — transient test evidence; the same content is already recorded in the repo's `docs/BUSINESS_UAT_OBSERVATIONS.md`. |

---

## 2. Explicitly examined and ruled **out** (keyword hits that are false positives)

These matched the keyword sweep but contain no DPC business content. They are listed so the exclusion is auditable.

| File | Why it is not DPC-related |
|---|---|
| `senior.pdf`, `senior-1.pdf` | Name matched "senior" — both are TATA 1MG pharmacy invoices. |
| `Qualifications 07-08-2026 10_43_57.xlsx` / `…10_44_00.xlsx` | NSQF skill-qualification master (Hydrocarbon SSC, etc.). "Promotion/cadre/reservation" occur as generic vocational terms. The two files are **byte-identical duplicates** of each other, but of an unrelated dataset. |
| `ADV_CRPD_SCO_2026-27_11 (1).pdf` | State Bank of India "Central Recruitment & Promotion Department" recruitment advertisement — a bank job ad, not CGPSC. |
| `consilidate sheet for 3 employee.xlsx`, `Hariom Travel Detail.xlsx`, `Hotel Booking Detail.xlsx` | Flight/hotel booking sheets; "CGPSC" appears only as the purpose of travel. |
| `sd.xlsx` | CERT-In secure-application-design checklist. |
| `prompt.md`, `Observation.txt`, `Observation (1).txt`, `user_beema.odt`, `scholarship_*.csv`, `beema-*.csv` | CGMFPFED Welfare / scholarship platform — a different project. |
| ~30 further files matching only the substring "acr" | Matched inside words such as *acronym*, or inside base64/binary noise (security reports, Aadhaar API specs, résumés, DigitalLocker and e-Mitra integration guides). |
| All other images (ID proofs, marksheets, PAN/Aadhaar scans in `Document/`, `KOMAL DOC/`, `khushi-doc/`, `priyanka doc/`, `sufi doc/`, personal photos, `1.png` = FortiClient VPN screenshot) | Personal/HR documents and screenshots; no DPC flow diagram found among them. |

---

## 3. Version, duplicate and supersession analysis

**Exact duplicate across locations**
- `Downloads/CONVENTIONAL DPC PROCESS(1).pptx` is **byte-identical** (MD5 `b237377c95a4b2def3ccee2596a30820`) to `/var/www/html/DPC/docs/CONVENTIONAL DPC PROCESS.pptx`. The `(1)` suffix is a browser re-download, not a second version. The repo copy is the one in use.

**Version pair**
- `dpc-template-currently-working-us.csv` (01:40) → `dpc-template-currently-working-us(1).csv` (15:56, same day). Latest = the `(1)` file. The newer one adds `MOBILE` and `SEAT CATEGORY`, changes the promotion post from *Additional Director, Education* to *Director, Public Instruction*, and fixes the `DEPUTATION TO` description from a place to a date. **Counter-intuitively, here the `(1)` file is the newer one** — verify by timestamp, not by name.

**Lineage, not duplication**
- `DPC_Requirements_Analysis.md` (15:32) → `DPC_Architecture_Database_Design_V1.md` (15:39, same day). The second is the approved-decisions successor of the first. Both are now superseded by the project's own handbooks.

**Possible stale sibling**
- `dpc-template-final-seniority-list-us.csv` (01:45) was generated in the **same batch as the older** working-officers template. No refreshed version exists in Downloads, so treat its column set as unverified against the current application.

**No duplicates found among** items 1, 3, 4, 10 — each is unique by content hash and has no sibling version in Downloads.

**Not present in Downloads at all** (so Downloads is *not* a complete business-document set): `ODPC_NEW_FINAL.pptx`, `DPC- SRS.docx`, `Online DPC Module-.docx` — all three live only in the project repo under `docs/` and `docs/archive/`.

---

## 4. Documents containing conflicting business rules

Four genuine conflicts. Each is a number a developer could pick up from the wrong document.

**C-1 · CR grade → marks. The single most dangerous conflict.**
- PPT (item 2), slides 4–5: क+ = **4**, क = **3**, ख = **2**, ग = **1**, घ = **0** → five-year total out of 20.
- `Mark Calculation Query.docx` (item 3) §12 repeats that scale: A+ = 4 … D = 0.
- `Understanding of DPC…pdf` (item 1), rule 3.4, headed *नवीन ऑनलाइन अंकन प्रणाली* (new online marking system): उत्कृष्ट (क+) = **9**, बहुत अच्छा (क) = **7**, अच्छा (ख) = **5**, औसत (ग) = **3**, खराब (घ) = **1**.
→ Two incompatible scales. Item 1 presents its scale as the *new online* system and instructs that conversion be master-data driven; item 2's thresholds (15 / 13 / 11 / 10 out of 20) are arithmetically meaningless under the 9-7-5-3-1 scale. **The threshold table has not been restated for the new scale anywhere in this folder.**

**C-2 · Vacancy calculation period.**
- `vacancy calculation rule.docx` (item 4) §1 proposes **1 January – 30 November** of the DPC year.
- `Understanding of DPC…pdf` (item 1), rule 1.5: the proposal is effective on the **first day of the current year** and vacancies are reckoned **up to 31 December** of the proposed year.
→ A one-month difference that changes which retirements create a vacancy.

**C-3 · Fractional vacancy rounding.**
- Item 4 §"Fractional Promotion Vacancy" leaves 4.5 open (round up / round down / roster rule / other) and asks CGPSC.
- Item 1, rule 1.3 already answers it: the *significant rule* — ≥ 0.5 rounds **up**; and where both direct recruitment and promotion land on .5, the direct-recruitment .5 is discarded and the promotion post is incremented.
- But item 1, rule 2.1 states the **opposite** convention for PH/disability reservation: the fraction is **always discarded**, even at .9.
→ Three different rounding behaviours coexist; they are reconcilable (general vs. horizontal reservation) but must be coded separately and are not distinguished in item 4 at all.

**C-4 · Last-year CR grade for Class-I → Class-I.**
- PPT (item 2), slides 4–5: last year's CR must **not be "ग" (C)**.
- `Mark Calculation Query.docx` (item 3) §11 records that during discussion it was suggested the rule is actually "last year's CR **must be Grade A**" — and flags it unresolved.
→ Unresolved. Item 1 does not settle it.

**C-5 · Circulation DPC trigger (narrower conflict).**
- PPT (item 2), slides 3/7: circulation is used when IPR is missing **or 2 or more years of CR** are unavailable.
- `Mark Calculation Query.docx` (item 3) §9 proposes that **any single** missing CR or loyalty record puts the candidate into CIR — expressly "a proposed change requiring CGPSC approval".

**Also note, not a business-rule conflict but a stack conflict:** `DPC_Architecture_Database_Design_V1.md` specifies Prisma + React; the project has since reset to Laravel 12 + MySQL + Blade. Anyone reading that file as current will design against the wrong platform.

---

## 5. Gaps found during the scan

1. **The 23 August 2026 "Minutes of Meeting – DPC Module Discussion with CGPSC" is missing.** Only the covering mail (`approval.pdf`) is in Downloads; the MoM attachment itself is not. Given that items 3 and 4 were both written three days later and defer repeatedly to CGPSC, that MoM is likely where several answers live.
2. **`MoM-IC-20260821091529-CNZ5.pdf` is a 69-byte empty shell** — a failed download from the CGPSC visit week. If it was a Minutes of Meeting, it needs to be fetched again.
3. **No Recruitment Rules document, no Government Order, no roster register, and no gazette notification is present anywhere in Downloads** — every document that *cites* recruitment rules (items 1, 3, 4) treats them as an external input that the department uploads. The statutory source documents are simply not in this folder.
4. **No DPC flow diagram image was found**, and no OCR engine is installed, so a flow chart embedded in an un-named image could not be ruled out by text search. The visual spot-check found none.

---

## 6. Recommendations

### Master Business Document

**`/home/hariom/Downloads/Understanding of DPC with Coder Instructions.pdf`** — adopt as the Master Business Document.

Reasons: it is the newest (2026-10-08, authored 2026-09-25, three weeks after the drafts); it is the only document in the folder that states a rule **and** its implementation instruction in the same breath; it is the only one that covers the full statutory span — vacancy engine, PH reservation, sealed cover, ACR/missing-CR, IPR, retrospective DPC, multi-cadre quotas, undertakings — in one place; and the rules it states are **decided**, not proposed, unlike items 3 and 4 which are explicitly drafts awaiting CGPSC.

Two conditions on that adoption:
- It does **not** replace the CGPSC PPT (item 2) for the **zone of consideration** formula `(2 × vacancies) + 4`, the single-vacancy zone of 5, the 7× reserved-category expansion, or the super-seat rule — item 1 is silent on all of these, so **the PPT remains the authoritative co-source** for the zone and merit-threshold chapter.
- It is **not yet referenced anywhere in the project repository** (`grep` over `docs/` finds no mention of it). It should be checked into `docs/` and reconciled against `docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` before any code is written against it, because several of its rules — 1.3 rounding, 1.5 period, 3.4 marking scale — land directly on top of decisions the project currently records as open.

### To archive

Move to a dated `archive/` folder (keep for traceability; do not read as current):

| Document | Reason |
|---|---|
| `DPC_Architecture_Database_Design_V1.md` | Designed for a Prisma/React stack the project abandoned. Actively misleading if read as current. |
| `DPC_Requirements_Analysis.md` | Superseded by its own successor and by the repo handbooks. |
| `dpc-template-currently-working-us.csv` (the one **without** `(1)`) | Superseded 14 hours later by the `(1)` version. |
| `dpc-pipeline-20260822-075404.csv` | A dated operational snapshot with no lasting value. |
| `CONVENTIONAL DPC PROCESS(1).pptx` | Redundant — byte-identical to the repo copy. Delete the Downloads copy *only* after confirming the repo copy is the one under version control; keep a single authoritative copy. |
| The six `WhatsApp Image 2026-08-31` photos | UAT evidence already captured in `docs/BUSINESS_UAT_OBSERVATIONS.md`. |

Do **not** archive items 3 and 4 (`Mark Calculation Query.docx`, `vacancy calculation rule.docx`) despite their age — they are the live register of what CGPSC has *not yet* answered, and several of their questions remain open even after item 1.

### Conflicting-rule documents, ranked by risk

1. **Item 1 vs. items 2 + 3 — the CR marking scale (C-1).** Highest risk: the thresholds and the scale now disagree by a factor of ~2. Resolve before any merit calculation is touched.
2. **Item 1 vs. item 4 — the vacancy period (C-2)** and **the rounding rules (C-3).**
3. **Item 2 vs. item 3 — the Class-I → Class-I last-CR grade (C-4)** and **the Circulation DPC trigger (C-5)** — both already flagged as awaiting CGPSC in item 3 itself.

### Suggested immediate action

Retrieve the two missing meeting records (§5.1 and §5.2) and the Recruitment Rules / Government Orders (§5.3) from CGPSC. Without them, items 3 and 4 cannot be closed out, and the C-1 marking-scale conflict cannot be resolved from anything currently on this machine.

---

*Inventory only. No file in `/home/hariom/Downloads` was created, modified, moved or deleted.*
