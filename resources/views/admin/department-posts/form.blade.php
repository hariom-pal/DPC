@extends($layout)

@section('title', __($post->exists ? 'admin.department_posts.form.title_edit' : 'admin.department_posts.form.title_new'))

@section('content')
    <h1 class="h4 mb-3">{{ __($post->exists ? 'admin.department_posts.form.title_edit' : 'admin.department_posts.form.title_new') }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data"
                  action="{{ $post->exists ? route($routePrefix.'.update', $post) : route($routePrefix.'.store') }}">
                @csrf
                @if ($post->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="department_id">{{ __('ui.department') }}</label>
                        @if ($lockedDepartment)
                        <input type="hidden" name="department_id" value="{{ $lockedDepartment->id }}">
                        <input type="text" class="form-control @error('department_id') is-invalid @enderror" id="department_id" value="{{ $lockedDepartment->name }}" readonly>
                        @else
                        <select class="form-select @error('department_id') is-invalid @enderror" id="department_id" name="department_id" required>
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected(old('department_id', $post->department_id) == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                        @endif
                        @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="designation_id">{{ __('admin.department_posts.form.post_name') }}</label>
                        {{-- Chosen from the Designation master, never typed
                             (DPC-MODULE-FIX-001, second pass — business, 2026-09-06).

                             The mapping still *stores* the name in
                             `department_posts.name`: it is what a superseded mapping
                             keeps, what the officer-snapshot tables copy and what the
                             CSV importers match on. `DepartmentPostRequest` resolves
                             the id into that column before validation, so uniqueness
                             and every other rule are unchanged. --}}
                        @php $selectedDesignation = (int) old('designation_id', $designations->firstWhere('name', $post->name)?->id ?? 0); @endphp
                        <select class="form-select @error('name') is-invalid @enderror" id="designation_id" name="designation_id"
                                required data-searchable data-searchable-placeholder="{{ __('ui.search') }}">
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($designations as $designation)
                                <option value="{{ $designation->id }}" @selected($selectedDesignation === $designation->id)>{{ $designation->name }}</option>
                            @endforeach
                        </select>
                        @error('name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.department_posts.form.hint_post_name') }}</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="class_level">{{ __('admin.department_posts.form.service_class') }}</label>
                        <select class="form-select @error('class_level') is-invalid @enderror" id="class_level" name="class_level" required>
                            @foreach ($classes as $class)
                                <option value="{{ $class->value }}" @selected(old('class_level', $post->class_level?->value) === $class->value)>{{ $class->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="pay_scale_id">{{ __('ui.pay_scale') }}</label>
                        <select class="form-select" id="pay_scale_id" name="pay_scale_id">
                            <option value="">{{ __('ui.none') }}</option>
                            @foreach ($payScales as $payScale)
                                <option value="{{ $payScale->id }}" @selected(old('pay_scale_id', $post->pay_scale_id) == $payScale->id)>{{ $payScale->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- Promotion Duration (item 4). These three attributes are why a
                         proposal never re-asks for a post's pay scale or class: they
                         are read off the post. --}}
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="promotion_duration_years">{{ __('admin.department_posts.form.promotion_duration_years') }}</label>
                        <input type="number" min="0" max="40" step="1"
                               class="form-control @error('promotion_duration_years') is-invalid @enderror"
                               id="promotion_duration_years" name="promotion_duration_years"
                               value="{{ old('promotion_duration_years', $post->promotion_duration_years) }}">
                        @error('promotion_duration_years')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.department_posts.form.hint_promotion_duration') }}</div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="status">{{ __('ui.status') }}</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $post->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- The Government Order that authorises this mapping.

                     Separated from the terms above because it is a different kind
                     of fact: the terms are what the mapping *says*, and this is
                     what makes it lawful. --}}
                <h2 class="h6 text-uppercase text-body-secondary border-bottom pb-2 mb-3 mt-2">
                    {{ __('admin.department_posts.form.authority') }}
                </h2>

                {{-- Item 10 (2026-08-26): several Government Orders, with Add More.

                     Orders already on record are listed read-only above the
                     repeater rather than rendered as editable rows. An order is
                     evidence and `DepartmentPostOrder` refuses to be edited, so
                     offering an input that could not be saved would be a lie. The
                     repeater records further ones. --}}
                @if ($post->exists && $post->orders->isNotEmpty())
                    <ul class="list-group list-group-flush mb-3">
                        @foreach ($post->orders as $recorded)
                            <li class="list-group-item small d-flex justify-content-between flex-wrap gap-2">
                                <span>{{ $recorded->citation() }}</span>
                                <span class="text-body-secondary">
                                    @if ($recorded->hasDocument())
                                        {{ $recorded->original_name }} ({{ $recorded->sizeLabel() }})
                                    @else
                                        {{ __('admin.department_posts.show.no_document') }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @php
                    // One empty row to start from, or whatever a failed submission
                    // posted back. Keys are preserved so an error reported against
                    // row 3 stays on row 3.
                    $orderRows = old('orders', [[]]);
                @endphp

                <div id="government-orders">
                    @foreach ($orderRows as $index => $orderRow)
                        @include('admin.department-posts.partials.order-row', ['index' => $index, 'row' => $orderRow])
                    @endforeach
                </div>

                <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="add-government-order">
                    {{ __('admin.department_posts.form.add_order') }}
                </button>

                <template id="government-order-template">
                    @include('admin.department-posts.partials.order-row', ['index' => '__I__', 'row' => []])
                </template>

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_from">{{ __('admin.department_posts.form.effective_from') }} <span class="text-danger">*</span></label>
                        <input type="date" class="form-control @error('effective_from') is-invalid @enderror"
                               id="effective_from" name="effective_from"
                               value="{{ old('effective_from', $post->effective_from?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required>
                        @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.department_posts.form.hint_effective_from') }}</div>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">{{ __('ui.save') }}</button>
                <a href="{{ route($routePrefix.'.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/forms/department-post-orders.js') }}" defer></script>
@endpush
