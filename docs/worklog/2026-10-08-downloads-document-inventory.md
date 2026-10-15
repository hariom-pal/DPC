# Inventoried every DPC business document sitting in Downloads — 2026-10-08

**Asked:** *"I want to identify all DPC-related business documents available in my Downloads
folder… Inventory only. Do not change any file."* Then: *"bhai ye jo bhi finding hai kisi doc
me bana do taaki padh lu fir se."*

**Produced:** `docs/business/DOCUMENT_INVENTORY.md` (20.9 KB) and a styled, standalone
`docs/business/DOCUMENT_INVENTORY.html` for comfortable re-reading. Nothing inside
`/home/hariom/Downloads` was created, modified, moved or deleted.

---

## 1 · What the scan actually covered

6,594 files in Downloads. After excluding the two bundled code trees (`csc-dsp-prod-main/`,
`cscconnect/` — no business documents in either), **222 document-type files** plus every image
at depth ≤ 3.

Two passes, because filenames lie:

- **Filename pass** — the requested keyword set plus Hindi equivalents. Returned 12 hits.
- **Content pass** — `pdftotext` for PDFs, OOXML part extraction for DOCX/XLSX/PPTX/ODT, raw
  read for CSV/MD/TXT; each file scored by how many distinct keywords its *text* contained.

The content pass is what earned its keep. `Mark Calculation Query.docx` and
`vacancy calculation rule.docx` — two of the four most important documents in the folder —
have **no DPC keyword in their filenames** and would have been missed by a name search.
Conversely `senior.pdf` and `senior-1.pdf` matched on name and turned out to be 1MG pharmacy
invoices.

**Result: 14 DPC documents out of 6,594 files.**

## 2 · OCR was not available — and the gap is honestly recorded

No `tesseract` or equivalent is installed. Rather than silently skip the image requirement, I
triaged images by filename/date and then **visually inspected** the plausible candidates. The
six `WhatsApp Image 2026-08-31` files turned out to be phone photos of a laptop running our own
end-to-end UAT (the 26-step Higher Education scenario, the demo credential table, OBS-005/006).
`1.png` was a FortiClient VPN screenshot. No DPC flow diagram was found — but the report states
plainly that an un-named image containing one could not be ruled out by text search.

## 3 · The finding that matters most — two incompatible CR marking scales

| Source | Scale | Qualifying thresholds |
|---|---|---|
| `CONVENTIONAL DPC PROCESS.pptx` (CGPSC's own deck) | क+ 4 · क 3 · ख 2 · ग 1 · घ 0 | 15 / 13 / 11 / 10 out of 20 |
| `Mark Calculation Query.docx` §12 | A+ 4 … D 0 — repeats the PPT | same |
| `Understanding of DPC with Coder Instructions.pdf` rule 3.4, headed *नवीन ऑनलाइन अंकन प्रणाली* | क+ **9** · क **7** · ख **5** · ग **3** · घ **1** | **not restated anywhere** |

Under 9-7-5-3-1 a five-year total runs to 45, so the printed thresholds of 15/13/11/10 are
arithmetically meaningless against it. Whichever scale the merit engine uses, the other
document contradicts it. Nothing in Downloads resolves this.

Three further conflicts are recorded in the report: the vacancy period (1 Jan–30 Nov proposed
vs. "up to 31 December" ruled), fractional rounding (three different behaviours, two of them
reconcilable as general-vs-horizontal reservation), and the Class-I → Class-I last-CR grade.

## 4 · Master Business Document

**`Understanding of DPC with Coder Instructions.pdf`** — newest (2026-10-08; authored
2026-09-25, three weeks after the drafts), and the only document that states a rule *and* its
implementation instruction in the same breath. It is bilingual throughout: each Hindi नियम is
followed by a bracketed English coder instruction.

Two conditions attached to that nomination, both in the report:

1. It is **silent on the zone of consideration** — `(2 × vacancies) + 4`, the single-vacancy
   zone of 5, the 7× reserved-category expansion, the super-seat rule. The CGPSC PPT stays
   authoritative for that chapter.
2. It is **not referenced anywhere in this repository** — `grep` over `docs/` finds nothing. Its
   rules 1.3, 1.5 and 3.4 land directly on top of items the project currently records as open
   in `docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`. It needs checking in and reconciling
   before any code is written against it.

## 5 · Duplicates and a naming trap

- `CONVENTIONAL DPC PROCESS(1).pptx` in Downloads is **byte-identical** (MD5
  `b237377c95a4…`) to `docs/CONVENTIONAL DPC PROCESS.pptx`. A browser re-download, not a
  second version.
- The working-officers CSV pair: **the `(1)` file is the newer one** (15:56 vs 01:40 the same
  day), adding `MOBILE` and `SEAT CATEGORY` and fixing the `DEPUTATION TO` help text from a
  place to a date. Sorting by name would pick the wrong one.
- `Qualifications 07-08-2026 10_43_57.xlsx` / `…10_44_00.xlsx` are identical to each other —
  but it is an NSQF skill-qualification master, nothing to do with DPC.

## 6 · Gaps worth chasing

1. The **23 August 2026 "Minutes of Meeting – DPC Module Discussion with CGPSC"** is missing.
   Only the covering mail survives (`approval.pdf`); the attachment is not in Downloads. Both
   drafts were written three days later and defer repeatedly to CGPSC — that MoM likely holds
   several answers.
2. `MoM-IC-20260821091529-CNZ5.pdf` is a **69-byte empty shell** — catalog object and trailer,
   zero pages. A failed download from the CGPSC visit week.
3. **No Recruitment Rules, no Government Order, no roster register, no gazette notification**
   exists anywhere in Downloads. Every document that cites recruitment rules treats them as an
   external upload. The statutory sources are simply not on this machine.

## 7 · One thing I could not do

The findings were to be published to a Claude Doc for re-reading. The auto-mode classifier
denied the connector call as data exfiltration — correct, given the content names internal
staff, government departments and file paths. The HTML render is the local substitute; it is
self-contained, works offline, prints cleanly and follows the system colour scheme.

---

**Files touched:** `docs/business/DOCUMENT_INVENTORY.md` (new),
`docs/business/DOCUMENT_INVENTORY.html` (new), this worklog. No source, no migration, no test,
no change to Downloads.
