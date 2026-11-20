{{--
    What provisioning did when this Seniority List was saved (change item 17).

    Since 2026-11-20 the account is the candidate's eSign signer on the Candidate
    Portal, never a password login: the temporary password is generated, hashed and
    never shown. What the department must act on is still shown — an officer with
    no Employee ID, or an Employee ID held elsewhere, has no account to sign with.

    Rendered from a flashed value.
--}}
@php($accounts = session('candidateAccounts'))

@if (is_array($accounts) && ($accounts['created'] !== [] || $accounts['skipped'] !== [] || $accounts['conflicts'] !== []))
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 mb-1">{{ __('dpc.seniority_list.accounts.heading') }}</h2>
            <p class="small text-body-secondary mb-3">{{ __('dpc.seniority_list.accounts.lead') }}</p>

            @if ($accounts['created'] !== [])
                <p class="small text-body-secondary mb-2">{{ __('dpc.seniority_list.accounts.created_count', ['count' => count($accounts['created'])]) }}</p>
            @endif

            @if ($accounts['reused'] !== [])
                <p class="small text-body-secondary mb-2">
                    {{ __('dpc.seniority_list.accounts.reused', ['numbers' => implode(', ', $accounts['reused'])]) }}
                </p>
            @endif

            @if ($accounts['skipped'] !== [])
                <div class="alert alert-warning py-2 small mb-2">
                    {{ __('dpc.seniority_list.accounts.skipped', ['names' => implode(', ', $accounts['skipped'])]) }}
                </div>
            @endif

            {{-- BD-27. Two departments may each have issued the same employee
                 number, and the portal cannot tell that from a transfer, so it
                 changes nothing and says so. --}}
            @if ($accounts['conflicts'] !== [])
                <div class="alert alert-danger py-2 small mb-0">
                    <strong class="d-block mb-1">{{ __('dpc.seniority_list.accounts.conflicts_heading') }}</strong>
                    {{ __('dpc.seniority_list.accounts.conflicts', ['numbers' => implode(', ', $accounts['conflicts'])]) }}
                </div>
            @endif
        </div>
    </div>
@endif
