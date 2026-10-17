# UAT Manual Testing Checklist — CGPSC ODPC Portal

Part A sets up the test data. Part B tests the Vacancy Master in depth. Part C walks
the whole DPC process end to end, role by role.

Record each step as **Pass / Fail / Blocked**, with a screenshot of every Fail.
Expected results describe what the code does today. Where it is incomplete, the step
says so: do not mark a documented limitation as a defect.

---

## Before you start

| Need | How |
|---|---|
| Deployed and validated UAT | `UAT-APPLICATION-DEPLOYMENT.md` §13 all green |
| Queue worker running | `systemctl is-active odpc-queue` → `active`. Without it no OTP is ever written and nobody can log in. |
| A way to read OTPs and temporary passwords | `UAT-ENV-CONFIGURATION.md` §1.2. By default an operator runs `grep -h "verification code is" <APP_DIR>/storage/logs/laravel*.log \| tail -4` (two codes per login: email and mobile). |
| Small sample PDFs | Any PDF under 10 MB. The repository has some in `docs/demo-kit/uploads/` |
| Sample CSVs for the DPC sections | `docs/demo-kit/uploads/demo-working-officers.csv`, `demo-seniority-list.csv`, `demo-evaluation-chart.csv` (see `docs/demo-kit/README.md`) |
| Browser | Current Chrome / Edge / Firefox. The site enforces a Content-Security-Policy, so disable browser extensions that inject scripts if a page misbehaves. |

### Roles

The application has exactly these roles. **There is no "Department Admin" role.** A
department's users are the Authorized Officer and the Administrative Head.

| Role | Who | Used in |
|---|---|---|
| CGPSC Administrator (`admin`) | Commission IT | Masters, users, **Vacancy Master** |
| Authorized Officer (`authorized_officer`) | Department | Pre-DPC, DPC proposal entry, Rule 1.1, submission |
| Administrative Head (`administrative_head`) | Department | Approve / return |
| Chairman, CGPSC (`chairman`) | Commission | Allotment |
| Member, CGPSC (`member`) | Commission | Accept, schedule, sitting, result |
| Promotion Candidate (`promotion_candidate`) | Officer on a seniority list | Sees own DPC and result |
| Officer under consideration | No account | Self-Inspection |

---

## Part A — Test data (CGPSC Administrator)

| # | Step | Expected |
|---|---|---|
| A1 | Open `https://<UAT_DOMAIN>/login`, enter the admin email and password | OTP screen |
| A2 | Enter the OTP(s) from the log | Dashboard. First login with a temporary password forces `/password/change`. |
| A3 | **Admin → Departments → New Department**: Code `UATD`, Name `UAT Test Department`, Active | Saved; listed |
| A4 | **Admin → Department Posts → New**: Department `UAT Test Department`, Post `Principal`, Class `II`, Sanctioned strength `100`, Effective from `01-01-2020`, Government Order `UAT/GO/1`, date `01-01-2020`, attach a PDF | Saved, "In force" |
| A5 | Same for Post `Lecturer` (Class III, sanctioned `500`), and Post `Joint Director` (Class I, sanctioned `10`) | Three mapped posts |
| A6 | *(Removed 2026-10-17.)* There is no admin Recruitment Rule screen: the rule is created when the Pre-DPC is approved (step C3). | — |
| A7 | **Admin → CGPSC Chairman**: record the serving Chairman (name, designation, email, mobile, effective from) | Needed before any DPC committee can be saved |
| A8 | **Admin → Users → New**: one AO and one AH (assign both to `UAT Test Department`), one user with role Chairman, two users with role Member | The temporary password is shown once on screen, and also written to the log (`grep -h "Temporary password" <APP_DIR>/storage/logs/laravel*.log \| tail`) |

Verify the prerequisites from the server if a screen disagrees:

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> php artisan tinker --execute='
$d = App\Domain\Department\Models\Department::where("code","UATD")->first(); dump($d?->id);
dump(App\Domain\Master\Models\DepartmentPost::where("department_id",$d?->id)->get(["id","name","sanctioned_strength","effective_from","active_flag"])->toArray());
dump(App\Domain\Master\Models\RecruitmentRule::whereIn("post_id", App\Domain\Master\Models\DepartmentPost::where("department_id",$d?->id)->pluck("id"))->get(["post_id","version","promotion_recruitment_pct","effective_from","status"])->toArray());
dump(App\Domain\Authentication\Models\User::role("admin")->pluck("email")->all());'
```

---

## Part B — Vacancy Master (CGPSC Administrator)

Path: **Admin → Departments → (row) Vacancy Master**. The same button is on the
department's Edit page.

The values below assume Part A. Every figure is checkable by hand.

| # | Test | Steps | Expected result |
|---|---|---|---|
| B1 | Department through navigation | Departments list → `UAT Test Department` row → **Vacancy Master** | Page titled *Vacancy Master*; Department `UAT Test Department` shown read-only. **No department dropdown.** The URL contains the department id. |
| B2 | Mapped posts listing | — | Rows: `Joint Director`, `Lecturer`, `Principal` (alphabetical). No post of any other department. |
| B3 | Vacancy year 2026 | Choose `2026` → **Show** | Header *Vacancy Year 01-01-2026 to 31-12-2026 (both inclusive)*. Every Working cell shows **Not Entered** (never `0`). Status *Not started*. |
| B4 | Sanctioned strength | — | `Principal 100`, `Lecturer 500`, `Joint Director 10`, taken from the mapping in force on 01-01-2026. No input field for it anywhere. |
| B5 | Start a draft | `Principal` → **Manage** → **Start draft** | *Draft started*. Calculation lists the open issue *Working strength as on 1 January is not entered*. Finalize is disabled. |
| B6 | Working strength entry | Working strength `75`; Promotion `40`, Direct `33`, Deputation `2`; Order `UAT/WS/2026/1`, date `05-01-2026`; attach PDF → **Save** | *Working strength saved*. Calculation: Sanctioned `100`, Working `75`, **Existing (opening) `25`**; Posts by quota *Promotion 50 · Direct 50*; Opening vacancies by quota **Promotion 10 · Direct 17**; Vacancies for the year *Promotion 10 · Direct 17*. Change history shows 1 row. |
| B7 | Mandatory document | Repeat B6 without a PDF | Error on the document field; nothing saved |
| B8 | Split must add up | Working `75`, split `41/33/2` | Error: the three must add up to 75 |
| B9 | Above sanctioned | Working `101` (split `51/48/2`) without the tick | Refused. With the tick and remarks it saves, but Calculation shows *A quota is overfilled* and Finalize stays disabled. Afterwards correct it back to `75` (B10). |
| B10 | Working strength correction | Change to `74` (split `40/32/2`) **without** a reason, then **with** reason `One officer relieved 31-12-2025` | First refused (*Give the reason…*); second saves. History shows both rows, with the reason. Opening becomes `26`. Set it back to `75 / 40 / 33 / 2` with a reason before continuing. |
| B11 | View supporting document | Click the file name in the working strength block | The PDF downloads |
| B12 | Retirement vacancy | Add vacancy: Anticipated, Retirement, `5`, date `01-06-2026`, quota Promotion, reconciliation *No — officer was in the 1 January working strength*, Order `UAT/RET/1`, date `10-01-2026`, PDF | Entry *Eligible · Counted*. Anticipated counted *Promotion 5*. **Promotion vacancies 15** (= 50 − (40 − 5)). Opening stays `25`. |
| B13 | Already in the opening figure | Same as B12 but Order `UAT/RET/2`, reconciliation *Yes — post already vacant on 1 January* | *Eligible*, **not counted**. "Already in opening 5". Promotion stays `15`. |
| B14 | Duplicate | Repeat B12 exactly (same order, date, quota) | Refused: *An identical entry … already exists* |
| B15 | Year boundaries | Anticipated retirement dated `01-01-2026` (Order `B1`), `31-12-2026` (Order `B2`), `01-01-2027` (Order `B3`), `31-12-2025` (Order `B4`) | `01-01-2026` and `31-12-2026` accepted; `01-01-2027` and `31-12-2025` refused (*must fall between 01-01-2026 and 31-12-2026*). Withdraw the two accepted ones afterwards (reason `boundary test`). |
| B16 | Existing vacancy | Existing, Retirement, `3`, date `30-09-2025`, Order `UAT/OLD/1` | *Explains opening figure*; adds nothing. A date of `02-01-2026` for an Existing entry is refused. |
| B17 | Death vacancy | Anticipated, Death, `1`, `15-03-2026`, quota Direct, counted in working, Order `UAT/D/1` | *Pending verification*; not counted; Calculation lists *1 entry awaits verification*; Finalize disabled. |
| B18 | Pending verification — decide | On the Death entry: Decision *Eligible*, basis *Approved by the Commission as a vacancy reason*, remarks `UAT check` → **Record decision** | *Eligible · Counted*. Direct vacancies rise by 1. "Verified by … — UAT check" is shown. |
| B19 | Higher-post promotion | Anticipated, *Promotion to a higher post*, `3`, `01-04-2026`, quota Promotion, counted in working, Higher post `Joint Director`, Higher-post DPC proposal number `DPC-2026-UATD-0009`, Order `UAT/HP/1` | *Pending verification* (rule 1.2). Not counted. |
| B20 | Higher-post — verification needs the order | Decision Eligible, basis *Higher-post promotion order issued*, remarks, **no** order number → then with order `UAT/PROM/7`, date `01-02-2026` | First refused (*Give the promotion order number and date*); second makes it Eligible and Counted. Promotion vacancies rise by 3. |
| B21 | One DPC cited once | Another higher-post entry with the same DPC number `DPC-2026-UATD-0009` | Refused: *DPC … is already cited by another entry* |
| B22 | Higher post must differ | Higher post = `Principal` itself | Refused |
| B23 | Deputation — over a year | Anticipated, Deputation, `1`, `01-03-2026`, quota Promotion, counted, Deputation end `30-06-2027`, against sanctioned post *Yes*, Order `UAT/DEP/1` | *Eligible* (rule 2.3) |
| B24 | Deputation — within a year | Same, Order `UAT/DEP/2`, expected months `6` | *Not eligible*; never counted |
| B25 | Deputation — not stated | Same, Order `UAT/DEP/3`, leave duration and sanctioned-post empty | *Pending verification*. Resolve it with Decision *Not eligible*, remarks. |
| B26 | Unsupported reason | Try to post a reason that is not in the list (only possible by editing the request) | Rejected server-side; the dropdown offers only the five controlled reasons, and "Direct recruitment" is not one of them |
| B27 | Double counting — quota cap | Anticipated retirement, `40`, quota Promotion, counted, Order `UAT/RET/BIG` | Calculation: *Promotion: N anticipated vacancies are counted but only 40 officers of that quota were working on 1 January*; Finalize disabled. Withdraw it afterwards. |
| B28 | Double counting — existing cap | Existing entries totalling more than the opening `25` | Issue *Existing-vacancy entries (…) exceed the opening vacancies (25)* |
| B29 | Quota separation | — | Promotion and Direct are always shown separately. The total is never multiplied by 50 %. |
| B30 | Finalization | Resolve every issue → **Finalize vacancy position** → confirm | Status *Finalized*; Revisions table row 1 with the figures; the working-strength form, the add form and the verify/withdraw buttons disappear |
| B31 | Frozen after finalization | Try to save working strength again (e.g. back button + resubmit) | Refused: *Only a draft position can be changed* |
| B32 | Historical snapshot | In another tab change `Principal`'s sanctioned strength (Department Posts → Edit) to `120` | The finalized position still shows `100` and the same vacancies. Restore `100` afterwards. |
| B33 | Revision | **Open a revision** without a reason, then with reason `Retirement order corrected` | First refused; second creates Revision 2 as a Draft with the working strength and active entries carried over. Revision 1 remains *Finalized* until revision 2 is finalized, then becomes *Superseded* with its figures unchanged. |
| B34 | Year isolation | Switch the year selector to `2025` | `Principal` shows *Not started* / *Not Entered*. Nothing from 2026 appears. |
| B35 | Seniority reference date | — | *Seniority list reference date: 01-04-2025* on the list and post pages for year 2026 |
| B36 | AO / AH cannot reach it | Log in as the department's AO, open `https://<UAT_DOMAIN>/admin/departments/<id>/vacancy-master`; repeat as AH | **403** for both, on the page and on every action |
| B37 | Document access | As AO, open a Vacancy Master document URL copied from the admin screen | **403**. Admin downloads it. No document is reachable under `/storage/...` without a signed link. |
| B38 | DPC proposal integration | See C6 and C7 below | — |

---

## Part C — End-to-end DPC process

The same department and posts as Part A. The promotion is `Lecturer → Principal`,
DPC year 2026.

| # | Role | Step | Expected |
|---|---|---|---|
| C1 | AO | Log in (OTP), change the temporary password | AO dashboard |
| C2 | AO | **Pre-DPC → New**: To post `Principal`, year 2026, GO number and effective date, Direct `50` / Promotion `50`, feeder `Lecturer` with 100 % share, experience, qualification → **Submit** | Pre-DPC *Submitted* |
| C3 | AH | Open the Pre-DPC → **Approve** | *Approved*. Signed by the mock signature provider (`FEATURE_CSC_DIGISIGN=false`). A new Active Recruitment Rule version is projected for `Principal`. |
| C4 | AO | **DPC Proposals → New** from the approved Pre-DPC | Draft with a DPC code (e.g. `DPC-2026-UATD-0001`), Revision 1 |
| C5 | AO | **S15 Committee**: at least 3 members besides the auto-seated CGPSC Chairman, covering the required categories (SC, ST, OBC, female, Bhar Sadhak Sachiv, Vibhag Sachiv), one marked DPC Chairman | Section complete only when compliant; the detail line names anything missing |
| C6 | AO | **S16 Working Officers**: a chart with exactly `100` rows (Principal's sanctioned strength) — officers (with Source promotion / direct / deputation, optional retirement and deputation dates) and vacant posts | Vacancy summary derived; nothing to type. Retirement dates after 31-12-2026 are refused. **With a finalized Vacancy Master (Part B)** the checklist adds *Vacancy Master reconciliation*: complete only when sanctioned (`100`) and working officers (rows with status *working*) equal the finalized position's `100` and `75`. |
| C7 | AO | Compare the reconciliation | A mismatch names both figures. Fix it by correcting the chart, or by **revising** the Vacancy Master (Part B33). Promotion vacancies are shown for comparison and are **not required to match**, because the chart cannot express higher-post vacancies. |
| C8 | AO | **S17 Seniority List** upload — exactly as many officers as the Zone of Consideration states | Complete when the count matches the zone. The page shows *Seniority list reference date (rule 1.6): 01-04-2025*. |
| C9 | AO | **S18 Evaluation Chart**: CR grades, loyalty, property per year; **Missing records** with reason + PDF where needed | Complete when it matches the seniority list |
| C10 | AO | **Pre-submission Verification (Rule 1.1)**: confirm sanctioned strength, Recruitment Rule, Seniority List; working strength vs previous DPC (choose *No DPC has been held for this post before*, remarks required) | Section complete; history shows who/when |
| C11 | AO | **Submit** | *Submitted*. Sections are locked. If a finalized Vacancy Master exists, the proposal records it. |
| C12 | AH | **Review** steps 1–5 (the last step shows the Rule 1.1 confirmations) → **Return** with remarks | *Returned*; Revision 2 |
| C13 | AO | Correct, **re-confirm Rule 1.1** (confirmations do not carry across a return), **Submit** | *Submitted* |
| C14 | AH | **Approve** | *Approved* and forwarded to the Commission |
| C15 | Chairman | Open the forwarded DPC → **Allot** to a committee member, with a completion deadline | Allotment *Pending*. The allottee is notified (in-app, and in the log). |
| C16 | Member (allottee) | **Accept** → **Schedule** a date and time on or before the deadline | *Scheduled*. Committee members and candidates are told (in-app; SMS/mail go to the log). |
| C17 | Member | **Open the sitting** → **Calculate** | Calculation run with each officer's remark (EL / NE / SS / CIR / RJ), the main and waiting lists, and holds |
| C18 | Member | Optionally record a correction (CR grade etc., reason ≥ 10 characters) → Calculate again | A new run; corrections are an append-only history |
| C19 | Member | **Approve result**, entering *Promotion as on* `2026` | *Result approved*. **Known limitation:** approval also needs the `member` role, so the allottee must hold it. |
| C20 | Member | **Generate documents** → **Publish** | Proceedings (the DPC minutes) and Promotion List, signed (mock) and published. Publication is refused while a reserved-category decision is pending. |
| C21 | AO / AH | Notifications bell | *DPC Result Available* |
| C22 | Promotion Candidate | Log in with the employee number recorded on the seniority list (account provisioned by the AO) | Sees only their own DPC, its stage, and their own result |
| C23 | Anyone, no login | **Self-Inspection** (`/self-inspection`) with an officer's identity | Read-only view of that officer's own data |
| C24 | AO | Optional: **Review DPC** of the approved DPC, or **Circulation** for held officers | Separate flows; see `docs/CGPSC_DPC_USER_GUIDE.md` §8 |

### What is NOT implemented — do not log these as defects

From `docs/business/CURRENT_SYSTEM_VS_LATEST_DPC_BUSINESS_DOCUMENT.md` and the pending
decision register:

- **No real SMS, e-mail gateway or digital signature.** Mocks only on UAT.
- **No PH reservation, sealed cover, departmental-enquiry handling, IPR document upload, complaint/representation upload, refused-promotion field, or two-level document verification.**
- **CR rules:** the 9/7/5/3/1 marking scale, multiple CRs per year, carry-forward and NRC are not implemented. The 4/3/2/1/0 scale is in force.
- **Vacancy Master:**
  - no roster-point / category-wise vacancy
  - feeder-cadre shares are not applied to the vacancy
  - the retired-officer "Rule Review Required" status is **not** implemented — the seniority list carries no retirement date
  - higher-post, death and "other" vacancies count only after explicit Administrator verification
- **Full entry of an offline historical DPC by Commission staff** is not implemented (BD-62).
- **The "fewer than 50 employees" exception** of rule 1.1 is not implemented (BD-59).
