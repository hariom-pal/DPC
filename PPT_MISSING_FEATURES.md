# PPT Missing Features

## Missing Requirement: Self-Inspection Raise Objection

- PPT requirement: Slide 51 includes a `RAISE OBJECTION` button along with `CLOSE` and `PRINT`.
- Missing implementation: There is no objection route, controller action, service, table, form, or button. Implemented routes are only `/self-inspection/record`, `/self-inspection/record/print`, and `/self-inspection/close`.
- Evidence: `routes/web.php` self-inspection group; `SelfInspectionController`; `resources/views/self-inspection/show.blade.php`.
- Demo impact: High. If the demo follows slide 51 literally, the expected `RAISE OBJECTION` button will not appear.

## Missing Requirement: Aadhaar-Based Login/OTP Screens

- PPT requirement: Slides 8, 10, 13, 20, 27, 29, 32, 36, 39, 42, 46 and 50 show Aadhaar number + Aadhaar OTP login, or DPC-ID + OTP for CGPSC DPC access.
- Missing implementation: No Aadhaar-number authentication or Aadhaar OTP verification path exists. The application uses email/mobile + password for system users, email/mobile OTP verification, and configurable identity-factor matching for self-inspection.
- Evidence: `resources/views/auth/login.blade.php`, `resources/views/auth/verify.blade.php`, `LoginController`, `VerificationController`, `SelfInspectionAuthenticator`.
- Demo impact: Critical if Aadhaar login is expected. The workflow can be demonstrated, but not with PPT-identical login screens.

## Missing Requirement: Per-Slide AH Approval Buttons

- PPT requirement: Slides 21-25 each show an approval view with an `APPROVE` button.
- Missing implementation: The AH can review five sections, but only the final review step exposes `Approve, Sign & Forward to CGPSC`.
- Evidence: `DpcProposalController::REVIEW_STEPS`, `resources/views/dpc-proposals/review.blade.php`.
- Demo impact: Medium. The business approval exists, but a slide-by-slide demo expecting `APPROVE` on each screen will diverge.

## Missing Requirement: Clear/Reset Buttons on Data-Entry Screens

- PPT requirement: Slides 9, 14, 16 and 17 show `CLEAR` or `RESET` controls.
- Missing implementation: Forms generally provide Save, Cancel, Add/Remove row, and back navigation. Dedicated Clear/Reset controls are not present on the audited screens.
- Evidence: `pre-dpc/form.blade.php`, `dpc-proposals/form.blade.php`, `working-officers.blade.php`, `seniority-list.blade.php`.
- Demo impact: Low to Medium. Does not block workflow, but visible mismatch from PPT screens.

## Missing Requirement: Inline Calculate on S16/S17 Department Upload Screens

- PPT requirement: Slides 16 and 17 show a `CALCULATE` button on the department officer/seniority upload screens.
- Missing implementation: Calculation exists, but as DPC/proceeding calculation routes after the proposal data is complete, not as an inline button on those entry screens.
- Evidence: `DpcCalculationController`, `ProceedingResultController`, `working-officers.blade.php`, `seniority-list.blade.php`.
- Demo impact: Medium. Functionality exists later, but the PPT screen button is absent.

## Missing Requirement: Commission-Initiated Review DPC In-App Action

- PPT requirement: Slide 35 allows Review DPC when facts come to notice by representation or by the Commission itself.
- Missing implementation: The implemented Review DPC creation is department-side only. Commission-initiated review is handled out of band rather than through a CGPSC screen/action.
- Evidence: `ReviewProposalController` comment and routes under `/dpc-proposals/reviews`, authorized for department AO creation.
- Demo impact: Medium. Department-requested review can be shown; Commission-originated in-app review cannot.

## Missing Requirement: Exact Circulation by CR-Ground

- PPT requirement: Slide 45 says circulation can cover missing CR communication for two or more years and missing immovable property returns.
- Missing implementation: Property-ground circulation can proceed; CR-ground circulation is refused as pending business decision BD-15.
- Evidence: `CirculationService::assessGrounds()` returns pending decision for `HoldReason::CrYearsMissing`.
- Demo impact: High if demo scenario uses CR-missing circulation. Use immovable-property hold for a successful circulation demo.
