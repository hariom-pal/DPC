# Terminal Suggestions

**e-DPC Portal · Chhattisgarh Public Service Commission**
**Opened:** 2026-09-05 · **Last updated:** 2026-09-06 (UAT-15, UAT-16 — designation master and the qualification multiselect)

---

## What this document is

**The terminal is temporary. This document is the permanent source of truth.**

Every recommendation, warning, limitation, suggestion, gap, manual step, note and
verification result that has appeared in terminal output during a working session
is recorded here. Nothing important is allowed to exist only in scrollback.

**It is a continuously maintained running log.** When anything of the kinds listed
below is printed to the terminal, the same information is appended here in the same
session. Entries are appended and their **Current Status** updated in place; entries
are not deleted when they are resolved, because "this was a problem and here is how
it was closed" is as useful as the problem itself.

**Kinds of thing recorded here:** business gaps · known limitations ·
recommendations · suggestions · warnings · demo notes · manual steps · deployment
notes · environment notes · missing features · future enhancements · technical debt
· business clarifications · UAT findings · verification results · implementation
omissions · performance notes · security notes · configuration notes · artisan
commands · queue and cron requirements · seeder dependencies · migration notes ·
anything printed under *Recommendation*, *Impact* or *Current Status*.

**Relationship to the other registers.** This file is the **session log** — what was
observed and said. The decision registers are narrower and authoritative for their
own subject:

| File | Holds |
|---|---|
| `docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` | construction-phase questions `BD-2` … `BD-49` |
| `docs/PENDING_BUSINESS_QUESTIONS.md` | UAT questions `UAT-Q-nnn` the business must decide |
| `docs/FUTURE_BUSINESS_ENHANCEMENTS.md` | `FBE-nnn` — decided, to be built later |
| `docs/CHANGE_REGISTER_2026-09-05_REPORT.md` | what was built on 2026-09-05 and why |
| **this file** | everything that was said in the terminal, cross-referenced to the above |

**Priority scale:** **P1** blocks the demonstration or a real promotion · **P2**
degrades the demonstration or needs a manual step · **P3** worth doing, no
immediate consequence · **Info** recorded for reference only.

---

# Business Gaps

### BG-1 · ~~The system does not create the DPC on Pre-DPC approval~~ — WITHDRAWN

**Description.** This was recorded as a gap on 2026-09-05, from a clarification that
said *"the system creates the DPC"*. The business alignment of **2026-09-06** states
the approved workflow in full and it is the opposite:

> *AO → Creates Pre-DPC → AH reviews and approves → **Only after approval, AO is
> allowed to create the Final DPC.** The Final DPC is created from the approved
> Pre-DPC.*

**Why it matters.** It was never a defect. The portal already did exactly this, and
building "automatic creation" would have been building the wrong thing.

**Current Status.** **Withdrawn 2026-09-06. Not a gap.** Verified live: before
approval the create screen offered no order; after approval it offered
`PDPC-2026-SE-0001`. `raisablePreDpcs()` filters on `activeApproved()` and
`SaveDpcProposalRequest` re-validates the same conditions, so an unapproved order
cannot be raised from by any route. `FBE-001 §3` is marked withdrawn.

**Suggested Action.** None. Present the DPC as "raised from the approved order",
which is what the screen says.

**Priority.** Closed.
**Related Module.** Pre-DPC · DPC Proposal.

---

### BG-2 · No multi-member DPC sitting

**Description.** The business describes a sitting the DPC Chairman and every assigned
Committee Member join, all of whom can view the candidate and the whole DPC record
and participate in the evaluation. Today a DPC is conducted by **one accountable
allottee**. Committee membership grants no access to the sitting; a Member sees only
DPCs allotted to them; the Chairman sees all of them but *reading is not conducting*.

**Why it matters.** This is the largest unbuilt item. It touches proceeding
authorisation, the evaluation tables (which would gain a per-member dimension),
notifications, and the audit trail, which must be able to say which member did what
within one sitting.

**Current Status.** Not built. Documented as `FBE-001 §9`, with the affected areas
listed there.

**Suggested Action.** Do not attempt before the demonstration. Needs the open points
in `FBE-001 §4` settled first — in particular whether each member records their own
marks or the committee records one agreed set, and whether a quorum or majority
concludes the sitting.

**Priority.** P3 for the demonstration, **P1 for the next phase**.
**Related Module.** Proceedings · Evaluation · Committee.

---

### BG-3 · Candidates have no contact channel, so they receive no SMS or e-mail

**Description.** The sitting announcement is built and sent to every serving
committee member and every officer on the Seniority List. **A Seniority List carries
no mobile number and no e-mail address** — the ten-field officer snapshot is a
point-in-time service record, not a directory — so a candidate receives the in-app
notification only.

**Why it matters.** It also blocks candidate login by mobile + OTP, and it is why
`DPC_SELF_INSPECTION_AUTH_DRIVER=otp` returns a structured *Pending Business
Decision (BD-19)* rather than working.

**Current Status.** Structural gap, not a defect. The listener reports its own
shortfall rather than hiding it — verified live:
`Sitting notification dispatched. {"sms":4,"email":4,"unreachable":10,"recipients":14}`.
Documented as `FBE-002`.

**Suggested Action.** Say so plainly in the demonstration. Closing it needs a
decision on where a candidate's mobile number comes from — the Seniority List
template, an HRMS integration, or the officer themselves — which is `FBE-002 §4`.

**Priority.** P2.
**Related Module.** Notifications · Seniority List · Self-Inspection.

---

### BG-4 · Committee minimum — ruled and closed

**Description.** The business clarification of 2026-09-05 said a committee contains
"one or more Committee Members"; the implemented rule is a minimum of **three
members besides the Chairman**. This was raised as an open point.

**Why it matters.** It decides whether a two-person committee may sit.

**Current Status.** **Closed by business ruling on 2026-09-06: keep Chairman + 3.**
Verified unchanged — `config/dpc.php` `min_members = 3`, `max_members = 6`, both
meaning members *besides* the Chairman. No change made.

**Suggested Action.** None. `FBE-001 §4` open point 4 is answered.

**Priority.** Info.
**Related Module.** Committee.

---

# Known Limitations

### KL-1 · A queue worker must be running

**Description.** In-app notifications, OTP delivery, credential dispatch and the
sitting announcement are **queued** listeners on `QUEUE_CONNECTION=redis`.

**Why it matters.** Without a worker the notification bell stays empty for the whole
demonstration and nothing is delivered anywhere. With one running, a single
end-to-end pass produced **21 notifications** across five actors, and the sitting
announcement reached 13 people.

**Current Status.** Verified both ways. Queue length reached 20 pending jobs with no
worker; draining it wrote all 21 notifications.

**Suggested Action.** `php artisan queue:work` alongside `php artisan serve`, before
the demonstration begins. Stated at the top of `docs/demo/CURRENT_DEMO_FLOW.md`.

**Priority.** **P1** — it is the single commonest cause of a demonstration looking
broken.
**Related Module.** Notifications · Authentication · Queue.

---

### KL-2 · Department Registration OTP — resolved for `local`

**Description.** Registration requires both an e-mail OTP and a mobile OTP. With
`MAIL_MAILER=log` and no SMS gateway, the codes reached only
`storage/logs/laravel.log`, and only if a queue worker happened to be running — so
registration could not be completed in front of a customer.

**Why it matters.** It made Step 2 of the demonstration undemonstrable for reasons
that had nothing to do with the workflow being shown.

**Current Status.** **Resolved on 2026-09-06.** When `APP_ENV=local` the issued code
is `DEMO_OTP` (default `123456`). Nothing else about OTP changed: the row is still
written, the TTL still expires it, the attempt counter still counts, and a wrong code
is still refused. The gate is `app()->environment('local')`, not a config flag, so
`DEMO_OTP` in a production `.env` does nothing. Pinned by three tests including
`test_production_ignores_the_demo_otp_and_issues_a_random_code`.

**Suggested Action.** Use `123456` at the verify screen. The previous workaround —
`grep -o 'verification code is [0-9]*' storage/logs/laravel.log | tail -2` — still
works and is the fallback on any non-local environment.

**Verification (2026-09-06).** Registration completed on screen end to end using
`123456` for both OTPs. Direct probe: `verify('123456') === true`,
`verify('999999') === false`.

**Priority.** Closed (was P2).
**Related Module.** Authentication · Registration.

---

### KL-3 · 23 Promotion Candidates have no known password

**Description.** `UserProvisioningService` issues each candidate a random
12-character password when a Seniority List first names their employee number, and
dispatches it over their contact channel. A candidate has neither an e-mail nor a
mobile, so it goes nowhere. `Demo@2026` does **not** work for any of them.

**Why it matters.** Candidate login is a step of the demonstration.

**Current Status.** By design, not a defect. 9 of 34 accounts can sign in.

**Suggested Action.** Use the Authorized Officer's **Reissue password** button on the
proposal **while the DPC is still in Draft or Returned** — it displays the temporary
password once. Verified: `CG200001` → `Tgntexi9K8` → forced change → `/my-dpc` 200.

**Priority.** P2.
**Related Module.** Candidate · User Management.

---

### KL-4 · Administration → Users → Reset Password does not display the new password

**Description.** The action issues a new temporary password and says so, but the
value is dispatched to the account's e-mail/SMS and never shown on screen.

**Why it matters.** For a candidate — who has neither channel — the action is
useless: it changes the password to something nobody can learn.

**Current Status.** Confirmed by direct test. Route works (302, *"A new temporary
password has been issued"*), value not rendered.

**Suggested Action.** Do not use it for candidates. Use the AO's Reissue button
(KL-3). Worth a small enhancement later: show the value once, as the AO panel does.

**Priority.** P3.
**Related Module.** User Management.

---

### KL-5 · Self-Inspection closes once the sitting approves its result

**Description.** `DPC_SELF_INSPECTION_CLOSE_AFTER_RESULT` defaults true, and only
proposals in `submitted` or `approved` status are inspectable.

**Why it matters.** Demonstrated in the wrong order it simply refuses, which looks
like a fault.

**Current Status.** Intended behaviour — after a result the route to a correction is
a Review DPC, which is the department's to raise.

**Suggested Action.** Show it **between Step 14 (AH forwards) and Step 19 (Member
approves the result)**.

**Priority.** P2 — sequencing, not a defect.
**Related Module.** Self-Inspection.

---

### KL-6 · Documents render as HTML, not PDF

**Description.** The Proceedings document and the Promotion List are generated,
signed and published as HTML.

**Why it matters.** A customer may expect a PDF.

**Current Status.** Architectural — `DocumentRenderer` is a contract precisely so a
PDF renderer can replace the HTML one without touching the approval path.

**Suggested Action.** State it plainly if asked; the provenance block, content hash
and signature are all present regardless of format.

**Priority.** P3.
**Related Module.** Proceedings · Documents.

---

### KL-7 · Digital signature uses the mock provider

**Description.** `FEATURE_CSC_DIGISIGN=false`. Signatures are real, complete records
— actor, artefact, hash, stored file — but not CSC-issued.

**Why it matters.** Legal weight in production depends on the real integration.

**Current Status.** Deliberate. Every integration is behind a `FEATURE_*` flag,
disabled by default, and the application boots and runs fully with none connected.

**Suggested Action.** Say so plainly: the integration is behind a flag and was never
mocked *in production*.

**Priority.** Info.
**Related Module.** Signatures · Integrations.

---

### KL-8 · Zone of Consideration reads "No cap" for the demonstration post pair

**Description.** The deck states no zone formula for class pairs other than
Class-I → Class-I, so for the demonstration's Class-III → Class-II promotion every
officer meeting the experience qualification is considered.

**Why it matters.** A customer may expect to see a computed zone.

**Current Status.** Correct behaviour, and the screen explains it: *"This class pair
has no zone formula — every officer meeting the experience qualification is
considered."*

**Suggested Action.** To show a computed zone, use a Class-I → Class-I pair.

**Priority.** Info.
**Related Module.** Vacancy · Eligibility.

---

### KL-9 · The application has no vhost, and port 8000 is taken

**Description.** The portal is served by `php artisan serve`. Port 8000 on this
machine is occupied by an unrelated project — the *CGMFPFED Tendu Leaf Collectors'
Welfare Platform*.

**Why it matters.** Pointing a browser at `:8000` reaches a different application
entirely, whose login screen looks superficially similar.

**Current Status.** Confirmed: `:8000` returned the CGMFPFED login page.

**Suggested Action.** Use **`http://127.0.0.1:8001`** throughout.

**Priority.** **P1** for the demonstration — it wastes the first five minutes.
**Related Module.** Environment.

---

# Recommendations

### R-1 · Never run `php artisan demo:credentials`

**Description.** Despite the name it **mutates**: it resets every demo password,
creates missing demo users, flips a department's registration status, writes timeline
rows and generates credential files.

**Why it matters.** It was run once by mistake expecting it to *list* credentials. It
created two users, two role rows, two department assignments and re-hashed every
password. All of it had to be reverted from a pre-cleanup dump.

**Current Status.** Documented in `docs/CGPSC_DPC_DEVELOPER_HANDBOOK.md` and in
`docs/demo/DEMO_USERS.md`.

**Suggested Action.** Read `docs/demo/DEMO_USERS.md` instead. If the command must be
kept, rename it — a read-sounding name on a write command is the defect.

**Priority.** **P1** — it destroys the prepared demonstration state.
**Related Module.** Console · Demo.

---

### R-2 · Take a backup before any reset

**Description.** Before the transactional cleanup a full `mysqldump` and a
`storage/app` tarball were taken, and both proved necessary once.

**Why it matters.** The `demo:credentials` incident (R-1) was recoverable only
because of the dump.

**Current Status.** Rollback available at
`…/scratchpad/dpc_dev_pre_demo_freeze_backup.sql` and
`storage_app_pre_demo_freeze.tgz`.

**Suggested Action.** Keep the habit: dump, act, then diff `CHECKSUM TABLE` over the
preserved tables to prove nothing else moved.

**Priority.** P2.
**Related Module.** Operations.

---

### R-3 · Verify a reset by checksum, not by eye

**Description.** After every cleanup, `CHECKSUM TABLE` was taken over all 31 master
and access tables before and after, and diffed.

**Why it matters.** It caught a real corruption: restoring users from a `mysqldump`
without `SET time_zone='+00:00'` shifted every `TIMESTAMP` by the local offset. The
row counts looked right; the checksum did not.

**Current Status.** Practice established. Every reset in this session ended with
*"ALL 31 MASTER/ACCESS TABLES BYTE-IDENTICAL TO THE PRE-WORK BASELINE"*.

**Suggested Action.** `mysqldump --tz-utc` writes UTC literals; reload with
`SET time_zone='+00:00'` or the timestamps silently move.

**Priority.** P2.
**Related Module.** Operations · Database.

---

### R-4 · A command whose value is that it runs by itself must be registered

**Description.** `dpc:return-expired-allotments` existed, was tested, and was
registered **nowhere** for a fortnight. "The system automatically returns the DPC"
was true of the code and false of the system.

**Why it matters.** It is a class of defect no unit test can catch, because the test
calls the command directly.

**Current Status.** Fixed 2026-09-05 — scheduled daily at 00:15 in
`routes/console.php`, `withoutOverlapping()`, `onOneServer()`. Verified by
`php artisan schedule:list`.

**Suggested Action.** When adding a scheduled command, `schedule:list` is the check
that it exists. See also DN-1 — the registration is inert without a host cron.

**Priority.** P2.
**Related Module.** Console · Scheduler.

---

### R-5 · Sanctioned Strength needs a maintenance screen (do not build before the freeze)

**Description.** `department_posts.sanctioned_strength` is mandatory for vacancy
calculation and has **no field on any screen and no validation rule**, so it cannot
be set through the application at all.

**Why it matters.** A Department Post created live during a demonstration will carry
`NULL`, and the DPC raised against it will stall at section S16 with no way to clear
it from any screen.

**Current Status.** Verified 2026-09-06. See UAT-1 for the full evidence.

**Current Status — IMPLEMENTED 2026-09-06** under the mandatory-fix instruction.
Exactly the confined change described: a `sanctioned_strength` rule on
`DepartmentPostRequest` (required · integer · min 1) and on
`SupersedeDepartmentPostRequest`, a numeric input on the Create/Edit form and on the
Supersede form pre-filled from the mapping being replaced, a column on the list, and
the value on the detail screen. No schema change; no direct SQL; persisted through
the existing Request and Controller.

**One latent bug was found and fixed with it.** `DepartmentPostMappingService::supersede()`
built the replacement row without carrying `sanctioned_strength`, so superseding a
mapping would have silently dropped the figure to `NULL` and broken every DPC that
depended on it. It was invisible because the field could not be set anywhere either.
The replacement now falls back to the incumbent's figure when the payload omits it.

**Verified — the mandatory chain, live through the Admin UI:** create with 5 → DB
shows 5 → edit to 9 → DB shows 9 → Vacancy Engine reads **9**. No seeder involved.
Pinned by `test_the_admin_ui_is_the_maintenance_source_for_the_vacancy_engine`.

**Priority.** Closed.
**Related Module.** Department Post Mapping · Vacancy.

---

### R-6 · Fill `DATE OF POSTING ON PROMOTION TO POST` on every Seniority List

**Description.** Self-Inspection's default identity factors are **name + date of
posting on the promotion-to post + date of birth**. The supplied sample seniority
list left that column blank, so no officer could ever match.

**Why it matters.** It made the Self-Inspection step of the demonstration impossible,
and the failure looked like a matching bug rather than a data gap.

**Current Status.** Fixed in the sample data on 2026-09-05 — the column now carries
each officer's feeder posting date, the convention the project's own seeder uses
(`DemoChart::postedOnFeederPost`). Verified failing before and matching after. Sample
data only; no code, schema, validation or workflow touched.

**Suggested Action.** If a department's real file leaves it blank, either fill it or
set `DPC_SELF_INSPECTION_FACTORS=name,dob` — the factor set is configuration.

**Priority.** P2.
**Related Module.** Self-Inspection · Seniority List · Demo Data.

---

### R-7 · Assert e-mail through the SMS channel, not `Mail::fake()`

**Description.** `MailFake::raw()` is an empty method — it records nothing. Every
notifier in this codebase sends with `Mail::raw()`, so `Mail::assertSent()` can never
see them.

**Why it matters.** A test written against `Mail::fake()` passes for the wrong reason
and proves nothing.

**Current Status.** `SittingAnnouncementTest` asserts on `RecordingSmsSender`
instead. Both channels are built from one string in the listener, so proving the SMS
proves the e-mail body too.

**Suggested Action.** Use `tests/Support/RecordingSmsSender.php` for delivery
assertions.

**Priority.** P3 — technical debt, recorded as TD-3.
**Related Module.** Testing · Notifications.

---

# Manual Actions Required

### MA-1 · Start both processes before the demonstration

```bash
cd /var/www/html/DPC
php artisan serve --port=8001      # the application
php artisan queue:work             # NOT OPTIONAL — see KL-1
```

**Why it matters.** Port 8001, not 8000 (KL-9). The worker is not optional (KL-1).
**Current Status.** Documented at the top of `docs/demo/CURRENT_DEMO_FLOW.md`.
**Suggested Action.** Run both, then confirm one login before the customer arrives.
**Priority.** **P1.** **Related Module.** Environment.

---

### MA-2 · Obtain a Promotion Candidate password during the run

**Description.** Sign in as the AO → open the DPC **while it is still Draft** → find
the officer's row → **Reissue password** → write down the 10-character value shown
once → sign in with the **employee number** → change the password to `Demo@2026`.

**Why it matters.** It is the only route that produces a readable candidate password
(KL-3, KL-4), and it is itself a good thing to demonstrate.

**Current Status.** Verified end to end.
**Suggested Action.** Do it at Step 10, before submitting.
**Priority.** P2. **Related Module.** Candidate.

---

### MA-3 · Demonstrate the deadline return by hand

**Description.** The automatic return runs on a scheduler that needs a host cron
(DN-1). To show it live:

```bash
php artisan dpc:return-expired-allotments --as-of=2026-10-16 --dry-run   # list
php artisan dpc:return-expired-allotments --as-of=2026-10-16             # perform
```

**Why it matters.** Waiting for 00:15 is not an option in a meeting.
**Current Status.** Idempotent — a row already carrying `returned_at` is skipped.
**Suggested Action.** Then open the DPC's allotment history: the return appears in
red beside the allotment it ended.
**Priority.** P2. **Related Module.** Allotment · Console.

---

### MA-4 · Show Self-Inspection at the right moment

**Description.** Between Step 14 and Step 19 only (KL-5). Factors for the supplied
data:

```
Name  : Shri Ashok Kumar Dwivedi
DOB   : 14-02-1968
DOP   : 12-04-2010     (Date of Posting on Promotion To Post)
```

**Why it matters.** All three must match exactly; nothing is matched partially.
**Current Status.** Verified matching.
**Suggested Action.** Follow `docs/demo/CURRENT_DEMO_FLOW.md` Step 22.
**Priority.** P2. **Related Module.** Self-Inspection.

---

# Deployment Notes

### DN-1 · The scheduler needs a host cron entry

**Description.** `routes/console.php` registers one scheduled command. Nothing runs
it unless the host has:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

**Why it matters.** Without that line the registration is as inert as no
registration, which is exactly the failure R-4 closed.

**Current Status.** Documented in `docs/CGPSC_DPC_DEVELOPER_HANDBOOK.md` §12.
`php artisan schedule:list` is the check.

**Suggested Action.** Add it to the deployment runbook for every environment.
**Priority.** **P1 in production.** **Related Module.** Scheduler · Deployment.

---

### DN-2 · A queue worker must be supervised in every environment

**Description.** The same dependency as KL-1, but permanent: notifications, OTPs and
credentials are all queued.

**Why it matters.** In production a stopped worker means officers silently stop being
told anything.

**Current Status.** No supervisor configuration is committed.
**Suggested Action.** Supervisor or systemd unit for `queue:work`, with restart on
failure, in the deployment runbook.
**Priority.** **P1 in production.** **Related Module.** Queue · Deployment.

---

### DN-3 · Run `security:evidence-grants --verify` at every release

**Description.** The command prints the production `GRANT`/`REVOKE` SQL for the
twelve evidence tables and, with `--verify`, attempts each write the grants should
refuse — as whichever account the application connects as — and reports what the
database actually did. Every probe carries `WHERE id = -1` so no row can match.

**Why it matters.** Database-level protection of the audit and evidence tables is
only real if it is checked; grants drift.

**Current Status.** Command exists. Documented in the developer handbook.
**Suggested Action.** Add `--verify` to the release checklist, not only to first
deployment.
**Priority.** P2. **Related Module.** Security · Deployment.

---

### DN-4 · Feature flags are all off by default

**Description.** `FEATURE_HRMS`, `FEATURE_CSC_DIGISIGN`, `FEATURE_SMS_GATEWAY`,
`FEATURE_EMAIL_GATEWAY`, `FEATURE_S3_STORAGE`, `FEATURE_LGD_SYNC` all default
`false`; `FEATURE_NOTIFICATIONS` defaults `true`.

**Why it matters.** The application boots and runs fully with no external system
connected — which is what makes the demonstration possible — and enabling a real
integration later is an environment variable plus its credentials.

**Current Status.** By architectural ruling of 2026-08-13. Every binding is chosen in
`AppServiceProvider::register()`.
**Suggested Action.** None. Record which flags each environment turns on.
**Priority.** Info. **Related Module.** Integrations.

---

# Environment Notes

### EN-1 · Local environment as it stands

| Setting | Value |
|---|---|
| `APP_ENV` | `local` |
| `APP_URL` / actual | `http://localhost` / **`http://127.0.0.1:8001`** |
| Database | MySQL `dpc_dev` |
| Cache / Session / Queue | Redis (password-protected) |
| Mail | `MAIL_MAILER=log` |
| SMS | log driver (`FEATURE_SMS_GATEWAY=false`) |
| `DEMO_OTP` | `123456` — **local only** |

**Why it matters.** Every limitation in this document that concerns delivery follows
from the mail and SMS drivers being log drivers.
**Current Status.** Verified 2026-09-06.
**Suggested Action.** None.
**Priority.** Info. **Related Module.** Environment.

---

### EN-2 · Redis requires authentication

**Description.** `redis-cli` returns `NOAUTH Authentication required` without the
password from `REDIS_PASSWORD`.

**Why it matters.** A cache or session inspection that appears to show an empty Redis
may simply be unauthenticated.

**Current Status.** Known.
**Suggested Action.** `export REDISCLI_AUTH=…` before `redis-cli`, or the answers are
wrong rather than absent.
**Priority.** Info. **Related Module.** Environment.

---

### EN-3 · Laravel's queue key is namespaced

**Description.** The queue list is `odpc-portal-database-queues:default`, not
`queues:default`. `LLEN queues:default` returns 0 while 20 jobs are pending.

**Why it matters.** It reads as "the queue is empty" when it is not.
**Current Status.** Known.
**Suggested Action.** `redis-cli --scan` for the real key name before drawing a
conclusion.
**Priority.** Info. **Related Module.** Queue.

---

# Demo Notes

### DM-1 · Database state prepared for the demonstration

**Description.** All 50 transactional tables at 0 rows with every `AUTO_INCREMENT`
back to 1, so the first Pre-DPC will be `PDPC-2026-SE-0001` and its DPC
`DPC-2026-SE-0001`. All 31 master and access tables preserved byte-for-byte.
`storage/app` holds only its three `.gitignore` files. Redis flushed.

**Why it matters.** Numbering starting at 0001 in front of a customer is worth the
preparation.
**Current Status.** Verified after every run in this session.
**Suggested Action.** If a dry run dirties it, repeat: `dpc:reset-transactional-data
--force`, truncate the three recruitment-rule tables, flush Redis, re-check
checksums.
**Priority.** P1. **Related Module.** Demo.

---

### DM-2 · Recruitment Rules is deliberately empty

**Description.** The Recruitment Rules master shows **no rows** before the
demonstration begins.

**Why it matters.** It is the point of Step 6: **nobody types a recruitment rule**.
Approving a Pre-DPC *projects* v1 into the master, and the customer watches it
appear. A preserved rule and one created live would also collide as two active
versions describing different promotion ladders — which happened on 2026-09-02 and is
why the tables are cleared.

**Current Status.** Intentional.
**Suggested Action.** Show `/admin/recruitment-rules` empty at Step 3, then again
after Step 6.
**Priority.** Info. **Related Module.** Recruitment Rules · Pre-DPC.

---

### DM-3 · Figures the supplied sample data produces

| Quantity | Value |
|---|---|
| Sanctioned / Working | 4 / 3 |
| Base / Retirement / Deputation vacancy | 1 / 1 / 0 |
| **Total Vacancy** | **3** (Promotion 3, Direct 0) |
| Zone of Consideration | **No cap** (Class III → Class II) |
| Seniority List / Evaluation Chart | 9 / 9 officers |
| Evaluation children | 53 CR grades · 54 loyalty · 54 property |
| Calculation | 9 assessed · 6 eligible · Main **3** · Waiting 3 · Not listed 3 |
| Holds | 1 — SN 6, immovable property not recorded for 2025 |
| Documents | 2 — Proceedings, Promotion List |
| Sitting announcement | 14 recipients (4 external + in-app, 10 in-app only) |

**Why it matters.** The **Main List of 3 equals the Total Vacancy the engine derived**
— with neither number typed by anybody. That is the strongest single moment in the
demonstration.
**Current Status.** Verified live, twice.
**Suggested Action.** Point the agreement out explicitly.
**Priority.** Info. **Related Module.** Demo · Vacancy · Eligibility.

---

### DM-4 · Nine accounts can sign in

**Description.** 34 accounts on record; **9 sign in with `Demo@2026`** — admin,
chairman, member1–5, AO, AH. Verified by real `POST /login`, by e-mail and by mobile.
The other 25 are 23 Promotion Candidates (KL-3) and 2 role-less legacy committee
records that stop at the OTP screen.

**Why it matters.** Those nine cover every role the demonstration needs.
**Current Status.** Re-verified 2026-09-05 after the final reset.
**Suggested Action.** `docs/demo/DEMO_USERS.md` is the full roster.
**Priority.** Info. **Related Module.** Demo · Authentication.

---

### DM-5 · Do not create a Department Post live

**Description.** A post created during the demonstration will have no sanctioned
strength and cannot be given one (R-5, UAT-1).

**Why it matters.** The DPC raised against it stalls at S16 with a message pointing
at a screen that has no such field.

**Current Status.** Verified 2026-09-06.
**Suggested Action.** Demonstrate Department Post Mapping **read-only** — open Block
Education Officer and show its Sanctioned Strength of 4 as an existing record.
**Priority.** **P1 for the demonstration.** **Related Module.** Department Post
Mapping.

---

# Future Enhancements

### FE-1 · ~~`FBE-001 §3` — automatic DPC creation on Pre-DPC approval~~ — WITHDRAWN
See BG-1. The business alignment of 2026-09-06 confirms the AO creates the Final DPC
from the approved Pre-DPC, which is what the portal does. **Status:** withdrawn — not
wanted. **Priority:** Closed. **Module:** Pre-DPC.

### FE-2 · `FBE-001 §9` — the multi-participant sitting
See BG-2. **Status:** not built; the largest remaining item. **Priority:** P1 next
phase. **Module:** Proceedings.

### FE-3 · `FBE-001 §5` — the Chairman composes the committee and fixes the date
**Description.** Today the Authorized Officer composes the committee while the
proposal is a draft, and the **allottee** sets the date within the Chairman's
deadline. The business describes the Chairman doing both.
**Why it matters.** It moves work between three actors and changes who is
accountable for the sitting date.
**Current Status.** Partly built — *who presides* and *by when* were built on
2026-09-05; composing and scheduling were not.
**Suggested Action.** Schedule with FE-2; they touch the same screens.
**Priority.** P3. **Module:** Committee · Scheduling.

### FE-4 · `FBE-002` — candidate login by mobile + OTP
See BG-3. **Description.** Candidate receives SMS + e-mail → logs in with mobile +
OTP → performs Self-Inspection before the sitting. **Status:** not built, explicitly
*document only*. Blocked by the absent contact channel. `DPC_SELF_INSPECTION_AUTH_DRIVER`
already names `otp` as one of three drivers and refuses with a structured *Pending
Business Decision (BD-19)*. **Priority:** P2. **Module:** Self-Inspection ·
Authentication.

### FE-5 · ~~Sanctioned Strength maintenance screen~~ — BUILT 2026-09-06
See R-5, UAT-1, UAT-7 and VR-7. **Status:** implemented and verified live.
**Priority:** Closed. **Module:** Department Post Mapping.

### FE-6 · Show the reset password once, as the AO panel does
See KL-4. **Status:** not built. **Priority:** P3. **Module:** User Management.

---

# Technical Debt

### TD-1 · `dpc_committee_members` has no `uuid` column

**Description.** Every other route-bound aggregate in this application binds on
`uuid` (commit *"address every aggregate by UUID"*). Committee members bind on the
sequential id, which is why the new `dpc_chairman_member_id` field on the allotment
form does too.

**Why it matters.** It is an inconsistency in a security-motivated convention. It is
not an exposure here — the value is only ever meaningful within a proposal the actor
is already authorised for, and it is validated against that proposal's own committee.

**Current Status.** Accepted deliberately on 2026-09-05 rather than adding a column
the day before a demonstration. The pre-existing member-replacement route already
binds by id.

**Suggested Action.** Add `uuid` to `dpc_committee_members` in the next schema
change, then move both routes onto it.
**Priority.** P3. **Related Module.** Committee · Security.

---

### TD-2 · Two OTP override knobs now exist

**Description.** `DPC_OTP_FAKE_CODE` (any environment, unset by default, used by the
test suite via `phpunit.xml`) and `DEMO_OTP` (local only, default `123456`).

**Why it matters.** Two settings for one concept eventually diverge.

**Current Status.** Deliberate — they have different scopes and different guarantees,
and merging them would have meant either weakening the environment gate or changing
what the test suite relies on, the day before a demonstration.

**Suggested Action.** After the freeze, consider folding `DPC_OTP_FAKE_CODE` into the
same environment-gated path.
**Priority.** P3. **Related Module.** Authentication · Configuration.

---

### TD-3 · `Mail::fake()` cannot observe `Mail::raw()`
See R-7. **Status:** worked around in tests. **Priority:** P3. **Module:** Testing.

### TD-4 · `storage/app/private/private/department-posts` — doubled path segment

**Description.** Department-post order documents are stored under a doubled
`private/private/` segment, suggesting the upload path is prefixed with the disk root
as well as the disk name.

**Why it matters.** Cosmetic today; it makes retention and backup scripting
error-prone.
**Current Status.** Observed 2026-09-02. Three orphan PDFs under it referenced
`department_posts` ids 10 and 11, which do not exist; they were removed with the
storage cleanup.
**Suggested Action.** Check the disk configuration when next touching document
storage.
**Priority.** P3. **Related Module.** Documents · Storage.

### TD-5 · `users` contains a duplicate person and two role-less records

**Description.** *Shri Sanjay Kumar Agrawal* appears twice (ids 10 and 25) — one with
an e-mail, one with only a mobile — both unverified, both role-less, both left over
from an earlier committee composition.

**Why it matters.** They inflate the account count and neither can sign in.
**Current Status.** Preserved deliberately: the instruction was that no user is
deleted.
**Suggested Action.** Decide after the freeze whether to merge or deactivate.
**Priority.** P3. **Related Module.** User Management.

---

# UAT Findings

### UAT-1 · Sanctioned Strength has no maintenance screen — verified 2026-09-06

**Description.** `department_posts.sanctioned_strength` exists, is fillable on the
model, is read by the vacancy engine, and is **mandatory** — but there is no field on
the create, edit, view, index or supersede screens, and **no validation rule** on
`DepartmentPostRequest` or `SupersedeDepartmentPostRequest`. The controller persists
`$request->safe()->…`, which returns validated data only, so even a crafted POST
cannot set it. No import writes it. The only writers anywhere are `forceFill()` calls
in seeders and tests.

**Why it matters.** With it `NULL`, `VacancyEngine` returns null totals and section
S16 never turns green — *"The Department Post Mapping for this post records no
Sanctioned Strength."* An incomplete S16 cannot be submitted, so **the DPC cannot
proceed at all**. Post id 1, *Teacher*, is in exactly that state now.

**It is an omission, not a regression.** Change register 2026-08-27 item 1 removed
the figure from the *DPC vacancy screen* deliberately — *"Sanctioned Strength shall
come from Department Post Mapping. The AO should not manually enter vacancy
figures"* — pinned by `test_the_sanctioned_strength_cannot_be_set_from_the_form()`.
But it was never added to the Department Post Mapping screen it was moved to.
`git log -S "sanctioned_strength"` over the admin request and views returns nothing:
it has **never** existed there in committed history. The field went from "typed in
the wrong place" to "typeable nowhere", and the code that reads it was written as
though the move had been completed.

**Current Status.** Verified, documentation only. Nothing changed. 8 of 9 posts carry
a value; 1 is `NULL`; all values came from `DemoDpcBuilder`, not from a screen.

**Suggested Action.** See R-5 and FE-5. Not before the freeze; DM-5 avoids it in the
demonstration.
**Priority.** **P1 next phase**, P2 for the demonstration.
**Related Module.** Department Post Mapping · Vacancy.

---

### UAT-2 · Notification bell empty without a worker
Recorded as KL-1. Found during the first end-to-end run: 20 jobs pending, 0
notifications, bell empty. **Status:** understood and documented. **Priority:** P1.

### UAT-3 · Self-Inspection could not match any officer
Recorded as R-6. Root cause was blank sample data, not a matching defect.
**Status:** fixed in sample data, verified. **Priority:** closed.

### UAT-4 · Approving a sitting result requires "Promotion as on (year)"
**Description.** `APPROVE BY CGPSC` fails with *"State the year the promotion is made
as on. It appears in the heading of the published result."* if the year box is left
blank.
**Why it matters.** It looks like a failure mid-demonstration; the field is easy to
miss beside the button.
**Current Status.** Correct behaviour, now in the demonstration script as an explicit
click.
**Suggested Action.** Enter `2026` before pressing the button.
**Priority.** P2. **Related Module.** Proceedings.

### UAT-5 · Circulation decisions are `consented` / `returned`, not `approved`
**Description.** The per-officer decision control posts `consented` or `returned`. A
payload of `approved` is silently ineffective, and the consent step then refuses
because not every held officer has been decided.
**Why it matters.** It cost a debugging cycle during the rehearsal.
**Current Status.** Working as designed; the buttons are correct.
**Suggested Action.** Use the on-screen buttons rather than constructing payloads.
**Priority.** Info. **Related Module.** Circulation.

### UAT-6 · A sitting may be opened before its scheduled date
**Description.** The DPC scheduled for 20-09-2026 was opened on 05-09-2026 without
complaint.
**Why it matters.** It looks wrong until the reason is known.
**Current Status.** Intended and configurable —
`dpc.proceeding.allow_open_before_scheduled_time` defaults permissive by architect
ruling AQ-5, because a strict default would make the system undemonstrable. The
actual opening time is always recorded.
**Suggested Action.** None; say so if asked.
**Priority.** Info. **Related Module.** Proceedings.

### UAT-7 · Superseding a mapping silently dropped its Sanctioned Strength

**Description.** `DepartmentPostMappingService::supersede()` built the replacement
row from `class_level`, `pay_scale_id`, `promotion_duration_years` and
`effective_from` — and not `sanctioned_strength`. A superseding Government Order
would therefore have set the establishment to `NULL`.

**Why it matters.** Superseding a mapping is the *normal* way a Government Order
changes an establishment, so this would have fired on exactly the operation it was
most wrong for — and would have broken every DPC on that post, silently.

**Current Status.** **Found and fixed 2026-09-06**, alongside the maintenance
screen. It was invisible until then because the field could not be set anywhere
either. The replacement now falls back to the incumbent's figure when the payload
omits it, and the supersede form pre-fills it. Pinned by an assertion in
`test_a_new_order_supersedes_the_mapping_without_changing_it`.

**Suggested Action.** None.
**Priority.** Closed (would have been P1).
**Related Module.** Department Post Mapping.

---

### UAT-8 · `tests/Unit/` fails when run after the Feature suites in one process

**Description.** `php artisan test tests/Feature/Admin/ tests/Feature/Dpc/ tests/Unit/`
reports 10 `MassAssignmentException` failures in
`PromotionHierarchyServiceTest`, which constructs
`new DepartmentPost(['name_en' => …])` — a column that is not fillable. The same
file passes on its own, and the full `php artisan test` run passes.

**Why it matters.** It reads as a regression when it is test-ordering sensitivity:
strict attribute mode (AD-18) is switched on by something in the Feature run and
persists into the Unit tests when the two are named in one explicit path list.

**Current Status.** **Pre-existing, not caused by any change of 2026-09-06.**
Confirmed: the file passes in isolation and the full suite passes 1,327/1,327.

**Suggested Action.** Run `php artisan test` (which uses the `phpunit.xml`
testsuite order) rather than naming directories explicitly. After the freeze, fix
the fixture to set `name_en` outside mass assignment.

**Priority.** P3.
**Related Module.** Testing.

---

### UAT-9 · The portal has three CSV importers, not fifteen

**Description.** A request for sample CSVs covering fifteen modules — department
registration, users, posts, recruitment rules, committee members, CR/APAR, property
returns, integrity, punishment — met an application that has **exactly three**:
S16 Currently Working Officers, S17 Final Seniority List, S18 Evaluation Chart.
Confirmed from `ImportTemplate` (three cases) and `route:list` (one import route
group).

**Why it matters.** Generating CSVs for importers that do not exist produces files
nobody can use and a demonstration that stalls when somebody tries. CR, property,
loyalty and tribal service are **columns of S18**, not separate files. Punishment
and disciplinary records are **not modelled in this build at all**.

**Current Status.** Three real importable CSVs generated and verified; the typed
screens got **reference data sheets** in `sample-data/reference/` with a README
stating plainly that they are not uploads.

**Suggested Action.** None. If a master importer is ever wanted, it is a feature
request, not a gap.

**Priority.** Info.
**Related Module.** Imports · Demo Data.

---

### UAT-10 · A passing score can still be Not Eligible, and that surprises people

**Description.** Two of the ten sample candidates score **13** against a qualifying
score of **10** and are still refused, because a `घ` grade anywhere in the five-year
scoring window is a hard gate that runs before the total is considered.

**Why it matters.** Presented without explanation it looks like an arithmetic bug.
It is `EligibilityService::applyDisqualifyingGradeGate()`, and **Explain Result**
names the year and the grade.

**Current Status.** Correct behaviour, deliberately built into the sample data so
it can be demonstrated rather than met by accident.

**Suggested Action.** Press **Explain** on SN 5 during the walkthrough.

**Priority.** Info.
**Related Module.** Eligibility · Demo Data.

---

### UAT-11 · Two demo documents carried stale row counts after the CSVs were regenerated

**Description.** The sample CSVs grew from 9 candidates to 10 on 2026-09-06, but
`CURRENT_DEMO_FLOW.md` (9 places) and `DEMO_DATA_GUIDE.md` (7 places) still said
*9 officers · 53 CR grades · 54 loyalty · 54 property* and
*"9 rows committed"*.

**Why it matters.** A presenter reading "9" while the screen says "10" looks like the
import went wrong. It is the ordinary failure mode of regenerating data without
re-reading every document that quotes it.

**Current Status.** **Corrected 2026-09-06.** The flow was regenerated from the
implementation; the data guide's seven figures were corrected in place. A grep for
`9 data rows|9 officers|53 CR|54 loyalty|CG200001–CG200009` across `docs/demo/`
now returns nothing.

**Suggested Action.** When sample data changes, grep the docs for the old counts
before declaring it done.

**Priority.** Closed.
**Related Module.** Documentation · Demo Data.

---

### UAT-12 · CSV post names are free text, not checked against the proposal

**Description.** `promotion_to_post` and `promotion_from_post` on all three importers
are `nullable|string|max:255` in `DpcRowRules`. They are snapshot text, never
compared with the proposal's own posts. A working-officers file naming
*Block Education Officer* imported into a DPC for *Deputy Director, Education*
reported **Total 4 · Valid 4 · Rejected 0 — with no warning of any kind.**

**Why it matters.** The wrong post names would reach the evaluation chart and the
signed Proceedings and Promotion List documents. The arithmetic is unaffected — the
engine uses the proposal's post, not the CSV text — so nothing fails loudly.

**Current Status.** Verified 2026-09-06. **Not fixed** — validating it is a business
decision (a department correcting a post name mid-exercise would otherwise be
refused), and the freeze is in force.

**Suggested Action.** Follow the demonstration script and do not create a Department
Post live. After the freeze, consider a **warning** on the preview screen when a row's
post names differ from the proposal's — a warning rather than a refusal, so a
legitimate rename is not blocked.

**Priority.** P3 for the demonstration, **P2 for production**.
**Related Module.** Imports · Documents.

---

### UAT-13 · The "13 notifications" figure was stale

**Description.** Three documents stated the sitting announcement reaches **13**
people. That figure dated from the 9-candidate sample CSVs. With the current
10-candidate data it is **14** — 4 committee members plus 10 candidates — and the
delivery log reads `{"sms":4,"email":4,"unreachable":10,"recipients":14}`.

**Why it matters.** A presenter quoting 13 while the bell shows 14 undermines the
figures they have just been asked to trust.

**Current Status.** **Corrected 2026-09-06** in `CURRENT_DEMO_FLOW.md`,
`SAMPLE_DATA_GUIDE.md` and this file. Documentation only.

**Suggested Action.** Same lesson as UAT-11: when sample data changes, grep the docs
for every count it feeds.

**Priority.** Closed.
**Related Module.** Documentation · Notifications.

---

### UAT-14 · `demo-working-officers.csv` named the wrong post pair

**Description.** The sample working-officers file was found naming
*Additional Director, Education* ← *Block Education Officer*, while the Seniority
List and Evaluation Chart both named *Block Education Officer* ← *Head Master,
Middle School*. Its timestamp was 20:53 against 18:22 for the other two, so it had
been altered after generation.

**Why it matters.** The S16 chart would have shown a completely different post pair
from S17 and S18 during the demonstration — and because CSV post names are free text
(UAT-12) it imported **4 valid, 0 rejected, with no warning**. Nothing in the
application could have caught it.

**Current Status.** **Repaired 2026-09-06.** All three files realigned; verified by
re-importing all three and confirming the stored snapshots agree, with the
calculation unchanged at Main 3 · Waiting 3 · Not Eligible 4. A copy of the corrupt
file is in the session scratchpad.

**Suggested Action.** This is the strongest argument for adding the import warning
proposed in UAT-12 — the corruption was invisible to the software and was caught only
by comparing three files by hand.

**Priority.** Closed (repaired). Raises the priority of UAT-12.
**Related Module.** Demo Data · Imports.

---

# Verification Results

### VR-1 · Business flow chain — verified 2026-09-06

| Link | Verified | Evidence |
|---|---|---|
| AO creates Pre-DPC | ✅ | `role:authorized_officer` on `/pre-dpc/*` |
| AH approves | ✅ | `dpc.workflows.pre_dpc.approve` — `role: administrative_head`, `signature: true` |
| System **creates** DPC | ❌ | AO raises it — **BG-1 / FBE-001 §3** |
| System **routes** to CGPSC Chairman | ✅ | allotment queue filters `status=approved AND forwarded_at NOT NULL` |
| Chairman assigns allottee + DPC Chairman + deadline | ✅ | `AllotDpcRequest` — all three `required` |
| Allottee schedules | ✅ | `SchedulingService::assertAllottee()` |
| Meeting date ≤ deadline | ✅ | `assertWithinCompletionDate()` + `BoundsTheSittingByTheDeadline` |
| Notifications | ✅ | `DpcSittingScheduled::dispatch()` on schedule and reschedule |
| DPC conduct | ✅ | `dpc_proceeding.open` from `scheduled` |

**Current Status.** Eight of nine links as described; the ninth is documented, not
defective. **Priority:** Info. **Module:** Workflow.

---

### VR-2 · Committee minimum — verified unchanged 2026-09-06
`config/dpc.php` `min_members = 3`, `max_members = 6`, both meaning members
**besides** the Chairman. Official rule **Chairman + 3** intact. No change made.
**Priority:** Info. **Module:** Committee.

---

### VR-3 · Local OTP — verified 2026-09-06
`APP_ENV=local` issues `DEMO_OTP`; a wrong code is still refused; production issues a
random code even with `DEMO_OTP` set. Three tests: `test_the_demo_otp_is_issued_in_local`,
`test_a_wrong_code_is_still_refused_in_local`,
`test_production_ignores_the_demo_otp_and_issues_a_random_code`.
**Priority:** Info. **Module:** Authentication.

---

### VR-4 · Full suite — 2026-09-05
**1,319 tests, 4,372 assertions, all passing.** `pint` clean · `phpstan` clean at the
configured level · `php artisan lang:check` complete in both locales.
**Priority:** Info. **Module:** Quality gate.

---

### VR-5 · Screens render on an empty database — 2026-09-05
25 admin screens, 9 Authorized Officer screens, 4 Administrative Head screens, 6
Chairman screens, 6 Member screens and 6 public screens all returned **200** against
the cleaned database. All 9 demo logins verified by real `POST /login`, by e-mail and
by mobile. **Priority:** Info. **Module:** All.

---

### VR-6 · Sanctioned Strength — verified 2026-09-06
Full evidence in UAT-1 and in the Miscellaneous section below.
**Priority:** Info. **Module:** Department Post Mapping.

---

### VR-7 · Sanctioned Strength maintenance — verified live 2026-09-06

The mandatory verification chain, performed through the Admin UI over HTTP:

| Step | Result |
|---|---|
| 1–3 · Create a Department Post with Sanctioned Strength 5 | `302` · DB row `sanctioned_strength = 5` |
| 4–5 · Edit and update to 9 | `302` · DB row `sanctioned_strength = 9` |
| 6 · Confirm the database | `SELECT` returns 9 |
| 7 · Vacancy Engine reads the mapping | returns **9** — the edited value |
| 8 · Confirm no seeder required | the row was created and edited entirely through the screens |

Screens confirmed rendering: **List** (column + "Not recorded" badge on *Teacher*),
**Create** (`<input type="number" id="sanctioned_strength" required min="1">`),
**View** (value, and the explanation when absent), **Supersede** (pre-filled).
The probe row was removed afterwards and the baseline restored.

**Priority:** Info. **Module:** Department Post Mapping · Vacancy.

---

### VR-8 · Full business chain — verified live 2026-09-06

Sixteen steps, end to end, against the running application:

| # | Step | Result |
|---|---|---|
| 1 | AO creates Pre-DPC | `PDPC-2026-SE-0001` |
| 2 | Before approval, no order offered to raise a Final DPC | create screen offered none |
| 3 | AH approves the Pre-DPC | `302` · Recruitment Rule v1 projected |
| 4 | **Only now** is the order offered | `PDPC-2026-SE-0001` appears |
| 5 | AO raises the Final DPC | `DPC-2026-SE-0001` |
| 6 | Committee — Chairman + 3, one social category each | `302` |
| 7 | Three section uploads | `302` each |
| 8 | AO submits the Final DPC | `302` |
| 9 | **AH approves the Final DPC** (second approval) | `302` |
| 10 | **Routed automatically** to the CGPSC Chairman | appears on his queue unasked |
| 11 | Chairman assigns allottee + DPC Chairman + deadline | allottee user 3 · presiding *Smt. Sunita Rathore* · due 30-09-2026 |
| 12 | Allottee accepts | `302` |
| 13 | Meeting date **after** the deadline | refused — *"must be concluded by 30-09-2026…"* |
| 14 | Meeting date **on or before** | `302` — 25-09-2026 accepted |
| 15 | Notifications | **13** — 4 by SMS + e-mail + in-app, 9 in-app only |
| 16 | Deadline expiry returns the DPC | dry run listed `DPC-2026-SE-0001`, 1 day over |

Step 11 deliberately made the **DPC Allottee and the DPC Chairman different people**,
which the business states is valid.

**Priority:** Info. **Module:** Workflow.

---

### VR-13 · DPC-MODULE-FIX-001, Post master audit — 2026-09-06

Full audit of every screen, form, filter, report, document and importer that touches
a Post, written up as **`docs/uat/DPC_MODULE_FIX_001_POST_MASTER_AUDIT.md`**.

**Found:** four free-text Post inputs across two screens — Working Officers (S16) and
Seniority List (S17) row templates. Everything else (9 foreign keys, 5 admin masters,
21 report/document references, every filter) was already using the Department Post
master.

**Fixed:** the four inputs are now `<select>` elements populated from
`DepartmentPost::inForce()`; the form posts a master **id**; `DpcProposalService`
resolves it to the master's name for the existing varchar snapshot. **No schema
change** — the officer snapshot deliberately keeps a name so a concluded proposal
cannot change when a master is edited.

**Backward compatible:** the three CSV importers still accept post *names*, because a
department filling in a spreadsheet has no access to primary keys. Documented as a
justified exception with three options for the business.

Verified: 9 master options render on both screens · posting id 5 stores
*"Block Education Officer"* · all three imports still 4/10/10 · calculation unchanged
· round-trip re-select works · **zero free-text post inputs remain**.
1,327 tests pass; pint and phpstan clean.

**Priority:** Info. **Module:** Masters · DPC Proposal · Imports.

---

### VR-12 · Full demo verification — 2026-09-06

An instrumented end-to-end pass with `SELECT` evidence between steps, written up as
**`docs/demo/DEMO_VERIFICATION_REPORT.md`**. Covers: Recruitment Rule generation
(created *only* at AH approval — `submitted` 0 rules → `approved` 1 rule, 1 source,
3 qualification links, 1 signature), the Vacancy Engine (every input, calculation and
output, with the recorded `inputs` JSON), Working Officers (all 17 fields and their
effect on the vacancy), the Evaluation Chart (35 columns, CR provenance, the 5-year
window, Hindi grading, the `घ` gate, the score formula), Committee validation (8
rules, Chairman, members, attributes), Promotion Candidate (provisioning, password,
login, Self-Inspection), all 16 reports, and the 22-step business flow with screen,
controller, service, tables, notifications and rule for each.

**Verdict: GO.** No code defect found. Two documentation defects found and corrected
(UAT-12 logged, UAT-13 corrected). Ten residual risks listed, none blocking.

**Priority:** Info. **Module:** All.

---

### VR-11 · `CURRENT_DEMO_FLOW.md` regenerated — 2026-09-06

Rebuilt from the running implementation against the required 13-section structure,
replacing a 1,322-line document that predated the 10-candidate sample data. All 13
sections present; 982 lines.

**Facts re-verified against the code and the database rather than the old document:**
committee minimum 3 besides the Chairman · qualifying score 10 · disqualifying grade
`घ` · `DEMO_OTP` 123456 · scheduled command registered · 9 sign-in accounts of 34 ·
**three** CSV importers · reports enumerated from `ReportController` and `route:list`
· role permissions from the `role:` middleware groups.

**Two stale-figure defects found and corrected during the review** — see UAT-11.

`docs/demo/` now holds exactly one flow document; four superseded ones are in
`docs/archive/2026-09-05-superseded/`.

**Priority:** Info. **Module:** Documentation.

---

### VR-10 · Sample import data — generated and verified 2026-09-06

Three CSVs generated from the importers' own `expectedHeaders()`, imported into the
running application, committed, and the calculation run:

| File | Rows | Valid | Rejected | Committed |
|---|---|---|---|---|
| `demo-working-officers.csv` | 4 | 4 | 0 | ✅ |
| `demo-seniority-list.csv` | 10 | 10 | 0 | ✅ |
| `demo-evaluation-chart.csv` | 10 | 10 | 0 | ✅ |
| `demo-evaluation-chart-with-hold.csv` | 10 | 10 | 0 | ✅ |

**Derived:** sanctioned 4 · working 3 · **total vacancy 3** (UR 1, OBC 1, SC 1) ·
60 CR grades · 60 loyalty · 60 property.

**Calculated:** 10 assessed · 6 eligible · **Main 3 · Waiting 3 · Not Eligible 4** —
the distribution the business asked for, designed rather than stumbled on: 2 refused
on a `घ` grade, 2 on score.

The hold variant was confirmed to leave SN 4 with 5 property years instead of 6,
which is what the sitting detects as a hold.

Database returned to the clean baseline afterwards; the tenth candidate account
(`CG200010`) was removed so it is provisioned live during the demonstration.

**Priority:** Info. **Module:** Imports · Eligibility · Demo Data.

---

### VR-9 · Quality gate — 2026-09-06
**1,327 tests, 4,408 assertions, all passing** · `pint` clean · `phpstan` clean ·
`lang:check` complete in both locales. 8 test cases added.
**Priority:** Info. **Module:** Quality gate.

---

# Pending Clarifications

### PC-1 · Where does a candidate's mobile number come from?
Adding a column to the Seniority List template changes what every department uploads;
taking it from HRMS makes candidate notification depend on `FEATURE_HRMS`; collecting
it from the officer needs a screen they cannot reach without the number.
**Status:** open — `FBE-002 §4`. **Priority:** P2. **Module:** Seniority List.

### PC-2 · Is a candidate's mobile number evidence or contact detail?
The ten-field snapshot is deliberately a point-in-time service record. A contact
number changes and is not part of what the Commission decided on, so it may belong
beside the snapshot rather than in it.
**Status:** open — `FBE-002 §4`. **Priority:** P2. **Module:** Seniority List.

### PC-3 · Does OTP login replace the employee-number password or sit beside it?
Already framed as `BD-19`, which names three drivers: `identity_factors` (default),
`otp`, `account`.
**Status:** open. **Priority:** P2. **Module:** Authentication.

### PC-4 · ~~Who fills the DPC's five sections if the DPC exists from approval?~~
**Status:** **moot 2026-09-06** — `FBE-001 §3` is withdrawn, so the DPC never exists
before the department raises it. **Priority:** Closed. **Module:** DPC Proposal.

### PC-5 · What does "participates in the evaluation" mean concretely?
Whether each member records their own marks or the committee records one agreed set
decides whether the evaluation tables gain a per-member dimension.
**Status:** open — `FBE-001 §4`. **Priority:** P3. **Module:** Evaluation.

### PC-6 · Does the DPC Chairman's approval alone conclude the sitting?
`committee_configurations` already carries `quorum`, `voting_method`, `majority_type`
and `tie_break_rule`, and only the first is consulted today.
**Status:** open — `FBE-001 §4`. **Priority:** P3. **Module:** Proceedings.

### PC-7 · Is the "default CGPSC Chairman" always the serving Chairman on record?
Or a separately configured default that may differ.
**Status:** open — `FBE-001 §4`. **Priority:** P3. **Module:** Committee.

### PC-8 · ~~What replaces the completion deadline and re-allotment under `FBE-001`?~~
**Status:** **moot 2026-09-06** — the alignment keeps the completion deadline and the
return-to-Chairman remedies exactly as built. **Priority:** Closed. **Module:** Allotment.

### PC-9 · Should the *Teacher* mapping be given a Sanctioned Strength?
It is the one pre-2026-08-27 row still carrying `NULL`, so no DPC can be raised on
Teacher. The figure is a Government Order's to state, not a developer's to invent.
**Status:** open — needs the establishment figure from the department.
**Priority:** P2. **Module:** Department Post Mapping.

---

# Miscellaneous

## Artisan commands used or referenced in this session

| Command | Purpose | Note |
|---|---|---|
| `dpc:reset-transactional-data [--dry-run] [--force]` | Clear all workflow data, keep every master and user | **Refuses in production.** Every table must appear in one of its two lists or it refuses to run |
| `dpc:return-expired-allotments [--dry-run] [--as-of=]` | Return overdue DPCs to the CGPSC Chairman | Idempotent. Scheduled daily 00:15 — see DN-1 |
| `schedule:list` | Prove a scheduled command is registered | The check R-4 exists for |
| `lang:check` | Missing / extra / identical keys per locale | Currently complete; one identical key, `ui.layouts.portal.brand_title` |
| `security:evidence-grants --verify` | Probe that evidence tables refuse writes | See DN-3 |
| `schema:verify-mysql` | Build every migration on MySQL in a throwaway database | In the quality gate |
| `security:audit` | `composer audit` with retry | In the quality gate |
| `qualifications:unmapped [--fix]` | Rule rows whose qualification text matches no master | — |
| `notifications:replay --dry-run` | What a ledger replay *would* send | Sends nothing |
| `demo:seed` | Rebuild the demonstration database | Refuses in production |
| `demo:credentials` | **MUTATES** — see R-1 | **Do not run** |
| `queue:work` | Required — see KL-1, DN-2 | — |
| `serve --port=8001` | The application — see KL-9 | — |

## Queue and cron requirements
- **Queue:** KL-1 (demonstration), DN-2 (production).
- **Cron:** DN-1 — `schedule:run` every minute, or the scheduler is inert.

## Seeder dependencies
- `DemoDpcBuilder` is the **only** writer of `sanctioned_strength` (UAT-1) — the live
  values exist because of it.
- `DemoChart::postedOnFeederPost()` is the convention the sample seniority list now
  follows for `date_of_posting_on_promotion_to_post` (R-6).
- `Tests\Concerns\SeatsDpcCommittees` seats a minimal committee for tests that must
  allot but are not about committees; it writes rows directly rather than calling
  `DpcCommitteeService::compose()`, which would also require a `cgpsc_chairmen`
  master the test never mentions.

## Migration notes
- **No schema change** was made on 2026-09-05 or 2026-09-06. Every field involved
  already had a column.
- `2026_08_27_410001` introduced `department_posts.sanctioned_strength` and
  backfilled it from `dpc_proposals.sanctioned_post_count`. `sanctioned_post_count`
  is **kept deliberately** as a snapshot: a concluded DPC ran against the strength
  recorded at the time, and reading it live would let a mapping edited next year
  change what a signed proposal says it was calculated from.
- `migrations` table holds **81** rows; `migrate:status` reports 0 pending.

## Security notes
- The **audit trail is read-only by design** — no create, update, delete or purge
  route exists, because a record editable from the application that writes it is not
  an audit record. Retention is permanent by ruling (Decision 3, 2026-08-13).
- **CSP is enforced.** A button that does nothing with no error is almost always an
  inline handler the policy refused. `ViewsAreCspCleanTest` catches it; the fix is
  never `'unsafe-inline'`. Both new JS files added this session are external.
- **Spreadsheet formula injection** is neutralised on the audit export and the import
  error report — a cell beginning `=`, `+`, `-` or `@` is a formula to Excel.
- **Candidate scoping is by employee number, derived once**, and every action
  re-derives from it. A candidate who types another proposal's UUID gets a **404**,
  not a 403 — a 403 would confirm the proposal exists.
- `DEMO_OTP` is gated on `app()->environment('local')`, not on a config flag,
  precisely so it cannot be switched on by a copied `.env` (KL-2).

## Performance notes
- The allotment screen loads each proposal's committee with **one grouped query**,
  matching the existing pattern for allotments and schedules. An N+1 there would grow
  with the Commission's workload rather than with anything a test would notice.
- `dpc.notifications.in_app.unread_count_cache_seconds` defaults to **0 (off)**
  deliberately: a cached count made the DPC detail screen issue a different number of
  queries on its second render, producing permanent false positives in the one test
  that can catch a real N+1 regression on the heaviest screen in the system.

## Configuration notes
| Key | Default | Note |
|---|---|---|
| `DEMO_OTP` | `123456` | **local only** (KL-2, VR-3) |
| `DPC_OTP_FAKE_CODE` | unset | test suite sets it in `phpunit.xml` (TD-2) |
| `DPC_COMMITTEE_MIN_MEMBERS` / `MAX` | 3 / 6 | besides the Chairman (VR-2) |
| `DPC_SELF_INSPECTION_FACTORS` | `name,date_of_posting_on_promotion_to_post,dob` | three factors, not the deck's two, because the screen shows CR grades and property compliance (R-6) |
| `DPC_SELF_INSPECTION_CLOSE_AFTER_RESULT` | `true` | KL-5 |
| `DPC_SELF_INSPECTION_AUTH_DRIVER` | `identity_factors` | `otp` refuses with BD-19 (FE-4) |
| `dpc.proceeding.allow_open_before_scheduled_time` | permissive | UAT-6 |
| `DPC_IMPORT_MAX_FILE_KB` / `MAX_ROWS` | 5120 / 500 | uploads |
| `dpc.vacancy.period` | 01-01 to 30-11 of the DPC Year | derived from the year, never from today |
| `DPC_IMPORT_LEGACY_HEADERS_UNTIL` | unset | **no cutoff decided** — the date belongs to the Commission |

## Commands executed in the 2026-09-06 session

```bash
php artisan test                                   # 1,327 passed
php artisan test tests/Feature/Admin/ …            # subset — see UAT-8
./vendor/bin/pint  /  --test                       # style
./vendor/bin/phpstan analyse --memory-limit=1G     # static analysis
php artisan lang:check                             # catalogue
php artisan schedule:list                          # scheduler registration
php artisan dpc:return-expired-allotments --as-of=2026-10-01 --dry-run
php artisan dpc:reset-transactional-data --force   # restore the demo baseline
php artisan optimize:clear / serve --port=8001 / queue:work
```

## Configuration added on 2026-09-06

| Key | Value | Scope |
|---|---|---|
| `DEMO_OTP` | `123456` | **`APP_ENV=local` only** — gate is `app()->environment('local')`, not the key itself |

Present in `.env` and `.env.example`, both with the note that setting it on a UAT or
production host does nothing.

## Sample data — what exists and what cannot

| File | Rows | Importer |
|---|---|---|
| `demo-working-officers.csv` | 4 | **S16** |
| `demo-seniority-list.csv` | 10 | **S17** |
| `demo-evaluation-chart.csv` | 10 | **S18** |
| `demo-evaluation-chart-with-hold.csv` | 10 | **S18** (Circulation variant) |
| 3 PDFs | — | Recruitment Rule GO · Department Post order · missing-record evidence |
| `reference/REFERENCE-*.csv` (8) | — | **not uploads** — data sheets to type from |

There is **no importer** for departments, users, department posts, recruitment
rules, committee members, CR/APAR, property returns, integrity certificates or
punishment records (UAT-9).

## Documentation set — current and authoritative

| Document | Purpose |
|---|---|
| `docs/demo/DEMO_USERS.md` | all 34 accounts, grouped by role and department |
| `docs/demo/CURRENT_DEMO_FLOW.md` | 25 steps, each with purpose, login, navigation, clicks, validation, expected result |
| `docs/demo/DEMO_DATA_GUIDE.md` | all six upload screens, columns, row counts, expected results |
| `docs/demo/SAMPLE_DATA_GUIDE.md` | 18 steps mapping each CSV to its screen and expected result |
| `docs/demo/sample-data/` | 7 files — 4 CSV, 3 PDF — plus `reference/` (8 sheets + README) |
| **`docs/uat/TERMINAL_SUGGESTIONS.md`** | **this file — the running log** |
| `docs/FUTURE_BUSINESS_ENHANCEMENTS.md` | `FBE-001`, `FBE-002` |
| `docs/CHANGE_REGISTER_2026-09-05_REPORT.md` | what was built on 2026-09-05 |

**Archived 2026-09-05** to `docs/archive/2026-09-05-superseded/` with a README:
`DEMO_FLOW.md`, `DEMO_CHEAT_SHEET.md`, `UPLOAD_FILES_GUIDE.md` — all three described
the deleted seeded database (`SE-0001`…`SE-0009`) and would have misled the
presenter. `docs/demo/` now holds exactly one authoritative version of each document.

---

## Sanctioned Strength — full verification evidence (2026-09-06)

Recorded here in full because it was requested in the terminal and must not live only
in scrollback. Summary in UAT-1; action in R-5 and FE-5.

**Department Post Mapping screen — exists.**

| | |
|---|---|
| Menu path | Administration → **Department Posts** (`layouts/admin.blade.php:53`) |
| Routes | `admin.department-posts.{index,create,store,show,edit,update,destroy}` plus `.supersede.form`, `.supersede`, `.documents.download` |
| Controller | `app/Http/Controllers/Admin/DepartmentPostController.php` |
| Model | `app/Domain/Master/Models/DepartmentPost.php` |
| Table | `department_posts` (renamed from `cadres` by `2026_08_24_360001`) |
| Views | `resources/views/admin/department-posts/{index,form,show,supersede}.blade.php` + `partials/order-row.blade.php` |
| Stack | Blade + Bootstrap. No Livewire, no Vue, no React |

**Sanctioned Strength field — missing everywhere in the UI.**

| Surface | Result |
|---|---|
| Create screen | **NO — Missing** (`form.blade.php` renders `department_id`, `name`, `class_level`, `pay_scale_id`, `promotion_duration_years`, `effective_from`, `status`, order rows) |
| Edit screen | **NO — Missing** (same Blade) |
| View screen | **NO — Missing** (not even displayed) |
| Index / list | **NO — Missing** |
| Supersede screen | **NO — Missing** |
| Validation request | **NO — Missing** in `DepartmentPostRequest` and `SupersedeDepartmentPostRequest` |
| Controller save | **NO** — persists `$request->safe()->except(ORDER_FIELDS)`; `safe()` returns validated data only, so the key can never be present |
| Import / export | **NO** — no import template for Department Posts; `ExcelImportService` only *reads* the value (line 365) |
| Language keys | **NO** — no `admin.department_posts.form.*` key for it |

**Database — column exists.**

| Table | Column | Type | Nullable |
|---|---|---|---|
| `department_posts` | `sanctioned_strength` | `int unsigned` | YES |
| `dpc_proposals` | `sanctioned_post_count` | `int unsigned` | YES (snapshot) |
| `dpc_vacancy_calculations` | `sanctioned_strength` | `int unsigned` | NO (append-only record) |

**Current data — 9 posts, 8 populated, 1 NULL** (id 1, *Teacher*). All rows created
`2026-09-02 02:20:01` by the demo seeder.

**Source of values — Seeder.** `DemoDpcBuilder.php:86` —
`$toPost->forceFill(['sanctioned_strength' => …])->save()`. The migration also
contains a backfill (`410001:120-121`) from the old per-proposal figures, but the
present rows postdate it and came from the seeder. Not Admin UI, not manual SQL, not
import.

**Business impact — it does not block creating a Department Post; it blocks using
one.** The post saves with `sanctioned_strength = NULL`. The consequence appears
later: `VacancyEngine::calculate()` returns null totals, S16 stays incomplete with
*"The Department Post Mapping for this post records no Sanctioned Strength."*, and
the DPC cannot be submitted — from any screen, by any role.

**Final answer**

| Question | Answer |
|---|---|
| Department Post Mapping Exists | **YES** |
| Sanctioned Strength UI Exists | **NO** |
| Database Column Exists | **YES** |
| Current Values Come From | **Seeder** (`DemoDpcBuilder`) |
| Safe for Demo | **YES** — provided no Department Post is created live (DM-5) |
| Implementation Required | **YES** — but **not before the freeze** (R-5, FE-5) |

### UAT-15 · Committee and Chairman designation was free text — fixed, and this audit's own finding overruled

**Priority:** P2 · **Status:** CLOSED 2026-09-06 · **Cross-reference:**
`docs/uat/DPC_MODULE_FIX_001_POST_MASTER_AUDIT.md` §4.3

**What was said in the terminal.** During DPC-MODULE-FIX-001 the audit classified
`members[n][designation]` as *"NOT A POST — free text and stays so"*, on the reasoning
that a person's designation is not a Department Post and that binding it to the post
master would prevent seating an external member.

**Business overruled it the same day:**

> *"OK i told to make everything from master but when i am creating committe member
> Designation is free text but it should be from post master only it is still not
> fixed"*

**What was fixed.** Three screens that accepted a typed designation now post a master
id and store the master row's name:

| Screen | Field before | Field now |
|---|---|---|
| DPC Committee — composition | `members[n][designation]` *(text)* | `members[n][designation_id]` *(select)* |
| DPC Committee — substitute a member | `designation` *(text)* | `designation_id` *(select)* |
| CGPSC Chairman — appoint / amend | `designation` *(text)* | `designation_id` *(select)* |

**The one judgement call, stated openly.** The dropdown reads the **Designation
master** (`designations`), not `department_posts`. Binding it to `department_posts`
literally would have made the demo committee unrecordable: that table holds one
department's promotable cadre — nine rows for School Education — and the ranks the
committee actually seats (*Member, CGPSC* · *Secretary, School Education* · *Deputy
Secretary, GAD* · *Chairman, Chhattisgarh Public Service Commission*) belong to no
department's cadre and are in none of them. `designations` already carries every one
of those **and** every Department Post name, and is the master `users.designation_id`
points at.

**If the business wants `department_posts` instead**, it is a one-line change per
screen — but the Designation master would have to be merged into it first, and a DPC
committee would then be limited to a single department's cadre. Recorded here rather
than decided unilaterally.

**No schema change.** The three columns still store the designation **name** as a
`varchar`. That is the same snapshot principle the officer tables follow: a committee
that has already sat, and a proceeding that cites it, must not change because somebody
later renames a master row. The id is what the screen posts; the name is what the
record keeps.

**Master rows added.** `Chairman, Chhattisgarh Public Service Commission`,
`Secretary, School Education` and `Deputy Secretary, GAD` were absent from the master
— the dropdown could not have offered what the demo script asks the presenter to pick.
Seeded by `Demo\DemoAccountSeeder::seedDesignations()` and inserted into `dpc_dev`
through the model. Master now holds **26** active rows.

**Demo note.** On the Committee screen the presenter now *selects* a designation
instead of typing it. The four demo committee designations are all present in the
list. Nothing else about the screen changed.

**Tests added — 9.** Precedence (id beats typed text), rejection of an id outside the
master, backward compatibility with an old free-text payload, and a render assertion
on each of the three screens including `assertDontSee` on the old text input — because
adding a dropdown while leaving the text box in place would have passed every other
assertion.

---

### UAT-16 · Qualification selection was a ctrl-click list, and the admin one was wired to the wrong enhancement

**Priority:** P2 · **Status:** CLOSED 2026-09-06

**What business asked for:**

> *"when we are selecting qualification make it like Drop down inside a check box and
> searchable multiselect do not remove anything just fix this"*

**What was there.** Both feeder grids rendered a native `<select multiple size="4">` —
a four-row scrolling list requiring ctrl-click. One mis-click silently discards every
other selection, and on these screens that edits a **recruitment rule**.

**A real defect found while fixing it.** The admin copy carried
`data-searchable`, which is `public/js/searchable-select.js` — the **single-value**
enhancement. It assigns `select.value`, and on a `multiple` select that collapses the
answer to one option. **A feeder that accepts three qualifications could not be
recorded through that screen**, and nothing failed: the form still submitted, with one
qualification instead of three. Removed; the marker is now `data-multiselect`, and the
test asserts the wrong attribute is *absent*, not merely that the right one is present.

**What was built.** `public/js/multiselect-dropdown.js` — a dependency-free
progressive enhancement over any `<select multiple data-multiselect>`. It renders a
dropdown whose panel holds a filter box and one checkbox per option, with the toggle
showing the chosen names (or a count past two).

**Nothing was removed, as instructed.** The `<select multiple>` is still there and is
still the source of truth — same `name="…[qualification_ids][]"`, same options, same
`@selected` state, same validation, same posted array, same `@error` blocks, same hint
text. With the script blocked the field degrades to exactly the control it was before.

**CSP-clean.** External file, every listener attached in JavaScript, no inline
handlers — the portal enforces `script-src 'self'`.

**Applies to:** Pre-DPC → Promotion From Post grid, and Admin → Recruitment Rules →
feeder grid. Both add rows dynamically; a `MutationObserver` enhances new rows, and
neither grid re-serialises existing ones, so an enhanced row is never rebuilt.

**Bilingual.** Four new `ui.multiselect_*` keys in `lang/en` and `lang/hi`, passed to
the script as `data-*` attributes — a government portal may not show an English string
on a Hindi page.

---

---

*End of log. Append, do not replace. When a terminal session prints a recommendation,
warning, limitation, gap or verification result, it is added here in the same
session.*
