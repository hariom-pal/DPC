{{-- The pop-up after an action (MoM of 12 November 2026, point 1): what was done, and
     what comes next. Opened by js/next-step.js. Expects: $message, $next. --}}
<div class="modal fade" id="next-step-modal" tabindex="-1" aria-labelledby="next-step-modal-title" aria-hidden="true" data-next-step-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-success">
            <div class="modal-header bg-success-subtle">
                <h2 class="modal-title h5" id="next-step-modal-title">{{ __('ui.next_step.title') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('ui.close') }}"></button>
            </div>
            <div class="modal-body">
                @if ($message)<p class="mb-2">{{ $message }}</p>@endif
                <p class="mb-0 fw-semibold text-success" id="next-step-modal-next">{{ $next }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" data-bs-dismiss="modal">{{ __('ui.next_step.ok') }}</button>
            </div>
        </div>
    </div>
</div>
