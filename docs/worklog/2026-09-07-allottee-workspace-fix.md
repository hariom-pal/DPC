# The allottee could not reach their own DPC — 2026-09-07

**Reported:** the screen where the member a DPC is allotted to accepts it, and the
rescheduling option that used to be there, are gone.
**Cause:** a real defect, in four layers, introduced by the business correction of
2026-09-07 rather than by anything since.
**Left green:** `pint` passed · `phpstan` No errors · **1460 passed**, 2 skipped.

---

## 1 · What was actually wrong

On 2026-09-07 the qualification for being allotted a DPC was corrected from *holding
a role* to *membership of that DPC's committee*, on the stated grounds that
**"sitting on one DPC's committee is not an office"**. `AllotmentService` was changed
accordingly and its comment concluded *"Nothing is loosened by dropping it."*

**Nothing was loosened. Something was stranded.** The committee test is narrower in
deciding *who is eligible*; it says nothing about *what the allottee can then do* —
and the rest of the allottee's path was still gated on `Role::Member`. So the
Chairman could allot a DPC entirely legitimately to somebody with no role, and then:

| Layer | What it did |
|---|---|
| `routes/web.php` | the whole `cgpsc` prefix sat behind `role:chairman\|member`, so the request never reached a controller |
| `DpcAllotmentPolicy::viewAny()` | asked for `Chairman` or `Member` |
| `config/dpc.php` — `accept`, `schedule`, `reschedule` | each gated `'role' => 'member'`, so even a direct POST was refused |
| `DashboardSummaryService` | offered the Commission group on the role, so the dashboard showed no route to the screen |

The allotment succeeded and then **nothing could move the DPC** — no accept, no
decline, no schedule, no reschedule. It did not return to the Chairman either,
because nothing had lapsed. The DPC simply stopped, and the controls the allottee was
supposed to have looked as though they had disappeared.

**Found in the live data, not by reading.** `DPC-2026-SE-0001` was allotted to
**Mamta**, one of the six accounts that hold no role, status still `pending` with
zero schedules — the exact dead end.

## 2 · What was changed

The individual is checked in three places already, and each of them is narrower than
any role test. The fix was to stop the role tests refusing people those checks would
have admitted.

* **`EnsureCanWorkAnAllotment`**, a new middleware: Chairman, Member, or **somebody
  holding a live allotment**. The four allottee actions moved out of the role-gated
  `cgpsc` group into a sibling group behind it — nesting could not express this,
  because group middleware accumulates and a child cannot drop its parent's.
* **`DpcAllotmentPolicy::viewAny()`** admits a live allottee. It decides who may open
  the screen and never what is on it: `view()` and the controller's own query both
  scope every row to `allotted_to`.
* **`WorkflowEngine::assertAllowed()`** accepts `'role' => null` as a *statement* that
  the actor is not identified by a role, and now **refuses a transition that omits the
  key entirely** — so a forgotten role can no longer be mistaken for a deliberate one.
* **`accept`, `schedule`, `reschedule`** state `'role' => null`. All three call
  `SchedulingService::assertAllottee()` before the engine, which requires the actor
  to be the allottee of *this* proposal.
* **`DashboardSummaryService`** offers the Commission group to a live allottee, so
  there is a route to the screen. `commissionGroup()` already scopes its counts to
  `allotted_to` for anyone who is not the Chairman.

**`current()` everywhere.** A returned or superseded allotment is not a live one, so
once the Chairman takes a DPC back the door closes again.

## 3 · The tests that were missing

Nineteen tests exercised the reschedule endpoint and **not one looked at the
screen**, which is why the control could vanish with the suite green — the POST kept
working for anybody who knew the URL, which is nobody. Seven added:

* the allottee sees the reschedule control, and still sees it after rescheduling
* a member who is not the allottee does not
* **an allottee holding no portal role** can open the screen, accept, schedule and
  then reschedule
* their dashboard offers the screen
* somebody with neither a role nor an allotment gets **403**, and no dashboard link
* a role-less allottee sees their own DPC and not another member's

`AuthorizationCoverageTest` now recognises `EnsureCanWorkAnAllotment` as a gate.
Allowlisting those routes as *"reachable by every role"* would have been worse than
leaving them flagged: it would have recorded something untrue about them.

## 4 · Also done

**Every demo password is `Demo@2026` again.** One account — `HARIOm.pal@csc.gov.in`
— had drifted back to a random password with `must_change_password` set, which is why
it would not open. `demo:credentials` re-run: **50 of 50** verified by `Hash::check`,
`must_change_password` clear on all, both channels verified. Note the stored address
is mixed-case; the mobile `9015861718` works as an identifier too.

## 5 · Extended to the sitting, up to the candidate list

**Asked for after the first fix landed**, and it was the far end of the same defect:
the allottee could accept and fix a date, then be refused the screen that runs the
sitting they had just scheduled.

`DpcProceedingPolicy` needed less changing than expected, because two of its four
methods were already written the right way — **`view()` and `act()` bind the
individual by `conducted_by` and name no role at all**, which is narrower than a role
test rather than wider. Only `viewAny()` and `open()` asked for a role, and only
because at those two moments no proceeding exists yet to authorise against. Both now
admit a live allottee.

The **conducting** surface moved behind `EnsureCanWorkAnAllotment` — the sitting
itself, corrections, holds, calculate, the result view and history — and
`dpc_proceeding.open` states `'role' => null`, with `ProceedingService::assertAllottee()`
binding the individual before the engine is reached.

Pinned by two more tests: an allottee holding no role opens the sitting, is recorded
as `conducted_by`, and **sees the candidate on the screen**; and a stranger still
cannot read a sitting somebody else is conducting.

## 6 · Open, and deliberately not decided

**`approve_result` and `cancel_dpc` keep their role gate**, along with document
generation and publication. Those are the transitions that settle and sign a
promotion list. Whether somebody holding no office may sign one is a business
question rather than a defect, so a role-less allottee can today conduct a sitting
and read its result but not approve it. Recommended as the next decision to take, not
assumed.
