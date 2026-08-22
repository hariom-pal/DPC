@extends('layouts.app')

{{--
    S47 — PROCESS OF CIRCULATION DPC / ACTION.

    Five of the six fields the slide shows are context read from the origin DPC
    and are displayed rather than entered; only the circulation proposal date
    and the DPC ID are inputs. The officers and their resolution evidence are
    S45's substance: a note follows the *resolution* of a recorded gap, and a
    note that records only "resolved" says nothing a reader can check later.
--}}

@section('title', 'Raise a Circulation DPC')

@section('content')
    <div class="container py-4">
        <h1 class="h4 mb-1">Circulation DPC — परिभ्रमण डी.पी.सी.</h1>
        <p class="text-body-secondary">
            Ask the Commission to consent to a promotion it reserved, now that the missing record has been supplied.
            <strong>The DPC itself is not re-run and no marks are recalculated.</strong>
        </p>

        @if (session('pendingBusinessDecision'))
            @include('dpc.circulations._pending-business-decision', [
                'pending' => session('pendingBusinessDecision'),
            ])
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($origins->isEmpty())
            <div class="alert alert-info">
                No DPC of this department currently has a promotion held. A circulation can only be raised
                against a concluded DPC that reserved at least one officer's promotion.
            </div>
        @else
            <form method="GET" action="{{ route('dpc-circulations.create') }}" class="row g-3 align-items-end mb-4">
                <div class="col-md-6">
                    <label for="origin" class="form-label">DPC ID</label>
                    {{-- `data-submit-on-change` rather than an inline handler: see
                         portal.js. The <noscript> button below is the fallback. --}}
                    <select id="origin" name="origin" class="form-select" data-submit-on-change>
                        @foreach ($origins as $origin)
                            <option value="{{ $origin->uuid }}" @selected($selected && $selected->id === $origin->id)>
                                {{ $origin->dpc_code ?? 'DPC #'.$origin->id }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <noscript><button type="submit" class="btn btn-outline-secondary">Show held officers</button></noscript>
                </div>
            </form>

            @if ($selected)
                <div class="card mb-4">
                    <div class="card-header">The DPC being circulated</div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">DPC ID</dt>
                            <dd class="col-sm-8">{{ $selected->dpc_code ?? '—' }}</dd>

                            {{-- S47 labels this "फ्रेश DPC की तिथि". The label is
                                 narrower than the rule — a Review holds officers
                                 too (S44 shows `ST SS CIR`) — so it is worded for
                                 what it is. Category-B difference, recorded. --}}
                            <dt class="col-sm-4">DPC date</dt>
                            <dd class="col-sm-8">{{ optional($selected->proposal_date)->format('d-m-Y') ?? '—' }}</dd>

                            <dt class="col-sm-4">Type of DPC</dt>
                            <dd class="col-sm-8">CIRCULATION</dd>
                        </dl>
                    </div>
                </div>

                <form method="POST" action="{{ route('dpc-circulations.store') }}">
                    @csrf
                    <input type="hidden" name="origin_dpc_proposal_uuid" value="{{ $selected->uuid }}">

                    <div class="mb-4" style="max-width: 22rem;">
                        <label for="proposal_date" class="form-label">
                            Circulation proposal date — परिभ्रमण पदोन्नति प्रस्ताव तिथि
                        </label>
                        <input type="date" id="proposal_date" name="proposal_date" class="form-control"
                               value="{{ old('proposal_date', now()->toDateString()) }}" required>
                    </div>

                    <h2 class="h6">Held promotions on this DPC</h2>

                    @if ($holds->isEmpty())
                        <div class="alert alert-info">No promotion is currently held on this DPC.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 3rem;">Include</th>
                                        <th>SN</th>
                                        <th>Officer</th>
                                        <th>Ground</th>
                                        <th>Resolution received</th>
                                        <th>What was received</th>
                                        <th>Reference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($holds as $index => $hold)
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="form-check-input"
                                                       name="items[{{ $index }}][hold_uuid]"
                                                       value="{{ $hold->uuid }}">
                                            </td>
                                            <td>{{ $hold->sn }}</td>
                                            <td>{{ $hold->officer_name }}</td>
                                            <td>
                                                {{ $hold->reason_code->label() }}
                                                @if ($hold->detail)
                                                    <div class="small text-body-secondary">{{ $hold->detail }}</div>
                                                @endif
                                            </td>
                                            <td>
                                                <input type="date" class="form-control form-control-sm"
                                                       name="items[{{ $index }}][resolution_received_on]">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm"
                                                       name="items[{{ $index }}][resolution_detail]"
                                                       placeholder="e.g. 2016 immovable property return filed">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm"
                                                       name="items[{{ $index }}][resolution_reference]"
                                                       placeholder="File / letter no.">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <button type="submit" class="btn btn-primary">Raise circulation</button>
                    @endif
                </form>
            @endif
        @endif
    </div>
@endsection
