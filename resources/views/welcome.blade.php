@extends('layouts.portal')

@section('title', 'e-DPC Portal — Chhattisgarh Public Service Commission')

@section('content')

    {{-- ============================================================ HERO --}}
    <section class="gov-hero py-5">
        <div class="container position-relative py-lg-4" style="z-index:1">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <span class="badge text-bg-light text-uppercase mb-3" style="letter-spacing:.08em">
                        Government of Chhattisgarh
                    </span>
                    <h1 class="mb-3">
                        Online Departmental Promotion Committee
                        <span class="d-block mt-2" style="font-size:.62em;font-weight:500;opacity:.92">
                            विभागीय पदोन्नति समिति — ऑनलाइन प्रक्रिया
                        </span>
                    </h1>
                    <p class="lead mb-4">
                        A single, paperless and auditable process for departmental promotions — from the
                        department's proposal through the Commission's proceeding to the signed promotion
                        list, with every decision recorded and verifiable.
                    </p>

                    <div class="d-flex flex-wrap gap-3 mb-4">
                        <a href="{{ route('register') }}" class="btn btn-accent btn-lg d-inline-flex align-items-center gap-2">
                            @include('partials.icon', ['name' => 'user-plus', 'size' => 20])
                            New Registration
                        </a>
                        <a href="{{ route('login') }}" class="btn btn-outline-light btn-lg d-inline-flex align-items-center gap-2">
                            @include('partials.icon', ['name' => 'login', 'size' => 20])
                            Department Login
                        </a>
                    </div>

                    <div class="row g-2">
                        @foreach ([
                            ['shield', 'Secure'],
                            ['paperless', 'Paperless'],
                            ['audit', 'Audit Trail'],
                            ['signature', 'Digital Signature'],
                            ['workflow', 'Workflow Driven'],
                            ['roles', 'Role Based'],
                        ] as [$icon, $label])
                            <div class="col-6 col-md-4">
                                <div class="glass hero-feature">
                                    @include('partials.icon', ['name' => $icon, 'size' => 18])
                                    <span>{{ $label }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="col-lg-6 hero-art">
                    <img src="{{ asset('images/hero-building.svg') }}"
                         alt="Illustration of a government secretariat building" loading="lazy">
                </div>
            </div>
        </div>
    </section>

    {{-- =================================================== QUICK ACTIONS --}}
    <section class="py-5" aria-labelledby="quick-actions-title">
        <div class="container">
            <div class="text-center mb-4">
                <div class="section-kicker">Get Started</div>
                <h2 class="section-title mb-2" id="quick-actions-title">Quick Actions</h2>
                <div class="section-rule mx-auto"></div>
            </div>

            <div class="row g-3 g-lg-4">
                @foreach ([
                    ['user-plus', 'New Registration', 'Register your department and request portal access.', route('register')],
                    ['login', 'Department Login', 'Authorized Officers and Administrative Heads sign in here.', route('login')],
                    ['admin', 'Super Admin Login', 'Commission administrators — master data and approvals.', route('login')],
                    // S49. The officer under consideration has no account, so this
                    // is their only way in — and a screen nobody can reach is a
                    // screen that was not delivered.
                    ['eye', 'Self-Inspection', 'Officers under consideration — view the data your department recorded.', route('self-inspection.landing')],
                    ['book', 'User Manual', 'Step-by-step guidance for every role in the process.', '#manual'],
                    ['chart', 'Reports', 'Proceedings, promotion lists and signed documents.', route('login')],
                    ['help', 'Help Desk', 'Contact your nodal officer or the Commission.', '#help-desk'],
                ] as [$icon, $title, $text, $href])
                    <div class="col-sm-6 col-lg-4">
                        <a href="{{ $href }}" class="gov-card p-4 h-100">
                            <div class="gov-icon lg mb-3">@include('partials.icon', ['name' => $icon, 'size' => 26])</div>
                            <h3 class="h6 mb-1">{{ $title }}</h3>
                            <p class="small text-body-secondary mb-0">{{ $text }}</p>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- =========================================================== ABOUT --}}
    <section class="py-5 bg-white border-top border-bottom" id="about" aria-labelledby="about-title">
        <div class="container">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6">
                    <div class="section-kicker">About</div>
                    <h2 class="section-title mb-2" id="about-title">What is a Departmental Promotion Committee?</h2>
                    <div class="section-rule mb-3"></div>
                    <p class="text-body-secondary">
                        A Departmental Promotion Committee (DPC) considers eligible officers for promotion against
                        available vacancies. The department prepares the proposal — post, committee, working officers,
                        vacancies, seniority list and the evaluation chart — and forwards it to the Commission. The
                        Commission allots and schedules the sitting, conducts it, and approves the result.
                    </p>
                    <p class="text-body-secondary mb-0">
                        The portal conducts that entire process online. Marks are calculated by a fixed, versioned
                        engine from the confidential reports on record; every correction is recorded beside the
                        original rather than over it; and every legally significant action is digitally signed and
                        permanently auditable.
                    </p>
                </div>
                <div class="col-lg-6">
                    <div class="row g-3">
                        @foreach ([
                            ['flow-check', 'Fresh DPC', 'A new promotion proposal, from the department to the Commission.'],
                            ['refresh', 'Review DPC', 'Corrects an error found in a concluded DPC, without touching the original.'],
                            ['circulate', 'Circulation DPC', 'Resolves a promotion the Commission reserved once the missing record arrives.'],
                            ['publish', 'Publication', 'Signed proceedings and promotion lists released to the department.'],
                        ] as [$icon, $title, $text])
                            <div class="col-sm-6">
                                <div class="gov-card p-3 h-100">
                                    <div class="gov-icon mb-2">@include('partials.icon', ['name' => $icon, 'size' => 22])</div>
                                    <h3 class="h6 mb-1">{{ $title }}</h3>
                                    <p class="small text-body-secondary mb-0">{{ $text }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ==================================================== PROCESS FLOW --}}
    <section class="py-5" id="process" aria-labelledby="process-title">
        <div class="container">
            <div class="text-center mb-4">
                <div class="section-kicker">How it works</div>
                <h2 class="section-title mb-2" id="process-title">Process Flow</h2>
                <div class="section-rule mx-auto"></div>
                <p class="text-body-secondary mt-3 mb-0">From registration to published result — ten stages.</p>
            </div>

            @php
                $flow = [
                    ['Registration', 'A department registers and verifies its officers.'],
                    ['Super Admin Approval', 'The Commission approves the department and issues credentials.'],
                    ['Department Login', 'The Authorized Officer signs in to prepare the proposal.'],
                    ['Create DPC', 'Post, committee, working officers, vacancies, seniority, evaluation chart.'],
                    ['Eligibility', 'Marks, gates, zone of consideration and Super Seat, by the versioned engine.'],
                    ['Proceeding', 'The Commission conducts the sitting, corrects the chart and calculates.'],
                    ['Review DPC', 'An error found later is corrected by a Review that supersedes the original.'],
                    ['Circulation', 'A reserved promotion is released once the missing record is supplied.'],
                    ['Publication', 'Signed proceedings and promotion lists are released to the department.'],
                    ['Reports', 'Documents, history and the audit trail remain available and verifiable.'],
                ];
            @endphp

            <ol class="flow row g-3">
                @foreach ($flow as $i => [$title, $text])
                    <li class="col-md-6 col-xl-4 d-flex align-items-stretch">
                        <div class="flow-step w-100">
                            <span class="step-no">{{ $i + 1 }}</span>
                            <h3>{{ $title }}</h3>
                            <p>{{ $text }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ================================================ SYSTEM FEATURES --}}
    <section class="py-5 bg-white border-top border-bottom" aria-labelledby="features-title">
        <div class="container">
            <div class="text-center mb-4">
                <div class="section-kicker">Capabilities</div>
                <h2 class="section-title mb-2" id="features-title">System Features</h2>
                <div class="section-rule mx-auto"></div>
            </div>

            <div class="row g-3 g-lg-4">
                @foreach ([
                    ['workflow', 'Digital Workflow', 'Every stage moves through a defined workflow with a recorded actor, time and reason.'],
                    ['audit', 'Audit Trail', 'A tamper-evident record of who did what, when — designed for scrutiny in CAT or the High Court.'],
                    ['signature', 'Digital Signature', 'Legally significant actions are digitally signed; documents carry a verifiable content hash.'],
                    ['refresh', 'Review DPC', 'A concluded DPC can be corrected without altering the original record.'],
                    ['circulate', 'Circulation DPC', 'Reserved promotions are released on evidence, with the Commission\'s consent.'],
                    ['lock', 'Role Based Security', 'Every screen and action is gated by role, department and workflow state.'],
                    ['transparency', 'Transparency', 'Results explain themselves — each officer\'s marks are traceable to the rules applied.'],
                    ['bell', 'Notifications', 'Departments and Commission members are told when something needs their action.'],
                    ['chart', 'Reports', 'Proceedings, promotion lists and document history, downloadable and verifiable.'],
                ] as [$icon, $title, $text])
                    <div class="col-sm-6 col-lg-4">
                        <div class="gov-card p-4 h-100">
                            <div class="gov-icon lg mb-3">@include('partials.icon', ['name' => $icon, 'size' => 26])</div>
                            <h3 class="h6 mb-1">{{ $title }}</h3>
                            <p class="small text-body-secondary mb-0">{{ $text }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ====================================================== USER ROLES --}}
    <section class="py-5" id="roles" aria-labelledby="roles-title">
        <div class="container">
            <div class="text-center mb-4">
                <div class="section-kicker">Who uses it</div>
                <h2 class="section-title mb-2" id="roles-title">User Roles</h2>
                <div class="section-rule mx-auto"></div>
            </div>

            <div class="row g-3 g-lg-4">
                @foreach ([
                    ['admin', 'Super Admin', 1, 'Runs the portal for the Commission.', [
                        'Approves department registrations and issues credentials',
                        'Maintains master data — cadres, categories, roster, rules',
                        'Manages users and sees integration status',
                    ]],
                    ['building', 'Department Administrator', 1, 'Prepares and submits the department\'s proposal.', [
                        'Authorized Officer creates the DPC proposal and chart',
                        'Administrative Head reviews and approves with a signature',
                        'Raises Review and Circulation proposals when needed',
                    ]],
                    ['roles', 'Chairman', 2, 'Accountable for the Commission\'s work on each DPC.', [
                        'Allots a forwarded DPC to a Member, and may re-allot it',
                        'Oversees every proceeding',
                        'Conducts a sitting only after re-allotting it to himself',
                    ]],
                    ['users', 'Member', 2, 'Conducts the sitting for the DPCs allotted to him.', [
                        'Accepts the allotment and schedules the sitting',
                        'Corrects the chart, calculates and approves the result',
                        'Signs circulation notes releasing reserved promotions',
                    ]],
                    ['shield', 'Commission', 3, 'The institutional authority the process runs under.', [
                        'Approves results by CGPSC',
                        'Consents to circulation of reserved promotions',
                        'Publishes signed proceedings and promotion lists',
                    ]],
                    ['eye', 'Viewer', 3, 'Reads without acting.', [
                        'Officers inspect the data held about them',
                        'Reads published documents and verifies them',
                        'No ability to change any record',
                    ]],
                ] as [$icon, $title, $tone, $purpose, $duties])
                    <div class="col-md-6 col-xl-4">
                        <div class="gov-card role-card {{ $tone > 1 ? 'tone-'.$tone : '' }} p-4 h-100">
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="gov-icon">@include('partials.icon', ['name' => $icon, 'size' => 22])</div>
                                <h3 class="h6 mb-0">{{ $title }}</h3>
                            </div>
                            <p class="small mb-1"><strong>Purpose.</strong> {{ $purpose }}</p>
                            <p class="small text-body-secondary mb-1"><strong>Responsibilities</strong></p>
                            <ul>
                                @foreach ($duties as $duty)
                                    <li>{{ $duty }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ====================================================== STATISTICS --}}
    <section class="py-5 bg-white border-top border-bottom" aria-labelledby="stats-title">
        <div class="container">
            <div class="text-center mb-4">
                <div class="section-kicker">At a glance</div>
                <h2 class="section-title mb-2" id="stats-title">Portal Statistics</h2>
                <div class="section-rule mx-auto"></div>
            </div>

            @php $s = $portalStats ?? []; @endphp
            <div class="row g-3">
                @foreach ([
                    ['Departments', $s['departments'] ?? 0],
                    ['Users', $s['users'] ?? 0],
                    ['DPCs', $s['dpcs'] ?? 0],
                    ['Review DPCs', $s['reviews'] ?? 0],
                    ['Circulations', $s['circulations'] ?? 0],
                    ['Documents', $s['documents'] ?? 0],
                ] as [$label, $value])
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="stat-tile">
                            {{-- The final value is rendered server-side, so the
                                 number is correct with JavaScript disabled and
                                 the counter merely animates to where it already is. --}}
                            <div class="stat-value" data-count-to="{{ $value }}">{{ number_format($value) }}</div>
                            <div class="stat-label">{{ $label }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="text-center small text-body-secondary mt-3 mb-0">Live figures from this installation.</p>
        </div>
    </section>

    {{-- ==================================== NOTICES · MANUAL · FAQ · CONTACT --}}
    <section class="py-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="gov-card p-4 h-100">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <div class="gov-icon">@include('partials.icon', ['name' => 'inbox', 'size' => 22])</div>
                            <h2 class="section-title h5 mb-0">Notice Board</h2>
                        </div>

                        <ul class="notice-list">
                            @foreach ([
                                ['16', 'Aug', 'Circulation DPC is now available', 'Departments can resolve a reserved promotion online once the missing CR or immovable-property record is supplied.'],
                                ['14', 'Aug', 'Review DPC available to all departments', 'A concluded DPC can be corrected by a Review that references the original and states its grounds.'],
                                ['13', 'Aug', 'Digital signature required at every approval', 'Approvals now carry an OTP step-up recorded in the audit trail as evidence of intent.'],
                                ['30', 'Jul', 'Departments to verify nodal officer details', 'Departments should confirm the mobile number and e-mail of their Authorized Officer.'],
                            ] as [$d, $m, $title, $text])
                                <li>
                                    <span class="notice-date"><span class="d">{{ $d }}</span><span class="m">{{ $m }}</span></span>
                                    <span>
                                        <strong class="d-block">{{ $title }}</strong>
                                        <span class="small text-body-secondary">{{ $text }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="gov-card p-4 mb-4" id="manual">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="gov-icon">@include('partials.icon', ['name' => 'book', 'size' => 22])</div>
                            <h2 class="section-title h5 mb-0">User Manual</h2>
                        </div>
                        <p class="small text-body-secondary">
                            Role-wise guidance covering registration, proposal preparation, the sitting, Review and
                            Circulation. Issued by the Commission to each department's nodal officer.
                        </p>
                        <a href="#help-desk" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-2">
                            Request a copy @include('partials.icon', ['name' => 'arrow-right', 'size' => 16])
                        </a>
                    </div>

                    <div class="gov-card p-4" id="contact">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="gov-icon">@include('partials.icon', ['name' => 'phone', 'size' => 22])</div>
                            <h2 class="section-title h5 mb-0">Contact</h2>
                        </div>
                        <p class="small text-body-secondary mb-2">
                            Chhattisgarh Public Service Commission<br>
                            Shankar Nagar Road, Raipur, Chhattisgarh
                        </p>
                        <p class="small text-body-secondary mb-0">
                            Departments should raise queries through their own nodal officer, who is the
                            Commission's point of contact.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================================= FAQ --}}
    <section class="py-5 bg-white border-top" id="faq" aria-labelledby="faq-title">
        <div class="container">
            <div class="text-center mb-4">
                <div class="section-kicker">Questions</div>
                <h2 class="section-title mb-2" id="faq-title">Frequently Asked Questions</h2>
                <div class="section-rule mx-auto"></div>
            </div>

            <div class="row justify-content-center">
                <div class="col-lg-9">
                    <div class="accordion" id="faqAccordion">
                        @foreach ([
                            ['Who can register on this portal?', 'A government department that submits promotion proposals to the Commission. The department registers, and the Commission approves the registration and issues credentials to the Authorized Officer and the Administrative Head.'],
                            ['How are promotion marks calculated?', 'From the confidential reports on record, by a fixed engine using the published rules — grade marks, the tribal-area bonus, the qualifying score for the class pair, the disqualifying-grade gate and the last-year gate. The rules come from configuration and government notification, never from ad-hoc changes to the software.'],
                            ['What happens if an error is found after a DPC has concluded?', 'The department raises a Review DPC, stating the grounds. The Review references the original, is decided under the rules that applied at the time, and supersedes the original when approved. The original record is never altered.'],
                            ['Why is a promotion sometimes reserved?', 'If an officer\'s confidential reports are missing for two or more years, or the immovable-property return is not recorded, the officer takes part in the DPC but the promotion action is reserved. When the missing record is supplied, a Circulation note obtains the Commission\'s consent for the promotion to proceed.'],
                            ['Are the documents legally reliable?', 'Every generated document is stored unchanged, carries a SHA-256 content hash, and is digitally signed. A department\'s copy can be verified against the hash the Commission issued, and superseded documents remain available and are watermarked.'],
                            ['Is the process auditable?', 'Yes. Every workflow transition is recorded with the actor, the time and the reason, and the activity timeline can reconstruct the history of a DPC without reading a single business table.'],
                        ] as $i => [$q, $a])
                            <div class="accordion-item border-0 mb-2 gov-card">
                                <h3 class="accordion-header">
                                    <button class="accordion-button {{ $i === 0 ? '' : 'collapsed' }} fw-semibold"
                                            type="button" data-bs-toggle="collapse"
                                            data-bs-target="#faq{{ $i }}"
                                            aria-expanded="{{ $i === 0 ? 'true' : 'false' }}"
                                            aria-controls="faq{{ $i }}">
                                        {{ $q }}
                                    </button>
                                </h3>
                                <div id="faq{{ $i }}" class="accordion-collapse collapse {{ $i === 0 ? 'show' : '' }}"
                                     data-bs-parent="#faqAccordion">
                                    <div class="accordion-body text-body-secondary">{{ $a }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================================== CTA --}}
    <section class="gov-hero py-5">
        <div class="container position-relative text-center" style="z-index:1">
            <h2 class="h3 text-white mb-2">Ready to begin?</h2>
            <p class="lead mb-4">Register your department, or sign in to continue an existing proposal.</p>
            <div class="d-flex flex-wrap gap-3 justify-content-center">
                <a href="{{ route('register') }}" class="btn btn-accent btn-lg">New Registration</a>
                <a href="{{ route('login') }}" class="btn btn-outline-light btn-lg">Department Login</a>
            </div>
        </div>
    </section>

@endsection
