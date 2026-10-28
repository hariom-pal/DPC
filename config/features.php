<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Feature Flags
|--------------------------------------------------------------------------
|
| The single switchboard for every external integration in the system
| (architect ruling, 2026-08-13). Enabling or disabling an integration must
| never require a source-code change — only an environment variable, its
| credentials, and the provider registration in AppServiceProvider.
|
| Every integration flag defaults to FALSE. The application must run, and be
| demonstrable end to end, with all of them off: that is the UAT and early
| production configuration, and it is what CI runs. An integration that has
| not shipped its API yet must never be able to break a workflow.
|
| `notifications` is the one flag defaulting to TRUE — it is an internal
| capability, not an external dependency. Its own delivery channels (SMS,
| e-mail) are separately flagged below, so notifications stay on while their
| transports fall back to log drivers.
|
| Read these through App\Enums\Feature rather than config() directly, so a
| typo is a fatal error instead of a silently disabled integration.
|
*/

return [

    // Human Resource Management System — employee master, working officers,
    // seniority. Off: every officer record is entered manually or imported
    // from the PPT Excel templates, exactly as M4 works today.
    'hrms' => (bool) env('FEATURE_HRMS', false),

    // CSC DigiSign / CSC eSign — legally significant digital signatures.
    // Off: development and UAT sign through the mock provider; production
    // refuses to sign rather than fall back to it.
    'csc_digisign' => (bool) env('FEATURE_CSC_DIGISIGN', false),

    // Outbound SMS gateway (OTP + credential issuance).
    // Off: messages are written to the application log.
    'sms_gateway' => (bool) env('FEATURE_SMS_GATEWAY', false),

    // Outbound e-mail gateway.
    // Off: mail uses the framework's configured non-delivering driver.
    'email_gateway' => (bool) env('FEATURE_EMAIL_GATEWAY', false),

    // Object storage for signed documents and uploaded imports.
    // Off: the local filesystem disk is used.
    's3_storage' => (bool) env('FEATURE_S3_STORAGE', false),

    // Local Government Directory (LGD) API for administrative areas.
    // Off: the seeded Chhattisgarh district list is served.
    'lgd_sync' => (bool) env('FEATURE_LGD_SYNC', false),

    // Workflow and OTP notifications. Internal capability, on by default;
    // its transports are flagged separately above.
    'notifications' => (bool) env('FEATURE_NOTIFICATIONS', true),

    // UIDAI Aadhaar OTP at department registration, over the CSC
    // authentication API (credentials under `uidai` in config/dpc.php).
    // Off: the mock provider accepts DPC_AADHAAR_OTP_MOCK_CODE.
    'uidai_aadhaar_otp' => (bool) env('FEATURE_UIDAI_AADHAAR_OTP', false),
    // Officer identity through DigiLocker (2026-10-27); off = the simulator (never in production).
    'digilocker' => (bool) env('FEATURE_DIGILOCKER', false),

];
