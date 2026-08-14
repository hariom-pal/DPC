# UAT Business Validation

**Four questions the Commission must answer before a promotion list can be published.**

**Created 2026-08-14 by architect directive.**

---

## Who this is for

This document is for **CGPSC officials and department officers**, not engineers. It contains no technical detail and asks for no technical decision.

The system is built and tested. Every screen in the Commission's own presentation works. What is *not* settled is a small number of **promotion rules** — points where the presentation, the conventional DPC rules, and the worked example do not agree, or simply do not say. The software has deliberately **not guessed** at any of them.

Each section below states one question, shows the actual case that raises it, explains what the system does today, and lists the options. **Answering these four is what allows a promotion list to be published rather than marked provisional.**

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

## What happens once you answer

| Answer | Effect |
|---|---|
| Questions 1 and 2 | Change a **setting**, not the software. Past calculations keep the rules they were run under |
| Question 3 | A change to how officers are placed on lists. It requires a code change and re-approval, because the placement rule sits in a part of the system that is deliberately frozen |
| Question 4 | If A, a small addition — the mechanism already exists, built for held promotions. If B or C, a rule change like questions 1 and 2 |

**If a question cannot be answered now,** please say so explicitly. The system will keep flagging those cases as provisional and refusing to publish them, which is safe but means the affected promotion lists cannot be issued.

---

## What is *not* being asked here

So that UAT time is not spent on them:

- **SMS and e-mail are switched off** — no government gateway is connected yet. Messages are recorded, not sent.
- **HRMS is not connected** — officer details are typed in or uploaded.
- **The digital signature uses a stand-in** — the real CSC signature is not connected. The live system will *refuse* to approve without it rather than fall back to a stand-in.
- **Documents are HTML, not PDF** — deliberately, until the CSC signing service confirms which format it requires.

None of these is a defect, and all four are already recorded. See `docs/UAT_CHECKLIST.md` for the full scenario list and `docs/PENDING_BUSINESS_DECISIONS.md` for the complete register.
