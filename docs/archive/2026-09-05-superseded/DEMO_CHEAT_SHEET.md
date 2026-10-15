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

# Demo Cheat Sheet — e-DPC Portal

**`http://localhost`** · password: **`Demo@2026`** · 2026-09-02 · re-verified 07:2x

---

## Login credentials

| Role | Login ID | Name |
|---|---|---|
| **Authorized Officer** | `ao.schooleducation@cg.gov.in` | Shri Anil Kumar Verma |
| **Administrative Head** | `ah.schooleducation@cg.gov.in` | Smt. Sunita Rathore |
| **Chairman** | `chairman@cgpsc.gov.in` | Dr. Ajay Kumar Shrivastava |
| **Member 1** | `member1@cgpsc.gov.in` | Shri Brijesh Kumar Pandey — holds SE-0001, SE-0002 |
| **Member 2** | `member2@cgpsc.gov.in` | Smt. Kavita Deshmukh — holds SE-0003 |
| **Member 3** | `member3@cgpsc.gov.in` | Dr. Suresh Chandra Netam — holds SE-0007 |
| **Member 4** | `member4@cgpsc.gov.in` | Shri Mohan Lal Kashyap — holds SE-0008 |
| **Member 5** | `member5@cgpsc.gov.in` | Smt. Anita Bhonsle — holds SE-0009 |
| **CGPSC Admin** | `admin@cgpsc.gov.in` | CGPSC Administrator |
| ~~Promotion Candidate~~ | **CANNOT SIGN IN** — random password, no delivery channel | use Self-Inspection instead ↓ |

Mobile numbers also work: admin `9990000000`, chairman `9000000101`, members `90000002 01–05`, AO `9000001101`, AH `9000001102`.

**Self-Inspection — no account needed** · `http://localhost/self-inspection`
Name `Shri Ashwani Kumar Sahu` · Joined post `01-03-2011` · DOB `08-06-1969` — ✅ verified working.

---

## Upload order — enforced, not optional

Target: **DPC-2026-SE-0004** (the only Draft). Files in `docs/demo/sample-data/`.

| # | File | Rows | Screen |
|---|---|---|---|
| 1 | `demo-working-officers.csv` | 3 | Officers Under Consideration |
| 2 | `demo-seniority-list.csv` | 8 | Seniority List |
| 3 | `demo-evaluation-chart.csv` | 8 | Tabulation Chart |

Each: **Upload from Excel → Choose file → Upload & Preview → Commit N Row(s)**

> Step 3 is **refused** before step 2 exists. Preview writes nothing — only Commit does.

PDFs (any order): `demo-recruitment-rule-go.pdf` · `demo-department-post-order.pdf` · `demo-missing-record-justification.pdf`

---

## Demo flow

| # | Who | Do | Min |
|---|---|---|---|
| 1 | AO | Login → dashboard shows only permitted tiles | 2 |
| 2 | AO | DPC Proposals → **SE-0004** → Readiness for Submission | 3 |
| 3 | AO | Upload working officers → **Total Vacancy 3** | 5 |
| 4 | AO | Upload seniority list → chart auto-fills with the same 8 | 4 |
| 5 | AO | Upload evaluation chart | 6 |
| 6 | AO | **CALCULATE** → **Explain Result** → **Submit for Approval** | 4 |
| 7 | AH | **SE-0005** → section review → **Approve, Sign & Forward to CGPSC** | 4 |
| 8 | Chairman | Allotments → **SE-0006** → **Allot** to a Member | 4 |
| 9a | member3 | Schedules → **SE-0007** → **Accept DPC** → **Schedule** | 3 |
| 9b | member4 | Proceedings → **SE-0008** → **Open Proceeding** | 3 |
| 9c | member5 | **SE-0009** → **CALCULATE** → **APPROVE BY CGPSC** | 4 |
| | | **Core path** | **≈42** |
| 10 | any | Reports → pipeline → Export CSV | 3 |
| 11 | member1 | **SE-0001** → Documents → Proceedings + Promotion List | 4 |
| 12 | — | **SE-0002** = Review DPC; original shows **SUPERSEDED** | 4 |
| 13 | member2 | **SE-0003/C1** = Circulation | 3 |
| 14 | *(logged out)* | **Self-Inspection** — officer's own view, no account | 3 |

---

## Reports to open

- **Reports → Where the DPCs are** — pipeline by stage (1 drafted · 1 awaiting approval · 7 forwarded · 6 allotted · 1 sitting open · 3 approved)
- **Reports → Export pipeline (CSV)**
- **Eligibility Calculation → Explain Result** — per-officer justification
- **DPC Result — Approval** — MAIN LIST / Promotions Held (CIR) / Not Listed
- **Allotments → DPC Allotment Report**
- **Proceedings → Documents** — Proceedings + Promotion List (SE-0001)
- **Admin → Audit trail** + CSV export

---

## Important business rules

1. **Vacancy is derived, never typed.** Sanctioned − working, plus retirements in the DPC year, plus deputations. Here: 4 − 3 = 1, +1 retirement, +1 deputation = **3**.
2. **The Tabulation Chart is projected from the Seniority List.** The chart cannot contain an officer the list does not.
3. **Working officers ≤ sanctioned strength.** Refused at commit.
4. **An import replaces its section**, it never appends. Re-runnable.
5. **An approved result is immutable.** No role can edit it. A correction is a **Review DPC**; the original survives marked *Superseded*.
6. **Approval is digitally signed** — both the Administrative Head's forward and the Commission's result.
7. **Return requires remarks**, and remarks are internal — the candidate sees *that* it was returned, never what was said.
8. **Role decides the application.** Tiles are not hidden, the routes are refused server-side.
9. **A posting is dated.** Transferring an officer closes the old posting and keeps it; access follows the posting.
10. **Login accepts e-mail, mobile, or employee number.** The third form exists for candidates, who have neither of the first two — though in this database their password was never deliverable, so use Self-Inspection to show the candidate's view.

---

## Known limitations

- **Only School Education is registered.** The other 8 departments exist as masters with no posts. A Higher Education demo is not possible.
- **Only DPC-2026-SE-0004 accepts uploads** — everything else is approved or submitted and correctly refuses.
- **Role Assignments can only show a role change**, not a cross-department transfer — there is no second registered department to move anyone to.
- **Notice Board is empty** (`portal_notices` = 0).
- **Import history is empty** until you upload — no past batches to browse.
- **Promotion Candidates cannot sign in.** All 14 hold a random password with no delivery channel (no e-mail, no mobile). Known gap — `SUGGESTIONS_AND_IMPROVEMENTS.md` #4. Demonstrate **Self-Inspection** instead.
- **Only 9 of 25 accounts can sign in** — 1 admin, 1 chairman, 5 members, AO, AH. That covers every role the flow needs.
- **No SMS gateway.** OTPs go to the mail log. Not needed: every demo account is pre-verified.
- **CSC DigiSign is not configured.** Signing uses the mock provider — correct behaviour behind a feature flag, never used in production.

---

## Things to avoid during the demo

| Avoid | Why |
|---|---|
| **`ds.gad@cg.gov.in`** | Not a portal account — an external committee member. Unverified, no role, leads nowhere |
| Uploading the **chart before the seniority list** | Refused by design; explain the rule instead of hitting it by accident |
| The **blank** S18 template from Upload Templates | Spans 2021–2026; this proposal needs **2019–2024**. Use the pre-filled one |
| **Editing the CSVs in Excel** and saving as ANSI | Destroys the Devanagari CR grades. Save as *CSV UTF-8* only |
| Uploading to an **approved/submitted DPC** | Correctly refused — unless you are demonstrating that refusal deliberately |
| The **Notice Board** screen | Renders fine but empty |
| **Running `php artisan test`** | ⛔ **DESTROYS THE DEMO.** The suite runs `dpc:reset-transactional-data`, which deletes `storage/app/private/{proceedings,circulations,signatures,imports,record-gaps}` by real path. Every stored document goes blank. Recovery: re-seed |
| **A Promotion Candidate login** (`CG100037` etc.) | Verified failing — random password, no delivery channel. Use Self-Inspection |
| **Demonstrating a transfer on Role Assignments** | Postings are dated the day the DB was seeded, so a transfer dated today is refused ("must take effect after that date"). Correct behaviour; simply avoid it |

---

## Recovery steps

| Problem | Fix |
|---|---|
| **Upload rejected some rows** | Open the **error report CSV** from the preview — it names row and reason. Or re-copy the pristine file from `docs/demo/sample-data/` |
| **"This proposal can no longer be edited"** | You are not on SE-0004. It is the only Draft |
| **"Seniority List must be uploaded first"** | Upload file 2, then file 3 |
| **"…must match the Seniority List"** | Re-upload `demo-seniority-list.csv`, then the chart |
| **CR grades show as `????`** | The file was re-saved as ANSI. Re-copy the original |
| **Wrong data committed / demo derailed** | `php artisan demo:seed --force` — full rebuild, **≈35 s**, verified working. Everything returns to the state this sheet describes |
| **SE-0004 no longer in Draft** (rehearsal submitted it) | Same rebuild command |
| **Page returns 500** | None was reachable in testing. Note the URL, refresh once, then move to the next step — do not debug on stage |
| **Blank page on a document download** | Storage and the database have drifted apart — almost always because the test suite was run. Rebuild as above |
| **Candidate login refused** | Expected. Use Self-Inspection (`/self-inspection`) |
| **Cannot sign in** | Check the login ID shape: e-mail, exactly 10 digits, or employee number. Password is `Demo@2026` |
| **Redis or MySQL down** | `php artisan db:show` and `redis-cli ping`. Sessions are in Redis — if it is down, nobody can log in |

**The single recovery command:**

```bash
php artisan demo:seed --force
```

Rebuilds the entire demonstration database in about 35 seconds. Verified working on 2026-09-01. Run it if anything goes wrong that you cannot explain in one sentence.
