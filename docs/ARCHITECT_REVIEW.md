# Handover — 2026-08-13

Baseline established. No code was written today; the repository was verified, gated, cleaned, committed and tagged.

---

## Repository status

| | |
|---|---|
| **Branch** | `feature/m0-setup` |
| **Tag** | `v0.6.0-m6-complete` (annotated) |
| **Commit** | the commit `v0.6.0-m6-complete` points at — `git rev-parse v0.6.0-m6-complete^{}` |
| **Remote** | `origin` → `git@github.com:hariom-pal/DPC.git` |
| **Working tree** | Clean |

The hash is given as a tag reference rather than a literal, because this file is *inside* the commit it would name — writing the hash here would change it. The tag is the stable identifier; resolve it with the command above.

**This commit closes TD-14.** Before it, the last commit was `3133ac4 M3` — M4, M5 and M6 existed only in an uncommitted working tree. Beyond the loss risk, that made the three freeze policies unverifiable: a freeze is a claim about a commit, and there was no commit at any milestone boundary to diff a suspected breach against. There is now.

**Note on the branch name.** `feature/m0-setup` is six milestones out of date. Renaming rewrites the remote ref, so it is flagged rather than done.

---

## Quality gates — all green

| Gate | Result |
|---|---|
| Laravel Pint | Pass |
| PHPStan (Larastan level 6) | 0 errors |
| PHPUnit | 368 passed / 1281 assertions |
| `composer audit` | No advisories |
| `php artisan migrate:fresh --seed` | OK — 54 tables, 10 seeders |

Run as `composer gate` plus `migrate:fresh --seed`. Nothing needed fixing.

**One CI caveat:** `composer audit` reaches packagist over the network and failed with `curl error 28` on a clean tree during yesterday's run, passing on retry. **CI must retry that step**, or a build will fail in a way that reads as a security gate failing (`TD-2`). It passed first time today.

---

## Repository cleanliness

Verified: no backup, temporary, editor or conflict files; no file over 2 MB; no runtime artefacts from `storage/`; `node_modules` absent. `.idea/` and `.phpunit.result.cache` were already ignored. `.claude/settings.local.json` — machine-local tool settings — was untracked but not ignored, so it was added to `.gitignore` before committing.

Three empty directories remain under `design/` (`assets`, `design-system`, `figma`). They are untouched: `design/` is on the do-not-delete list, and git does not track empty directories in any case.

`package.json`, `vite.config.js` and `.npmrc` are tracked but the project deliberately has **no Node/Vite build step**. They are Laravel scaffolding rather than junk, so they were left in place — removing them is a decision, not a cleanup.

---

## Documentation

All eleven mandatory documents exist and are synchronized:

`README.md` · `PROJECT_STATUS.md` · `PROJECT_PROGRESS.md` · `CHANGELOG.md` · `ARCHITECT_REVIEW.md` · `TECHNICAL_DEBT.md` · `WORKFLOWS.md` · `EVENT_CATALOG.md` · `INTEGRATION_ROADMAP.md` · `UAT_CHECKLIST.md` · `DEMO_SCRIPT.md`

Two desyncs were found in `README.md` and fixed: its document map predated the six newer mandatory documents, and it described `composer gate` without `composer audit`, which has been a mandatory gate since M4 Phase 6 (the script itself was correct; only the README was stale). A milestone-state section was added so the freezes are visible on the front page.

---

## UAT readiness

**Ready, with one qualification.** The full pipeline — registration → Pre-DPC → DPC proposal → Excel import → AH approval with signature → CALCULATE → allotment → scheduling — runs end to end with **every integration switched off**, which is the configuration UAT will use.

**The qualification:** while BD-2/BD-3 is unsettled, a DPC where a reserved category has fewer eligible officers than vacancies returns a **provisional** result. UAT proceeds; a provisional result must not be published.

**Two things to tell testers before they start, not during:**

1. **SMS, HRMS and the real signature are switched off.** Unsaid, the finding log fills with the same three non-defects and the real findings get harder to see.
2. **"Provisional" is a correct outcome**, not a failure — it is the system declining to guess at an unsettled rule.

Both are written into the checklist preamble and the demo script's opening.

Cannot be validated in UAT: SMS/e-mail delivery (TD-6), real digital signature (BD-4), HRMS pre-fill (BD-5), reserved-relaxation outcomes (BD-2/BD-3), PUNISHMENT disqualification (BD-1), audit-trail screens (TD-7).

---

## Remaining business decisions

Full detail in `docs/business/PENDING_BUSINESS_DECISIONS.md`; eight open, four material.

| # | Question | Effect |
|---|---|---|
| **BD-2 / BD-3** | Reserved relaxation — automatic or committee discretion, and does relaxation to 10 apply beyond Class-I→I? | **Blocks publishable results.** The only open item that limits what UAT can conclude. |
| **BD-1** | Does a recorded `PUNISHMENT` disqualify an officer? | Slide 33 marks two officers NE whom the engine passes. Current behaviour is pinned by test; not implemented unilaterally. |
| **BD-4** | Does the CSC eSign API sign raw bytes or require a PDF? | The one integration question that could change application code — the signed proposal is currently plain text. |
| **BD-5** | HRMS API contract | Blocks the HRMS provider; no UAT impact. |

**Recommendation: escalate BD-2/BD-3 to CGPSC before UAT begins, not during.** It is the only one whose answer changes what UAT can conclude, and it needs a committee ruling rather than an engineering decision, so it has the longest lead time.

---

## Next milestone — M7 (DPC Proceeding & Results)

Scope per `docs/design/05-milestones.md`: conduct the DPC, CALCULATE, Main + Waiting list generation, APPROVE BY CGPSC, CANCEL DPC, proceedings and result PDF (queue → S3). Depends on M5 and M6, both frozen. Carries open question **Q7**.

**The M7 design document does not exist yet.** Approval is recorded, but nothing has been written — no `docs/design/08-m7-*.md`. Writing it is the first task, not the second.

---

## Recommendations before starting M7

**1. Settle BD-2/BD-3 first, or accept that M7 inherits it.** M7 produces the legally significant artefacts — proceedings and result documents. A rule that today only marks a calculation provisional will, in M7, decide what a *published promotion order* contains. The cost of it being open rises sharply at exactly this milestone boundary.

**2. Start the SMS gateway procurement now, in parallel.** The Government SMS gateway is not one integration among six: **OTP delivery depends on it, so without it no officer can log in to production at all.** Every other integration degrades gracefully — HRMS off means manual entry, e-mail off means one channel instead of two. This one means production does not function. It is also the longest lead time because it needs a government contract rather than an engineering decision, which is precisely why it will be discovered late if left to the deployment checklist (TD-6).

**3. Take freeze verification seriously now that it is possible.** With `v0.6.0-m6-complete` in place, `git diff v0.6.0-m6-complete -- app/Domain/Eligibility` mechanically answers "has the frozen engine changed?" Worth running at each M7 phase boundary — M7 depends on both frozen milestones, which is exactly the condition under which a freeze quietly gets breached.

**4. Treat the gate as necessary but not sufficient.** Recorded in `TECHNICAL_DEBT.md` and repeated here because M7 is where it matters most: of the eight defects fixed since M4, **four were found by implementing a directive properly rather than by testing the feature concerned** — the audit trail untested since M1, a failing notification rolling back a workflow transition, `dpc_time` reading differently per driver, and non-deterministic timeline ordering. **All four passed Pint, PHPStan, PHPUnit and audit while broken.** M7's outputs are the ones that get read in a tribunal.

**5. Consider renaming the branch.** `feature/m0-setup` now contains M0 through M6.

---

## Status

M4, M5 and M6 are complete and frozen. The repository is clean, gated, committed, tagged and pushed. **M7 has not begun.**
