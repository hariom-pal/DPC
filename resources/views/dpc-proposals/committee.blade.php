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

                <div id="members"></div>

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
<script>
    (function () {
        const membersEl = document.getElementById('members');
        const tpl = document.getElementById('member-template').innerHTML;
        let m = 0;

        function toggleExternal(card) {
            const select = card.querySelector('.user-select');
            card.querySelector('.external-fields').style.display = select.value ? 'none' : 'flex';
        }

        function addMember() {
            const idx = m++;
            const wrap = document.createElement('div');
            wrap.innerHTML = tpl.replaceAll('__M__', idx);
            const card = wrap.firstElementChild;
            membersEl.appendChild(card);
            toggleExternal(card);
            return card;
        }

        membersEl.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-member')) e.target.closest('.member-card').remove();
        });
        membersEl.addEventListener('change', function (e) {
            if (e.target.classList.contains('user-select')) toggleExternal(e.target.closest('.member-card'));
        });
        document.getElementById('add-member').addEventListener('click', () => addMember());

        const seed = @json($membersData);
        if (seed.length === 0) {
            const card = addMember();
            card.querySelector('[name$="[capacity]"]').value = 'chairman';
        } else {
            seed.forEach(function (member) {
                const card = addMember();
                if (member.user_id) card.querySelector('.user-select').value = member.user_id;
                card.querySelector('[name$="[capacity]"]').value = member.capacity || 'member';
                card.querySelector('[name$="[name]"]').value = member.name || '';
                card.querySelector('[name$="[designation]"]').value = member.designation || '';
                card.querySelector('[name$="[mobile]"]').value = member.mobile || '';
                card.querySelector('[name$="[email]"]').value = member.email || '';
                toggleExternal(card);
            });
        }
    })();
</script>
@endpush
