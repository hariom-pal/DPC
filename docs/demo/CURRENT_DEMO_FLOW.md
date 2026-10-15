# Current Demo Flow — e-DPC Portal (CGPSC)

**Regenerated 2026-09-06 from the running implementation.** This is the **single
authoritative demonstration flow**. Earlier flow documents were archived on
2026-09-05 to `docs/archive/2026-09-05-superseded/` and must not be followed.

---

# 1 · Introduction

## Purpose of the demonstration

To show that the Chhattisgarh Public Service Commission can conduct a complete
Departmental Promotion Committee exercise online — from a department sanctioning a
promotion, through the Commission allotting and conducting the sitting, to signed
proceedings and a published promotion list — with **every figure derived from a
record somebody is accountable for, and nothing typed twice.**

Three things are worth making the audience notice, because they are what separate
this from a form-filling system:

1. **Nobody types a Recruitment Rule.** Approving a Pre-DPC projects it into the
   master. *(§5.4)*
2. **Nobody types a vacancy.** The engine derives it from the working-officers
   chart, the Government Order's sanctioned strength, and each officer's own
   retirement and deputation dates. *(§5.8)*
3. **The Main List equals the Total Vacancy** — 4 and 4 — and the two agree because
   one produced the other, not because anyone reconciled them. *(§5.11)*

**Everything in this document was executed against the running application on
2026-09-07.** Every "Expected result" is a transcription of what came back, and
`tests/Feature/Demo/SampleDataTest.php` asserts the figures against the engine, so
a change to the sample data or to this page fails the build rather than surprising
a presenter.

---

# 2 · Demo Prerequisites

## Required services — both, or the demonstration will look broken

```bash
cd /var/www/html/DPC
php artisan serve --host=127.0.0.1 --port=8001    # the application
php artisan queue:work                             # NOT OPTIONAL
```

| Service | Why |
|---|---|
| `php artisan serve --port=8001` | There is no vhost. **Port 8000 on this machine serves a different application entirely** — the CGMFPFED Tendu Leaf platform — whose login screen looks superficially similar |
| `php artisan queue:work` | Notifications, OTP delivery and credential dispatch are **queued** listeners on Redis. Without a worker the notification bell stays empty all day and nothing is delivered |
| MySQL `dpc_dev` | running |
| Redis (password-protected) | cache, session and queue |

Then open **`http://127.0.0.1:8001/login`**.

## Environment

| Setting | Value |
|---|---|
| `APP_ENV` | **`local`** |
| `DEMO_OTP` | **`123456`** — see below |
| `MAIL_MAILER` | `log` |
| SMS | log driver (`FEATURE_SMS_GATEWAY=false`) |
| Signature provider | mock (`FEATURE_CSC_DIGISIGN=false`) |

## Demo OTP — local only

When `APP_ENV=local` the issued OTP **is** `DEMO_OTP` (`123456`). Use it wherever an
OTP is asked for, including Department Registration.

**Only the randomness is removed.** The OTP row is still written, it still expires,
the attempt counter still counts, it is still consumed on use, and **a wrong code is
still refused**. The gate is `app()->environment('local')`, not a configuration
flag — so `DEMO_OTP` present on a UAT or production host does nothing at all, which
is pinned by a test.

## Required sample CSV files

`docs/demo/sample-data/`

| File | Rows | Used at |
|---|---|---|
| `demo-working-officers.csv` | 10 | §5.8 |
| `demo-seniority-list.csv` | 10 | §5.9 |
| `demo-evaluation-chart.csv` | 10 | §5.10 |
| `demo-evaluation-chart-with-hold.csv` | 10 | optional — Circulation only |
| `demo-recruitment-rule-go.pdf` · `demo-department-post-order.pdf` · `demo-missing-record-justification.pdf` | 1 page each | optional PDF uploads |

Values for the **typed** screens are in `sample-data/reference/`. Full detail:
**`docs/demo/SAMPLE_DATA_GUIDE.md`**.

## Database state before you begin

Every transactional table at **zero rows**, every counter reset — so the first
Pre-DPC will be `PDPC-2026-SE-0001` and its DPC `DPC-2026-SE-0001`. **9 accounts**,
9 departments (**one registered**), 9 department posts, **Recruitment Rules
deliberately empty**.

`php artisan dpc:reset-transactional-data` is what produces this state: it clears
every Pre-DPC, DPC, import, calculation and document, and **keeps every master record
and every user account**. Follow it with `php artisan demo:credentials` so all nine
accounts share `Demo@2026`. **It creates nobody** — since 2026-10-05 it only resets
passwords, writes the sheet, and reports how many departments hold accounts.

---

# 3 · Demo Users

**Password for every account that can sign in: `Demo@2026`**

The login box accepts **three** kinds of identifier and picks the column by shape:
contains `@` → e-mail · exactly 10 digits → mobile · anything else → employee number.

## The nine accounts the walkthrough is driven from

| Role | Name | Username / Login ID | Password | Permissions — what this role may reach |
|---|---|---|---|---|
| `admin` | CGPSC Administrator | `admin@cgpsc.gov.in`<br>*(or `9990000000`)* | `Demo@2026` | Every master (departments, posts, pay scales, qualifications, reservation, committee types & configurations, CGPSC Chairman, DPC types, vacancy types, record & review reasons, notices), User Management, Pre-DPC↔DPC links, **Audit Trail**, Integrations, Reports. **Substitute a committee member at any stage** |
| `chairman` | Dr. Ajay Kumar Shrivastava | `chairman@cgpsc.gov.in`<br>*(or `9000000101`)* | `Demo@2026` | **Allotments** — allot / re-allot, naming the Allottee, the DPC Chairman and the Completion Deadline. Sees **every** allotment, schedule and proceeding. Circulations. Reports |
| `member` | Shri Brijesh Kumar Pandey | `member1@cgpsc.gov.in`<br>*(or `9000000201`)* | `Demo@2026` | Only DPCs **allotted to them**: accept, schedule, reschedule, open the sitting, correct, calculate, approve the result, generate and publish documents, circulation consent. Reports |
| `member` | Smt. Kavita Deshmukh | `member2@cgpsc.gov.in` · `9000000202` | `Demo@2026` | as above |
| `member` | Dr. Suresh Chandra Netam | `member3@cgpsc.gov.in` · `9000000203` | `Demo@2026` | as above |
| `member` | Shri Mohan Lal Kashyap | `member4@cgpsc.gov.in` · `9000000204` | `Demo@2026` | as above |
| `member` | Smt. Anita Bhonsle | `member5@cgpsc.gov.in` · `9000000205` | `Demo@2026` | as above |
| `authorized_officer` | Shri Anil Kumar Verma | `ao.schooleducation@cg.gov.in`<br>*(or `9000001101`)* | `Demo@2026` | Pre-DPC (create, submit, amend, cancel), DPC Proposals (all five sections, imports, calculate, submit), Circulations, Templates, Reports, Notifications — **own department only** |
| `administrative_head` | Smt. Sunita Rathore | `ah.schooleducation@cg.gov.in`<br>*(or `9000001102`)* | `Demo@2026` | The same screens **read-only on sections**, plus the S21–S25 review walkthrough and **Approve / Return with signature**. Reports |

## There are no other accounts

The database holds **nine**, and the nine above are all of them. Until 2026-10-05 it
held fifty; the rest were removed so the walkthrough carries **one department and
nothing else**.

| Removed | Count | Why |
|---|---|---|
| Higher Education's AO and AH | 2 | The department has no posts and no recruitment rules, so the pair could reach nothing |
| Promotion Candidates | 33 | **§5.9 creates these itself.** Accounts standing under `SE/BEO/1001` … `SE/BEO/1010` would be *matched* rather than created, and the panel the presenter is told to read would report nothing provisioned |
| Role-less accounts | 6 | Registration attempts and committee records that never completed |

`php artisan demo:credentials` is what makes the sheet true: it sets one password on
every account, switches off the first-login change, and marks both contact channels
verified so nobody stops at an OTP screen. **Run it before the demonstration.**

> **It used to create accounts; it no longer does.** It invented an Authorized Officer
> and an Administrative Head for Higher Education every time it ran — which, after the
> prune, put back exactly what had just been removed. That was dropped on 2026-10-05.
> It now reports the department count instead: *"One department: School Education
> Department."*

**To obtain a candidate login without it:** as the AO, with the DPC still in
**Draft**, press **Reissue password** on the officer's row. The temporary password
is displayed **once**. *(Administration → Users → Reset Password issues one but does
not display it, so it is useless for a candidate.)*

---

# 4 · Import Sequence

Three importers, in this order. **The second → third dependency is enforced by the
application**; the first → second is not, but the vacancy and zone would be sized
from stale data if you skipped it.

```
  demo-working-officers.csv   (S16)
        │   sets Working Strength → Total Vacancy → Zone of Consideration
        ▼
  demo-seniority-list.csv     (S17)
        │   projects the officer set onto S18, provisions candidate logins
        ▼   ← HARD DEPENDENCY
  demo-evaluation-chart.csv   (S18)
```

Every import is **two steps**: **Upload & Preview** stages the file and shows Total /
Valid / Rejected with the file's SHA-256 and *integrity verified* — **nothing is
saved**; only **Commit** writes, and committing **replaces** that section rather than
appending to it.

| # | Screen | CSV file | Rows | Expected result |
|---|---|---|---|---|
| 1 | DPC → **Working Officers & Vacancies (S16)** | `demo-working-officers.csv` | 10 | Total 10 · Valid 10 · Rejected 0 → *"10 rows committed, 0 rejected."* **Total Vacancy 4** derived (UR 1, ST 1, OBC 1, SC 1); **no zone formula** applies to a Class-II → Class-I promotion |
| 2 | DPC → **Seniority List (S17)** | `demo-seniority-list.csv` | 10 | Total 10 · Valid 10 · Rejected 0 → 10 seniority entries, **10 evaluation rows projected**, and **ten Promotion Candidate accounts created** under the employee numbers the list carries — `SE/BEO/1001` … `SE/BEO/1010`, each password shown once |
| 3 | DPC → **Evaluation / Tabulation Chart (S18)** | `demo-evaluation-chart.csv` | 10 | Total 10 · Valid 10 · Rejected 0 → **50 CR grades · 50 loyalty · 50 property** — five recorded years per officer, not six, because the 2026 columns are deliberately blank. All five sections green; **Submit for Approval** enabled |

**Common rules.** `.xlsx` `.xls` `.csv` `.txt` · max 5 MB · max 500 rows · dates
`DD-MM-YYYY` · category as a **code** (UR/SC/ST/OBC/EWS) · **UTF-8 mandatory** for
the Hindi CR grades · column order does not matter, unrecognised columns are ignored
and reported · the DPC must be in **Draft** or **Returned**.

---

# 5 · End-to-End Business Flow

```
Department Login
      ↓
AO  ──►  Creates Pre-DPC
      ↓
AH  ──►  Reviews and approves the Pre-DPC        → Recruitment Rule v1 projected
      ↓
AO  ──►  Creates the Final DPC from the approved Pre-DPC
      ↓        (an unapproved order is never offered)
AH  ──►  Reviews (S21–S25) and approves the Final DPC
      ↓
SYSTEM ─► Routes the approved DPC to the default CGPSC Chairman   (no manual step)
      ↓
CGPSC Chairman assigns, in one act:
        • DPC Allottee        • DPC Chairman        • Completion Deadline
      ↓
DPC Allottee ─► Schedules the meeting   (Meeting Date ≤ Completion Deadline)
      ↓
SYSTEM ─► Notifies all Committee Members and all Candidates
      ↓
Committee conducts the DPC  ─►  Evaluation  ─►  Proceedings  ─►  DPC Completed
```

---

## 5.1 · Department Login

**Who** `ao.schooleducation@cg.gov.in` / `Demo@2026` · **Screen** `/login`

**Clicks** Email or Mobile → password → **Login**.

**Business explanation** One login screen serves every actor; what you may do comes
from the role on your account, not from what you typed. Sign out and back in as
`9000001101` to show the mobile form reaching the same dashboard.

**Validation** Wrong password → *"These credentials do not match our records."* ·
deactivated account named explicitly · unverified account diverted to `/verify` ·
rate-limited, repeated failures return **429**.

**Expected result** `/dashboard` — *Welcome, Shri Anil Kumar Verma · Under Secretary
· authorized officer · Your department: School Education Department*, six counter
tiles all **0**.

---

## 5.2 · Department Registration *(optional)*

**Who** nobody — public screen · **Screen** `/login` → **New Registration**
**Data** `sample-data/reference/REFERENCE-department-registration.csv`

**Clicks** Department (only the 8 unregistered appear) → District, Place, PIN,
Department Email → the AO block → the AH block → **Submit** → at `/verify` enter
**`123456`** for both OTPs.

**Business explanation** Registration is the **only** way a departmental account is
created — the Administrator never provisions one. One submission creates the
department, both officer accounts, and an active department assignment for each.

**Expected result** Both channels verified; department becomes `registered`; the AO
and AH can sign in.

---

## 5.3 · Department Post Mapping *(optional)*

**Who** `admin@cgpsc.gov.in` · **Screen** `/admin` → **Department Posts**

**Clicks** Point at the **Sanctioned Strength** column — eight posts carry a figure,
**Teacher shows "Not recorded"**. Open **Additional Director, Education**: Class
**I**, Level-13A, **Sanctioned Strength 10**. Press **Edit** to show the field is
required and editable, then **Cancel**. Then open **Recruitment Rules** — **empty**.

> **Additional Director is the post this walkthrough fills, and 10 is the number the
> sample data is built for.** It is already on the mapping; check it rather than
> change it. If it reads anything else the S16 import will not balance, and §5.8 will
> say so instead of guessing.

**Business explanation** Sanctioned Strength is maintained **here and nowhere else**:
it is what a Government Order says, and the Authorized Officer never types a vacancy
figure. It is required on create, editable, and carried onto the replacement when a
mapping is superseded.

**Expected result** 9 posts, Additional Director at **10**; Recruitment Rules
empty — which §5.4 explains.

> **Do not create a new Department Post live** unless you complete it.

---

## 5.4 · Pre-DPC → AH Approval → the Recruitment Rule appears

**Who** AO, then AH · **Screen** Pre-DPC → **+ New Pre-DPC**
**Data** `sample-data/reference/REFERENCE-pre-dpc-and-recruitment-rule.csv`

**Clicks (AO)** DPC Year `2026` · Promotion To Post **Additional Director,
Education** *(Pay Scale, Class and Promotion Duration fill in by themselves,
read-only)* · Promotion From Post **Block Education Officer** · share `100` ·
minimum experience `5` years · tick **Post-Graduate**, **Post-Graduate with B.Ed.**,
**Ph.D.** · Direct `0` / Promotion `100` · GO `F-2-14/2026/20-Three` · effective
`01-01-2026` → **Save Draft** → **Submit for Approval**.

> **This pair is the whole walkthrough: Block Education Officer → Additional
> Director, Education.** The three sample CSVs are built for it — the working-officer
> chart lists Additional Director's ten chairs, and the seniority list is ten Block
> Education Officers. An order for any other pair will import nothing.
>
> **Minimum experience `5` is load-bearing.** It is what refuses SN 10 at §5.11, who
> otherwise out-scores three officers who are listed.

**Clicks (AH)** Sign in → open the Pre-DPC → **Approve & Sign**.

**Validation** Both posts must be this department's, active and in force · the two
posts must differ · Direct % + Promotion % = **exactly 100** · feeder shares total
exactly 100% of the promotion quota · an active exercise for the same pair and year
is refused.

**Expected result** *"Draft saved as **PDPC-2026-SE-0001**"* → **Approved** and
signed. Now open Administration → **Recruitment Rules**: **one rule has appeared** —
Additional Director, Education **v1**, GO `F-2-14/2026/20-Three`, promotion
**100.00%**, effective 01-01-2026, **active**.

> **Nobody typed that rule.** This is why the master was empty in §5.3.

---

## 5.5 · AO creates the Final DPC

**Who** AO · **Screen** DPC Proposals → **+ New DPC**

**Clicks** **Raise from Pre-DPC** → the drop-down offers `PDPC-2026-SE-0001` **and
nothing else** → DPC Type **Fresh DPC** → Promotion From Post **Block Education
Officer** → **Create Draft**.

> **Why the feeder is asked at all when the order names only one.** An order may
> sanction several feeders for the same post — the seeded ladder gives Additional
> Director two, Joint Director and Block Education Officer — and the order alone
> cannot say which one a given DPC runs. So the DPC states it, and the service
> refuses to guess. Here there is one to pick.

**Business explanation** A DPC may only be raised from an order that is **approved
and still in force**. The post pair, pay scale, class, promotion duration and the
recruitment rule are all inherited — the officer chooses none of them. This is the
guarantee that a DPC cannot be conducted on terms nobody sanctioned.

**Expected result** *"Draft raised as **DPC-2026-SE-0001** from PDPC-2026-SE-0001."*
Readiness checklist appears — five sections, one green.

---

## 5.6 · Committee (S15)

**Who** AO · **Screen** DPC detail → **DPC Committee**
**Data** `sample-data/reference/REFERENCE-committee-members.csv`

**Clicks** Read the rules panel: *"3 to 6 members besides the Chairman"* and *"The
CGPSC Chairman sits on every DPC committee — the seat is added automatically."* Add
three members: **Shri Brijesh Kumar Pandey** (SC · Female · Bhar Sadhak Sachiv),
**Smt. Sunita Rathore** (OBC), **Shri Sanjay Kumar Agrawal** (Vibhag Sachiv) →
**Save Committee**.

**Worth demonstrating** Tick **ST** on a member who already has **SC** — the SC box
clears itself. A member holds **one** social category; ticking none shows a live
**UR (General)** badge. Submitting two from a stale form is refused by name:
*"Member 1 (…) is recorded as SC + ST…"*

**Expected result** *"Committee saved."* Four rows — the CGPSC Chairman at the top,
then three members. **S15** green.

---

## 5.7 · The three imports begin

Follow **§4** in order. Detail for each below.

---

## 5.8 · Working Officers (S16) — the vacancy derives itself

**Who** AO · **CSV** `demo-working-officers.csv` (10 rows)

**Clicks** Upload from Excel → **Upload & Preview** → **Commit 10 Row(s)**.

**Business explanation** The chart lists **posts, not only people**. Each row is a
sanctioned chair: six substantively held, one held by an officer away on
**deputation**, three `vacant`. Each occupied chair says which quota it consumes.
From that alone the engine derives everything. The retirement and deputation windows
run **01-01-2026 to 30-11-2026**, derived from the **DPC Year**, not from today — so
re-running in December gives the same answer as in March.

> **`DEPUTATION TO` is a date, not a place** — the day the deputation ends. The
> column name says otherwise and the template's help line now corrects it.

**Expected result**

| | |
|---|---|
| Sanctioned Strength | **10** *(on the Department Post Mapping for Additional Director)* |
| Rows on the chart | **10** — every sanctioned chair accounted for |
| Working Strength | **7** |
| Promotion % (from the Recruitment Rule) | 100.00% |
| Promotion chairs / Direct chairs | 10 / 0 |
| Promotion chairs held / Direct chairs held | **6** / 0 |
| Clear vacancies *(the three `vacant` rows)* | 3 |
| From deputation *(row 7's chair — he holds neither quota)* | 1 |
| Retirement Vacancy | 0 — *no officer on this chart retires inside the window* |
| **TOTAL VACANCY** | **4** |
| Zone of Consideration | **No formula** — for a Class-II to Class-I promotion every officer meeting the experience qualification is considered |

**Vacancy Breakdown**, read-only: one vacancy in each of **UR · ST · OBC · SC**.
Three are `clear` — the empty chairs at rows 8, 9 and 10 — and one is
`from_promotion`, the chair of the officer away on deputation.

**Remember those four categories** — they decide the Main List.

**Validation** Rows may not exceed Sanctioned Strength · the chart must account for
**every** chair · rows against one mode of appointment may not exceed what the
Recruitment Rule allows · Total Vacancy must be at least 1.

> A `vacant` row carries **only** a category and a roster point. Its `SOURCE` cell is
> ignored and every other column is refused, because there is nobody on the chair —
> which quota an empty chair belongs to is arithmetic across the post, not a cell on
> the row.

---

## 5.9 · Seniority List (S17) — and the candidate logins

**Who** AO · **CSV** `demo-seniority-list.csv` (10 rows)

**Clicks** Upload → **Commit 10 Row(s)** → **read the panel that appears**.

**Business explanation** Three things happen on one commit: the list is recorded;
**the Evaluation Chart is projected from it** (the officers appear on S18 by
themselves and cannot be added, removed or renamed there); and **Promotion Candidate
accounts are provisioned**. All **ten** are new, and each is created under the
employee number the list carries — `SE/BEO/1001` … `SE/BEO/1010` — not a minted one.
A number is only invented for an officer who arrives without one. The ten one-time
passwords appear in *"Hand these over to the officers named."*

**Expected result** 10 seniority entries · 10 evaluation rows · ten accounts created
· **S17** green: *"10 officer(s) in the feeder seniority list."*

**Now press Reissue password** on `SE/BEO/1001` — *Shri Ramesh Chandra Verma*, who
turns out to be the Super Seat — and write the value down; you need it at §5.19.

---

## 5.10 · Evaluation Chart (S18)

**Who** AO · **CSV** `demo-evaluation-chart.csv` (10 rows)

**Clicks** The ten officers are **already there**, identity filled, evaluation
columns empty. Upload → **Commit 10 Row(s)**.

**Business explanation** The chart carries, per officer per year: the CR grade in
Hindi, whether a loyalty certificate is on record, and whether the immovable-property
return was filed. The engine uses the **five most recent available CR years**.

**Validation** The officers must be **exactly** the Seniority List's — a different
count, a different set of serial numbers, or the right officer with a different
employee number is each reported distinctly and refused **before anything is
written**.

**Expected result** *"Evaluation Chart (S18) imported: 10 rows committed, 0
rejected."* **50 CR grades · 50 loyalty · 50 property** — ten officers × five
recorded years. The chart carries a 2026 column and it is deliberately blank, which
is worth pointing at: the engine scores the five most recent years that carry a
grade, so a column with nothing in it costs nobody a mark. **S18** green:
*"10 officer(s) on the chart, matching the Seniority List."* All five sections
complete.

---

## 5.11 · Calculate — Main 4 · Waiting 3 · Not Eligible 3

**Who** AO · **Screen** DPC detail → **Calculate**

**Expected result**

> *"For a Class-II to Class-I promotion no zone formula applies; every officer
> meeting the experience qualification is considered (10 available). **10 officer(s)
> assessed; 7 eligible; 4 on the Main List; 3 on the Waiting List.**"*

| List | # | SN | Officer | Cat | Score | |
|---|---|---|---|---|---|---|
| Main | 1 | 1 | Shri Ramesh Chandra Verma | UR | **20** | **Super Seat** — क+ in all five years |
| Main | 2 | 2 | Smt. Sunita Rani Patel | OBC | 18 | |
| Main | 3 | 3 | Shri Jagdish Prasad Netam | ST | 18 | 17 on CR, **+1 tribal area** |
| Main | 4 | 4 | Smt. Kavita Bhaskar | SC | 15 | |
| Waiting | 1 | 5 | Shri Prakash Kumar Yadav | OBC | 13 | |
| Waiting | 2 | 6 | Shri Naresh Kumar Markam | ST | 13 | 12 on CR, **+1 tribal area** |
| Waiting | 3 | 7 | Smt. Anjali Shrivastava | UR | 14 | |

**The three who are not listed, each refused for a different reason** — which is the
point of showing them:

| SN | Officer | Score | Why |
|---|---|---|---|
| 8 | Shri Suresh Kumar Sahu | 7 | Below the qualifying mark — **13** for a Class-II to Class-I promotion |
| 9 | Shri Dinesh Kumar Kashyap | 12 | **`घ` in 2023** — an adverse grade inside the window refuses outright |
| 10 | Smt. Priya Dubey | 15 | **Under five years** on the feeder post, so the Recruitment Rule is not satisfied. Note the score: she out-scores three officers on the lists and is still refused, because merit is assessed *after* the gates |

**Not Listed — 4.** SN 5 and SN 10 on a **disqualifying grade**; SN 6 and SN 9 on
score.

**Two things to point out.** The **Main List of 4 equals the Total Vacancy of 4**,
and its four categories — UR, OBC, ST, SC — are the four the vacancy breakdown
produced. Nobody reconciled them; one produced the other. And **SN 9 scores 12 and is
refused outright** — a `घ` anywhere in the five-year window is a hard gate, not a
deduction. Press **Explain** on SN 9: it names the year and the grade.

Every press of Calculate creates a **new** run; nothing is overwritten. The
provenance panel states the run id, the actor, the **rule version**, the application
version, and an **officer data fingerprint**.

---

## 5.12 · Submit → AH Review (S21–S25) → Approve, sign, forward

**Who** AO then AH · **Screen** DPC detail → **Submit for Approval**; AH → **Review**

**Clicks (AH)** Step through **S21 Selection of Posts → S22 DPC Committee → S23
Officers Under Consideration → S24 Seniority List → S25 Tabulation Chart** →
**Approve & Sign**. *(Or **Return for Correction** with remarks, to show the other
branch — the DPC keeps its number and the revision count increments.)*

**Business explanation** The submission gate re-checks all five sections **and** three
arithmetic invariants, because the Excel import writes sections without passing
through a form. The review walkthrough is read-only by construction. **One approval,
one signature, one transition, one audit event** covers all five sections.

**Expected result** Status **Approved**, `approved_at` and `forwarded_at` stamped,
signature attached — *"Proposal approved, signed, and forwarded to CGPSC."*

---

## 5.13 · System routes the DPC to the CGPSC Chairman — automatically

**Who** nobody. **No manual step.**

Approval sets `forwarded_at`, and the Chairman's allotment queue is filtered on
`status = approved AND forwarded_at IS NOT NULL`. The DPC **appears on his queue by
itself**.

**Expected result** Sign in as `chairman@cgpsc.gov.in` → **Allotments** →
`DPC-2026-SE-0001` is listed under *Proposals Awaiting Action*, unasked.

---

## 5.14 · CGPSC Chairman assigns — three decisions, one act

**Who** `chairman@cgpsc.gov.in` · **Screen** **Allotments**
**Data** `sample-data/reference/REFERENCE-allotment-and-schedule.csv`

**Clicks**
- **Allot to…** → `Shri Brijesh Kumar Pandey` — **the DPC Allottee**
- **DPC Chairman** → `Smt. Sunita Rathore` — **presides; a different person**
- **Must be concluded by** → `31-12-2026` — **the Completion Deadline**
- **Allot**

**Business explanation** The Chairman decides **who executes**, **who presides** and
**by when**, and the form collects all three because they are decided together —
there is no separate step to forget. The DPC Chairman list is **this DPC's own
committee**, and the Chairman's own seat is on it, so presiding personally is simply
that option. The designation is valid **for this DPC only** — a column on a committee
membership, not a role.

**Validation** The allottee must hold a Commission role and be active · the DPC
Chairman must be a **serving member of this DPC's own committee** (a seat from
another DPC is a field error) · the deadline is required and cannot be in the past.

**Expected result** *"DPC allotted."* The report shows the allottee, **DPC Chairman:
Smt. Sunita Rathore**, and the deadline. The Member is notified.

---

## 5.15 · DPC Allottee schedules the meeting

**Who** `member1@cgpsc.gov.in` · **Screen** **Schedules**

**Clicks** **Accept** → then deliberately enter **15-01-2027** at `11:00` and press
Schedule. **It is refused, against the date box:**

> *"This DPC must be concluded by 31-12-2026, so it cannot be held on 15-01-2027.
> Choose an earlier date, or ask the CGPSC Chairman to extend the deadline."*

Now enter **22-12-2026** at **11:00** → **Schedule**.

**Business explanation** **Only the DPC Allottee schedules** — not the Chairman, and
not the presiding member unless they are the same person. The date must fall **on or
before the Completion Deadline**, and so must any later reschedule: without that,
rescheduling would be a way out of the deadline and the return-to-Chairman rule that
rests on it would never fire. Rescheduling requires a reason and keeps the old date
beside the new one.

**Expected result** *"DPC accepted."* then *"DPC scheduled."* Schedule Report shows
22-12-2026 11:00.

> **These three dates are the only thing in this script that expires.** The deadline is
> validated `after_or_equal:today`, so once 31-12-2026 passes, §5.14 stops with *"The
> completion date cannot be in the past"* and the walkthrough cannot continue. Roll all
> three forward together, keeping the shape: **deadline** comfortably ahead of today,
> **refused date** after the deadline, **accepted date** on or before it. The sitting may
> be in the future — `DPC_ALLOW_EARLY_PROCEEDING` defaults to true, so §5.17 can open it
> early.

---

## 5.16 · System sends notifications

**Automatic**, the moment the date is fixed or moved.

**Expected result** **14 notifications** — every serving committee member and every
officer on the Seniority List, carrying the **DPC date**, the **DPC time** and
**joining instructions**. Open the bell as `member1` to show it.

| Audience | Channels |
|---|---|
| 4 committee members | SMS + e-mail + in-app |
| 10 candidates | **in-app only** |

The log states the shortfall exactly rather than hiding it:
`Sitting notification dispatched. {"sms":4,"email":4,"unreachable":10,"recipients":14}`

**Why candidates get no SMS** A Seniority List records service particulars and
carries **no mobile number and no e-mail address**, so there is nowhere to send.
Recorded as `FBE-002`.

A **reschedule** sends the same notice worded as a move — *"has been MOVED to…"* — so
nobody attends on the old day.

---

## 5.17 · Committee conducts the DPC → Evaluation → Proceedings

**Who** `member1@cgpsc.gov.in` · **Screen** **Proceedings**

**Clicks** **Open Sitting** → **CALCULATE** → read the result → enter **Promotion as
on (year)** = `2026` → **APPROVE BY CGPSC** → **Documents** → **Generate Documents**
→ **Publish to Department**.

**Business explanation** Opening records who opened it and when, and pins a hash of
the proposal as it stood. The Commission's calculation is distinct from the
department's — the department's run stands untouched in the history. Holds are
detected automatically: an officer whose mandatory record is missing has their
promotion **reserved**, and the screen is explicit that *"a hold is not a finding on
merit."* Approval **pins the calculation permanently**; a correction afterwards is a
Review DPC.

**Validation** Only the allottee may act, only while the sitting is open. The
*Promotion as on* year is required — leaving it blank is refused.

**Expected result** Main 4 / Waiting 3 / Not Listed 3 reproduced · status **Result
Approved** · two signed documents — **Proceedings** and **Promotion List** — each
carrying its provenance and content hash, then **published**.

**DPC Completed.**

---

## 5.18 · Self-Inspection *(show between §5.12 and §5.17)*

**Who** nobody — public · **Screen** `/self-inspection`

Mobile `9425020004` → a code is sent → enter `123456` → **View my record** →
**Print**.

That is *Smt. Kavita Bhaskar*, SN 4 — worth choosing because she is the officer the
Circulation variant holds at §5.20, so the same person can be shown twice from two
sides.

**Expected result** That officer's **own row alone** — CR grades, category, property
compliance — under *"DATA BEING CONSIDERED AS PROVIDED BY YOUR DEPARTMENT."*

> **How the officer is identified is configuration, not code.**
> `dpc.self_inspection.authentication.driver` ships as **`otp`**: the officer enters
> the mobile their department recorded, a code is issued only if a row actually
> matches, and in `local` that code is `DEMO_OTP` — `123456`. Setting the driver to
> `identity_factors` instead asks for **name, date of joining the promotion-from
> post, and date of birth** — all three, matched exactly.
>
> **The screen closes once the sitting approves its result**, so show it before
> §5.17. It also only opens on a **submitted** or **approved** proposal, never a
> draft.

---

## 5.19 · Candidate login

**Who** `SE/BEO/1001` — *Shri Ramesh Chandra Verma* — with the password reissued
at §5.9

**Expected result** Forced password change → **My Promotion** → the DPC, their own
particulars, seniority number, both posts, status, and a timeline with internal
remarks stripped. **No other candidate, no committee, no chart, no marks.** A
candidate who types another proposal's UUID gets a **404**, not a 403.

---

## 5.20 · Circulation DPC *(optional)*

Only if you uploaded `demo-evaluation-chart-with-hold.csv` at §5.10. That file is
the base chart with **one cell blanked** — SN 4's CR for 2023 is not on record — and
everything else about it is identical.

**A gap is only a hold once it is justified, and the demonstration should show both
halves.** Calculate on the imported chart *before* accounting for the missing CR:
SN 4 scores **12** instead of 15 and comes back **Not Eligible**, because four CRs do
not meet the five the rule requires. Now record the gap on her row — a reason and
`demo-missing-record-justification.pdf`, both required, neither sufficient alone —
and calculate again: SN 4 is **`CIR`**, held. *"A hold is not a finding on merit."*

Then the AO raises a Circulation, the AH approves and signs, the Member decides each
held officer, consents and **signs** — which releases the hold.

> This **changes the counts**: **Main 3 · Waiting 3 · CIR 1 · Not Eligible 3**. SN 4
> leaves the Main List and does *not* fill a seat, because a held promotion must not;
> and note that nobody is promoted up from the Waiting List to take the seat either —
> the vacancy stays open pending the record.

---

# 6 · Role Responsibilities

## Department User (the account itself)

**Can** sign in by e-mail, mobile or employee number; switch active role if the
account holds several; read notifications; open Reports scoped to their department.
**Cannot** see another department's data — every departmental query is scoped to the
signed-in officer's department.

## Authorized Officer (AO)

**Can** create, submit, amend and cancel a Pre-DPC · raise a Final DPC **from an
approved Pre-DPC only** · fill all five DPC sections · upload the three CSVs · run
the departmental calculation · reissue a Promotion Candidate's password while the DPC
is a Draft · record a justified missing record · raise a Circulation.
**Cannot** approve anything · enter a vacancy figure (derived) · enter a sanctioned
strength (a master) · edit any section once the DPC is submitted · touch another
department's data · allot, schedule or conduct.

## Administrative Head (AH)

**Can** review the Pre-DPC and the Final DPC · **approve with a digital signature** ·
return for correction with remarks · approve a Circulation.
**Cannot** enter or edit section data — that is the AO's, by design · allot, schedule
or conduct · reach another department.

## CGPSC Chairman

**Can** see **every** forwarded DPC, allotment, schedule and proceeding · **allot and
re-allot**, naming the **Allottee**, the **DPC Chairman** and the **Completion
Deadline** · preside personally by selecting their own committee seat · change who
presides or extend the deadline by re-allotting · handle circulations.
**Cannot** schedule the meeting — that is the Allottee's · conduct a sitting they
were not allotted (they re-allot it to themselves first, so exactly one actor is
accountable) · edit a department's section data.

## DPC Allottee

**Can** accept the allotment · **schedule and reschedule the meeting**, within the
Completion Deadline · open the sitting · record corrections with reasons · calculate
· approve the result · generate and publish documents · give circulation consent.
**Cannot** schedule past the Completion Deadline · move the deadline (the Chairman's)
· act on a DPC allotted to somebody else · edit an approved result — a correction
afterwards is a Review DPC.

## DPC Chairman

**Presides over that one sitting.** The designation is valid **for that DPC only** —
a column on a committee membership — and **no permanent chairman role is ever
created**. **May be the same person as the Allottee, or a different committee member.
Both are valid.**

> **As implemented today**, presiding is a recorded designation shown on the
> committee, the allotment screen and the proceedings record. A separate
> presiding-versus-conducting distinction *at the sitting itself* is not built —
> `FBE-001 §9`.

## Committee Members

**Recorded per DPC**, with one social category and any combination of the additional
attributes. **Sitting on a committee grants no access and no role** — a member
created here gets an account only so they are not duplicated next time.
**Can** be substituted at any stage by the Administrator, with a reason; the outgoing
member stays on record as replaced.
**Cannot** reach the DPC by virtue of membership — access comes from allotment.

## Candidate (Promotion Candidate)

**Can** see **their own** DPC, its status, a timeline with internal remarks stripped,
notifications, and the final result once published; and use Self-Inspection with no
account at all.
**Cannot** see other candidates, the committee, the evaluation chart, internal
remarks, or any other officer's marks. Those screens **do not exist** for this role —
not "exist but 403".

---

# 7 · Expected Demo Outputs

| Step | What should appear | What should be verified | What should change |
|---|---|---|---|
| 5.1 Login | `/dashboard`, six tiles at 0 | role and department in the header | a session begins |
| 5.2 Registration | `/verify`, then success | `123456` accepted, wrong code refused | department `registered`; 2 accounts |
| 5.3 Post Mapping | 9 posts; Recruitment Rules **empty** | Sanctioned Strength 4 on BEO; *Teacher* "Not recorded" | nothing |
| 5.4 Pre-DPC + approval | `PDPC-2026-SE-0001` → **Approved** | signature attached | **Recruitment Rule v1 appears** |
| 5.5 Final DPC | `DPC-2026-SE-0001`, checklist | only the approved order was offered | proposal in Draft |
| 5.6 Committee | 4 rows, Chairman on top | SC+ST refused; UR badge when blank | **S15** green |
| 5.8 S16 import | 10/10 valid | Total Vacancy **4**; UR/ST/OBC/SC | **S16** green; vacancy recorded |
| 5.9 S17 import | 10/10 valid; credentials panel | 10 evaluation rows projected | **S17** green; ten accounts created |
| 5.10 S18 import | 10/10 valid | 50 CR / 50 loyalty / 50 property | **S18** green; Submit enabled |
| 5.11 Calculate | Main 4 · Waiting 3 · Not Listed 3 | Main = Total Vacancy; Explain on SN 9 | a calculation run recorded |
| 5.12 Review + approve | S21–S25, then signed | one signature covers all five | **Approved**, forwarded |
| 5.13 Routing | DPC on the Chairman's queue | it arrived with no manual step | — |
| 5.14 Allotment | allottee · DPC Chairman · deadline | allottee ≠ presiding member | allotment recorded; Member notified |
| 5.15 Scheduling | late date **refused**; 25-09 accepted | the message names both dates | schedule recorded |
| 5.16 Notifications | bell populated | 14 recipients; 4 external, 10 in-app | 14 notifications written |
| 5.17 Proceedings | Main/Waiting reproduced | year required before approve | **Result Approved**; 2 documents published |
| 5.18 Self-Inspection | the officer's own row | all three factors must match | an access is recorded |
| 5.19 Candidate | My Promotion | no other officer is reachable | password changed |

---

# 8 · Reports

| Report | Where | What it shows |
|---|---|---|
| **DPC Pipeline** | `/reports` | Every stage in process order, with PPT screen references. A DPC appears against every stage it has reached, so the counts deliberately do not sum |
| **Outcome tiles** | `/reports` | **Promoted · On a waiting list · Held (CIR) · Not placed** |
| **By year** | `/reports` | DPCs per DPC Year |
| **By variant** | `/reports` | Fresh / Review / Circulation |
| **By department** | `/reports` | Commission and Administrator only; a department sees only itself |
| **Pipeline export (CSV)** | `/reports/export` | `dpc-pipeline-YYYYMMDD-HHMMSS.csv` |
| **DPC Allotment Report** | CGPSC → Allotments | Status, allottee, DPC Chairman, deadline, default date |
| **Allotment History** | per DPC | Allot · re-allot · **automatic return**, read entirely from the timeline |
| **DPC Schedule Report** | CGPSC → Schedules | Date and time, old beside new |
| **Scheduling History** | per DPC | Accept · schedule · reschedule, from the timeline |
| **Calculation Result + Explain** | per run | Main List, Waiting List, All Officers Assessed, and a full derivation per officer |
| **Calculation History** | per proceeding | Every run ever made; nothing overwritten |
| **Proceedings document** | Proceeding → Documents | Signed, with provenance and content hash |
| **Promotion List document** | Proceeding → Documents | Signed, published to the department |
| **Audit Trail + CSV export** | `/admin/audit-trail` | Workflow ledger · Activity timeline · Data changes |
| **Self-Inspection print sheet** | `/self-inspection/record/print` | The officer's own row |

**Nothing on a report is recalculated.** Promotion figures come from the calculation
run each proceeding **pinned at approval**, so a report can never disagree with the
signed document it summarises.

---

# 9 · Sample Data Used

## Importable — `docs/demo/sample-data/`

| File | Rows | Screen | Verified |
|---|---|---|---|
| `demo-working-officers.csv` | 10 | S16 | ✅ 10 valid, 0 rejected, committed |
| `demo-seniority-list.csv` | 10 | S17 | ✅ 10 valid, 0 rejected, committed |
| `demo-evaluation-chart.csv` | 10 | S18 | ✅ 10 valid, 0 rejected, committed |
| `demo-evaluation-chart-with-hold.csv` | 10 | S18 (Circulation) | ✅ 10 valid, 0 rejected, committed |
| `demo-recruitment-rule-go.pdf` | 1 page | Recruitment Rule GO | ✅ valid PDF |
| `demo-department-post-order.pdf` | 1 page | Department Post order | ✅ valid PDF |
| `demo-missing-record-justification.pdf` | 1 page | Missing-record evidence | ✅ valid PDF |

## Typed, not imported — `sample-data/reference/`

Eight data sheets with a README: department registration · department users · CGPSC
Chairman · department posts · Pre-DPC and recruitment rule · committee members ·
allotment and schedule · self-inspection factors.

**The portal has exactly three CSV importers.** There is none for departments,
users, posts, recruitment rules, committee members, CR/APAR, property returns,
integrity certificates or punishment records. CR, property, loyalty and tribal
service are **columns of S18**. Punishment records are not modelled in this build.

Full detail: **`docs/demo/SAMPLE_DATA_GUIDE.md`**.

---

# 10 · Known Demo Limitations

Only limitations actually still present. The full running log, with status on every
item ever raised, is `docs/uat/TERMINAL_SUGGESTIONS.md`.

| # | Limitation | Effect | What to do |
|---|---|---|---|
| 1 | **Queue worker required** | No worker → no notification, no OTP delivery, empty bell | Start `php artisan queue:work` |
| 2 | **Port 8000 is a different application** | Reaches the CGMFPFED platform | Use `:8001` |
| 3 | **23 candidates have no known password** | They have no e-mail or mobile, so credentials went nowhere | Use the AO's **Reissue password** while the DPC is a Draft |
| 4 | **Admin → Reset Password does not display the value** | Useless for a candidate | Use the AO's Reissue button |
| 5 | **Candidates get no SMS or e-mail** | Sitting notice reaches them in-app only | Say so plainly — `FBE-002` |
| 6 | **Self-Inspection closes after the result is approved** | Refuses if shown too late | Show between §5.12 and §5.17 |
| 7 | **Documents render as HTML, not PDF** | Open in the browser | Provenance and signature are present regardless |
| 8 | **Signature provider is the mock** | `FEATURE_CSC_DIGISIGN=false` | Real records, not CSC-issued |
| 9 | **Zone reads "No formula"** | Class II → Class I is given no formula in the deck | Correct; use a Class-I → Class-I pair to show a computed zone |
| 10 | **Deadline sweep needs a host cron** | `schedule:run` is not installed here | Demonstrate with `php artisan dpc:return-expired-allotments --as-of=2026-10-01` |
| 11 | **`Teacher` has no Sanctioned Strength** | No DPC can be raised on it; shows "Not recorded" | Intentional — it demonstrates the warning state |
| 12 | **No multi-member (multi-participant) sitting** | One accountable allottee conducts | `FBE-001 §9` |
| 13 | **`php artisan demo:credentials` mutates** | Resets every password and rewrites the sheet. It no longer creates accounts (2026-10-05) | Safe to run before the demonstration; do not run it during one |

---

# 11 · Business Rules

## Chairman

- Every DPC committee includes the **CGPSC Chairman**, seated **automatically** from
  the `CGPSC Chairman` master. The department can neither add nor remove the seat.
- No Chairman on record → the committee cannot be saved, and the message names the
  screen that fixes it.
- The CGPSC Chairman **may always allot**; the designated DPC Chairman may also allot.

## DPC Allottee

- Named by the CGPSC Chairman at allotment.
- Responsible for coordinating the DPC, scheduling and rescheduling.
- **Only the Allottee may schedule.**
- One member may hold several DPCs at once.
- Allotting **never overwrites** — re-allotting writes a new row and keeps the old
  one with its reason.

## DPC Chairman

- Chosen by the CGPSC Chairman from **that DPC's own committee** — which includes the
  Chairman's own seat.
- **Exactly one per DPC.**
- **Valid for that DPC only. No permanent chairman role is ever created.**
- May be the same person as the Allottee, or a different member. **Both valid.**

## Completion Deadline

- Set by the CGPSC Chairman at allotment. **Required.** Cannot be in the past.
- It is **not** the meeting date — it is the date by which the DPC must be concluded.
- Changed only by the Chairman, by re-allotting.

## Meeting Date

- **Meeting Date ≤ Completion Deadline**, on scheduling **and** on rescheduling.
- Enforced at the form (field-level message) and again in the service, which binds
  non-HTTP callers too.
- A past date is permitted — the Commission may record a sitting that has happened.
- Rescheduling requires a reason; the old date stays beside the new one.
- An allotment carrying **no** deadline is unbounded — those predate the field.

## Deadline behaviour

- A DPC not concluded by its deadline **returns automatically to the CGPSC
  Chairman** — `dpc:return-expired-allotments`, scheduled daily at 00:15.
- The Chairman may then **reassign the same Allottee, assign another, change the DPC
  Chairman, or extend the deadline**.
- Re-allotting to the **same** member is permitted when the deadline or the presiding
  member changes — that is how an extension is granted. A re-allotment changing
  nothing is refused.
- The return is **fully auditable**: a timeline event, shown in red on the allotment
  history beside the allotment it ended.
- Idempotent — a row already returned is skipped.
- A DPC whose sitting has already concluded is never returned, however late.

## Committee validation

- **Chairman + minimum 3 members**, maximum 6 members besides the Chairman.
  *(Confirmed unchanged by business ruling, 2026-09-06.)*
- Coverage is judged of the committee **as a whole**, satisfying requirements with
  the **fewest possible members** — one member may cover several attributes. A
  separate member per attribute is **not** required.
- Exactly one CGPSC Chairman seat; at most one DPC Chairman.
- The Administrator may substitute a serving member **at any stage**, with a reason.

## Member attributes

- **Social category — at most one.** SC, ST, OBC are **mutually exclusive**: SC+ST,
  SC+OBC and ST+OBC are each refused, by name.
- **None selected = UR (General).** **There is no UR checkbox** — UR is the absence
  of a claim, shown as a live badge.
- **Independent attributes — any combination:** Female · Bhar Sadhak Sachiv · Vibhag
  Sachiv. They combine with each other and with whichever category was chosen.
- Attributes belong to **that membership**, not to the person, and grant nothing.

## Notifications

- Fixing **or moving** a meeting notifies **every serving committee member and every
  officer on the Seniority List**, carrying **DPC Date, DPC Time and joining
  instructions**.
- A reschedule is worded as a move.
- Committee members: SMS + e-mail + in-app. Candidates: **in-app only** — a Seniority
  List carries no contact channel.
- A replaced member is not notified.
- **A notification failure never fails the workflow.**

## Vacancy and merit

- Sanctioned Strength comes from the **Department Post Mapping** — never from the AO.
- `Total Vacancy = Sanctioned − chairs substantively held`; deputation consumes no
  quota. The split follows the Recruitment Rule's percentage.
- Vacancy period **01-01 to 30-11 of the DPC Year**, derived from the year, not today.
- Score = the **five most recent available CR years** (क+ 4 · क 3 · ख 2 · ग 1 · घ 0)
  **+1** for any tribal-area service. Qualifying score for Class-III → Class-II is
  **10**.
- **Any `घ` in the scoring window fails outright**, whatever the total.
- Reserved-category relaxation is **not applied** — it awaits a business decision
  (Q5/Q6); affected officers are flagged rather than silently decided.
- The Evaluation Chart's officers are **projected from the Seniority List** and
  cannot be added, removed or renamed.

---

# 12 · Verification Checklist

```
PREREQUISITES
  ☐  php artisan serve --port=8001 running
  ☐  php artisan queue:work running
  ☐  http://127.0.0.1:8001/login reachable
  ☐  Database clean — 0 proposals, 9 users, Recruitment Rules empty

DEPARTMENT SIDE
  ☐  Login (AO)                          → /dashboard, tiles at 0
  ☐  Department Registration (optional)  → OTP 123456 accepted
  ☐  Department Post Mapping             → Sanctioned Strength 10 on Additional Director
  ☐  Create Pre-DPC                      → PDPC-2026-SE-0001
  ☐  Submit Pre-DPC                      → Submitted
  ☐  AH Approval of Pre-DPC              → Approved + Recruitment Rule v1 appears
  ☐  AO creates Final DPC                → DPC-2026-SE-0001
  ☐  Committee (S15)                     → 4 rows; SC+ST refused
  ☐  Import Working Officers (S16)       → 10/10 valid
  ☐  Vacancy derived                     → TOTAL VACANCY 4 (UR, ST, OBC, SC)
  ☐  Import Seniority List (S17)         → 10/10 valid; ten accounts created
  ☐  Candidate password reissued         → value written down (SE/BEO/1001)
  ☐  Import Evaluation Chart (S18)       → 10/10 valid; 50/50/50
  ☐  Calculate                           → Main 4 · Waiting 3 · Not Eligible 3
  ☐  Explain Result on SN 9              → adverse grade घ named
  ☐  Submit Final DPC                    → Submitted
  ☐  AH Review S21–S25 + Approval        → Approved, signed, forwarded

COMMISSION SIDE
  ☐  Automatic routing                   → DPC on the Chairman's queue, unasked
  ☐  Chairman assignment                 → Allottee + DPC Chairman + Deadline
  ☐  Allottee accepts                    → Accepted
  ☐  Scheduling — late date refused      → message names both dates
  ☐  Scheduling — 22-12-2026 accepted    → Scheduled
  ☐  Notifications                       → 14 recipients; bell populated
  ☐  Open sitting                        → In Proceeding
  ☐  Calculate (Commission run)          → Main 4 · Waiting 3
  ☐  Approve by CGPSC (year 2026)        → Result Approved
  ☐  Generate + publish documents        → 2 signed documents

ALONGSIDE
  ☐  Self-Inspection (before approval)   → officer's own row + Print
  ☐  Candidate login                     → My Promotion only
  ☐  Reports                             → pipeline, outcomes, CSV export
  ☐  Audit Trail                         → 3 sources + CSV export
  ☐  Circulation DPC (optional)          → hold released at signature
```

---

# 13 · Demo Timeline

| # | Activity | Time | Notes |
|---|---|---|---|
| 0 | Start services, confirm one login | **3 min** | do before the customer arrives |
| 1 | Introduction and login | **3 min** | |
| 2 | Department Registration | **4 min** | *optional* |
| 3 | Department Post Mapping + empty Recruitment Rules | **4 min** | *optional* — sets up the §5.4 reveal |
| 4 | Pre-DPC, submit, AH approve, **rule appears** | **6 min** | high-value |
| 5 | Raise the Final DPC | **2 min** | |
| 6 | Committee, incl. the social-category rule | **5 min** | |
| 7 | Import S16 + **vacancy derives itself** | **5 min** | high-value |
| 8 | Import S17 + candidate accounts | **4 min** | reissue a password here |
| 9 | Import S18 | **3 min** | |
| 10 | Calculate + **Explain Result** | **6 min** | **the centrepiece** |
| 11 | Submit, S21–S25 review, approve and sign | **5 min** | |
| 12 | Self-Inspection | **3 min** | must be before activity 16 |
| 13 | Automatic routing + Chairman assignment | **5 min** | three decisions in one act |
| 14 | Scheduling — refusal, then acceptance | **4 min** | show the refusal first |
| 15 | Notifications | **2 min** | |
| 16 | Sitting: open, calculate, approve | **6 min** | |
| 17 | Documents: generate and publish | **3 min** | |
| 18 | Candidate login | **3 min** | |
| 19 | Reports | **4 min** | |
| 20 | Audit Trail | **3 min** | strong close |
| 21 | Circulation DPC | **6 min** | *optional* |

**Full demonstration: 60–70 minutes** · **Core path** (skipping 2, 3, 21):
**45–50 minutes** · **Short version** (activities 4, 7, 10, 13, 14, 20 only):
**25 minutes**.

---

*Regenerated 2026-09-06 from the running implementation and the business decisions of
2026-09-05 and 2026-09-06. Cross-checked against `docs/uat/TERMINAL_SUGGESTIONS.md`
and `docs/FUTURE_BUSINESS_ENHANCEMENTS.md`. This is the only authoritative
demonstration flow.*
