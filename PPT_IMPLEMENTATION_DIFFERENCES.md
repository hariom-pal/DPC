# PPT Implementation Differences

## Authentication

The PPT repeatedly shows Aadhaar number + Aadhaar OTP, and for some CGPSC screens DPC ID + OTP. The implementation uses a unified email/mobile + password login for registered users and an OTP verification/signature layer for selected actions. Self-inspection uses configurable identity factors instead of Aadhaar OTP.

Evidence: `auth/login.blade.php`, `auth/verify.blade.php`, `LoginController`, `VerificationController`, `SelfInspectionAuthenticator`.

Status: ⭐ Implemented Differently

## DPC Type Selection

The PPT shows Fresh, Review and Circulation DPC on the DPC proposal selection screen. The implementation disables non-Fresh types on that screen and provides separate Review DPC and Circulation DPC modules.

Evidence: `SaveDpcProposalRequest`, `dpc-proposals/form.blade.php`, `ReviewProposalController`, `CirculationController`.

Status: ⭐ Implemented Differently

## AH Approval Model

The PPT mockups show an approval action on each AH review screen. The implementation gives the AH a five-step read-only review wizard and one final digital approval covering the full proposal.

Evidence: `DpcProposalController::REVIEW_STEPS`, `dpc-proposals/review.blade.php`, `DpcProposalService::approve()`.

Status: ⭐ Implemented Differently

## Calculation Placement

The PPT shows `CALCULATE` on department-side upload screens and the CGPSC online DPC action screen. The implementation stores department-entered data first, then calculates through proposal/proceeding calculation routes. Marks and remarks are computed outputs, not department-entered fields.

Evidence: `DpcCalculationService`, `ProceedingResultController`, `evaluation-chart.blade.php`.

Status: ⭐ Implemented Differently

## Online DPC Timing

The PPT says the DPC is conducted at the fixed date/time. The implementation records schedule/opening time and can enforce it, but the default configuration permits opening before the scheduled time.

Evidence: `ProceedingService::assertScheduledTimeReached()`, `config/dpc.php`.

Status: 🟡 Partially Implemented

## Review DPC Origin

The PPT permits Review DPC after facts come to the department or to the Commission. The implementation supports department-raised Review DPCs; Commission-initiated review is not an in-app action.

Evidence: `ReviewProposalController`, `ReviewDpcService`.

Status: 🟡 Partially Implemented

## Circulation DPC CR-Ground Handling

The PPT allows circulation for missing CR communication and missing immovable property details. The implementation treats CR-ground circulation as an unresolved business decision and refuses to decide it through circulation, while property-ground circulation can proceed.

Evidence: `CirculationService::assessGrounds()`, `PendingBusinessDecision` BD-15.

Status: 🟡 Partially Implemented

## Self-Inspection Authentication and Objection

The PPT shows Aadhaar/OTP for individual access and slide 51 includes `RAISE OBJECTION`. The implementation uses identity-factor matching and intentionally exposes only Print and Close.

Evidence: `SelfInspectionAuthenticator`, `SelfInspectionController`, `self-inspection/show.blade.php`.

Status: 🟡 Partially Implemented / ❌ Missing for objection button

## Reports

The PPT names allotment report, scheduling report, proceedings, promotion list, waiting list, letters/orders/notifications. The implementation includes allotment and scheduling reports, dashboard-style aggregate reports, notification center, proceedings/promotion-list documents, circulation documents and publication. It does not prove exact visual equivalence to the PPT's prescribed forms.

Evidence: `ReportController`, `DpcReportService`, `ProceedingDocumentService`, `CirculationDocumentService`, `resources/views/documents/*`.

Status: 🟡 Partially Implemented
