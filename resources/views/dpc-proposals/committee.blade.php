@extends('layouts.app')

@section('title', 'DPC Committee')

@php
    $membersData = old('members', $proposal->committeeMembers->map(fn ($m) => [
        'user_id' => $m->user_id,
        'name' => $m->name,
        'designation' => $m->designation,
        'mobile' => $m->mobile,
        'email' => $m->email,
        'capacity' => $m->capacity->value,
    ])->all());
@endphp

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')
            <h1 class="h4 mb-1">DPC Committee</h1>
            <p class="text-muted small mb-3">विभागीय पदोन्नति समिति का विवरण — one Chairman is required; other members reference an existing CGPSC account wherever possible, or can be added as an external member.</p>

            <form method="POST" action="{{ route('dpc-proposals.committee.update', $proposal) }}">
                @csrf
                @method('PUT')

                {{-- The saved members travel as data, not as interpolated
                     script — see public/js/forms/dpc-committee.js and TD-29. --}}
                <div id="members" data-seed="{{ json_encode($membersData) }}"></div>

                <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="add-member">+ Add Member</button>

                <div class="mt-3">
                    <button type="submit" class="btn btn-success">Save Committee</button>
                    <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <template id="member-template">
        <div class="card border-0 shadow-sm mb-3 member-card">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Existing User (CGPSC)</label>
                        <select class="form-select user-select" name="members[__M__][user_id]">
                            <option value="">— External Member (manual entry) —</option>
                            @foreach ($candidateUsers as $u)
                                <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->getRoleNames()->first() }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">Capacity</label>
                        <select class="form-select" name="members[__M__][capacity]" required>
                            <option value="chairman">Chairman</option>
                            <option value="member">Member</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-2 d-flex align-items-end justify-content-end">
                        <button type="button" class="btn btn-outline-danger btn-sm remove-member">× Remove</button>
                    </div>
                </div>
                <div class="row external-fields">
                    <div class="col-md-3 mb-2"><label class="form-label">Name</label>
                        <input type="text" class="form-control" name="members[__M__][name]"></div>
                    <div class="col-md-3 mb-2"><label class="form-label">Designation</label>
                        <input type="text" class="form-control" name="members[__M__][designation]"></div>
                    <div class="col-md-3 mb-2"><label class="form-label">Mobile</label>
                        <input type="text" class="form-control" name="members[__M__][mobile]"></div>
                    <div class="col-md-3 mb-2"><label class="form-label">Email</label>
                        <input type="email" class="form-control" name="members[__M__][email]"></div>
                </div>
            </div>
        </div>
    </template>
@endsection

@push('scripts')
    <script src="{{ asset('js/forms/dpc-committee.js') }}" defer></script>
@endpush
