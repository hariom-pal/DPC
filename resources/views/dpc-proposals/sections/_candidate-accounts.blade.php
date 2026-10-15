{{--
    What provisioning did when this Seniority List was saved (change item 17).

    **Shown once, because the passwords exist nowhere else.** A temporary password
    is generated, hashed and forgotten; this panel is the only moment it is
    readable. Between item 17 shipping and 2026-08-25 the provisioning result was
    discarded by the controller, so accounts were created that no officer could
    ever sign in to — the feature looked delivered and was not.

    Rendered from a flashed value, so a refresh does not redisplay credentials.
--}}
@php($accounts = session('candidateAccounts'))

@if (is_array($accounts) && ($accounts['created'] !== [] || $accounts['skipped'] !== [] || $accounts['conflicts'] !== []))
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 mb-1">{{ __('dpc.seniority_list.accounts.heading') }}</h2>
            <p class="small text-body-secondary mb-3">{{ __('dpc.seniority_list.accounts.lead') }}</p>

            @if ($accounts['created'] !== [])
                <div class="alert alert-success py-2 small">
                    <strong class="d-block mb-1">{{ __('dpc.seniority_list.accounts.created_heading') }}</strong>
                    {{ __('dpc.seniority_list.accounts.created_note') }}
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('dpc.seniority_list.accounts.employee_number') }}</th>
                                <th>{{ __('dpc.seniority_list.accounts.temporary_password') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accounts['created'] as $employeeNumber => $password)
                                <tr>
                                    <td><code>{{ $employeeNumber }}</code></td>
                                    <td><code>{{ $password }}</code></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
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
