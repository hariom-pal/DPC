# Changelog

All notable work on the ODPC Portal, in reverse chronological order. Business-rule changes are traceable to `docs/ODPC_NEW_FINAL.pptx` / `docs/CONVENTIONAL DPC PROCESS.pptx` slide numbers where applicable. This file, `docs/PROJECT_STATUS.md`, `docs/PROJECT_PROGRESS.md`, and `docs/ARCHITECT_REVIEW.md` are the only documents updated on an ongoing basis (see `docs/ARCHITECT_REVIEW.md` for the full documentation policy).

## 2026-08-16 — M9 Phase 3: the prescribed form, and publication refused on BD-14

Third slice of M9 (PPT S45, S48; C3/C7). One additive table. **No file in M4, M5, M6, M7 or M8 was modified** — and specifically **M7's `DocumentType`, `ProceedingDocumentService`, renderer and document views are untouched**, verified by diff.

- **M9 owns its document layer, per the AQ-22 ruling.** Its own `CirculationDocumentType` (`circulation_note`, `revised_promotion_list`), its own `CirculationDocumentStatus`, its own `dpc_circulation_documents` table, and its own `CirculationDocumentRenderer` contract with an HTML implementation bound in the container. **The enum was the least of the coupling:** every M7 entry point — `generate()`, `publish()`, `render()` — is hard-typed to `DpcProceeding`, and a Circulation has none (AD-24). Reuse would have meant filing the note among the *original DPC's* documents, or a **fifth decorator** in a container graph that has already cycled once.

- **What is reused is `SignatureService` and the pattern, never M7's classes.** `sign(User, Model $signable, …)` accepts any model — Phases 1 and 2 already sign a `DpcCirculation` through it. The duplication AQ-22 accepts is mitigated by **asserting the same properties M7 asserts**: stored bytes never rewritten, SHA-256 over stored bytes, `verify()` against the stored artefact, published documents downloadable.

- **§23.1 implemented exactly.** While BD-14 is unresolved: publication is **refused**, the refusal displays **"Pending Business Decision (BD-14)"** verbatim, **no effective date is invented**, and **rendering, signing, storage and verification all work completely**. The last clause has its own test, because a guard that also disabled generation would satisfy "refuse" and defeat the ruling.

- **The note states two facts and asserts neither as governing:** the origin DPC's `promotion_as_on_year`, captured at approval and never derived (AQ-7), and the date the reservation was released. Which governs seniority and pay is BD-14 — a service-law consequence the Commission states, not a field the renderer computes. A test asserts the phrase *"asserts neither as the effective date"* appears and that no effective-date basis is printed.

- **BD-14 is expressed as configuration, not as a constant** (AD-3): `dpc.circulation.effective_date_basis` is null until CGPSC rules. Two tests show the other side — set it, and publication succeeds and the note states the basis. **The ruling will be applied by configuration, with no code change and no redeploy of business logic.**

- **The revised promotion list is the stored result with `CIR` cleared for released officers only.** US9, held on a different ground and not on the note, keeps theirs. Generating documents creates no calculation run and leaves the pinned run byte-identical (AD-23).

- **Two real defects found by the tests, both in code written this phase.** The document **immutability guard was too permissive**: the columns generation writes stayed writable forever, so `content_hash` was editable on a signed document — precisely the guarantee the guard exists to provide. It now **narrows once the document is generated** to `published_at` alone. And **authorisation used the `sign` ability**, which is only true while the note is `consented` — making every document action unreachable the moment the note was signed, which is exactly when they become possible. A separate `manageDocuments` ability replaces it.

- **A Phase 2 guard fired as designed.** `test_phase_3_is_not_present` asserted `dpc_circulation_documents` did not exist; Phase 3 created it. Rewritten to assert what is now true *and* to keep Phase 2's real claim: signing alone produces no document.

- **26 new tests / 89 assertions**; **98 passed / 276 assertions** across M9; and the **full suite re-run at finalisation: 718 passed / 2178 assertions** (692 before, **+26**). Pint clean, PHPStan (Larastan level 6) 0 errors, `migrate:fresh --seed` OK — **45 migrations, 65 tables, 10 seeders**. `composer audit` clean **on the second attempt**: the first invocation returned composer's usage output rather than a result. **Three consecutive finalisations have now needed a retry on this gate** — the two before it were network timeouts falling back to a stale cache (**TD-2**); this symptom differed, and both are recorded rather than smoothed over.

- **All four governing decisions verified, not assumed.** **AD-21**: the M8 diff, including all four view paths, is empty. **AD-23**: a comment-stripped scan of **22** Circulation source files across three controller roots finds no forbidden service reachable. **AD-24**: no model extends `DpcProposal`. **AQ-22**: `DocumentType`, `ProceedingDocumentService`, M7's `Documents/` directory and M7's document views are **byte-identical**.

- **Freeze verified per milestone:** M4, M5, M6 (`v0.6.0-m6-complete`), M7 (`v0.7.0-m7-complete`) and M8 (`v0.8.0-m8-phase3`) — **every diff empty**. The migrations differing from the M8 tag are **M9's own two**, added after it.

**Phase 3 completes M9's engineering. Publication remains blocked until CGPSC answers BD-14.**

## 2026-08-16 — M9 Phase 2: the Commission's consent, and hold release at signature

Second slice of M9 (PPT S46, S48). **No migration** — Phase 1 created the columns Phase 2 fills, which is what the design predicted. **No file in M4, M5, M6, M7 or M8 was modified**; all five freeze diffs empty.

- **The signature is the operative act, and consent is recorded intent (AQ-17).** The Commission decides each officer, consents to the note, and signs — three transitions, deliberately not two. **Holds are released inside the signature transaction, after the signature succeeds**, and only for officers the Commission actually consented to. A note that is consented but never signed leaves every promotion reserved; a note where every officer was returned signs cleanly and releases nothing. Both are tested.

- **The one write M9 makes into a frozen milestone's table.** `dpc_proceeding_holds.released_at` is the seam M7's own migration declared for M9, and the hold model's guard permits that column and no other (AQ-21). **`HoldService::release()` is deliberately not used** — it asserts an open proceeding and refuses automatic holds, which is correct for a sitting in progress and wrong for a DPC that concluded. That case is precisely the one M7 could not serve and left a seam for.

- **S48's before-and-after is one stored result rendered twice.** The obvious implementation recalculates with the hold cleared — and that would change marks, could make a Super Seat reachable, and could move officers who are not party to the circulation and have no notice of it. So `CirculationResultService` reads `DpcCalculationRun` and `DpcCalculationResult` **as models**, from the run the proceeding pinned at approval rather than from "latest", and the `CIR` annotation comes from **hold state**. A test asserts the run, results, evaluation rows and proceeding are **byte-identical** after a full signature, and that an officer on a different hold, not on the note, is identical in both columns.

- **Only the Member who conducted the original DPC may act (AQ-20).** AQ-6 reused verbatim: holding the `member` role is not the same as being *this* DPC's member, and **the Chairman is not exempt** — both refusals are tested. The person who held the promotion is the person who releases it.

- **Two decisions, not three.** Consent, and return for further evidence. S45 describes no power to refuse a promotion on the strength of a circulation, so none was invented — that is **BD-12**, and a test asserts the enum has exactly two cases so a third cannot arrive quietly.

- **The notification framework added no fourth copy of the recipient query (TD-20).** The workflow engine already reaches department-scoped roles *and* named user ids, so the Commission — who hold no department — is reached by naming the origin's allottee on the department's approval, and the signed message names the **officers released** by carrying them in the transition's own remark. Design §15 required the message to name officers rather than only the DPC; it does, without a dedicated listener.

- **A Phase 1 guard fired exactly as designed.** `test_phase_2_transitions_are_not_declared_yet` failed the moment Phase 2 declared `consent`, `return_to_department` and `sign`. That is the test doing its job, not a regression: it has been rewritten to assert the complete Phase 1+2 set, and the architecture test's workflow assertion with it.

- **Two defects found and fixed during the phase.** A `static` closure in `CirculationResultService` reached for `$circulation` it had not captured — caught by the Commission screen test rather than by review. And a test asserted on a `US1` the fixture never creates; replaced with a stronger assertion on the officer who *is* held on another ground and not on the note.

- **72 tests / 186 assertions** across M9 (46 from Phase 1, **+26 from Phase 2**), and the **full suite re-run at finalisation: 692 passed / 2088 assertions** (666 before, **+26**). Pint clean, PHPStan (Larastan level 6) 0 errors, `migrate:fresh --seed` OK — **44 migrations, 64 tables, 10 seeders, unchanged from Phase 1 because Phase 2 added no schema**. `composer audit` clean **on the second attempt**; the first hit a network timeout and fell back to local cache — **TD-2, which has now fired at two consecutive finalisations.**

- **All three governing decisions verified, not assumed.** **AD-21**: the M8 diff, including all four view paths, is empty. **AD-23**: a comment-stripped scan of every Circulation source file — now including `app/Http/Controllers/Cgpsc` — finds no forbidden service reachable. **AD-24**: no model extends `DpcProposal`, the table is `dpc_circulations`, and `dpc_proposal` appears in no circulation service.

- **Freeze verified per milestone, not in aggregate:** M4, M5, M6 (against `v0.6.0-m6-complete`), M7 (`v0.7.0-m7-complete`) and M8 (`v0.8.0-m8-phase3`) — **every diff empty**. The one migration that differs from the M8 tag is **M9 Phase 1's own**, added after it; no M4–M8 migration changed.

**Phase 3 has not begun.** Phase 2 signs a plain-text statement of what was consented to; the prescribed form — the rendered, hashed, published circulation note and the revised promotion list — is Phase 3, and `dpc_circulation_documents` still does not exist. A test asserts that.

## 2026-08-16 — M9 Phase 1: the circulation aggregate and the department's side

First slice of M9 (PPT S45, S47; C3/C7). Two additive tables. **No file in M4, M5, M6, M7 or M8 was modified** — all five freeze diffs empty, including M8's view paths.

- **A Circulation is its own aggregate, and the schema now says so (AD-24).** `dpc_circulations` and `dpc_circulation_items`; no `dpc_proposals` row of type `circulation` is created, nothing extends `DpcProposal`, and the origin is referenced by foreign key and never written. Its own `dpc_circulation` workflow in configuration, its own policy, and its own identifier — **`HE202600001/C1`, not a DPC ID.** Drawing from `dpc_id_sequences` would have consumed DPC serials for something that is not a DPC and produced a code indistinguishable from one.

- **BD-15 is detected, refused in a structured way, and recorded — and nothing is decided.** A circulation covering a **CR-ground** hold returns a `PendingBusinessDecision` naming the officers affected, the question, and **what the system deliberately did not do**. No calculation, no scoring, no hold released, and **no automatic escalation to a Review** — escalating would answer the question by acting. The refusal is written to the DPC's timeline, because a case blocked on CGPSC must be distinguishable from one nobody raised. Screens get the structured panel; API callers get a `409` with the same shape.

- **It is deliberately not a validation error.** A `PendingBusinessDecisionException` is not a `WorkflowException`: one says the caller did something they may not do, the other says the *system* cannot yet decide. Presenting a blocked case in red beside validation failures would teach a department that waiting on CGPSC is a mistake of theirs to fix.

- **Why the CR ground is blocked at all, in one line:** the engine scores *"the five most recent **available** CR years"*, so a CR-held officer's window is incomplete by definition — supplying the grade grows it, makes a **Super Seat reachable for the first time** (it is refused on an incomplete window), and a Super Seat carries notional seniority that **reorders the main list for officers who were never notified**. Raised as **BD-15**, with **BD-12**, **BD-13** and **BD-14** alongside it.

- **AD-23 now has a check, and writing it found a flaw in the obvious version.** A `str_contains` over raw source flags `CirculationService`'s own docblock, which names all ten forbidden classes in prose precisely so the next reader knows why they are absent — a check like that trains people to delete the explanation to go green. The test strips comments with `token_get_all` and reads what the parser sees, because **the rule is about reachability, not spelling.** It tests AD-23's full list, not the four names in the ruling: `ProceedingCalculationService` is M7's own entry point into the engine, and `ReviewAwareCalculationService` is M8's decorator **bound over M7's service in the container**, so a type-hint on the M7 class resolves to it.

- **Nothing releases a hold.** `dpc_proceeding_holds.released_at` is M7's declared seam for M9, and Phase 2 writes it at signature (AQ-17) — not at raising, not at the department's approval. Three tests pin it. The item's foreign key to the hold is `restrictOnDelete`: a hold is the reason a promotion did not take effect, and no circulation may take one with it.

- **Two deviations from the approved design, both narrowing and both flagged.** `dpc_circulation_documents` is **not** created — it belongs to Phase 3, which renders and signs the note, per the standing rule that a phase declares only what it implements. And `CirculationStatus` declares only the five states Phase 1 can reach; `consented` and `signed` arrive with the transitions that perform them.

- **PHPStan caught a real API error before it shipped.** `cascadeOnDelete()` / `nullOnDelete()` / `restrictOnDelete()` take no constraint-name argument — PHP ignores extra arguments silently, so the migration *ran* and produced auto-generated names while appearing to set explicit ones. Corrected to `constrained(table, column, indexName)`, with every name well inside MySQL's 64-character limit. **This is TD-24's failure mode caught by a different gate**: the migration was green on SQLite either way.

- **46 new tests / 118 assertions**, and the **full suite re-run at finalisation: 666 passed / 2020 assertions** (620 before, **+46**). Pint clean, PHPStan (Larastan level 6) 0 errors, `migrate:fresh --seed` OK — **44 migrations, 64 tables, 10 seeders**. `composer audit` clean **on the second attempt**: the first hit a network timeout and fell back to local cache, which is **TD-2** and is recorded rather than reported as a clean first run.

- **Freeze verification against all five frozen milestones — every diff empty.** M4/M5/M6 against `v0.6.0-m6-complete`, M7 against `v0.7.0-m7-complete`, and **M8 against `v0.8.0-m8-phase3` including all four of AD-21's view paths**, which a diff scoped to `app/` alone would miss. No M4–M8 migration changed either.

**Phase 1 is complete and stops here. Phase 2 has not begun.** Nine architect questions in §24 of the design remain unruled, and **BD-15 blocks nothing in Phase 1 — it is answered by refusal, not by waiting.**

## 2026-08-14 — M8 closure documentation synchronized

**Documentation only. No source file, test, migration, view or route was touched; no gate was run; the `v0.8.0-m8-phase3` tag was not moved.** This entry closes the documentation half of M8: the freeze, the exception mechanism and BD-11's reclassification existed in the working tree but had never been committed, and two of the ten mandatory documents still described a milestone that had been superseded twice over.

- **`docs/MILESTONE_SUMMARY.md` and `docs/PROJECT_TIMELINE.md` brought into line.** Both still said BD-11 blocked M8, neither recorded the M8 freeze or AD-22, and the summary's at-a-glance table carried *70 tests*, *607 / 1865* and *61 tables* — the figures the gate run had already disproved. The M8 row now reads **Complete & released · Frozen (AD-21)**, M7's freeze column is filled in, and the timeline gains four entries: the three phases, the gate run that found two P0s, M8's closure with BD-11's reclassification, and AD-22.

- **BD-11 now reads identically in every document that mentions it:** a **Deferred Business Decision — Required before production activation of the Review supersession policy.** It blocks **no milestone** — not M8's completion, not M9's start. What it gates is switching supersession on for real officers, and the distinction is the point: a milestone gate is invisible outside this project, whereas production activation is a checkpoint someone can be held to.

- **M8's test count is recorded as 83, not 85 — and this is a correction to the figure the prompt supplied.** M8's tests are exactly the four files commit `a60689d` added under `tests/Feature/Review`: `ReviewDpcTest` 34, `ReviewSupersessionTest` 19, `ReviewCalculationTest` 18, `ReviewDocumentReferenceTest` 12 — **83**, which is also what `php artisan test tests/Feature/Review` reports. No M8 test lives outside that directory, and `a60689d` modified no existing test file. **85 was never reproducible**, and it had already reached `PROJECT_STATUS.md` and `PROJECT_PROGRESS.md` by copy; both are corrected here. This is the third time in two days that a number written once and propagated has had to be withdrawn — after *607 / 1865* and *61 tables* — and it is the reason the count is stated above with its four constituent files rather than as a total.

- **The suite figures are unchanged and were re-verified before committing:** Pint **pass**, PHPStan (Larastan level 6) **0 errors**, PHPUnit **620 passed / 1902 assertions** (exit 0), `composer audit` **no advisories**, and all four freeze diffs — M4/M5/M6, M7 and **M8** — **empty**.

- **`docs/ARCHITECT_REVIEW.md` §0 replaced in full**, per the Permanent Documentation Rule.

**M9 has not started.** No M9 code, migration, route, view or test exists; the `CIR` references in the codebase are M5's remark code and M7's held promotions, both of which predate M9.

## 2026-08-14 — M8 quality gate run in full: two P0 defects found and fixed

**No new feature, no refactor, no M4/M5/M6/M7 file touched.** The gate was run in the directed order — Pint, PHPStan (Larastan level 6), full PHPUnit suite, `composer audit`, `migrate:fresh --seed`. Gates 1, 2 and 4 passed first time. **Gates 3 and 5 each failed, and neither failure was cosmetic: M8 Phase 3 was not deployable.**

- **Final figures, all five green:** Pint **pass** · PHPStan **0 errors** · PHPUnit **620 passed / 1902 assertions, 0 failing** (95 MB peak, 1m19s) · `composer audit` **no advisories** · `migrate:fresh --seed` **OK, 43 migrations, 62 tables, 10 seeders** · freeze diff against `v0.7.0-m7-complete` **empty**, re-verified after the fixes.

- **P0 — a circular container dependency killed the PHP process (gate 3).** `php artisan test` died with SIGKILL. Bounded, it became `Premature end of PHP process` at `ProceedingDocumentTest::test_both_documents_are_generated_after_approval` — which failed **alone** too, so it was not cumulative leakage. Resolving `DocumentRenderer` recursed forever: `ReviewAwareDocumentRenderer` needs `ResultApprovalService`, bound to `ReviewAwareApprovalService`, which needed `ReviewAwareDocumentService`, which needs `DocumentRenderer`. **This is AD-20's four-decorator pattern reaching its cost** — three of the four sit in each other's dependency graph, and M7's own renderer already depended on the approval service, so Phase 3's constructor closed the ring. `ARCHITECTURE_BASELINE_v0.7.md` warns that a *fifth* decorator is the signal to re-examine the boundary; **the fourth was already enough.** Fixed by resolving the document service inside `approve()` — the only place it is used — rather than in the constructor. One edge, no refactor.

- **This is also the cause of the development machine hanging.** CLI `memory_limit` is `-1` here, so the recursion consumed 15 GB of RAM and 1.3 GB of swap before the kernel intervened; the host thrashes long before the process dies, which presents as a hang rather than a test failure. Previously recorded as an open, uninvestigated question with no cause established. It was never a capacity problem, and the suite now peaks at **95 MB**.

- **P0 — M8's last migration could not run on MySQL (gate 5).** `SQLSTATE 1059: Identifier name 'dpc_document_review_references_dpc_proceeding_document_id_foreign' is too long` — **65 characters against MySQL's limit of 64.** `dpc_document_review_references`, the table carrying Directive 3's four permanent document references and Directive 2's supersession timestamp, **had never existed in the database**; `migrate:fresh` aborted there, so no seeder ran either. The recorded "61 tables" was the count *before* the table that failed. Fixed with an explicit short constraint name; no column, index, type or relationship changed.

- **Why 84 green M8 tests never caught it, and the process finding that matters more than the bug (TD-24).** `phpunit.xml` runs on **in-memory SQLite**, which does not enforce MySQL's identifier limit. Every M8 test exercised a table the real database could not create. **A green suite was not evidence the schema deploys**, and identifier length is not the only place the two engines differ. Running the suite — or at least a schema check — against MySQL is now the highest-value process change available.

- **Documentation corrected rather than re-asserted.** *607 passed / 1865 assertions* and *61 tables* appeared in four documents and **no run could have produced either**: the suite could not reach its end and the migration could not complete. `ARCHITECT_REVIEW.md` §5 and §10 items 6 and 8, `PROJECT_STATUS.md` and `PROJECT_PROGRESS.md` now carry the run that actually happened, with the previous figures named so the change is auditable. **The failure mode is a number written once, propagated by copy, and never re-derived** — §5's caveat "true when recorded, no source file has changed since" was the wrong test, because it had never been true.

- **Carried in from the preceding completeness review and now gate-verified for the first time:** `sanctioned_post_count` is copied onto a Review (without it, M4's own submission gate refused every Review DPC), the S37 reason checkboxes name the six grounds instead of rendering raw codes (`ReviewReason` has `name_en`, not `name`), and two regression tests guard both.

- **Two items raised, neither blocked by a freeze:** **TD-24** (suite engine differs from the deployment engine) and **TD-23** (M8's end-to-end test `forceFill`s past M4's `submit()` and the AH approval, which is what hid the `sanctioned_post_count` defect — design §13 requires the real path).

~~**Not committed, not pushed, not tagged.** Awaiting architect approval. **M8 closure is still not requested — BD-11 is unanswered.** M9 has not begun.~~

**Superseded the same day — all four followed.** Architect approval was given; **BD-11 was reclassified as a Deferred Business Decision — Required before production activation of the Review supersession policy**, and therefore blocks no milestone; **M8 was closed**. The work was committed in `1c8a8e1` *docs: finalize M8 documentation* and `a60689d` *feat(m8): Review / Re-DPC chain (Phases 1-3)*, **pushed** to `origin/feature/m0-setup`, and **tagged `v0.8.0-m8-phase3`** at `a60689d`, confirmed on the remote. M8 was then **frozen (AD-21)** at that baseline, and the **Freeze Exception mechanism (AD-22)** was defined to make the freeze openable at all. **M9 has still not begun.**

## 2026-08-14 — M8 engineering complete: documentation synchronized at the milestone boundary

No source change. This entry records the documentation state at the boundary.

**Corrected 2026-08-14.** This entry was headed "…and committed at the milestone boundary" and stated that M8's three phases, having never been committed, "are now, in one commit on `feature/m0-setup`". **No such commit was ever made.** `HEAD` remains `6f52f71` (M7) and the branch is level with `origin`. The claim was written in anticipation of a step that did not happen — the same failure mode that put a false gate result in four documents (see the entry above). The freeze policy's premise still holds and is still unmet: **a freeze is a statement about a commit**, and M8 does not yet have one.

- **M8 engineering is complete and M8 is *not* closed.** Everything the milestone was scoped to build exists, is tested, and passes every gate. Closure is withheld pending **BD-11** — what supersession means for an officer already promoted under the reviewed DPC. That is a service-law question about a serving officer's substantive rank and pay, not an engineering one, and the three plausible answers differ in what they do to that officer. The system records the supersession, notifies the department, and asserts nothing about the promotion itself.

- **M9 has NOT started.** No M9 code, migration, route, view or test exists.

- ~~**Gate re-verified before committing**: Pint passed, PHPStan 0 errors, PHPUnit 607 passed / 1865 assertions, `composer audit` no advisories, `migrate:fresh --seed` OK (61 tables).~~ **Withdrawn 2026-08-14.** Neither the test figure nor the table count was reproducible: the suite was killed by a circular container dependency before it finished, and `migrate:fresh` aborted on M8's last migration under MySQL. The verified figures are in the entry above — **620 passed / 1902 assertions**, **62 tables**. Freeze verification against `v0.7.0-m7-complete` **was** and remains empty for M4, M5, M6 and M7; that part stands.

- **`docs/ARCHITECT_REVIEW.md` now carries the four sections it was missing** — a consolidated **Architect decisions** section stating each decision *with the alternative it rejected and why that alternative is wrong* (AD-19, AD-20, AQ-9, AQ-11, AQ-13, the watermark, the derived UUID, chain depth, three chain roles); **Open business decisions**, with BD-11 stated in full and the four carried-forward questions; **Technical debt**, TD-19 to TD-22 with what changed this phase; and a 23-item **Architect approval checklist** whose last two rows are the ones that matter — *M8 closure requested: **no***, *M9 started: **no***.

- **`docs/PROJECT_PROGRESS.md` replaced from `## Implementation Summary` onward** and is now the project's single progress-and-suggestions document: current completion status, remaining milestones, pending business decisions, technical debt, architect suggestions, recommended next steps, and the implementation notes from this session worth carrying forward. **No additional progress markdown file was created.** The eighteen module sections above it are unchanged — they are the detailed record the summary sits on.

- **`docs/PROJECT_STATUS.md` brought current.** Its body had drifted badly: Overall Progress, Current Work, Next Milestone, Build Status and Test Status all still described M6 and a 368-test suite, while the header already described M8. Rewritten, plus M7's closure detail, M8's status detail and both rows added to the Milestone Closure Record — M8's row records **"Not closed"** rather than a date, because that is what is true.

- **`README.md`** corrected: it claimed M8 was "at design stage — nothing implemented".

- **The branch name `feature/m0-setup` is now eight milestones stale.** Renaming rewrites the remote ref, so it stays a decision rather than a silent fix — but it is a real handover hazard for anyone new to the repository.

## 2026-08-14 — M8 Phase 3: supersession takes effect, Review documents, SUPERSEDED watermark

Third slice of M8 (PPT S44). One additive table. **No file in M4, M5, M6 or M7 was modified** — notable because this phase changes how M7 approves a result, renders a document and serves one.

- **Supersession takes effect at the Review's approval, not at its raising.** Phase 1 recorded the intent; Phase 3 records the effect. The timing is the substance of AQ-9, not a detail of it: **a Review that is cancelled, returned or never approved must leave the original standing.** If raising a Review superseded the original immediately, a department could invalidate a signed promotion list by filing a correction it never pursued. Recorded on both timelines and notified to the department with both DPC codes — a department holding a signed list needs to know which list replaces it, and it knows them by code.

- **The watermark is applied on delivery, never in storage.** The obvious implementation rewrites or regenerates the stored file — and that breaks two things at once: a generated document is immutable (AD-4), and its SHA-256 is what proves a department's copy is the copy the Commission issued. Rewriting the bytes invalidates every copy already in circulation. So the file on disk stays exactly as signed and the watermark is prepended when the document is **served**. `contents()` is what a reader gets; `storedContents()` and `verify()` are what was issued. Three tests pin the split, including that **a superseded document still verifies**. It is also the honest model of what happened: the document *was* issued as it stands and was *later* superseded — baking the watermark in would assert it had been superseded at the moment of issue.

- **The configuration snapshot UUID is derived from the rules, not generated at random (Directive 1).** A random UUID would satisfy the letter of the directive and record nothing useful — only that a snapshot existed. It is a UUIDv5 over the canonical snapshot with keys sorted, so two calculations under identical rules carry the **same** UUID and *"were these two decided under the same rules?"* becomes an equality check. Stored with the original calculation run UUID (Directive 2) in `dpc_review_calculations`, on the M8 side because `dpc_calculation_runs` belongs to frozen M5.

- **A Review's documents state what they correct (AQ-13)** — original DPC, original date, review DPC, review date and reasons, before anything else on the page. A promotion list dated today that silently corrects one from years ago is misleading on its face, and the reader most likely to be misled is the one reading it long afterwards with nobody left to ask.

- **A provisional Review result cannot be published**, inheriting M7's Directive 5 — and it fires in practice, because US3's case is exactly BD-2/BD-3. The companion test shows the other side: a Review that **corrects the very CR it was raised for** produces a clean result and publishes.

- **`docs/ARCHITECTURE_BASELINE_v0.7.md` created (Directive 3).** Documents all four decorators M8 now places over M7, the rule-reuse mechanism in full, **what the pattern costs** — indirection; the class that runs is not the class the caller names — and **where it stops**: decoration adds behaviour for a new milestone, it does not repair an old one, and a fifth decorator would be the point to ask whether the boundary is in the right place.

- **TD-22 stays open by directive.** The baseline document records why: solving a concurrency residual before concurrent workers exist would be designing against a hypothesis.

- 19 new tests, **607 passed / 1865 assertions**. Pint clean, PHPStan 0 errors, `composer audit` clean on the first attempt, `migrate:fresh --seed` OK (61 tables).

**M8 closure is not requested.** Everything M8 was scoped to build now exists. What is not decided is **BD-11** — what supersession means for an officer already promoted under the reviewed DPC. The system records the supersession and tells the department; it asserts nothing about the promotion itself, and it should not until that is answered.

## 2026-08-14 — M8 Phase 2: Review calculation under the original's rules

Second slice of M8 (PPT S38, S41–S44). One additive migration. **No file in M4, M5, M6 or M7 was modified** — notable given that this phase changes how M5's engine is invoked and how M7's calculation service behaves.

- **A Review calculates under the original's rules, never today's (AQ-11).** A Review may be conducted years after the DPC it corrects; applying current configuration would mean a correction raised to fix a mis-typed CR **also silently re-decided eligibility** under thresholds that did not exist when the DPC was held. The test that matters: a later notification lowers the Class-I threshold from 15 to 14, and US3 — who scored 14 and was refused — **stays refused**.

- **The seam was already there, built by M5 itself.** `DpcCalculationService` calls `RuleSet::fromConfig()` internally and takes no rule set, and M5 is engine-frozen. But `fromConfig()` and `fromSnapshot()` share one `build()`, so a stored `configuration_snapshot` has exactly the shape of `config('dpc.scoring')` — and under AD-3, business rules *are* configuration. Running under the original's rules is therefore running under the original's configuration, restored in a `finally`. **No second scoring path exists**, which is the point: a parallel "historic rules" implementation would be a second engine to keep correct.

- **Added by decoration, not by edit.** `ReviewAwareCalculationService` extends M7's service and is bound over it in the container, so `git diff v0.7.0-m7-complete -- app/Domain/Proceeding` stays empty while M7's behaviour is genuinely extended. A Fresh DPC passes straight through, and a test asserts it still uses today's rules — the decorator has to be invisible to everything that is not a Review. Recorded as **AD-20**, now the standing pattern for extending a complete milestone.

- **Two details that would have been wrong if left implicit.** Configuration is restored in a `finally`, because it is process-global and a failed calculation leaving a 2017 rule set in place would silently corrupt the *next* one. And `engine_version` is preserved across the override: the rules are the original's, but the engine doing the work is this build, and replacing `dpc.scoring` wholesale would have blanked it — every Review document then claiming an unknown engine.

- **Chain depth is not the review number (Directive 2).** It would have been easy to store one value under two names. `review_number` is this Review's position among reviews; `chain_depth` counts DPCs in the chain including the original, so the first Review is depth 2. Depth counts DPCs; the number counts corrections. Stored rather than derived because walking a chain to measure it is exactly the query that gets slower as the thing it measures grows.

- **Three chain roles, not two (Directive 4).** A superseded **Review** and the **Original** are both "not the operative DPC", but they are not the same thing — the original is what the department first decided; a superseded Review is a correction that was itself corrected. Collapsing them loses the distinction a tribunal asks about first. The role is derived from **position**, not from `superseded_at`, keeping the structural view separate from Phase 3's job of making supersession take legal effect.

- **Original DPC version persisted (Directive 1).** `original_proposal_version` records the content fingerprint of the DPC being corrected, from its concluded proceeding. The original is immutable so it cannot change — recording it means the claim rests on evidence rather than on that assumption holding.

- **Full M6 reuse confirmed end to end (AQ-12).** A Review is allotted, accepted, scheduled, opened, calculated and approved exactly as a Fresh DPC. No new workflow, engine, renderer or proceeding — which is what S35's *"exactly the same process"* promised.

- **`docs/EVENT_CATALOG.md`** now carries `dpc_review.created` and `dpc_review.superseded_by`, and explains why one act writes two events on two subjects: a correction chain must be visible from either end.

- 18 new tests, **588 passed / 1817 assertions**. Pint clean, PHPStan 0 errors, `composer audit` clean on the first attempt, `migrate:fresh --seed` OK (60 tables).

**One residual recorded rather than buried (TD-22):** the configuration override is process-global. It is restored on every exit path and cannot interleave under PHP-FPM, but if calculation is ever queued or run in a long-lived concurrent worker, it becomes real.

## 2026-08-14 — M8 Phase 1: Review proposal and the correction chain

First slice of M8 (PPT S35, S37–S38). Two additive tables. **No file in M4, M5, M6 or M7 was modified** — verified by `git diff v0.7.0-m7-complete`.

- **The chain is one row read from both ends.** `dpc_review_links` carries the review's and the original's foreign keys together, so "what reviews this?" and "what does this review?" are the same table queried from opposite directions (AQ-9). It is deliberately **not** a column on `dpc_proposals`: writing there would mean editing the frozen M4 model or writing behind its immutability guard — the trap TD-19 and TD-21 already record.

- **Supersession is recorded at creation but takes effect only at approval.** `superseded_at` stays null. Raising a Review must not invalidate the original — a Review that is cancelled or never approved leaves the original standing, so the link records the *intent* now and the *effect* when it happens.

- **Copy-on-create, by `getAttributes()` rather than a field list.** S38 is titled *Correction in Tabulation Chart* and shows a populated chart; making a department retype ten officers to fix one CR entry would guarantee fresh transcription errors while fixing an old one. Copying by explicit field list would have meant that a column added to a section table by a later migration is silently dropped from every copy — design risk R3, closed structurally rather than by vigilance.

- **The Review owns its own rows, and that is the milestone's central guarantee.** Two tests: the Review's row ids never intersect the original's, and editing a copied CR grade leaves the original byte-identical.

- **Directive 3 collided with a freeze, and was solved without touching it.** "Display THIS IS A REVIEW DPC throughout the Review workflow" is a UI directive — but a Review traverses **M4's own frozen proposal screens**. The banner is rendered from `layouts/app.blade.php` (M0, not frozen) via a view composer that checks whether the route's bound proposal is a Review. No M4 view is touched, and the banner reaches every screen a Review passes through — including screens M4 has not built yet. It matters because a Review looks *exactly* like a Fresh DPC, which is what S35 promises and precisely why someone opening one must not have to notice a type field to realise they are correcting a promotion already decided and published.

- **A real finding in existing schema, implemented:** M4 prepared `review_reason_id` — singular — while S37 renders the reasons as checkboxes and several routinely apply at once. The pivot is additive; the singular column is left **unused** rather than repurposed.

- **PHPStan found a logic bug, not a type complaint.** It flagged a nullsafe operator as unnecessary — because `assertReviewable()` guarantees the original has no link *as an original*. Following that through showed the chain lookup was simply wrong: a Review of a Review inherits its chain from the link that makes the **original itself a Review**, a different query. Left as written, every Review of a Review would have started a fresh chain numbered 1, and Directives 1 and 2 would have been quietly satisfied with wrong values.

- **Strict attribute mode earned its keep on its first outing outside M7.** `created_by` is not fillable on the frozen `DpcProposal`, so the copy would have discarded it and **reported success**. `Model::preventSilentlyDiscardingAttributes()`, added in M7 Phase 4 after exactly this bug appeared twice, turned it into an exception on the first run.

- 33 new tests, **570 passed / 1778 assertions**. Pint clean, PHPStan 0 errors, `composer audit` clean on the first attempt, `migrate:fresh --seed` OK (60 tables).

**PPT comparison, S35 and S37 — no divergence.** All six review grounds offered; department-initiated only, with Commission-initiated review out of band per AQ-10; the original-DPC selector restricted to concluded, unreviewed, own-department DPCs; multiple reasons recorded together.

## 2026-08-14 — M8 design: Review DPC

Design only. **Nothing implemented.** Repository clean and tagged `v0.7.0-m7-complete` before design work began.

- **`docs/design/09-m8-review-dpc.md`** — 16 sections covering PPT S35–S44, including the Risk Assessment, Database Design and Workflow Design sections directed, and the **first "Deferred Because of Business"** section under the new standing rule.

- **The milestone is small, and the design says why.** S35 ends with a sentence unusually direct for this deck: *"the Review DPC process will be exactly the same as the Fresh DPC process."* S36–S44 confirm it — every screen in the Review flow is a screen the system already has. **M8 adds no new workflow, no new engine, no new document renderer and no new proceeding.** The design's job was to find where "exactly the same" is not quite true, and there are four places: a Review has an **origin**, it **starts from the original's data**, it **changes a decision already made**, and it may run **years later under rules that have since changed**. The last two are the milestone; the first two are wiring.

- **One real finding in the existing schema.** M4 prepared `review_reason_id` — **singular**. S37 renders the reasons as **checkboxes**, and in practice several apply at once: a name omitted from the seniority list is frequently also a roster-point error. Since M4 is frozen the fix is an additive pivot table, and the singular column is left **unused** rather than repurposed — quietly redefining a frozen column's meaning is worse than leaving it idle.

- **Copy-on-create, not reference.** S38 is titled *Correction in Tabulation Chart* and shows a populated chart; a Review that made the department retype ten officers to fix one CR entry would guarantee new transcription errors while fixing an old one. The Review's sections are copied as **new rows**, so the original is never read at edit time, the Review is self-contained like every other evidence record, and *what changed* is answerable by comparing two snapshots — which is exactly what a tribunal asks of a Review.

- **Six architect questions raised (AQ-9 … AQ-14), of which only one genuinely blocks.** **AQ-11** — does a Review apply today's rules or the original's? — is recommended as the original's, because a Review raised to fix a mis-typed CR should not silently re-decide eligibility under thresholds that did not exist when the DPC was held. Answering it that way also gives M5's `configuration_snapshot` its first real use; a stored field nothing reads is a field that silently rots. The other four have defaults that can be proceeded on and corrected cheaply.

- **AQ-9 is the exception, and it is blocked on business.** What a Review does to the original — supersede, annotate, or nothing — cannot be decided until **BD-11** says what happens to an officer **already promoted** under the reviewed DPC. That is a question about the legal effect of a promotion order, not about how to model one. **BD-11 raised.** M8 phases 1 and 2 proceed regardless; phase 3 does not.

- **BD-2/BD-3 acquire a sharper edge in M8.** "Reservation rule not followed" is one of the six listed **grounds for review** — so a DPC may be reviewed *precisely because* a reservation rule was misapplied, and the rule in question may be one of the unsettled ones. A Review raised on that ground would produce a provisional correction of a provisional result.

## 2026-08-14 — M7 CLOSED · three registers separated · timeline and milestone summary

**M7 is approved and closed** at tag `v0.7.0-m7-complete`. Business questions remain open and are tracked **only** in `docs/PENDING_BUSINESS_DECISIONS.md`; **M7 is not reopened for them** (architect ruling).

- **`docs/ARCHITECTURE_DECISIONS.md` created — the third register, and the one that was missing.** Business questions had a home; known imperfections had a home; **the decisions that shaped the architecture lived only in `docs/ARCHITECT_REVIEW.md`, which is replaced in full at every phase.** A ruling made in M4 Phase 5 and never restated since survived only in a git history nobody reads — the same failure mode `TECHNICAL_DEBT.md` was created to fix, applied to different information. 18 decisions recorded (AD-1 … AD-18), each with **the consequence as well as the decision**, because a decision whose cost is unwritten gets re-litigated by whoever next finds it inconvenient.

  Three entries are worth naming. **AD-10** (pure engines) records that the payoff arrived two milestones after the decision: because the M5 engine reads only what it is handed, M7 could score a *corrected* chart without changing one line of frozen code. **AD-15** (freezes) records what the freeze **forecloses** — four small improvements (TD-16, TD-19, TD-20, TD-21) are visibly blocked by it and were left undone rather than take the freeze quietly. **AD-17** records that every significant defect in this project was found by applying a discipline, never by running the gate.

- **`docs/MILESTONE_SUMMARY.md` created** — one section per milestone: what it delivered, what governs it now, what it left behind. It ends with the pattern all eight share: the defects that mattered were found by the Demo Readiness Rule, by implementing a directive properly, by a full PPT comparison, or by asserting an outcome rather than a rendering. Every one passed all four gates while broken.

- **`docs/PROJECT_TIMELINE.md` created** — what happened when, and *why*. It records 2026-08-13 as the day eight standing rules were established, each in response to something that had just gone wrong: the audit trail inert since M1, slide 33's answer key, the notification rollback, three milestones sitting uncommitted. It closes with what actually gates production, ordered by lead time — the SMS gateway first, because it is a procurement task rather than an engineering one.

- **Standing rule adopted (Directive 4):** every future milestone design carries a **"Deferred Because of Business"** section, so what a milestone *cannot* settle is stated at design time rather than discovered at closure. M7 learned this the hard way — BD-9 and BD-10 surfaced in the final phase's PPT comparison.

- `PROJECT_STATUS.md` now reads **M7 Complete**. README's document map lists sixteen mandatory documents and states plainly why the three registers are separate.

- Gates green: Pint · PHPStan 0 errors · **537 passed / 1682 assertions** · `composer audit` clean · `migrate:fresh --seed` OK (58 tables). M4, M5 and M6 untouched, verified by diff.

## 2026-08-14 — M7 business clarification: register moved, validation pack, generator commit hash

**M7 implementation approved. M7 milestone NOT closed** — status is now **Technically Complete · Business Clarification Pending**.

- **BD-9 and BD-10 ruled: raise to CGPSC, do not implement assumptions, do not modify M5.** Both were found by the Phase 4 full PPT comparison against slide 34's stated result. Neither is now guessed at in code, and the frozen engine stays untouched.

- **`docs/PENDING_BUSINESS_DECISIONS.md`** moved from `docs/business/` to `docs/` per directive, and restructured to lead with a **Blocking M7 closure** section naming BD-2, BD-3, BD-9 and BD-10 with the single sentence each changes. The other six items are kept rather than dropped — losing BD-1 (`PUNISHMENT`) or BD-4 (CSC eSign format) to make the file shorter would trade real information for tidiness. Every reference across eight documents updated; `docs/business/` is gone, so there is exactly one register.

- **`docs/UAT_BUSINESS_VALIDATION.md` created** — the four blocking questions put to **CGPSC officials, not engineers**. Each states the rule as printed, what the presentation does *not* say, the actual case that raises it, what the system does today, and the options to choose between. BD-9 leads with the Commission's own worked example: two vacancies, US4 is SC, there is no SC vacancy, and slide 34 places them on the **unreserved** waiting list while the system places them on `SC_W`. It also states plainly that a "provisional" result is the system declining to guess, not a defect — and lists what is deliberately switched off, so UAT time is not spent re-reporting it.

- **Generator commit hash on every document (Directive 4).** `generator_version` says *which renderer* — `html-v1` — and that is a label somebody has to remember to bump; two builds can both claim `html-v1` and render differently. The commit hash says *which build*, with nothing to remember. For a document produced in evidence years later, "rendered by html-v1" is an assertion; "rendered by commit a1b2c3…" is a fact that can be checked out and re-run. Recorded on the document row, in the document-generated event, and printed in the footer of both documents. Nullable and recorded honestly as `unknown` in a development checkout rather than refusing to generate.

- **M8 does not start** until business answers or explicitly defers BD-9 and BD-10.

- 2 new tests, **537 passed / 1682 assertions**. Pint clean, PHPStan 0 errors, `composer audit` clean, `migrate:fresh --seed` OK (58 tables). M4, M5 and M6 untouched, verified by diff.

## 2026-08-14 — M7 Phase 4: CIR, proceedings document and promotion list

Fourth slice of M7 (PPT S33–S34, S45). Two new tables plus two additive columns. **No file in M4, M5 or M6 was modified** — verified by full-repository diff against `v0.6.0-m6-complete`.

- **`CIR` implemented (architect decision, not deferred to M9).** A hold reserves the **promotion action** for an officer who still takes part in the DPC, and it is orthogonal to merit — the PPT's own US8 scores 20, qualifies for a Super Seat, and is held anyway on a missing 2016 property return. Detection runs **inside CALCULATE**, because holds and scores read the same effective chart and detecting them separately would let the two drift apart invisibly. Automatic holds are **re-derived, not accumulated**: correcting the cause *releases* the hold rather than deleting it, so the record of having been held survives. A manual hold is never touched by detection, and an automatic hold cannot be released by hand — it would simply reappear.

- **Two documents, deliberately separate.** The **proceedings document** records what the Commission *did* — who sat, what was corrected and why, what was held. The **promotion list** records what it *decided*. A department contesting a promotion needs the second; a tribunal examining how it was reached needs the first.

- **Render → store → sign → publish, with the separation load-bearing.** Rendering is behind the `DocumentRenderer` contract so PDF later replaces HTML without touching the approval path (AQ-3). Storage goes through the abstraction, so `FEATURE_S3_STORAGE` decides local or S3 and nothing in M7 knows which. Signing goes through `SignatureService` — the integration point for CSC DigiSign, whose **API is not implemented**. Publication is separate so a **provisional result can be rendered and read by the Commission but never released to a department** (Directive 5).

- **Documents are versioned, never overwritten.** Re-issuing creates version 2; version 1's hash is asserted unchanged. The artefact a department was already given must not change afterwards.

- **A silent-failure class closed permanently.** Twice in M7 a newly added column failed to reach its model: `update()` discards non-fillable attributes and **reports success**, so the write is lost with no error anywhere, surfacing only as a puzzling assertion failure later. `Model::preventSilentlyDiscardingAttributes()` is now on outside production. The full suite — including every frozen milestone's tests — passes with it enabled, which is the evidence it changes no existing behaviour.

- **Two directives could not be implemented literally, and both are reported rather than fudged.** The **calculation engine version** is not on the M5 run row: putting it there meant either editing frozen M5 source or writing to M5's table behind its own immutability guard, so it is recorded on every calculate event, on the proceeding, and on every document instead (TD-21). And **`dpc_proposals` had no UUID** at all — added and backfilled by migration following M6's `users.uuid` precedent, with new proposals minted by `ProceedingService` via direct update rather than by adding `HasUuids` to a frozen model (TD-19).

- **Workflow Version moved 1 → 2**, because M7 added a workflow. That is exactly the condition the documented bump rule names. Schema Version and Payload Version did **not** move: M7 added properties in quantity but changed the meaning of no existing key, so a subscriber written against version 1 of either keeps working. The contrast is why the three are kept independent.

- **`docs/EVENT_CATALOG.md` extended** with the full `dpc_proceeding` set — transitions, corrections, calculation, holds and documents — plus `ProceedingDocumentsPublished`, the one published event that is *not* a workflow transition. `calculate` and `recalculated` are now genuinely distinct events rather than one event with a flag: a recalculation supersedes a result the Commission may already have acted on, and a consumer that cannot tell them apart cannot know that.

- 51 new tests, **535 passed / 1679 assertions**. Pint clean, PHPStan 0 errors, `composer audit` clean on the first attempt, `migrate:fresh --seed` OK (58 tables).

**The full PPT comparison found two divergences from S34's stated result, and M7 closure was NOT requested because of them.** US4 is an **SC** officer in a DPC with **no SC vacancy**: the PPT places them on the **unreserved** waiting list (`UR_W`), the system on `SC_W`. The implied rule — *an officer whose category has no vacancy waits against the unreserved list* — is stated nowhere in either deck, sits in frozen M5 placement logic, and is a reservation rule adjacent to BD-2/BD-3. Raised as **BD-9**. Separately, US4's `C.CASE` note is composed onto the PPT's remark as `UR_W CC`; the mechanism built this phase for `CIR` would carry it, but what a court case *does* to a promotion is unknown and adjacent to BD-1. Raised as **BD-10**. Neither is a defect in Phase 4's work — and neither should be discovered after M7 is declared closed.

## 2026-08-14 — M7 Phase 3: CALCULATE, result, approval and cancellation

Third slice of M7 (PPT S33 `CALCULATE` / `CANCEL DPC`, S34 `APPROVE BY CGPSC`). One additive migration. **No file in M4, M5 or M6 was modified** — verified by full-repository diff against `v0.6.0-m6-complete`.

- **The frozen M5 engine is called unchanged, on corrected data.** This was the phase's central problem: `CALCULATE` must score the *corrected* chart, but `DpcCalculationService` reads the proposal's evaluation rows and M5 is frozen. The solution needs no M5 change — `ProceedingCalculationService` materialises corrected **in-memory copies** of the rows and substitutes them on the proposal's loaded relation before handing it over. `DpcCalculationService::calculate()` opens with `loadMissing()`, which leaves an already-loaded relation alone, so the engine consumes corrected rows without knowing they were corrected. Nothing is persisted through those copies, and a test asserts the M4 row is unchanged after a calculation. **This was the phase where the freeze was most at risk**, because the shortest path to a corrected calculation would have been to change M5's own entry point.

- **The PPT's own worked example is reproduced exactly, end to end.** S33 shows US3 scoring **14** and marked `NE`; after `TRIBAL 2021` is recorded it scores **15** and moves to the waiting list. A test drives calculate → correct → recalculate and asserts 14 → `NE`, then 15 → eligible → `waiting`, with US1 taking the single UR vacancy. The correction pipeline and the frozen engine agree with the Commission's own arithmetic.

- **Stale-calculation protection is decided by correction sequence, not by timestamp.** The obvious implementation compares `applied_at` with `executed_at` — and both have **second granularity**, so a correction and a recalculation inside one second would be indistinguishable, and the failure would be *silent*: an approval would sail through against a result that does not reflect the last correction. Directive 1's correction sequence closes it exactly; a run records the high-water sequence it saw, and a result is stale when a higher sequence exists. A test freezes the clock at a single instant, corrects and recalculates within it, and asserts staleness is still detected. The refusal **names the officers affected** — a message the Commission cannot act on is one it will click past.

- **Two runs, two questions.** `latest_calculation_run_id` moves with every recalculation; `final_calculation_run_id` is pinned at approval and never moves. Conflating them would let a later calculation retrospectively change what was approved. An approved result screen always shows the run that was attested to.

- **Concurrency closed on two fronts.** `lockForUpdate` re-read before any approval or cancellation decision — the M4 pattern, not M3's (TD-5). And the `(proceeding, sequence)` unique index means two simultaneous corrections fail loudly rather than quietly producing two corrections numbered 4, which closes the race carried forward from Phase 2.

- **Directive 3 needed more than it asked for.** `effective_value` is now on every correction event — but implementing it showed it is **identical to `new_value` in every case**, since a correction's effective value *is* what it sets. The real replay gap was elsewhere: after a chain of corrections a later event's `previous_value` is what the *previous correction* made it, not what the department sent, so the old-beside-new display could not be rebuilt from the timeline alone. `submitted_value` was added alongside, and both are now on every event.

- **`CIR` was in the design's Phase 3 but not in the directive's "implement only" list, so it was not built.** The consequence is stated rather than left to be discovered: the S34 result currently cannot show that a promotion is reserved. In the PPT's own example US8 appears as `ST SS CIR` — top-scoring, Super-Seated, and held on a missing property return; today's result would show `ST SS` and omit the hold. That is a gap in the legal output. Recommended for Phase 4, before documents.

- **Approval currently produces no signature.** `approve_result` declares `signature => false`, because signing needs a document to sign and documents are Phase 4 with CSC DigiSign. Declaring `true` now would be the placeholder Directive 4 forbids. Between now and Phase 4 the system can therefore produce an approved but unsigned promotion list — acceptable inside an unshipped milestone, not acceptable as how M7 closes.

- **A defect our own tests caught, worth recording.** `lists()` originally partitioned results with a **string** comparison against a column cast to an **enum**, silently returning three empty lists. The screen test passed anyway — `assertSee('MAIN LIST')` matches a static heading whether or not the list beneath it has rows. Only the worked-example test, which asserts an officer's actual placement, caught it. The screen test now asserts placement. **The fourth defect in M7 found by asserting an outcome rather than a rendering.**

- 36 new tests, **484 passed / 1569 assertions**. Pint clean, PHPStan 0 errors, `composer audit` clean on the first attempt, `migrate:fresh --seed` OK (56 tables).

**BD-2/BD-3 is now live rather than theoretical.** A run with unsettled reserved-relaxation cases sets `has_pending_decisions`, the result screen marks it **provisional**, and the flag is carried on the approval timeline event. Approval is deliberately **not** blocked — blocking would resolve a business question in code, which Directive 5 forbids — so a provisional result can now be approved. It must not be published while flagged.

## 2026-08-14 — M7 Phase 2: the editable S33 workspace and Commission corrections

Second slice of M7 (PPT S33 `EDIT` / `TRIBAL` / `SAVE`). One additive migration. **No file in M4, M5 or M6 was modified** — verified by full-repository diff against `v0.6.0-m6-complete`, which shows changes only to `AppServiceProvider` (policy registration), `config/dpc.php`, `routes/web.php` and documentation. Every M7 file is new.

- **Corrections are recorded, never applied.** `ProceedingCorrectionService::record()` writes an append-only override; `dpc_evaluation_rows` is read and never written. `effectiveChart()` then assembles the department's submission with corrections applied, in **two queries** — one eager-load of rows with their per-year children, one for all in-force overrides, keyed in memory. That assembled chart is what the workspace renders and what Phase 3 will hand to the frozen M5 engine, which will neither know nor care where its input came from.

- **Withdrawal is itself a correction.** `DELETE` on a correction appends a *new* override restoring the previous value, with its own reason and author, and supersedes the original. An undo that erased the original would leave the record showing a value nobody ever chose. Both rows survive and both appear in the history.

- **A correction records the *effective* previous value, not the department's original.** When a second correction supersedes a first, its `previous_value` is what the first had made it — so a chain reads back step by step rather than as unrelated jumps from the original. Pinned by test.

- **Validation reuses M4's own rules.** `RecordCorrectionRequest` validates the corrected value through `DpcRowRules`, the same definitions manual entry and Excel import use, rather than restating them. Three code paths validating one field differently is how a bad CR grade eventually reaches a promotion list. A test asserts an invalid grade is refused **by M4's rule**, not by a parallel one.

- **No workflow transition was added.** A correction changes no state — the DPC is in exactly the same place before and after — so the ledger must not claim one (Directive 4). `dpc_proceeding` still declares only `open`. A test asserts the transition count is unchanged after a correction.

- **Directive 1 completed.** `closed_by`, `closed_by_name`, `closed_at` added, additive. The opening pair already existed: under AQ-6 the member who opens a sitting necessarily conducts it, so a separate `opened_by` would be a second name for one fact, and two columns that must agree are two that can disagree. Closure is **not** derived from `approved_at`/`cancelled_at` — those record when a decision was taken, closure records who ended the sitting. A test pins that the columns are writable in the same transaction that concludes the sitting; without it the terminal guard would reject every write the moment the status moved, and Phase 3 could never fill them.

- **Directive 3 completed for audit.** The audit table keys on a sequential id that never leaves the database, so `generateTags()` on both models puts the **proceeding UUID** into every audit row. Without it an audit export of corrections could not be tied back to the sitting anyone else can see.

- **Identity fields are deliberately not correctable.** `correctable_fields` covers CR grade, loyalty, property and tribal service — how an officer *performed*. Name, DOB, category and qualification are the department's record of who the officer *is*; correcting those at the sitting would change **who was considered**, not how they scored. Recorded in the config comment so the omission is not read as an oversight.

- 39 new tests, **448 passed / 1479 assertions**. Pint clean, PHPStan 0 errors, `composer audit` clean on the first attempt, `migrate:fresh --seed` OK (56 tables).

**PPT comparison, S33 — one display ambiguity documented, not resolved.** S33 renders the tribal annotation as `TRIBAL 2021` and S43 as `TRIBAL 2013`, which read as *years*; M4's frozen column is a *count* of years. Both cannot be literal. It changes no outcome — M5 turns any non-zero value into the single §5.2 bonus of +1, so `2021` and `3` score identically — so the workspace labels the field "Service in Tribal Area (years)" and stores what M4's column means, while `TRIBAL <n>` stays a Note annotation. Category B. Worth putting to CGPSC at UAT, because it changes what the printed result says rather than what it computes.

## 2026-08-14 — M7 Phase 1: proceedings entity, lifecycle and override model

First slice of M7 (PPT S31–S32, and the read-only chart of S33). Two additive tables; **M4, M5 and M6 were not touched**, verified by `git diff v0.6.0-m6-complete -- app/Domain/{Eligibility,Cgpsc,Dpc}` returning empty. That check is now a standing per-phase gate — it only became possible once the baseline tag existed.

- **`dpc_proceedings`** — the sitting as a first-class record: UUID public identifier, the schedule slot it was held under, the conducting member and a **snapshot of their name**, the actual opening time, and a content fingerprint of the department's submission. Frozen once terminal; while open, only the lifecycle columns Phase 3 populates may move. Who conducted it, when it opened and what it acted on are history the moment they are written — they are the evidence.

- **`dpc_proceeding_overrides`** — the correction record, append-only, model only in this phase (Phase 2 records into it). Every row carries UUID, previous value, new value, reason, actor and timestamp, per Directive 1. **This table is why M4 stays frozen:** the obvious implementation of S33's `EDIT` button is to update the evaluation row, and that is also the one that destroys the evidential model — a corrected row would silently contradict the signed M4 approval document that attested to it. A test asserts the M4 row is byte-identical after an override is recorded.

- **Replayable by construction (Directive 2).** `toTimelineProperties()` lives on the model rather than in the Phase 2 service, so the recording path and any future replaying subscriber cannot drift apart. Storing only the new value would have made correction history reconstructible solely by replaying M4 plus every prior override in order — precisely the business-table dependency the timeline is not permitted to have.

- **`dpc_proceeding` workflow** on the existing engine, declaring **only** the `open` transition. `approve_result` and `cancel_dpc` arrive with Phase 3, which produces them; a transition nothing can perform reads as supported behaviour when it is not. Subject is the `DpcProposal`, as in M6, so every CGPSC-side event stays on one ordered timeline thread.

- **AQ-5 implemented as ruled:** early opening is configurable and permissive by default, and the **actual** opening timestamp is recorded regardless. Three tests — permissive default, strict refusal, and the real time recorded either way.

- **AQ-6 implemented as ruled:** the Chairman may *read* any proceeding but conducts only what is allotted to him. Enforced in policy, controller and service. Re-allotment — which already exists and records its own reason — remains the route, so exactly one actor is ever accountable for a sitting.

- **The S33 controls are absent rather than inert.** `SAVE`, `EDIT`, `TRIBAL`, `CALCULATE` and `CANCEL DPC` belong to Phases 2 and 3. A button that does nothing is worse than no button.

- **Two defects found by our own reviews, before the gate.** The security review found the allottee check surfacing as a validation error rather than a 403 — which both reads oddly and confirms the record exists; it now authorises against the existing allotment policy. The performance review found an N+1 on the proceedings queue (one schedule lookup per row); it is now two keyed queries, following M6's pattern. Neither would have failed any gate.

- **TD-16 raised.** M5's content fingerprint is `private` on a frozen class, so M7 computes its own. The two are defensibly different — M5 fingerprints the *scoring input*, M7 the *whole submission* including loyalty and property returns, which change no score but decide whether an officer is held — but they overlap, and overlapping duplicated logic drifts. Raised for a ruling rather than resolved unilaterally.

- 41 new tests, **409 passed / 1378 assertions**. Pint clean, PHPStan 0 errors (one real nullsafe error found and fixed, not suppressed), `composer audit` clean, `migrate:fresh --seed` OK (56 tables).

**PPT comparison, S31–S32 — no new divergence.** S31's data-freeze rule turns out to be **already structurally enforced**: M4 freezes every section on approval, so a department cannot alter anything after forwarding. Phase 1 adds what was missing — the *evidence*, in the form of a submission fingerprint recorded at opening, so the claim is provable rather than arguable. One documented difference carried forward: S32 shows entry by DPC ID + Aadhaar OTP, replaced under approved decision Q1 by ordinary authentication and a per-member queue. Category B.

## 2026-08-14 — M7 design: DPC Proceedings & Result Generation

**Design only. No production code, no migration, no change to business logic or existing workflow.** Working tree clean at `c2deae4` before and after.

- **`docs/design/08-m7-proceedings.md`** — the full 30-section design for M7 (PPT S31–S34): four additive tables, a new `dpc_proceeding` workflow on the existing engine, six services, seven controllers, seven views, and four implementation phases. **No new framework and no new cross-cutting mechanism.** Every arrow into M4 and M5 is read-only, which is the structural expression of both freezes and is checkable by `git diff v0.6.0-m6-complete`.

- **The PPT turns out to contain a second worked answer key, and the frozen engine reproduces it.** S33 renders the same chart three times in sequence — as forwarded, after CALCULATE, and after Commission correction. US3 scores **14** and is `NE`; once `TRIBAL 2021` is recorded, US3 scores **15** and moves to `ST_W`. Checked by hand against the frozen engine: five most recent grades ख,क,क,क,क = 14, plus the tribal +1 = 15, meeting the ≥15 Class-I→I threshold. **Exact.** This is independent confirmation of the M5 engine from the customer's own numbers, and it settles the shape of two design questions at once — Commission corrections are real, and they must trigger recalculation. It becomes M7's acceptance test.

- **Ambiguity Q12 appears to dissolve rather than need a ruling.** The Conventional deck says "2 or more CR years missing"; the S14 label says "CR ≤ 4". With **six** CR columns on the chart these are the same condition stated from opposite ends. S33 shows both triggers live: US6 has four CR grades and is `CIR`; US8 scores 20, qualifies for a Super Seat, and is held anyway on a missing 2016 property return. Recorded as a documented interpretation; the ruling stays M9's.

- **US8 also establishes that a hold is orthogonal to merit** — top-scoring, Super-Seated, and still `CIR`. `CIR` is a procedural reservation of the promotion action, not a verdict on eligibility, which is why the design places it *outside* the frozen engine rather than extending `RemarkCode`.

- **A conflict with the archived SRS is documented rather than silently resolved (TD-15).** `docs/archive/DPC- SRS.docx` describes per-member voting, free-text meeting minutes, upload of an externally signed proceedings file, and a separate promotion-order generation and distribution module. **None of it appears in the PPT.** The PPT governs by standing rule, but the difference would roughly double M7, so it is raised as a scope question rather than assumed away.

- **`docs/ARCHITECT_REVIEW.md` replaced with eight blocking architect questions** (AQ-1 … AQ-8), each with background, why a decision is required, options, pros, cons, recommendation, future impact and default action. Preference questions were deliberately excluded; anything answerable from the PPT or the existing architecture was decided in the design instead. Only AQ-1 blocks the data model; AQ-3 (ambiguity Q7, the legal document format) blocks phase 4 alone, so a late ruling on it does not stall the milestone.

- **One sequencing recommendation recorded:** BD-2/BD-3 (reserved relaxation) should be settled before **phase 3**, not before phase 1. Phases 1 and 2 are unaffected. Phase 3 is where an unsettled rule stops merely marking a calculation provisional and starts determining what a signed promotion list says.

## 2026-08-13 — Repository baseline: `v0.6.0-m6-complete`

**No code written.** Repository verified, gated, cleaned, committed and tagged, to leave a clean reproducible state before M7.

- **TD-14 resolved — M4, M5 and M6 are committed.** The last commit had been `3133ac4 M3`: three closed, frozen milestones existed only in an uncommitted working tree with no stash. Now one commit, tagged **`v0.6.0-m6-complete`** (annotated), pushed with the branch. The loss risk was the obvious half; **the half that mattered more is that the freezes are now verifiable** — `git diff v0.6.0-m6-complete -- app/Domain/Eligibility` mechanically answers "has the frozen engine changed?" Until a commit existed at a milestone boundary there was nothing to diff against, so a freeze breach during M7 would have been indistinguishable from M7's own work.

- **Gates re-run before committing, all green:** Pint pass · PHPStan (Larastan level 6) 0 errors · PHPUnit 368 passed / 1281 assertions · `composer audit` no advisories · `migrate:fresh --seed` OK (54 tables, 10 seeders). Nothing needed fixing.

- **Cleanliness verified:** no backup, temporary, editor or conflict files; nothing over 2 MB; no `storage/` runtime artefacts; `node_modules` absent. `.idea/` and `.phpunit.result.cache` were already ignored. `.claude/settings.local.json` was untracked but not ignored — added to `.gitignore`. `design/`'s three empty subdirectories were left alone (protected, and git does not track empty directories anyway), as were `package.json` / `vite.config.js` / `.npmrc` — Laravel scaffolding rather than junk, so removing them would be a decision rather than a cleanup.

- **`README.md` promoted to a mandatory document and re-synchronized.** Two real desyncs: its document map predated the six newer mandatory documents, and it described `composer gate` without `composer audit` — mandatory since M4 Phase 6, and present in the composer script all along, so only the README was wrong. Added a milestone-state section so the three freezes are visible on the front page.

- **`docs/ARCHITECT_REVIEW.md` replaced with a handover** — repository status, gates, UAT readiness, remaining business decisions, M7, and five recommendations. It records that **the M7 design document does not yet exist**: approval is noted, but nothing has been written.

- Documentation verification: all eleven mandatory documents present and synchronized.

## 2026-08-13 — M6 closure, freeze, and UAT documentation set

**Documentation only. No source file was modified.** M6 approved and closed by the architect; M4, M5 and M6 are now all frozen.

- **M6 FROZEN.** No further functional changes; bug fixes only. All three completed workflow milestones are now under freeze: M4 architecture-frozen, M5 engine-frozen, M6 frozen.

- **`docs/INTEGRATION_ROADMAP.md`** — HRMS, CSC DigiSign, LG Directory, Government SMS, Government E-mail, Calendar and internal Notifications, each with its Feature Flag, Provider, Events Used, Dependencies and Current Status, plus the procedure for adding another. It records one assessment worth stating plainly: **the Government SMS gateway is the highest-value outstanding integration** — not because S26 notifications need it, but because **OTP delivery does, so without it no officer can complete verification in production**. Every other integration being absent makes the system less convenient; this one makes it unusable.

- **`docs/UAT_CHECKLIST.md`** — 9 sections, ~60 scenarios, one scenario to one expected outcome, written for business users with no technical vocabulary. Opens by stating what is deliberately switched off (SMS/e-mail, HRMS, real signature) so testers do not raise those as defects, and which two results will legitimately appear as "awaiting a business decision".

- **`docs/DEMO_SCRIPT.md`** — Parts 0–9 following the PPT (S12→S30), with a ★-marked 15-minute short version, the accounts and data to prepare beforehand, the six questions to expect with their answers, and what to do if something fails live.

- **`docs/TECHNICAL_DEBT.md` created — a permanent register.** Every known limitation, deferred item and recommendation now has a home that survives a phase boundary. **`docs/ARCHITECT_REVIEW.md` is replaced in full at each phase**, so until now a carried-forward risk survived only by being manually re-copied — a process that holds until it doesn't, and the item most likely to be dropped is an old P2 nobody has thought about in five phases. 14 open engineering items, 2 blocking business items, 7 documented interpretations, 8 resolved. Items move to Resolved rather than being deleted.

  The Resolved section records a pattern worth keeping visible: **four of the eight were found by applying a directive properly rather than by testing the feature that directive concerned** — the untested audit trail, the notification rollback, the driver-dependent time read, and the timeline ordering defect. Directives that look like documentation tasks have repeatedly turned out to be live bugs.

- **TD-14 raised — the three closed milestones are uncommitted.** Verifying "no source file was modified in this phase" meant reading `git status`, which showed the last commit is `3133ac4 M3`: **M4, M5 and M6, 133 changed paths, have never been committed** and there is no stash. The loss risk is the obvious part. The less obvious part is that **the freeze policies are currently unenforceable** — a freeze is a statement about a commit, and with no commit at a milestone boundary there is nothing to diff a suspected breach against, so a change to frozen M5 code during M7 would be indistinguishable from M7's own work. Nothing was committed; that is not ours to decide. Recommendation recorded: commit each closed milestone at its own boundary before M7.

- **UAT Readiness Review prepared** in `docs/ARCHITECT_REVIEW.md`, per instruction. No implementation begun; M7 awaits approval.

- Gates re-verified after the documentation additions: **368 tests / 1281 assertions**, Pint clean, PHPStan 0 errors, `composer audit` clean, `migrate:fresh --seed` OK (54 tables). `composer audit` **failed on its first run and passed on retry** — `curl error 28` reaching packagist, the network timeout already recorded as TD-2, reproduced on the very first gate run after the register predicted it. Not a code finding; it confirms that CI needs a retry on that step or builds will fail in a way that looks like a security gate failing.

## 2026-08-13 — M6 Phase 4: notification coverage and closeout — **MILESTONE M6 COMPLETE**

Final slice of M6 (PPT S26–S30). No new transitions and no new tables, as instructed. M4 architecture-frozen and M5 engine-frozen; neither touched throughout M6.

- **Notification coverage verified at the listener, not the event.** Earlier phases asserted the *event* carried the right recipients; these tests assert `SendWorkflowNotification` **resolves them to real users**. That is a different claim — the department-scoped role query and the explicit user-id list are two independent code paths, and only one of them can reach a CGPSC Member. A recording SMS sender captures who the application actually tried to message. Covered across all five transitions, plus the negative cases: an unrelated department is never notified, a deactivated user is skipped, and a Member who is no longer the allottee stops being notified.

- **A directive turned out to be a live bug.** Directive 3 — "timeline exports must sort by Occurred At then UUID, never sequential ids" — looked like a documentation task. It was not. **Every timeline and ledger read ordered by `occurred_at` alone, and `occurred_at` has second granularity** while a workflow transition writes its ledger and timeline rows inside one transaction. Events in the same second carried identical timestamps and came back in whatever order the database chose, so allot and accept performed in quick succession could present in either order, differently between reads. A history that reorders itself between reads is not a history, and it would have surfaced first in an export or a printed record. Fixed at all four read sites; a test writes five events inside a single frozen second and asserts repeated reads return an identical order.

- **`docs/EVENT_CATALOG.md` created** — every published event with its trigger, payload, timeline entry, audit entry and future subscribers, plus the consumption contract: ordering, idempotency, replay, dry run and failure behaviour.

- **Three independent versions documented.** **Workflow Version** (the state machines — value 1, documentation only per directive), **Schema Version** (a timeline row's `properties`), and **Notification Payload Version** (the event object). Deliberately independent: adding a transition cannot change how an existing timeline row reads, nor the event's shape, so tying them together would force consumers to re-verify against changes that cannot affect them. **None is bumped for an added key** — only for a change in the meaning of something that already existed.

- **Replay Dry Run documented, not built** — design, output shape, and the three situations it exists for: before enabling a new subscriber against months of history; after a bug that dropped notifications, to see what would be re-sent before anything reaches an officer; and to verify an audit export before it becomes evidence. The most useful thing it produces is the list of events it *cannot* replay.

- 9 new tests, **368 tests / 1281 assertions passing** (77 across M6's four phases). Pint clean, PHPStan 0 errors, `composer audit` clean, `migrate:fresh --seed` OK (54 tables).

**Full PPT verification, S26–S30 — no divergence.** S26's requirement that information reach the Administrative Head and Authorized Officer on **registered mobile *and* e-mail** is met on both channels for both recipients, verified at the listener. S28's Allotment Report carries STATUS and DEFAULT DPC DATE. S30 is complete including RE-ALLOTMENT and (RE) SCHEDULING with the old slot struck through beside the new — the slide's own worked move, `12/07/2026 2:00 PM` → `15/07/2026 2:00 PM`, is the scenario the Phase 3 tests use.

**M6 is complete.** Next per the roadmap is the **UAT Readiness Review** checkpoint before M7.

## 2026-08-13 — M6 Phase 3: re-scheduling and old-beside-new history

Third slice of M6 (PPT S30). **The full S26–S30 transition set now exists** — Phase 4 adds notification coverage and closeout, introducing no new transition. M4 architecture-frozen and M5 engine-frozen; neither touched.

- **`reschedule` transition** — allottee only, reason required, and the new slot must differ from the current one. **Append-only:** a new `dpc_schedules` row is written and the previous one superseded. Repeated rescheduling is supported and every step kept; a test moves a DPC twice and confirms three rows with exactly one current. A **superseded** row is still immutable — the interesting case, since that is the row a tamperer would target.
- **Old-beside-new display** — the scheduling screen and Scheduling Report show the superseded date and time struck through beside the new; the allotment screen and Allotment Report do the same for the previous allottee. Both resolve their superseded rows in **one keyed query**, not one per row. This reproduces S30's own worked example: `12/07/2026 2:00 PM` → `15/07/2026 2:00 PM`, which is the scenario the tests use.
- **Scheduling history screen** — accept, schedule and reschedule events rendered from the timeline alone, with reasons and the recorded time zone.

**Cross-cutting directives, implemented in the shared layer so every workflow inherits them:**

- **Actor snapshot on every timeline event** — `users.uuid` added and backfilled (additive; `id` remains primary and route key, so no relation or URL changed), plus `activity_timelines.actor_uuid` and `actor_name` stamped by `ActivityTimelineService`. **`causer_id` is a reference; the snapshot is the record.** A reference tells you who the actor is *now*; a snapshot tells you who acted *then*, and they diverge exactly where it counts — a renamed user makes the reference resolve to a different name, a removed user to nothing. **A test asserts renaming the actor does not change the record.** Existing rows were backfilled; rows whose causer has since gone stay null rather than acquiring a fabricated name.
- **Notification payload versioning** — `WorkflowTransitioned::PAYLOAD_VERSION = 1`, carried on every event and asserted by test. Bumped only when the meaning of an existing property changes, never when one is added, so a subscriber written against version 1 keeps working.
- **Time-zone preparation** — `dpc.timezone` (default `Asia/Kolkata`), recorded on every scheduling event. No conversion built. It exists because **a stored `14:00` is meaningless without the zone it was meant in**: when time-zone support is built, events written before it can still be resolved correctly, which would be impossible if the zone had only ever been implicit.
- **Timeline replay** — a test replays allot → accept → schedule → reschedule and rebuilds both a notification and a calendar entry **without reading a single business table**. What makes this work is that **supersession events record the previous values** (`previous_dpc_date`, `previous_dpc_time`, `previous_allotted_to_name`); without them, reproducing the old-beside-new display would mean walking a `superseded_by_id` chain through business tables — exactly what the timeline is not permitted to depend on. Each event's own UUID lets a replaying integration tell whether it has already emitted that event rather than duplicating it.

- 19 new tests, **359 tests / 1231 assertions passing**. 3 additive columns (54 tables). Pint clean, PHPStan 0 errors, `composer audit` clean, `migrate:fresh --seed` OK.
- `docs/WORKFLOWS.md` extended with Timeline Replay, Notification Version, Actor Snapshot and Future Time Zone Support, plus entry/exit criteria for every transition.
- Phase 4 (notification coverage + closeout) not started.

## 2026-08-13 — M6 Phase 2: member acceptance and scheduling

Second slice of M6 (PPT S29–S30). M4 architecture-frozen and M5 engine-frozen; neither touched.

- **`accept` and `schedule` transitions** added to the `dpc_cgpsc` workflow; `reschedule` stays out of config until Phase 3 produces it. **`AllotmentStatus::workflowState()`** now maps status → state, so the CGPSC workflow state remains **derived** rather than stored — tested that nothing about it touches M4's frozen `dpc_proposals`.
- **`dpc_schedules`** — UUID identifier, **append-only** on the same terms as allotments. `superseded_by_id` and `reschedule_reason` exist from Phase 2 though Phase 2 never populates them: they are the mechanism that makes overwriting impossible, and Phase 3 uses them. Edit and delete both throw at the model layer.
- **Acceptance & Scheduling screen and Scheduling Report (S30)** with SCHEDULING / DPC DATE / DPC TIME. A Member sees only their own DPCs; the Chairman sees all, since he may allot to himself and remains accountable for the Commission's schedule. Current schedules resolve in one keyed query rather than one per row.
- **`DpcAllotmentPolicy::act()`** — holding the Member role is not the same as being *this* DPC's member. Enforced at the policy and re-checked in the service, so a console caller is bound too.
- **Scheduling is not validated against the default DPC date** (architect Decision 4), and a past date is permitted — CGPSC may record a DPC already held. Enforcing either would be inventing a business rule the PPT does not state.

**Cross-cutting directives, implemented across the shared workflow layer so every workflow gets them:**

- **Timeline schema version** — `schema_version` on every timeline row, starting at 1, stamped by `ActivityTimelineService`; existing rows backfilled. Bumped only when the meaning of an existing key changes, never for an added key, so an exporter written against version 1 keeps working.
- **Workflow transition UUIDs** — `workflow_transitions.uuid`, backfilled and unique. Timeline rows got one too: an export that can be regenerated must identify its rows stably without leaking sequential ids.
- **Export readiness** — the version-1 timeline schema is documented in `docs/WORKFLOWS.md` §6b. This is why M6 records allottee *names* rather than ids alone, and scheduling records `scheduled_for` as a resolved ISO-8601 instant instead of leaving a consumer to join date and time.
- **Calendar integration stays decoupled** — no calendar code. `dpc_cgpsc.schedule` already carries everything an ICS entry needs, and `schedule_uuid` is the stable external key so a Phase 3 reschedule supersedes rather than duplicates.

**Two defects found while implementing the directives, both fixed:**

- **Directive 3 was not satisfied by the obvious implementation.** Making the listener queued with `afterCommit` and wrapping its delivery loop was not enough: `WorkflowTransitioned::dispatch()` runs *inside* the workflow transaction, so any listener that throws — a third-party subscriber, a future calendar integration, a misconfigured mailer — escapes the service and **rolls back the completed transition**. A test proved it. Fixed by isolating the dispatch in `WorkflowEngine::record()`, where the ledger and timeline rows are already written and a failure degrades to a logged warning. The guarantee now holds at three layers, and a test asserts a throwing listener cannot prevent a transition completing. This is also what will stop the future calendar integration from being able to break a DPC schedule.
- **`dpc_time` read differently per database.** MySQL's `TIME` column returns `H:i:s`; SQLite returns the stored string. A schedule written as `14:00` therefore read back differently in test and production — and a time that varies by driver cannot be reliably compared, exported, or handed to a calendar. Normalised to `H:i:s` on write.

- 23 new tests, **340 tests / 1160 assertions passing**. 1 new table + 3 additive columns (54 total). Pint clean, PHPStan 0 errors, `composer audit` clean, `migrate:fresh --seed` OK.
- Phase 3 (re-scheduling, and re-allotment/re-scheduling history shown old-beside-new as S30 does) not started.

## 2026-08-13 — M6 Phase 1: CGPSC allotment — workflow, allotment report, history

First slice of M6 (PPT S27–S28). M4 architecture-frozen and M5 engine-frozen; neither touched.

- **`dpc_cgpsc` workflow** — runs on the `DpcProposal` as subject under its own workflow name, so CGPSC-side state sits alongside the `dpc_proposal` workflow **without touching M4's frozen status enum**. The state is *derived* from the current allotment, never duplicated onto `dpc_proposals`, because two sources of truth can disagree. Only `allot` and `reallot` are declared; `accept`/`schedule`/`reschedule` are documented in `docs/WORKFLOWS.md` as the approved design but stay out of config until Phase 2 produces them.
- **`dpc_allotments`** — UUID public identifier, **append-only**. Re-allotting writes a new row and marks the previous one superseded via `superseded_by_id`; the model rejects every other update and rejects deletion outright, enforced at the model layer so the guarantee survives any caller.
- **Chairman allotment screen and Allotment Report (S28)** — allot inline, re-allot behind a disclosure with a mandatory reason, and the report showing **STATUS** and **DEFAULT DPC DATE** exactly as the slide does. All current allotments are resolved in one keyed query rather than one per row — the report is a list, and an N+1 there would grow with the Commission's workload.
- **Allotment history rendered purely from timeline records** (architect Directive 3). `historyFromTimeline()` touches no allotment row, so if the timeline could not answer the question it would visibly fail rather than quietly fall back to current state. A test reconstructs a two-step history — including who the DPC was taken *from* — without reading the table.
- **Decisions applied:** members resolved dynamically from the role, not limited to five (tested with seven extra members); **default DPC date is manual Chairman input, never derived**, and kept optional because requiring it would itself be an invented rule; no member-decline transition — re-allotment is the Chairman's remedy; scheduling validation deferred to Phase 2 as informational.
- **`DpcAllotmentPolicy` is the first policy in the project with no department check** — deliberately. CGPSC users hold no department; a DPC reaches CGPSC precisely by leaving its department's control. The compensating control is that the role gate is applied at both middleware and policy, and tested from the department side.

**Shared-infrastructure change, flagged rather than buried.** Implementing the notification decision exposed that `SendWorkflowNotification` resolves recipients as *"users holding role X within the subject's department"* — which **can never reach a CGPSC Chairman or Member**, since they hold no department. No configuration could fix it: a role cannot express "this specific member". Three **additive** changes were made, all optional-parameter defaults so every existing M3/M4 caller behaves exactly as before: `WorkflowTransitioned` gained `notifyUserIds`; `SendWorkflowNotification` now resolves department-roles **or** named users; and `WorkflowEngine::record()` gained `$properties` and `$notifyUserIds`. The `$properties` addition is what makes Directive 3 achievable at all — the engine previously wrote a timeline entry containing only from/to/remarks, from which you could not tell *who* a DPC was allotted to.

- Re-allotment notifies the **outgoing** member as well as the new one — they may already have been preparing for a DPC that is no longer theirs.
- **`docs/WORKFLOWS.md` created** as directed: state diagrams, allowed transitions, role matrix, audit events, timeline events, notifications, rate limiting, and the design rules every workflow follows.
- 26 new tests, **317 tests / 1090 assertions passing**. 1 new table (53 total). Pint clean, PHPStan 0 errors, `composer audit` clean, `migrate:fresh --seed` OK.
- Phase 2 (member acceptance, scheduling, `dpc_schedules`) not started.

## 2026-08-13 — **M5 ENGINE FREEZE announced** · business-decision register · PPT classification

M5 approved and officially complete. Post-approval directives applied. No implementation in this entry — M6 is at design stage, awaiting approval.

**M5 ENGINE FREEZE — in force.** The **Eligibility Algorithm**, **Scoring Algorithm**, **Zone Logic** and **Super Seat Logic** are frozen. Future changes only through **Configuration**, **Master Data**, or **Government Notification** — never source code. Bug fixes and security fixes remain permitted.

Only *approved* rules are frozen: unresolved business rules (BD-1 PUNISHMENT, BD-2/BD-3 reserved relaxation) are explicitly excluded, so settling them changes behaviour through configuration without breaching the freeze. The freeze is enforceable rather than aspirational — every threshold is configuration, each rule set is labelled by `rule_version`, every run stores its own snapshot, 13 golden tests fail if a value moves unintentionally, and `RuleSet::fromSnapshot()` keeps superseded rule sets executable.

**`docs/PENDING_BUSINESS_DECISIONS.md` created.** Business questions no longer live in `ARCHITECT_REVIEW.md`, which is now engineering-only. Eight open items, each with severity, what it blocks, what CGPSC must confirm, and what the system does meanwhile — including **BD-1 (does PUNISHMENT disqualify?)**, ruled a business rule and not implemented. Closed items are retained so settled questions are not reopened. `docs/design/06-ambiguities.md` redirects there.

**Every PPT difference is now classified** as Category A (implementation bug — none open), B (documentation difference), C (business clarification — all moved to the register), or D (future milestone).

**Worked-example inventory across all 52 slides** (Directive 1: a PPT worked example becomes a golden test automatically). Worked calculations appear on S17/S18/S24/S25/S33/S34/S38/S40/S43/S44/S48/S51. The M4/M5 ones are golden tested; **S34's MAIN LIST is recorded as a mandatory golden test for M7**, S38–S44 for M8, S48 for M9, S51 for M10 — so no future milestone rediscovers its own answer key the way M5 did. Sample display rows (S9/S11/S15/S16/S22/S23/S28/S30) are classified as UI fixtures, not golden tests, since they illustrate a screen rather than state an arithmetic result.

**M6 (CGPSC Workflow) design submitted for approval** — S26–S30: Chairman allotment and allotment report, member acceptance and scheduling, re-allotment and re-scheduling with history retained old-beside-new, and notifications. Proposed as four phases extending the existing config-driven `WorkflowEngine` rather than adding a mechanism, with allotments and schedules never overwritten and addressed by UUID. Five points raised for ruling first — notably **whether the DEFAULT DPC DATE is derived from the proposal date or typed by the Chairman**, which is the one place M6 could hide a business rule.

## 2026-08-13 — M5 Phase 4: CALCULATE UI — history, lists, Explain Result — **M5 COMPLETE**

Final slice of M5 (PPT S17/S18/S33). The milestone's first UI, so the Demo Readiness checklist applied in full. M4 untouched and still frozen.

- **CALCULATE** wired onto the proposal detail screen — the only addition M5 makes to an M4 screen, presentation only, changing no M4 logic. Deliberately available **after submission**: a calculation writes separate evidence and never touches the proposal, and the Commission calculates when the proposal itself is no longer editable, so gating it on `update` would make the engine unusable at exactly the point it is needed.
- **Calculation history**, **Main List (मुख्य सूचि) / Waiting List (अनुपूरक सूचि) screens**, an **all-officers table** including those on neither list (an absent name is explained, never silently missing), and **Explain Result** as a page per officer with the narrative, year-by-year marks, score arithmetic and every ground for refusal.
- **UUID-only addressing** — tests assert the sequential id neither resolves as a route nor appears in rendered markup. Officers within a run are addressed by chart serial number, business data already printed on the PPT.
- **Rate limiting on CALCULATE**, keyed by user *and* proposal so two officers of the same department cannot throttle each other. Unbounded recalculation would let anyone bury the run the Commission acted on under thousands of near-identical rows.
- **Provisional runs warn on screen** in both history and detail, so a flagged calculation cannot be mistaken for a final one.
- `docs/deployment/EVIDENCE-TABLE-PERMISSIONS.md` documents the production database-permission requirement (architect Decision 2). **It flags an operational catch:** revoking `UPDATE` outright would break every calculation, because the run performs exactly one legitimate update (`Draft → Completed`). A column-level grant on `status` is recommended instead — a permission change, not a code change.

**PPT finding — the customer's answer key was found, and it corrects an earlier error.**

Performing the full PPT comparison this phase required turned up **slide 33 with MARK and REMARK filled in** for all ten officers. Earlier reviews of ours recorded that no worked MARK/REMARK existed anywhere; that was true of slide 18 and **false** of slide 33.

- **The engine reproduces 9 of the 10 stated marks exactly**, now pinned by 12 new golden tests.
- **Three rows independently confirm decisions taken on other grounds.** US4's blank 2014 cell still yields 15, which is only reachable if the window reaches back to 2012 for a fifth *graded* year — the customer's own arithmetic vindicating "five most recent **available** CR years". US3 appears twice, at 14 · NE and then 15 · ST_W once `TRIBAL 2021` is recorded, working §5.2 flipping a verdict. US5 and US9 each score exactly the Class-I threshold of 15 and are still refused, confirming both hard gates.
- **Two rows diverge (P1).** US2 and US10 both score 15 — agreed — and the slide marks both **NE**. Both carry `PUNISHMENT` in the Note column; no other row does. The engine treats Note as commentary, and §5.3 lists only two hard gates. **Not implemented unilaterally:** adding a rule that refuses officers promotion, on a reading of a slide, against approved and about-to-be-frozen logic, is exactly the change that must not be made without a ruling. Both rows are pinned by test asserting current behaviour with the divergence documented in place. Raised in `docs/ARCHITECT_REVIEW.md`.
- **Two observations recorded for M9** so it need not rediscover them: US8 is `ST SS CIR` with a property `N`, matching Q12's circulation trigger; US4 is an SC officer shown on the UR waiting list, suggesting reserved-category officers can appear on the unreserved list, which per-category placement would not produce.

- 30 new tests, **291 tests / 1007 assertions passing**. Pint clean, PHPStan 0 errors, `composer audit` clean, `migrate:fresh --seed` OK (52 tables).

**A post-M5 Feature Freeze is drafted for architect announcement** in `docs/ARCHITECT_REVIEW.md`: no changes to the eligibility algorithm, scoring, zone or Super Seat logic; only bug fixes, notification-driven rule-set updates through configuration or master data, and security fixes. The architecture already supports it — every threshold lives in `config/dpc.php → scoring`, each set is labelled by `rule_version`, every run stores its own snapshot, and the golden tests fail if any value moves unintentionally.

## 2026-08-13 — M5 Phase 3: CALCULATE — runs, results, Main/Waiting lists, Explain Result

Third slice of M5 (PPT S33, requirements §5.7). No UI. M4 untouched and still frozen.

- **`DpcCalculationService`** — the orchestrator, and **the only class in the milestone that touches the database**. It exists so the four engines never have to: it loads a proposal, converts it to plain value objects, calls the pure services, ranks, places on lists, and persists. That is how Decision 1's purity requirement survives contact with persistence.
- **`dpc_calculation_runs`** — **UUID public identifier** with the auto-increment id kept internal (architect directive: a sequential id in a legal document discloses how many calculations exist and invites guessing neighbours). Each run stores the complete rule set it executed under, the officer-data fingerprint, executed-by/at, the zone whole, counts, pending decisions, and **build provenance** (application version, git commit, build timestamp, from new `dpc.application` config). If an engine defect is ever found, those three values are what identify which stored calculations the affected code produced — unanswerable after the fact without them.
- **`dpc_calculation_results`** — one row per officer **including those on neither list**, so an absent name is explained rather than silently missing. The officer's identity is snapshotted rather than merely joined, so a result stays readable if the proposal data later changes.
- **Main and Waiting lists (§5.7)** — zone membership by seniority; within the zone §5.5's notional seniority puts Super Seat officers ahead of the field, with actual seniority deciding among them; each category then fills its own vacancies from its own eligible officers, and the remainder go to the waiting (supplementary) list. Remarks follow §6.3: the category code on the main list, `_W`-suffixed on the waiting list, `NE` for the ineligible, `EL` for an eligible officer outside the zone. A test demonstrates a Super Seat officer who is fourth by seniority taking first place ahead of two seniors.
- **Self-containment (Decision 1)** — a stored run rebuilds its own rules via `$run->ruleSet()`. Tested: changing `dpc.scoring.*` afterwards leaves a stored run's rules and verdicts untouched.
- **Explain Result (Decision 2)** — `ExplainCalculationService` assembles per-officer and per-run explanations **purely from stored fields**. Nothing is recomputed and no current configuration is read. Tested: the explanation is byte-identical before and after the configuration is changed underneath it. An explanation that recomputed anything would disagree with the result it claims to explain after any rule change, which is worse than no explanation in a service matter.
- **Immutability** — enforced at the **model** layer for both runs and results: update and delete throw, and a run accepts exactly one update (`Draft → Completed`). Recalculating creates a new run beside the old one; the previous run and its lists are verified intact.
- **Known limitation, disclosed rather than papered over:** model-layer immutability does not stop a query-builder mass update, which bypasses Eloquent events entirely. No application code path can edit a completed calculation and no developer can do so accidentally — but a developer with database access, or one who deliberately writes a query-builder update, can. Closing it properly requires database triggers or revoked grants, both outside application code and neither directed; raised for architect ruling in `docs/ARCHITECT_REVIEW.md`.
- **"Proposal Version" interpreted as a content fingerprint** — DPC proposals are not sequentially versioned (a deliberate M4 decision), so there is no version number to store. A SHA-256 of the officer data consumed serves instead: identical fingerprints mean identical input, and a differing one proves the data changed between runs, which is the question a recalculation actually raises. Flagged for confirmation.
- **Calculation Status** — all four states declared per directive. `Draft` and `Completed` are genuinely produced (a `Draft` run is one whose calculation did not finish); `Cancelled` and `Archived` are declared but not yet reachable, a knowing exception to the project's no-unused-values rule, disclosed in the review.
- **Q5/Q6 now block publishable output**, as forecast: a run meeting a reserved shortfall completes and records every affected officer and category in `pending_decisions`, but the lists it produces are provisional for those categories.
- 27 new tests, **259 tests / 873 assertions passing**. 2 new tables (52 total). Pint clean, PHPStan 0 errors, `composer audit` clean, `migrate:fresh --seed` OK.
- Phase 4 (CALCULATE wiring into the screens — the first UI of this milestone) not started.

## 2026-08-13 — M5 Phase 2: Zone of Consideration, Super Seat, Golden Tests

Second slice of M5 (PPT S17/S33, requirements §5.5–§5.6). No UI. M4 untouched and still frozen.

- **`ZoneOfConsiderationService`** — Class-I→I sizing (1 vacancy → top 5; >1 → 2×vacancies+4), configuration-driven. **The PPT's own printed figure is reproduced exactly:** slide 17's "पदोन्नति हेतु कुल विचार्य संख्या – 2 X 2 + 4 = 8" is the only number on the whole deck checkable without interpretation, and it matches. Class pairs with no configured rule return an **uncapped** zone rather than a very large number — the PPT's "other cases" admits every officer meeting the experience qualification, and "no cap" and "a big cap" are different facts a stored calculation should not blur. A zone larger than the officers available is truncated and says so, so a short list is visibly short through lack of candidates rather than through the formula.
- **`SuperSeatService`** — all CR years in the scoring window at the top grade → notional (काल्पनिक) seniority, plus §5.5's tie-break by actual seniority. Two judgement calls: the rule is tested on the **grade, not on a total of 20** (the requirements state both, and they agree only while क+ is worth 4 — a golden test revalues it to 9 and confirms the rule still means "an unbroken run of top grades"); and a **short CR window can never qualify**, because three क+ years with two missing is not the same demonstration as five, and a notional promotion ahead of a senior colleague must not rest on absent records. Officers without a serial number sort last deterministically — an unstable sort would make a calculation unreproducible.
- **RuleSet versioning (architect Directive 1)** — implemented as `RuleSet::fromSnapshot()` rather than as an archive of past rule sets. An archive is a second source of truth that can drift from, or be deleted independently of, the calculations depending on it. Instead **every run stores its own complete rule set**, and `toArray()`/`fromSnapshot()` are exact inverses (asserted by golden test), so a 2026 calculation re-run in 2031 rebuilds the 2026 rules *from the calculation itself*. "Never delete a rule version" is satisfied structurally — there is no registry that could be pruned. An **incomplete snapshot is rejected**, not silently topped up from current configuration, which would produce a calculation claiming to be historical while not being so.
- **Reserved zone expansion (7×) is BLOCKED** alongside the qualifying-score relaxation — both are part of Q5. The zone is sized by the printed formula and the affected categories are **named**, never expanded. Q5/Q6 now block two rules, not one.
- **Explain Result (Directive 2)** — every zone and Super Seat outcome carries the `formula` and `explanation` that produced it, built at calculation time from the rules that fired. "8 officers were considered" is not defensible; "2 vacancies × 2 + 4 = 8" is.
- **Golden test coverage audit** — Decision 4 is now a permanent project rule (every *configurable* business rule needs at least one golden test). All **13** configurable rules are covered, plus two whole-chart assertions: the set of eligible officers, and the set of Super Seat officers (US8 is the only one on slide 18).
- **Ragged S18 rows** accepted as documentation ambiguity per Decision 5 — natural interpretation retained, ambiguity documented, no business question raised to CGPSC.
- 33 new tests, **232 tests / 801 assertions passing**. Pint clean, PHPStan 0 errors, `composer audit` clean, `migrate:fresh --seed` OK.
- Phase 3 (orchestration + calculation-run persistence with UUID identifiers) and Phase 4 (CALCULATE wiring) not started.

## 2026-08-13 — M5 Phase 1: Scoring Engine, Eligibility Engine, Golden Test Suite

First slice of M5 (PPT C4–C6, S33; requirements §5.1–§5.4). No UI, per architect Decision 5. M4 untouched and still frozen.

- **`ScoringService`** — CR grade → marks (क+=4, क=3, ख=2, ग=1, घ=0) over the **five most recent *available*** CR years (architect Decision 1), plus the §5.2 tribal-area bonus. The S18 chart holds six CR columns; the oldest is not scored. A year listed with no grade is **skipped, not scored as zero** — a missing CR means "no assessment on record", which is what a Circulation DPC exists for, and scoring it as zero would silently turn an administrative gap into a failed officer. An unrecognised grade is treated the same way rather than guessed as the worst mark.
- **`EligibilityService`** — the §5.3 hard gates (any घ in the window; Class-I→I final CR may not be ग) and the §5.4 qualifying scores by class pair. Gates are evaluated in the PPT's order and **every** failure is recorded, not just the first: an officer refused promotion is entitled to all the grounds, and a CAT/HC challenge will ask for them.
- **`RuleSet`** — an immutable snapshot of the rules in force at calculation time. Nothing in the engine reads `config()` directly; everything reads a RuleSet, because a promotion computed under one government notification must stay explicable years later after the notification changes. `toArray()` is the configuration snapshot every run must persist, and `rule_version` is the label it is explained by.
- **Both engines are pure** — no database, no clock, no configuration read inside. Same officer plus same RuleSet always yields the same result, which is what makes a stored calculation reproducible.
- **Reserved relaxation (Q5/Q6) — BLOCKED, per architect Decision 3: do not guess.** The engine applies the printed thresholds, ships with `reserved_relaxation.enabled = false`, and **flags** exactly the officers the unruled rule could have changed the answer for (reserved category, failed *only* on the score, at or above the relaxed floor). They are marked not eligible — the conservative side deliberately, since granting eligibility under an unapproved rule would promote someone the rules may not entitle to it. Four tests pin the boundaries, including that an officer failing a *hard gate* is not flagged, because relaxation is about the score and could not have rescued them.
- **All rules configuration-driven** (`config/dpc.php → scoring`): marks, scoring window, tribal bonus, gate grades, qualifying scores per class pair, relaxation floor. Nothing hardcoded — asserted by a test that reads the config file. `rule_version` must be bumped whenever a value changes.
- **13 golden cases transcribed from PPT slide 18**, run against the **shipped configuration** rather than fixtures, so they prove the values the application deploys with reproduce the customer's own document. The two most valuable: **US5 and US9 both score exactly 15 — the Class-I threshold — and both are correctly refused**, one by the घ gate and one by the final-year ग gate. An engine checking only the total would pass them.
- **PPT finding:** three S18 rows list fewer CR grades than there are year columns (rows 4 and 9 carry five, row 6 carries four, against six columns), and the slide gives no alignment. Row 6 has an incomplete CR window under any reading — the Circulation-DPC condition (Q12) — yet appears as an ordinary row; and for row 9 the alignment decides eligibility, since its final grade is ग. The natural reading (grades are the most recent years) is implemented, and those three rows are asserted **separately** from the seven unambiguous ones so an alignment correction can never be mistaken for an arithmetic regression. Flagged for confirmation with CGPSC.
- **Business questions registered** in `docs/design/06-ambiguities.md`: **BQ1** (CSC eSign — raw bytes or PDF?), raised per architect directive with no implementation until the specification arrives; **BQ2** (HRMS API contract). Q5/Q6 recorded as blocking M5.
- 42 new tests, **199 tests / 723 assertions passing**. Pint clean, PHPStan 0 errors, `composer audit` clean, `migrate:fresh --seed` OK.
- Phases 2–4 (Zone of Consideration + Super Seat; orchestration + calculation-run persistence; CALCULATE wiring) not started.

## 2026-08-13 — Cross-cutting: Feature Flags & Provider Pattern (M4 frozen)

Established before M5 on architect ruling, so every future integration follows one pattern. No M4 business logic changed — configuration, provider registration, and one admin screen, consistent with the M4 freeze.

- **`config/features.php`** — one switchboard for every external integration, each flag documenting its own fallback. `FEATURE_HRMS`, `FEATURE_CSC_DIGISIGN`, `FEATURE_SMS_GATEWAY`, `FEATURE_EMAIL_GATEWAY`, `FEATURE_S3_STORAGE`, `FEATURE_LGD_SYNC` all default **off**; `FEATURE_NOTIFICATIONS` defaults on, being an internal capability rather than an external dependency.
- **`App\Enums\Feature`** — flags as a type, not strings. Business logic asks `Feature::Hrms->enabled()`, never `config('features.hrms')`: a mistyped config key returns null, which is falsy, so an integration would silently switch off and nothing would fail — the worst failure mode for a mechanism whose job is to be trustworthy. A mistyped enum case is a fatal error. Each case carries its env key and documented fallback, so the admin screen enumerates the cases and a new flag appears automatically.
- **HRMS provider architecture** — `HrmsProvider` interface, `MockHrmsProvider` (bound while off), `RealHrmsProvider` (production target, unimplemented, throws). The mock never fabricates an employee: a plausible-looking fake officer flowing into a promotion proposal would be far worse than an empty result.
- **All providers rebound onto flags** — signature, HRMS, SMS, directory. Flags with no implementation yet (SMS gateway, LGD sync) throw a message naming the flag if switched on, so enabling something unbuilt fails immediately instead of degrading silently.
- **Never mock-sign in production** — enforced at the service layer for both DPC and Pre-DPC approval, keyed on the feature flag (the single switch deciding what is bound; checking anything else could drift out of step with what actually signs). Tested: production + flag off → approval refused, proposal stays submitted, no signature, no DPC ID, no partial state. Counterpart also tested — non-production still signs via mock so UAT stays demonstrable while CSC's API is outstanding.
- **The entire M4 workflow is tested to run with every integration disabled** — draft through signed approval. That is the guarantee behind "no workflow may fail because an integration is off".
- **Integration Status screen** (`/admin/integrations`, admin-only, read-only) — every flag, its env key, state, fallback, and which provider each contract actually resolves to right now. Resolution is attempted rather than assumed, so a flag enabled without its provider reports `UNAVAILABLE` with the reason. A flag nobody can see is a flag nobody can trust; before a phased rollout an operator must be able to confirm from the running application that production signs through CSC and not the mock.
- **Storage disks follow `FEATURE_S3_STORAGE`** in config, so no code decides where a signed document goes.
- **Superseded config keys removed** — `dpc.signature.provider`, `dpc.sms.driver`, `dpc.directory.provider` chose providers by name, which is now the flag's job. Leaving both would create two switches for one decision on a legally significant path.
- `docs/integration/HRMS.md` (new) and `docs/integration/CSC-DigiSign.md` (rewritten): provider architecture, enable/disable configuration, integration flow, failure behaviour, future API requirements, production considerations, test coverage.
- 12 new tests, **157 tests / 596 assertions passing**. Pint clean, PHPStan 0 errors, `composer audit` clean, `migrate:fresh --seed` OK.

**M5 design submitted for approval** in `docs/ARCHITECT_REVIEW.md` — not implemented, per the Design → Review → Approval → Implementation process. Two of its open questions (Q5 reserved relaxation, Q6 relaxation scope) block implementation and need ruling.

## 2026-08-13 — M4 Phase 7: Closeout — **MILESTONE M4 COMPLETE**

Final slice of M4 (PPT S19–S25). Phase-6 decisions and directives applied; the **Demo Readiness Rule** adopted as the completion test. M5 not started.

**Three P1 defects found by applying the Demo Readiness Rule** — all had passed six consecutive green quality gates:

- **An empty proposal could be submitted and signed.** No committee, no officers, no seniority list, no evaluation chart, yet the Administrative Head would be asked to digitally sign it. Fixed by `DpcProposalCompletenessService`: the PPT's S14→S18 sequence is now a completeness check, enforced at the service layer, surfaced to the officer as a readiness panel with a "Complete" link per outstanding section, and named explicitly when submission is refused. Completeness only, never correctness — no scoring, ranking, or eligibility judgement, which stay M5's. **This broke 13 existing tests**, all of which had been submitting incomplete proposals: the suite had encoded the defect as expected behaviour.
- **The signed document contained only a header.** A single approval covers every section, but the signed artefact was five lines — department, type, posts, date, legal stay. The AH's signature attested to a header while the sections it approved lived only in mutable rows. The document now contains all five sections as they stood at signing, which is what makes the signature useful if a promotion is contested in CAT/HC. Pinned by a test that reads the stored file.
- **The audit trail had never been tested anywhere in the project.** `config/audit.php` hardcoded `'console' => false`; PHPUnit runs in console, so every audit record in every test since M1 was silently discarded and no assertion about auditing could ever have passed. A government-grade traceability requirement with zero coverage across seven milestones. Made env-driven, enabled in `phpunit.xml`, and asserted for the first time. Stays `false` by default in real environments so seeders do not manufacture audit rows.

**Phase 7 work**

- **Administrative Head review walkthrough (S21–S25)** — five read-only steps mirroring the PPT: Selection of Posts → Committee → Officers Under Consideration → Seniority List → Tabulation Chart, with progress indicator and step navigation. The approve and return actions appear **only on the final step**, which is how the PPT's per-screen APPROVE buttons and the single-approval ruling reconcile: five screens reviewed, one approval, one signature, one transition, one timeline entry.
- **Section partials extracted** so the proposal detail screen and the review walkthrough share one renderer instead of duplicating five tables. Presentation-only.
- **DigiSign integration point verified, still unimplemented** per directive: a test proves approval *hard-fails* with the CSC provider selected rather than silently falling back to the mock, leaving the proposal `submitted` with no signature, no DPC ID, and no partial state. A promotion order signed by a development stub would be worse than no signature.
- `DpcProposalClosureTest` (17 tests) and a `CompletesDpcProposals` test trait.

**Decisions and directives applied**

- **Decision 1** — `composer audit` added to the permanent gate (`composer.json → scripts.gate`), now Pint → PHPStan → audit → PHPUnit, plus a standalone `composer audit:security`.
- **Decision 2** — Excel template export not implemented; moved to the M11 Enhancement Backlog.
- **Decision 3** — import batches retained permanently; now tested that committing deletes no evidence.
- **Directive 1** — the CSV error report is now **persisted at validation time** rather than rendered on demand, so the officer downloads the same artefact the batch was judged against even if validation rules change later. Original file and import log were already retained.
- **Directive 2** — SHA-256 `file_hash` on every upload (indexed), taken before storing. Drives duplicate detection (earlier identical uploads named on the preview screen), integrity verification (a tampered stored file is flagged), and evidence.
- **Directive 3** — prepared for a future `Expired` status without stubbing it: no unused enum case was added, since a case with no transition into it would be dead code on a demoed screen. The column has room, `isCommittable()` allows only `Previewed` so an expired batch is refused by default, and `isTerminal()` is the single place terminality is declared.
- 1 additive migration (50 tables). 22 new tests, **145 tests / 516 assertions passing** overall. Pint clean, PHPStan 0 errors, `composer audit` clean, `migrate:fresh --seed` OK.

**M4 is complete.** Full PPT comparison of S12–S25 recorded in `docs/ARCHITECT_REVIEW.md`; no unexplained divergence remains. M5 (Scoring & Eligibility Engine) is next and has its complete input: raw per-year CR grades, loyalty and property flags, tribal-area service years, seniority order, vacancy counts by category, and class levels — with no calculation performed anywhere in M4.

## 2026-08-13 — M4 Phase 6: Excel Import (+ Phase 5 decisions applied)

Sixth slice of M4 (PPT S16–S18 upload templates). Architect Decisions 1–5 from the Phase 5 review applied; Phase 7 not started.

**Phase 5 decisions applied**

- **Decision 1** — Loyalty stays per-year. No change.
- **Decision 2** — `dpc_working_officers.date_of_posting_on_post` **not** renamed; schema stability preferred over naming consistency. Documented in the model docblock instead, per the ruling.
- **Decision 3** — new `config('dpc.limits')` bounds every officer array from configuration (committee 50, vacancy rows 200, working officers / seniority entries / evaluation rows 500) rather than relying on PHP's `max_input_vars` default. Phase 5's `dpc.evaluation.max_rows` moved into this block so section limits live in one place; no behaviour change. Import files are bounded by the same numbers, so an upload cannot smuggle in a larger write than manual entry allows.
- **Decision 4** — `saveWorkingOfficers()`, `saveSeniorityEntries()`, and `saveEvaluationRows()` now each record one ActivityTimeline entry per save, via a shared helper, matching the Committee pattern from Phase 2. The actor argument is optional: `ActivityTimelineService` already falls back to the authenticated user, so the ruling was applied without churning existing call sites.
- **Decision 5** — `tribal_area_service_years` stays a count of years. No calendar-year column added.

**Excel import**

- **`DpcRowRules`** — validation rules extracted into one shared definition per section. The three manual-entry Form Requests and the importer now validate against the same rule sets, so neither path can drift (architect directive: never duplicate business logic). Mechanical extraction, no behaviour change — the existing Phase 3–5 tests passed unchanged throughout.
- **`import_batches` + `import_rows`** — the staged pipeline the directive calls for: **Preview → Validation → Error Report → Commit**. An upload is parsed, mapped, and validated into staged rows and writes *nothing*; only an explicit commit of a previewed batch reaches the proposal, and it does so by calling the existing `DpcProposalService` save methods. Batches are retained after commit — for a promotion contested in CAT/HC, "which file produced this list, uploaded by whom, confirmed by whom, and what was rejected" is part of the record.
- **`ImportTemplate` enum** — the single place mapping each PPT template to its column headers, rule set, and target section. Accepts both the PPT's own headers (`NAME OF US`, `DATE OF POSTING ON US`, `QUAL`, `CAT`, `DOP`) and this system's field names, so a file exported from the portal re-imports unedited. Column order is irrelevant; unrecognised columns are ignored.
- **Day-first date parsing.** The PPT tables use `DD-MM-YYYY`, so that is tried first and the parse is round-tripped to reject near-misses. Without it, `01-02-2012` would silently import as 2 January rather than 1 February — a plausible-looking corruption of dates that feed M5's eligibility calculations. Pinned by test.
- **Category resolved by code**, not id — a department filling a spreadsheet has no access to primary keys. An unknown code rejects that row with the code quoted back. **Per-row rejection**, so one bad cell never blocks the rest of the file.
- **S18 per-year columns matched by pattern** (`CR 2016`, `LOYALTY 2017`, `IMMOVABLE PROPERTY 2013`), since the year set varies by proposal and differs between CR and Property on slide 18.
- **Security:** upload type/size gated by config (detected type, not the client-supplied filename); files stored on a private disk under a per-proposal path; import inherits the proposal's ownership and DRAFT/RETURNED-only rules at three layers; only a `Previewed` batch is committable, so double-commit and commit-after-cancel are refused. The **error report is escaped against CSV/formula injection** — it echoes untrusted spreadsheet content into a file opened in Excel, so cells starting `=`, `+`, `-`, `@`, tab or CR are defanged with a leading quote. Tested with a `=cmd|/c calc!A1` payload.
- **Dependency advisories found and fixed:** installing `maatwebsite/excel` surfaced 6 advisories (4 high, 2 medium) against `league/commonmark` 2.8.3, a transitive framework dependency — DoS and filter-bypass classes, all fixed upstream in 2.9.0. Upgraded to 2.10.0; `composer audit` now clean. Pre-existing, not introduced by this phase. Adding `composer audit` to the quality gate is recommended in `docs/ARCHITECT_REVIEW.md`.
- 2 new tables (50 total). 16 new tests, **124 tests / 428 assertions passing** overall. Pint clean, PHPStan (Larastan level 6) 0 errors, `composer audit` clean, `migrate:fresh --seed` OK.
- Phase 7 (M4 closeout) remains.

## 2026-08-13 — M4 Phase 5: Evaluation / Tabulation Chart

Fifth slice of M4 (PPT S18), plus the approved Phase 4 field and all four additional architect directives. Phase 6+ not started.

- **`dpc_evaluation_rows`** — one row per officer on the tabulation chart: the standard ten-field employee snapshot, plus `tribal_area_service_years` and `note`. Same discipline as Phases 3–4: plain columns, no live employee/HRMS reference, `reservation_category_id` the only FK (to the M2 master).
- **Per-year child tables** (decision Q4): `dpc_evaluation_cr_grades`, `dpc_evaluation_loyalty_flags`, `dpc_evaluation_property_flags`, each unique on (row, year) and cascade-deleted from the parent. All three value columns are nullable so "recorded as No" stays distinct from "not recorded" — the distinction the Circulation-DPC rule (Q12) turns on, and the reason a partial chart is accepted rather than rejected.
- **No calculations** (directive 2): no mark, remark, score, total, service-year, experience, or zone column exists. Asserted by a test against the persisted column list, so a later phase cannot quietly add one. MARK and REMARK are blank on the PPT mockup too — CALCULATE-time outputs owned by M5.
- **PPT comparison caught a blocker.** Slide 18 was extracted from the PPTX directly rather than read from the requirements summary: the chart carries **six** year-wise CR columns (2012–2017), not the five the scoring rule (§5.4) sums. The initial five-year entry cap would have rejected the PPT's own worked example. The five-year window is a scoring rule, not a data-entry limit — default raised to six and pinned with a test that saves S18 row 1 verbatim. A second finding (slide 18 shows Loyalty as a single column, while Q4 rules it per-year) is implemented per Q4 and flagged for the architect in `docs/ARCHITECT_REVIEW.md`.
- **`date_of_posting_on_feeder_post` added to `dpc_seniority_entries`** (PPT S17/S24) — the approved field from the Phase 4 review. Nullable, additive, manual entry, HRMS-import compatible.
- **Employee snapshot tables standardized** (directive 1): `dpc_working_officers` gained `employee_number`, `current_post`, `feeder_post`, `date_of_posting_on_feeder_post`; `dpc_seniority_entries` gained `date_of_posting_on_current_post`. All three snapshot tables now carry the identical ten fields. Strictly additive — no approved column renamed, retyped, or dropped, which is why working officers keeps the Phase 3 name `date_of_posting_on_post` for the concept the other two call `date_of_posting_on_current_post` (rename flagged for approval).
- **Proposal Detail eager loading** (directive 4): all officer sections and the three per-year tables are eager loaded. `DpcProposalDetailPerformanceTest` renders the screen with 2 officers and with 25 and asserts the query count is identical — a flat curve rather than a fixed budget, since a fixed number breaks on unrelated framework changes.
- **XSS regression pinned:** the entry screen seeds saved rows into a `<script>` block. Verified `@json` compiles to `json_encode($v, 15, 512)` (`JSON_HEX_TAG` hex-escapes `<`), and a test stores a `</script><script>` payload as an officer name and asserts it is never emitted raw on either screen.
- Evaluation reference values (CR grade codes, max CR years, year bound, note codes, row cap) are config-driven per Q3 in `config/dpc.php → evaluation`. The grade→mark mapping and all thresholds are deliberately absent — M5 owns them.
- 4 new tables (48 total). 18 new tests, **108 tests / 368 assertions passing** overall. Pint clean, PHPStan (Larastan level 6) 0 errors, `migrate:fresh --seed` OK.
- Excel import remains Phase 6; Phase 7 closes M4.

## 2026-08-13 — Roadmap: UAT Readiness Review milestone inserted

Approved (Option A): `docs/design/05-milestones.md` now has a **UAT Readiness Review** checkpoint between M6 (CGPSC Workflow) and M7 (DPC Proceeding & Results) — the full proposal → evaluation → allotment pipeline demoed to business users before the higher-stakes proceeding/legal-output work begins. M4's internal phasing (Seniority List and Evaluation Chart stay as M4 Phases 4–5) is unchanged. CSC DigiSign remains a cross-cutting integration built incrementally at each approval point, not a standalone milestone. Review DPC (M8), Circulation DPC (M9), and Self-Inspection (M10) are retained as-is.

## 2026-08-13 — M4 Phase 4: Seniority List

Fourth slice of M4 (PPT S17). All 4 architect directives applied exactly; Phase 5+ not started.

- **`dpc_seniority_entries`** — a proposal-time snapshot per directive: seniority number, employee number, name, DOB, qualification, current post, feeder post, date of joining, all plain columns; `reservation_category_id` is the one FK, pointing at the M2 master (config data, not "live employee data"). No live employee/HRMS reference anywhere on the table, so a historical proposal can never change because upstream HR data changes later.
- **No CALCULATE button** (directive 3) — this phase only stores data; the zone-of-consideration computation (e.g. the PPT's worked "2×2+4=8") is explicitly M5's Eligibility Engine, not implemented here.
- **Same two-layer immutability + shared-service-layer pattern as Phase 3** (directive 4): `saveSeniorityEntries(DpcProposal, array $entries)` takes a plain array (ready for Phase 6's Excel importer to call directly) and is guarded by the same `assertEditable()` Service-layer check, on top of the existing Policy gate.
- 1 new table (`dpc_seniority_entries`; 44 total). 5 new tests (`DpcSeniorityListTest`), 90 tests / 286 assertions passing overall. Pint clean, PHPStan (Larastan level 6) 0 errors, `migrate:fresh --seed` OK.
- Evaluation chart and Excel import remain Phases 5–6.

## 2026-08-13 — M4 Phase 3: Working Officers + Vacancy breakdown

Third slice of M4 (PPT S16). All 5 architect directives applied exactly; Phase 4+ not started.

- **`dpc_vacancies`** — strictly row-based per directive: one row per (`vacancy_type_id`, `reservation_category_id`) pair with a `count`, never separate columns per vacancy type. Both FKs reference the M2 masters; nothing hardcoded. A DB unique constraint plus a Form Request cross-check both prevent a duplicate pair.
- **`dpc_working_officers`** — a proposal-time snapshot per directive: plain columns (name/DOB/qualification/dates/roster position), no live FK to any employee/HRMS record — this system has no such source to begin with, and the schema is built so it never could silently drift even if one existed.
- **`sanctioned_post_count`** added to `dpc_proposals` (captured on this same S16 screen, not S14 — moved here after re-reading the mockup during Phase 1 planning).
- **Immutability, enforced at two layers:** the existing `update` Policy ability already blocked non-DRAFT/RETURNED edits at the HTTP layer; added a matching `DpcProposalService::assertEditable()` guard at the **Service** layer too (directive: never trust only the HTTP layer), and backfilled the same guard onto `updateDraft()` and `saveCommittee()` for consistency — a defense-in-depth gap from Phases 1–2, closed here. Verified directly: a test calls the service method itself, bypassing HTTP/Policy entirely, and confirms it still refuses to mutate a submitted proposal.
- **Shared service layer for manual entry and future Excel import** (directive): `saveWorkingOfficers(DpcProposal, array $officers)` takes a plain array — the same shape a Phase 6 `ExcelImportService` will produce from a parsed upload — so Phase 6 calls this exact method rather than duplicating persistence logic.
- 2 new tables (`dpc_vacancies`, `dpc_working_officers`; 43 total). 6 new tests (`DpcWorkingOfficersTest`), 85 tests / 274 assertions passing overall. Pint clean, PHPStan (Larastan level 6) 0 errors, `migrate:fresh --seed` OK.
- Seniority list, evaluation chart, and Excel import remain Phases 4–6.

## 2026-08-13 — M4 Phase 2: DPC Committee

Second slice of M4 (PPT S15). Design directives from the architect applied exactly; Phase 3+ not started.

- **`DpcCommitteeMember`** (`dpc_committee_members`) — references an existing application `User` wherever possible; external (non-user) members are an optional manual-entry fallback (`user_id` null). When linked to a user, name/designation/mobile/email are **denormalized server-side from that user's own record**, never trusted from client input — closes a spoofing angle (a client can't pair a real `user_id` with a fake display name). A row snapshot either way, so a proposal's historical committee record doesn't silently change if a user's profile is edited later.
- **Validation, entirely server-side** (`SaveDpcCommitteeRequest`): exactly one Chairman; no user appears twice; no two members share a mobile number; committee size must fall within the active `CommitteeConfiguration`'s min/max; external members rejected outright if that configuration's `external_member_allowed` flag is off. All 6 rules have a dedicated test.
- **Seeded default committee configuration** (`CommitteeMasterSeeder`): `CommitteeType('dpc_committee')` + `CommitteeConfiguration` (chairman mandatory, min 1, max 5, external members allowed) — admin-editable via the existing M2 screen, not hardcoded. `voting_method`/`majority_type`/`tie_break_rule`/`quorum` are seeded with clearly-flagged placeholder values (required columns, but voting/quorum logic belongs to the M7 Proceeding milestone, which the PPT describes as single-conductor, not multi-member voting per decision Q16).
- **CSC DigiSign schema preparation** (architect directive): `signature_status`/`signed_at`/`signature_reference` columns added to `dpc_committee_members`, reusing the existing `SignatureStatus` enum for typing. Unused — no signing logic in this phase.
- **Audit + timeline, verified not assumed:** `DpcCommitteeMember` is `Auditable` (owen-it) — every add/remove is its own audit row (individual `create()` calls per member, not a bulk insert, specifically so each one passes through Eloquent's event system); one `ActivityTimeline` entry per committee save action. Both directly asserted in tests.
- 1 new table (`dpc_committee_members`; 41 total). 8 new tests (`DpcCommitteeTest`), 79 tests / 259 assertions passing overall. Pint clean, PHPStan (Larastan level 6) 0 errors, `migrate:fresh --seed` OK.

## 2026-08-13 — M4 Phase 1: DPC Proposal aggregate + Post Selection

First slice of M4 (PPT S12–S25). Design approved in `docs/ARCHITECT_REVIEW.md` before any code was written; implemented exactly Phase 1 scope, nothing further.

- **Aggregate:** `DpcProposal` (`app/Domain/Dpc`) — unlike Pre-DPC, not versioned (a department has many proposals over time, not one evolving baseline). Reuses the M3 `WorkflowEngine` unchanged: DRAFT→SUBMITTED→APPROVED, RETURNED loop, CANCELLED terminal, config-driven via `config('dpc.workflows.dpc_proposal')`.
- **Single approval, by design:** one `approve` transition covers AH review of the whole proposal — one `SignatureService` call, one `WorkflowTransition` row, one `ActivityTimeline` entry, one audit event. Verified directly in `tests/Feature/Dpc/DpcProposalWorkflowTest.php`.
- **Post selection (S14):** post-to/post-from reuse the `cadres` master (M2/M3) instead of free text — class I/II/III is read from the cadre, closing the divergence risk flagged in the previous review. DPC type restricted to `fresh` for now (Review/Circulation shown per the mockup but disabled — they open in M8/M9 on this same aggregate).
- **DPC ID generation:** `DpcIdGeneratorService`, format `<DeptCode><Year><Serial>` (PPT S28/S32/S37), race-safe via a locked per-department-per-year sequence table (`dpc_id_sequences`) — assigned atomically inside the same transaction as approval, along with `forwarded_at`.
- **Policies introduced:** `DpcProposalPolicy` — the architecture ruling to use Laravel Policies from M4 onward (M1–M3 used `role:` middleware + inline checks only). Closes an IDOR/BOLA gap: cross-department view/edit is now policy-denied and tested, and cadre selection is scoped to the acting user's department at the Form Request layer.
- **Hardening carried into the new code (not retrofitted onto M3):** every transition re-fetches the proposal row with `lockForUpdate()` before checking status, closing a narrow double-submit/double-approve race.
- 2 new tables (`dpc_proposals`, `dpc_id_sequences`; 40 total). 10 new tests (`DpcProposalWorkflowTest`), 71 tests / 237 assertions passing overall. Pint clean, PHPStan (Larastan level 6) 0 errors, `migrate:fresh --seed` OK.
- Committee, working officers, seniority list, evaluation chart, and Excel import are **not** in this phase — Phases 2–6, per the approved plan in `docs/ARCHITECT_REVIEW.md`.

## 2026-08-13 — Documentation audit, cleanup & security hardening

- Audited every Markdown document in the repo against the PPTs; produced `docs/project-documentation-audit.md`, `docs/PROJECT_PROGRESS.md`, `docs/PROJECT_STATUS.md`.
- Found and restored 7 milestone worklog files (M0–M2) that had been silently deleted by the M3 commit; archived them (and other superseded pre-reset planning docs, including the two pre-reset `.docx` files) to `docs/archive/`. Removed 3 empty, untracked leftover directories (`docs/api/`, `docs/architecture/`, `docs/requirements/`).
- **Security (P0):** added Laravel `RateLimiter` brute-force protection on `POST /login` and `POST /verify` (config-driven via `config/dpc.php → rate_limiting`, default 5 attempts/minute, keyed per identifier+IP so one account's lockout doesn't affect others). Covers Login and OTP Verify; self-service Forgot Password / Password Reset do not exist in the app yet (no such screen in the PPT) — flagged as a decision in `docs/ARCHITECT_REVIEW.md` rather than built silently.
- Replaced the stock Laravel `README.md` with a project-specific one.
- Established the standing production-execution doctrine (module completeness bar, mandatory security/VAPT/performance review, quality gate, `docs/ARCHITECT_REVIEW.md` format) — see that file for the current pending decisions.
- Quality gate: Pint clean, PHPStan (Larastan level 6) 0 errors, 61 tests / 203 assertions passing (added `tests/Feature/Auth/RateLimitingTest.php`, 2 tests).

## 2026-07-30 — M3: Pre-DPC Proposal workflow

First workflow milestone (PPT S7–S11). Reusable config-driven workflow engine (`app/Domain/Shared/Workflow`); Pre-DPC aggregate with versioning (DRAFT→SUBMITTED→APPROVED, RETURNED loop, CANCELLED); signature-gated AH approval; approval projects into the Cadre + Recruitment Rule masters. 59 tests / 185 assertions passing. Full detail: `docs/archive/worklog/` is not applicable (M3 worklog stays live in `docs/worklog/`) — see `docs/worklog/2026-07-30-milestone-3-review.md`.

## 2026-07-30 — M2: Master data & user management

15 master/lookup models (districts, designations, pay scales, cadres, reservation hierarchy, committee types/configuration, versioned recruitment rules) with lifecycle status, soft deletes, audit columns; CGPSC Admin role + user provisioning (Chairman/Member/Admin accounts). Government reservation percentages deliberately left unseeded (commit `a90ea0c`) — admin-configured, never hardcoded. 52 tests / 156 assertions passing. Full detail: `docs/archive/worklog/2026-07-30-milestone-2-review.md`.

## 2026-07-30 — M1: Auth, RBAC, registration, OTP, audit

RBAC via `spatie/laravel-permission` (6 roles); audit trail via `owen-it/laravel-auditing`; department registration (two-tier AO/AH accounts, PPT S5–S6); dual-channel (email+mobile) OTP verification; login by email or mobile; e-sign foundation (`SignatureService`). Decision Q1 (email/mobile+password+OTP replacing the PPT's Aadhaar mechanism) and Q2 (CGPSC Admin role, not in the PPT) ruled here. 19 tests / 58 assertions passing. Full detail: `docs/archive/worklog/2026-07-30-milestone-1-review.md`.

## 2026-07-30 — M0: Project foundation

Laravel 13.23.0 scaffold on the approved stack (PHP 8.4, MySQL, Redis for cache/queue/sessions, Bootstrap 5.3 vendored, no Node/Vite). Bilingual (EN/HI) shell; `/health` endpoint; Pint + Larastan (level 6) + PHPUnit quality gate established. 6 tests / 11 assertions passing. Full detail: `docs/archive/worklog/2026-07-30-milestone-0-foundation.md`.

## 2026-07-29/30 — Project reset

All prior Node.js/React/Express/Prisma direction discarded; restarted on Laravel, with the two source PPTs as the sole authoritative requirements input. Superseded pre-reset planning (`technical-proposal.md`, `screen-specs.md`) archived to `docs/archive/`.
