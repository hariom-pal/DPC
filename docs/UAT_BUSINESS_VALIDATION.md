# UAT Business Validation

**Nine questions for the Commission. One of them — Question 6 — is the only thing holding up completion of a finished milestone, and is the one to answer first.**

**Created 2026-08-14 by architect directive.** Question 5 added 2026-08-14. **Questions 6 to 9 added 2026-08-18**, covering the Circulation facility (slides 45–48).

---

## Who this is for

This document is for **CGPSC officials and department officers**, not engineers. It contains no technical detail and asks for no technical decision.

The system is built and tested. Every screen in the Commission's own presentation works. What is *not* settled is a small number of **promotion rules** — points where the presentation, the conventional DPC rules, and the worked example do not agree, or simply do not say. The software has deliberately **not guessed** at any of them.

Each section below states one question, shows the actual case that raises it, explains what the system does today, and lists the options.

**Questions 1 to 4 are about publishing a promotion list.** Answering them is what allows a list to be published rather than marked provisional.

**Question 5 is different, and the difference matters.** It does not block anything from being built or published. It asks what a *correction* to an already-published promotion means for an officer who was promoted under it — and the answer is needed **before the Review correction facility is switched on in live use**, not before any development finishes. Nothing is waiting on it today.

**Questions 6 to 9 concern the Circulation facility** — how a promotion that was reserved for a missing record is finally settled (slides 45–48). **Question 6 is the most urgent item on this page.** The facility is finished and tested; it is the only piece of work in the project that cannot be declared complete, and the reason is this single unanswered rule. Questions 7 and 8 affect officers whose promotions are reserved today; Question 9 is a policy point with nothing waiting on it.

**None of these nine is a question about software.** We are not asking you to approve a design, a screen or a milestone. Every one is a promotion rule or a service-law consequence that only the Commission can settle.

---

## How the system behaves while a question is open

Three things happen, every time, and they are worth knowing before UAT begins:

1. **The printed rule is applied.** Where a rule is written down, the system follows it exactly.
2. **Every affected case is flagged.** A calculation that met a situation an unsettled rule would decide is marked **provisional** on screen, and the affected officers are listed.
3. **A provisional result cannot be published.** The Commission can see it, print it and discuss it. It cannot be released to the department until the rule is confirmed.

**A "provisional" result is not a fault.** It is the system declining to guess. Please do not raise it as a defect during UAT.

---

## Question 1 — Reserved category relaxation *(BD-2)*

**Severity: highest. This decides who is eligible for promotion.**

### The rule as printed

The conventional DPC rules say that for Class-I to Class-I promotion an officer needs **15 marks or more**, with a note:

> "For the reserved category this may be relaxed to 10 marks, if a sufficient number of reserved-category officers are not obtained even after the zone of consideration is widened."

### What is not stated

**"May be relaxed" — by whom, and when?**

- Does the system apply the relaxation **automatically** whenever a reserved category is short of officers?
- Or is it a **decision the DPC committee takes**, case by case, and records?

The words *"may be"* read as discretion. But nothing says who exercises it, or whether it must be recorded.

### What happens today

The system does **not** relax anything. An officer scoring between 10 and 15 in a reserved category is marked **not eligible**, and the calculation is flagged **provisional** with those officers named.

### What we need you to decide

| | Option |
|---|---|
| **A** | **Automatic.** The system relaxes to 10 whenever a reserved category has fewer eligible officers than vacancies, after widening the zone |
| **B** | **Committee discretion.** The system flags the case; the committee decides at the sitting and records the reason |
| **C** | Something else — please describe |

**If B, one follow-up:** should the committee's decision be recorded against each officer individually, or once for the whole category?

---

## Question 2 — Does relaxation apply beyond Class-I to Class-I? *(BD-3)*

**Severity: highest. This decides who is eligible in every other kind of promotion.**

### The rule as printed

The qualifying marks are stated per class pair:

| Promotion | Marks required |
|---|---|
| Class-I → Class-I | 15 |
| Class-II → Class-I | 13 |
| Class-II → Class-II | 11 |
| All others | 10 |

The relaxation note — "may be relaxed to 10 for the reserved category" — appears **beside the Class-I → Class-I line only**.

### What is not stated

Is the relaxation:

- **Specific to Class-I → Class-I**, because it is the only pair with a threshold high enough to relax? or
- **General**, applying to every pair, with 10 as a universal floor?

Note that for "all others" the requirement is *already* 10, so relaxation there would mean nothing. The question really concerns **Class-II → Class-I (13)** and **Class-II → Class-II (11)**.

### What happens today

No relaxation is applied in any class pair. Cases are flagged.

### What we need you to decide

| | Option |
|---|---|
| **A** | Class-I → Class-I only |
| **B** | Every class pair, with 10 as the floor |
| **C** | Specific pairs — please list |

---

## Question 3 — Which waiting list, when a category has no vacancy? *(BD-9)*

**Severity: high. This decides which list an officer's name appears on in a published document.**

### The case, from your own example

Slide 34 of the Commission's presentation shows a worked DPC with **two vacancies: one Unreserved (UR) and one Scheduled Tribe (ST)**. The final result reads:

| | Officer | Category | Marks | Result shown |
|---|---|---|---|---|
| Main list | US1 | UR | 15 | `UR` |
| Main list | US8 | ST | 20 | `ST SS CIR` |
| Waiting list | US3 | ST | 15 | `ST_W` |
| Waiting list | **US4** | **SC** | 15 | **`UR_W`** |

US3 is ST, the ST seat is already taken by US8, so US3 waits on the **ST** list. That is straightforward.

**US4 is Scheduled Caste — and there is no SC vacancy in this DPC at all.** Your example places US4 on the **Unreserved** waiting list (`UR_W`).

### What is not stated

The rule that produces `UR_W` — *an officer whose own category has no vacancy waits against the unreserved list* — **does not appear anywhere in either presentation**. It is visible only by reading the expected result on slide 34.

### What happens today

The system places US4 on **`SC_W`** — a waiting list for their own category, even though that category has no seat.

**This is the one point where the system's output differs from your worked example on who appears where.** Everything else on slide 34 — US1, US3 and US8, including the marks, the Super Seat and the held promotion — is reproduced exactly.

### What we need you to decide

| | Option |
|---|---|
| **A** | An officer whose category has no vacancy waits on the **unreserved** list (as slide 34 shows) |
| **B** | They wait on **their own category's** list, even with no seat (what the system does today) |
| **C** | They do **not** appear on a waiting list at all |

**Please also confirm:** if A, does the same apply on the **main** list — can a reserved-category officer be selected against an unreserved vacancy on merit?

---

## Question 4 — What does a pending court case do? *(BD-10)*

**Severity: medium. This changes what a published result says, and possibly whether a promotion takes effect.**

### The case, from your own example

On slide 34, officer **US4** carries **`C.CASE`** in the Note column, and the result reads **`UR_W CC`** — the court case appearing as part of the remark.

### What is not stated

The presentation shows `CC` printed. It does not say what it **means**:

- Is it **an annotation only** — the officer is promoted or waitlisted normally, and the note simply travels with the record?
- Does it **hold the promotion**, in the way a missing property return does (`CIR`), until the case concludes?
- Does it **disqualify** the officer from this DPC?

### What happens today

The note `C.CASE` is recorded and displayed in its own column. It changes nothing, and it is not composed into the remark.

### What we need you to decide

| | Option |
|---|---|
| **A** | Annotation only — print `CC` beside the remark, change nothing else |
| **B** | Hold the promotion until the case concludes, as a `CIR` does |
| **C** | Disqualify the officer from this DPC |

**A closely related question, which it would be sensible to answer at the same time:** slide 33 marks two officers **not eligible** who carry `PUNISHMENT` in the Note column and who score exactly the qualifying 15 marks. Does a recorded punishment disqualify an officer? *(This is **BD-1**, and it has been open since the engine was built.)*

---

## Question 5 — When a DPC is corrected, what happens to an officer already promoted under it? *(BD-11)*

**Severity: high. This decides an officer's substantive rank and pay.** It does **not** hold up any part of the system — the correction facility is built, tested and ready. It must be answered **before that facility is switched on in live use.**

### The situation

A DPC concludes. The promotion list is approved, signed and published. Officers are promoted and take up their posts.

Some time later an error is found — a name left off the seniority list, a mis-entered CR, a reservation rule misapplied — and a **Review DPC** is raised (slide 35). It recalculates, and **its result may differ from the original's.**

The presentation lists the six grounds for a review and says the process is identical to a fresh DPC. **It says nothing about what the correction does to promotions already given effect.**

### What the system does today

When a Review is approved:

- the earlier DPC is marked **superseded**, on both DPCs' records;
- **the department is told**, and the message names both DPC numbers — a department holding a signed list needs to know which list replaces it;
- the earlier documents **remain available**, stamped `SUPERSEDED` when they are opened, with the original file and its integrity seal untouched;
- **nothing is said about the promotion itself.** No withdrawal, no change of rank, no recovery of pay, no revised date of promotion.

**That silence is deliberate, and it is the reason for this question.** The software will not decide a service-law matter on the Commission's behalf. But it means that, until you answer, a superseded promotion list is a document whose effect on the officer named on it is genuinely unsettled — and the person most likely to be misled by that is a reader coming to it years later.

### What we need you to decide

When a Review DPC produces a different result from the one it corrects:

| | Option |
|---|---|
| **A** | The original promotion **stands**. Only the record is corrected, and the Review governs future vacancies |
| **B** | The original promotion is **withdrawn from the date of the Review**, and the Review's result takes effect from then |
| **C** | The original promotion is **withdrawn retrospectively**, with recovery of pay already drawn |
| **D** | **It depends on the ground** — e.g. a name wrongly omitted is corrected retrospectively, a marginal scoring difference is not |
| **E** | Something else — please describe |

**Also worth settling at the same time:** does the answer change if the officer has since **retired**, or has been **promoted again** from the corrected post?

**If the answer is A**, please say so explicitly rather than leaving it implied. A written confirmation that a correction fixes the record and leaves the promotion undisturbed is what turns the system's present silence into a defensible position — and a department acting on a superseded list needs that in writing, not inferred from what the software declines to do.

---

## Question 6 — From what date does a circulated promotion take effect? *(BD-14)*

**Severity: high. This is the one question holding up completion of the Circulation facility.** Everything else about it is built, tested and working. **This is the question to answer first.**

### The situation

A DPC concludes in, say, **2017**, and its promotion list is headed *"PROMOTION AS ON 2017"*. One officer's promotion is **held** — reserved — because two or more years of their confidential reports, or a year's immovable-property return, were not available (slide 45).

Years later the missing record arrives. The Commission circulates a note, consents to that officer's promotion, and signs it. Suppose that signature happens in **2026**.

**Is that officer promoted as on 2017, or as on 2026?**

### Why it is not a formatting question

**Retrospective seniority reaches everybody below.** If the answer is 2017, the officer re-enters the seniority list nine years back, ahead of everyone promoted to that post in between — and their pay may be recalculated from that date. If the answer is 2026, the officer joins at the bottom of those already promoted, and the years the record was missing are lost to them.

Both answers are defensible. They produce different seniority lists, and the difference is not recoverable later without another correction.

### What the system does today

- The circulation note **states both dates** — the original DPC's *promotion as on* year, and the date the Commission released the hold.
- It **asserts neither as governing.**
- The note is rendered, signed, sealed and can be verified. **It is not published**, and the screen says **"Pending Business Decision (BD-14)"** in place of an effective date.

Nothing is invented. But a note that cannot be published is a promotion that cannot be given effect, so the officers concerned stay in the same position they have been in since their DPC.

### What we need you to decide

| | Option |
|---|---|
| **A** | **The original DPC's year.** The promotion is treated as having been due then; the missing record only delayed the paperwork |
| **B** | **The date of the Commission's consent.** The promotion takes effect when the Commission was able to decide it |
| **C** | **It depends on why the record was missing** — e.g. the original year if the department was at fault, the consent date if the officer was |
| **D** | Something else — please describe |

**If the answer is A**, please also say whether pay is recalculated from that date, or only seniority.

**This will be applied as a setting, not a code change.** The system already records both dates; the answer decides which one governs.

---

## Question 7 — May a missing-CR case be corrected by a Review instead? *(BD-15)*

**Severity: high. It affects officers whose promotions are reserved right now with no route to resolution.**

### The situation

Slide 45 gives **two** grounds for circulating a note: a missing **immovable-property** return, or **two or more years** of missing confidential reports.

A property return is a document that can simply be supplied. **A confidential report is not** — a missing CR is usually missing because it was never written, and what arrives later is a report written after the fact, or a decision that the year cannot be assessed at all. That is a different kind of thing to circulate a note about.

### What the system does today

A circulation raised on **CR grounds** is **refused**, with the affected officers named and the reason stated as *"Pending Business Decision (BD-15)"*. Nothing is calculated, no hold is released, and **the case is not automatically turned into a Review** — doing that would answer this question by acting.

**The practical cost of leaving this open:** those officers' promotions remain reserved and there is currently **no route to resolving them at all** — neither a circulation nor a review.

### What we need you to decide

| | Option |
|---|---|
| **A** | **Circulation is correct for both grounds.** A supplied or reconstructed CR is circulated like a property return |
| **B** | **A missing CR must go to a Review DPC**, because the promotion has to be recalculated once the grade is known |
| **C** | **It depends on what arrives** — a graded report circulates; an "unable to assess" decision goes to Review |
| **D** | Something else — please describe |

---

## Question 8 — What if the record that arrives is adverse? *(BD-12)*

**Severity: medium.**

Slide 45 describes the Commission taking a decision **once the missing record is received**. It does not describe the Commission **refusing** a promotion on the strength of what arrives — but a confidential report graded **घ** is a hard disqualifier, and a property return may disclose something the department cannot accept.

**Today the Commission has two choices on a circulation: consent, or return it to the department for further evidence.** There is deliberately no third option, because inventing a refusal would be inventing a rule.

**What we need you to decide:** is there a refusal path? And if the Commission refuses, does that disturb a promotion list **already published** — or is the reserved promotion simply cancelled, leaving everyone else's position untouched?

---

## Question 9 — Is there a time limit on a held promotion? *(BD-13)*

**Severity: low. Nothing is waiting on it.**

Slide 45 sets no limit. A promotion may therefore stay reserved **indefinitely**, and until recently nothing in the system would have drawn attention to it.

The system now **shows how long each promotion has been held**. Displaying an age is not a rule; whether there is a limit, and what happens when it passes, is.

**What we need you to decide:** is there a period after which a held promotion must be resolved or lapses? If so, what happens at that point — and who is told?

---

## What happens once you answer

| Answer | Effect |
|---|---|
| Questions 1 and 2 | Change a **setting**, not the software. Past calculations keep the rules they were run under |
| Question 3 | A change to how officers are placed on lists. It requires a code change and re-approval, because the placement rule sits in a part of the system that is deliberately frozen |
| Question 4 | If A, a small addition — the mechanism already exists, built for held promotions. If B or C, a rule change like questions 1 and 2 |
| Question 5 | If A, **no software change at all** — it confirms what the system already does, and the Review correction facility can be switched on in live use. If B, C or D, new work: recording a withdrawal, a revised date of promotion, and possibly a pay consequence, none of which exists today |
| **Question 6** | Changes a **setting**, not the software — both dates are already recorded and the answer decides which governs. **It also lets the Circulation facility be declared complete**, which is what it is holding up |
| Question 7 | If A, no change — circulation already handles both grounds and the refusal is simply lifted. If B or C, missing-CR cases are routed to a Review instead, which already exists |
| Question 8 | If a refusal path is wanted, a third decision is added alongside consent and return — a small addition to a facility that already records per-officer decisions |
| Question 9 | If there is a limit, the system already knows each hold's age; what is missing is what to *do* at the limit, and whom to tell |

**If a question cannot be answered now,** please say so explicitly. For questions 1 to 4 the system will keep flagging those cases as provisional and refusing to publish them — safe, but the affected promotion lists cannot be issued. **For question 5, the consequence is different:** the Review correction facility stays switched off in live use. **For question 6, a finished piece of work stays formally incomplete**, and the circulated promotions it covers cannot be issued. **For questions 7 and 8, specific officers stay in limbo** — their promotions reserved, with no route to resolution. Everything else continues normally.

---

## What is *not* being asked here

So that UAT time is not spent on them:

- **SMS and e-mail are switched off** — no government gateway is connected yet. Messages are recorded, not sent.
- **HRMS is not connected** — officer details are typed in or uploaded.
- **The digital signature uses a stand-in** — the real CSC signature is not connected. The live system will *refuse* to approve without it rather than fall back to a stand-in.
- **Documents are HTML, not PDF** — deliberately, until the CSC signing service confirms which format it requires.

**Also not being asked:** whether any part of the system is finished, correct or approved. **It is.** Every milestone through the Circulation DPC is built, tested and signed off internally. These nine questions are about **promotion rules and their legal effect**, and nothing on this page depends on further development — including Question 6, where the work is complete and only the rule is missing.

None of these is a defect, and all are already recorded. See `docs/UAT_CHECKLIST.md` for the full scenario list and `docs/PENDING_BUSINESS_DECISIONS.md` for the complete register.
