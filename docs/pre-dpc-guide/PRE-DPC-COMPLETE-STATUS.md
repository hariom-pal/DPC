# Pre-DPC and DPC Proposal: Implementation Status

**Audit date:** 2026-10-23.
**Code audited:** `main` at `eed36c0`, plus the uncommitted Department Registration work, which is reported separately in section 8.

**How this was checked:**
- The source code was read: routes, controllers, services, requests, policies, views and configuration.
- The dev database was queried read-only (SELECT only).
- The two dummy CSV files were run through the real importer on an in-memory test database.
- Nothing was written to the dev database, and no application code was changed.

**How claims are marked:**
- A statement with a `file:line` or `Class::method` reference was read in the code.
- A statement marked *(inferred)* is a reading of the code that was not run.

> **Two modules, one exercise.**
> - **Pre-DPC** (`/pre-dpc`) is the sanctioning order. It says which posts feed which post, in what shares, and under which Government Order.
> - **DPC Proposal** (`/dpc-proposals`) is the actual exercise. It holds the committee, vacancies, Working Officers, Seniority List, Employee Details, the Rule 1.1 checklist, and AH approval and forwarding.
> - The Seniority List and Working Officers uploads belong to the **DPC Proposal**, not to the Pre-DPC (`routes/web.php:263-301` has no import route).

---

## 1. What the last development rounds completed

| Commit | Content |
|---|---|
| `868e4cc` | Admin Vacancy Management and the AO proposal's vacancy data unified: one finalized vacancy record per post and year. |
| `c97be95` | AO keeps the vacancy record. CR grades are scored and checked against the Class Master rule. One department is seeded ready for a DPC. The AO proposal follows the sequence Working List → Seniority → CR → PH. |
| `eed36c0` | Every *Coder Instructions* rule built (map: `docs/CODER-INSTRUCTIONS-STATUS.md`): Class Master promotion rule fixed on the DPC, sealed cover, PH verification by AH, deputation and refused-promotion declarations, per-reason missing-CR documents, AO + AH document verification, DOB and joining orders, Rule 1.1 checklist items, promotion order PDF, Previous DPC records (CGPSC Staff). |

## 2. Fully implemented (code present and covered by automated tests)

| Feature | Where |
|---|---|
| Pre-DPC draft, edit, submit, approve (with signature), return (remarks required), cancel, amend (new version), delete draft | `PreDpcProposalService` (`createDraft`, `submit` :118, `approve` :134, `return` :168, `cancel` :180, `amend` :266, `delete` :213); transitions in `config/dpc.php:436-443` |
| Pre-DPC auto-population: pay scale and class from the To Post, promotion duration per feeder | `PostAttributeResolver`; `PreDpcProposalService::applyExercise` :349, `applySources` :394 |
| Pre-DPC validation: split must total 100, feeder shares must total 100, no duplicate feeder, feeder below the To Post, only one active order per exercise | `SavePreDpcProposalRequest::withValidator` :126-139 |
| Approval creates a Recruitment Rule version | `PreDpcProposalService::projectToMasters` :612-651 |
| DPC raised from an active approved Pre-DPC, with the Class Master promotion rule fixed on it | `DpcProposalService::createFromPreDpc` :116-170, `promotionRuleFor` :74-86 |
| Vacancy Management record (sanctioned strength and working strength with order PDFs, finalize) | `VacancyMasterService` (`openDraft`, `saveSanctionedStrength`, `saveWorkingStrength`, `finalize` :789) |
| Working Officers and Seniority List upload: preview → errors → commit | `ExcelImportService::preview` :66, `commit` :166 |
| Working Officers list reconciled with the finalized record; Seniority List blocked until it matches | `ProposalVacancyPosition::reconcile` :216; `ExcelImportService::assertWorkingListReconciled` :405 |
| Committee composition with the CGPSC Chairman seated automatically | `DpcCommitteeService::compose` :71, `seatCgpscChairman` :126 |
| Employee Details per officer: basic information, CR, IPR, PH, sealed cover, declarations | `EmployeeDetailsService` (`saveBasic` :66, `saveCrYear` :108, `savePropertyYear` :241, `savePh` :317, `saveDeclarations` :361, `saveVigilance` :389) |
| Missing CR reasons with documents (second document for suspension) | `RecordGapService::record` :55 |
| AO verification on upload and AH verification before approval | `EmployeeDetailsService::upload` :435, `verifyByHead` :456; `DpcProposalService::approve` :639-655 |
| PH claim verification by the AH | `EmployeeDetailsService::verifyPhClaim` :477 |
| Rule 1.1 checklist (7 items) | `ProposalVerificationService` |
| AO submit with completeness checklist; AH approve, sign and forward, or return | `DpcProposalService::submit` :591, `approve`, `return` :689 |
| CGPSC side: allotment, scheduling, proceeding, calculation, result approval | `AllotmentService`, `ProceedingService`, `ProceedingCalculationService`, `ResultApprovalService` |
| Promotion order: signed PDF and signed link, issued by the AH | `PromotionOrderService::issue` :82 |
| Previous DPC records entered by CGPSC Staff | `PreviousDpcRecordController` (`/previous-dpcs`) |

## 3. Partially implemented, or implemented with a known defect

| # | Finding | Effect | Reference |
|---|---|---|---|
| P1 | Pre-DPC list column "Exercise" reads `$proposal->promotionFromPost`. That relation does not exist on the model; the column was dropped in migration `2026_08_25_390001`. | The From side always shows "—". The To side is correct. | `resources/views/pre-dpc/index.blade.php:37`; `PreDpcProposal` has no such relation (`exerciseLabel()` :285 exists but is unused) |
| P2 | The Pre-DPC form's JS disables Save unless feeder shares total 100, even when Promotion % is 0. The server skips that check when Promotion % ≤ 0. | A 100 % direct-recruitment order cannot be saved from the screen *(inferred)*. | `public/js/forms/pre-dpc-exercise.js:254-283` vs `SavePreDpcProposalRequest::assertSourceSharesTotalOneHundred` :249 |
| P3 | Year field minimums differ: the HTML says 2000, the server says 2019. | The server refuses a year below 2019 with a message. | `pre-dpc/form.blade.php:57`; `config('dpc.earliest_dpc_year')` |
| P4 | The Pre-DPC policy has a signature-download ability, but no route or button uses it. | The signed Pre-DPC document cannot be downloaded. | `PreDpcProposalPolicy::downloadSignature` :183 |
| P5 | Working Officers import commits **only the valid rows** and replaces the whole list. | A rejected officer silently disappears from the list. The Seniority List import, by contrast, refuses the whole file. | `ExcelImportService::commit` :176-201 |
| P6 | Seniority List import creates Promotion Candidate logins but never shows their temporary passwords. The manual path does show them, but manual entry is off by default. | Candidates need a password reissue to log in. | `DpcImportController::commit` :538-540 vs `DpcProposalController` :400-403; `config/dpc.php:809-812` |
| P7 | Working Officers import skips the retirement-window and deputation-overlap checks, and has no duplicate-SN check. These checks exist on manual save only. | Those mistakes pass the upload. | `SaveWorkingOfficersRequest` :359-442 vs `DpcRowRules::workingOfficer` |
| P8 | An `.xlsx` with several sheets is read from the **last** sheet. | Use CSV, or a single-sheet workbook. | `app/Domain/Import/Support/RawSheetImport.php` (`ToArray` overwritten per sheet) |
| P9 | Import help text differs from the rules: a posting date shown as "Required" is nullable; CAT lists UR/SC/ST/OBC/EWS although only PH is active; roster says "1–100" but there is no maximum; From-Post says "nothing else is accepted" but it is not checked for Working Officers. | Misleading guidance only. | `lang/en/imports.php:35-40,56` |
| P10 | The Seniority List preview counts an officer as "kept" when the Employee ID matches ignoring case. The real match on commit is exact. | Changing only the case of an Employee ID detaches that officer's CR and other details on commit *(inferred)*. | `ExcelImportService::seniorityChanges` :483 vs `EvaluationCandidateService::keyOf` :376 |
| P11 | The Admin "Sanctioning orders" link screen offers and links Pre-DPCs without checking their status. | A draft or cancelled Pre-DPC can be linked to an old DPC *(inferred)*. | `PreDpcLinkReconciler::candidatesFor`, `link` |
| P12 | The vacancy screen note says "the Administrator verifies" higher-post entries, but the code lets only the AO verify. | Stale wording *(inferred)*. | `lang/en/vacancymaster.php:227`; `VacancyMasterController::verifyEntry` :162 |
| P13 | Category lookups in imports ignore the master's active/inactive status. | An inactive code such as UR is still accepted. | `ExcelImportService::parse` :696 |

## 4. Not implemented (confirmed absent or recorded as open)

- **Pre-DPC has none of these:** document upload, checklist, undertaking, or remarks on approval (`pre-dpc/form.blade.php`, `PreDpcProposalService::approve`).
- **No automatic DPC on Pre-DPC approval.** The AO raises the DPC manually (`DpcProposalController::store` :117).
- **No Admin screen for Recruitment Rules.** A rule exists only through Pre-DPC approval (`projectToMasters`).
- **No CGPSC scrutiny or return of a DPC Proposal.** The `dpc_proposal` workflow has no CGPSC transition (`config/dpc.php:600-606`). Corrections after approval go through a Review DPC raised by the department.
- **Opening a sealed cover** once the enquiry ends (`docs/CODER-INSTRUCTIONS-STATUS.md`, Open points).
- **Earlier-PH check across departments.** It reads the same department only.
- **Comparing entered data with document contents.** Not done.
- **Hindi (Devanagari) promotion order PDF.** dompdf cannot shape Devanagari.
- **"Take previous year CR" carry-forward.** Deliberately not applied.
- **Qualification gate.** Advisory only (`dpc.merit.qualification_gate=advise`).
- **"Fewer than 50 eligible" exception.** `config/dpc.php:238-239` says it is not implemented; only Previous DPC record uploads become optional below 50.
- **Reserved-category relaxation (Q5/Q6).** Awaiting a business decision; an affected result is flagged provisional and its publication is blocked (`ZoneOfConsiderationService.php:130`).
- **Result-approval signature.** Not wired: the `approve_result` signature is `false` (`config/dpc.php:540`).

## 5. Automated tests

**Last full run:** 2026-10-22. **1,657 tests passed, 0 failed, 2 skipped.** This run includes the uncommitted Department Registration changes.

Pre-DPC tests (listed by file):
- `tests/Feature/PreDpc/PreDpcWorkflowTest.php`: 26 tests. Create, submit, approve, return, cancel, amend, delete; roles; auto-population; recruitment-rule projection.
- `tests/Feature/PreDpc/PreDpcExerciseTest.php`: 24 tests. Year range, numbering, one active order per exercise, share totals, post-attributes endpoint.
- `tests/Feature/PreDpc/PreDpcTransitionLockTest.php`: 5 tests. Concurrent submit and approve.
- `tests/Feature/Admin/PreDpcLinkReconcilerTest.php`: 7 tests.

Pre-DPC to DPC handoff:
- `tests/Feature/Dpc/DpcProposalWorkflowTest.php`: inheritance from the Pre-DPC; an unapproved or superseded Pre-DPC is refused.
- `tests/Feature/Eligibility/ClassMasterRuleCalculationTest.php`: no rule means no DPC.

The DPC workflow is covered by:
- `tests/Feature/Dpc/AoProposalSequenceTest.php`
- `DpcExcelImportTest`, `SimplifiedSeniorityListTest`, `DpcSeniorityListReuploadTest`
- `EmployeeDetailsTest`, `SealedCoverDetailsTest`, `ServiceDeclarationsTest`, `ProposalVerificationTest`, `DpcRecordGapTest`
- `PromotionOrderTest`, `PreviousDpcRecordTest`

**There is no single test that walks the whole path** from Pre-DPC to promotion order. Each segment is tested separately with its own fixture.

**Dummy CSV check:** both files in this folder went through the real upload → preview → commit path on an in-memory test database, in two runs:
- category-wise off (the dev default);
- category-wise on.

In both runs, each file gave 10 rows, 0 rejected, committed. The Working Officers list reconciled with a finalized record of working 7 (promotion 7). See `PRE-DPC-CSV-VALIDATION-RULES.md` §8.

## 6. Not tested in a browser

None of the following has been exercised in a real browser during this audit or the last development rounds, as far as the record shows:
- the Pre-DPC screens;
- the DPC proposal screens;
- the upload screens;
- Employee Details;
- the CGPSC screens;
- the new Department Registration screen.

Every result above comes from HTTP-level feature tests, which render pages on the server. They do not execute JavaScript. These parts depend on JavaScript and are therefore **untested in a browser**:
- the Pre-DPC form's share total and Save-button logic;
- the auto-population calls;
- the registration OTP buttons.

## 7. Is the workflow usable end to end for a new department and post?

**In code: yes, with conditions.** Every stage exists and each is covered by tests. A new department and post can go through the whole path if these are in place:

1. **Signing.** On a server where `APP_ENV=production`, **CSC DigiSign must be enabled**. Otherwise the AH cannot approve either a Pre-DPC or a DPC Proposal ("Digital signing is unavailable…", `PreDpcProposalService::assertSignatureAvailable` :523, `DpcProposalService` :765). The dev server is `APP_ENV=local`, where the mock signer works.
2. **A Class Master promotion rule** for the class pair and year (`DpcProposalService::promotionRuleFor`).
3. **Eligible CR Grade values** valid for the CR years, needed for calculation (`DpcCalculationService::rulesFor` :61-80).
4. **The vacancy record's promotion %** must equal the Pre-DPC's promotion %.
5. **CGPSC allotment** needs the allottee to be a committee member linked to a portal user (`AllotmentService` :496-506).

**On the dev database today**, the workflow stops at these points until data is added through the screens:

| Step | State on dev (read 2026-10-23) | Result |
|---|---|---|
| Pre-DPC | None exists; no Recruitment Rule exists | The AO must create one, and the AH must approve it, before a DPC can be raised. |
| Vacancy record | One **finalized** record exists for Additional Director, Education, 2026: sanctioned 10, working 7 (all promotion), promotion vacancy 3, promotion 100 % | The Pre-DPC must use **Promotion 100 % / Direct 0 %**, or the proposal's vacancy section reports "rule differs" (`DpcProposalCompletenessService::adminVacancySection` :451). |
| Committee | No members exist | The AO composes it. Needs SC, ST, OBC and Female coverage plus the two secretaries (`config/dpc.php:759-762`). |
| CGPSC users | Only `admin` and `chairman` accounts exist; no `member` and no `cgpsc_staff` | Allotment to anyone other than a seated committee member linked to a user is refused. |

## 8. Department Registration: uncommitted, separate from Pre-DPC

These changes are in the working tree only, not committed:
- department registration reworked to the CGPSC mock-up;
- Aadhaar OTP (mock now, UIDAI by setting `FEATURE_UIDAI_AADHAAR_OTP=true`) plus e-mail OTP;
- one account per officer for life;
- a seat holds one officer.

They do not touch any Pre-DPC or DPC Proposal code.

| Aspect | State |
|---|---|
| Code | Done. See `docs/DEPARTMENT-REGISTRATION.md` |
| Automated tests | Pass: `RegistrationTest` (15), `OtpVerificationTest` (2), 20 UIDAI unit tests, all within the 1,657 above |
| Browser | **Not tested** |
| Live UIDAI | **Not tested.** Needs CSC credentials and UIDAI's encryption certificate. |
| Committed / pushed | **No** |
