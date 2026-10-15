{{--
    The public landing page.

    Every string comes from `lang/{en,hi}/landing.php` (change item 7). The
    repeated blocks iterate over the language file itself rather than over a
    literal array declared here, which is the point: a list of cards written in
    the template is a list that exists only in English, and that is exactly how
    this page came to name a "Department Administrator" long after that role was
    removed. Iterating the catalogue means a card cannot exist untranslated.

    `__('landing.…')` returns the nested array for a group key, so each loop is
    over `(array) __(...)` and reads its captions by name — never by position.
--}}
@extends('layouts.portal')

@section('title', __('landing.title'))

@section('content')

    {{-- ============================================================ HERO --}}
    <section class="gov-hero py-5">
        <div class="container position-relative py-lg-4" style="z-index:1">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <span class="badge text-bg-light text-uppercase mb-3" style="letter-spacing:.08em">
                        {{ __('landing.hero.kicker') }}
                    </span>
                    <h1 class="mb-3">
                        {{ __('landing.hero.heading') }}
                        <span class="d-block mt-2" style="font-size:.62em;font-weight:500;opacity:.92">
                            {{ __('landing.hero.subheading') }}
                        </span>
                    </h1>
                    <p class="lead mb-4">
                        {{ __('landing.hero.lead') }}
                    </p>

                    {{-- Signed in, the two calls to action are the wrong ones: an
                         officer with a session does not need to register their
                         department again, and "Department Login" is a dead end that
                         bounces them straight back here. They get their dashboard
                         instead (reported 2026-09-06). --}}
                    <div class="d-flex flex-wrap gap-3 mb-4">
                        @auth
                            <a href="{{ route('dashboard') }}" class="btn btn-accent btn-lg d-inline-flex align-items-center gap-2">
                                @include('partials.icon', ['name' => 'login', 'size' => 20])
                                {{ __('ui.dashboard_link') }}
                            </a>
                            <form method="POST" action="{{ route('logout') }}" class="m-0" data-no-busy>
                                @csrf
                                <button type="submit" class="btn btn-outline-light btn-lg">{{ __('ui.logout') }}</button>
                            </form>
                        @else
                            <a href="{{ route('register') }}" class="btn btn-accent btn-lg d-inline-flex align-items-center gap-2">
                                @include('partials.icon', ['name' => 'user-plus', 'size' => 20])
                                {{ __('ui.new_registration') }}
                            </a>
                            <a href="{{ route('login') }}" class="btn btn-outline-light btn-lg d-inline-flex align-items-center gap-2">
                                @include('partials.icon', ['name' => 'login', 'size' => 20])
                                {{ __('ui.department_login') }}
                            </a>
                        @endauth
                    </div>

                    @php
                        $heroIcons = [
                            'secure' => 'shield', 'paperless' => 'paperless', 'audit' => 'audit',
                            'signature' => 'signature', 'workflow' => 'workflow', 'roles' => 'roles',
                        ];
                    @endphp

                    <div class="row g-2">
                        @foreach ((array) __('landing.hero.features') as $key => $label)
                            <div class="col-6 col-md-4">
                                <div class="glass hero-feature">
                                    @include('partials.icon', ['name' => $heroIcons[$key], 'size' => 18])
                                    <span>{{ $label }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="col-lg-6 hero-art">
                    <img src="{{ asset('images/hero-building.svg') }}"
                         alt="{{ __('landing.hero.art_alt') }}" loading="lazy">
                </div>
            </div>
        </div>
    </section>

    {{-- =================================================== QUICK ACTIONS --}}
    <section class="py-5" aria-labelledby="quick-actions-title">
        <div class="container">
            <div class="text-center mb-4">
                <div class="section-kicker">{{ __('landing.sections.get_started') }}</div>
                <h2 class="section-title mb-2" id="quick-actions-title">{{ __('landing.sections.quick_actions') }}</h2>
                <div class="section-rule mx-auto"></div>
            </div>

            @php
                // Icon and destination per action. Deliberately here and not in
                // the language file: a route is not a translatable string, and a
                // translator editing one would break the page.
                //
                // S49. Self-Inspection is the officer's only way in — they have
                // no account — so a screen nobody can reach is a screen that was
                // not delivered.
                $quickActions = [
                    'register' => ['user-plus', route('register')],
                    'department' => ['login', route('login')],
                    'administrator' => ['admin', route('login')],
                    'self_inspection' => ['eye', route('self-inspection.landing')],
                    'manual' => ['book', '#manual'],
                    'reports' => ['chart', auth()->check() ? route('dashboard') : route('login')],
                    'help_desk' => ['help', '#help-desk'],
                ];

                /* Three of these are dead ends for somebody who already has a
                   session: registering a department that is registered, and two
                   logins that bounce straight back here. Hidden rather than
                   re-pointed, because "New Registration" retitled to something else
                   is a different tile, and the hero above already carries the one
                   destination a signed-in officer wants (2026-09-06). */
                $signedOutOnly = ['register', 'department', 'administrator'];
            @endphp

            <div class="row g-3 g-lg-4">
                @foreach ((array) __('landing.quick_actions') as $key => $action)
                    @continue(auth()->check() && in_array($key, $signedOutOnly, true))
                    <div class="col-sm-6 col-lg-4">
                        <a href="{{ $quickActions[$key][1] }}" class="gov-card p-4 h-100">
                            <div class="gov-icon lg mb-3">@include('partials.icon', ['name' => $quickActions[$key][0], 'size' => 26])</div>
                            <h3 class="h6 mb-1">{{ $action['title'] }}</h3>
                            <p class="small text-body-secondary mb-0">{{ $action['text'] }}</p>
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
                    <div class="section-kicker">{{ __('landing.sections.about') }}</div>
                    <h2 class="section-title mb-2" id="about-title">{{ __('landing.sections.about_heading') }}</h2>
                    <div class="section-rule mb-3"></div>
                    <p class="text-body-secondary">
                        {{ __('landing.about.lead_1') }}
                    </p>
                    <p class="text-body-secondary mb-0">
                        {{ __('landing.about.lead_2') }}
                    </p>
                </div>
                <div class="col-lg-6">
                    @php $kindIcons = ['fresh' => 'flow-check', 'review' => 'refresh', 'circulation' => 'circulate', 'publication' => 'publish']; @endphp
                    <div class="row g-3">
                        @foreach ((array) __('landing.about.kinds') as $key => $kind)
                            <div class="col-sm-6">
                                <div class="gov-card p-3 h-100">
                                    <div class="gov-icon mb-2">@include('partials.icon', ['name' => $kindIcons[$key], 'size' => 22])</div>
                                    <h3 class="h6 mb-1">{{ $kind['title'] }}</h3>
                                    <p class="small text-body-secondary mb-0">{{ $kind['text'] }}</p>
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
                <div class="section-kicker">{{ __('landing.sections.how_it_works') }}</div>
                <h2 class="section-title mb-2" id="process-title">{{ __('ui.process_flow') }}</h2>
                <div class="section-rule mx-auto"></div>
                <p class="text-body-secondary mt-3 mb-0">{{ __('landing.sections.process_lead') }}</p>
            </div>

            <ol class="flow row g-3">
                @foreach (array_values((array) __('landing.process')) as $i => $stage)
                    <li class="col-md-6 col-xl-4 d-flex align-items-stretch">
                        <div class="flow-step w-100">
                            <span class="step-no">{{ $i + 1 }}</span>
                            <h3>{{ $stage['title'] }}</h3>
                            <p>{{ $stage['text'] }}</p>
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
                <div class="section-kicker">{{ __('landing.sections.capabilities') }}</div>
                <h2 class="section-title mb-2" id="features-title">{{ __('landing.sections.system_features') }}</h2>
                <div class="section-rule mx-auto"></div>
            </div>

            @php
                $featureIcons = [
                    'workflow' => 'workflow', 'audit' => 'audit', 'signature' => 'signature',
                    'review' => 'refresh', 'circulation' => 'circulate', 'security' => 'lock',
                    'transparency' => 'transparency', 'notifications' => 'bell', 'reports' => 'chart',
                ];
            @endphp

            <div class="row g-3 g-lg-4">
                @foreach ((array) __('landing.features') as $key => $feature)
                    <div class="col-sm-6 col-lg-4">
                        <div class="gov-card p-4 h-100">
                            <div class="gov-icon lg mb-3">@include('partials.icon', ['name' => $featureIcons[$key], 'size' => 26])</div>
                            <h3 class="h6 mb-1">{{ $feature['title'] }}</h3>
                            <p class="small text-body-secondary mb-0">{{ $feature['text'] }}</p>
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
                <div class="section-kicker">{{ __('landing.sections.who_uses_it') }}</div>
                <h2 class="section-title mb-2" id="roles-title">{{ __('ui.user_roles') }}</h2>
                <div class="section-rule mx-auto"></div>
            </div>

            @php
                // Icon and card tone per role. The tone groups the cards by side
                // of the process — department, Commission, everyone else — and is
                // presentation, so it stays out of the language file.
                $roleStyles = [
                    'administrator' => ['admin', 1],
                    'department' => ['building', 1],
                    'chairman' => ['roles', 2],
                    'member' => ['users', 2],
                    'candidate' => ['eye', 3],
                    'commission' => ['shield', 3],
                ];
            @endphp

            <div class="row g-3 g-lg-4">
                @foreach ((array) __('landing.roles.cards') as $key => $role)
                    @php [$icon, $tone] = $roleStyles[$key]; @endphp
                    <div class="col-md-6 col-xl-4">
                        <div class="gov-card role-card {{ $tone > 1 ? 'tone-'.$tone : '' }} p-4 h-100">
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="gov-icon">@include('partials.icon', ['name' => $icon, 'size' => 22])</div>
                                <h3 class="h6 mb-0">{{ $role['title'] }}</h3>
                            </div>
                            <p class="small mb-1"><strong>{{ __('landing.roles.purpose_label') }}</strong> {{ $role['purpose'] }}</p>
                            <p class="small text-body-secondary mb-1"><strong>{{ __('landing.roles.responsibilities_label') }}</strong></p>
                            <ul>
                                @foreach ($role['duties'] as $duty)
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
                <div class="section-kicker">{{ __('landing.sections.at_a_glance') }}</div>
                <h2 class="section-title mb-2" id="stats-title">{{ __('landing.sections.portal_statistics') }}</h2>
                <div class="section-rule mx-auto"></div>
            </div>

            @php $s = $portalStats ?? []; @endphp
            <div class="row g-3">
                @foreach ((array) __('landing.statistics') as $key => $label)
                    @php $value = $s[$key] ?? 0; @endphp
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="stat-tile">
                            {{-- The final value is rendered server-side, so the
                                 number is correct with JavaScript disabled and
                                 the counter merely animates to where it already is. --}}
                            <div class="stat-value" data-count-to="{{ $value }}">{{ number_format((int) $value) }}</div>
                            <div class="stat-label">{{ $label }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="text-center small text-body-secondary mt-3 mb-0">{{ __('landing.sections.statistics_note') }}</p>
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
                            <h2 class="section-title h5 mb-0">{{ __('landing.notices.heading') }}</h2>
                        </div>

                        <ul class="notice-list">
                            @foreach ($portalNotices as $notice)
                                <li>
                                    <span class="notice-date"><span class="d">{{ $notice['day'] }}</span><span class="m">{{ $notice['month'] }}</span></span>
                                    <span>
                                        <strong class="d-block">{{ $notice['title'] }}</strong>
                                        <span class="small text-body-secondary">{{ $notice['text'] }}</span>
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
                            <h2 class="section-title h5 mb-0">{{ __('ui.user_manual') }}</h2>
                        </div>
                        <p class="small text-body-secondary">
                            {{ __('landing.manual.text') }}
                        </p>
                        <a href="#help-desk" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-2">
                            {{ __('landing.manual.cta') }} @include('partials.icon', ['name' => 'arrow-right', 'size' => 16])
                        </a>
                    </div>

                    <div class="gov-card p-4" id="contact">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="gov-icon">@include('partials.icon', ['name' => 'phone', 'size' => 22])</div>
                            <h2 class="section-title h5 mb-0">{{ __('ui.contact') }}</h2>
                        </div>
                        <p class="small text-body-secondary mb-2">
                            {{ __('landing.contact.organisation') }}<br>
                            {{ __('landing.contact.address') }}
                        </p>
                        <p class="small text-body-secondary mb-0">
                            {{ __('landing.contact.note') }}
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
                <div class="section-kicker">{{ __('landing.sections.questions') }}</div>
                <h2 class="section-title mb-2" id="faq-title">{{ __('landing.sections.faq_heading') }}</h2>
                <div class="section-rule mx-auto"></div>
            </div>

            <div class="row justify-content-center">
                <div class="col-lg-9">
                    <div class="accordion" id="faqAccordion">
                        @foreach (array_values((array) __('landing.faq')) as $i => $entry)
                            <div class="accordion-item border-0 mb-2 gov-card">
                                <h3 class="accordion-header">
                                    <button class="accordion-button {{ $i === 0 ? '' : 'collapsed' }} fw-semibold"
                                            type="button" data-bs-toggle="collapse"
                                            data-bs-target="#faq{{ $i }}"
                                            aria-expanded="{{ $i === 0 ? 'true' : 'false' }}"
                                            aria-controls="faq{{ $i }}">
                                        {{ $entry['q'] }}
                                    </button>
                                </h3>
                                <div id="faq{{ $i }}" class="accordion-collapse collapse {{ $i === 0 ? 'show' : '' }}"
                                     data-bs-parent="#faqAccordion">
                                    <div class="accordion-body text-body-secondary">{{ $entry['a'] }}</div>
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
            <h2 class="h3 text-white mb-2">{{ __('landing.cta.heading') }}</h2>
            <p class="lead mb-4">{{ __('landing.cta.lead') }}</p>
            <div class="d-flex flex-wrap gap-3 justify-content-center">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-accent btn-lg">{{ __('ui.dashboard_link') }}</a>
                @else
                    <a href="{{ route('register') }}" class="btn btn-accent btn-lg">{{ __('ui.new_registration') }}</a>
                    <a href="{{ route('login') }}" class="btn btn-outline-light btn-lg">{{ __('ui.department_login') }}</a>
                @endauth
            </div>
        </div>
    </section>

@endsection
