# Final Business Alignment Before Code Freeze — 2026-09-06

Thirteen items. **One required code** — the Sanctioned Strength maintenance screen,
a verified implementation omission. The rest was verification of behaviour already
built, plus one withdrawal.

Full detail in `docs/CHANGE_REGISTER_2026-09-06_REPORT.md`.

---

## The one code change

**Sanctioned Strength could not be maintained anywhere.** The column has existed
since 2026-08-27, the Vacancy Engine has read it ever since, and it is mandatory —
but no screen and no validation rule could set it, so the only writers in the whole
codebase were `forceFill()` calls in seeders. A post created through the Admin form
carried `NULL`, and every DPC raised against it stalled at section S16 with no
screen able to clear the block.

Now: required on Create/Edit (`integer`, `min:1`), a column on the List with a
**"Not recorded"** badge for the older rows, stated on the View screen, and
pre-filled on Supersede. No schema change; persisted through the existing Request
and Controller.

**A latent bug surfaced with it.** `supersede()` built the replacement row without
carrying `sanctioned_strength`, so a superseding Government Order would have set the
establishment to `NULL` and broken every DPC on that post — silently, and on exactly
the operation it was most wrong for. It was invisible because nobody had a value to
lose. Fixed with a fallback to the incumbent's figure.

## The one withdrawal

`FBE-001 §3` — *"the system creates the DPC on approval"* — is **withdrawn**. The
alignment confirms the approved workflow is *AO creates the Final DPC from the
approved Pre-DPC*, which is what the portal has always done. It was recorded as a
gap on 2026-09-05 from an earlier clarification; building it would have been
building the wrong thing.

## Everything else: verified, not changed

Committee **Chairman + 3** unchanged, as instructed. One reserved social category
with UR by default. Chairman's three assignments in one act. Meeting Date ≤
Completion Deadline. Notifications on scheduling. Deadline expiry return. Local
Demo OTP.

## Verification

- **1,327 tests, 4,408 assertions, all passing.** 8 cases added.
  `pint` clean · `phpstan` clean · `lang:check` complete in both locales.
- **Sanctioned Strength chain, live through the Admin UI:** create 5 → DB 5 → edit 9
  → DB 9 → Vacancy Engine reads **9**. No seeder involved.
- **Sixteen-step business chain, live:** Pre-DPC → AH approval → *(order only now
  raisable)* → Final DPC → AH approval → automatic routing → Chairman assigns
  allottee + presiding member + deadline → late date refused → in-time date accepted
  → 13 notifications → deadline sweep lists the overdue DPC.
- Allottee and DPC Chairman were deliberately **different people** in the run.
- 9/9 demo logins · 13/13 admin screens · 5/5 public screens · 6/6 sample files.
- Database returned to the clean baseline; Redis flushed.

## One test-ordering note

`php artisan test tests/Feature/… tests/Unit/` reports 10 failures in
`PromotionHierarchyServiceTest`; the file passes alone and the full suite passes.
Pre-existing ordering sensitivity (strict attribute mode leaking across suites),
not a regression. Recorded as `UAT-8`. Run `php artisan test`.

## Status

**BUSINESS FROZEN · CODE FROZEN.** Critical production-blocking defects only.
