{{-- Rule 1.1 pre-submission confirmations, read-only. Shared by the proposal
     detail screen and the last step of the AH review walkthrough, so the
     Administrative Head sees what the department confirmed before signing. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <h2 class="h6">{{ __('dpc.verification.title') }}</h2>
                        <a href="{{ route('dpc-proposals.verification', $proposal) }}" class="btn btn-outline-secondary btn-sm">{{ __('dpc.verification.open_screen') }}</a>
                    </div>
                    @if (collect($verificationStatus)->every(fn ($entry) => $entry['latest'] === null))
                        <p class="small text-muted mb-0">{{ __('dpc.verification.not_recorded_legacy') }}</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($verificationStatus as $entry)
                                <li class="list-group-item px-0">
                                    @if ($entry['complete'])
                                        <span class="badge text-bg-success">{{ __('dpc.verification.status_confirmed') }}</span>
                                    @elseif ($entry['stale'])
                                        <span class="badge text-bg-warning">{{ __('dpc.verification.status_stale') }}</span>
                                    @elseif ($entry['latest'] !== null)
                                        <span class="badge text-bg-secondary">{{ __('dpc.verification.status_withdrawn') }}</span>
                                    @else
                                        <span class="badge text-bg-light text-dark">{{ __('dpc.verification.not_recorded') }}</span>
                                    @endif
                                    <strong>{{ $entry['item']->label() }}</strong>
                                    @if ($entry['latest'] !== null)
                                        <span class="small text-muted">
                                            {{ __('dpc.verification.by_at', [
                                                'name' => $entry['latest']->verifier?->name ?? '—',
                                                'at' => $entry['latest']->verified_at->format('d-m-Y H:i'),
                                            ]) }}
                                        </span>
                                        @if ($entry['latest']->previous_dpc_basis)
                                            <div class="small text-muted ms-4">
                                                {{ $entry['latest']->previous_dpc_basis->label() }}
                                                @if ($entry['latest']->previous_dpc_reference)
                                                    — {{ $entry['latest']->previous_dpc_reference }}
                                                @endif
                                                @if ($entry['latest']->hasDocument())
                                                    — <a href="{{ route('dpc-proposals.verification.minutes', [$proposal, $entry['latest']]) }}">{{ $entry['latest']->original_name }}</a>
                                                @endif
                                            </div>
                                        @endif
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
