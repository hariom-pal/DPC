@extends('layouts.app')

@section('title', __('ui.screen.pre_dpc_proposal', ['number' => $proposal->pre_dpc_number ?? __('ui.screen.proposal')]))

{{-- Actions are gated by `@can`, which asks PreDpcProposalPolicy — the same
     class the controller authorises against (TD-26, closed 2026-08-18). This
     screen used to re-derive the rules from the actor's role and the proposal's
     status, which meant every rule was stated twice, in two idioms, and could
     drift apart one edit at a time. --}}

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')
            @include('partials.esign-issue', ['proposal' => $proposal])

            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h1 class="h4 mb-0">{{ $proposal->pre_dpc_number ?? 'Pre-DPC Proposal' }}</h1>
                    <div class="text-muted small">{{ $proposal->exerciseLabel() }} — {{ __('ui.version_n', ['number' => $proposal->version]) }}</div>
                </div>
                <div class="text-end">
                    <span class="badge {{ $proposal->status->badgeClass() }} fs-6">{{ $proposal->status->label() }}</span>
                    @if ($proposal->isActive())
                        <div><span class="badge text-bg-primary mt-1">{{ __('predpc.show.active_for_this_exercise') }}</span></div>
                    @endif
                </div>
            </div>

            @if ($proposal->status->value === 'returned' && $proposal->return_remarks)
                <div class="alert alert-warning"><strong>{{ __('ui.returned') }}</strong> {{ $proposal->return_remarks }}</div>
            @endif

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">{{ __('ui.dpc_year') }}</dt><dd class="col-sm-9">{{ $proposal->dpc_year ?? '—' }}</dd>
                        {{-- **The post, with its own Class and Pay Scale on the same
                             line** (business instruction, 2026-09-06).

                             They were on the Sanctioned Terms card below and nowhere
                             else, which put a class and a scale on screen that
                             belonged to the *feeder* posts while this line named the
                             promotion post — two different posts, three lines apart,
                             with nothing saying so. --}}
                        <dt class="col-sm-3">{{ __('ui.promotion_to_post') }}</dt>
                        <dd class="col-sm-9">
                            {{ $proposal->promotionToPost?->name ?? '—' }}
                            @if ($proposal->class_level || $proposal->payScale)
                                <span class="text-body-secondary small d-block">
                                    {{ __('ui.class') }}: <strong>{{ $proposal->class_level?->label() ?? '—' }}</strong>
                                    &nbsp;·&nbsp;
                                    {{ __('ui.pay_scale') }}: <strong>{{ $proposal->payScale?->label ?? '—' }}</strong>
                                </span>
                            @endif
                        </dd>
                        <dt class="col-sm-3">{{ __('predpc.show.government_order_no') }}</dt><dd class="col-sm-9">{{ $proposal->government_order_number ?? '—' }}</dd>
                        <dt class="col-sm-3">{{ __('predpc.show.publish_date') }}</dt><dd class="col-sm-9">{{ $proposal->effective_from?->format('d-m-Y') ?? '—' }}</dd>
                        <dt class="col-sm-3">{{ __('predpc.form.rules_document') }}</dt>
                        <dd class="col-sm-9">
                            @if ($proposal->hasRulesDocument())
                                <a href="{{ route('pre-dpc.rules-document', $proposal) }}">{{ $proposal->rules_document_name }}</a>
                            @else
                                —
                            @endif
                        </dd>
                        @if ($proposal->parentVersion)
                            <dt class="col-sm-3">{{ __('predpc.show.amends') }}</dt>
                            <dd class="col-sm-9">
                                <a href="{{ route('pre-dpc.show', $proposal->parentVersion) }}">{{ $proposal->parentVersion->pre_dpc_number }}</a>
                                {{ __('predpc.show.version_of_parent', ['number' => $proposal->parentVersion->version]) }}
                            </dd>
                        @endif
                        @if ($proposal->approved_at)
                            <dt class="col-sm-3">{{ __('ui.approved') }}</dt><dd class="col-sm-9">{{ __('predpc.show.approved_by', [
                                'at' => $proposal->approved_at->format('d-m-Y H:i'),
                                'actor' => $proposal->approver?->name,
                                'id' => $proposal->signature_id,
                            ]) }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">{{ __('predpc.show.sanctioned_terms') }}</h2>
                    {{-- Taken from the posts, never re-entered (change item 5).
                         Stored on the proposal rather than read live, because an
                         approved Pre-DPC is a Government Order: it sanctioned
                         *these* terms, and a later edit to the Department Post
                         master must not silently restate what it said. --}}
                    <dl class="row mb-0">
                        {{-- **The Promotion From Posts' figures, not the promotion
                             post's** (change register of 2026-08-26, item 3).

                             Promotion eligibility is judged on the officer's current
                             post, so these are the figures a reader checks a
                             candidate against. Each feeder has its own, and the
                             table below states them per feeder; this line is the
                             summary, so a reader who scans only the terms card is
                             not shown the scale of the post nobody has been
                             promoted into yet.

                             The order still *stores* the promotion post's scale and
                             class, and other things read them — the correction here
                             is what the screen presents, which is what the register
                             asked for. --}}
                        {{-- **The Promotion To Post's own class and pay scale**
                             (business instruction, 2026-09-06).

                             They were deliberately left off this card by the change
                             register of 2026-08-26, which put the feeders' figures
                             here instead because eligibility is judged on the
                             officer's *current* post. Business asked for both: a
                             reader also needs to see what the exercise promotes
                             *into*, and without it the card states a class and a
                             scale that belong to somebody's old post while naming
                             the new one two lines above.

                             Read from the proposal, not from the master, for the
                             reason the card's own note gives: an approved Pre-DPC is
                             a Government Order, and it sanctioned *these* terms. --}}
                        <dt class="col-sm-4 fw-normal">{{ __('predpc.show.class_of_promotion_post') }}</dt>
                        <dd class="col-sm-8">{{ $proposal->class_level?->label() ?? '—' }}</dd>
                        <dt class="col-sm-4 fw-normal">{{ __('predpc.show.pay_scale_of_promotion_post') }}</dt>
                        <dd class="col-sm-8">{{ $proposal->payScale?->label ?? '—' }}</dd>

                        <dt class="col-sm-4 fw-normal">{{ __('predpc.show.class_of_feeder_posts') }}</dt>
                        <dd class="col-sm-8">
                            {{ $proposal->promotionSources
                                ->map(fn ($source) => $source->promotionFromPost?->class_level?->label())
                                ->filter()->unique()->implode(', ') ?: '—' }}
                        </dd>
                        <dt class="col-sm-4 fw-normal">{{ __('predpc.show.pay_scale_of_feeder_posts') }}</dt>
                        <dd class="col-sm-8">
                            {{ $proposal->promotionSources
                                ->map(fn ($source) => $source->promotionFromPost?->payScale?->label)
                                ->filter()->unique()->implode(', ') ?: '—' }}
                        </dd>
                        <dt class="col-sm-4 fw-normal">{{ __('predpc.show.recruitment_split') }}</dt>
                        <dd class="col-sm-8">{{ __('predpc.show.split_value', [
                            'direct' => $proposal->direct_recruitment_pct,
                            'promotion' => $proposal->promotion_recruitment_pct,
                        ]) }}</dd>
                    </dl>
                </div>
            </div>

            {{-- The posts officers may be promoted from.

                 There is usually more than one — Schedule-IV fills most posts from
                 several feeders with a share each — and each carries its own Pay
                 Scale, Class and qualifying service, because eligibility is judged
                 on the officer's current post. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">{{ __('predpc.show.promotion_from_posts') }}</h2>
                    <p class="small text-body-secondary mb-2">{{ __('predpc.show.promotion_from_posts_lead') }}</p>

                    @if ($proposal->promotionSources->isEmpty())
                        <p class="small text-body-secondary mb-0">{{ __('predpc.show.no_sources_recorded') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('ui.promotion_from_post') }}</th>
                                        <th>{{ __('ui.pay_scale') }}</th>
                                        <th>{{ __('ui.class') }}</th>
                                        <th class="text-end">{{ __('predpc.form.share') }}</th>
                                        <th>{{ __('predpc.show.minimum_experience') }}</th>
                                        <th>{{ __('ui.promotion_duration') }}</th>
                                        <th>{{ __('ui.minimum_qualification') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($proposal->promotionSources as $source)
                                        <tr>
                                            <td>{{ $source->promotionFromPost?->name ?? '—' }}</td>
                                            {{-- This feeder's own scale and class, read
                                                 from its own Department Post Mapping. --}}
                                            <td>{{ $source->promotionFromPost?->payScale?->label ?? '—' }}</td>
                                            <td>{{ $source->promotionFromPost?->class_level?->label() ?? '—' }}</td>
                                            <td class="text-end">{{ rtrim(rtrim(number_format((float) $source->promotion_percentage, 2), '0'), '.') }}%</td>
                                            <td>{{ $source->experienceLabel() ?? __('ui.none_prescribed') }}</td>
                                            <td>{{ $source->promotion_duration_years === null ? '—' : __('ui.n_years', ['count' => $source->promotion_duration_years]) }}</td>
                                            <td>{{ $source->qualificationLabel() ?? __('ui.none_prescribed') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Workflow actions --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body d-flex flex-wrap gap-2">
                    @can('update', $proposal)
                        <a href="{{ route('pre-dpc.edit', $proposal) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                    @endcan
                    @can('submit', $proposal)
                        {{-- The AO's declaration, made here and carried to the eSign screen
                             (MoM of 2026-10-29; shown on this page since 2026-10-30). --}}
                        <form method="GET" action="{{ route('esign.show', ['kind' => 'pre-dpc-submit', 'uuid' => $proposal->uuid]) }}" class="w-100 order-first" id="pre-dpc-declaration">
                            @include('partials.declaration', ['key' => 'pre_dpc_submit'])
                            <button class="btn btn-success btn-sm mt-2" data-esign>{{ __('esign.button.submit') }}</button>
                        </form>
                    @endcan
                    @can('update', $proposal)
                        <form method="POST" action="{{ route('pre-dpc.cancel', $proposal) }}" data-confirm="{{ __('ui.cancel_this_proposal') }}">@csrf<button class="btn btn-outline-danger btn-sm">{{ __('ui.cancel') }}</button></form>
                    @endcan

                    {{-- Draft only (business instruction, 2026-09-06). The policy
                         decides, so the button appears on exactly the proposals the
                         route would accept — a returned or approved one is cancelled,
                         not deleted, because somebody has already seen it.

                         The deletion is soft: the row keeps the Pre-DPC Number it
                         drew from the department's sequence. --}}
                    @can('delete', $proposal)
                        <form method="POST" action="{{ route('pre-dpc.destroy', $proposal) }}"
                              data-confirm="{{ __('predpc.show.delete_draft_confirm') }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-outline-danger btn-sm">{{ __('predpc.show.delete_draft') }}</button>
                        </form>
                    @endcan

                    @can('approve', $proposal)
                        <a href="{{ route('esign.show', ['kind' => 'pre-dpc-approve', 'uuid' => $proposal->uuid]) }}" class="btn btn-success btn-sm" data-esign>{{ __('esign.button.approve') }}</a>
                    @endcan

                    @can('amend', $proposal)
                        <form method="POST" action="{{ route('pre-dpc.amend', $proposal) }}">@csrf<button class="btn btn-outline-secondary btn-sm">{{ __('predpc.show.amend_new_version') }}</button></form>
                    @endcan

                    {{-- After approval: the post's Vacancy Management for this Pre-DPC's year (2026-10-30). --}}
                    @if ($proposal->status->value === 'approved' && $proposal->isActive() && $proposal->promotionToPost
                        && auth()->user()?->department_id === $proposal->department_id
                        && auth()->user()?->hasAnyRole([\App\Enums\Role::AuthorizedOfficer->value, \App\Enums\Role::AdministrativeHead->value]))
                        <form method="POST" action="{{ route('vacancy-management.pre-dpc', $proposal) }}" id="pre-dpc-vacancy">
                            @csrf
                            <button class="btn btn-primary btn-sm">{{ __('vacancymaster.pm.proceed', ['post' => $proposal->promotionToPost->name]) }}</button>
                        </form>
                    @endif

                    @if (! $proposal->status->isEditable() && $proposal->status->value !== 'submitted' && $proposal->status->value !== 'approved')
                        <span class="text-muted small align-self-center">{{ __('predpc.show.no_actions_available_in_this_state') }}</span>
                    @endif
                </div>

                @can('return', $proposal)
                    <div class="card-body border-top">
                        <form method="POST" action="{{ route('pre-dpc.return', $proposal) }}" class="row g-2">
                            @csrf
                            <div class="col-md-9">
                                <input type="text" class="form-control form-control-sm @error('return_remarks') is-invalid @enderror" name="return_remarks" placeholder="{{ __('ui.remarks_required_to_return') }}">
                                @error('return_remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-3"><button class="btn btn-outline-warning btn-sm w-100">{{ __('ui.return_for_correction') }}</button></div>
                        </form>
                    </div>
                @endcan
            </div>

            {{-- Transition history --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">{{ __('ui.workflow_history') }}</h2>
                    <ul class="list-group list-group-flush">
                        @forelse ($transitions as $t)
                            <li class="list-group-item px-0">
                                <span class="badge text-bg-light">{{ $t->event }}</span>
                                {{ $t->from_state }} → {{ $t->to_state }}
                                <span class="text-muted small">{{ __('ui.by_actor_on', ['actor' => $t->actor?->name, 'at' => $t->occurred_at->format('d-m-Y H:i')]) }}</span>
                                @if ($t->signature_id)<span class="badge text-bg-success">{{ __('ui.signed_hash', ['id' => $t->signature_id]) }}</span>@endif
                                @if ($t->remarks)<div class="small text-muted">{{ $t->remarks }}</div>@endif
                            </li>
                        @empty
                            <li class="list-group-item px-0 text-muted">{{ __('ui.no_transitions_yet') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <a href="{{ route('pre-dpc.index') }}" class="btn btn-link mt-2">{{ __('ui.back_to_list') }}</a>
        </div>
    </div>
@endsection
