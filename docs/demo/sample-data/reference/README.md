# Reference data sheets — TYPED, NOT IMPORTED

**Nothing in this folder can be uploaded to the portal, and none of it is meant to
be.**

The e-DPC Portal has **exactly three CSV importers** — the Currently Working
Officers chart (S16), the Final Seniority List (S17) and the Evaluation / Tabulation
Chart (S18). They live one level up, in `docs/demo/sample-data/`, and they are real
files the application actually accepts.

Everything else in the portal is **typed on a screen or derived by the system**.
There is no importer for departments, users, department posts, recruitment rules,
committee members, CR/APAR, property returns, integrity certificates or punishment
records — and writing CSVs that pretend otherwise would be exactly the placeholder
data nobody wants at a demonstration.

So these files are **data sheets to type from**. Open one beside the screen it
names and copy the values across. That is all they are for.

| File | For the screen |
|---|---|
| `REFERENCE-department-registration.csv` | `/register` — New Registration, and `/verify` |
| `REFERENCE-department-users.csv` | the nine accounts that can sign in, and where each came from |
| `REFERENCE-cgpsc-chairman.csv` | Administration → CGPSC Chairman |
| `REFERENCE-department-posts.csv` | Administration → Department Posts |
| `REFERENCE-pre-dpc-and-recruitment-rule.csv` | Pre-DPC → New Pre-DPC |
| `REFERENCE-committee-members.csv` | DPC → DPC Committee (S15) |
| `REFERENCE-allotment-and-schedule.csv` | CGPSC → Allotments, and CGPSC → Schedules |
| `REFERENCE-self-inspection.csv` | `/self-inspection` — the three identity factors |

## Two of these are already in the database

**Department Posts** and the **CGPSC Chairman** are seeded and present. The sheets
record what is there so it can be checked, re-entered on a rebuilt database, or
shown as read-only during the walkthrough. Do **not** create a new Department Post
during the demonstration unless you intend to complete it.

## Recruitment Rules are never typed at all

`REFERENCE-pre-dpc-and-recruitment-rule.csv` records the **Pre-DPC** terms. The
Recruitment Rule itself is **projected into the master when the Administrative Head
approves that Pre-DPC** — v1 appears by itself. That is the point of Step 6 of the
walkthrough, so the Recruitment Rules master is deliberately empty before you begin.

## CR, property, loyalty and tribal service are columns, not files

They are not separate imports. All four are **columns of the S18 Evaluation Chart**,
one per year, and they are already filled in `../demo-evaluation-chart.csv`.
Punishment / disciplinary records are not modelled in this build at all.
