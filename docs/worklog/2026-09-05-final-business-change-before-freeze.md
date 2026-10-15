# Final Business Change Before Freeze — 2026-09-05

The last functional change before the business freeze: committee social-category
rules, the CGPSC Chairman's three allotment decisions, the meeting date bounded by
the completion deadline, the deadline-expiry return, and the sitting announcement.
Candidate login by mobile + OTP was **documented only**, as instructed.

Full detail in `docs/CHANGE_REGISTER_2026-09-05_REPORT.md`.

---

## What changed

| Item | Outcome |
|---|---|
| One reserved social category per committee member; none = UR (General); no UR checkbox | Built |
| Female / Bhar Sadhak Sachiv / Vibhag Sachiv combine freely | Already true — verified and pinned |
| Committee validated as a whole, fewest members possible | Already true — verified and pinned |
| CGPSC Chairman names allottee + DPC Chairman + deadline in one act | Built |
| Meeting date ≤ completion deadline, on schedule and reschedule | Built at the form layer (the service already enforced it) |
| Lapsed deadline returns the DPC to the Chairman, auditably | Built — the sweep existed but was registered nowhere |
| Scheduling notifies all committee members and all candidates | Built |
| Candidate login by mobile + OTP | Documented only — `FBE-002` |

## Two things worth remembering

**A command that is never scheduled is not a feature.** `dpc:return-expired-allotments`
had existed and been tested for a fortnight while never once running, because
nothing registered it. "Automatically returns" was true of the code and false of
the system. It now runs daily at 00:15 — and needs a host cron running
`schedule:run`, which is stated in the developer handbook rather than assumed.

**Candidates cannot be reached by SMS or e-mail, and the reason is structural.** A
Seniority List is a point-in-time service record with no contact column at all, so
the sitting announcement reaches them in the portal only. The listener logs its own
shortfall — `{"sms":4,"email":4,"unreachable":9,"recipients":13}` — so it is a
known gap rather than a surprise. Closing it is `FBE-002`.

## Database

**No schema change.** Every field involved already had a column. The new allotment
field addresses a committee seat by its route key, because `dpc_committee_members`
has no `uuid` column — the same convention the pre-existing member-replacement
route uses.

## Documentation

Updated: architecture handbook, user guide, developer handbook,
`docs/demo/CURRENT_DEMO_FLOW.md` (Steps 8, 15, 16, the appendix and the
limitations), `docs/FUTURE_BUSINESS_ENHANCEMENTS.md` (FBE-001 marked partly built;
FBE-002 added). New: `docs/CHANGE_REGISTER_2026-09-05_REPORT.md`.

Archived to `docs/archive/2026-09-05-superseded/` with a README explaining why:
`DEMO_FLOW.md`, `DEMO_CHEAT_SHEET.md`, `UPLOAD_FILES_GUIDE.md` — all three
described the deleted seeded database and were replaced by the three current demo
documents. `docs/demo/` now holds exactly one authoritative version of each.

## Verification

- **1,319 tests, 4,372 assertions, all passing.** 24 test cases added across four
  files; one existing test rewritten because §4 of the clarification reversed the
  rule it pinned.
- `pint` clean · `phpstan` clean · `lang:check` complete in both locales.
- **Live end-to-end run** on the demonstration database: SC + ST refused by name;
  the clarification's own three-member committee accepted; allotment naming who
  presides; a sitting on 20-11-2026 refused against a deadline of 15-10-2026 and
  10-10-2026 accepted; 13 sitting notifications written (4 committee members on
  every channel, 9 candidates in-app).
- Database returned to the clean demonstration baseline afterwards: every
  transactional table at 0 rows, all 31 master and access tables byte-for-byte
  identical to the pre-work checksum, `storage/app` holding only its three
  `.gitignore` files, Redis flushed, all demo logins re-verified.

## Status

**BUSINESS FROZEN.** Critical bug fixes only.
