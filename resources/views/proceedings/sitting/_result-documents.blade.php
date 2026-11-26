{{-- The result documents (2026-11-25): the Hindi proceedings and each sealed-cover
     recommendation, made when the result is approved, eSigned by every committee member
     and final once all have. Expects: $proposal. --}}
@php
    $resultProceeding = \App\Domain\Proceeding\Models\DpcProceeding::query()->where('dpc_proposal_id', $proposal->id)
        ->where('status', \App\Domain\Proceeding\Enums\DpcProceedingStatus::ResultApproved->value)->latest('id')->first();
    $resultDocs = app(\App\Domain\Proceeding\Services\ResultDocumentService::class);
    $resultViewer = auth()->user();
@endphp
@if ($resultProceeding !== null && $resultViewer !== null && $resultDocs->mayView($resultProceeding, $resultViewer))
    @php
        $docs = $resultDocs->documents($resultProceeding);
        $final = $resultDocs->isFinal($resultProceeding);
        $signed = $resultDocs->signatures($resultProceeding)->keyBy('user_id');
        $signRefusal = $resultDocs->signRefusal($resultProceeding, $resultViewer);
        $mayGenerate = $resultProceeding->conducted_by === $resultViewer->id || app(\App\Domain\Proceeding\Services\SealedCoverService::class)->isDpcChairman($proposal, $resultViewer);
    @endphp
    <div class="card border-0 shadow-sm mb-3" id="result-documents">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h2 class="h6 mb-0">{{ __('proceeding.result_documents.heading') }}</h2>
                @if ($docs->isNotEmpty())
                    <span class="badge {{ $final ? 'text-bg-success' : 'text-bg-warning' }}" data-result-documents-state="{{ $final ? 'final' : 'signing' }}">
                        {{ $final ? __('proceeding.result_documents.final') : __('proceeding.result_documents.signing', ['n' => $signed->count(), 'of' => count($resultDocs->signers($resultProceeding))]) }}
                    </span>
                @endif
            </div>
            @error('result_documents')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror

            @if ($docs->isEmpty())
                <p class="small text-muted mb-2">{{ __('proceeding.result_documents.none_yet') }}</p>
                @if ($mayGenerate)
                    <form method="POST" action="{{ route('result-documents.generate', $resultProceeding) }}" class="m-0">
                        @csrf<button class="btn btn-outline-primary btn-sm" id="generate-result-documents">{{ __('proceeding.result_documents.generate') }}</button>
                    </form>
                @endif
            @else
                <ul class="list-unstyled small mb-2">
                    @foreach ($docs as $doc)
                        <li class="mb-1" data-result-document="{{ $doc->kind }}">
                            <a href="{{ route('result-documents.show', [$resultProceeding, $doc]) }}" target="_blank" rel="noopener">
                                {{ $doc->kind === 'karyavahi'
                                    ? __('proceeding.result_documents.karyavahi', ['format' => __('proceeding.result_documents.formats.'.($doc->format ?? 'other'))])
                                    : __('proceeding.result_documents.sealed_cover', ['name' => $doc->officer_name, 'sn' => $doc->sn]) }}
                            </a>
                            <span class="text-muted">(PDF{{ $doc->finalized_at ? ', '.__('proceeding.result_documents.signed_copy') : '' }})</span>
                        </li>
                    @endforeach
                </ul>

                <p class="small text-muted mb-1" id="result-documents-portal-note">{{ __('proceeding.result_documents.portal_note') }}</p>
                <div class="small mb-2" id="result-document-signers">
                    <span class="fw-semibold">{{ __('proceeding.result_documents.signers') }}:</span>
                    @foreach ($resultDocs->signers($resultProceeding) as $signer)
                        @php($mark = $signer['user_id'] !== null ? $signed->get($signer['user_id']) : null)
                        <span class="badge {{ $mark ? 'text-bg-success' : 'text-bg-light border' }}" data-signer-state="{{ $mark ? 'signed' : 'pending' }}">
                            {{ $signer['name'] }} — {{ $mark ? __('proceeding.result_documents.signed_on', ['at' => $mark->signed_at->format('d-m-Y H:i')]) : __('proceeding.result_documents.not_signed') }}
                        </span>
                    @endforeach
                </div>

                @if ($signRefusal === null)
                    <form method="POST" action="{{ route('result-documents.sign', $resultProceeding) }}" class="m-0"
                          onsubmit="return confirm(@js(__('proceeding.result_documents.sign_confirm')))">
                        @csrf<button class="btn btn-primary btn-sm" id="sign-result-documents">{{ __('proceeding.result_documents.sign') }}</button>
                    </form>
                @endif
            @endif
        </div>
    </div>
@endif
