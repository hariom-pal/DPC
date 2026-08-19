# UAT Checklist

For business users testing the ODPC Portal. **One scenario, one expected outcome.**

**Created 2026-08-13 by architect directive.**

---

## Before you start

**Nothing here needs a technical explanation.** Each row is something you do and what should happen. If what happens differs from the "Expected outcome" column, that is a finding — note the scenario number and what you saw instead.

**What is deliberately switched off during UAT:**

| | Why |
|---|---|
| SMS and e-mail delivery | No government gateway is connected yet. Messages are recorded but not sent. **This is expected, not a fault.** |
| HRMS lookup | No HRMS connection exists. All officer details are typed in or uploaded from Excel. |
| Real digital signature | CSC DigiSign is not connected. Approvals are signed by a stand-in so the workflow can be demonstrated end to end. **The live system will refuse to approve without a real signature.** |

**Two rules that are not yet settled by CGPSC** and will show as warnings rather than results:

- Whether a recorded **PUNISHMENT** disqualifies an officer (scenario 6.4).
- Whether the **reserved-category relaxation** applies automatically (scenario 6.5).

Both appear on screen as "awaiting a business decision". That is correct behaviour: the system applies the printed rule and flags the case rather than guessing.

---

## 1. Registration and access

| # | Scenario | Expected outcome |
|---|---|---|
| 1.1 | A department registers on the portal with its details, and the names of its Administrative Head and Authorized Officer | Both accounts are created; credentials are issued to the registered mobile and e-mail |
| 1.2 | Log in with the issued password for the first time | You are required to set your own password before anything else |
| 1.3 | Enter a wrong password five times in a minute | You are locked out for a minute with a clear message |
| 1.4 | Log in as an Authorized Officer and try to open a Commission screen | Access is refused |
| 1.5 | Log in as one department and try to open another department's proposal | Access is refused |

## 2. Pre-DPC posts

| # | Scenario | Expected outcome |
|---|---|---|
| 2.1 | Authorized Officer enters the department's posts, pay scales and feeder cadres | Saved as a draft; nothing is visible to the Commission yet |
| 2.2 | Submit for approval without the Government Order number | Submission is refused, naming what is missing |
| 2.3 | Administrative Head approves | The posts become available for use in DPC proposals |
| 2.4 | Administrative Head returns it with remarks instead | It goes back to the officer with the remarks shown |
| 2.5 | Correct an already-approved Pre-DPC | A **new version** is created; the approved one is untouched |

## 3. Preparing a DPC proposal

| # | Scenario | Expected outcome |
|---|---|---|
| 3.1 | Create a proposal choosing post-from and post-to | Draft created; a readiness checklist shows what is still outstanding |
| 3.2 | Choose the same post for both from and to | Refused |
| 3.3 | Name the DPC Committee with one Chairman and members | Saved; the checklist marks the committee complete |
| 3.4 | Name two Chairmen | Refused — exactly one Chairman is required |
| 3.5 | Enter sanctioned post count and the vacancy breakdown by category | Saved |
| 3.6 | Enter the seniority list of feeder-post officers | Saved |
| 3.7 | Enter the evaluation chart with year-wise CR, loyalty and property | Saved |
| 3.8 | Try to submit before the chart is entered | **Refused, naming exactly which sections are incomplete** |
| 3.9 | Complete every section and submit | Accepted and sent to the Administrative Head |

## 4. Uploading from Excel

| # | Scenario | Expected outcome |
|---|---|---|
| 4.1 | Upload a correct seniority-list spreadsheet | A **preview** appears. **Nothing is saved yet** |
| 4.2 | Review the preview and commit | The officers appear in the seniority list |
| 4.3 | Upload a file where one row has a wrong category code | Preview shows that row rejected with the reason; the good rows remain committable |
| 4.4 | Download the error report for a file with rejections | A spreadsheet lists each rejected row, its line number, and why |
| 4.5 | Commit a file that has some rejected rows | Only the valid rows are imported; the count is stated |
| 4.6 | Upload the same file a second time | A warning says this exact file has been uploaded before |
| 4.7 | Upload a file that is not a spreadsheet | Refused |
| 4.8 | Commit the same upload twice | The second attempt is refused |
| 4.9 | Check a date entered as `01-02-2012` | Read as **1 February**, not 2 January |

## 5. Approval by the Administrative Head

| # | Scenario | Expected outcome |
|---|---|---|
| 5.1 | Open a submitted proposal as Administrative Head | An invitation to review it section by section |
| 5.2 | Walk through all five review screens | Posts, Committee, Officers, Seniority, Tabulation Chart — in that order |
| 5.3 | Look for an Approve button on the first four screens | There is none. Approval appears only at the end |
| 5.4 | Approve on the final screen | Approved and signed **once**, a DPC ID is generated, and it is forwarded to the Commission |
| 5.5 | Return it with remarks instead | It goes back to the officer, who can correct and resubmit |
| 5.6 | Try to edit any section after approval | Refused — an approved proposal cannot be changed |
| 5.7 | Open the signed document | It contains **all five sections**, not just a summary |

## 6. Eligibility calculation

| # | Scenario | Expected outcome |
|---|---|---|
| 6.1 | Press CALCULATE on a proposal with an evaluation chart | Main List and Waiting List are produced |
| 6.2 | Open any officer's result | A plain-language explanation: which CR years were used, the marks, the total, the requirement, and the outcome |
| 6.3 | Check an officer refused promotion | **Every** ground for refusal is listed, not just one |
| 6.4 | Check an officer whose Note says PUNISHMENT | They currently pass if their marks qualify. **Flagged as awaiting a business decision** — confirm whether this is correct |
| 6.5 | Check a reserved-category officer scoring between 10 and their threshold | Not eligible, and the calculation is marked **provisional** with the case listed |
| 6.6 | Press CALCULATE again | A **new** calculation is created. The earlier one is still there, unchanged |
| 6.7 | Open the calculation history | Every calculation ever run is listed |
| 6.8 | Look for a way to edit a calculation result | There is none, for any user |
| 6.9 | Check an officer with fewer than five CR years | Flagged as an incomplete record rather than simply failed |

## 7. Commission — allotment

| # | Scenario | Expected outcome |
|---|---|---|
| 7.1 | Log in as Chairman | Proposals forwarded by departments are listed |
| 7.2 | Check whether a proposal not yet approved by its department appears | It does not |
| 7.3 | Allot a DPC to a Member, with a default DPC date | Allotted; the Allotment Report shows the member, status and date |
| 7.4 | Allot to yourself as Chairman | Allowed |
| 7.5 | Leave the default DPC date blank | Allowed — it is optional |
| 7.6 | Re-allot to a different Member without giving a reason | Refused — a reason is required |
| 7.7 | Re-allot with a reason | Re-allotted; **the previous member is shown struck through beside the new one** |
| 7.8 | Open the allotment history | Every allotment and re-allotment, in order, with reasons |
| 7.9 | Log in as a Member and try to allot | Refused — allotment is the Chairman's |

## 8. Commission — acceptance and scheduling

| # | Scenario | Expected outcome |
|---|---|---|
| 8.1 | Log in as the allotted Member | Only the DPCs allotted to you are listed |
| 8.2 | Log in as a different Member | The other member's DPC is not visible |
| 8.3 | Accept the DPC | Marked accepted |
| 8.4 | Try to set a date before accepting | Refused |
| 8.5 | Set the DPC date and time | Scheduled; the Scheduling Report shows DONE with the date and time |
| 8.6 | Set a date earlier than the Chairman's default date | Allowed — the default is guidance, not a restriction |
| 8.7 | Re-schedule without a reason | Refused |
| 8.8 | Re-schedule with a reason | **The previous date and time are shown struck through beside the new** |
| 8.9 | Open the scheduling history | Every acceptance, scheduling and re-scheduling, in order, with reasons |
| 8.10 | Try to accept a DPC allotted to another Member | Refused |

## 9. Records and trust

| # | Scenario | Expected outcome |
|---|---|---|
| 9.1 | Open a proposal's workflow history | Every step, who did it, and when |
| 9.2 | Change a user's name, then re-open an old history | The history still shows the name of the person **as it was when they acted** |
| 9.3 | Ask an administrator to alter a completed calculation | Not possible for any role |
| 9.4 | Ask an administrator to delete an allotment or a schedule | Not possible for any role |
| 9.5 | Look at any web address for a calculation or allotment | Shows a long reference code, never a simple sequential number |
| 9.6 | Log in as a CGPSC Admin and open Integration Status | Every integration, whether it is on, and what happens while it is off |

---

## Recording a finding

For anything that does not match, note:

1. **Scenario number** (e.g. 8.7)
2. **What you did** — the exact values entered
3. **What you expected**
4. **What actually happened** — the on-screen message, if any
5. **Who you were logged in as**

Point 5 matters more than it looks: most rules in this system depend on both your role and whether the record is yours.
