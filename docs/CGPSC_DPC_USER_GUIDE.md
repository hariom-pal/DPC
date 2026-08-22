# CGPSC DPC User Guide

**Online Departmental Promotion Committee (e-DPC) Portal · Chhattisgarh Public Service Commission**

**For:** every person who uses the portal — departmental officers, Commission members, administrators, and officers being considered for promotion.

**Version 1.0 · 2026-08-22.** This is the operating manual for the portal as built. It is one of five documents; the other four are technical or for the Commission, and are listed in the repository's `README.md`.

No technical knowledge is needed. Read §1 to §3, then the section for your own role. A completely new officer should be able to work the portal from this document alone.

---

## 1. What this portal is for

A **Departmental Promotion Committee (DPC)** decides which officers are promoted. It has always involved a department preparing papers, sending them to the Chhattisgarh Public Service Commission, a committee sitting to consider them, and a promotion list being issued.

**This portal does all of that online.** The papers are entered once, the calculations are performed by the system against the rules the Commission has notified, and the result is digitally signed and permanently verifiable.

**Three things it will not do**, and each is deliberate:

- **It will not guess.** Where the rules do not settle a question, the portal says so plainly on screen and refuses to publish a result it cannot stand behind. You will see the words *"Pending Business Decision"* with the question named.
- **It will not let anything be quietly changed.** Once a proposal is submitted, its sections cannot be edited. Corrections are recorded *beside* what they correct, never over it. Every action is kept permanently, with who did it and when.
- **It will not show you somebody else's data.** Every screen shows only what your role and your department entitle you to see.

---

## 2. Who uses it

| Role | Who this is | What they do |
|---|---|---|
| **Authorized Officer (AO)** | The departmental officer who prepares papers | Enters everything: sanctioned posts, committee, officers, seniority, evaluation chart. Submits for approval |
| **Administrative Head (AH)** | The senior departmental officer | Reviews what the AO prepared, then approves and signs, or returns it for correction. **Cannot enter data** — the two levels are separate on purpose |
| **Chairman, CGPSC** | The Commission's Chairman | Allots a forwarded DPC to a Member |
| **Member, CGPSC** | A Member of the Commission | Accepts an allotted DPC, fixes the date, conducts the sitting, approves the result |
| **CGPSC Administrator** | Commission IT/administration | Maintains master data, creates Chairman/Member accounts, reads the audit trail |
| **Officer under consideration** | Any officer whose name is on a chart | Views their own data before the committee sits. **Needs no account** |

**Why the AO cannot approve their own work.** The Commission's own process puts the entry with one officer and the approval with another. The portal enforces it: the buttons an AO sees are not the buttons an AH sees, and the system refuses the action even if the request is made another way.

---

## 3. Signing in

Go to the portal address and choose **Login**.

- **Enter** your email address **or** your mobile number, and your password.
- **First time?** You will be given a temporary password. The portal **requires** you to change it before you can do anything else.
- **Forgotten password?** Contact your department's nodal officer or the Commission. There is no self-service reset.

**An OTP at the important moments.** When you do something legally significant — approving and signing a proposal, signing a result — the portal sends a one-time password and asks for it. This is your signature, and it is recorded as evidence that you intended the action.

**An officer being considered for promotion does not log in here.** See §9.

**The bell in the top bar** shows how many notifications are waiting. Click it for the latest few, or **See all** for everything. Every role has this.

---

## 4. The process, end to end

```
  DEPARTMENT                          COMMISSION
  ──────────                          ──────────
  1. Pre-DPC proposal                 
     posts sanctioned  ──────────►    (projected into the masters)
     AO enters, AH approves

  2. DPC proposal                     3. Allotment
     post pair, committee,   ────►       Chairman allots to a Member
     officers, vacancies,             4. Acceptance & scheduling
     seniority, chart                    Member accepts, fixes the date
     AO enters, AH approves & signs
                                      5. The sitting
  (meanwhile)                            Member opens it, corrects the
  Officers view their own data             chart if needed, CALCULATES
  and raise corrections in person          and approves the result

                                      6. Documents
                        ◄────────         proceedings + promotion list,
  Department receives them                signed and published
```

**If something needs correcting afterwards**, there are two routes:

- **Review DPC** — the whole DPC is re-done correctly and the earlier promotion list is superseded. Used when the result itself was wrong.
- **Circulation** — used when a promotion was *held* because an officer's record was incomplete, and the record has since been supplied.

---

## 5. For the Authorized Officer

### 5.1 Pre-DPC proposal — declaring sanctioned posts

**Menu: Pre-DPC**

This declares which posts exist, how they are filled, and which posts feed them. It must be approved before you can file a DPC.

1. **+ New Proposal**
2. Enter the **Government Order number** and the **date it takes effect**.
3. **Add Post** for each post:
   - **Post Name** — chosen from your department's list, not typed. If the list is empty, ask the Commission to register your posts.
   - **Class** — I, II, III or IV.
   - **Direct Recruitment %** and **Promotion %** — these must total 100. The portal will tell you if they do not.
4. For each post, **add its feeder cadres** — the posts officers are promoted *from*: the feeder cadre, the share of promotions, the minimum experience, and the minimum qualification (all chosen from lists).
5. **Save Draft** as often as you like.
6. **Submit for Approval** when finished.

**Buttons:** *Add Post*, *Add Feeder*, *Save Draft*, *Submit for Approval*, *Cancel*, and — after approval — *Amend (new version)*.

**An approved Pre-DPC is never edited.** *Amend* creates a **new version**; the old one remains as the record of what was approved when.

> **If you see:** *"the feeder … matches no cadre in your department's Cadre master"* on approval — a feeder you named is not registered as a post. Ask the Commission to add it, then approve again. The portal refuses rather than creating a Government Order that would promote nobody.

### 5.2 DPC proposal — the five sections

**Menu: DPC Proposals → + New Proposal**

You choose the **promotion path**: the post being promoted **to** and the post promoted **from**. Then complete five sections in any order.

| Section | What it holds |
|---|---|
| **1 · Post Selection** | The promotion path, the DPC type, the proposal date |
| **2 · Committee** | The committee members and their capacities |
| **3 · Working Officers & Vacancies** | Officers currently in post, and vacancies by type and category. **Three figures here are worked out for you — see §5.2a** |
| **4 · Seniority List** | The final seniority list, in order |
| **5 · Evaluation Chart** | The heart of it — one row per officer with DOB, qualification, category, dates, and **year-by-year CR grades, loyalty and property declarations** |

**Every section shows whether it is complete.** You cannot submit until all five are.

### 5.2a Vacancies are calculated, not typed

On **Working Officers & Vacancies** you enter **one** number — the **Sanctioned Posts** for the target cadre. The portal then works out three more and shows them greyed out, because they are consequences rather than choices:

| Field | Where it comes from |
|---|---|
| **Working Officers** | Counted from the officer chart on the same screen — however you filled it, by typing or by uploading the Currently Working file |
| **Total Vacancy** | **Sanctioned Posts − Working Officers** |
| **Zone of Consideration** | How many officers may be considered for the vacancy. For a Class-I → Class-I promotion it is `(Total Vacancy × 2) + 4`, except with a single vacancy, where the rule is the top 5 |

**Example.** Sanctioned 10, and you upload 7 officers:

```
Working Officers      : 7
Total Vacancy         : 3
Zone of Consideration : 10        (2 × 3 + 4)
```

**You cannot type over any of the three**, and that is deliberate — a typed vacancy that disagreed with the officer chart is how a DPC ends up promoting more officers than it has seats for.

#### What you still decide: how the vacancy is made up

The four vacancy types stay yours to enter:

- **Clear Vacancy**
- **Retirement Vacancy**
- **Promotion Vacancy**
- **Deputation Vacancy**

They do not add up to the Total Vacancy — they **classify** it — so **they must total exactly the Total Vacancy the portal calculated.** With a Total Vacancy of 3, `Clear 1 + Retirement 1 + Promotion 1 + Deputation 0` is accepted; `Clear 2 + Retirement 2` is refused, because it totals 4.

You may also split each type by reservation category, and the same rule applies to the whole grid: every row added together must equal the Total Vacancy.

#### Two refusals you may meet

> **"Working Officers cannot exceed the sanctioned strength."**
> Your chart has more officers than the post is sanctioned for. One of the two is wrong. Nothing is saved until you fix it — including on an upload, where the file is refused and your existing chart is left untouched.

> **"The sum of all vacancy types must equal the calculated total vacancy."**
> The message tells you both numbers. Adjust the vacancy rows, not the Sanctioned Posts, unless the sanctioned figure is what was wrong.

**An important practical point.** Because the vacancy is *derived* from the officer count, **you must list every officer in post** — not a sample. A cadre with 40 sanctioned posts and 37 officers serving needs all 37 on the chart; filing 3 of them would tell the portal there are 37 vacancies.

### 5.2b The Seniority List must match the Zone of Consideration

Where a Zone of Consideration applies, the **Seniority List must contain exactly that many officers** — no more, no fewer.

```
Zone of Consideration : 10   →   the list must carry exactly 10 officers
```

The Seniority List screen shows all four figures at the top, and says how many officers it currently holds, so you know the target before you start rather than after being refused.

> **"The uploaded Seniority List must contain exactly 8 officers based on the calculated Zone of Consideration."**
> The list is the wrong length. The message always names the required number. This applies to typing and to uploading alike, and an upload of the wrong length is refused without disturbing the list you already have.

**When no exact count is required.** Two cases, both shown on screen rather than left to be guessed:

- **You have not entered the Sanctioned Posts yet.** There is no vacancy, so there is no zone. Fill in section 3 first.
- **The promotion is not Class-I → Class-I.** The rules give a numerical zone only for that pair. For every other pair, every officer meeting the experience qualification is considered and there is no cap — the screen says *"No cap"* and the list may be any length.

### 5.3 Entering officers from a spreadsheet

Sections 3, 4 and 5 accept an Excel or CSV upload.

1. **Download a blank template** — the link is beside the upload box on each section, and there is also a **Upload Templates** tile on your dashboard. The template has the exact column names in the exact order, and nothing else.
2. Fill it in. Two things people get wrong:
   - **CAT** takes the **code** — `UR`, `SC`, `ST`, `OBC` — not the full name.
   - A missing CR year or property return must be left **blank**, not filled with "N". Blank means *not recorded*, which is a different fact and is handled differently.
3. **Upload & Preview.** Nothing is saved yet.
4. The **preview** shows every row it read and every error it found, with row numbers. Fix the file and upload again if needed.
5. **Commit** replaces that section with the valid rows.

**Column order does not matter and unrecognised columns are ignored.** Dates may be written however your spreadsheet prefers.

### 5.4 Submitting

**Submit for Approval** on the proposal. If a section is incomplete the portal names which. The proposal then becomes **read-only** and moves to the Administrative Head.

---

## 6. For the Administrative Head

**Menu: DPC Proposals** (and **Pre-DPC**)

You will be notified when something is waiting.

1. Open the proposal and use the **review walkthrough** — it presents all five sections in order, exactly as submitted.
2. Then choose:
   - **Return for Correction** — with remarks. **Write what is wrong**; your remarks are what the AO sees and are kept permanently.
   - **Approve, Sign & Forward to CGPSC** — the portal asks for an **OTP**. This is your signature. The whole proposal is signed as one document and forwarded to the Commission.

**You cannot enter or edit data.** That is the AO's, by design.

**After forwarding**, the DPC receives its identifier — for example `SE202600001` — which is how everybody refers to it from then on.

---

## 7. For the Chairman and Members

### 7.1 Chairman — allotment

**Menu: Allotments.** Forwarded DPCs are listed. **Allot** assigns one to a Member, with a suggested date. **Re-allot** moves it to a different Member; the earlier allotment stays visible beside the new one.

To conduct a DPC yourself, allot it to yourself first — so exactly one person is answerable for each promotion.

### 7.2 Member — accepting and scheduling

**Menu: Schedules.** DPCs allotted to you appear here.

- **Accept** the allotment.
- **Set the date and time** of the sitting. The department is notified.
- **Reschedule** if needed, with a reason. **The old date stays visible beside the new one** — the record shows what changed.

### 7.3 Member — conducting the sitting

**Menu: Proceedings → Open Workspace**, on or after the scheduled date. Only the Member the DPC was allotted to may open it.

**The workspace shows the department's evaluation chart, exactly as submitted.**

- **EDIT** — correct a cell. **You must give a reason.** The correction is recorded *beside* the original, never over it; both remain visible for ever.
- **TRIBAL** — record tribal-area service where it applies.
- **SAVE** — keep your corrections.
- **CALCULATE** — the system scores every officer against the notified rules and produces the **main list** and the **waiting list**.

**Every result is explicable.** For any officer, **Explain Result** shows each mark, where it came from, and every rule applied. Nothing is a black box.

**What you may see on a result:**

| | Meaning |
|---|---|
| **Provisional** | An unsettled rule could have changed this result. It is complete and visible, but **cannot be published** until the Commission settles the rule. The screen names the question |
| **CIR** | This officer's promotion is **held** — their record is incomplete (missing CRs or property declarations). They are in the proceeding, but the decision waits. Resolved later by **Circulation** |
| **SS** | Super seat |
| **EL / NE** | Eligible / not eligible |

- **APPROVE BY CGPSC** — approves the result. **This is the decision**, and it is fixed at this moment.
- **CANCEL DPC** — cancels it, with a reason.

**If you change the chart after calculating**, the portal notices the calculation is out of date and requires you to recalculate. It will not let you approve a result computed from superseded data.

### 7.4 Documents

After approval, **generate the proceedings document and the promotion list**. Each is rendered, stored, fingerprinted with a SHA-256 hash, and signed with your OTP.

**Publish** releases them to the department, which is notified. Anyone can later **verify** a document: the portal re-checks the hash and confirms it is exactly the document that was signed.

---

## 8. Corrections after the sitting

### 8.1 Review DPC — when the result was wrong

**From the DPC list, on a concluded DPC: Raise Review DPC.**

Choose one or more **reasons** from the list. The chart arrives **pre-filled from the original**, and **the original is never touched**.

A Review then runs the *same* full process — allotment, acceptance, scheduling, sitting, calculate, approve, documents. **It is calculated under the rules that applied to the original DPC**, not today's, because that is what makes the correction fair.

On approval it **supersedes** the DPC it corrects. Both timelines record it, the department is notified with both identifiers, and the earlier documents are stamped **SUPERSEDED** when downloaded — while the stored document and its hash stay exactly as issued.

**Every screen of a Review DPC carries a warning band** saying so, naming which DPC is being corrected, its date, which review in the chain this is, and the reasons. A Review looks exactly like a fresh DPC, which is precisely why it is announced. Departmental users also get a **Review Chain** button there, which shows the whole correction history end to end. Commission users see the band but not that button: the chain is a departmental screen.

> **What supersession means for an officer already promoted is not yet settled by the Commission.** The portal records the supersession and tells the department which list is no longer current. It says nothing about the promotion itself, and it will not until CGPSC rules.

### 8.2 Circulation — when a held promotion can now be resolved

**Menu: Circulation DPC → + New**, choosing a concluded DPC with held (`CIR`) promotions.

1. Select the held officers and **attach the evidence** now available.
2. **Submit** (AO) → **Approve & Sign** (AH). It goes to the Commission.
3. The Commission records a **decision per officer** and **APPROVE BY CGPSC**.
4. **The Commission's signature is the operative act.** Holds are released inside that signature, and only for officers consented to. **A note that is never signed leaves every promotion held.**
5. The **circulation note** and **revised promotion list** are then produced, signed and verifiable.

**Only the Member who conducted the original DPC may act on its circulation** — including where that is the Chairman. Exactly one person is answerable for each promotion.

> **If you see** *"Pending Business Decision (BD-14)"* when publishing — the Commission has not yet settled from what date a circulated promotion takes effect. The note is complete, signed and verifiable; only its release is held. Nothing has been lost.
>
> **If a circulation is refused for a CR shortfall** — resolving a missing CR would change the officer's marks and could reorder the promotion list, including for officers who were never told. The Commission has not yet settled whether that belongs in a Review instead. The case is recorded, not lost.

---

## 9. For an officer under consideration — Self-Inspection

**You do not need an account.** From the portal's front page, choose **Self-Inspection**, or on the login page follow *"An officer under consideration for promotion?"*

### What it is for

Before the committee sits, you may **check the data your department recorded about you** — and raise a correction if it is wrong. That is the whole purpose.

### How to open it

**View my data**, then confirm three facts your department recorded. The screen labels them:

- **Name (as recorded by your department)**
- **Date of Joining Current Cadre**
- **Date of Birth**

All three must match exactly. If they do not, the portal will not tell you which one was wrong — that is deliberate, so nobody can look an officer up by guessing one field at a time.

> **Details not matching?** They must match **your department's record**, which may differ from your own papers — and that difference is itself worth raising with them.

Attempts are limited and **every attempt is recorded**.

### What you will see

Your own row: name, date of birth, qualification, date of joining the current cadre, category, and **year by year** your loyalty declarations, immovable-property returns and CR grades — plus any note. A dash (**—**) means your department recorded nothing for that year, which is different from recording "no".

**Two buttons:** **PRINT** (a clean copy you can take with you) and **CLOSE**.

### What you will not see

**No marks, no remarks, and no promotion decision.** Those are produced by the committee, which has not met. This screen shows what your department supplied — nothing more.

### If the data is wrong

**Go to your department in person**, on or before the date shown in the notice.

**The Commission does not consider representations about departmental data** — they are filed without action. Your department holds the record and only your department can correct it. Do this **before** the committee sits; afterwards the data it considered cannot be changed.

If no date is shown, the sitting has not been scheduled yet. Raise it as soon as you can.

---

## 10. Reports

**Menu: Reports.** Every role has this, and it shows only what you are entitled to see — your own department's DPCs, or all of them if you are with the Commission.

| Report | Answers |
|---|---|
| **Outcomes** | How many officers were promoted, are waiting, are held, or were not placed |
| **Where the DPCs are** | How many sit at each stage, in process order |
| **DPCs by year** | Volume per year |
| **Corrections and circulations** | Reviews raised, circulation notes raised and signed |
| **By department** | One row per department (Commission only) |

**Export pipeline (CSV)** downloads the stage table.

**Every figure comes from a stored, signed result** — nothing is recalculated for a report. A report can never disagree with the document it summarises.

---

## 11. Notifications

The **bell** in the top bar shows unread count; **See all** opens the full list. Each notification says what happened in plain words — *"A date has been fixed for your DPC"* — with the exact transition beneath, and remarks where there were any.

**Open** takes you to the screen where you act on it and marks it read at the same time. **Mark all read** clears the list.

You are notified when: a proposal awaits your approval; yours is approved or returned; your DPC is allotted, accepted, scheduled or rescheduled; a sitting opens; a result is approved; **signed documents are released**; a circulation note needs you or is signed; a DPC of yours is superseded by a Review.

> Notifications will also be sent by SMS and e-mail once the Government gateway is connected. **This list does not depend on it** — so nothing is missed in the meantime.

---

## 12. Common tasks

| I want to… | Do this |
|---|---|
| Add a post to my department's sanctioned list | Pre-DPC → new proposal or *Amend* → Add Post → submit → AH approves |
| Correct an approved Pre-DPC | Open it → **Amend (new version)**. The old version stays as the record |
| Enter 40 officers without typing | Section → **Download a blank template** → fill → **Upload & Preview** → **Commit** |
| Fix a rejected upload | Read the preview's errors — each names its row — correct the file, upload again |
| Find out why an officer scored what they did | The result screen → **Explain Result** |
| See everything that happened to one DPC | Administrator: **Audit trail**, filter by subject. Anyone: the DPC's own timeline |
| Check a document is genuine | Open it → **Verify**. The portal re-checks its hash |
| Understand "provisional" | An unsettled rule could have changed the result. It cannot be published until the Commission settles it |
| Resolve a held (`CIR`) promotion | **Circulation DPC**, attaching the record that was missing |
| Correct a wrong result | **Raise Review DPC** from the concluded DPC |
| Check my own data as an officer | Front page → **Self-Inspection** (no account needed) |

---

## 13. Frequently asked questions

**Can I edit a proposal after submitting it?**
No. It becomes read-only. The AH can **return** it to you, which makes it editable again.

**Why can't I see the "New Proposal" button?**
Only the Authorized Officer creates proposals. An Administrative Head approves them. The portal shows only buttons your role can actually use — if you cannot see it, you would not have been allowed to use it.

**Why does the portal say a result is "provisional"?**
A rule the Commission has not settled could have changed it. The result is complete and every mark is explicable, but it cannot be published until the rule is settled. This protects officers from a promotion list that might have to be withdrawn.

**Why was my Pre-DPC approval refused over a feeder cadre?**
A feeder you named is not registered as a post in your department's list. The rule would be created without it and would promote nobody from that cadre — so the portal refuses instead of producing a Government Order that does nothing. Ask the Commission to register the post.

**An officer's CR for one year is missing. What happens?**
They are still considered, and appear in the proceeding. Their promotion is **held** — marked `CIR` — until the record is supplied through a **Circulation**. They are not simply dropped.

**Can the committee change the department's chart?**
Yes, during the sitting, with a reason for each change. Corrections are recorded **beside** the original, never over it. Both remain visible for ever.

**Who can conduct a DPC?**
Only the Member it was allotted to. A Chairman who wishes to conduct one allots it to himself first.

**What happens if two people click Approve at the same time?**
Only one approval is recorded. The portal takes a lock and refuses the second — so a proposal can never carry two signatures.

**Can a signed document be altered?**
No. It is fingerprinted when signed and **Verify** re-checks the fingerprint. Even a superseded document keeps its original bytes — the `SUPERSEDED` stamp is applied when it is delivered, not stored into it.

**Is my data safe on the Self-Inspection screen?**
You see only your own row, and only while a proposal including you is under consideration. Every attempt to open it is recorded. It shows no marks and no decision.

**Why did I not get an SMS?**
The Government SMS gateway is not yet connected. **Use the bell in the portal** — the in-app list is complete and does not depend on it.

**Why are the web addresses so long?**
Every proposal, sitting, document and note is addressed by a long random identifier rather than by a number like `4`. This is deliberate: a numbered address tells anyone who sees it how many DPCs exist, and invites trying the neighbouring numbers. Bookmarks and links still work normally — the address for a given DPC never changes.

---

## 14. Demonstration walkthrough

About 25 minutes, covering the whole process. Run `demo:seed` first; it prints every credential.

| # | As | Do | Point out |
|---|---|---|---|
| 1 | — | Open the front page | Six roles; **Self-Inspection** needs no account |
| 2 | AO | Pre-DPC → open the approved proposal | Posts and feeders from **lists**, not typed |
| 3 | AO | DPC Proposals → open one → the five sections | Every section marked complete |
| 4 | AO | Evaluation Chart → **Download a blank template** | The exact columns; the commonest cause of a rejected upload, removed |
| 5 | AH | Open the same proposal → review walkthrough | The AH sees **no** edit buttons |
| 6 | Chairman | Allotments | Re-allotment keeps the old allottee visible |
| 7 | Member | Schedules → accept, set a date | The department is notified |
| 8 | Member | Proceedings → Open Workspace | The department's chart, as submitted |
| 9 | Member | **EDIT** a cell with a reason → **SAVE** | The correction sits **beside** the original |
| 10 | Member | **CALCULATE** → the result | **Explain Result** on one officer — every mark traced |
| 11 | Member | Find an officer marked `CIR` | Held, not dropped |
| 12 | Member | **APPROVE BY CGPSC** → generate → sign → publish | Then **Verify** the document |
| 13 | AO | Circulation DPC → the seeded one | The Commission's signature is the operative act |
| 14 | — | Self-Inspection with the three printed facts | Own data only; no marks; **CLOSE** and **PRINT** |
| 15 | Any | The **bell** | Notifications without any gateway |
| 16 | Any | **Reports** | Counted from signed results, never recalculated |
| 17 | Admin | **Audit trail** | Three records; read-only; export |

**Worth saying once, at the end:** every screen that could have guessed at an unsettled rule refuses instead, and names the question. That is the design, not a limitation.

---

## 15. Troubleshooting

| Symptom | What it means | What to do |
|---|---|---|
| *"Only draft/returned proposals can be edited"* | It has been submitted | Ask the AH to return it |
| *"This proposal cannot be submitted yet — the following section(s) are incomplete"* | Exactly what it says | Complete the named sections |
| Upload rejected with a row list | The file did not match | Read each row's error, fix, re-upload. Use the **blank template** |
| *"Select a feeder cadre from your department's Cadre master"* | The post is not registered | Ask the Commission to add it |
| Cannot file a Pre-DPC at all; the form is disabled | Your department's post list is empty | Ask the Commission to register your sanctioned posts |
| *"Pending Business Decision (BD-…)"* | A rule the Commission has not settled | Nothing is lost. Note the number and raise it with the Commission |
| Result marked **provisional**, cannot publish | An unsettled rule could have changed it | As above |
| *"Open Workspace"* missing on a DPC allotted to you | It was re-allotted, or the date has not arrived | Check Schedules |
| Calculation reported out of date | The chart changed since | **CALCULATE** again |
| No OTP arrived | The SMS gateway is not connected yet | Contact the Commission; in development the code is written to the application log |
| Self-Inspection will not accept my details | They must match your **department's** record exactly | Check with your department — the difference is itself worth raising |
| *"Too many attempts"* on Self-Inspection | Rate limit | Wait and try again; contact your department if the details are right |
| A document shows **SUPERSEDED** | A Review DPC has corrected that DPC | Use the Review's promotion list. Both remain available |

**Still stuck?** Departmental users: your nodal officer. Commission users: CGPSC administration. Officers under consideration: **your own department** — they hold your record, and only they can correct it.
