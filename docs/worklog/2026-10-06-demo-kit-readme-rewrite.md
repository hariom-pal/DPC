# The demo kit README was unreadable because it numbered everything twice — 2026-10-06

**Asked:** *"samajh nahi aa raha — ye readme theek se update karo ki Pre-DPC kis post se kis
post ki banani hai, fir kaise DPC banega, member kaise assign honge."*

**Found:** the file had **two numbering schemes using the same symbol**, and a **missing
step**. Both fixed. `docs/demo-kit/README.md` 363 → 580 lines.

---

## 1 · The real defect: §5 contained §1–§16

Top-level parts were **§1–§7**. The walkthrough inside part §5 numbered its own steps
**§1–§16**. So *"§4"* meant **"Every upload"** *and* **"Pre-DPC"**, and every cross-reference
— *"the arithmetic is §3 above"*, *"§12 needs this"*, *"she exists only after §8"* — was
ambiguous on its face. A reader following a pointer landed in the wrong place roughly half
the time.

A **third** numbering sat in the who-does-what material (acts 1–9), unrelated to either.

**Now:** parts are **§1–§9**, walkthrough steps are **Step 1 – Step 17**, and the
who-does-what table is keyed to those same step numbers. One scheme, stated at the top of
the file.

## 2 · A step was missing entirely — raising the DPC

The old walkthrough went **§5 AH approves the Pre-DPC → §6 Committee**. It never said how a
DPC comes into existence. That is precisely the gap the question named (*"fir kaise DPC
banega"*), and the kit could not answer it because the step was not in it.

Added as **Step 6**, written against the actual screen rather than from memory:

- **DPC Proposals → New DPC**, card **"1. Sanctioned by"**, first field **Approved Pre-DPC**.
- The **Promotion From Post** dropdown is **disabled** until an order is chosen and reads
  *"Choose a Pre-DPC first"* — the order precedes the DPC and the form enforces it.
- Everything else sits in a read-only panel headed **"Inherited from the Pre-DPC"** with a
  **"Not editable here"** badge: Year, From/To Post, Class, Pay Scale, Promotion Duration,
  Recruitment Rule v1, generated Proposal Date.
- The AO chooses **three** things only: Type of DPC, which feeder, legal stay.

**A first draft of this section was wrong** — it said *"open the approved Pre-DPC → Raise
DPC"*. There is no such button; `route:list` gives `dpc-proposals.create`, and
`dpc-proposals/form.blade.php` with `lang/en/dpc.php` gave the real labels. Corrected before
the file was finished.

## 3 · The post pair now has its own part

*"Kis post se kis post"* was answerable only by reading the middle of a step. It is now
**§2**, before the credentials:

| | Post | Class |
|---|---|---|
| **Promotion FROM** (feeder) | Block Education Officer | II |
| **Promotion TO** | Additional Director, Education | I |

with the three consequences that actually trip people up spelled out: the **seniority list
is of the FROM post**, the **vacancy is counted on the TO post**, and the **5 years of
qualifying service are served in the FROM post**. The Department Post Mapping table at
Step 3 now marks the two rows **← FROM** and **← TO**.

## 4 · "Member" meant four different things

*"Member kaise assign honge"* had no single answer because four distinct things were all
called a member or a chairman. Now a table in §1:

| Term | Who sets it | What it is |
|---|---|---|
| **Committee Member** | the AO, at Step 7 | sits on this DPC. **No role is granted** |
| **CGPSC Chairman** | seeded; nobody | **seated automatically** on every committee; also the one who allots |
| **DPC Chairman** | the CGPSC Chairman, at allotment | **presides** — a column on the membership row, not a role |
| **Allottee** | the CGPSC Chairman, at allotment | **executes** — accepts, schedules, opens, runs |

Verified against `DpcCommitteeService` (*"Do NOT assign any permanent Commission Member
role to a user"*) and `AllotmentService`, whose own docblock carries the three-way table and
states that the allottee and the DPC Chairman may be the same person or different people.
Step 13 now presents the allotment as **three decisions in one act**, which is how the
service implements it.

## 5 · Committee size, checked rather than copied

The screen says *"3 to 6 members besides the Chairman"*; `committee_configurations` row 1
says `min_members 4 / max_members 7`. Not a contradiction —
`CommitteeRuleResolver` notes the columns have *"always counted the whole committee"* and
`CommitteeBoundsReconciler::besidesChairman()` subtracts the Chairman's seat before display.
The README's wording was right and is kept.

## 6 · Added — §9, Higher Education

Asked during the same conversation. Recorded with the blocking facts: HE is
**`unregistered`**, has **0 department posts**, and its AO/AH accounts were removed in the
2026-10-05 prune, so **Step 4's two dropdowns would both be empty**. Three things would have
to be built first.

If it is built, the pair worth choosing is **Principal, Government College (I, Level-13) →
Additional Director, Higher Education (I, Level-13A)** — both Class-I, so the **Zone of
Consideration formula `2 × vacancies + 4` actually fires**. The School Education walkthrough
structurally cannot show it: `ZoneOfConsiderationService` has a rule for **Class-I → Class-I
only**, and BEO → Additional Director is Class-II → Class-I, which returns an **uncapped**
zone. That is now stated in §2 as a consequence of the pair, not left for someone to discover.

## 7 · One finding worth acting on separately

**`pay_scales.level` and `grade_pay` are NULL on all six seeded scales.**
`PromotionHierarchyService` *skips* a check it cannot answer, so for a **same-class** pair
the rule *"a promotion within one class must be to a higher pay scale"* is **skipped, not
passed**. Cross-class pairs are unaffected (class alone decides), so the current
demonstration is not affected — but a Class-I → Class-I demonstration would be showing a
validation that never runs. Filling `level` on the six rows (L10→10 … L14→14) is a
six-value master edit. **Not done** — masters are change-controlled.

## 8 · Not touched

- `docs/demo/CURRENT_DEMO_FLOW.md` keeps its own §numbering; it is a separate document and
  nothing in it was renumbered.
- Earlier worklogs cite the kit's **old** §numbers. They are historical records and were
  left as written.
- The seven files in `demo-kit/uploads/` are unchanged.

---

# Addendum — the NULL pay scales are not cosmetic: **demotions are being accepted**

§7 above recorded `pay_scales.level`/`grade_pay` being NULL as a check that is *skipped
rather than passed*, and judged it harmless for the current walkthrough. **Running the
actual service over every post pair shows it is worse than that.**

## 9 · What the engine accepts today

`PromotionHierarchyService::verdict()` was run over all 9 × 9 pairs of School Education's
active posts: **46 accepted, 26 refused.** Among the 46 accepted:

| FROM | TO | Verdict |
|---|---|---|
| Director, Public Instruction (Cl-I, **Level-14**) | District Education Officer (Cl-I, **Level-12**) | **ACCEPTED** |
| Additional Director (Cl-I, **Level-13A**) | Joint Director (Cl-I, **Level-13**) | **ACCEPTED** |
| Head Master, Middle School (Cl-III, Level-10) | Teacher (Cl-III, Level-10) | **ACCEPTED** |

Every one of those is a **demotion**, and the portal would let an AO draft a Pre-DPC for it.

The verdict object says exactly why:

```
checked = [class]
skipped = [pay_scale, same_class_pay_scale, grade_pay]
```

**Only one of four checks ran.** Class alone cannot separate Level-14 from Level-12 — they
are both Class-I — so for **every Class-I → Class-I pair the hierarchy guard is effectively
absent**, in both directions. The same holds for Class-III → Class-III and Class-II →
Class-II.

## 10 · The code is right; the master is empty

This is **not** a defect in `PromotionHierarchyService`. Its docblock states the choice and
the reasoning:

> *A pay scale registered before this register has no `level` and no `grade_pay`; refusing
> every promotion out of it would turn an incomplete master into a room full of ineligible
> officers, on the day the migration ran.*

Skipping an unanswerable check is deliberate, and `verdict()` reports what it skipped
precisely so this is visible rather than assumed. **The fault is that all six seeded pay
scales carry NULL in both columns**, so the check is unanswerable for every pair in the
system.

```
L10 → level NULL, grade_pay NULL       L13  → level NULL, grade_pay NULL
L11 → level NULL, grade_pay NULL       L13A → level NULL, grade_pay NULL
L12 → level NULL, grade_pay NULL       L14  → level NULL, grade_pay NULL
```

## 11 · The fix, and why it was not applied

Six UPDATEs — `L10→10, L11→11, L12→12, L13→13, L13A→13.5 or 14, L14→14 or 15`.

**Not done.** Two reasons, both needing a ruling:

1. **Masters are change-controlled.** Seeded master values are not edited on the strength of
   a demo observation.
2. **L13A has no obvious integer.** The column is an integer `level`; Level-13A sits between
   13 and 14 in the pay matrix. Either the column becomes decimal, or the ladder is numbered
   by rank (10, 11, 12, 13, 14, 15) rather than by pay-matrix label. **That is a business
   decision, not a typing error** — and it decides whether Additional Director (13A) is
   correctly ranked above Joint Director (13).

Recommended as a **new entry in `CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`**, alongside BD-22.
Awaiting the go-ahead to write it up.

## 12 · Effect on the demonstration

**None on the scripted walkthrough.** Block Education Officer → Additional Director is
**Class-II → Class-I**, decided by the class check alone, which does run. The pair is correct
and the refusal demonstration suggested in the kit's Step 4 (*"try Director, Public
Instruction as the FROM post"*) also still works — Class-I → Class-I going down is accepted,
but Class-I → Class-II is refused on class.

> **The kit's Step 4 note was therefore wrong** and has been corrected: it suggested
> Director, Public Instruction → Additional Director as a pair the system would refuse. It
> does **not** refuse it. The note now names a pair that is actually refused.

---

# Addendum 2 — the kit told the wrong user to open three screens

**Asked:** *"document to kuch aur likha hai — README update karo."* Correct: the file
contradicted itself in one place and named the wrong login in three.

## 13 · A demo blocker at the strongest moment

Step 5's payoff is *"open Administration → Recruitment Rules — one rule has appeared,
nobody typed it."* The kit told the **Administrative Head** to do it.

```
admin.recruitment-rules.index   =>  role:admin
admin.department-posts.index    =>  role:admin
admin.cgpsc-chairman.index      =>  role:admin
pre-dpc.index                   =>  role:authorized_officer|administrative_head
dpc-proposals.index             =>  role:authorized_officer|administrative_head
cgpsc.allotments.index          =>  role:chairman|member
```

**The AH holds `administrative_head` and would have been refused.** So would the AO at
Step 3's Department Post Mapping, and at the pre-flight check for the serving CGPSC
Chairman. Three screens, all named with a login that cannot open them, one of them in front
of the audience at the moment the demonstration is built around.

Taken from `route:list -v`, not inferred.

## 14 · What changed

| | |
|---|---|
| **Step 3** | retitled *"The Administrator's two masters — sign in as `admin@cgpsc.gov.in`"* and split: **3a** Department Post Mapping, **3b** Recruitment Rules shown **empty**. It was marked *optional*; it is not — 3b is the setup without which Step 5 has no payoff |
| **Step 5** | now says plainly that the AH approves, then the **Administrator signs in again** to show the filled master. The 403 is stated so nobody discovers it live |
| **Step 5, fallback** | the generated rule is also visible to the **AO** at Step 6, on the New DPC form's *"Inherited from the Pre-DPC"* panel as **Recruitment Rule v1** — the only non-`admin` view of it in the portal (`dpc-proposals/form.blade.php`). Recorded as the weaker option, because the audience never sees the master empty |
| **§3 pre-flight** | the Chairman check is marked as an `admin` screen |
| **§8** | new row for a 403 on any Administration screen |

## 15 · §4 rewritten: "The nine logins" → "Who can do what"

The old part was a list of accounts with no statement of **who may create a Pre-DPC or a
DPC** — the question that prompted this. It now opens with the rule:

> **Pre-DPC and DPC can be created only by the Authorized Officer, and only in their own
> department.** The AH shares the screens to *review*, not to create.

and carries a second table mapping each screen to the role its middleware demands.

## 16 · The contradiction in §2

§2 said *"`PromotionHierarchyService` checks class, pay scale and grade pay, and a
same-class pair must additionally carry a higher pay scale"* — and then, forty lines below,
that those three checks are **skipped** on this database. Both sentences were in the same
part. The first now states that only the class check actually runs here, and why Block
Education Officer → Additional Director is still sound (Class-II → Class-I is decided by
the check that does run).

## 17 · Not changed

The §9 finding stands: filling `pay_scales.level` needs a ruling on where **Level-13A**
sits between 13 and 14. Still awaiting the go-ahead to write it into
`CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`.
