<?php

declare(strict_types=1);

namespace App\Http\Controllers\SelfInspection;

use App\Domain\Dpc\Models\DpcEvaluationRow;
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

        return view('self-inspection.identify', [
            'enabled' => $this->guard->enabled(),
            'pending' => $this->authenticator->unavailable(),
            'factors' => SelfInspectionFactor::configured(),
        ]);
    }

    public function store(IdentifySelfInspectionRequest $request): RedirectResponse
    {
        if (! $this->guard->enabled() || $this->authenticator->unavailable() !== null) {
            return redirect()->route('self-inspection.identify');
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

        $request->session()->put(self::SESSION_ROWS, $rows->pluck('id')->all());
        $request->session()->put(
            self::SESSION_EXPIRES,
            now()->addMinutes((int) config('dpc.self_inspection.session_lifetime_minutes', 20))->toIso8601String(),
        );

        return redirect()->route('self-inspection.show');
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
