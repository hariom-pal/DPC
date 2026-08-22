@extends('layouts.app')

@section('title', 'Seniority List')

@php
    $entriesData = old('entries', $proposal->seniorityEntries->map(fn ($e) => [
        'seniority_number' => $e->seniority_number,
        'employee_number' => $e->employee_number,
        'name' => $e->name,
        'dob' => $e->dob?->format('Y-m-d'),
        'qualification' => $e->qualification,
        'current_post' => $e->current_post,
        'feeder_post' => $e->feeder_post,
        'reservation_category_id' => $e->reservation_category_id,
        'date_of_joining_service' => $e->date_of_joining_service?->format('Y-m-d'),
        'date_of_posting_on_current_post' => $e->date_of_posting_on_current_post?->format('Y-m-d'),
        'date_of_posting_on_feeder_post' => $e->date_of_posting_on_feeder_post?->format('Y-m-d'),
    ])->all());
@endphp

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')
            <h1 class="h4 mb-1">Seniority List</h1>
            <p class="text-muted small mb-3">{{ $proposal->postFromCadre->name }} की अंतिम पदक्रम सूचि — a snapshot as of today; it will not change later even if HR records change.</p>

            @include('dpc-proposals.imports._upload', ['template' => \App\Domain\Import\Enums\ImportTemplate::from('final_seniority_list_us')])

            <form method="POST" action="{{ route('dpc-proposals.seniority-list.update', $proposal) }}">
                @csrf
                @method('PUT')

                @error('entries')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
                {{-- Seed rows travel as data — see public/js/forms/dpc-seniority-list.js. --}}
                <div id="entries" data-seed="{{ json_encode($entriesData) }}"></div>

                <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="add-entry">+ Add Entry</button>

                <div class="mt-3">
                    <button type="submit" class="btn btn-success">Save Seniority List</button>
                    <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <template id="entry-template">
        <div class="card border-0 shadow-sm mb-2 entry-card">
            <div class="card-body row g-2">
                <div class="col-md-1"><label class="form-label">S.N.</label>
                    <input type="number" min="1" class="form-control form-control-sm" name="entries[__E__][seniority_number]" required></div>
                <div class="col-md-2"><label class="form-label">Employee No.</label>
                    <input type="text" class="form-control form-control-sm" name="entries[__E__][employee_number]"></div>
                <div class="col-md-3"><label class="form-label">Name</label>
                    <input type="text" class="form-control form-control-sm" name="entries[__E__][name]" required></div>
                <div class="col-md-2"><label class="form-label">DOB</label>
                    <input type="date" class="form-control form-control-sm" name="entries[__E__][dob]" required></div>
                <div class="col-md-2"><label class="form-label">Qualification</label>
                    <input type="text" class="form-control form-control-sm" name="entries[__E__][qualification]"></div>
                <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm remove-row">× Remove</button></div>

                <div class="col-md-3"><label class="form-label">Current Post</label>
                    <input type="text" class="form-control form-control-sm" name="entries[__E__][current_post]"></div>
                <div class="col-md-3"><label class="form-label">Feeder Post</label>
                    <input type="text" class="form-control form-control-sm" name="entries[__E__][feeder_post]"></div>
                <div class="col-md-3"><label class="form-label">Reservation Category</label>
                    <select class="form-select form-select-sm" name="entries[__E__][reservation_category_id]" required>
                        @foreach ($reservationCategories as $rc)<option value="{{ $rc->id }}">{{ $rc->code }} — {{ $rc->name_en }}</option>@endforeach
                    </select></div>
                <div class="col-md-3"><label class="form-label">Date of Joining</label>
                    <input type="date" class="form-control form-control-sm" name="entries[__E__][date_of_joining_service]"></div>
                <div class="col-md-3"><label class="form-label">Date of Posting on Current Post</label>
                    <input type="date" class="form-control form-control-sm" name="entries[__E__][date_of_posting_on_current_post]"></div>
                <div class="col-md-3"><label class="form-label">Date of Posting on Feeder Post</label>
                    <input type="date" class="form-control form-control-sm" name="entries[__E__][date_of_posting_on_feeder_post]"></div>
            </div>
        </div>
    </template>
@endsection

@push('scripts')
    <script src="{{ asset('js/forms/dpc-seniority-list.js') }}" defer></script>
@endpush
