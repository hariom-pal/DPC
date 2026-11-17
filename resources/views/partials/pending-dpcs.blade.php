{{-- MoM of 16 November 2026, point 8: a list of pending DPCs with their stage and
     deadline. Expects: $entries (DpcStageService::pendingWith/pendingAt), $id, $title,
     $lead, $mine (true: the action is this user's). --}}
<div class="card border-0 shadow-sm mb-3" id="{{ $id }}">
    <div class="card-body">
        <h2 class="h6 mb-0">{{ $title }} <span class="badge text-bg-secondary">{{ count($entries) }}</span></h2>
        <p class="small text-muted mb-2">{{ $lead }}</p>
        @if ($entries === [])
            <p class="small text-muted mb-0">{{ __('dpc.stage.none') }}</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle small mb-0">
                    <thead><tr>
                        <th>{{ __('dpc.stage.col_dpc') }}</th>
                        <th>{{ __('dpc.stage.col_stage') }}</th>
                        <th>{{ __('dpc.stage.col_action') }}</th>
                        <th>{{ __('dpc.stage.col_deadline') }}</th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            @php
                                $proposal = $entry['proposal'];
                                $deadline = $entry['deadline'];
                                $nearing = ! $deadline['overdue'] && $deadline['days_left'] !== null && $deadline['days_left'] <= 1;
                            @endphp
                            <tr class="{{ $deadline['overdue'] ? 'table-danger' : ($nearing ? 'table-warning' : '') }}" data-pending="{{ $proposal->uuid }}" data-overdue="{{ $deadline['overdue'] ? 'yes' : 'no' }}">
                                <td>{{ $proposal->dpc_code ?? $proposal->citation() }}</td>
                                <td>{{ $deadline['stage']->label() }}</td>
                                <td>{{ $deadline['stage']->action() }}</td>
                                <td>
                                    @if ($deadline['due_at'] === null)
                                        <span class="text-muted">{{ __('dpc.stage.no_deadline') }}</span>
                                    @elseif ($deadline['overdue'])
                                        <strong>{{ __('dpc.stage.overdue', ['date' => $deadline['due_at']->format('d-m-Y')]) }}</strong>
                                    @else
                                        {{ __('dpc.stage.due', ['date' => $deadline['due_at']->format('d-m-Y')]) }}
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ app(\App\Domain\Dpc\Services\DpcStageService::class)->linkFor(auth()->user(), $proposal, $deadline['stage']) }}" class="btn btn-sm {{ $mine ? 'btn-primary' : 'btn-outline-secondary' }}">{{ __('dpc.stage.open') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
