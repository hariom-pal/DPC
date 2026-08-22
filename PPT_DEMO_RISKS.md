# PPT Demo Risks

| Severity | Risk | PPT Reference | Implementation Evidence | Demo Impact |
|---|---|---|---|---|
| Critical | Aadhaar/Aadhaar OTP login is not implemented. | S8, S10, S13, S20, S36, S39, S46, S50 | `auth/login.blade.php` uses email/mobile + password; self-inspection uses configured identity factors. | Demo cannot match PPT login screens. |
| High | Self-inspection `RAISE OBJECTION` button is absent. | S51 | `SelfInspectionController` has show/print/close only; `self-inspection/show.blade.php` has Print and Close only. | Presenter following PPT will expect a third button. |
| High | CR-missing circulation is blocked. | S45 | `CirculationService::assessGrounds()` raises BD-15 for CR-ground holds. | Circulation demo fails if officer is held for missing CR years instead of immovable property. |
| High | Review/Circulation DPC cannot be created from base DPC type radio screen. | S14 | `SaveDpcProposalRequest` rejects non-fresh; form disables non-fresh radios. | User must use separate Review/Circulation modules, not the S14 radio path. |
| Medium | AH approval is one final approval, not an approval button on every section. | S21-S25 | `dpc-proposals/review.blade.php` exposes approval only on final step. | Slide-by-slide walkthrough differs. |
| Medium | Online DPC scheduled-time enforcement is permissive by default. | S31, S41 | `config/dpc.php` has `allow_open_before_scheduled_time` default true. | A strict scheduled-time demo expectation will not be enforced unless configured. |
| Medium | Department upload screens do not show PPT `CALCULATE` buttons inline. | S16-S17 | Calculation routes exist elsewhere; forms show Save/Upload, not Calculate. | Demo navigation differs. |
| Medium | Circulation S47 does not display every post/class field exactly as slide. | S47 | `dpc/circulations/create.blade.php` focuses on origin DPC, date, held officers/resolution evidence. | Visual mismatch with PPT mockup. |
| Low | Registration button set differs from PPT. | S6 | Register screen has one Register submit, not Edit/Save/Register triplet. | Minor visual mismatch. |
| Low | M1-M5 allottee columns are dynamic user select. | S28 | `AllotmentService` allows any active Chairman/Member. | Better operationally, but not identical to fixed PPT table. |
| Low | Generated documents are HTML through renderer, not confirmed PPT/PDF format. | S34, S45/S48 | `ProceedingDocumentService`, `CirculationDocumentService` render/store/sign/publish documents. | Acceptable unless demo requires exact prescribed document visual/PDF. |

## Highest-Risk Demo Path

1. Demonstrator opens PPT login slide and expects Aadhaar OTP.
2. Demonstrator uses Review/Circulation radio on DPC proposal screen.
3. Demonstrator opens self-inspection and expects `RAISE OBJECTION`.
4. Demonstrator tries circulation for a CR-missing hold.

Use an immovable-property hold for circulation, use the separate Review DPC menu for review, and present authentication as a known implementation difference rather than as PPT-identical.
