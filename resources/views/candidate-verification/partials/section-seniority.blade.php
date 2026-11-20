@php
    $own = $data['own'];
    $dpc = $data['dpc'];
@endphp
<dl class="row small mb-3" id="own-details">
    @foreach (['name', 'employee_number', 'dob', 'gender', 'mobile', 'department', 'designation', 'date_of_joining_service', 'date_of_joining_current_post', 'date_of_appointment_current_post', 'seniority_number', 'seniority_position', 'category', 'qualification'] as $field)
        <dt class="col-sm-5">{{ __('candidate.verification.fields.'.$field) }}</dt>
        <dd class="col-sm-7" data-field="{{ $field }}">
            @if ($own[$field] === null || $own[$field] === '')
                <span class="text-body-secondary">{{ __('candidate.verification.fields.unavailable') }}</span>
                @if (in_array($field, ['gender', 'date_of_appointment_current_post'], true))<span class="small text-body-secondary">({{ __('candidate.verification.fields.unavailable_note') }})</span>@endif
            @else
                {{ $own[$field] }}
            @endif
        </dd>
    @endforeach
</dl>

<h3 class="h6">{{ __('candidate.verification.fields.dpc') }}</h3>
<dl class="row small mb-3" id="dpc-details">
    <dt class="col-sm-5">{{ __('candidate.verification.fields.dpc_reference') }}</dt><dd class="col-sm-7">{{ $dpc['reference'] }}</dd>
    <dt class="col-sm-5">{{ __('candidate.verification.fields.dpc_year') }}</dt><dd class="col-sm-7">{{ $dpc['year'] }}</dd>
    <dt class="col-sm-5">{{ __('candidate.verification.fields.department') }}</dt><dd class="col-sm-7">{{ $dpc['department'] ?? '—' }}</dd>
    <dt class="col-sm-5">{{ __('candidate.verification.fields.from_post') }}</dt><dd class="col-sm-7">{{ $dpc['from_post'] ?? '—' }}</dd>
    <dt class="col-sm-5">{{ __('candidate.verification.fields.promotion_post') }}</dt><dd class="col-sm-7">{{ $dpc['promotion_post'] ?? '—' }}</dd>
</dl>

<h3 class="h6">{{ __('candidate.verification.fields.list') }}</h3>
<p class="small text-body-secondary">{{ __('candidate.verification.fields.list_note') }}</p>
<div class="table-responsive">
    <table class="table table-sm small" id="seniority-list">
        <thead><tr><th>{{ __('candidate.verification.fields.sn') }}</th><th>{{ __('candidate.verification.fields.officer') }}</th><th>{{ __('candidate.verification.fields.category') }}</th><th>{{ __('candidate.verification.fields.current_post') }}</th></tr></thead>
        <tbody>
            @foreach ($data['list'] as $line)
                <tr @class(['table-primary' => $line['is_self']])>
                    <td>{{ $line['sn'] }}</td>
                    <td>{{ $line['name'] }}@if ($line['is_self']) <span class="badge text-bg-primary">{{ __('candidate.verification.fields.you') }}</span>@endif</td>
                    <td>{{ $line['category'] ?? '—' }}</td>
                    <td>{{ $line['current_post'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
