<?php

declare(strict_types=1);

namespace App\Http\Controllers\SelfInspection;

use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\SelfInspection\Enums\SelfInspectionAuthDriver;
use App\Domain\SelfInspection\Enums\SelfInspectionFactor;
use App\Domain\SelfInspection\Requests\IdentifySelfInspectionRequest;
use App\Domain\SelfInspection\Services\SelfInspectionAccessGuard;
use App\Domain\SelfInspection\Services\SelfInspectionAuthenticator;
use App\Domain\SelfInspection\Services\SelfInspectionViewService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Self-Inspection & Assessment — PPT S49, S50, S51. M10.
 *
 * **Three screens and two buttons, which is exactly what the deck has.** S49
 * introduces the facility, S50 identifies the officer, S51 shows their own row
 * with a deadline notice, `CLOSE` and `PRINT`. There is **no RAISE OBJECTION**:
 * S51 directs the officer to the department **in person** and states that the
 * Commission will consider no representation. Building an online objection would
 * be adding a feature the requirement does not contain (BD-20).
 *
 * **The session holds an id, not a person.** A matched officer is remembered by
 * the evaluation-row ids they matched, in the session, with an expiry — no `User`
 * row is created, nothing is written to `users`, and no guard is involved. That
 * keeps M10 out of M1's authentication entirely, which is what lets an individual
 * with no account reach it at all.
 *
 * **Inspectability is re-checked on every request**, not trusted from the session:
 * a sitting can approve its result while an officer has the screen open, and when
 * it does the screen closes.
 */
class SelfInspectionController extends Controller
{
    /** Session key holding the evaluation-row ids the officer matched. */
    private const SESSION_ROWS = 'self_inspection.rows';

    /** Session key holding the absolute expiry of that match. */
    private const SESSION_EXPIRES = 'self_inspection.expires_at';

    /**
     * Session key holding the mobile number a code was sent to.
     *
     * Held in the session rather than posted back in a hidden field, so the number
     * the code is verified against is the number it was sent to. A hidden field
     * would let a caller request a code for their own number and then verify it
     * against somebody else's.
     */
    private const SESSION_MOBILE = 'self_inspection.mobile';

    public function __construct(
        private readonly SelfInspectionAuthenticator $authenticator,
        private readonly SelfInspectionAccessGuard $guard,
        private readonly SelfInspectionViewService $presenter,
    ) {}

    /**
     * S49 — *"विभागीय अधिकारीयों व कर्मचारियों को स्वयं के डाटा अवलोकन की सुविधा"*.
     */
    public function landing(): View
    {
        return view('self-inspection.landing', [
            'enabled' => $this->guard->enabled(),
            'pending' => $this->authenticator->unavailable(),
        ]);
    }

    /**
     * S50 — the identification form.
     */
    public function create(Request $request): View|RedirectResponse
    {
        if ($this->matchedRows($request)->isNotEmpty()) {
            return redirect()->route('self-inspection.show');
        }

        $driver = SelfInspectionAuthDriver::configured();

        return view('self-inspection.identify', [
            'enabled' => $this->guard->enabled(),
            'pending' => $this->authenticator->unavailable(),
            'factors' => SelfInspectionFactor::configured(),

            // Which of the two forms to draw: the mobile box, or the code box for a
            // number a code has already gone to.
            'usesOtp' => $driver->usesOtp(),
            'awaitingCode' => $driver->usesOtp() && $request->session()->has(self::SESSION_MOBILE),
            'sentTo' => $this->maskMobile((string) $request->session()->get(self::SESSION_MOBILE, '')),
        ]);
    }

    public function store(IdentifySelfInspectionRequest $request): RedirectResponse
    {
        if (! $this->guard->enabled() || $this->authenticator->unavailable() !== null) {
            return redirect()->route('self-inspection.identify');
        }

        if (SelfInspectionAuthDriver::configured()->usesOtp()) {
            return $this->handleOtp($request);
        }

        $rows = $this->authenticator->identify($request->answers(), $request);

        if ($rows->isEmpty()) {
            // One message for every kind of failure. Saying *which* factor did
            // not match would let a caller confirm a name, then a date of birth,
            // then a posting date, one request at a time — which is a lookup
            // service, not a login.
            return back()
                ->withInput($request->except(array_map(
                    static fn (SelfInspectionFactor $factor): string => $factor->value,
                    array_filter(
                        SelfInspectionFactor::configured(),
                        static fn (SelfInspectionFactor $factor): bool => $factor->isDate(),
                    ),
                )))
                ->withErrors([
                    'self_inspection' => 'No record under consideration matches those details. '
                        .'Check them against your department\'s own record and try again; if they are '
                        .'correct and this still fails, contact your department.',
                ]);
        }

        $this->rememberMatch($request, $rows);

        return redirect()->route('self-inspection.show');
    }

    /**
     * The two-step mobile-and-code identification (business instruction,
     * 2026-09-07).
     *
     * **Step one always reports success.** Telling the caller that a number is not
     * on any list would turn this screen into a lookup service — feed it numbers
     * and it says which belong to somebody under consideration for promotion.
     * `SelfInspectionAuthenticator::requestCode()` issues a code only for a number
     * that matches and records a failed attempt for one that does not; this says the
     * same sentence either way.
     *
     * **Step two is the one that can fail**, and it fails with one message for both
     * a wrong code and a number that has since left every list — for the same
     * reason the factor driver has one message for every kind of failure.
     */
    private function handleOtp(IdentifySelfInspectionRequest $request): RedirectResponse
    {
        // "Use a different number" — forget the pending one and start again, so a
        // code is never verified against a number other than the one it went to.
        if ($request->boolean('restart')) {
            $request->session()->forget(self::SESSION_MOBILE);

            return redirect()->route('self-inspection.identify');
        }

        if (! $request->isCodeStep()) {
            $mobile = (string) $request->validated('mobile');

            if (! $this->authenticator->codesCanBeDelivered()) {
                return back()->withErrors(['self_inspection' => __('selfinspection.otp.sms_unavailable')]);
            }

            try {
                $this->authenticator->requestCode($mobile, $request);
            } catch (OtpThrottled $e) {
                return back()->withInput()->withErrors(['self_inspection' => $e->getMessage()]);
            }

            $request->session()->put(self::SESSION_MOBILE, $mobile);

            return redirect()
                ->route('self-inspection.identify')
                ->with('status', __('selfinspection.otp.code_sent', ['mobile' => $this->maskMobile($mobile)]));
        }

        $mobile = (string) $request->session()->get(self::SESSION_MOBILE, '');

        if ($mobile === '') {
            // The code box was reached without a number in the session — a stale
            // tab, or a posted form. Start again rather than guess.
            return redirect()->route('self-inspection.identify');
        }

        $rows = $this->authenticator->identifyByOtp($mobile, (string) $request->validated('code'), $request);

        if ($rows->isEmpty()) {
            return back()->withErrors([
                'self_inspection' => __('selfinspection.otp.failed'),
            ]);
        }

        $request->session()->forget(self::SESSION_MOBILE);
        $this->rememberMatch($request, $rows);

        return redirect()->route('self-inspection.show');
    }

    /**
     * Put the matched rows in the session, with their expiry.
     *
     * @param  \Illuminate\Support\Collection<int, DpcEvaluationRow>  $rows
     */
    private function rememberMatch(Request $request, $rows): void
    {
        $request->session()->put(self::SESSION_ROWS, $rows->pluck('id')->all());
        $request->session()->put(
            self::SESSION_EXPIRES,
            now()->addMinutes((int) config('dpc.self_inspection.session_lifetime_minutes', 20))->toIso8601String(),
        );
    }

    /**
     * A mobile number with its middle hidden, for telling an officer where a code
     * went without printing the number back at whoever asked for it.
     */
    private function maskMobile(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        return strlen($digits) < 10 ? '' : substr($digits, 0, 2)."\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}".substr($digits, -2);
    }

    /**
     * S51 — the officer's own data, with the deadline notice.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $rows = $this->matchedRows($request);

        if ($rows->isEmpty()) {
            return redirect()->route('self-inspection.identify');
        }

        $selected = $this->selected($request, $rows);

        $this->authenticator->recordView($selected, 'view', $request);

        return view('self-inspection.show', [
            ...$this->presenter->present($selected),
            'rows' => $rows,
            'selected' => $selected,
            'presenter' => $this->presenter,
        ]);
    }

    /**
     * S51's PRINT — the same data, laid out for paper.
     *
     * A separate route rather than a browser print stylesheet, because it is
     * recorded: an officer may present this later as what the record said on the
     * day, and the access log is what makes that claim checkable.
     */
    public function print(Request $request): View|RedirectResponse
    {
        $rows = $this->matchedRows($request);

        if ($rows->isEmpty()) {
            return redirect()->route('self-inspection.identify');
        }

        $selected = $this->selected($request, $rows);

        $this->authenticator->recordView($selected, 'print', $request);

        return view('self-inspection.print', [
            ...$this->presenter->present($selected),
            'presenter' => $this->presenter,
            'printed_at' => now(),
        ]);
    }

    /**
     * S51's CLOSE.
     */
    public function close(Request $request): RedirectResponse
    {
        $request->session()->forget([self::SESSION_ROWS, self::SESSION_EXPIRES]);

        return redirect()->route('self-inspection.landing')
            ->with('status', 'You have been signed out of Self-Inspection.');
    }

    /**
     * The rows this session matched, filtered to those **still** inspectable.
     *
     * Re-queried and re-checked on every request. A session is a record of a
     * successful identification, not a standing permission: the proposal can move
     * on, and the guard's rules are evaluated against its current state.
     *
     * @return Collection<int, DpcEvaluationRow>
     */
    private function matchedRows(Request $request): Collection
    {
        /** @var Collection<int, DpcEvaluationRow> $empty */
        $empty = DpcEvaluationRow::query()->whereRaw('1 = 0')->get();

        $expiry = $request->session()->get(self::SESSION_EXPIRES);

        if (! is_string($expiry) || Carbon::parse($expiry)->isPast()) {
            return $empty;
        }

        /** @var list<int> $ids */
        $ids = (array) $request->session()->get(self::SESSION_ROWS, []);

        if ($ids === []) {
            return $empty;
        }

        return DpcEvaluationRow::query()
            ->with(['crGrades', 'loyaltyFlags', 'propertyFlags', 'reservationCategory', 'proposal.department'])
            ->whereKey($ids)
            ->get()
            ->filter(fn (DpcEvaluationRow $row): bool => $this->guard->isInspectable($row))
            ->values();
    }

    /**
     * Which row the officer is looking at.
     *
     * An officer can be under consideration in more than one DPC at once — a
     * promotion to two different posts, or a Review of a DPC they were in. The
     * screen therefore lists what matched and defaults to the first; `?row=` picks
     * another, and a value not in the matched set is ignored rather than trusted.
     *
     * @param  Collection<int, DpcEvaluationRow>  $rows
     */
    private function selected(Request $request, Collection $rows): DpcEvaluationRow
    {
        $requested = $request->integer('row');

        $selected = $rows->firstWhere('id', $requested);

        return $selected instanceof DpcEvaluationRow ? $selected : $rows->firstOrFail();
    }
}
