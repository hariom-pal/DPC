{{-- The four sections and the candidate's decision on each. --}}
<ol class="list-unstyled d-flex flex-wrap gap-2 mb-3" id="verification-progress">
    @foreach (\App\Domain\CandidateVerification\Enums\VerificationSection::cases() as $step)
        @php
            $decided = $decisions[$step->value] ?? null;
            $class = $decided === 'agreed' ? 'text-bg-success' : ($decided === 'grievance' ? 'text-bg-warning' : 'text-bg-light border');
        @endphp
        <li>
            <a href="{{ route('candidate-verification.section', [$participant->uuid, $step->value]) }}"
               class="badge {{ $class }} text-decoration-none p-2 {{ isset($section) && $section === $step ? 'border border-2 border-primary' : '' }}"
               data-progress="{{ $step->value }}" data-decision="{{ $decided ?? 'none' }}">
                {{ $loop->iteration }}. {{ $step->label() }} — {{ $participant->decisionLabel($decided) }}
            </a>
        </li>
    @endforeach
    <li><a href="{{ route('candidate-verification.review', $participant->uuid) }}" class="badge text-bg-primary text-decoration-none p-2">{{ __('candidate.verification.actions.review') }}</a></li>
</ol>
