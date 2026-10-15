# Health Check — 2026-09-07

**Question asked:** is everything still running correctly, and is any gap visible?
**Method:** the full quality gate executed, plus MySQL schema build, evidence-grant probe, language catalogue, route table and Blade compile. Nothing below is taken from a document.
**Repository state:** branch `feature/m0-setup`, HEAD `71761a7`, plus the uncommitted working tree.

---

## 1. The gate — all green

| Check | Command | Result |
|---|---|---|
| Style | `pint --test` | **passed** |
| Static analysis | `phpstan analyse` (level per `phpstan.neon`, 2 GB) | **No errors** |
| Tests | `php artisan test` | **1447 passed**, 2 skipped, 4811 assertions, 265.67 s |
| PHP dependencies | `composer audit` | **No security vulnerability advisories** (loaded from local cache; the host had no network) |

**The 2 skipped tests are deliberate and named.** Both are in `Tests\Feature\Admin\MasterDataPortabilityTest:173`, skipped with the reason *"Covered by the lookup test, which seeds the rows these reference."* Not an unimplemented case.

## 2. Structural checks beyond the gate

| Check | Result |
|---|---|
| `schema:verify-mysql` — build every migration on real MySQL 8.0 in a throwaway schema (**TD-24**) | **84 migrations, 79 tables, clean.** The P0 class of bug this exists to catch is absent. |
| `lang:check` | **Catalogue complete.** 0 missing from `hi`, 0 extra in `hi`. One string identical in both — `ui.layouts.portal.brand_title` — and it is correct: the brand line is deliberately bilingual in both locales (`छत्तीसगढ़ लोक सेवा आयोग · Chhattisgarh Public Service Commission`). |
| `route:list` | **281 routes** register with no error. |
| `view:cache` | **Every Blade template compiles.** |
| `config:cache` | Builds. |
| Migration discipline (additive-only ruling) | **Held.** `git diff HEAD -- database/migrations` = **0 changed files**; all 30 new migrations are new files. |
| Leftover markers | **0** `TODO` / `FIXME` / `HACK` across `app/`, `resources/views/`, `routes/`, `config/`. |
| Stray artifacts | **0** `.tmp` / `.orig` / `.bak` / `.rej` on disk. |
| Schema-identifier guard non-vacuity | **Not vacuous.** `SchemaIdentifierLengthTest` uses the unfiltered `Schema::getTableListing()`, strips the SQLite `main.` qualifier, and asserts on the inspected count — so the SQLite listing trap cannot silently empty it. |

---

## 3. Gaps found — four, none of them a defect in the running application

### G-1 · The whole body of work is uncommitted  ·  **highest exposure**

`303` tracked files changed and `190` untracked paths — **20,504 insertions, 6,482 deletions** — sit in the working tree against HEAD `71761a7`. This includes 30 new migrations, whole new domains (`app/Domain/Committee/`), and the services behind the vacancy engine and the record-gap flow.

**Why it matters.** This is what runs, what the 1447 tests exercise and what the audit of 2026-08-28 assessed — and none of it is in git. A single `git checkout .`, a stash misfired, or a disk fault loses roughly three weeks of work with no recovery path. Every other item on this page is minor next to it.

**What to do.** Commit it, in whatever number of commits is honest about the shape of the work. Nothing about the code needs to change first — the gate is already green on exactly this tree.

### G-2 · A stale rename sits in the git index

    RD  public/js/forms/pre-dpc-posts.js -> public/js/forms/pre-dpc-posts.js.tmp

The index holds a staged rename to a `.tmp` intermediate; the working tree has neither path, because the file is now `public/js/forms/pre-dpc-exercise.js` and is correctly wired (`resources/views/pre-dpc/form.blade.php:262`).

**Why it matters.** The rename in the working tree is finished and right. But the index is the thing `git commit` reads, so committing without re-staging this path would record a `.tmp` filename into the history. It is a leftover from the rename pass, not a code problem.

**What to do.** `git add -A public/js/forms/` before the G-1 commit.

### G-3 · No `package-lock.json`, so the frontend half of the dependency gate cannot run

`npm audit` fails outright — `ENOLOCK: This command requires an existing lockfile`. There is no `package-lock.json` in the repository and none is ignored by `.gitignore`; the file has simply never been committed.

**Why it matters.** Two things. The frontend dependency audit is not *passing* — it is *absent*, which is a different and less comfortable state for a government build. And without a lockfile the asset build is not reproducible: two machines running `npm install` a month apart can resolve different transitive versions.

**What to do.** `npm install --package-lock-only`, commit the lockfile, then add `npm audit --omit=dev` to the gate alongside `composer audit`.

### G-4 · TD-1 evidence grants remain unproven anywhere

`security:evidence-grants --verify` reports **26 probes came back wrong** — every `DELETE` and every `UPDATE (uuid)` on the twelve evidence tables was permitted.

**This is the expected result on this machine and is not a regression.** The probe runs as whichever user the application connects as, and on this development host that is `root@127.0.0.1`, which cannot be denied anything. The grants are written for `odpc_app`. The command deliberately does not run `GRANT` itself.

**Why it is still worth recording.** It is already tracked as blocker **B-3**, owner DBA. What the probe result adds is that the control has never been *demonstrated* to work anywhere, because the only host it can be demonstrated on is one with the restricted account. The moment a UAT host exists with `odpc_app`, `--verify` there is the evidence — and it should be captured, not assumed.

---

## 4. What was checked and found sound

- The `DEMO_OTP` gate is genuinely the environment, not a config flag: `OtpService.php:98` branches on `app()->environment('local')`, which cannot be true on a UAT or production host. `config/dpc.php:52` reads the value but does not gate on it. The `.env.example` note says the same thing.
- Business documentation is current, not stale. `docs/CGPSC_DPC_BLOCKERS.md` carries five items with owners, and `docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` carries the open BD-list through BD-31 with the 2026-08-26 change-register additions present.
- The four `PPT_*.md` compliance documents at the repository root are committed at `53cb6c4` and were not left behind by this session.

---

## 5. Verdict

**Nothing is broken.** Gate green on all four legs, MySQL builds the real schema, every screen compiles, the catalogue is complete, and no functional gap surfaced.

The one thing that genuinely needs doing today is **G-1** — commit the tree. G-2 is thirty seconds of the same job. G-3 is a lockfile. G-4 waits on a host that does not exist yet.
