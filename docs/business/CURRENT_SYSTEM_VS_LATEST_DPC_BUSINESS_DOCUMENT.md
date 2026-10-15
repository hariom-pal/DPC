# Current DPC System vs Latest DPC Business Document

**Date:** 13 October 2026
**Purpose:** Show the gap between what the DPC system does today and what the latest business document asks for. This document only compares. It makes no recommendation and proposes no design.

---

## 1. Sources

| | |
|---|---|
| **Latest business document (source of truth)** | *Understanding of DPC with Coder Instructions* (PDF, 6 pages, internal title `DPC_UNDERSTANDING`, created 25 September 2026). It has four parts: (1) Vacancy, (2) Reservation, PH and Sealed Cover, (3) ACR/CR, (4) Statutory Document Audit. Each rule is written in Hindi, with a "coder instruction" in English. Both are used here. Rule numbers such as **1.3** or **3.1** refer to this document. |
| **Current system** | The DPC application at `/var/www/html/DPC`, branch `feature/m0-setup`, **working tree as on 13 October 2026**. This includes a large amount of uncommitted work. The newest database change is dated 7 September 2026, before the business document. So nothing in the system was built in response to it. |
| **Method** | The code, configuration, database migrations and screens were read directly. Older project documents were used only to quote the numbers of pending business decisions (BD-xx). |

## 2. How to read the tables

| Status | Meaning |
|---|---|
| ✅ Matches | The system does what the document asks. **Or** the document says nothing on this topic and the current behaviour does not conflict with it. The Remarks column says which of the two applies. |
| ⚠️ Partial | Part of the requirement exists, or it exists but behaves differently. |
| ❌ Missing | The requirement is not present in the system at all. |
| ❓ Business Clarification Required | The document is unclear, contradicts itself or another rule, or leaves the decision open. The gap cannot be measured until the business answers. |

Every difference in the **Remarks** column starts with its **gap type**:

- **Configuration difference:** the system already has the behaviour, but a setting holds a different value from the document. No new feature is needed to align it.
- **Implementation gap:** the system has no feature for what the document asks.
- **Unresolved business decision:** the answer is not settled, either in the document or in the project's pending business decision list (BD-xx).

## 3. Summary

| Section | ✅ | ⚠️ | ❌ | ❓ |
|---|---|---|---|---|
| 1. Vacancy Calculation | 1 | 6 | 2 | 2 |
| 2. Recruitment Rules | 3 | 3 | 2 | 0 |
| 3. Reservation | 0 | 1 | 6 | 2 |
| 4. Confidential Reports (CR) | 2 | 2 | 10 | 5 |
| 5. Documents | 0 | 2 | 12 | 0 |
| 6. DPC Workflow | 4 | 3 | 2 | 0 |
| 7. Committee Process | 3 | 1 | 0 | 3 |
| 8. Reports | 5 | 0 | 0 | 0 |
| **Total** | **18** | **18** | **34** | **12** |

Most ✅ rows in Sections 6, 7 and 8 are topics the latest document does not address. They mean "no conflict", not "requirement met".

**Main points**

1. **The parts of the document that are most specific are the ones missing.** Parts 3 (CR) and 4 (documents and undertakings) set out the most detailed requirements. Most of them are not in the system.
2. **The system has no undertakings at all.** The document asks for at least ten separate digital undertakings. None exist. No screen asks the user to confirm or declare anything.
3. **Three gaps are configuration only:**
   - vacancy period end date (30 Nov vs 31 Dec)
   - rounding of posts (floor vs round half-up)
   - qualification check (advisory vs enforced)
4. **The CR number system is different.** The system uses 4/3/2/1/0. The document asks for 9/7/5/3/1, kept as master data, with up to four CRs per year averaged. The system supports one CR grade per year.
5. **Rule 1.4 may conflict with the system's reservation logic.** The Hindi text says reservation (in promotion) ended from 2019. The system applies SC/ST/OBC category-wise vacancies and lists to every DPC. See section 3.1.

---

## 4. Section 1 — Vacancy Calculation

| Business Area | Current System | Latest Business Document | Status | Remarks |
|---|---|---|---|---|
| **Prerequisites — confirmation checklist** | *(Updated 15 Oct 2026.)* A "Pre-submission Verification (Rule 1.1)" step on the proposal records four confirmations: sanctioned strength/setup, Recruitment Rule, Seniority/Gradation List, and working strength reconciled with the previous DPC. Each is recorded with who, when and the figures confirmed; the submission gate refuses the proposal until all four are confirmed for the current revision. | **Rule 1.1:** confirm the vacancy setup against the setup approval letter, the recruitment rule and the gradation list, and the filled-post count against the previous DPC minutes. | ✅ Matches | The approval letter and gradation list themselves are not uploaded; the department confirms it checked them. |
| **Sanctioned Strength** | Taken from the post master (`sanctioned_strength`) and copied onto the proposal. Post mappings are date-effective and change through a new Government Order with a PDF. Working officers may never exceed sanctioned strength. This is checked at save, at import and at submission. | **Rule 1.1:** the sanctioned setup must be matched against the setup approval letter (स्वीकृति पत्र). No formula is given. | ⚠️ Partial | **Implementation gap.** The figure and its Government Order exist. Nothing records that it was matched against the setup approval letter. |
| **Working Officers — count** | The number of working officers is the number of rows in the uploaded list. It is never typed separately. The check is that rows add up to sanctioned strength and do not exceed it. | **Rule 4.4:** the number of officers working on the promotion post is to be **entered**, and it must match the uploaded list 100%. | ⚠️ Partial | **Implementation gap.** The system counts the list itself. There is no separately entered figure to reconcile against it. |
| **Working Officers — optional upload under 50** | The working officer list and the seniority list are always mandatory. | **Rule 1.1 (coder note):** for a previous DPC entered by Commission staff, uploading the final seniority list and the working officer details is **optional** if fewer than 50 employees are eligible. | ❓ Business Clarification Required | **Unresolved business decision** (BD-59). No "fewer than 50" exception exists. The figure 50 appears only as the committee-size limit. |
| **Cascade Vacancy** | Each DPC counts only its own promotion post. A vacancy type "Expected from promotion" exists, but it means an officer who came by promotion and is retiring or on deputation. It does not mean an incumbent being promoted to a higher post. | **Rule 1.2:** a vacancy caused by promoting an incumbent to a higher post may be counted only if either: <br>• the higher-post DPC order has been issued; or <br>• the higher-post proposal has reached the Commission and been validated. <br>An undertaking and the higher-post **DPC Proposal Number** must be recorded. | ❌ Missing | **Implementation gap.** Recorded in project decisions as BD-44 (cascade vacancy, not modelled). No undertaking or proposal-number field exists. |
| **Vacancy Period — end date** | The period is **1 January to 30 November** of the DPC year, set in configuration. A retirement date in December is refused on entry. | **Rule 1.5:** vacancies are counted up to **31 December** of the proposed year. | ⚠️ Partial | **Configuration difference** for the end date (a setting, not a feature). There is also an **implementation gap**: the December retirement refusal is a validation rule in code. Related pending decisions: BD-34, BD-35, BD-40. |
| **Vacancy Period — effective date** | The proposal date is set to the date the proposal is created. The recruitment rule in force is looked up on that date. | **Rule 1.5:** the DPC proposal is effective from the **first day** of the current year. | ⚠️ Partial | **Implementation gap.** The vacancy window follows the DPC year, which agrees with the document. The proposal's own effective date does not. |
| **Rounding Rules — post allocation** | Promotion posts = sanctioned × promotion %. Rounding is configurable (floor, ceil or round), and the default is **floor**. Direct recruitment always gets the remainder. Rounding is applied once for the whole post. | **Rule 1.3:** a decimal of .5 or more rounds up. If direct recruitment and promotion both come out at .5, drop the direct-recruitment .5 and add one post to promotion. | ⚠️ Partial | **Configuration difference.** With the setting changed to "round", the remainder method happens to give the tie to promotion, which matches the rule. Under the current default (floor) the result is the opposite. Pending decisions BD-33 and BD-41 are still recorded as open. Rule 1.3 reads as their answer. |
| **Previous DPC Validation** | *(Updated 15 Oct 2026.)* The working-strength confirmation names the previous DPC: one held in the portal (by proposal), one held offline (reference, date and minutes PDF), or a statement that none has been held (remarks required). There is still no Commission-staff route to enter a complete historical DPC. | **Rule 1.1:** an offline previous DPC is entered in full by Commission staff. **Rule 4.3:** previous DPC minutes are uploaded. | ⚠️ Partial | **Implementation gap** for full historical entry (BD-62). Minutes upload for an offline previous DPC now exists. |
| **Seniority Date** | The seniority list has serial numbers and officer details only. It has no "as on" date. | **Rule 1.6:** the final seniority list must be prepared as on **1 April of the preceding year**. | ❌ Missing | **Implementation gap.** |
| **Pre-2019 proposals** | The DPC year may be any year from 2000 onward. This limit only guards against typing mistakes. Nothing closes or refuses older proposals. | **Rule 1.4 (Hindi):** proposals from before 2019 are to be closed through "Completion" so they do not affect current calculations. **Coder note:** "Do not take proposal for 2019 in this software; a different module is to be designed for the same." | ❓ Business Clarification Required | **Unresolved business decision.** The Hindi says "before 2019"; the English says "for 2019". It is not clear whether 2019 itself is inside or outside this software. Either way the system has no year limit. |

---

## 5. Section 2 — Recruitment Rules

| Business Area | Current System | Latest Business Document | Status | Remarks |
|---|---|---|---|---|
| **Promotion Percentage — direct vs promotion** | Each recruitment rule and Pre-DPC holds a direct-recruitment % and a promotion %, which must total 100%. Promotion posts = sanctioned strength × promotion %. | Implied by Rule 1.3 (allocation between direct recruitment and promotion). | ✅ Matches | No conflict. Rounding is covered in Section 1. |
| **Promotion Percentage — multiple feeder cadres** | Each feeder cadre has its own share of the promotion quota, and the shares must total 100%. The share is **stored and shown, but not used** in the vacancy calculation. One DPC runs per feeder cadre, and each one sees the full promotion vacancy of the post. | **Rule 4.4:** capture the % of total available posts filled by promotion from multiple cadres. Sometimes a % of the calculated promotion posts is set aside for another cadre (example: 3% for Draughtsman → Assistant Engineer). **Each cadre is vertical:** if one cadre has no officers, its share is not given to another cadre. | ⚠️ Partial | **Implementation gap.** The share is captured, but vacancy is not split by cadre, so the vertical rule is not applied. |
| **Qualification** | Accepted qualifications are recorded per feeder cadre on the recruitment rule and Pre-DPC. The candidate check is **advisory by default** (setting `qualification_gate = advise`), because officers' qualifications are stored as free text. | **Rule 4.4:** when the working officer table is uploaded, ask each officer's qualification and **validate** it against Schedule 4 of the recruitment rule. | ⚠️ Partial | **Configuration difference** (the check can be switched to reject). There is also an **implementation gap**: free-text qualifications cannot be matched exactly. Related pending decision: BD-32. |
| **Experience** | Minimum experience (years plus unit) per feeder cadre, and qualifying service duration, are recorded and **enforced** in the candidate check. | **Rule 4.4:** fields "Experience Required on Post" and "Total Years of Required Experience". | ✅ Matches | The field labels differ from the document's wording. The undertaking about clauses 8–11 is covered in Section 5. |
| **Recruitment Rule Version** | Rules are versioned per post, with effective-from and effective-to dates. The current rule is the highest active version. Activating a new version does not end-date the old one. Approving a Pre-DPC creates a new active version. A proposal records the rule it used. | **Rule 4.4:** all entered and calculated data must strictly match some version of the same data. Capture the latest recruitment rule and take an undertaking that the proposal follows it. | ⚠️ Partial | **Implementation gap.** Versions exist and are linked to proposals. The undertaking is missing, and the old version is not end-dated automatically. |
| **Government Order** | Government Order number required; GO PDF optional (up to 10 MB). | Not specifically required. | ✅ Matches | The document is silent; no conflict. |
| **Notification Date** | No notification date field. Only GO number and effective dates are held. | **Rule 4.4:** capture the date of notification of the latest recruitment rule, plus an undertaking that "the latest recruitment rule was published on dd/mm/yyyy and this proposal is prepared as per it". | ❌ Missing | **Implementation gap.** |
| **Eligibility undertaking (clauses 8–11)** | None. | **Rule 4.4:** digital undertaking that each officer meets all recruitment-rule criteria, especially clauses 8 to 11. | ❌ Missing | **Implementation gap.** |

---

## 6. Section 3 — Reservation

| Business Area | Current System | Latest Business Document | Status | Remarks |
|---|---|---|---|---|
| **SC/ST/OBC** | Every DPC is reserved vertically. Each vacant post takes the reservation category of its row in the working officer chart. Ranked officers fill seats of their own category; the rest go to that category's waiting list. Reserved relaxations are switched off but flagged, and a flag blocks publication. The reservation policy and roster master tables exist but no calculation uses them. | **Rule 1.4 (Hindi):** "आरक्षण वर्ष 2019 से समाप्त हुआ" — reservation ended from 2019. Proposals before 2019 are to be closed so they do not affect current calculations. Apart from that, the document is silent on SC/ST/OBC. | ❓ Business Clarification Required | **Unresolved business decision.** If reservation in promotion has ended from 2019, the category-wise vacancies and lists may not apply to DPCs in this software. The document does not say this directly about SC/ST/OBC lists, so it needs confirmation. |
| **PH Reservation** | Not present. Categories are UR, SC, ST, OBC and EWS only. No PH flag, no PH history, no PH share. | **Rule 2.1:** <br>• 3% of the promotion quota is reserved for persons with disabilities; <br>• an officer can use it only **once** in service; <br>• keep a separate record of officers serving under the PH quota, and check whether the benefit was used before; <br>• calculate it like horizontal/female reservation, by dropping the decimal even at .9. | ❌ Missing | **Implementation gap.** |
| **Horizontal Reservation** | Not present. "Female" is recorded only as a committee-member attribute, not for candidates. | **Rule 2.1** mentions horizontal/female reservation only as the model for the PH calculation. | ❓ Business Clarification Required | **Unresolved business decision.** The document does not say whether female or other horizontal reservation applies to promotion itself. |
| **Sealed Cover** | Not present. Results are only EL, NE, SS, CIR or RJ. A Commission member can place a **manual hold** with free text. | **Rule 2.2:** if a departmental enquiry has started and a chargesheet is issued, or police have filed a chargesheet in court, the officer's result is kept in a **sealed cover**. | ❌ Missing | **Implementation gap.** Recorded in project decisions as BD-48 ("Not implemented"). |
| **Chargesheet** | Not present. Only a free-text note (max 255 characters, suggested codes such as `PUNISHMENT` or `C.CASE`) that the calculation never reads. | **Rule 2.2:** departmental or police chargesheet triggers the sealed cover. | ❌ Missing | **Implementation gap.** |
| **Departmental Enquiry** | Not present apart from the same free-text note. Show cause notices and penalties are not recorded. | **Rule 2.2:** departmental enquiry with chargesheet → sealed cover. **Only** a show cause notice with no penalty order → promotion order may be issued. | ❌ Missing | **Implementation gap.** Recorded in project decisions as BD-45 (recorded as a note, affects no status). |
| **Suspension** | Not present. | **Rule 2.2:** during suspension without a chargesheet, the officer is treated as fit. **Rule 3.2:** suspension is a valid reason for a missing CR, with suspension and reinstatement orders. | ❌ Missing | **Implementation gap.** |
| **Deputation — vacancy** | Each working officer can carry deputation from/to dates. Any overlap with the vacancy window creates a vacancy. The deputation date is **optional**, and a one-day overlap is enough. | **Rule 2.3:** if a post sanctioned for deputation stays vacant in the parent department for **more than one year**, promotion may be made against it. The date of going on deputation is **mandatory**. | ⚠️ Partial | **Implementation gap.** The date fields exist but are optional, and there is no "more than one year" test. Related pending decision: BD-43 (overlap vs containment). |
| **Deputation — seniority list** | The seniority list has no deputation or sanctioned-setup fields. | **Rule 2.3:** officers on deputation without a sanctioned setup are not to be included in the final seniority list. Promotion is still made against their post. | ❌ Missing | **Implementation gap.** |

---

## 7. Section 4 — Confidential Reports (CR)

| Business Area | Current System | Latest Business Document | Status | Remarks |
|---|---|---|---|---|
| **CR Upload** | No CR document is ever uploaded. CR is entered as one grade per year, through a dropdown on the evaluation chart or a "CR year" column in the Excel import. | **Rule 3.1 (coder note):** for each year, ask in order: <br>1. "Did the officer put up a CR? (Yes/No)" — if No, take the previous year's CR. <br>2. "Is there grading in the CR? (Yes/No)" — if No, take the previous year's CR **and** upload the ungraded CR. <br>3. If Yes, ask how many CRs that year (maximum 4). | ❌ Missing | **Implementation gap.** None of the three questions, and no CR upload. |
| **Multiple CRs** | Only one CR per officer per year. The database enforces this. | **Rule 3.1:** up to **4** CRs in a year. The system takes their **average mark** and drops any decimal. | ❌ Missing | **Implementation gap.** |
| **Reporter / Reviewer / Accepting Officer** | Not present. | **Rule 3.1:** for each CR, capture the designation and remark of the Reporter, the Reviewer and the Accepting Officer. The final remark is the Accepting Officer's; if absent, the Reviewer's; if absent, the Reporter's. | ❌ Missing | **Implementation gap.** |
| **Missing CR — reasons and documents** | A missing year can be justified with one reason from a master list of **7** (record not available, on deputation, department delay, court case, record lost, newly appointed, other) plus **one general PDF**, whatever the reason. A missing year with this justification makes the officer CIR. Without it, the officer fails the recruitment-rule check (NE by default). | **Rule 3.2:** the reason must be one of **three**, each with its own mandatory document: <br>1. NRC issued → upload NRC <br>2. long leave → upload leave approval <br>3. suspended → upload suspension **and** reinstatement orders | ⚠️ Partial | **Implementation gap.** The reason list is an editable master, but it holds different reasons, and no reason specifies its own document. The coder note says "out of 4" but lists three reasons. |
| **Missing CR — one of four missing** | Not handled as a separate case. | **Rule 3.1:** if three CRs were written in a year and one is missing, there is no established rule; the committee decides. | ❓ Business Clarification Required | **Unresolved business decision.** BD-56: what the average is divided by, and how the committee's decision is recorded. |
| **Missing CR — Class I to Class I** | Same treatment as every other class pair. A CR hold cannot be sent to circulation (BD-15). | **Rule 3.3:** in Class I to Class I merit-cum-seniority promotion, a missing CR is in some cases sent directly to circulation, and in others the previous CR is accepted. There is no clear rule. | ❓ Business Clarification Required | **Unresolved business decision.** BD-57, overlapping BD-15 and BD-49. |
| **NRC** | Not present as a concept. | **Rule 3.1 / 3.2:** NRC upload is compulsory when any one of Reporter, Reviewer or Accepting Officer has not marked the CR. NRC is also a valid reason for a missing CR. | ❌ Missing | **Implementation gap.** |
| **Carry Forward** | No carry-forward. The calculation takes the most recent **five graded** years and skips missing ones, reaching further back instead. Example: grades 2024 = क+, 2023 missing, 2022–2019 = ख. The system scores 2024 and 2022–2019. The document would score 2024, then 2024's grade again for 2023, then 2022–2020. The totals differ. | **Rule 3.1:** in place of a missing CR, the previous available CR is accepted (carried forward). | ❌ Missing | **Implementation gap.** There is also an **unresolved business decision**: the document does not say how many years in a row one CR can be carried forward (BD-54). |
| **CR Marking Scale — values** | Fixed: क+ = 4, क = 3, ख = 2, ग = 1, घ = 0. Five years plus a tribal-area bonus of 1 gives a maximum of 21. | **Rule 3.4:** क+ = 9, क = 7, ख = 5, ग = 3, घ = 1. | ⚠️ Partial | **Implementation gap.** A grade-to-mark mapping exists, with different values. The new scale is exactly *new = 2 × old + 1*, so the order of officers is the same under both. Averaging multiple CRs, however, gives marks with no grade name (e.g. 8). The system's checks compare grade names, so they cannot handle those marks. |
| **CR Marking Scale — master data** | The mapping is fixed in the configuration file. It cannot be changed through settings or a master screen; changing it means a software release. Each calculation run stores a copy of the rules it used, so past results are not affected by a change. | **Rule 3.4 (coder note):** any conversion must take effect through **master data**. | ❌ Missing | **Implementation gap.** |
| **CR Marking Scale — old records** | One scale applies to the whole calculation. | **Rule 3.5:** the old grading system applies "for old service records". | ❓ Business Clarification Required | **Unresolved business decision** (BD-50). This may mean each CR is scored on the scale of its own year, so one officer's five years could mix both scales. The system cannot express this today. |
| **Grade Thresholds** | Qualifying totals: I-I 15, II-I 13, II-II 11, all others 10. They can be changed through environment settings, not master data. | **Rule 3.5 (old system):** 20 ≥ I-I ≥ 15; 15 > I-II ≥ 13; 13 > II-II ≥ 11/10; all others 10. Intervals are to take effect through **master data**. No thresholds are given for the new 9/7/5/3/1 scale. | ❓ Business Clarification Required | **Unresolved business decision.** <br>• Old-system values agree with the system, but they are not master data (**implementation gap**). <br>• The document writes "I-II" where the system writes "II-I". <br>• "11/10" is not explained. <br>• Thresholds for the new scale are not stated (BD-51). <br>• Whether the tribal-area bonus stays at 1 on the new scale is not stated (BD-52). |
| **Disqualifying grades and Super Seat** | Three checks compare grade names: <br>• घ anywhere in the window disqualifies; <br>• ग as the latest CR blocks Class I to Class I; <br>• क+ in all five years gives Super Seat. | Not addressed directly. The averaging in Rule 3.1 produces marks with no grade name. | ❓ Business Clarification Required | **Unresolved business decision** (BD-53). When a year's mark is an average, it is not stated whether these checks apply to the averaged mark or to any single CR in that year. |
| **CR frequency** | Not modelled. | **Rule 3.1 (Hindi):** a CR may be written every three months, and is mandatory for service of more than 90 days. No coder instruction is attached. | ❌ Missing | **Implementation gap.** The document gives the rule but no instruction for how the software should use it. |
| **CR after retirement** | Not modelled. | A CR can be written only up to one month after retirement. The coder note says **"NA"**. | ✅ Matches | The coder note marks it as not applicable to the software. |
| **Digital Undertakings — channel** | None. | **Rule 3.1:** undertaking that the CR is signed through the proper channel, plus upload of the channel document. | ❌ Missing | **Implementation gap.** |
| **Duplicate CR Validation** | None. Only one grade per year can be stored, but nothing asks whether a second CR exists for the same year. | **Part 4:** with every CR upload, a digital undertaking that there is no duplicate CR for the same year. The reason given: a second CR with better remarks is sometimes obtained for the same year. | ❌ Missing | **Implementation gap.** |
| **Committee correction of CR** | During the sitting the Commission can correct a year's CR grade. Each correction needs a reason and is kept as an added record; earlier values are never overwritten. | Not addressed. | ✅ Matches | The document is silent. Note: once a year's value is an average or carried forward, it will no longer be a typed grade. |
| **Missing CR and loyalty — effect on result** | Fewer than five graded years → CIR (if each gap is justified with a PDF) or NE. Also an automatic hold at the sitting. CIR officers are not ranked or placed on any list. | Rule 3.1 (carry forward), 3.2 (reasons) and 3.3 (committee discretion) together. | ❌ Missing | **Implementation gap.** The system's handling rests on skipping years and generic justifications, not on carry-forward and the three fixed reasons. |

---

## 8. Section 5 — Documents

| Business Area | Current System | Latest Business Document | Status | Remarks |
|---|---|---|---|---|
| **IPR — upload** | IPR is a Yes/No flag per year. No document is uploaded. A missing year rejects the candidate (five continuous years needed) and holds the officer at the sitting. | **Rule 4.1:** every IPR must be uploaded. | ⚠️ Partial | **Implementation gap.** Whether a return was filed is recorded; the return itself is not. |
| **IPR — late submission** | No submission date is recorded. | **Rule 4.1:** IPR is due by 31 January of the following year. If it was filed later, the department decides whether to accept it. If accepted, the reason and the decision copy must be uploaded. | ❌ Missing | **Implementation gap.** |
| **IPR — duplicate check** | No increment date is recorded. | **Rule 4.1:** capture the date of next increment shown on each IPR, and use it to detect the same IPR uploaded for two years. | ❌ Missing | **Implementation gap.** |
| **Previous DPC Minutes** | No upload. DPC types are fresh, review and circulation only. | **Rule 4.3:** if no DPC was held in earlier years or periods, the Commission has the previous DPC minutes uploaded. A separate proposal is taken for each year. | ❌ Missing | **Implementation gap.** Each proposal already has a single DPC year, and past years can be filed. Nothing marks a proposal as retrospective or links consecutive years. |
| **Complaints** | Not present. | **Rule 4.4:** upload every complaint, representation or other document received about this DPC, with an undertaking that none of them affects the DPC. | ❌ Missing | **Implementation gap.** |
| **Representations** | Not present. The self-inspection notice tells officers to raise corrections with their department in person, and that the Commission will consider no representation. | **Rule 4.4:** upload every representation received from officers on the feeder post, with a summary and an undertaking that the DPC is not affected. | ❌ Missing | **Implementation gap.** |
| **Promotion Refusal** | Not present. | **Rule 4.4:** <br>• the working officer table has a field "Was the officer refused promotion on the same post?", default No; <br>• if Yes, upload the refusal letter and the DPC order; <br>• if the refusal relates to last year, mark the officer unfit; <br>• refusal after a promotion order: the department issues a cancellation order and presents the case in the next DPC. | ❌ Missing | **Implementation gap.** |
| **Date of birth and joining documents** | Only the dates are stored. | **Rule 4.4:** upload the order number and date of the documents for each officer's date of birth and joining, with an undertaking. | ❌ Missing | **Implementation gap.** |
| **Undertakings — original records** | None. | **Rule 4.4:** undertaking that the originals of all uploaded documents and entered data are in the officer's original (Mul) folder. | ❌ Missing | **Implementation gap.** |
| **Undertakings — overall** | **There are no undertakings, declarations or confirmations anywhere in the proposal flow.** The nearest items: <br>• a Yes/No "legal stay" field; <br>• an electronic signature on approval (a test provider at present); <br>• a reason with a PDF for a missing CR or loyalty year. | The document asks for at least ten: <br>• higher-post vacancy (1.2) <br>• CR signed through channel (3.1) <br>• no duplicate CR (Part 4) <br>• complaints and representations (4.4) <br>• date of birth / joining documents (4.4) <br>• original Mul folder (4.4) <br>• recruitment-rule clauses 8–11 (4.4) <br>• latest recruitment rule (4.4) <br>• checklist confirmations (1.1) | ❌ Missing | **Implementation gap.** |
| **Verification Workflow — each document** | Uploaded files record only the uploader and a fingerprint (hash). Nobody verifies individual documents. | **Rule 4.4:** every uploaded document must be verified at **two** levels. | ❌ Missing | **Implementation gap.** |
| **Verification Workflow — proposal** | The Authorised Officer prepares and submits; the Administrative Head approves with an electronic signature or returns with remarks. | Two-step verification is asked per document, not per proposal. | ⚠️ Partial | **Implementation gap.** There are two levels for the whole proposal, but not for each document. |
| **Data consistency across versions** | Proposal revisions are numbered. Each calculation run stores a copy of its rules and a fingerprint of the proposal. A calculation that no longer matches the data blocks result approval. | **Rule 4.4:** all entered and calculated data must strictly match some version of the same data. | ❌ Missing | **Implementation gap.** Versions and fingerprints exist for proposals and calculations. Nothing compares entered data against the uploaded source documents, because those documents are not uploaded. |
| **Retrospective DPC — one proposal per year** | One DPC year per proposal. | **Rule 4.3:** a joint proposal for several years would need separate software, so a separate proposal is taken for each year. | ❌ Missing | **Implementation gap** in the sense of the previous-minutes upload (above). One-year-per-proposal itself already agrees with the document. |

---

## 9. Section 6 — DPC Workflow

| Business Area | Current System | Latest Business Document | Status | Remarks |
|---|---|---|---|---|
| **Pre-DPC** | The department's Authorised Officer creates it and the Administrative Head approves or returns it. It holds: <br>• promotion post and DPC year <br>• Government Order and effective date <br>• direct and promotion % <br>• feeder cadres with share, qualifications, experience and duration <br>Approval creates a new recruitment rule version. | Not addressed as a stage. Rule 1.1's checklist is meant to be done before the DPC starts. | ✅ Matches | The document is silent on the Pre-DPC stage itself. The checklist gap is in Section 1. |
| **AH Approval** | The Administrative Head approves the Pre-DPC, the DPC proposal and the department side of a circulation, each with an electronic signature. Return needs remarks. There is no reject action. The signature provider is a test provider; the real provider is not connected. | Not addressed. | ✅ Matches | The document is silent. |
| **Final DPC** | Created from an approved Pre-DPC. Sections: committee, working officers and vacancies, seniority list, evaluation chart. Data comes in by upload. The proposal cannot be submitted until every section is complete. Statuses: draft, submitted, returned, approved, cancelled. A Review DPC copies an approved DPC into a new draft. | Rules 1.5, 1.6, 2.x, 3.x and 4.x set what the proposal must contain. | ⚠️ Partial | **Implementation gap.** The stage exists. Its content differs as listed in Sections 1 to 5 (effective date, seniority date, CR, documents, undertakings). |
| **Offline previous DPC entry** | Only a department's Authorised Officer can create a proposal. | **Rule 1.1:** a Commission staff account enters an offline previous DPC "in the same manner as a new DPC proposal". | ❌ Missing | **Implementation gap.** |
| **Chairman Assignment** | The Commission Chairman is held as a date-effective record and seated automatically on every DPC committee. | Not addressed. | ✅ Matches | The document is silent. |
| **DPC Assignment** | The Chairman (or the designated DPC Chairman) allots an approved DPC to a member of that DPC's committee, with a completion deadline. The allottee can accept or decline. Re-allotment keeps history. An allotment past its deadline is returned automatically every night. | Not addressed. | ✅ Matches | The document is silent. |
| **Scheduling** | The allottee schedules and reschedules the sitting within the deadline. A reason is required to reschedule, and history is kept. | Not addressed. | ⚠️ Partial | The document is silent on scheduling. Marked Partial only because the related notification requirement is not met (next row). |
| **Notifications** | • Every workflow step notifies the relevant department roles in-app, and by SMS and mail. <br>• The sitting schedule is sent to committee members and candidates. Candidates have no phone or email on record, so they get only the in-app message. <br>• Publication of the result is sent only to the department's Authorised Officer and Administrative Head. <br>• **SMS is a test sender that only writes to a log, and mail is written to a log.** No real message is delivered. | **Rule 4.3:** issue the DPC order online, and send its link by **SMS and mail to the officers and all DPC committee members**. | ⚠️ Partial | **Implementation gap.** The notification set-up exists. The DPC order message to officers and committee members does not, and SMS and mail delivery are not connected. |
| **DPC Order and seniority date** | No promotion order is produced; the candidate screen says the department issues it. The result records "promotion as on [year]", entered at approval. | **Rule 4.3:** seniority runs from the **date of the DPC order**. Issue the order online, with the order date clearly shown. | ❌ Missing | **Implementation gap.** |

---

## 10. Section 7 — Committee Process

| Business Area | Current System | Latest Business Document | Status | Remarks |
|---|---|---|---|---|
| **Committee Sitting** | The allottee opens the sitting. On the sitting day, members call each candidate and close their review. Each candidate is shown with year-wise CR, loyalty, property and tribal-area service, and the latest calculation. Attendance and quorum are not recorded. | Not addressed. | ✅ Matches | The document is silent. |
| **Chairman Role** | The Chairman role allots DPCs, designates the DPC Chairman and sees all proceedings and reports. The DPC Chairman of a given DPC can allot that DPC. | Not addressed. | ✅ Matches | The document is silent. |
| **Member Role** | Committee membership is checked against required categories: SC, ST, OBC, female, Bhar Sadhak Sachiv, Vibhag Sachiv. A seated member can view the sitting and call and close candidates. Members do not sign or consent individually. | **Rule 4.4 (Hindi):** the DPC minutes may be given with the consent (सहमति) of the officers in the zone of consideration. If even one disagrees, minutes are not given without rejecting the disagreement. | ❓ Business Clarification Required | **Unresolved business decision.** It is unclear whose consent is meant: the officers being considered, or the committee members. The system records neither. |
| **Evaluation Process** | For each officer, in this order: <br>1. recruitment-rule check (hierarchy, property return, experience, duration, CR and loyalty gaps) <br>2. CR score over five graded years <br>3. eligibility checks (घ, ग, qualifying total) <br>4. Super Seat <br>5. zone of consideration <br>6. ranking <br>7. placement on main and waiting lists by category. <br>Holds are found automatically for missing CR years and property returns. Corrections are kept as a history of added records. Each run keeps a copy of its rules. | Parts 2 and 3 of the document change the inputs: sealed cover, PH, CR averaging, carry forward and the new marking scale. | ⚠️ Partial | **Implementation gap.** The process exists. The rule differences are listed in Sections 3 and 4. |
| **Proceedings** | After approval, a Proceedings document and a Promotion List (main and waiting) are produced, signed and published. Publication is blocked while a reserved-category decision is pending. Department users have no screen to download these documents. | **Rule 4.4 (Hindi):** minutes are given only with consent, as in the Member Role row. | ❓ Business Clarification Required | **Unresolved business decision.** See the Member Role row. |
| **Recommendation** | The allottee approves the result, entering the "promotion as on" year. Officers on hold can later be sent to circulation, where the Commission consents or returns each one. CR-ground holds cannot be circulated (BD-15). | **Rule 3.3:** for Class I to Class I with a missing CR, some cases go directly to circulation. **Rule 4.3:** seniority from the date of the DPC order. | ❓ Business Clarification Required | **Unresolved business decision.** Rule 3.3 allows circulation of CR cases in some situations; the system does not (BD-15, BD-57). The DPC order gap is covered in Section 6. |
| **Review DPC** | An approved and concluded DPC can be reviewed. The Review copies it, runs the same flow on the original rules, and on approval marks the earlier result as superseded. | Not addressed. | ✅ Matches | The document is silent. |

---

## 11. Section 8 — Reports

The latest business document has no reporting requirements. The rows below describe what exists today so both sides can see it.

| Business Area | Current System | Latest Business Document | Status | Remarks |
|---|---|---|---|---|
| **DPC pipeline report** | Counts by stage: draft, submitted, returned, forwarded, allotted, sitting opened, result approved, cancelled. Also counts by year, outcomes (promoted, waiting, held, not eligible), reviews and circulations, and a department view. Commission users see all departments; others see their own. CSV export covers the pipeline only. | Not addressed. | ✅ Matches | The document is silent. |
| **Calculation reports** | Calculation history, run detail and a per-officer explanation, on screen only. | Not addressed. | ✅ Matches | The document is silent. |
| **Result documents** | Proceedings and Promotion List; Circulation Note and Revised Promotion List. Produced as signed HTML. | Not addressed (Rule 4.3's DPC order is covered in Section 6). | ✅ Matches | The document is silent. |
| **Audit and master data** | Audit trail with CSV export. Master data CSV export, template and import. | Not addressed. | ✅ Matches | The document is silent. |
| **Export formats** | CSV and HTML only. No Excel or PDF export. | Not addressed. | ✅ Matches | The document is silent. |

---

## 12. Points in the latest document that read inconsistently

These are noted so they can be resolved when the document is discussed. They are reported as found, not interpreted.

1. **Rule 1.4:** the Hindi says proposals **before** 2019 are closed; the coder note says "do not take proposal **for** 2019".
2. **Rule 3.2:** the coder note says the reason is "out of **4**", but lists **three**.
3. **Rule 3.5:** the document writes "श्रेणी I-II"; the system and earlier documents use "II-I". The lower bound "11/10" for II-II is not explained.
4. **Rule 3.4 vs 3.5:** a new scale is given (9/7/5/3/1), but thresholds are given only for the old scale.
5. **Rule 4.4 (consent):** it is not clear whose consent is needed before minutes are issued.
6. **Rule 1.4 vs Rule 2.1:** rule 1.4 says reservation ended from 2019. Rule 2.1 still sets PH reservation in promotion. It is not stated whether "reservation" in rule 1.4 means SC/ST/OBC only.

## 13. Pending business decisions referred to

Taken from the project's existing decision list. They are listed here only because the rows above refer to them.

| BD | Subject |
|---|---|
| BD-15 | CR-ground holds cannot go to circulation |
| BD-32 | Qualification / recruitment-rule failure gives NE or RJ |
| BD-33, BD-41, BD-58 | Rounding of promotion posts |
| BD-34, BD-40 | Vacancy period |
| BD-35 | December retirements |
| BD-43 | Deputation overlap vs full containment |
| BD-44 | Cascade vacancy |
| BD-45 | Departmental enquiry recorded as a note only |
| BD-48 | Sealed cover not implemented |
| BD-49, BD-57 | Missing CR in Class I to Class I |
| BD-50 | CR scale per CR year or per calculation |
| BD-51 | Thresholds on the new scale |
| BD-52 | Tribal-area bonus on the new scale |
| BD-53 | Disqualifying checks after averaging |
| BD-54 | Limit on carry forward |
| BD-55 | Excel import for multi-CR years |
| BD-56 | One of four CRs missing |

---

*This document compares only. No code, configuration, migration or test was changed to produce it.*
