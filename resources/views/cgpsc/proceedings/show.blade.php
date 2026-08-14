@extends('layouts.app')

@section('title', 'DPC Proceeding — Action')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-12">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Online DPC — Action</h1>
            <p class="text-muted small mb-3">
                {{ $proceeding->proposal->postFromCadre?->name }} →
                {{ $proceeding->proposal->postToCadre?->name }},
                {{ $proceeding->proposal->department?->name }}
            </p>

            @error('correction')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="row g-3 small">
                        <div class="col-md-3">
                            <div class="text-muted">DPC ID</div>
                            <code>{{ $proceeding->proposal->dpc_code ?? '—' }}</code>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted">Scheduled For</div>
                            @if ($proceeding->schedule)
                                {{ $proceeding->schedule->dpc_date->format('d/m/Y') }}
                                {{ \Illuminate\Support\Carbon::parse($proceeding->schedule->dpc_time)->format('h:i A') }}
                            @else
                                —
                            @endif
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted">Opened By / At</div>
                            {{ $proceeding->conducted_by_name }},
                            {{ $proceeding->opened_at->format('d/m/Y h:i A') }}
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted">Closed By / At</div>
                            @if ($proceeding->closed_at)
                                {{ $proceeding->closed_by_name }},
                                {{ $proceeding->closed_at->format('d/m/Y h:i A') }}
                            @else
                                <span class="text-muted">Not yet closed</span>
                            @endif
                            <span class="badge {{ $proceeding->status->badgeClass() }}">
                                {{ $proceeding->status->label() }}
                            </span>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="small text-muted">
                        <div>Reference <code>{{ $proceeding->uuid }}</code></div>
                        {{-- S31: the department's entries are fixed once the proposal is forwarded. This
                             fingerprint is the evidence of exactly which submission was acted on. --}}
                        <div>
                            Department submission fingerprint
                            <code>{{ substr($proceeding->proposal_version, 0, 16) }}…</code>
                            — recorded when the sitting opened, so the entries acted on are provable.
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-info py-2 small d-flex justify-content-between align-items-center">
                <span>
                    <strong>Corrections are recorded beside the department's entry, never over it.</strong>
                    A corrected value is shown with the department's original struck through. Nothing you do here
                    changes what the department submitted.
                </span>
                @if ($canEdit)
                    <form method="POST" action="{{ route('cgpsc.proceedings.calculate', $proceeding) }}"
                          class="ms-3">
                        @csrf
                        <button class="btn btn-sm btn-primary">CALCULATE</button>
                    </form>
                @endif
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h6 mb-0">Evaluation Sheet &amp; Final Seniority List</h2>
                        <a class="btn btn-sm btn-outline-secondary"
                           href="{{ route('cgpsc.proceedings.corrections.index', $proceeding) }}">
                            Correction History
                        </a>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0" style="font-size:.85rem">
                            <thead class="table-light">
                                <tr>
                                    <th rowspan="2">SN</th>
                                    <th rowspan="2">Name</th>
                                    <th rowspan="2">DOB</th>
                                    <th rowspan="2">Qual</th>
                                    <th rowspan="2">DOP</th>
                                    <th rowspan="2">Cat</th>
                                    <th colspan="{{ max($years->count(), 1) }}">Loyalty</th>
                                    <th colspan="{{ max($years->count(), 1) }}">Immovable Property</th>
                                    <th colspan="{{ max($years->count(), 1) }}">Year-wise CR</th>
                                    <th rowspan="2">Tribal</th>
                                    <th rowspan="2">Note</th>
                                    <th rowspan="2">Mark</th>
                                    <th rowspan="2">Remark</th>
                                    @if ($canEdit)<th rowspan="2">Edit</th>@endif
                                </tr>
                                <tr>
                                    @foreach ($years as $year)<th>{{ $year }}</th>@endforeach
                                    @foreach ($years as $year)<th>{{ $year }}</th>@endforeach
                                    @foreach ($years as $year)<th>{{ $year }}</th>@endforeach
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($chart as $entry)
                                @php $row = $entry['row']; @endphp
                                <tr>
                                    <td>{{ $entry['sn'] }}</td>
                                    <td>{{ $row->name }}</td>
                                    <td>{{ $row->dob->format('d-m-Y') }}</td>
                                    <td>{{ $row->qualification }}</td>
                                    <td>{{ $row->date_of_posting_on_feeder_post?->format('d-m-Y') }}</td>
                                    <td>{{ $row->reservationCategory?->code }}</td>

                                    @foreach ($years as $year)
                                        <td class="text-center">
                                            @include('cgpsc.proceedings._cell', ['cell' => $entry['loyalty'][$year] ?? null])
                                        </td>
                                    @endforeach
                                    @foreach ($years as $year)
                                        <td class="text-center">
                                            @include('cgpsc.proceedings._cell', ['cell' => $entry['property'][$year] ?? null])
                                        </td>
                                    @endforeach
                                    @foreach ($years as $year)
                                        <td class="text-center">
                                            @include('cgpsc.proceedings._cell', ['cell' => $entry['cr'][$year] ?? null])
                                        </td>
                                    @endforeach

                                    <td class="text-center">
                                        @include('cgpsc.proceedings._cell', ['cell' => $entry['tribal']])
                                    </td>
                                    <td>{{ $row->note }}</td>
                                    {{-- Mark and Remark are the Commission's, produced by the calculation.
                                         They stay empty until the result is calculated in the next phase. --}}
                                    <td class="text-center text-muted">—</td>
                                    <td class="text-center text-muted">—</td>

                                    @if ($canEdit)
                                        <td class="text-nowrap">
                                            <button class="btn btn-sm btn-outline-primary py-0 px-1"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#edit-{{ $entry['sn'] }}">EDIT</button>
                                        </td>
                                    @endif
                                </tr>

                                @if ($canEdit)
                                    <tr class="collapse" id="edit-{{ $entry['sn'] }}">
                                        <td colspan="{{ 11 + (3 * max($years->count(), 1)) }}" class="bg-light">
                                            <form method="POST"
                                                  action="{{ route('cgpsc.proceedings.corrections.store', $proceeding) }}"
                                                  class="row g-2 align-items-end">
                                                @csrf
                                                <input type="hidden" name="sn" value="{{ $entry['sn'] }}">

                                                <div class="col-md-3">
                                                    <label class="form-label small mb-0">What is being corrected</label>
                                                    <select name="field" class="form-select form-select-sm">
                                                        @foreach ($correctableFields as $field)
                                                            <option value="{{ $field->value }}">{{ $field->label() }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>

                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Year</label>
                                                    <select name="year" class="form-select form-select-sm">
                                                        <option value="">Not year-specific (Tribal)</option>
                                                        @foreach ($years as $year)
                                                            <option value="{{ $year }}">{{ $year }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>

                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Corrected value</label>
                                                    <input type="text" name="new_value" class="form-control form-control-sm"
                                                           placeholder="Grade, Y/N, or years">
                                                    <div class="form-text">Leave empty to clear the entry.</div>
                                                </div>

                                                <div class="col-md-4">
                                                    <label class="form-label small mb-0">Reason (required)</label>
                                                    <input type="text" name="reason" class="form-control form-control-sm"
                                                           placeholder="Why this correction is being made">
                                                </div>

                                                <div class="col-md-1">
                                                    <button class="btn btn-sm btn-primary w-100">SAVE</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="{{ 11 + (3 * max($years->count(), 1)) }}"
                                        class="text-muted text-center py-3">
                                        This proposal has no evaluation chart rows.
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    @error('new_value')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    @error('year')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    @error('reason')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
            </div>

            @if ($corrections->isNotEmpty())
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-body">
                        <h2 class="h6 mb-3">Corrections in Force</h2>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>SN</th><th>Officer</th><th>Field</th><th>Year</th>
                                        <th>Department Entry</th><th>Corrected To</th><th>Reason</th>
                                        <th>By</th>@if ($canEdit)<th>Withdraw</th>@endif
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach ($corrections as $correction)
                                    <tr>
                                        <td>{{ $correction->sn }}</td>
                                        <td>{{ $correction->officer_name }}</td>
                                        <td>{{ \App\Domain\Proceeding\Enums\CorrectableField::from($correction->field)->label() }}</td>
                                        <td>{{ $correction->year ?? '—' }}</td>
                                        <td><del class="text-muted">{{ $correction->previous_value ?? '(blank)' }}</del></td>
                                        <td><strong>{{ $correction->new_value ?? '(blank)' }}</strong></td>
                                        <td class="small">{{ $correction->reason }}</td>
                                        <td class="small">{{ $correction->applied_by_name }}</td>
                                        @if ($canEdit)
                                            <td>
                                                <form method="POST"
                                                      action="{{ route('cgpsc.proceedings.corrections.destroy', [$proceeding, $correction]) }}"
                                                      class="d-flex gap-1">
                                                    @csrf @method('DELETE')
                                                    <input type="text" name="reason" class="form-control form-control-sm"
                                                           placeholder="Reason" style="width:12rem">
                                                    <button class="btn btn-sm btn-outline-danger">Withdraw</button>
                                                </form>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            <div class="mt-3">
                <a class="btn btn-sm btn-outline-primary"
                   href="{{ route('cgpsc.proceedings.result', $proceeding) }}">Result &amp; Approval</a>
                <a class="btn btn-sm btn-outline-secondary"
                   href="{{ route('cgpsc.proceedings.history', $proceeding) }}">Proceeding History</a>
                <a class="btn btn-sm btn-link" href="{{ route('cgpsc.proceedings.index') }}">Back</a>
            </div>
        </div>
    </div>
@endsection
