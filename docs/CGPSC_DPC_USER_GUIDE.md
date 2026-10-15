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
| **Promotion Candidate** | An officer on a Seniority List | Sees the DPC they are being considered in, its stage, and their own final result once it is published. Sees nothing about any other officer |
| **Officer under consideration** | Any officer whose name is on a chart | Views their own data before the committee sits, through Self-Inspection. **Needs no account** |

**There is one Administrator, and it belongs to the Commission.** "CGPSC Administrator" is the only administrator role in the portal; there is no departmental administrator, and a department's own two roles are the AO and the AH above.

**A department is a posting, not a property of the account.** An officer's login never changes when they are transferred: the Administrator records a new posting, which closes the previous one and keeps it. That is why an old proposal still shows which department the officer who signed it was in **on the date they signed it**.

**Why the AO cannot approve their own work.** The Commission's own process puts the entry with one officer and the approval with another. The portal enforces it: the buttons an AO sees are not the buttons an AH sees, and the system refuses the action even if the request is made another way.

---

## 3. Signing in

Go to the portal address and choose **Login**.

- **Enter** your email address, your mobile number **or** your employee number, and your password. The portal works out which you gave it.
- **First time?** You will be given a temporary password. The portal **requires** you to change it before you can do anything else.
- **Forgotten password?** Contact your department's nodal officer or the Commission. There is no self-service reset.

**An OTP at the important moments.** When you do something legally significant — approving and signing a proposal, signing a result — the portal sends a one-time password and asks for it. This is your signature, and it is recorded as evidence that you intended the action.

**A Promotion Candidate does log in here** — with the employee number their department recorded on the Seniority List. See §9a.

**An officer who has no account at all** uses Self-Inspection instead. See §9.

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

### 5.0 What the Administrator registers first — the Department Post Mapping

**Menu: Administration → Department Posts**

A mapping is one post in one department, with its **Pay Scale**, **Class** and **Promotion Duration** — and it is what a **Government Order** says. So the mapping records the order that authorises it:

- **Government Order / Circular Number** and its **date**
- the **PDF** of the order itself
- **Effective from**, and **Effective to** once something replaces it

**A new Government Order does not edit the mapping — it replaces it.** Press **New Government Order** on the mapping in force and enter the new terms and the new order. What happens then:

| | |
|---|---|
| The previous mapping | Marked **inactive**, closed on the day before the new one begins, and **kept** |
| The new mapping | Becomes the one **in force**, from its own effective date |
| DPCs already raised | Unchanged — each still points at the mapping it was raised under |

That last row is the whole reason for it. A DPC conducted in 2026 must still be auditable against the order that authorised it in 2026, even after the Government issues a new one in 2027. Only the mapping **in force** is offered when a Pre-DPC is drafted.

**Replacing the PDF keeps the old one.** Uploading a clearer scan retires the previous file rather than deleting it; every version stays on the mapping's details page, each with the date it was uploaded, who uploaded it, and whether it still matches the hash taken at upload. Only PDFs are accepted.

> **If the Government Order says "Not recorded"** — that mapping was registered before orders were captured. It still works; it simply has no order on file, and the portal says so rather than showing a blank.

---

### 5.1 Pre-DPC proposal — sanctioning one promotion

**Menu: Pre-DPC**

A Pre-DPC is the Government Order that sanctions promotion **into one post**, for one DPC Year — from every post that promotion may draw on. It must be approved before you can file the DPC that runs under it.

1. **+ New Proposal**
2. **DPC Year** — the year this exercise belongs to.
3. **Promotion To Post** — the post being filled, chosen from your department's registered posts, never typed. If the list is empty, ask the CGPSC Administrator to register your posts; a Pre-DPC cannot invent one. Its **Pay Scale and Class** appear beneath it.
4. **Promotion From Posts** — the posts officers are promoted *from*. **Use *Add Promotion From Post* for as many as the order names**: a Deputy Director may be filled from Assistant Director, Senior Lecturer and Research Officer, and one order carries all three. Each row asks for:
   - the **post** itself;
   - its **Pay Scale and Class**, which fill in on their own and cannot be typed. **These are that post's own** — promotion eligibility is judged on the post the officer holds today, so the figures beside a feeder are the feeder's, not the promotion post's;
   - its **share** of the promotion quota. The shares must total exactly 100% between them;
   - its **Minimum Experience** and **Minimum Qualification** — set per post, because five years as an Assistant Director is not the same condition as five years as a Research Officer.

   Use *Remove* to drop a row. The last one cannot be removed: an order that names no Promotion From Post sanctions nobody.
5. **Direct Recruitment %** and **Promotion %** — these must total exactly 100. The portal says so if they do not.
6. Enter the **Government Order number** and the **date it takes effect**. Both are required before you can submit: a Pre-DPC without its number and date sanctions nothing.
7. **Save draft** as often as you like, then **Submit for Approval**.

**Its number is issued when you save the draft** — `PDPC-2026-SE-0001`, running per department per year — and it never changes afterwards.

**Buttons:** *Save draft*, *Submit for Approval*, *Cancel*, and — after approval — *Amend (new version)*.

**One approved Pre-DPC per exercise, at a time.** An exercise is Department + Promotion To Post + DPC Year — the From Posts are listed *inside* the order, so they are not part of what makes it unique. Approving an amendment makes the previous order **inactive** rather than deleting it, so what was sanctioned when is always readable. An amendment gets a **new** Pre-DPC Number, because it is a new order.

> **If you see:** *"an approved Pre-DPC is already in force for this exercise"* — one already sanctions that exact promotion for that year. Use *Amend* on it rather than drafting a second.

> **A post filled from several posts is one Pre-DPC, not several.** Add a row per feeder. The Recruitment Rule the portal maintains for the *To* post is composed from that order's rows, so it names every feeder with the share the order sanctioned.

### 5.2 DPC proposal — the five sections

**Menu: DPC Proposals → + New Proposal**

You choose the **approved Pre-DPC** the promotion is sanctioned by, and — where that order names more than one — **which Promotion From Post this DPC runs**. One DPC runs one feeder, because the seniority list and the qualifying service are per post; an order naming exactly one selects it for you. Everything else is inherited from the order: Department, DPC Year, Promotion To Post, Recruitment Rule, Class, Pay Scale and Promotion Duration. There is no way to raise a DPC without one. The **Proposal Date** is generated, never typed. Then complete five sections in any order.

| Section | What it holds |
|---|---|
| **1 · Post Selection** | The promotion pair and terms inherited from the Pre-DPC, the DPC type, the generated proposal date |
| **2 · Committee** | The committee members and their capacities |
| **3 · Working Officers & Vacancies** | Officers currently in post, and vacancies by type and category. **Three figures here are worked out for you — see §5.2a** |
| **4 · Seniority List** | The final seniority list, in order |
| **5 · Evaluation Chart** | **Generated for you from section 4.** You fill in only the year-by-year CR grades, loyalty and property declarations, tribal-area service and a note — see §5.2c |

**Every section shows whether it is complete.** You cannot submit until all five are.

**Saving the Seniority List creates a login for every officer on it** (a Promotion Candidate account, so they can follow their own DPC). The temporary passwords are shown to you **once**, immediately after saving — they are not recoverable afterwards, so hand them over then. The same screen lists the officers and lets you **reissue** a password for anyone who has lost theirs.

### 5.2a Vacancies are calculated, not typed

On **Working Officers & Vacancies** you enter **one** number — the **Sanctioned Posts** for the Promotion To Post. The portal then works out three more and shows them greyed out, because they are consequences rather than choices:

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

**An important practical point.** Because the vacancy is *derived* from the officer count, **you must list every officer in post** — not a sample. A post with 40 sanctioned posts and 37 officers serving needs all 37 on the chart; filing 3 of them would tell the portal there are 37 vacancies.

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

### 5.2c The Evaluation Chart is generated for you

**You do not enter the officers again.** As soon as the Seniority List is saved, the portal builds the Evaluation Chart from it — one row per officer, with everything it already knows about them filled in:

| Filled in for you | You provide |
|---|---|
| Employee No., Name, Date of Birth | **CR Grade** (year by year) |
| Qualification, Category | **Loyalty** (year by year) |
| Promotion To Post, Promotion From Post | **Immovable Property** (year by year) |
| Date of Joining Service | **Tribal-area Service** |
| Date of Posting on Promotion To / From Post | **Note** |

Those details are shown as plain text, not as boxes to type in. **To correct any of them, change the Seniority List** — the chart follows automatically, and the CR grades you have already entered are kept.

This is why the old duplication was worth removing: the same officer used to be entered on three screens, nothing checked that the three agreed, and it is the *chart* the Commission's engine scores. The least-checked copy was the one that decided the promotion.

#### The pre-filled spreadsheet

Press **Download pre-filled Excel template** on the Evaluation Chart screen. **Every officer is already on it.** Fill in only Loyalty, Immovable Property, CR, Tribal Area Service and Note, then upload it back through the same screen.

Two things worth knowing:

- **Leave a year blank where nothing was recorded.** Blank means *not recorded*, which is a different fact from "no" — it is what a Circulation DPC exists for. Do not put `N` in a cell you have no record for.
- **Download it again whenever you like.** It comes back with whatever you have already entered, so it is safe to download, edit a little and upload repeatedly.

#### The counts at the top of the screen

```
Expected Officers : 10        (from the Seniority List)
Imported Officers : 10
```

They must be equal. If they are not:

> **"The Evaluation Chart must contain exactly the same officers as the approved Seniority List."**
> An officer has been added, removed or renumbered in the file. Download the pre-filled template again and work from that — it is always the right set.

> **"Employee numbers cannot be changed on the Evaluation Chart"**
> A row's employee number does not match the Seniority List. This one is refused by name rather than ignored, because a reassigned employee number is how one officer's confidential record ends up filed against another's.

If the screen says **"Upload the Seniority List first"**, section 4 is empty — the chart is made from it, so there is nothing to fill in yet.

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

**Menu: Allotments.** Every DPC a department has approved and forwarded is listed here. Allotting is one act with three decisions:

- **Allot to…** — the Member who will receive and conduct it.
- **DPC Chairman** — who will preside, chosen from that DPC's own committee. Your own seat is on the list, so to preside yourself, select it.
- **Must be concluded by** — the completion deadline. Required.

**Re-allot** moves a DPC, changes who presides, or extends the deadline, with a reason. The earlier allotment stays visible beside the new one, and nothing is overwritten.

To *conduct* a DPC yourself, allot it to yourself — so exactly one person is answerable for each promotion. Presiding and conducting are separate: you may preside over a DPC somebody else conducts.

### 7.2 Member — accepting and scheduling

**Menu: Schedules.** DPCs allotted to you appear here.

- **Accept** the allotment.
- **Set the date and time** of the sitting. It must be **on or before the completion deadline** the Chairman set — a later date is refused, and the message names the deadline. Every committee member and every officer under consideration is then notified of the date, the time and how to attend.
- **Reschedule** if needed, with a reason, and within the same deadline. **The old date stays visible beside the new one** — the record shows what changed — and everybody is told the date has moved.

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
- **Date of Joining Service**
- **Date of Birth**

All three must match exactly. If they do not, the portal will not tell you which one was wrong — that is deliberate, so nobody can look an officer up by guessing one field at a time.

> **Details not matching?** They must match **your department's record**, which may differ from your own papers — and that difference is itself worth raising with them.

Attempts are limited and **every attempt is recorded**.

### What you will see

Your own row: name, date of birth, qualification, date of joining service, category, and **year by year** your loyalty declarations, immovable-property returns and CR grades — plus any note. A dash (**—**) means your department recorded nothing for that year, which is different from recording "no".

**Two buttons:** **PRINT** (a clean copy you can take with you) and **CLOSE**.

### What you will not see

**No marks, no remarks, and no promotion decision.** Those are produced by the committee, which has not met. This screen shows what your department supplied — nothing more.

### If the data is wrong

**Go to your department in person**, on or before the date shown in the notice.

**The Commission does not consider representations about departmental data** — they are filed without action. Your department holds the record and only your department can correct it. Do this **before** the committee sits; afterwards the data it considered cannot be changed.

If no date is shown, the sitting has not been scheduled yet. Raise it as soon as you can.

---

## 9a. For a Promotion Candidate — following your own DPC

**You have an account, and it was created for you.** Every officer named on a Seniority List gets one: the login is the **employee number** your department recorded, and the password is issued to you. If you already had an account here for any other reason, that account is reused — the portal never creates a second one for the same person.

**Menu: My DPC**

### What you can see

| | |
|---|---|
| **Your own DPC** | The exercise you are being considered in — the promotion, the department, the DPC Year |
| **Its status** | Where it has reached, in plain words |
| **Its timeline** | Filed, submitted, approved, forwarded, allotted, sitting scheduled, result approved, documents published |
| **Notifications** | The bell, as every role has |
| **Your final result** | Once the Commission has **published** the proceedings — and not one moment before |

### What you cannot see, and why

| | |
|---|---|
| **Any other officer** | Not their name, not their marks, not their position. Another officer's assessment is not yours to read |
| **The committee** | Who sat on it is not a candidate's business while the DPC is live |
| **The evaluation chart** | Marks, grades and gradings for anybody, including yourself, before publication |
| **Internal remarks** | Anything the department or the Commission wrote for itself |

**Your result appears when the document does.** Not when the Commission approves the result, and not when the document is generated — when it is **published to your department**. Until then the portal will tell you the stage and nothing more, which is the honest answer.

**To check the particulars recorded about you** — your dates, category, qualification and year-by-year entries — follow the Self-Inspection link on this screen. It is the same screen §9 describes, and it needs no account.

**If any of it is wrong**, that is a matter for your **department**, in person — the same rule as §9. The Commission does not consider representations about departmental data.

**If you have lost your password**, ask the department that named you on the Seniority List. There is no self-service reset: the list records no mobile number or e-mail, so the portal has no way to send you a code. Your Authorized Officer can issue a new temporary password from the Seniority List screen and hand it to you.

---

## 9b. What changed on 2026-08-26

If you used the portal before this date, five things behave differently.

### The Commission Members list is gone

There is no longer a master list of people who may sit on a committee. You add
members **when you compose the committee**, and there are two ways to do it:

1. **Search Existing User** — type an e-mail address or a mobile number. If the
   person already has an account, select them and their details fill in.
2. **Create New Member** — type their name, designation, mobile and e-mail. As you
   type the e-mail or the mobile the portal checks whether that person is already
   in the system; if they are, it says **"This user already exists"** and offers
   **Use Existing User**. Take it — it links the person you already have instead of
   creating a second copy of them.

**Sitting on a committee gives nobody any new access.** It is recorded against that
one DPC and nothing else. The same person can be an Authorized Officer in their own
department, a Promotion Candidate on somebody else's list, and a committee member
here, all at once.

### Each member records what they represent

The form asks for two different things, and they behave differently.

**Social category — choose one, or none.** SC, ST and OBC. A member belongs to
**only one** of them, so ticking one clears the others: SC + ST, SC + OBC and
ST + OBC are all refused. **A member who is none of the three is UR (General)** —
there is no UR box to tick, and the badge beside the boxes says *UR (General)*
while all three are blank so you can see what you have recorded.

**Additional attributes — any combination.** Female, Bhar Sadhak Sachiv, Vibhag
Sachiv. These combine freely with each other and with whichever social category
you chose. All of these are valid:

- SC + Female
- SC + Female + Bhar Sadhak Sachiv
- ST + Female
- OBC + Bhar Sadhak Sachiv
- Female only *(UR)*
- Female + Vibhag Sachiv *(UR)*
- Female + Bhar Sadhak Sachiv + Vibhag Sachiv *(UR)*

**One member may cover several requirements at once, and that is the point.** The
portal checks what the committee represents **as a whole** and always tries to
satisfy the requirements with the fewest members. You do **not** need a separate
member for each attribute. This committee is valid:

| Member | Represents |
|---|---|
| 1 | SC · Female · Bhar Sadhak Sachiv |
| 2 | OBC |
| 3 | Vibhag Sachiv |

Five requirements, three members.

These describe the *committee*, not the officers being promoted. They have nothing
to do with reservation.

### The CGPSC Chairman is added for you

Every DPC committee includes the CGPSC Chairman. You will see the seat above the
form, and you cannot add or remove it — it comes from the record the Administrator
keeps under **Administration → CGPSC Chairman**. The three-to-six members you
compose are *in addition* to that seat.

If the portal tells you no CGPSC Chairman is recorded, the committee cannot be saved
until the Administrator records one. That is an Administrator's task, not yours.

### Who conducts a DPC, and by when

**Three responsibilities, and two of them may be the same person.**

| | Who | What they do |
|---|---|---|
| **CGPSC Chairman** | the serving Chairman on record | Every approved DPC reaches them. They decide **who receives it**, **who presides over it**, and **by when it must be concluded** |
| **DPC Allottee** | the Member it is given to | Coordinates and conducts it. Schedules the sitting, and may reschedule |
| **DPC Chairman** | one member of that DPC's committee | Presides over that one sitting |

The Chairman makes all three decisions **on one form**, when they allot. The
"DPC Chairman" list offers that DPC's own committee — including the CGPSC
Chairman's own seat, so presiding personally is simply that option rather than a
separate control.

**The allottee and the DPC Chairman may be the same person, or different people.
Both are normal.**

**The DPC Chairman is valid for that DPC only.** No permanent chairman or member
role is created; the record sits on that committee membership and nowhere else.

**Scheduling is bounded by the deadline.** If you are the allotted member you set
the date and time on or before the completion date, and may reschedule within it.
A date past the deadline is refused against the date box, and the message names the
deadline and tells you who can extend it.

**If the DPC is not concluded by that date it goes back to the CGPSC Chairman**
automatically. They may then reassign it to the same member, give it to another,
change who presides, or extend the deadline. All of it appears on the DPC's
allotment history, including the automatic return itself.

A member may hold several DPCs at the same time.

### If you hold more than one role

Some accounts hold several roles. If yours does, the header shows an **Acting as**
control naming the role you are currently working in.

**The role you select decides what you can do.** Acting as a Member, you cannot
approve a proposal as an Authorized Officer — switch first. This is deliberate: the
portal records the capacity you acted in, and it can only do that if you say which
one you are using. Switching is recorded on the timeline.

If your account holds one role you will not see the control, and nothing changes for
you.

### Two smaller changes

- **Pay Scale and Class now describe the Promotion From Post.** Eligibility is
  judged on the officer's *current* post, so the figures shown are that post's, not
  those of the post nobody has been promoted into yet. Where an order names several
  feeders, each row shows its own.
- **Department Post Mapping takes several Government Orders.** Each has its own
  number, date and PDF, and **Add More** records another. Previous orders stay on the
  record — nothing replaces anything. **Qualifications are now multi-select** on
  recruitment rules and Pre-DPC promotion sources: tick every degree the post
  accepts.

---

## 9c. What changed on 2026-08-27 — vacancies and merit

If you used the portal before this date, six things behave differently. Two of them
remove work from you; four add a step, and each of those exists because the Commission
asked for a record it did not have before.

### You no longer type the number of sanctioned posts

The sanctioned strength of a post is now part of the **Department Post Mapping**, kept by
the CGPSC Administrator and authorised by a Government Order. The box you used to type it
into is gone from the Working Officers screen.

If the figure shown is wrong, it is wrong on the mapping and not on your DPC — ask the
Administrator to record a new Government Order. That is deliberate: how many posts a
department is sanctioned is not a fact a single DPC gets to decide, and two DPCs on the
same post disagreeing about it was possible before.

### Working strength is counted from your Working Officer List

You do not enter it. It is the number of officers on the list you upload. If it looks
wrong, the list is wrong.

### The vacancy screen now shows nine figures instead of one

```
Sanctioned Strength            40
Working Strength             − 34
                              ────
Base Vacancy                    6
Retirement Vacancy            + 2   ← officers retiring 1 Jan – 30 Nov
Deputation Vacancy            + 1   ← officers away on deputation in that window
                              ────
Total Vacancy                   9
Promotion Percentage           75%  ← from the Recruitment Rule
Promotion Vacancy               6
Direct Recruitment Vacancy      3
```

Two things to know about it:

- **The percentage is not yours to enter.** It comes from the Recruitment Rule for the
  post. There is no field for it, and there should not be — the share of a post filled by
  promotion is fixed by the rules, not by the DPC.
- **The retirement and deputation additions name the officers who caused them**, with
  their dates, underneath the table. If a figure surprises you, that list is where the
  answer is.

Every calculation is kept. A DPC that recalculates its vacancies keeps the earlier
figures too, so what the Commission saw when it acted stays on the record.

### Retirement and deputation dates on the Working Officer List

Two new columns, both optional:

- **Date of Retirement** — fill it in for an officer retiring within the DPC Year. An
  officer retiring between **1 January and 30 November** adds one vacancy.
- **Deputation From** and **Deputation To** — leave *To* blank if the officer is still
  away. A deputation that overlaps the vacancy period by even one day adds one vacancy.

The portal checks both against the period and tells you if a date falls outside it. It
does not invent dates: an officer with no retirement date recorded is simply an officer
who is not retiring, which is true of most of them.

### Missing CRs and Loyalty Certificates now need a reason and a document

This is the biggest change to how you work, and it changes an outcome.

Before, a missing CR was a blank on the chart and the engine scored what it found. Now,
where a **mandatory CR or Loyalty Certificate is unavailable**, you say why and attach the
proof — and having done that, **the officer stays in the DPC** with their promotion
*held* rather than dropping out of it.

**How.** On the Evaluation Chart, each officer's card has a **Missing records** button. It
opens a screen showing what that officer's chart is short of, and a short form:

| Field | Notes |
|---|---|
| Record | Confidential Report, or Loyalty Certificate |
| Year | A Loyalty Certificate must be a year that carries a CR — the certificate belongs to the CR year |
| Reason | From a list the Administrator keeps: Record Not Available, Officer on Deputation, Department Delay, Court Case, Record Lost, Newly Appointed, Other |
| Remarks | **Mandatory** for a reason that means nothing on its own — "Other", and any reason the Administrator has marked as needing particulars |
| Supporting document | **Mandatory. A PDF.** |

**Both the reason and the document are required.** A reason saved without its PDF does not
hold the promotion — it leaves the record simply missing, and the officer is treated as
not having accounted for it. The screen will not let you save one without the other.

**Five CRs are mandatory, and they need not be continuous.** An officer with CRs for
2013, 2016, 2017, 2019 and 2020 has five.

**An officer who has not served five years is not refused for that.** They have as many
CRs as there are to have. Record the absent years with *Newly Appointed* and the
supporting document, and they continue in the DPC.

### The result now says one of four things, not two

| Status | What it means |
|---|---|
| **Eligible** | Promotable |
| **Not Eligible** | Does not qualify — the marks fell short, or a recruitment-rule requirement is unmet |
| **CIR** | **Held, not refused.** A mandatory record is unavailable and has been accounted for. The officer continues in this DPC, and the promotion waits for the record. The screen names which record |
| **Rejected** | Out. The Property Return is missing from the mandatory five continuous years, or the promotion is not from a lower post to a higher one |

**CIR is not a failure.** If you read only one paragraph of this section, read this one: a
candidate marked CIR is still scored, still ranked, and still in the exercise. Their
promotion is waiting for a document, which is a different thing from being refused, and
the portal now says so on every screen that shows the outcome — including **Explain
Result**, which states the ground and names the awaited record.

### Two things the portal will tell you it could not decide

A calculation may report that a rule has not been settled. Two you may see:

- **A qualification it could not compare.** The Recruitment Rule names a qualification
  *level* — "Post-Graduate" — and the Evaluation Chart carries the degree your office
  typed — "M.A., B.Ed.". The portal will not treat those as a mismatch, because it cannot
  know they are one. It reports it and refuses nobody.
- **A promotion the hierarchy could not fully check.** If a pay scale has no level or
  grade pay recorded, that comparison is skipped and the run says so, rather than reading
  the missing figure as a demotion.

A run carrying either is marked **provisional** and cannot be published until the
Commission settles the point. That is the intended behaviour: the portal would rather stop
than publish a promotion list it cannot stand behind.

### New, for the CGPSC Administrator

**Admin → Reasons for a Missing Record.** The list of reasons an officer may choose from,
with a display order and a **"Remarks are mandatory"** tick per reason. Withdrawing a
reason stops it being chosen on anything new and leaves it on the records that already
cite it — because withdrawing a reason today does not change why a record was missing two
years ago.

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

### When a sitting is fixed, everybody who must attend is told

The moment the allotted Member sets — or moves — the date, the portal notifies
**every serving committee member** and **every officer on the Seniority List**. The
message carries the **DPC date**, the **DPC time** and **joining instructions**, and
a rescheduled sitting says so in as many words rather than repeating "scheduled".

Committee members are reached by SMS and e-mail as well as in the portal.

> **Officers under consideration are reached in the portal only.** A Seniority List
> records service particulars and carries no mobile number and no e-mail address,
> so there is nowhere to send an SMS to. They see the notice when they sign in.
> Giving candidates a contact channel is recorded as a future enhancement.

> Notifications will also be sent by SMS and e-mail once the Government gateway is connected. **This list does not depend on it** — so nothing is missed in the meantime.

---

## 12. Common tasks

| I want to… | Do this |
|---|---|
| Sanction promotion from another feeder post | Pre-DPC → open the order in force → *Amend* → **Add Promotion From Post** → submit → AH approves |
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

**Why can I not draft a second Pre-DPC for the same promotion?**
Only one approved Pre-DPC may be in force for a given Department, Promotion To Post and DPC Year. If the promotion draws on another feeder post, **add it to the order already in force** with *Amend* rather than drafting a second — one Government Order names every post its promotion may draw on. *Amend* issues a new number, and the previous order becomes inactive without being deleted.

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
| *"Your department has no posts registered yet"* | The Department Post master is empty | Ask the CGPSC Administrator to register the establishment |
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
