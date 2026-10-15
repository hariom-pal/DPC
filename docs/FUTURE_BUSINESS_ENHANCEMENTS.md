# Future Business Enhancements

**e-DPC Portal · Chhattisgarh Public Service Commission**
**Opened:** 2026-09-05

---

## What this file is, and what it is not

This file records business workflows the Commission has described for
**implementation after a demonstration**, so the understanding is preserved
while the build is frozen.

**An entry is not implemented unless its own banner says so.** Each carries a
status banner as its first block, and the default is
*Future Business Enhancement – Not Implemented* — nothing here has been built on
account of being written down. Where the business later rules that a part should
be built, the entry's banner and its comparison table are marked and the rest is
left standing, so whoever picks it up can see which half remains.

Current status: **FBE-001 partly implemented** (2026-09-05) · **FBE-002 not
implemented**.

**Relationship to the other registers.**

| File | Holds |
|---|---|
| `docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` | construction-phase questions `BD-2` … `BD-49` |
| `docs/PENDING_BUSINESS_QUESTIONS.md` | UAT questions `UAT-Q-nnn` — things the business must **decide** |
| **this file** | `FBE-nnn` — things the business has **already decided** and wants built later |

An entry moves here when the business has settled *what it wants*, so it is no
longer a question. It stays here until it is scheduled, at which point it
becomes a change-register item.

Entries are **appended, never overwritten**.

---

# FBE-001 · DPC creation, Chairman assignment and per-DPC committee composition

> ## Partly implemented on 2026-09-05 — the remainder is Not Implemented
>
> **Recorded:** 2026-09-05 · **Source:** business clarification, 2026-09-05
> **Revised:** 2026-09-05, second clarification the same day
> **Status:** points 4, 6, 7 and 8 **built** · point 3 **withdrawn 2026-09-06**
> (the current behaviour is the approved workflow) · point 9 **not built**
>
> A second clarification the same day settled the allotment half of this entry and
> it was implemented. The comparison table in §2 is kept and marked, because the
> half that remains is the larger half and whoever picks it up needs to see which
> is which.

## 1. The business workflow as described

1. The **Authorized Officer creates the Pre-DPC.**

2. The **Administrative Head reviews and approves the Pre-DPC.**

3. **After that approval, the system creates the DPC.** Creation is an
   automatic consequence of approval, not a separate act by the department.

4. **Every newly created DPC is automatically assigned to the default CGPSC
   Chairman.**

5. The **CGPSC Chairman does not necessarily conduct every DPC personally.**
   Instead, for each DPC the Chairman decides:
   - which committee will conduct that DPC;
   - the DPC schedule — date and time;
   - which members will participate;
   - who will act as the Chairman **for that particular DPC**.

6. For each DPC the CGPSC Chairman may either:

   - **Option A** — remain the Chairman for that DPC himself; **or**
   - **Option B** — nominate any one of the selected Committee Members as the
     Chairman for that specific DPC.

7. Every DPC Committee will therefore contain:

   - **exactly one DPC Chairman**, and
   - **one or more Committee Members**.

   **The DPC Chairman must also be one of the Committee Members.**

8. **Committee membership is valid only for that DPC.** No permanent Committee
   Member role and no permanent Committee Chairman role will ever be created.

9. During the DPC session:

   - the DPC Chairman joins the meeting;
   - all assigned Committee Members join the meeting;
   - every assigned member can view the candidate;
   - every assigned member can view the complete DPC record;
   - every assigned member participates in the evaluation;
   - the DPC Chairman **only presides over that DPC**.

## 2. What the portal does today, for comparison

Recorded so that whoever implements the rest of FBE-001 can see precisely which
parts are new and which already exist. Verified against the running application on
2026-09-05, **after** the changes of that afternoon.

| # | The described workflow | Today | Status |
|---|---|---|---|
| 1 | AO creates the Pre-DPC | `/pre-dpc/create`, `authorized_officer` only | ✅ as described |
| 2 | AH approves the Pre-DPC | Approval also signs the order and projects Recruitment Rule v1 into the master | ✅ as described |
| 3 | The system creates the DPC after approval | The **Authorized Officer** raises the DPC from the approved Pre-DPC at `/dpc-proposals/create`. Approval makes the order *raisable*; it does not raise it | ⛔ **WITHDRAWN 2026-09-06** — see the note below. The current behaviour **is** the approved workflow |
| 4 | Every approved DPC is routed to the default CGPSC Chairman | Every approved and **forwarded** DPC appears on the CGPSC Chairman's allotment queue and is theirs to act on. The serving Chairman is also seated automatically on every committee | ✅ **built 2026-09-05** |
| 5 | The Chairman decides the committee, the schedule, the members and who chairs | The Chairman decides the **allottee**, the **DPC Chairman** and the **completion deadline**, in one act, on the allotment form. The **committee is still composed by the Authorized Officer** while the proposal is a draft, and the **allottee — not the Chairman — sets the date and time**, within the Chairman's deadline | ◐ **partly built** — who presides and by when: built. Composing the committee and fixing the date: still the department's and the allottee's |
| 6 | Option A — the Chairman presides himself; Option B — he nominates a member | The DPC Chairman list on the allotment form is that DPC's own committee, and the Chairman's own seat is in it — so Option A is that option rather than a special case | ✅ **built 2026-09-05** |
| 7 | Exactly one DPC Chairman; the Chairman is also a Committee Member | Exactly one row per proposal carries `is_dpc_chairman`, and it can only be a serving member of that committee. Size rules come from the Committee Configuration master — today, 4 to 7 including the Chairman | ✅ **built** — see the open point below on the minimum |
| 8 | Membership is valid only for that DPC; no permanent role | `DpcCommitteeService` calls `assignRole()` on no path, and designating a DPC Chairman writes a column on a membership. Pinned by `CommitteeMembershipTest` | ✅ as described |
| 9 | All assigned members join, see the candidate and the whole DPC record, and evaluate; the Chairman presides | The DPC is conducted by **one accountable allottee**. A Member sees only DPCs allotted to them; the Chairman sees all of them, but *reading is not conducting*. There is **no multi-member sitting**: no per-member access by virtue of committee membership, no per-member evaluation input, and no distinction at the sitting between presiding and conducting | ❌ **not built** — the largest remaining item |

### §3 withdrawn — 2026-09-06

The business alignment of 2026-09-06 states the approved workflow in full:

> *AO → Creates Pre-DPC → AH reviews and approves the Pre-DPC → **Only after
> approval, AO is allowed to create the Final DPC.** The Final DPC is created from
> the approved Pre-DPC.*

That is exactly what the portal does, and has always done. §3 of this entry —
"the system creates the DPC" — was recorded from the earlier clarification of
2026-09-05 and is **withdrawn**: it described automatic creation that the business
has now confirmed it does not want. `raisablePreDpcs()` offers only orders that are
`activeApproved()`, and `SaveDpcProposalRequest` re-validates the same three
conditions, so an unapproved order cannot be raised from by any route.

**Verified live on 2026-09-06:** before approval the create screen offered no order;
after approval it offered `PDPC-2026-SE-0001`.

**What "routing" means, and it is also built.** The same alignment asks that once
the Final DPC is approved the system routes it to the default CGPSC Chairman with no
manual step. Approval sets `forwarded_at`, and the Chairman's allotment queue is
filtered on `status = approved AND forwarded_at IS NOT NULL` — so the DPC appears
there by itself, unasked. Nobody routes it.

### What was built on 2026-09-05, precisely

- **Allotment carries three decisions.** `AllotmentService::allot()` takes the
  allottee, the presiding committee member and the deadline, and writes all three
  in one transaction.
- **The CGPSC Chairman may always allot.** The earlier rule — only the designated
  DPC Chairman — is withdrawn; the designated DPC Chairman may still allot too.
- **The allottee and the DPC Chairman may differ or coincide.** Nothing couples
  them.
- **The meeting date is bounded by the deadline** at the form and at the service,
  on scheduling and on rescheduling.
- **A lapsed deadline returns the DPC automatically**, and the return is now
  scheduled and visible on the allotment history.
- **Re-allotting to the same member is permitted** when the deadline or the
  presiding member changes — which is how an extension is granted.

## 3. What this would touch when it is built

Listed so the size of the change is visible, not as a design.

| Area | Why it is affected |
|---|---|
| Pre-DPC approval | would gain the automatic creation of the DPC |
| DPC creation | ceases to be a department action; the `/dpc-proposals/create` screen and the "raisable Pre-DPCs" list lose their purpose |
| Committee composition | moves from the Authorized Officer to the CGPSC Chairman, and from the draft stage to after creation |
| Scheduling | moves from the allottee to the CGPSC Chairman |
| Allotment | the present allot / re-allot / accept model is replaced by "the Chairman assigns a committee", and the completion-deadline and re-allotment behaviour would need re-deciding |
| Proceeding authorisation | today keyed to *the allottee*; would become *any member of this DPC's committee*, with the DPC Chairman presiding |
| Evaluation during the sitting | today one actor records corrections; would become several participants |
| The workflow ledger | `dpc_cgpsc` and `dpc_proceeding` transition roles are written for a single allottee |
| Notifications | recipients become the committee, not one member |
| The audit trail | must be able to say which member did what within one sitting |

## 4. Open points the business will need to settle before the rest is built

Recorded as points, **not** as `UAT-Q` questions, because they arise only if and
when FBE-001 is scheduled.

1. **Does automatic DPC creation remove the department's ability to choose the
   DPC Type** (Fresh / Review / Circulation) and the proposal date, which are
   entered today at creation?
2. **Who fills the DPC's five sections** — committee, working officers,
   seniority list, evaluation chart — if the DPC now exists from the moment the
   Pre-DPC is approved, before the department has prepared any of them?
3. **What replaces the completion deadline and re-allotment**, which today
   return an overdue DPC to the Chairman?
4. **Is the minimum committee size still 3 members besides the Chairman**, or
   does "one or more Committee Members" replace the Committee Configuration
   master's bounds?
5. **What does "participates in the evaluation" mean concretely** — does each
   member record their own marks, or does the committee record one agreed set?
   The answer decides whether the evaluation tables gain a per-member dimension.
6. **Does the DPC Chairman's approval alone conclude the sitting**, or is a
   quorum or a majority of members required? The Committee Configuration master
   already carries `quorum`, `voting_method`, `majority_type` and
   `tie_break_rule`, and today only the first is consulted.
7. **Is the "default CGPSC Chairman" always the serving Chairman on record**, or
   a separately configured default that may differ?

## 5. Explicit statement of scope

- **Points 4, 6 and 7 were implemented on 2026-09-05** under a second business
  clarification the same day, and are described in
  `docs/demo/CURRENT_DEMO_FLOW.md` Steps 15 and 16.
- **Points 3 and 9 remain unbuilt.** No code, schema, API, screen, workflow rule
  or validation exists for either.
- **No database schema was changed** by the 2026-09-05 work.

---

# FBE-002 · Candidate Self-Inspection by mobile number and OTP

> ## Future Business Enhancement – Not Implemented
>
> **Recorded:** 2026-09-05 · **Source:** business clarification, 2026-09-05, §8
> **Status:** Recorded · not scheduled · not built
> **Instruction was explicit: "Do NOT implement this. Document only."**

## 1. The business workflow as described

1. The candidate receives an **SMS and an e-mail** — the sitting announcement.
2. The candidate **logs in using a Mobile Number and an OTP**.
3. The candidate performs **Self-Inspection before the scheduled DPC**.

## 2. What the portal does today, for comparison

| # | The described workflow | Today |
|---|---|---|
| 1 | The candidate receives SMS + e-mail | **Partly.** The sitting announcement is built and sent — but **a candidate has no mobile number and no e-mail address to send it to**, so they receive the in-app copy only. A Seniority List records service particulars; the ten-field officer snapshot has no contact column at all |
| 2 | Login by mobile + OTP | **Different.** The login box already accepts a ten-digit string as a mobile number, so the *identifier* half exists. What does not exist is **OTP as a login factor for a candidate**: OTP is used at registration and for unverified accounts, and a candidate has no channel to receive one on. A candidate signs in by **employee number and a password** the Authorized Officer reissues to them |
| 3 | Self-Inspection before the sitting | **Already built, and already available without any login.** `/self-inspection` matches an officer on name + date of posting on the promotion-to post + date of birth, opens their own row alone, and closes once the sitting has approved its result. `DPC_SELF_INSPECTION_AUTH_DRIVER` already names `otp` as one of three drivers, and selecting it today returns a structured *Pending Business Decision (BD-19)* rather than pretending a channel exists |

## 3. The one thing that blocks all three

**There is no contact channel on a candidate record.** Every part of this entry
follows from that single gap, and none of it can be built until it is closed:

- the SMS and the e-mail have nowhere to go;
- an OTP has nowhere to be delivered;
- and `DPC_SELF_INSPECTION_AUTH_DRIVER=otp` refuses for exactly that reason.

## 4. Open points the business will need to settle

1. **Where does a candidate's mobile number come from?** Adding a column to the
   Seniority List template changes what every department uploads. Taking it from
   an HRMS integration makes candidate notification depend on `FEATURE_HRMS`.
   Collecting it from the officer needs a screen they cannot reach without the
   number.
2. **Is the mobile number evidence or contact detail?** The ten-field snapshot is
   deliberately a point-in-time service record — a contact number changes and is
   not part of what the Commission decided on. It may belong beside the snapshot
   rather than in it.
3. **Does OTP login replace the employee-number password, or sit beside it?**
   BD-19 already frames this as a choice between three drivers.
4. **What happens to a candidate whose number is not on record?** Self-Inspection
   exists precisely so an officer with no account can see their data, and must
   keep working for them.

## 5. Explicit statement of scope

- **Nothing was implemented for FBE-002.** No code, schema, API, screen, workflow
  rule or validation.
- The **existing Self-Inspection facility is unchanged** and remains the route a
  candidate uses today, with no account and no OTP.

---

*Appended entries follow below. Nothing above this line is edited once
recorded, except to mark an entry as built.*
