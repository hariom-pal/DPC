{{-- Section-wise revision (2026-11-13): on a section revision the section says
     whether the AO may edit it; the AH's Edit unlocks it, with a reason. --}}
{{-- Only while the revision is open: once approved, no section is unlocked for anyone. --}}
@if ($position !== null && $position->isSectionRevision() && ($position->isDraft() || $position->isPendingApproval()))
    @if ($position->sectionUnlocked($sectionKey))
        <span class="badge text-bg-warning" data-section-state="{{ $sectionKey }}">{{ __('vacancymaster.section_revision.unlocked_badge') }}</span>
    @else
        <span class="badge text-bg-light border" data-section-state="{{ $sectionKey }}">{{ __('vacancymaster.section_revision.locked_badge') }}</span>
    @endif
@endif
@if ($canUnlock($sectionKey))
    <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="collapse" data-bs-target="#unlock-{{ $sectionKey }}"
            aria-expanded="false" aria-controls="unlock-{{ $sectionKey }}" title="{{ __('vacancymaster.section_revision.edit_title') }}"
            data-section-unlock="{{ $sectionKey }}">{{ __('vacancymaster.section_revision.edit') }}</button>
    <div class="collapse w-100" id="unlock-{{ $sectionKey }}">
        <form method="POST" action="{{ route($records.'.sections.unlock', [$department, $year, $post, $sectionKey]) }}" class="border rounded p-2 mt-2 bg-light">
            @csrf
            <label class="form-label small mb-1" for="unlock-reason-{{ $sectionKey }}">{{ __('vacancymaster.section_revision.reason') }}</label>
            <textarea class="form-control form-control-sm mb-2" id="unlock-reason-{{ $sectionKey }}" name="revision_reason" rows="2" maxlength="2000" required></textarea>
            <button class="btn btn-warning btn-sm">{{ __('vacancymaster.section_revision.unlock') }}</button>
        </form>
    </div>
@endif
