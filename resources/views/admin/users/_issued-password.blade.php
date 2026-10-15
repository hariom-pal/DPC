{{--
    The temporary password Reset Password just issued.

    **Shown once, because it exists nowhere else.** It is generated, hashed and
    forgotten; this panel is the only moment it is readable. It also goes to the user
    by SMS and e-mail, and until 2026-09-07 that was the only copy — which on a
    deployment whose gateways are in log mode meant nobody could read it, so pressing
    the button locked the account out rather than recovering it.

    Rendered from a flashed value, so a refresh does not redisplay it. The same
    treatment, for the same reason, as the Seniority List's candidate accounts.
--}}
@php($issued = session('issuedPassword'))

@if (is_array($issued) && ! empty($issued['password']))
    <div class="alert alert-success">
        <strong class="d-block mb-1">{{ __('admin.users.issued_heading', ['name' => $issued['name']]) }}</strong>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <code class="fs-5">{{ $issued['password'] }}</code>
        </div>
        <div class="small mt-1">{{ __('admin.users.issued_note') }}</div>
    </div>
@endif
