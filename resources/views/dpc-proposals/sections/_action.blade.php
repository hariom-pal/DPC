{{--
    The action in a section card's header: either the Manage link, or a stated
    lock — never nothing.

    Raised from UAT (2026-08-23). Each of the five sections used to render its
    own `@if ($proposal->status->isEditable() && $isAO)`, so once a proposal was
    submitted the button simply disappeared and the officer was left to guess
    whether the section was locked or the screen was broken. The Seniority List
    upload was reported as a defect on exactly that basis.

    `$isAO ?? false` rather than `$isAO`: the AH review walkthrough
    (`review.blade.php`) includes these same partials and defines only `$isAH`.
    The old expression got away with it because `isEditable()` short-circuited
    first for the submitted proposals that screen normally shows — it would have
    raised an undefined-variable warning the moment an AH opened the walkthrough
    on a draft.

    Expects: $proposal. Optional: $isAO, $manageRoute, $manageLabel.
--}}
@php($lockReason = $proposal->status->lockReason())

@if ($lockReason !== null)
    <span class="badge text-bg-secondary flex-shrink-0" title="{{ $lockReason }}">{{ __('ui.locked') }}</span>
@elseif (($isAO ?? false) && ! empty($manageRoute))
    <a href="{{ route($manageRoute, $proposal) }}" class="btn btn-outline-primary btn-sm flex-shrink-0">
        {{ $manageLabel ?? __('ui.screen.manage') }}
    </a>
@endif
