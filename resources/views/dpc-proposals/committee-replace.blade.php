@extends('layouts.admin')

@section('title', __('dpc.committee.replacement_heading', ['name' => $member->name]))

@section('content')
    {{-- Substituting a committee member (change register of 2026-08-26, item 9e).

         The outgoing member is kept on the record with who replaced them and why,
         so a proceeding or a signature that already cites them stays truthful. The
         attribute boxes are pre-ticked from the outgoing member because a
         substitution is normally like-for-like — a committee that silently lost its
         only ST member to a blank form would fail its composition rules at the
         sitting. --}}
    <h1 class="h4 mb-1">{{ __('dpc.committee.replacement_heading', ['name' => $member->name]) }}</h1>
    <p class="text-muted small mb-3">{{ $proposal->citation() }}</p>

    @include('partials.alerts')

    <form method="POST" action="{{ route('admin.committee-members.replace', [$proposal, $member]) }}">
        @csrf

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="name">{{ __('ui.name') }}</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name" value="{{ old('name') }}">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="designation">{{ __('ui.designation') }}</label>
                        {{-- From the Designation master, never typed. Blank means
                             *inherit the outgoing member's*, which is what this
                             form's blank fields have always meant. --}}
                        @php $selectedDesignation = (int) old('designation_id', $designations->firstWhere('name', $member->designation)?->id ?? 0); @endphp
                        <select class="form-select @error('designation_id') is-invalid @enderror"
                                id="designation" name="designation_id"
                                data-searchable data-searchable-placeholder="{{ __('ui.search') }}">
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($designations as $designation)
                                <option value="{{ $designation->id }}" @selected($selectedDesignation === $designation->id)>{{ $designation->name }}</option>
                            @endforeach
                        </select>
                        @error('designation_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="mobile">{{ __('ui.mobile') }}</label>
                        <input type="text" class="form-control @error('mobile') is-invalid @enderror"
                               id="mobile" name="mobile" value="{{ old('mobile') }}">
                        @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="email">{{ __('ui.email') }}</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                               id="email" name="email" value="{{ old('email') }}">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                {{-- Two groups, matching the composition form: a member holds at
                     most one social category and any combination of the rest
                     (business clarification, 2026-09-05). Pre-ticked from the
                     outgoing member, because a replacement is normally
                     like-for-like — including which category they represented. --}}
                <fieldset class="mt-3 member-social">
                    <legend class="form-label small mb-1">
                        {{ __('dpc.committee.social_category_heading') }}
                        <span class="text-body-secondary fw-normal">— {{ __('dpc.committee.social_category_hint') }}</span>
                    </legend>
                    <div class="d-flex flex-wrap gap-3 align-items-center">
                        @foreach (\App\Domain\Committee\Enums\MemberCategory::socialCategories() as $attribute)
                            <div class="form-check">
                                <input class="form-check-input member-social-input @error($attribute->column()) is-invalid @enderror"
                                       type="checkbox" value="1"
                                       id="replacement-{{ $attribute->value }}"
                                       name="{{ $attribute->column() }}"
                                       @checked(old($attribute->column(), $member->{$attribute->column()}))>
                                <label class="form-check-label small" for="replacement-{{ $attribute->value }}">
                                    {{ $attribute->label() }}
                                </label>
                            </div>
                        @endforeach
                        <span class="badge text-bg-light member-social-none">{{ __('dpc.committee.social_category_none') }}</span>
                    </div>
                    @error(\App\Domain\Committee\Enums\MemberCategory::ScheduledCaste->column())
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </fieldset>

                <fieldset class="mt-3">
                    <legend class="form-label small mb-1">
                        {{ __('dpc.committee.additional_attributes_heading') }}
                        <span class="text-body-secondary fw-normal">— {{ __('dpc.committee.additional_attributes_hint') }}</span>
                    </legend>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach (\App\Domain\Committee\Enums\MemberCategory::additionalAttributes() as $attribute)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                       id="replacement-{{ $attribute->value }}"
                                       name="{{ $attribute->column() }}"
                                       @checked(old($attribute->column(), $member->{$attribute->column()}))>
                                <label class="form-check-label small" for="replacement-{{ $attribute->value }}">
                                    {{ $attribute->label() }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                </fieldset>

                <div class="mt-3">
                    <label class="form-label" for="reason">
                        {{ __('dpc.committee.replacement_reason') }} <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control @error('reason') is-invalid @enderror"
                              id="reason" name="reason" rows="3" required>{{ old('reason') }}</textarea>
                    @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-warning">{{ __('dpc.committee.replace_member') }}</button>
        <a href="{{ route('dpc-proposals.committee', $proposal) }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
    </form>
@endsection

@push('scripts')
    <script src="{{ asset('js/forms/committee-social-category.js') }}" defer></script>
@endpush
