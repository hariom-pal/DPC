# Change Register — 2026-09-05

**e-DPC Portal · Chhattisgarh Public Service Commission**
**Business clarification received:** 2026-09-05 · **Implemented:** 2026-09-05

The last functional change before the business freeze. Six items, all from one
clarification. Two of the six were already satisfied and are recorded as verified
rather than as work; one was explicitly *document only*.

---

## Summary

| # | Item | Outcome |
|---|---|---|
| 1 | A committee member belongs to **one** reserved social category; none means UR (General); no UR checkbox | **Built** |
| 2 | Female / Bhar Sadhak Sachiv / Vibhag Sachiv combine freely with each other and with a social category | **Already true — verified and pinned** |
| 3 | Committee validation satisfies its requirements with the **fewest members**; no separate member per attribute | **Already true — verified and pinned** |
| 4 | The CGPSC Chairman decides allottee, DPC Chairman and completion deadline | **Built** |
| 5 | Meeting date on or before the completion deadline, on schedule and reschedule | **Built** (form layer; the service layer already enforced it) |
| 6 | A lapsed deadline returns the DPC to the CGPSC Chairman, auditably | **Built** (the sweep existed but was never scheduled) |
| 7 | Scheduling notifies all committee members and all candidates with date, time and joining instructions | **Built** |
| 8 | Candidate Self-Inspection by mobile + OTP | **Documented only, as instructed** — `FBE-002` |

---

## 1 · One social category per committee member

> *A Committee Member may belong to ONLY ONE reserved social category … SC + ST is
> not allowed. SC + OBC is not allowed. ST + OBC is not allowed. If none is
> selected, the member is automatically treated as UR (General). There is NO UR
> checkbox.*

**Where the rule lives.** `CommitteeComplianceService::socialCategoryFindings()`,
beside the rules it already enforced. That is the one place both the composition
Form Request and the service consult, so the form and its own validator cannot
disagree — a failure this class has had once before.

**It is the only per-member question this class asks.** Everything else there is
about the committee as a whole: a committee may contain an SC member *and* an ST
member and routinely must. What it may not contain is one person who is both. The
class docblock says so, because the two rules read as contradictory until you
notice which noun they attach to.

**The replacement form repeats it** in `ReplaceCommitteeMemberRequest`, using the
same message string. It posts one member and inherits the rest from what is saved,
so there is no committee list to hand the compliance service — but a substitution
that made somebody SC + ST would be exactly as wrong.

**No UR checkbox, deliberately.** UR is the absence of a claim. A seventh box would
create a state — "UR ticked *and* SC ticked" — that has no meaning, and a fourth
thing to keep in step with the other three. `MemberCategory::claimedSocialCategory()`
returns `null` for UR and `DpcCommitteeMember::socialCategoryLabel()` resolves that
null to the label for display, so screens state it without it ever being stored.

**The screen shows the rule before it enforces it.** The attributes fieldset is now
two: **Social category** (three boxes, ticking one clears the others, with a live
*UR (General)* badge while all three are blank) and **Additional attributes** (any
combination). `public/js/forms/committee-social-category.js` does the clearing and
guarantees nothing — the server refuses a two-category payload whatever the browser
did.

**Files:** `MemberCategory`, `CommitteeComplianceService`, `DpcCommitteeMember`,
`ReplaceCommitteeMemberRequest`, `DpcProposalController::committee()`,
`dpc-proposals/committee.blade.php`, `dpc-proposals/committee-replace.blade.php`,
`dpc-proposals/sections/committee.blade.php`,
`public/js/forms/committee-social-category.js`, `lang/{en,hi}/dpc.php`.

---

## 2 and 3 · Free combination, and minimum-member coverage

Both were already correct, and both are now pinned by tests named for the rule
rather than for the code:

- `test_a_member_may_hold_one_social_category_with_any_additional_attributes`
- `test_the_additional_attributes_combine_freely_and_leave_the_member_unreserved`
- `test_one_member_may_represent_several_attributes` *(pre-existing)*

The third is the business's own worked example — five requirements satisfied by
three members, because one member carries three attributes.
`CommitteeComplianceService::uncovered()` asks *"does anybody here represent ST"*
and never *"is there a member whose only job is ST"*; counting attributes rather
than covering them is the mistake that class is written not to make.

**Verified live**, with the clarification's exact example: Member 1 SC + Female +
Bhar Sadhak Sachiv, Member 2 OBC, Member 3 Vibhag Sachiv — accepted.

---

## 4 · Three decisions, one act

> *Every approved DPC is automatically routed to the default CGPSC Chairman. The
> CGPSC Chairman decides: which member will receive the DPC for execution. Who
> will act as the DPC Chairman. The Completion Deadline.*

**The allotment form now carries all three.** `dpc_chairman_member_id` is required
and validated against **this proposal's own serving committee**, so a seat from
another DPC is a field error rather than something the service must catch.
`AllotmentService::transition()` designates the presiding member through
`DpcCommitteeService::designateChairman()` inside the same transaction — before the
allotment row is written, so a refused designation takes the whole allotment down
rather than leaving a DPC allotted with nobody presiding.

**This reverses item 9d of the register of 2026-08-26**, which was read as *"DPC
will be allocated by the DPC Chairman, not by the CGPSC Chairman"* and enforced as
such. The consequence was that once a Member was designated, the CGPSC Chairman
could no longer act on their own Commission's DPC — including to perform the
remedies §6 of this clarification gives them. `assertMayAllot()` now admits the
CGPSC Chairman always. **The designated DPC Chairman may still allot**: widening
who may act did not require narrowing it elsewhere.

**Three responsibilities, two of which may be one person.** The clarification is
explicit that the DPC Allottee and the DPC Chairman may coincide or differ, and
nothing couples them.

**Re-allotting to the same member is now permitted** when the deadline or the
presiding member changes. It was refused outright, which made *"reassign the same
DPC Allottee"* — the first of the four remedies, and the commonest — impossible.
A re-allotment that changes nothing at all is still refused.

**Files:** `AllotDpcRequest`, `AllotmentController`, `AllotmentService`,
`cgpsc/allotments/index.blade.php`, `lang/{en,hi}/cgpsc.php`.

---

## 5 · The sitting is bounded by the deadline

`SchedulingService::assertWithinCompletionDate()` has refused a late sitting since
the deadline existed, on both schedule and reschedule, and still does — it is the
guard that binds non-HTTP callers.

What it could not do is put the message **against the date box**: a
`WorkflowException` becomes a page-level banner after a redirect, with the offending
date already discarded. `BoundsTheSittingByTheDeadline`, used by both scheduling
Form Requests, adds that. Two layers, one rule, one wording — the trait builds no
rule of its own and the message is a single catalogue string.

An allotment with **no** deadline stays unbounded at both layers. Those predate the
column, and inventing one would make a historical allotment retrospectively overdue.

**Files:** `BoundsTheSittingByTheDeadline` *(new)*, `ScheduleDpcRequest`,
`RescheduleDpcRequest`, `lang/{en,hi}/cgpsc.php`.

---

## 6 · A lapsed deadline returns the DPC — and now actually does

`dpc:return-expired-allotments` existed, was tested, and **was registered
nowhere**. "Automatically returns" was true of the code and false of the system for
a fortnight. It is now scheduled daily at 00:15 in `routes/console.php`,
`withoutOverlapping()` and `onOneServer()`.

**It needs a system cron running `schedule:run`.** Stated in the developer handbook
rather than assumed, because an unregistered schedule is precisely the failure this
closes.

**The return is auditable end to end**, which the clarification requires in as many
words. It already wrote a `dpc_cgpsc.allotment_returned` timeline entry;
`historyFromTimeline()` now reads it back beside the allotment and re-allotment
events, and the history screen renders it in red with the deadline it missed. Before
this, the history showed a DPC moving from one member to another with nothing
between them explaining why — which is the one question a reader of that screen has.

**Files:** `routes/console.php`, `AllotmentService`,
`cgpsc/allotments/history.blade.php`, `lang/{en,hi}/cgpsc.php`.

---

## 7 · Announcing the sitting

> *Once the DPC Allottee schedules the meeting, automatic SMS and Email
> notifications shall be sent to: All Committee Members, All Candidates.
> Notification should contain: DPC Date, DPC Time, Basic Joining Instructions.*

`DpcSittingScheduled` is a domain event of its own rather than a widening of
`WorkflowTransitioned`, which already fires on `schedule`. That listener tells the
*workflow participants* that a state changed; this tells a different and much larger
audience a different thing. One listener choosing between two audiences and two
messages on an event name is the shape that was avoided.

`AnnounceSittingToParticipants` resolves both audiences into one `SittingRecipient`
value object: serving committee members (contact details from the membership row,
which may name somebody with no account at all) and the officers on the Seniority
List, matched to accounts by employee number. A replaced member is excluded —
telling them to attend would be worse than telling them nothing.

**A rescheduled sitting says so.** `isReschedule` selects a different string;
somebody told three times that a DPC is "scheduled" is somebody who attends on the
wrong day.

### The gap, stated rather than hidden

**A Seniority List carries no mobile number and no e-mail address** — the
ten-field snapshot is a service record, not a directory. So a candidate receives
the **in-app notification** and there is nowhere to send them SMS or e-mail. The
listener logs its counts, so the shortfall is visible:

```
Sitting notification dispatched. {"sms":4,"email":4,"unreachable":9,"recipients":13}
```

Verified live on the demonstration data: 13 recipients — 4 committee members
reached on every channel, 9 candidates reached in the portal only, all 13 holding an
in-app notice carrying the date, the time and the joining instructions.

Closing it needs a contact channel on the candidate record, which is **FBE-002**.

**Files:** `DpcSittingScheduled` *(new)*, `AnnounceSittingToParticipants` *(new)*,
`SittingRecipient` *(new)*, `SchedulingService`, `AppServiceProvider`,
`lang/{en,hi}/notifications.php`.

---

## 8 · Candidate login by mobile + OTP — documented only

Instruction: *"Do NOT implement this. Document only."* Recorded as **FBE-002** in
`docs/FUTURE_BUSINESS_ENHANCEMENTS.md`, with the one thing that blocks all three of
its parts: there is no contact channel on a candidate record, so the SMS has nowhere
to go, the OTP has nowhere to be delivered, and
`DPC_SELF_INSPECTION_AUTH_DRIVER=otp` already refuses for exactly that reason
(BD-19).

Nothing was built.

---

## Database

**No schema change.** Every field this register adds already had a column:
`dpc_committee_members.is_dpc_chairman` and the six attribute booleans,
`dpc_allotments.complete_by`, `returned_at`, `return_reason`. `dpc_committee_members`
has no `uuid` column, so the new form field addresses a seat by its route key, which
is what the pre-existing member-replacement route already does.

---

## Verification

- **Full suite: 1,319 tests, 4,372 assertions, all passing.** 24 test cases added
  across four files, and one existing test rewritten because the rule it pinned
  was reversed by §4 of the clarification.
- `pint` clean; `phpstan` clean at the project's configured level.
- `php artisan lang:check` — catalogue complete in both locales.
- **Live end-to-end run** on the demonstration database: SC + ST refused by name;
  the clarification's three-member committee accepted; allotment naming a presiding
  member; a sitting on 20-11-2026 refused against the deadline of 15-10-2026 and
  10-10-2026 accepted; 13 sitting notifications written.
- The database was returned to its clean state afterwards, with all 31 master and
  access tables byte-for-byte identical to before the run.
