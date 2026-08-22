@extends('layouts.app')

@section('title', 'Working Officers & Vacancies')

@php
    $vacanciesData = old('vacancies', $proposal->vacancies->map(fn ($v) => [
        'vacancy_type_id' => $v->vacancy_type_id,
        'reservation_category_id' => $v->reservation_category_id,
        'count' => $v->count,
    ])->all());
    $officersData = old('officers', $proposal->workingOfficers->map(fn ($o) => [
        'sn' => $o->sn,
        'employee_number' => $o->employee_number,
        'name' => $o->name,
        'dob' => $o->dob?->format('Y-m-d'),
        'qualification' => $o->qualification,
        'current_post' => $o->current_post,
        'feeder_post' => $o->feeder_post,
        'date_of_joining_service' => $o->date_of_joining_service?->format('Y-m-d'),
        'date_of_posting_on_post' => $o->date_of_posting_on_post?->format('Y-m-d'),
        'date_of_posting_on_feeder_post' => $o->date_of_posting_on_feeder_post?->format('Y-m-d'),
        'reservation_category_id' => $o->reservation_category_id,
        'roster_position' => $o->roster_position,
        'deputation_date' => $o->deputation_date?->format('Y-m-d'),
    ])->all());
@endphp

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')
            <h1 class="h4 mb-1">Working Officers & Vacancies</h1>
            <p class="text-muted small mb-3">2026 की स्थिति में {{ $proposal->postToCadre->name }} पद पर कार्यरत अधिकारीयों का विवरण एवं रिक्ति विवरण — a snapshot as of today; it will not change later even if HR records change.</p>

            @include('dpc-proposals.imports._upload', ['template' => \App\Domain\Import\Enums\ImportTemplate::from('currently_working_us')])

            <form method="POST" action="{{ route('dpc-proposals.working-officers.update', $proposal) }}">
                @csrf
                @method('PUT')

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-2">
                                <label class="form-label">Sanctioned Post Count / स्वीकृत पद संख्या</label>
                                <input type="number" min="0" class="form-control @error('sanctioned_post_count') is-invalid @enderror"
                                       name="sanctioned_post_count" value="{{ old('sanctioned_post_count', $proposal->sanctioned_post_count) }}" required>
                                @error('sanctioned_post_count')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h2 class="h6 mb-0">Vacancy Breakdown</h2>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="add-vacancy">+ Add Vacancy Row</button>
                        </div>
                        @error('vacancies')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
                        {{-- Seed rows travel as data — see public/js/forms/dpc-working-officers.js. --}}
                        <div id="vacancies" data-seed="{{ json_encode($vacanciesData) }}"></div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Officers Currently Working on {{ $proposal->postToCadre->name }}</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="add-officer">+ Add Officer</button>
                </div>
                <div id="officers" data-seed="{{ json_encode($officersData) }}"></div>

                <button type="submit" class="btn btn-success mt-3">Save</button>
                <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary mt-3">Cancel</a>
            </form>
        </div>
    </div>

    <template id="vacancy-template">
        <div class="row vacancy-row mb-2 align-items-end">
            <div class="col-md-4"><label class="form-label">Vacancy Type</label>
                <select class="form-select" name="vacancies[__V__][vacancy_type_id]" required>
                    @foreach ($vacancyTypes as $vt)<option value="{{ $vt->id }}">{{ $vt->name_en }}</option>@endforeach
                </select></div>
            <div class="col-md-4"><label class="form-label">Reservation Category</label>
                <select class="form-select" name="vacancies[__V__][reservation_category_id]" required>
                    @foreach ($reservationCategories as $rc)<option value="{{ $rc->id }}">{{ $rc->code }} — {{ $rc->name_en }}</option>@endforeach
                </select></div>
            <div class="col-md-2"><label class="form-label">Count</label>
                <input type="number" min="0" class="form-control" name="vacancies[__V__][count]" value="0" required></div>
            <div class="col-md-2"><button type="button" class="btn btn-outline-danger btn-sm remove-row">× Remove</button></div>
        </div>
    </template>

    <template id="officer-template">
        <div class="card border-0 shadow-sm mb-2 officer-card">
            <div class="card-body row g-2">
                <div class="col-md-1"><label class="form-label">SN</label>
                    <input type="number" min="1" class="form-control form-control-sm" name="officers[__O__][sn]" required></div>
                <div class="col-md-2"><label class="form-label">Employee No.</label>
                    <input type="text" class="form-control form-control-sm" name="officers[__O__][employee_number]"></div>
                <div class="col-md-3"><label class="form-label">Name</label>
                    <input type="text" class="form-control form-control-sm" name="officers[__O__][name]" required></div>
                <div class="col-md-2"><label class="form-label">DOB</label>
                    <input type="date" class="form-control form-control-sm" name="officers[__O__][dob]" required></div>
                <div class="col-md-2"><label class="form-label">Qualification</label>
                    <input type="text" class="form-control form-control-sm" name="officers[__O__][qualification]"></div>
                <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm remove-row">× Remove</button></div>

                <div class="col-md-3"><label class="form-label">Current Post</label>
                    <input type="text" class="form-control form-control-sm" name="officers[__O__][current_post]"></div>
                <div class="col-md-3"><label class="form-label">Feeder Post</label>
                    <input type="text" class="form-control form-control-sm" name="officers[__O__][feeder_post]"></div>
                <div class="col-md-2"><label class="form-label">Category</label>
                    <select class="form-select form-select-sm" name="officers[__O__][reservation_category_id]" required>
                        @foreach ($reservationCategories as $rc)<option value="{{ $rc->id }}">{{ $rc->code }}</option>@endforeach
                    </select></div>
                <div class="col-md-2"><label class="form-label">Roster Position</label>
                    <input type="number" min="1" class="form-control form-control-sm" name="officers[__O__][roster_position]"></div>
                <div class="col-md-2"><label class="form-label">Deputation Date</label>
                    <input type="date" class="form-control form-control-sm" name="officers[__O__][deputation_date]"></div>

                <div class="col-md-4"><label class="form-label">Date of Joining Service</label>
                    <input type="date" class="form-control form-control-sm" name="officers[__O__][date_of_joining_service]"></div>
                <div class="col-md-4"><label class="form-label">Date of Posting on Current Post</label>
                    <input type="date" class="form-control form-control-sm" name="officers[__O__][date_of_posting_on_post]"></div>
                <div class="col-md-4"><label class="form-label">Date of Posting on Feeder Post</label>
                    <input type="date" class="form-control form-control-sm" name="officers[__O__][date_of_posting_on_feeder_post]"></div>
            </div>
        </div>
    </template>
@endsection

@push('scripts')
    <script src="{{ asset('js/forms/dpc-working-officers.js') }}" defer></script>
@endpush
