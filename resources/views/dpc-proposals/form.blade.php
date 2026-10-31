@extends('layouts.app')

@section('title', __($proposal->exists ? 'dpc.form.title_edit' : 'dpc.form.title_new'))

@section('content')
    {{-- Raising a DPC (PPT S14), rewritten 2026-08-24 for change items 11 and 12.

         There is no standalone "New DPC" any more. A DPC exists because an
         approved Pre-DPC sanctioned that promotion exercise, so this screen asks
         which order to raise it under and inherits everything else — Department,
         DPC Year, Promotion From Post, Promotion To Post, Recruitment Rule, Class,
         Pay Scale and Promotion Duration. The Proposal Date is generated (item 12)
         and the DPC Number is issued on save (item 18).

         What is left for the officer is the pair of facts the order does not
         carry: the type of DPC, and whether a legal stay is in force. --}}
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __($proposal->exists ? 'dpc.form.title_edit' : 'dpc.form.title_new') }}</h1>
            @if ($proposal->exists)
                <p class="text-muted small mb-3">
{!! __('dpc.form.raised_from', [
                        'citation' => e($proposal->citation()),
                        'link' => '<a href="'.e(route('pre-dpc.show', $proposal->preDpcProposal)).'">'
                            .e($proposal->preDpcProposal?->pre_dpc_number ?? '—').'</a>',
                    ]) !!}
                </p>
            @else
                <p class="text-muted small mb-3">
                    {{ __('dpc.form.choose_the_approved_pre_dpc_this_promotion_is_sa') }}
                </p>
            @endif

            @if (! $proposal->exists && $preDpcs->isEmpty())
                <div class="alert alert-warning">
{!! __('dpc.form.no_pre_dpc_explained', [
                        'link' => '<a href="'.e(route('pre-dpc.index')).'">'.e(__('ui.pre_dpc_proposals')).'</a>',
                    ]) !!}
                </div>
            @endif

            <form method="POST" action="{{ $proposal->exists ? route('dpc-proposals.update', $proposal) : route('dpc-proposals.store') }}">
                @csrf
                @if ($proposal->exists) @method('PUT') @endif

                @unless ($proposal->exists)
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white"><strong>{{ __('dpc.form.1_sanctioned_by') }}</strong></div>
                        <div class="card-body">
                            {!! __('dpc.form.approved_pre_dpc_label') !!}
                            <select class="form-select @error('pre_dpc_proposal_id') is-invalid @enderror"
                                    id="pre_dpc_proposal_id" name="pre_dpc_proposal_id" required>
                                <option value="">{{ __('ui.select') }}</option>
                                @foreach ($preDpcs as $preDpc)
                                    <option value="{{ $preDpc->id }}" @selected((int) old('pre_dpc_proposal_id') === $preDpc->id)>
                                        {{ $preDpc->pre_dpc_number }} — {{ $preDpc->exerciseLabel() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('pre_dpc_proposal_id')<div class="invalid-feedback">{{ $message }}</div>@enderror

                            {{-- Only orders still in force appear above. A superseded one is
                                 absent because the terms it sanctioned have been replaced. --}}
                            <div class="form-text mb-3">
                                {{ __('dpc.form.hint_pre_dpc_in_force') }}
                            </div>

                            {{-- Which of the order's Promotion From Posts this DPC runs.

                                 An order may sanction promotion from several posts;
                                 a DPC runs **one**, because the seniority list, the
                                 qualifying service and the scoring engine are all per
                                 feeder. Every source of every order is rendered here
                                 and the ones belonging to other orders are hidden as
                                 the order changes — the alternative, fetching them,
                                 would put a second round trip in front of a list the
                                 page already holds. --}}
                            <label class="form-label" for="promotion_from_post_id">{{ __('ui.promotion_from_post') }}</label>
                            <select class="form-select @error('promotion_from_post_id') is-invalid @enderror"
                                    id="promotion_from_post_id" name="promotion_from_post_id"
                                    data-empty-label="{{ __('dpc.form.choose_an_order_first') }}">
                                <option value="">{{ __('dpc.form.choose_an_order_first') }}</option>
                                @foreach ($preDpcs as $preDpc)
                                    @foreach ($preDpc->promotionSources as $source)
                                        <option value="{{ $source->promotion_from_post_id }}"
                                                data-pre-dpc="{{ $preDpc->id }}"
                                                @selected((int) old('promotion_from_post_id') === $source->promotion_from_post_id
                                                    && (int) old('pre_dpc_proposal_id') === $preDpc->id)>
                                            {{ $source->promotionFromPost?->name }}
                                            @if ($source->experienceLabel())
                                                — {{ $source->experienceLabel() }}
                                            @endif
                                        </option>
                                    @endforeach
                                @endforeach
                            </select>
                            @error('promotion_from_post_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">{{ __('dpc.form.hint_promotion_from_post') }}</div>
                        </div>
                    </div>
                @endunless

                @if ($proposal->exists)
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <strong>{{ __('dpc.form.inherited_from_the_pre_dpc') }}</strong>
                            <span class="badge text-bg-secondary">{{ __('dpc.form.not_editable_here') }}</span>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-4 fw-normal">{{ __('ui.dpc_year') }}</dt>
                                <dd class="col-sm-8">{{ $proposal->dpc_year ?? '—' }}</dd>
                                <dt class="col-sm-4 fw-normal">{{ __('ui.promotion_from_post') }}</dt>
                                <dd class="col-sm-8">{{ $proposal->promotionFromPost?->name ?? '—' }}</dd>
                                <dt class="col-sm-4 fw-normal">{{ __('ui.promotion_to_post') }}</dt>
                                <dd class="col-sm-8">{{ $proposal->promotionToPost?->name ?? '—' }}</dd>
                                {{-- The Pay Scale and Class of the **Promotion From
                                     Post** (change register of 2026-08-26, item 3).

                                     These rows used to show `$proposal->class_level`
                                     and `$proposal->payScale`, which are the post
                                     being *filled* — so an unlabelled "Pay Scale"
                                     here described a post nobody had been promoted
                                     into yet. Promotion eligibility is judged on the
                                     officer's current post, so the feeder's own
                                     figures are the ones a reader needs, and they
                                     are read from that post's own Department Post
                                     Mapping. --}}
                                <dt class="col-sm-4 fw-normal">{{ __('ui.class') }}</dt>
                                <dd class="col-sm-8">{{ $proposal->promotionFromPost?->class_level?->label() ?? '—' }}</dd>
                                <dt class="col-sm-4 fw-normal">{{ __('ui.pay_scale') }}</dt>
                                <dd class="col-sm-8">{{ $proposal->promotionFromPost?->payScale?->label ?? '—' }}</dd>
                                <dt class="col-sm-4 fw-normal">{{ __('ui.promotion_duration') }}</dt>
                                <dd class="col-sm-8">{{ $proposal->promotion_duration_years === null ? '—' : $proposal->promotion_duration_years.' year(s)' }}</dd>
                                <dt class="col-sm-4 fw-normal">{{ __('dpc.form.recruitment_rule') }}</dt>
                                <dd class="col-sm-8">
                                    @if ($proposal->recruitmentRule)
                                        {{ $proposal->recruitmentRule->government_order_number }}
                                        {{ __('dpc.form.rule_version', ['number' => $proposal->recruitmentRule->version]) }}
                                    @else
                                        —
                                    @endif
                                </dd>
                                <dt class="col-sm-4 fw-normal">{{ __('ui.proposal_date') }}</dt>
                                <dd class="col-sm-8">
                                    {{ $proposal->proposal_date->format('d-m-Y') }}
                                    <span class="text-muted small">{{ __('dpc.form.generated_when_the_proposal_was_raised') }}</span>
                                </dd>
                            </dl>
                        </div>
                    </div>
                @endif

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <strong>{{ $proposal->exists ? __('ui.screen.this_proposal') : __('ui.screen.this_proposal_numbered') }}</strong>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label d-block">{{ __('dpc.form.type_of_dpc_dpc') }}</label>
                            @foreach ($dpcTypes as $type)
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input @error('dpc_type_id') is-invalid @enderror" type="radio" name="dpc_type_id"
                                           id="dpc_type_{{ $type->id }}" value="{{ $type->id }}"
                                           {{ (int) old('dpc_type_id', $proposal->dpc_type_id) === $type->id ? 'checked' : '' }}
                                           {{ $type->code !== 'fresh' ? 'disabled' : '' }}>
                                    <label class="form-check-label" for="dpc_type_{{ $type->id }}">
                                        {{ $type->name_en }}
                                        @if ($type->code !== 'fresh')<span class="badge text-bg-light">{{ __('dpc.form.own_screen') }}</span>@endif
                                    </label>
                                </div>
                            @endforeach
                            @error('dpc_type_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <div class="form-text">
                                {{ __('dpc.form.hint_other_kinds') }}
                            </div>
                        </div>

                        <div class="mb-0">
                            <label class="form-label d-block">
                                {{ __('dpc.form.is_any_legal_stay_in_effect_on_this_proposal') }}
                            </label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="legal_stay" id="legal_stay_no" value="0"
                                       {{ ! old('legal_stay', $proposal->legal_stay) ? 'checked' : '' }}>
                                <label class="form-check-label" for="legal_stay_no">{{ __('dpc.form.no') }}</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="legal_stay" id="legal_stay_yes" value="1"
                                       {{ old('legal_stay', $proposal->legal_stay) ? 'checked' : '' }}>
                                <label class="form-check-label" for="legal_stay_yes">{{ __('dpc.form.yes') }}</label>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-success" @disabled(! $proposal->exists && $preDpcs->isEmpty())>
                    {{ $proposal->exists ? __('ui.screen.save_draft') : __('ui.screen.raise_dpc') }}
                </button>
                <a href="{{ route('dpc-proposals.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>

    @push('scripts')
        <script src="@versioned('js/forms/dpc-promotion-source.js')" defer></script>
    @endpush
@endsection
