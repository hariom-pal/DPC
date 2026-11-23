<?php

declare(strict_types=1);

namespace App\Http\Controllers\Candidate;

use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\CandidateVerification\Enums\VerificationSection;
use App\Domain\CandidateVerification\Models\Grievance;
use App\Domain\CandidateVerification\Models\VerificationParticipant;
use App\Domain\CandidateVerification\Models\VerificationSnapshot;
use App\Domain\CandidateVerification\Services\CandidateAuthenticator;
use App\Domain\CandidateVerification\Services\CandidateOutcome;
use App\Domain\CandidateVerification\Services\CandidateRecordView;
use App\Domain\CandidateVerification\Services\CandidateSession;
use App\Domain\CandidateVerification\Services\CandidateSignIn;
use App\Domain\CandidateVerification\Services\CandidateVerificationService;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Integrations\Mock\MockSignatureProvider;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The candidates' own verification portal (2026-11-19): sign in by registered mobile
 * and OTP, the four sections of their own record, I Agree or a grievance on each, a
 * final review, and one final submission signed with eSign. Outside the staff
 * session: a candidate reaches only the verifications their session holds, and every
 * other UUID is a 404. Nothing here changes a departmental record.
 */
class CandidateVerificationController extends Controller
{
    private const MOBILE = 'candidate_verification_mobile';

    private const ESIGN = 'candidate_verification_esign';

    public function __construct(
        private readonly CandidateSession $session,
        private readonly CandidateAuthenticator $auth,
        private readonly CandidateVerificationService $verification,
        private readonly CandidateRecordView $view,
    ) {}

    // ----------------------------------------------------------- sign in

    public function login(Request $request): View|RedirectResponse
    {
        if ($this->session->rows($request) !== null) {
            return redirect()->route('candidate-verification.dashboard');
        }

        return view('candidate-verification.login');
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['mobile' => ['required', 'string', 'max:20']]);
        $mobile = CandidateAuthenticator::normaliseMobile($data['mobile']);

        try {
            $this->auth->requestCode($data['mobile'], $request);
        } catch (OtpThrottled) {
            $request->session()->put(self::MOBILE, $mobile);

            return redirect()->route('candidate-verification.code')->with('status', __('candidate.verification.login.wait'));
        } catch (OtpDeliveryFailed) {
            return back()->withErrors(['mobile' => __('candidate.verification.login.unavailable')]);
        }

        // The same answer whether or not the number is registered.
        $request->session()->put(self::MOBILE, $mobile);

        return redirect()->route('candidate-verification.code')->with('status', __('candidate.verification.login.sent'));
    }

    public function codeForm(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has(self::MOBILE)) {
            return redirect()->route('candidate-verification.login');
        }

        $mobile = (string) $request->session()->get(self::MOBILE, '');

        return view('candidate-verification.code', ['tail' => $mobile === '' ? null : substr($mobile, -4)]);
    }

    /** A new code to the number already entered (2026-11-20); the same answers as the first request. */
    public function resendCode(Request $request): RedirectResponse
    {
        $mobile = (string) $request->session()->get(self::MOBILE, '');
        if ($mobile === '') {
            return redirect()->route('candidate-verification.login');
        }

        try {
            $this->auth->requestCode($mobile, $request);
        } catch (OtpThrottled) {
            return redirect()->route('candidate-verification.code')->with('status', __('candidate.verification.login.wait'));
        } catch (OtpDeliveryFailed) {
            return redirect()->route('candidate-verification.code')->withErrors(['code' => __('candidate.verification.login.unavailable')]);
        }

        return redirect()->route('candidate-verification.code')->with('status', __('candidate.verification.login.sent'));
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $groups = $this->auth->verify((string) $request->session()->get(self::MOBILE, ''), $data['code'], $request);

        if ($groups === null || $groups === []) {
            return back()->withErrors(['code' => __('candidate.verification.login.failed')]);
        }

        $request->session()->forget(self::MOBILE);

        if (count($groups) === 1) {
            // The officer is known: their eSign checks the name before the portal opens.
            $this->session->awaitSignature($request, array_values($groups)[0]);

            return redirect()->route('candidate-verification.sign-in');
        }

        // A shared mobile: the Employee ID decides, never the first officer found.
        $this->session->pending($request, array_merge(...array_values($groups)));

        return redirect()->route('candidate-verification.identify');
    }

    public function identifyForm(Request $request): View|RedirectResponse
    {
        return $this->session->pendingRows($request) === null
            ? redirect()->route('candidate-verification.login')
            : view('candidate-verification.identify');
    }

    public function identify(Request $request, SecurityLog $security): RedirectResponse
    {
        $pending = $this->session->pendingRows($request);
        if ($pending === null) {
            return redirect()->route('candidate-verification.login')->withErrors(['mobile' => __('candidate.verification.login.expired')]);
        }

        $data = $request->validate(['employee_number' => ['required', 'string', 'max:60']]);
        $chosen = $this->auth->choose($pending, $data['employee_number']);

        if ($chosen === []) {
            $security->record('candidate_verification.employee_mismatch', null, $request);

            return back()->withErrors(['employee_number' => __('candidate.verification.login.identify_failed')]);
        }

        $this->session->awaitSignature($request, $chosen);

        return redirect()->route('candidate-verification.sign-in');
    }

    /**
     * The sign-in's eSign (2026-11-20): the name on the certificate is checked against
     * the candidate's account before the portal opens. The simulator's screen outside
     * production while CSC DigiSign is off; the provider itself otherwise.
     */
    public function signInForm(Request $request, CandidateSignIn $signIn): View|RedirectResponse
    {
        $rows = $this->session->signatureRows($request);
        if ($rows === null) {
            return redirect()->route('candidate-verification.login')->withErrors(['mobile' => __('candidate.verification.login.expired')]);
        }

        if (! $this->simulated()) {
            return $this->completeSignIn($request, $signIn, $rows);
        }

        return view('candidate-verification.sign-in', [
            'name' => (string) DpcEvaluationRow::query()->whereIn('id', $rows)->value('name'),
        ]);
    }

    public function signIn(Request $request, CandidateSignIn $signIn): RedirectResponse
    {
        abort_unless($this->simulated(), 404);
        $rows = $this->session->signatureRows($request);
        if ($rows === null) {
            return redirect()->route('candidate-verification.login')->withErrors(['mobile' => __('candidate.verification.login.expired')]);
        }

        if ($request->input('decision') !== 'sign') {
            $this->session->end($request);

            return redirect()->route('candidate-verification.login')->withErrors(['mobile' => __('candidate.verification.login.esign_cancelled')]);
        }

        $name = trim((string) $request->validate(['name' => ['required', 'string', 'max:255']])['name']);
        app()->instance(MockSignatureProvider::SIMULATED_NAME, $name);

        try {
            return $this->completeSignIn($request, $signIn, $rows);
        } finally {
            app()->forgetInstance(MockSignatureProvider::SIMULATED_NAME);
        }
    }

    /** @param list<int> $rows */
    private function completeSignIn(Request $request, CandidateSignIn $signIn, array $rows): RedirectResponse
    {
        try {
            $signIn->sign($rows);
        } catch (WorkflowException $e) {
            return redirect()->route('candidate-verification.sign-in')->withErrors(['name' => $e->getMessage()]);
        }

        $this->session->start($request, $rows);

        return redirect()->route('candidate-verification.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->session->end($request);
        $request->session()->regenerate();

        return redirect()->route('candidate-verification.login');
    }

    // ----------------------------------------------------------- the candidate's own

    public function dashboard(Request $request, CandidateOutcome $outcome): View|RedirectResponse
    {
        $rows = $this->session->rows($request);
        $participants = $this->session->participants($request);
        if ($rows === null || $participants === null) {
            return $this->expired();
        }

        // Every DPC of theirs the portal shows — open, under review or concluded —
        // with its verification where there is one, its stage and, once published, the result.
        return view('candidate-verification.dashboard', [
            'rows' => $rows,
            'participants' => $participants->keyBy('dpc_evaluation_row_id'),
            'decisions' => $participants->mapWithKeys(fn (VerificationParticipant $p): array => [$p->id => $this->verification->decisions($p)]),
            'stages' => $rows->mapWithKeys(fn (DpcEvaluationRow $row): array => [$row->id => $outcome->stage($row)]),
            'results' => $rows->mapWithKeys(fn (DpcEvaluationRow $row): array => [$row->id => $outcome->result($row)]),
        ]);
    }

    /** A DPC of the candidate's with no verification of theirs (an older one): read only. */
    public function record(Request $request, int $row, string $section): View|RedirectResponse
    {
        if ($this->session->rows($request) === null) {
            return $this->expired();
        }
        $own = $this->session->row($request, $row) ?? throw new NotFoundHttpException;
        $section = $this->sectionOf($section);

        return view('candidate-verification.record', [
            'row' => $own,
            'section' => $section,
            'data' => $this->view->section($own, $section),
        ]);
    }

    public function section(Request $request, string $participant, string $section): View|RedirectResponse
    {
        $own = $this->own($request, $participant);
        if ($own === null) {
            return $this->expired();
        }
        $section = $this->sectionOf($section);
        $this->verification->opened($own);

        return view('candidate-verification.section', [
            'participant' => $own,
            'section' => $section,
            'data' => $this->view->section($own->row, $section),
            'decisions' => $this->verification->decisions($own),
            // Several a section (2026-11-22), one for each year that is wrong.
            'grievances' => Grievance::query()->where('dpc_verification_participant_id', $own->id)->where('section', $section->value)->orderBy('id')->get(),
            'corrected' => VerificationSnapshot::query()->where('dpc_verification_participant_id', $own->id)->where('version', '>', 1)->latest('version')->first(),
        ]);
    }

    public function agree(Request $request, string $participant, string $section): RedirectResponse
    {
        $own = $this->own($request, $participant);
        if ($own === null) {
            return $this->expired();
        }
        $section = $this->sectionOf($section);

        try {
            $this->verification->agree($own, $section);
        } catch (WorkflowException $e) {
            return back()->withErrors(['verification' => $e->getMessage()]);
        }

        return $this->next($own, $section)->with('status', __('candidate.verification.done.agreed'));
    }

    public function grievanceForm(Request $request, string $participant, string $section): View|RedirectResponse
    {
        $own = $this->own($request, $participant);
        if ($own === null) {
            return $this->expired();
        }
        $section = $this->sectionOf($section);

        // Only while the candidate may still act: after their submission or the deadline
        // the grievance screen is gone, and the section reads only (2026-11-20).
        if (! CandidateOutcome::actionable($own)) {
            return redirect()->route('candidate-verification.section', [$own->uuid, $section->value]);
        }

        return view('candidate-verification.grievance', [
            'participant' => $own,
            'section' => $section,
            'years' => $this->view->yearsFor($own->row, $section),
            // A new grievance, or the draft named to change (2026-11-22).
            'grievance' => $request->filled('grievance')
                ? Grievance::query()->where('dpc_verification_participant_id', $own->id)->where('section', $section->value)->where('uuid', (string) $request->query('grievance'))->firstOrFail()
                : null,
        ]);
    }

    public function saveGrievance(Request $request, string $participant, string $section): RedirectResponse
    {
        $own = $this->own($request, $participant);
        if ($own === null) {
            return $this->expired();
        }
        $section = $this->sectionOf($section);
        $maxKb = max(1, (int) config('dpc.candidate_verification.max_document_kb', 5120));

        $data = $request->validate([
            'category' => ['required', 'string', Rule::in($section->categories())],
            'related_year' => ['nullable', 'integer', 'between:1950,2100'],
            'remark' => ['required', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.$maxKb],
            'undertaking' => ['accepted'],
            'grievance' => ['nullable', 'string', 'uuid'],
        ], [
            'undertaking.accepted' => __('candidate.grievance.accept'),
        ]);

        try {
            $this->verification->saveGrievance($own, $section, [
                'category' => $data['category'],
                'related_year' => $data['related_year'] ?? null,
                'remark' => $data['remark'],
            ], $request->file('document'), $data['grievance'] ?? null);
        } catch (WorkflowException $e) {
            return back()->withInput()->withErrors(['document' => $e->getMessage()]);
        }

        return $this->next($own, $section)->with('status', __('candidate.verification.done.grievance_saved'));
    }

    public function removeGrievance(Request $request, string $participant, string $section): RedirectResponse
    {
        $own = $this->own($request, $participant);
        if ($own === null) {
            return $this->expired();
        }

        try {
            $this->verification->removeGrievance($own, $this->sectionOf($section), $request->filled('grievance') ? (string) $request->input('grievance') : null);
        } catch (WorkflowException $e) {
            return back()->withErrors(['verification' => $e->getMessage()]);
        }

        return redirect()->route('candidate-verification.section', [$own->uuid, $section])->with('status', __('candidate.verification.done.grievance_removed'));
    }

    public function document(Request $request, string $participant, string $section): StreamedResponse|RedirectResponse
    {
        $own = $this->own($request, $participant);
        if ($own === null) {
            return $this->expired();
        }

        $grievance = Grievance::query()->where('dpc_verification_participant_id', $own->id)->where('section', $this->sectionOf($section)->value)
            ->when($request->filled('grievance'), fn ($q) => $q->where('uuid', (string) $request->query('grievance')))->orderBy('id')->first();
        abort_if($grievance === null || ! Storage::disk($grievance->disk)->exists($grievance->path), 404);

        return Storage::disk($grievance->disk)->download($grievance->path, $grievance->original_name);
    }

    public function review(Request $request, string $participant): View|RedirectResponse
    {
        $own = $this->own($request, $participant);
        if ($own === null) {
            return $this->expired();
        }

        return view('candidate-verification.review', [
            'participant' => $own,
            'decisions' => $this->verification->decisions($own),
            'grievances' => Grievance::query()->where('dpc_verification_participant_id', $own->id)->orderBy('id')->get()->groupBy(fn (Grievance $g): string => $g->section->value),
            'blockers' => $own->hasSubmitted() ? [] : $this->verification->blockers($own),
        ]);
    }

    public function submit(Request $request, string $participant): RedirectResponse
    {
        $own = $this->own($request, $participant);
        if ($own === null) {
            return $this->expired();
        }

        $request->validate(['declaration' => ['accepted'], 'consent' => ['accepted']]);

        if (($blockers = $this->verification->blockers($own)) !== []) {
            return back()->withErrors(['verification' => $blockers[0]]);
        }

        if ($this->simulated()) {
            $request->session()->put(self::ESIGN, ['participant' => $own->uuid, 'expires' => now()->addMinutes(10)->timestamp]);

            return redirect()->route('candidate-verification.esign', $own->uuid);
        }

        return $this->perform($own);
    }

    /** The eSign provider's screen, simulated outside production while CSC DigiSign is off. */
    public function esignForm(Request $request, string $participant): View|RedirectResponse
    {
        abort_unless($this->simulated(), 404);
        $own = $this->own($request, $participant);
        if ($own === null) {
            return $this->expired();
        }

        return view('candidate-verification.esign', ['participant' => $own]);
    }

    public function esign(Request $request, string $participant): RedirectResponse
    {
        abort_unless($this->simulated(), 404);
        $own = $this->own($request, $participant);
        if ($own === null) {
            return $this->expired();
        }

        $pending = $request->session()->pull(self::ESIGN);
        if (! is_array($pending) || ($pending['participant'] ?? null) !== $own->uuid || (int) ($pending['expires'] ?? 0) < now()->timestamp) {
            return redirect()->route('candidate-verification.review', $own->uuid)->withErrors(['verification' => __('esign.errors.esign_failed')]);
        }

        if ($request->input('decision') !== 'sign') {
            return redirect()->route('candidate-verification.review', $own->uuid)->withErrors(['verification' => __('candidate.verification.review.esign_cancelled')]);
        }

        $name = trim((string) $request->validate(['name' => ['required', 'string', 'max:255']])['name']);
        app()->instance(MockSignatureProvider::SIMULATED_NAME, $name);

        try {
            return $this->perform($own);
        } finally {
            app()->forgetInstance(MockSignatureProvider::SIMULATED_NAME);
        }
    }

    public function acknowledgement(Request $request, string $participant): Response|RedirectResponse
    {
        $own = $this->own($request, $participant);
        if ($own === null) {
            return $this->expired();
        }
        abort_unless($own->hasSubmitted(), 404);

        $html = view('candidate-verification.acknowledgement', ['participant' => $own, 'snapshot' => (array) $own->submitted_snapshot])->render();
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return response((string) $pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="verification-acknowledgement-'.$own->uuid.'.pdf"',
        ]);
    }

    // ----------------------------------------------------------- helpers

    private function perform(VerificationParticipant $participant): RedirectResponse
    {
        try {
            $this->verification->submit($participant);
        } catch (WorkflowException $e) {
            return redirect()->route('candidate-verification.review', $participant->uuid)->withErrors(['verification' => $e->getMessage()]);
        }

        return redirect()->route('candidate-verification.review', $participant->uuid)->with('status', __('candidate.verification.done.submitted'));
    }

    private function own(Request $request, string $uuid): ?VerificationParticipant
    {
        $participants = $this->session->participants($request);
        if ($participants === null) {
            return null;
        }

        return $participants->firstWhere('uuid', $uuid) ?? throw new NotFoundHttpException;
    }

    private function sectionOf(string $section): VerificationSection
    {
        return VerificationSection::tryFrom($section) ?? throw new NotFoundHttpException;
    }

    private function next(VerificationParticipant $participant, VerificationSection $section): RedirectResponse
    {
        $next = $section->next();

        return $next === null
            ? redirect()->route('candidate-verification.review', $participant->uuid)
            : redirect()->route('candidate-verification.section', [$participant->uuid, $next->value]);
    }

    private function expired(): RedirectResponse
    {
        return redirect()->route('candidate-verification.login')->withErrors(['mobile' => __('candidate.verification.login.expired')]);
    }

    private function simulated(): bool
    {
        // The mock provider is bound exactly while CSC DigiSign is off; never in production.
        return ! app()->environment('production') && Feature::CscDigiSign->disabled();
    }
}
