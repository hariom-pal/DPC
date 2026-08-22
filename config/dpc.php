<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | One-Time Password (OTP)
    |--------------------------------------------------------------------------
    |
    | OTP is used to verify email + mobile at registration and as the
    | step-up "signature" on legally significant actions. Values are
    | configuration-driven so they can be tuned without code changes.
    |
    */

    'otp' => [
        'length' => (int) env('DPC_OTP_LENGTH', 6),
        'ttl_seconds' => (int) env('DPC_OTP_TTL', 600),
        'max_attempts' => (int) env('DPC_OTP_MAX_ATTEMPTS', 5),

        // When set (test/dev only), the generated code is forced to this
        // value so flows can be asserted deterministically. Never set in prod.
        'fake_code' => env('DPC_OTP_FAKE_CODE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS
    |--------------------------------------------------------------------------
    |
    | Delivery driver for SMS (OTP + credential issuance). "log" writes to
    | the application log for local/dev; a real gateway is pluggable later.
    |
    */

    'sms' => [
        // Delivery is governed by FEATURE_SMS_GATEWAY; with it off, messages
        // are written to the application log.
        'sender_id' => env('DPC_SMS_SENDER_ID', 'CGPSC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Digital Signature
    |--------------------------------------------------------------------------
    |
    | Legally significant actions are signed through a provider abstraction so
    | business logic never depends on the concrete mechanism. "mock" is used in
    | development; production will use CSC DigiSign / CSC eSign. Signed files are
    | stored on the configured filesystem disk (S3 in production).
    |
    */

    'signature' => [
        // Which provider signs is decided by FEATURE_CSC_DIGISIGN (see
        // config/features.php), not here — one switch, one place.
        'disk' => env('DPC_SIGNATURE_DISK', env('FEATURE_S3_STORAGE', false) ? 's3' : 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Rate Limiting (brute-force protection)
    |--------------------------------------------------------------------------
    |
    | Named limiters registered in AppServiceProvider and applied via the
    | `throttle:<name>` middleware. Config-driven so limits can be tuned per
    | environment without code changes. P0 security requirement — see
    | docs/CGPSC_DPC_ARCHITECTURE_HANDBOOK.md (2026-08-13 review).
    |
    */

    'rate_limiting' => [
        'login' => [
            'max_attempts' => (int) env('DPC_RATE_LIMIT_LOGIN_MAX', 5),
            'decay_seconds' => (int) env('DPC_RATE_LIMIT_LOGIN_DECAY', 60),
        ],
        'otp_verify' => [
            'max_attempts' => (int) env('DPC_RATE_LIMIT_OTP_VERIFY_MAX', 5),
            'decay_seconds' => (int) env('DPC_RATE_LIMIT_OTP_VERIFY_DECAY', 60),
        ],

        /*
         | CALCULATE (M5 Phase 4). Not brute-force protection — a calculation
         | writes permanent evidence that no code path can ever delete, so an
         | unthrottled button is a way to grow the evidence tables without
         | limit and bury the run the Commission actually acted on. A real
         | operator recalculates after fixing data, a handful of times at most.
         */
        'calculate' => [
            'max_attempts' => (int) env('DPC_RATE_LIMIT_CALCULATE_MAX', 10),
            'decay_seconds' => (int) env('DPC_RATE_LIMIT_CALCULATE_DECAY', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Administrative-area Directory (LG Directory)
    |--------------------------------------------------------------------------
    |
    | "seed" serves a static Chhattisgarh district list in dev; a live LGD-API
    | provider replaces it later without changing calling code.
    |
    */

    'directory' => [
        // Governed by FEATURE_LGD_SYNC; with it off, the seeded district list
        // is served.
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Storage
    |--------------------------------------------------------------------------
    |
    | Disk for uploaded documents (e.g. recruitment-rule Government Orders).
    | "local" in dev; "s3" in production. Only metadata is stored in MySQL.
    |
    */

    'documents' => [
        'disk' => env('DPC_DOCUMENTS_DISK', env('FEATURE_S3_STORAGE', false) ? 's3' : 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeded CGPSC Admin
    |--------------------------------------------------------------------------
    |
    | Bootstrap credentials for the CGPSC Admin account. Change the password
    | immediately in any non-local environment.
    |
    */

    'admin' => [
        'name' => env('DPC_ADMIN_NAME', 'CGPSC Administrator'),
        'email' => env('DPC_ADMIN_EMAIL', 'admin@cgpsc.gov.in'),
        'mobile' => env('DPC_ADMIN_MOBILE', '9990000000'),
        'password' => env('DPC_ADMIN_PASSWORD', 'ChangeMe@12345'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflows (configuration-driven)
    |--------------------------------------------------------------------------
    |
    | Each transition declares the event, the state(s) it is allowed from, the
    | resulting state, the role permitted to perform it, whether a digital
    | signature is required, and who is notified. The WorkflowEngine enforces
    | legality + role and records an audit ledger + timeline + notification for
    | every transition.
    |
    */

    'workflows' => [
        'pre_dpc' => [
            'transitions' => [
                'submit' => ['from' => ['draft', 'returned'], 'to' => 'submitted', 'role' => 'authorized_officer', 'signature' => false, 'notify' => ['administrative_head']],
                'approve' => ['from' => ['submitted'], 'to' => 'approved', 'role' => 'administrative_head', 'signature' => true, 'notify' => ['authorized_officer']],
                'return' => ['from' => ['submitted'], 'to' => 'returned', 'role' => 'administrative_head', 'signature' => false, 'notify' => ['authorized_officer']],
                'cancel' => ['from' => ['draft', 'returned'], 'to' => 'cancelled', 'role' => 'authorized_officer', 'signature' => false, 'notify' => ['administrative_head']],
            ],
        ],

        /*
        | CGPSC workflow (PPT S26–S30, M6). Runs on the DpcProposal as subject
        | under its own workflow name, so it sits alongside the `dpc_proposal`
        | workflow without touching M4's frozen status enum: the CGPSC-side
        | state lives in the workflow ledger and the allotment record, never as
        | a new column on `dpc_proposals`.
        |
        | Phase 1 declares only the transitions it implements. `accept`,
        | `schedule` and `reschedule` arrive with Phase 2, which produces them
        | — a transition nothing can perform would read as supported behaviour
        | when it is not.
        |
        | `notify` carries the department-side roles. The allotted Member is a
        | CGPSC user with no department, so a role cannot express "this
        | specific member"; the service passes their user id explicitly.
        */
        'dpc_cgpsc' => [
            'transitions' => [
                'allot' => ['from' => ['unallotted'], 'to' => 'allotted', 'role' => 'chairman', 'signature' => false, 'notify' => ['authorized_officer', 'administrative_head']],
                'reallot' => ['from' => ['allotted'], 'to' => 'allotted', 'role' => 'chairman', 'signature' => false, 'notify' => ['authorized_officer', 'administrative_head']],
                'accept' => ['from' => ['allotted'], 'to' => 'accepted', 'role' => 'member', 'signature' => false, 'notify' => ['authorized_officer', 'administrative_head']],
                'schedule' => ['from' => ['accepted'], 'to' => 'scheduled', 'role' => 'member', 'signature' => false, 'notify' => ['authorized_officer', 'administrative_head']],
                'reschedule' => ['from' => ['scheduled'], 'to' => 'scheduled', 'role' => 'member', 'signature' => false, 'notify' => ['authorized_officer', 'administrative_head']],
            ],
        ],

        /*
        | DPC Proceeding (PPT S31–S34, M7). Like `dpc_cgpsc`, this runs on the
        | DpcProposal as subject under its own workflow name, so every
        | CGPSC-side event lands on one subject thread and "show me everything
        | that happened to this DPC" stays a single ordered timeline query.
        |
        | Phase 1 declares only the transition it implements. `approve_result`
        | and `cancel_dpc` arrive with Phase 3, which produces them — a
        | transition nothing can perform reads as supported behaviour when it
        | is not.
        |
        | The role is `member` because that is the role the allottee holds.
        | Being *the allottee* is enforced by policy and service, not by role
        | (architect ruling AQ-6): a Chairman who wishes to conduct a DPC
        | re-allots it to himself first, so exactly one actor is accountable.
        */
        'dpc_proceeding' => [
            'transitions' => [
                'open' => ['from' => ['scheduled'], 'to' => 'in_proceeding', 'role' => 'member', 'signature' => false, 'notify' => ['authorized_officer', 'administrative_head']],

                // Phase 3. `signature` is **false** rather than true: signing
                // requires a document to sign, and the result document is
                // Phase 4. Declaring a requirement nothing yet honours would
                // be exactly the placeholder Directive 4 forbids. Phase 4
                // sets it true when signing is wired.
                'approve_result' => ['from' => ['in_proceeding'], 'to' => 'result_approved', 'role' => 'member', 'signature' => false, 'notify' => ['authorized_officer', 'administrative_head']],
                'cancel_dpc' => ['from' => ['in_proceeding'], 'to' => 'cancelled', 'role' => 'member', 'signature' => false, 'notify' => ['authorized_officer', 'administrative_head']],
            ],
        ],

        /*
        | Circulation DPC (PPT S45, S47; C3/C7 — M9). Runs on the
        | `DpcCirculation` as subject: a Circulation is its own aggregate and
        | never reuses, extends or specializes `dpc_proposals` (AD-24), so it
        | carries its own workflow rather than borrowing M4's.
        |
        | Phase 1 declares only the department's transitions. `consent`,
        | `return_to_department` and `sign` belong to the Commission and arrive
        | with Phase 2, which performs them — a transition nothing can perform
        | reads as supported behaviour when it is not.
        |
        | `approve` requires a signature (AQ-23): every department-originated
        | artefact is signed by the Administrative Head before it reaches
        | CGPSC, and a confirmation that a reserved promotion should proceed is
        | at least as significant as the proposal that produced it.
        |
        | Note what is absent and stays absent: nothing here calculates,
        | scores, determines eligibility or a zone, or conducts a proceeding
        | (AD-23). A circulation resolves a decision already made.
        */
        'dpc_circulation' => [
            'transitions' => [
                'submit' => ['from' => ['draft', 'returned'], 'to' => 'submitted', 'role' => 'authorized_officer', 'signature' => false, 'notify' => ['administrative_head']],
                'approve' => ['from' => ['submitted'], 'to' => 'approved', 'role' => 'administrative_head', 'signature' => true, 'notify' => ['authorized_officer']],
                'return' => ['from' => ['submitted'], 'to' => 'returned', 'role' => 'administrative_head', 'signature' => false, 'notify' => ['authorized_officer']],
                'cancel' => ['from' => ['draft', 'returned'], 'to' => 'cancelled', 'role' => 'authorized_officer', 'signature' => false, 'notify' => ['administrative_head']],

                /*
                | Phase 2 — the Commission's side.
                |
                | The role is `member` because that is the role the origin
                | DPC's allottee holds. Being *that* allottee is enforced by
                | policy and service, not by role (AQ-20, reusing AQ-6): the
                | person who held the promotion is the person who releases it,
                | and a Chairman is not exempt.
                |
                | `consent` and `sign` are separate on purpose. The Commission's
                | decision on each officer and its signature on the note are
                | different acts, and C3 names the signature as what the note is
                | sent for. Collapsing them would make the per-officer
                | decisions unrecorded intent.
                |
                | `sign` carries the signature and is where holds are released
                | (AQ-17) — never at consent, and never at the department's
                | approval.
                */
                'consent' => ['from' => ['approved'], 'to' => 'consented', 'role' => 'member', 'signature' => false, 'notify' => ['authorized_officer', 'administrative_head']],
                'return_to_department' => ['from' => ['approved', 'consented'], 'to' => 'returned', 'role' => 'member', 'signature' => false, 'notify' => ['authorized_officer', 'administrative_head']],
                'sign' => ['from' => ['consented'], 'to' => 'signed', 'role' => 'member', 'signature' => true, 'notify' => ['authorized_officer', 'administrative_head']],
            ],
        ],

        // DPC Proposal (PPT S12–S25, M4). A single workflow approval covers
        // all sections the AH reviews (architect decision, 2026-08-13) — one
        // signature, one transition, one timeline entry, one audit event.
        'dpc_proposal' => [
            'transitions' => [
                'submit' => ['from' => ['draft', 'returned'], 'to' => 'submitted', 'role' => 'authorized_officer', 'signature' => false, 'notify' => ['administrative_head']],
                'approve' => ['from' => ['submitted'], 'to' => 'approved', 'role' => 'administrative_head', 'signature' => true, 'notify' => ['authorized_officer']],
                'return' => ['from' => ['submitted'], 'to' => 'returned', 'role' => 'administrative_head', 'signature' => false, 'notify' => ['authorized_officer']],
                'cancel' => ['from' => ['draft', 'returned'], 'to' => 'cancelled', 'role' => 'authorized_officer', 'signature' => false, 'notify' => ['administrative_head']],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DPC Proceeding (PPT S31–S34)
    |--------------------------------------------------------------------------
    |
    | M7. Every value here is a business rule expressed as configuration, so a
    | government notification changes a setting rather than source code — the
    | M5 freeze doctrine applied to the proceeding.
    |
    | Phase 1 uses `allow_open_before_scheduled_time` only. The remaining keys
    | are declared with the phase that consumes them.
    |
    */

    'proceeding' => [
        // S31 says the DPC is conducted "at the prescribed date and time".
        // Whether the system *enforces* that is configurable, defaulting
        // permissive (architect ruling AQ-5): the deck's sentence governs the
        // validity of department data changes, not whether the Commission may
        // convene early, and a strict default would make the system
        // undemonstrable. The actual opening time is always recorded.
        'allow_open_before_scheduled_time' => (bool) env('DPC_ALLOW_EARLY_PROCEEDING', true),

        // S33 EDIT / TRIBAL. Which fields the Commission may correct at the
        // sitting, so the set can be narrowed or widened by notification
        // rather than by code. `CorrectableField` bounds what may be named
        // here; this bounds what is actually enabled.
        //
        // Deliberately absent: the officer's identity fields (name, DOB,
        // category, qualification). Those are the department's record of who
        // the officer *is*, not of how they performed, and correcting them at
        // the sitting would change who was considered rather than how they
        // scored. If CGPSC needs that, it is a Review DPC.
        'correctable_fields' => [
            'tribal_area_service_years',
            'cr_grade',
            'loyalty_flag',
            'property_flag',
        ],

        // A correction without a stated reason evidences nothing, and the
        // reason is what a tribunal reads.
        'correction_reason_min' => (int) env('DPC_CORRECTION_REASON_MIN', 10),

        /*
        | Held promotions — `CIR` (PPT S45, Conventional deck C3).
        |
        | A hold reserves the promotion action for an officer who still takes
        | part in the DPC. Both triggers are configurable because ambiguity Q12
        | is formally open, though S33 shows the two formulations are the same
        | rule: the chart carries six CR columns, so "2 or more years missing"
        | and the S14 label's "CR <= 4" describe one condition from opposite
        | ends.
        */
        'hold' => [
            'enabled' => (bool) env('DPC_HOLDS_ENABLED', true),

            // Fewer than this many CR years recorded -> held.
            'min_cr_years' => (int) env('DPC_HOLD_MIN_CR_YEARS', 5),

            // Any year without an immovable-property return -> held.
            'property_required' => (bool) env('DPC_HOLD_PROPERTY_REQUIRED', true),
        ],

        /*
        | Proceedings document and promotion list.
        |
        | `format` is documentation of intent rather than a switch: the renderer
        | is resolved from the `DocumentRenderer` contract in AppServiceProvider,
        | so PDF later means binding a different implementation (AQ-3).
        */
        'documents' => [
            'disk' => env('DPC_PROCEEDING_DISK', 'local'),
            'format' => env('DPC_PROCEEDING_FORMAT', 'html'),

            // Recorded on every document so it can say what produced it.
            // Bump when the rendered output changes materially.
            'generator_version' => env('DPC_DOCUMENT_GENERATOR_VERSION', 'html-v1'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DPC ID
    |--------------------------------------------------------------------------
    |
    | Format <DeptCode><Year><Serial> (PPT S28/S32/S37, e.g. HE201711111).
    | Serial is per department per year, generated race-safely by
    | DpcIdGeneratorService at proposal approval. Width is config-driven,
    | never hardcoded.
    |
    */

    'dpc_id' => [
        'serial_width' => (int) env('DPC_ID_SERIAL_WIDTH', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evaluation / Tabulation Chart (PPT S18)
    |--------------------------------------------------------------------------
    |
    | Reference values for the tabulation chart, kept in config per Q3 so a
    | government notification can change them without a code change. These
    | drive data entry and validation only.
    |
    | Deliberately absent: the grade -> mark mapping, qualifying-score
    | thresholds, zone-of-consideration sizing, and every eligibility gate.
    | M4 stores raw data only; all calculation lives in the M5 Eligibility
    | Engine (architect directive, 2026-08-13).
    |
    */

    'evaluation' => [
        // CR grade codes in descending order of merit (PPT C4).
        'cr_grades' => ['क+', 'क', 'ख', 'ग', 'घ'],

        // The S18 chart carries SIX "YEAR WISE CR" columns (2012–2017 in the
        // PPT's worked example) while only the last five are scored (§5.4).
        // This bounds data entry, not scoring — the five-year scoring window
        // is a calculation and belongs to M5 — so it follows the chart, not
        // the rule. Fewer years may always be entered: a Circulation DPC
        // exists precisely because an officer is missing CR years.
        'max_cr_years' => (int) env('DPC_EVALUATION_MAX_CR_YEARS', 6),

        // Sanity bound for a CR/loyalty/property year. Not a business rule.
        'min_year' => (int) env('DPC_EVALUATION_MIN_YEAR', 1950),

        // AO-enterable Note codes (PPT S18, requirements §6.3). Offered as
        // suggestions in the UI; the column stays free text because the PPT
        // shows the officer typing this. EL/NE/SS are *computed* remarks and
        // are intentionally not listed here — those are M5 outputs.
        'note_codes' => ['PUNISHMENT', 'C.CASE', 'CIR'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pre-DPC projection (M3 — PPT S7–S11)
    |--------------------------------------------------------------------------
    |
    | **TD-25.** On approval a Pre-DPC projects into the Cadre and Recruitment Rule
    | masters. A feeder named only as text is resolved by name within the
    | department; when nothing matches, the feeder used to be **skipped in
    | silence** and the rule created without it — so the rule looked complete and
    | sanctioned nobody from that cadre.
    |
    | The reporting half was fixed on 2026-08-19 (a warning at projection plus
    | `php artisan pre-dpc:unmatched-feeders`). This is the behavioural half, and
    | it is configuration because the register offered two answers and each is right
    | in a different situation:
    |
    |   true  — **refuse the approval.** The default. The screen has posted
    |           `feeder_cadre_id` since 2026-08-18, so a text feeder that matches
    |           nothing now means a data problem, and a Government Order that
    |           sanctions nobody is worse than an approval that stops to say so.
    |   false — project anyway and report. The pre-2026-08-18 behaviour, for a
    |           department mid-migration whose historical feeders have not yet been
    |           registered as cadres.
    |
    */

    'pre_dpc' => [
        'refuse_unmatched_feeder' => (bool) env('DPC_PRE_DPC_REFUSE_UNMATCHED_FEEDER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Security Headers (M11 hardening)
    |--------------------------------------------------------------------------
    |
    | Applied by `App\Http\Middleware\SecurityHeaders` to every web response.
    |
    | **Every value can be overridden or emptied, and the whole set switched off.**
    | A header that breaks a page in production and cannot be disabled without a
    | deploy is worse than an absent one — so each is configuration, and an empty
    | string omits that header entirely.
    |
    | The default Content-Security-Policy is strict because this application can
    | afford to be: it vendors Bootstrap and its own scripts and loads nothing from
    | a CDN (an M0 decision). `script-src 'self'` therefore costs nothing today,
    | and the reason to set it now is that it stops costing nothing the moment
    | somebody pastes in a third-party widget.
    |
    | Inline **styles** are allowed and inline **scripts** are not: several views
    | set element widths inline, while an inline-script allowance is what turns a
    | reflected string into executable code.
    |
    */

    'security' => [
        'headers' => [
            'enabled' => (bool) env('DPC_SECURITY_HEADERS', true),

            // Set to a string to replace the built-in policy outright, or to an
            // empty string to send no CSP at all.
            'content_security_policy' => env('DPC_CSP'),

            /*
            | Enforce the policy, or only report violations. **Enforced by
            | default since 2026-08-22, when TD-29 closed.**
            |
            | It shipped report-only for most of the project's life, and that was
            | a finding rather than a preference: the application had 6 views with
            | inline `<script>` blocks and 21 inline event handlers, several on
            | M4's frozen screens. Under an enforced `script-src 'self'` a browser
            | silently refuses all of them — the add-a-row buttons on five forms
            | stop working, and every confirmation before a destructive action
            | stops appearing, so the form simply submits.
            |
            | **The test suite cannot see any of that**, because CSP is enforced
            | by the browser and not the server: a screen returns 200 either way.
            | So the switch was not thrown on a green suite. Every inline handler
            | was moved into `public/js/`, and the result was then driven in
            | headless Chromium with the policy *enforced* — twelve checks across
            | the committee, seniority, working-officers, evaluation-chart,
            | Pre-DPC and recruitment-rule forms, plus the confirmation dialogues
            | and the accessibility control — with **zero violations reported and
            | zero console errors**. Only then was this default changed.
            |
            | `ViewsAreCspCleanTest` now fails the build if an inline handler or
            | script block comes back, so the flag stays honest.
            |
            | Left configurable because a header that breaks a page in production
            | and cannot be turned off without a deploy is worse than an absent
            | one: `DPC_CSP_ENFORCE=false` reverts to report-only in one line.
            | Adding `'unsafe-inline'` instead would make the header decorative
            | while looking strict, and is not offered.
            */
            'enforce_csp' => (bool) env('DPC_CSP_ENFORCE', true),

            // Where the browser posts violations. Unset by default: inventing an
            // endpoint that silently discards reports is worse than having none.
            'csp_report_uri' => env('DPC_CSP_REPORT_URI'),

            'frame_options' => env('DPC_FRAME_OPTIONS', 'SAMEORIGIN'),
            'referrer_policy' => env('DPC_REFERRER_POLICY', 'same-origin'),
            'permissions_policy' => env(
                'DPC_PERMISSIONS_POLICY',
                'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            ),

            // Sent only over TLS — pinning `localhost` to HTTPS in a developer's
            // browser is a genuinely annoying thing to do to somebody.
            'hsts' => env('DPC_HSTS', 'max-age=31536000; includeSubDomains'),
        ],

        /*
        |----------------------------------------------------------------------
        | Evidence tables — the database grants a production rollout must apply
        |----------------------------------------------------------------------
        |
        | **TD-1.** These twelve tables hold evidence: a calculation and its
        | results, the Commission's allotment and schedule, the sitting, its
        | corrections and holds, the review chain, and the signed documents that
        | render all of it. Every one is immutable in the application — each model
        | rejects `update` outside the narrow set below and rejects `delete`
        | outright — and every one of those guards lives in Eloquent's model
        | events.
        |
        | **The list is here rather than in a runbook because the runbook had
        | already gone stale.** It was written during M5 and named two tables; M6,
        | M7, M8 and M9 added ten more, and a document cannot notice a migration.
        | `EvidenceGrantsTest` compares this list against every model that guards
        | itself, so the next one cannot be forgotten.
        |
        | **A query-builder mass update bypasses model events entirely:**
        |
        |   DpcCalculationResult::query()->where(...)->update([...]);
        |
        | That is a characteristic of the ORM, not a defect, and it cannot be
        | closed from inside the application: any guard added lives in the layer
        | being bypassed. So the honest statement of the guarantee is that no
        | application code path can edit a completed calculation, and no
        | developer can do so accidentally — but a developer with database access
        | can. For a promotion record that may be produced in CAT or the High
        | Court, that distinction matters, and **database permissions are what
        | close it.** Triggers were considered and rejected by the architect:
        | they make a table un-migratable without dropping them first, and the
        | syntax differs between MySQL and SQLite.
        |
        | **`update` is a list of columns, not a flag, and that is the whole
        | subtlety of this control.** Revoking UPDATE outright on
        | `dpc_calculation_runs` breaks every calculation, because completing a
        | run is one UPDATE of `status`. The same is true of a document, which
        | legitimately gets published after it is generated. A column-level grant
        | permits exactly the lifecycle move and nothing else; `update => []`
        | means no UPDATE at all.
        |
        | Each list below mirrors the `$permitted` set in that table's model
        | guard, and `EvidenceGrantsTest` fails if a model with an immutability
        | guard is missing from this list — which is how a future evidence table
        | gets into the runbook instead of being remembered.
        |
        | `php artisan security:evidence-grants` prints the SQL; `--verify`
        | reports whether the running database actually refuses the writes.
        */
        'evidence_tables' => [
            // M5. The calculation and its results — the scoring evidence.
            'dpc_calculation_runs' => [
                // Draft -> Completed, and nothing else. Revoking UPDATE
                // outright here stops every calculation from completing.
                'update' => ['status'],
            ],
            'dpc_calculation_results' => [
                // Written once, never transitioned.
                'update' => [],
            ],

            // M7. The sitting, and what it decided.
            'dpc_proceedings' => [
                'update' => [
                    'status', 'promotion_as_on_year',
                    'latest_calculation_run_id', 'calculated_through_sequence',
                    'calculation_engine_version', 'final_calculation_run_id',
                    'approved_at', 'signature_id', 'cancelled_at', 'cancellation_reason',
                    'closed_by', 'closed_by_name', 'closed_at',
                    'updated_at',
                ],
            ],
            'dpc_proceeding_overrides' => [
                // A correction is superseded by a later one; it is never edited.
                'update' => ['superseded_by_id', 'updated_at'],
            ],
            'dpc_proceeding_holds' => [
                // A hold is released. Nothing else about it moves.
                'update' => ['released_at', 'updated_at'],
            ],
            'dpc_proceeding_documents' => [
                // Generation writes the bytes, the hash and the signature;
                // release publishes them. What the document *is* — which
                // proceeding, which calculation, which version — is fixed.
                'update' => [
                    'status', 'disk', 'path', 'content_hash', 'signature_id',
                    'generated_at', 'generated_by', 'generated_by_name',
                    'published_at', 'failure_reason', 'updated_at',
                ],
            ],

            // M6. Who the Commission gave the DPC to, and when it sits.
            // Neither was in the original runbook: it predated M6.
            'dpc_allotments' => [
                // Accepted, scheduled, superseded. Who it was allotted to and
                // by whom is history the moment it is written.
                'update' => ['status', 'accepted_at', 'superseded_by_id', 'updated_at'],
            ],
            'dpc_schedules' => [
                // A sitting is re-scheduled by superseding this row, never by
                // moving the date on it.
                'update' => ['superseded_by_id', 'reschedule_reason', 'updated_at'],
            ],

            // M8. The correction chain — which DPC corrects which, under whose
            // rules, and from when the supersession bites.
            'dpc_review_links' => [
                // A supersession takes effect once and is never lifted.
                'update' => ['superseded_at', 'updated_at'],
            ],
            'dpc_review_calculations' => [
                // Provenance: which run was executed under which snapshot.
                'update' => [],
            ],
            'dpc_document_review_references' => [
                'update' => ['supersession_effective_at', 'updated_at'],
            ],

            // M9. The circulation note.
            'dpc_circulation_documents' => [
                'update' => [
                    'status', 'disk', 'path', 'content_hash', 'signature_id',
                    'generated_at', 'failure_reason', 'published_at', 'updated_at',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications — in-app channel (M11)
    |--------------------------------------------------------------------------
    |
    | The third channel, beside SMS and e-mail. It exists because the other two
    | cannot be relied on yet: the government SMS gateway is not procured
    | (**TD-6**) and `FEATURE_NOTIFICATIONS` defaults to off, so without this
    | nobody is actually told anything — in a demo, or on the day the system goes
    | live before its gateway does.
    |
    | **Deliberately NOT behind `FEATURE_NOTIFICATIONS`.** That flag gates external
    | delivery, which is what needs a procured gateway and a phased rollout. An
    | in-app record leaves the application, calls nothing and cannot fail
    | half-way, so gating it behind the flag that guards outbound integrations
    | would switch off the only channel that always works.
    |
    */

    'notifications' => [
        'in_app' => [
            'enabled' => (bool) env('DPC_NOTIFICATIONS_IN_APP', true),

            /*
            | Seconds to cache the unread badge count. **0 — off — is the
            | default, deliberately.**
            |
            | It was 30, and the N+1 detector on the DPC proposal detail screen
            | caught why that is wrong: a cached count makes a page issue a
            | different number of queries on its second render than on its first.
            | The detector compares two requests and concludes a section stopped
            | being eager-loaded. It was a false positive that time — and a
            | *permanent* source of them, on the one test in the suite that can
            | catch a real N+1 regression on the heaviest screen in the system.
            | Blunting that detector to cache one indexed `count()` is a bad
            | trade.
            |
            | The query is a `count()` over the `(user_id, read_at)` index. Set a
            | value here if profiling ever shows it matters.
            */
            'unread_count_cache_seconds' => (int) env('DPC_NOTIFICATIONS_UNREAD_CACHE', 0),

            // How many appear in the header dropdown before "see all".
            'dropdown_limit' => (int) env('DPC_NOTIFICATIONS_DROPDOWN', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-Inspection & Assessment (M10 — PPT S49–S51)
    |--------------------------------------------------------------------------
    |
    | S49: *"विभागीय अधिकारीयों व कर्मचारियों को स्वयं के डाटा अवलोकन की सुविधा"* —
    | the facility for a departmental officer to **view their own data** before
    | the DPC sits. S51 shows that data, a deadline notice, and exactly two
    | buttons: CLOSE and PRINT.
    |
    | **Every judgement this screen needs is a configuration value here**, so a
    | business ruling is applied by editing configuration rather than code
    | (AD-3, AD-19). Two of them stand in for open questions and are named as
    | such below.
    |
    */

    'self_inspection' => [

        'enabled' => (bool) env('DPC_SELF_INSPECTION_ENABLED', true),

        /*
        | BD-19 — how does an individual officer authenticate?
        |
        | S50 uses Aadhaar No. + Aadhaar OTP. Q1 replaced Aadhaar project-wide
        | with identifier + password and OTP step-up — but that deviation
        | assumed a **registered user**, and an individual under consideration
        | is not one: they exist only as a row in an officer snapshot, and
        | **none of the three ten-field snapshots carries a mobile or an
        | email**, so there is no channel to send an OTP to.
        |
        | The three answers BD-19 chooses between are the three drivers here,
        | so the ruling is a configuration change:
        |
        |   `identity_factors` — the officer proves who they are with facts only
        |                        they and their department hold. **Default, and
        |                        the only one buildable from what exists.**
        |   `otp`              — requires a contact channel on the officer
        |                        record. Selecting it today returns a
        |                        structured *Pending Business Decision (BD-19)*
        |                        rather than pretending a channel exists.
        |   `account`          — the department provisions an Individual
        |                        account. A new workflow, in neither deck; also
        |                        refused as pending until ruled.
        */
        'authentication' => [
            'driver' => env('DPC_SELF_INSPECTION_AUTH_DRIVER', 'identity_factors'),

            /*
            | Which facts must all match for `identity_factors`.
            |
            | S50 asks for **two** — name as on the Aadhaar card, and date of
            | joining the current cadre. This defaults to **three**, adding date
            | of birth, because the screen it opens shows year-wise CR grades,
            | reservation category and immovable-property compliance. Two
            | factors from a document that circulates as a seniority list is
            | thinner than any other actor's login in this system.
            |
            | Reducing this to the PPT's two is a configuration change. It is
            | deliberately not the default.
            |
            | Available: name, dob, date_of_posting_on_current_post,
            | date_of_posting_on_feeder_post, date_of_joining_service,
            | employee_number.
            */
            'factors' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env(
                    'DPC_SELF_INSPECTION_FACTORS',
                    'name,date_of_posting_on_current_post,dob',
                )),
            ))),
        ],

        /*
        | Which proposals an officer may inspect their row on.
        |
        | `submitted` and `approved` only: the point of the screen is to catch a
        | data error *before* the committee sits, and a draft is still being
        | edited — showing it would present figures the department has not
        | finished entering as the figures under consideration. `cancelled` and
        | `returned` are excluded for the same reason from the other end.
        */
        'visible_proposal_statuses' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DPC_SELF_INSPECTION_STATUSES', 'submitted,approved')),
        ))),

        /*
        | Close the screen once the sitting has produced a result.
        |
        | After that, correcting the chart is no longer what the officer is
        | being invited to do — the route is a Review DPC (M8), which is the
        | department's to raise. Configurable because it is a policy about when
        | inspection stops being useful, not a rule from the deck.
        */
        'close_after_result' => (bool) env('DPC_SELF_INSPECTION_CLOSE_AFTER_RESULT', true),

        /*
        | The date in S51's notice: *"...दिनांक 25 जून 2026 या उसके पूर्व विभाग में
        | स्वयं उपस्थित होकर समाधान करवा लेवें..."*
        |
        | The deck shows a date but says nothing about where it comes from.
        | Rather than invent one, it is derived:
        |
        |   `days_before_sitting` — N days before the scheduled DPC date.
        |                           **Default.** The only basis the data
        |                           supports today.
        |   `none`                — show the notice with **no date**. Its
        |                           substance is unconditional; only the
        |                           deadline is derived.
        |
        | **When no basis can be resolved — no sitting scheduled yet — the
        | notice is shown without a date rather than with a guessed one.** The
        | officer is still told to raise a correction with the department in
        | person, which is the part the deck actually states.
        */
        'objection_deadline' => [
            'basis' => env('DPC_SELF_INSPECTION_DEADLINE_BASIS', 'days_before_sitting'),
            'days_before_sitting' => (int) env('DPC_SELF_INSPECTION_DEADLINE_DAYS', 15),
        ],

        /*
        | Brute-force protection, keyed on the claimed identity and the caller's
        | IP. Lower than the login limiter: an officer types their own name and
        | two dates, and gets them right.
        */
        'rate_limit' => [
            'max_attempts' => (int) env('DPC_SELF_INSPECTION_RATE_MAX', 5),
            'decay_seconds' => (int) env('DPC_SELF_INSPECTION_RATE_DECAY', 300),
        ],

        // How long a matched session stays open before the officer must
        // identify themselves again.
        'session_lifetime_minutes' => (int) env('DPC_SELF_INSPECTION_SESSION_MINUTES', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling Time Zone
    |--------------------------------------------------------------------------
    |
    | The zone DPC dates and times are understood to be in. **Preparation only**
    | (architect directive): nothing converts between zones today, and the
    | default is and remains Asia/Kolkata.
    |
    | It exists now because a stored `14:00` is meaningless without it. The zone
    | is recorded on every scheduling timeline event, so a future consumer — a
    | calendar entry, an export, a rescheduling rule — can resolve the wall
    | clock to an absolute instant correctly, including for events scheduled
    | before any time-zone support was built.
    |
    */

    'timezone' => env('DPC_TIMEZONE', 'Asia/Kolkata'),

    /*
    |--------------------------------------------------------------------------
    | Build Provenance
    |--------------------------------------------------------------------------
    |
    | Stamped onto every calculation run (architect directive, 2026-08-13) so a
    | result can be traced to the exact build that produced it. If a defect in
    | the engine is ever found, these three values are what identify which
    | stored calculations were computed by the affected code — a question that
    | is unanswerable after the fact without them.
    |
    | Supplied by the deployment pipeline; the defaults make it obvious when
    | they were not.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Circulation DPC (PPT S45–S48, C3/C7 — M9)
    |--------------------------------------------------------------------------
    |
    | Its own block, because a Circulation is its own aggregate (AD-24) and
    | shares no configuration with the proceeding.
    |
    */

    'circulation' => [

        'documents' => [
            'disk' => env('DPC_CIRCULATION_DISK', 'local'),
            'generator_version' => env('DPC_CIRCULATION_GENERATOR_VERSION', 'circ-html-v1'),
        ],

        /*
        | BD-14 — from what date does a circulated promotion take effect?
        |
        | **null means unresolved**, and while it is null the documents are
        | generated, stored, hashed, signed and verifiable but **publication is
        | refused** with "Pending Business Decision (BD-14)" (design §23.1).
        |
        | Expressed as configuration rather than as a constant because business
        | rules live in configuration (AD-3): when CGPSC rules, the answer is
        | applied by setting this value — no code change, no redeploy of
        | business logic, and the ruling is auditable as a configuration change.
        |
        | Permitted values once ruled: `origin_dpc_year` (retrospective to the
        | DPC's promotion-as-on year) or `consent_date` (from the Commission's
        | consent). **Nothing here guesses which**, and no default is supplied.
        */
        'effective_date_basis' => env('DPC_CIRCULATION_EFFECTIVE_DATE_BASIS'),
    ],

    'application' => [
        'version' => env('APP_VERSION', '0.0.0-dev'),
        'git_commit' => env('APP_GIT_COMMIT', 'unknown'),
        'build_timestamp' => env('APP_BUILD_TIMESTAMP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring & Eligibility Rules (M5 — PPT C4–C6, S33; requirements §5)
    |--------------------------------------------------------------------------
    |
    | Every mark, threshold, bonus, and multiplier the Eligibility Engine uses
    | lives here (architect directive: never hardcode a business rule). These
    | change by government notification, and a promotion computed under an old
    | notification must stay explicable years later — so every calculation run
    | records `rule_version` plus a full snapshot of this block.
    |
    | Bump `rule_version` whenever any value below changes. It is the label a
    | stored calculation is explained by; leaving it stale would make two
    | different rule sets indistinguishable in the record.
    |
    */

    'scoring' => [

        'rule_version' => env('DPC_RULE_VERSION', '2026.1'),

        /*
        | The version of the calculation engine itself (architect Directive 1,
        | M7 Phase 4), as distinct from `rule_version`, which versions the
        | *rules* the engine applies. The two move independently: a government
        | notification changes the rules without changing the engine, and a
        | bug fix changes the engine without changing the rules.
        |
        | Recorded on every calculate event, on the proceeding, and stamped
        | into every generated document. It is **not** written onto the M5 run
        | row: doing so would mean editing frozen M5 code, or writing to M5's
        | table behind its own immutability guard. See the Phase 4 review.
        */
        'engine_version' => env('DPC_ENGINE_VERSION', '1.0.0'),

        // §5.1 CR grade -> marks.
        'cr_grade_marks' => [
            'क+' => 4,
            'क' => 3,
            'ख' => 2,
            'ग' => 1,
            'घ' => 0,
        ],

        // §5.4 The score is the sum of the last N CR years. The S18 chart
        // carries six year columns while only the last five are scored, so
        // this window is a scoring rule and is deliberately NOT the same
        // setting as `evaluation.max_cr_years`, which bounds data entry.
        'scoring_years' => (int) env('DPC_SCORING_YEARS', 5),

        // §5.2 Tribal-area service bonus, added once when the officer has any
        // recorded tribal-area service.
        'tribal_area_bonus' => (int) env('DPC_TRIBAL_AREA_BONUS', 1),

        // §5.3 Hard eligibility gates.
        'gates' => [
            // Any CR in the scoring window with this grade fails outright.
            'disqualifying_grade' => env('DPC_DISQUALIFYING_GRADE', 'घ'),

            // For CLASS-I -> CLASS-I only: the most recent CR must not be this
            // grade. Keyed by "<from>-<to>" so another class pair can be given
            // the same kind of gate by notification without a code change.
            'last_year_forbidden_grade' => [
                'I-I' => env('DPC_LAST_YEAR_FORBIDDEN_GRADE_I_I', 'ग'),
            ],
        ],

        // §5.4 Qualifying score by promotion class pair, "<from>-<to>".
        // `default` covers the PPT's "शेष" (all other cases).
        'qualifying_scores' => [
            'I-I' => (int) env('DPC_QUALIFYING_I_I', 15),
            'II-I' => (int) env('DPC_QUALIFYING_II_I', 13),
            'II-II' => (int) env('DPC_QUALIFYING_II_II', 11),
            'default' => (int) env('DPC_QUALIFYING_DEFAULT', 10),
        ],

        /*
        | Reserved relaxation (§5.4, decisions Q5/Q6) — WAITING FOR BUSINESS
        | DECISION. Architect ruling, 2026-08-13: do not guess.
        |
        | The printed rule allows the qualifying score to be relaxed down to 10
        | for reserved-category officers if they remain insufficient after zone
        | expansion. What is NOT settled is whether the engine applies that
        | automatically or a committee exercises it as logged discretion (Q5),
        | and whether it applies only to CLASS-I -> CLASS-I as printed or to
        | every class pair (Q6).
        |
        | `enabled` stays false until ruled. While false the engine applies the
        | printed thresholds above and *flags* every officer the relaxation
        | could have affected, rather than silently deciding either way. The
        | floor is configured now so enabling it later is a flag, not a change.
        */
        'reserved_relaxation' => [
            'enabled' => (bool) env('DPC_RESERVED_RELAXATION_ENABLED', false),
            'floor' => (int) env('DPC_RESERVED_RELAXATION_FLOOR', 10),
        ],

        /*
        | §5.5 Super Seat (SS). All CR years in the scoring window at the top
        | grade -> notional (काल्पनिक) seniority; among several, actual
        | seniority decides the order.
        |
        | Keyed on the grade rather than on a total of 20, so that changing
        | `cr_grade_marks` by notification cannot silently break the rule: with
        | a grade-based test, "all top grades" stays "all top grades" whatever
        | the top grade happens to be worth.
        */
        'super_seat' => [
            'required_grade' => env('DPC_SUPER_SEAT_GRADE', 'क+'),
        ],

        /*
        | §5.6 Zone of Consideration (विचार्य संख्या).
        |
        | Sizing rules are keyed by promotion class pair. A pair with no entry
        | has no numeric zone: the PPT's "other cases" rule admits every
        | officer who meets the experience qualification, which is a filter on
        | officers rather than a cap on how many are considered.
        |
        | The Class-I -> Class-I formula reproduces the PPT's own worked
        | example on S17/S18: 2 vacancies -> 2 x 2 + 4 = 8.
        */
        'zone' => [
            'rules' => [
                'I-I' => [
                    'single_vacancy_size' => (int) env('DPC_ZONE_I_I_SINGLE', 5),
                    'multiplier' => (int) env('DPC_ZONE_I_I_MULTIPLIER', 2),
                    'addend' => (int) env('DPC_ZONE_I_I_ADDEND', 4),
                ],
            ],

            /*
            | Reserved-category shortfall expansion (7 x that category's
            | vacancy, for that category only) — part of decision Q5 and
            | therefore WAITING FOR BUSINESS DECISION, exactly like the
            | qualifying-score relaxation above.
            |
            | Architect ruling, 2026-08-13: do not guess. While `enabled` is
            | false the engine sizes the zone by the printed formula and
            | *flags* the categories an expansion could have helped, rather
            | than expanding on a rule nobody has approved. The multiplier is
            | configured now so enabling it later is a flag, not a change.
            */
            'reserved_expansion' => [
                'enabled' => (bool) env('DPC_ZONE_RESERVED_EXPANSION_ENABLED', false),
                'multiplier' => (int) env('DPC_ZONE_RESERVED_EXPANSION_MULTIPLIER', 7),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Section Size Limits
    |--------------------------------------------------------------------------
    |
    | Upper bound on how many rows a single write to each proposal section may
    | carry. These are NOT business rules — no PPT rule caps a seniority list —
    | they exist so one authenticated request cannot open an unbounded
    | transaction. Architect decision, 2026-08-13 (Decision 3): bound every
    | officer array from configuration; never rely on PHP's `max_input_vars`
    | default, which caps this only by coincidence.
    |
    | Committee size is separately constrained by the active
    | CommitteeConfiguration master (min/max); the value here is only the
    | outer request-size guard.
    |
    */

    'limits' => [
        'committee_members' => (int) env('DPC_LIMIT_COMMITTEE_MEMBERS', 50),
        'vacancy_rows' => (int) env('DPC_LIMIT_VACANCY_ROWS', 200),
        'working_officers' => (int) env('DPC_LIMIT_WORKING_OFFICERS', 500),
        'seniority_entries' => (int) env('DPC_LIMIT_SENIORITY_ENTRIES', 500),
        'evaluation_rows' => (int) env('DPC_LIMIT_EVALUATION_ROWS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Excel Import (PPT S16–S18 upload templates)
    |--------------------------------------------------------------------------
    |
    | The three upload templates are imported through a staged pipeline:
    | Preview -> Validation -> Error Report -> Commit (architect directive,
    | 2026-08-13). Nothing is written to a proposal section until the officer
    | explicitly commits a previewed batch.
    |
    */

    'imports' => [
        'disk' => env('DPC_IMPORT_DISK', env('FEATURE_S3_STORAGE', false) ? 's3' : 'local'),

        // Accepted upload formats. XLSX/XLS are the PPT's own templates; CSV
        // is accepted because it is the same tabular shape and is what most
        // departments can produce without Excel.
        'mimes' => ['xlsx', 'xls', 'csv', 'txt'],

        // Max upload size in kilobytes.
        'max_file_kb' => (int) env('DPC_IMPORT_MAX_FILE_KB', 5120),

        // Max data rows accepted from one file. Deliberately matches the
        // section limits above — an import cannot smuggle in a larger write
        // than manual entry allows.
        'max_rows' => (int) env('DPC_IMPORT_MAX_ROWS', 500),
    ],

];
