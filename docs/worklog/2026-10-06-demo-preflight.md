# Demo pre-flight, run on the day — 2026-10-06

**Asked:** what to do for today's demonstration.
**Done:** every state precondition in `docs/demo-kit/README.md` was re-verified against
the live `dpc_dev`, one day after the 2026-10-05 reset. **Nothing had drifted.**
**Outstanding:** two processes to start. Nothing else.

---

## 1 · Baseline, verified today

| Check | Kit expects | Found | |
|---|---|---|---|
| Accounts | 9 | **9**, all `Demo@2026` verified by `Hash::check`, all active | ✅ |
| Roles | one per account | admin · chairman · member×5 · AO · AH | ✅ |
| Recruitment Rules | **0** | **0** — §5's punchline is intact | ✅ |
| `dpc_proposals` / `pre_dpc_proposals` | 0 / 0 | 0 / 0 | ✅ |
| `import_batches` | 0 | 0 | ✅ |
| `audits` / `activity_timelines` | 0 / 0 | 0 / 0 | ✅ |
| `dpc_id_sequences` | 0 | 0 — first IDs will be `PDPC-2026-SE-0001` / `DPC-2026-SE-0001` | ✅ |
| Stored files under `private/` | 0 | 0 (only `.gitignore`) | ✅ |
| Departments registered | 1 | 1 — School Education only | ✅ |
| Department Posts | 9 | 9, all active | ✅ |
| **Additional Director, Education — sanctioned** | **10** | **10** — the one number the vacancy of 4 rests on | ✅ |
| Block Education Officer | Class II, sanctioned 4 | Class II, 4 | ✅ |
| CGPSC Chairman | one active | Dr. Ajay Kumar Shrivastava, `active_flag=1`, since 02-09-2026, linked to user 2 | ✅ |
| Committee configurations | present | 2 | ✅ |
| Upload files | 7 in `demo-kit/uploads/` | 7 | ✅ |

## 2 · Infrastructure

| | |
|---|---|
| MySQL `127.0.0.1:3306` | listening, `dpc_dev` reachable |
| Redis `127.0.0.1:6379` | listening; `cache()->put/get` round-trips **ok** (`redis-cli ping` returns `NOAUTH` — the app authenticates, the bare CLI does not; not a fault) |
| Cache / session / queue driver | `redis` on all three |
| `DPC_ALLOW_EARLY_PROCEEDING` | **true** — §16 can open the 22-12-2026 sitting today |
| Laravel | 13.23.0 |

## 3 · The two things not yet done

Neither is a defect; both are processes that are simply not up.

| | Command | Why it matters |
|---|---|---|
| Web server | `php artisan serve --host=127.0.0.1 --port=8001` | nothing is listening on 8001 |
| Queue worker | `php artisan queue:work` | **not optional** — §14's fourteen notifications, OTP delivery and credential dispatch are all queued. Without it the bell stays empty and §14 shows nothing |

> **Port 8001, not 8000.** Port 8000 on this host serves a different application with a
> similar login screen.

## 4 · The expiring dates — still valid today

| Field | Value | Status on 2026-10-06 |
|---|---|---|
| Completion Deadline (§12) | **31-12-2026** | 86 days ahead — **valid** |
| Refused sitting (§13) | **15-01-2027** | after the deadline — **still refuses, correctly** |
| Accepted sitting (§13) | **22-12-2026** | on or before the deadline — **valid** |

No roll-forward needed. This is the one class of fault that has stopped this walkthrough
before; it was checked first.

## 5 · Do not run

| | |
|---|---|
| `php artisan demo:seed` | runs `migrate:fresh` — drops the database, masters included |
| §2 Department Registration | optional, and it **registers a second department** plus two accounts, undoing the one-department decision. Skip it unless the audience wants to see registration |

`php artisan demo:credentials` is safe — since Addendum 3 of 2026-10-05 it creates nobody.

## 6 · Known items the audience may reach, unchanged

- **B-1** no SMS/e-mail gateway — OTP is `123456` everywhere, written to the log.
- **BD-22** roster-point attribution — Reservation Rosters are read by no calculation;
  a vacancy takes the category of the officer who vacated the chair.
- **`recruitment_rules` in `PRESERVED`** — a reset leaves generated rules behind, so this
  table must be checked by hand before every demonstration. It was checked today: 0.
- **`composer audit`** — 5 advisories, `maatwebsite/excel` high and on the import path.
- **493 uncommitted paths** in the working tree — the highest-exposure item in the
  project, and unrelated to the demonstration.
