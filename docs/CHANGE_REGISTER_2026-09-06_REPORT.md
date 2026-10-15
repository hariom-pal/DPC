# Change Register — 2026-09-06

**e-DPC Portal · Chhattisgarh Public Service Commission**
**Business alignment received:** 2026-09-06 · **Implemented:** 2026-09-06

The final business alignment before the freeze. Thirteen items. **One required
code** — the Sanctioned Strength maintenance screen, a verified implementation
omission. Everything else was verification of behaviour already built, and one
withdrawal.

---

## Summary

| # | Item | Outcome |
|---|---|---|
| 1 | AO → Pre-DPC → AH approval → **only then** AO creates the Final DPC | **Verified** — already correct |
| 2 | AH approves the Final DPC again | **Verified** — already correct |
| 3 | Approved Final DPC routed automatically to the CGPSC Chairman | **Verified** — already correct |
| 4 | Chairman assigns Allottee + DPC Chairman + Completion Deadline | **Verified** — built 2026-09-05 |
| 5 | Only the Allottee schedules; Meeting Date ≤ Deadline | **Verified** — built 2026-09-05 |
| 6 | DPC Chairman may be the Allottee or another member | **Verified** — both proved live |
| 7 | Committee: Chairman + minimum 3 members | **Verified unchanged** — no change made |
| 8 | One reserved category; UR by default; independent attributes | **Verified** — built 2026-09-05 |
| 9 | Notifications on scheduling · local Demo OTP | **Verified** — built 2026-09-05 / 2026-09-06 |
| 10 | Deadline expiry returns the DPC to the Chairman | **Verified** — built 2026-09-05 |
| 11 | **Sanctioned Strength maintenance** | **BUILT** — the one code change |
| 12 | Documentation | **Updated**, one authoritative flow |
| 13 | Final verification | **GO** |

---

## 11 · Sanctioned Strength — the mandatory fix

### What was wrong

`department_posts.sanctioned_strength` has existed since the change register of
2026-08-27, which moved the figure off the DPC vacancy screen — *"Sanctioned
Strength shall come from Department Post Mapping. The AO should not manually enter
vacancy figures."*

**The move off was made. The move on was never built.** There was no field on the
create, edit, view, index or supersede screens, and no rule in
`DepartmentPostRequest` or `SupersedeDepartmentPostRequest`. Because the controller
persists `$request->safe()->…`, which returns validated data only, the key could
never arrive even in a crafted payload. `git log -S` over the admin request and
views returns nothing: it had **never** existed there.

So the figure was maintainable nowhere, and the only writers in the entire codebase
were `forceFill()` calls in seeders and tests. A post created through the form
carried `NULL`; the Vacancy Engine then returned null totals, section S16 never
turned green — *"The Department Post Mapping for this post records no Sanctioned
Strength"* — and the DPC could not be submitted by any role from any screen.

### What was built

| Surface | Change |
|---|---|
| Create / Edit form | Required numeric input, `min=1`, with hint text |
| List | Column, right-aligned; a **"Not recorded"** badge where the value is null |
| View | Stated always, with an explanation when absent |
| Supersede | Required input, **pre-filled from the mapping being replaced** |
| `DepartmentPostRequest` | `['required', 'integer', 'min:1', 'max:100000']` |
| `SupersedeDepartmentPostRequest` | the same rule |
| `DepartmentPostMappingService::supersede()` | carries the figure onto the replacement |
| Controller | **unchanged** — `safe()` picks the key up now that a rule exists |
| Schema | **unchanged** |

**`min:1`, not `min:0`.** A mapping sanctioning zero posts sanctions nothing, and
the engine would derive a vacancy of zero from it — which on screen is
indistinguishable from a post that is fully staffed. A post being abolished is
expressed by superseding the mapping or setting it inactive.

**The column stays nullable in the schema**, deliberately. Rows registered before
2026-08-27 have none, and making the database refuse them would turn a historical
gap into a migration failure. The form requires it from now on; the engine keeps
reporting "not recorded" for the older rows, and the list shows which they are.

### A latent bug found and fixed with it

`DepartmentPostMappingService::supersede()` built the replacement row from
`class_level`, `pay_scale_id`, `promotion_duration_years` and `effective_from` —
**not** `sanctioned_strength`. A superseding Government Order would have set the
establishment to `NULL` and broken every DPC on that post, silently. It was
invisible because the field could not be set anywhere either, so nobody had a value
to lose.

Superseding is the *normal* way an order changes an establishment, so this would
have fired on exactly the operation it was most wrong for. The replacement now falls
back to the incumbent's figure when the payload omits it.

### Verification — the mandatory chain, live through the Admin UI

| Step | Result |
|---|---|
| 1–3 · Create a Department Post with Sanctioned Strength **5** | `302` · DB shows **5** |
| 4–5 · Edit and update to **9** | `302` · DB shows **9** |
| 6 · Confirm the database | `SELECT` returns **9** |
| 7 · Vacancy Calculation reads the mapping | returns **9** — the *edited* value |
| 8 · Confirm no seeder required | created and edited entirely through the screens |

Screens confirmed rendering over HTTP: **List** (column, and the "Not recorded"
badge against *Teacher*), **Create**
(`<input type="number" id="sanctioned_strength" required min="1">`), **View**, and
**Supersede** (pre-filled). The probe row was removed afterwards and the baseline
restored.

Pinned by five new tests, including
`test_the_admin_ui_is_the_maintenance_source_for_the_vacancy_engine`, which performs
the whole chain in one assertion path.

---

## 1–3 · The Pre-DPC → Final DPC → routing chain, and one withdrawal

**Verified, and one earlier entry withdrawn.** The alignment states:

> *AO → Creates Pre-DPC → AH reviews and approves → **Only after approval, AO is
> allowed to create the Final DPC.** The Final DPC is created from the approved
> Pre-DPC.*

That is exactly what the portal does. `raisablePreDpcs()` offers only orders that
are `activeApproved()`, and `SaveDpcProposalRequest` re-validates the same three
conditions, so an unapproved order cannot be raised from by any route. Proved live:
**before** approval the create screen offered no order; **after** approval it offered
`PDPC-2026-SE-0001`.

**`FBE-001 §3` is therefore withdrawn.** It was recorded on 2026-09-05 from a
clarification reading *"the system creates the DPC"*, and described automatic
creation the business has now confirmed it does not want. Building it would have
been building the wrong thing.

**Routing is automatic.** Approving the Final DPC sets `forwarded_at`, and the
Chairman's allotment queue filters on `status = approved AND forwarded_at IS NOT
NULL` — so the DPC appears there by itself. Nobody routes it.

---

## 4–10 · Verified without change

All were built on 2026-09-05 and are unchanged. Each was re-proved live:

- **Three assignments in one act** — allottee, DPC Chairman, completion deadline.
- **Allottee ≠ DPC Chairman is valid**: the verification run deliberately allotted
  to *Shri Brijesh Kumar Pandey* while *Smt. Sunita Rathore* presided.
- **Meeting Date ≤ Completion Deadline** — 20-10-2026 refused against a deadline of
  30-09-2026 with the message naming both dates; 25-09-2026 accepted.
- **Notifications** — 13 recipients: 4 committee members by SMS, e-mail and in-app;
  9 candidates in-app only (they have no contact channel — `FBE-002`).
- **Deadline expiry** — the sweep listed `DPC-2026-SE-0001` as 1 day over and is
  scheduled daily at 00:15.
- **Committee: Chairman + 3** — `min_members = 3` verified unchanged. **No change
  made**, as instructed.
- **One reserved category** — SC/ST/OBC mutually exclusive, UR by default, no UR
  checkbox; Female / Bhar Sadhak Sachiv / Vibhag Sachiv combine freely.

---

## 9 · Local Demo OTP

`APP_ENV=local` issues `DEMO_OTP` (default `123456`) instead of a random code, so
Department Registration can be completed on a machine with no SMS gateway, no mail
transport and possibly no queue worker.

**Only the randomness is removed.** The row is still written, the TTL still expires
it, `max_attempts` still counts, the code is still consumed, and **a wrong code is
still refused**. There is no branch in `verify()` and there must not be.

**The gate is `app()->environment('local')`, not a config flag.** A configuration
value can be set anywhere by accident; the environment cannot be `local` on a UAT or
production host. `DEMO_OTP` in a production `.env` does nothing at all — pinned by
`test_production_ignores_the_demo_otp_and_issues_a_random_code`.

---

## Database

**No schema change.** No migration was created or run. The column already existed;
only the ability to maintain it was added.

---

## Documentation

Updated: `docs/demo/CURRENT_DEMO_FLOW.md` (Steps 2 and 3, the limitations table, a
pointer to the running log), `docs/demo/DEMO_DATA_GUIDE.md`,
`docs/FUTURE_BUSINESS_ENHANCEMENTS.md` (`FBE-001 §3` withdrawn),
`docs/uat/TERMINAL_SUGGESTIONS.md` (BG-1 withdrawn, KL-2 and R-5 closed, UAT-7/8 and
VR-7/8/9 added, PC-4 and PC-8 made moot, PC-9 opened). New: this register.

`docs/demo/` holds exactly one authoritative version of each document; the three
superseded demo documents were archived on 2026-09-05 to
`docs/archive/2026-09-05-superseded/` with a README.

---

## Verification

- **Full suite: 1,327 tests, 4,408 assertions, all passing.** 8 test cases added.
- `pint` clean · `phpstan` clean · `lang:check` complete in both locales.
- **Sixteen-step live business chain**, recorded in full as `VR-8` in the running
  log.
- Database returned to the clean demonstration baseline: every transactional table
  at 0 rows, `storage/app` holding only its three `.gitignore` files, Redis flushed,
  9 posts with 8 carrying a sanctioned strength.

## Status

**BUSINESS FROZEN · CODE FROZEN.** Critical production-blocking defects only.
