{{-- An uploaded document as the MoM of 12 November 2026 asks: "Uploaded", what it is,
     and its file name, which opens it. Expects: $proposal, $row, $document. --}}
<div data-uploaded-document="{{ $document->uuid }}">
    <span class="badge text-bg-success">{{ __('dpc.employee_details.documents.uploaded') }}</span>
    <span class="text-muted">{{ $document->kind->label() }}:</span>
    <a href="{{ route('dpc-proposals.employees.documents.download', [$proposal, $row, $document]) }}" target="_blank" rel="noopener">{{ $document->original_name }}</a>
</div>
