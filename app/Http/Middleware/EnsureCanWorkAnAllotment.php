<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may reach the screens that move an allotted DPC — accept, decline,
 * schedule, reschedule.
 *
 * **The CGPSC workspace is gated on `role:chairman|member`, and the allottee is
 * not always either.** Since the business correction of 2026-09-07 the
 * qualification for being allotted a DPC is membership of *that DPC's committee*
 * and explicitly not a portal role — {@see AllotmentService}
 * records the reasoning, that *"sitting on one DPC's committee is not an office"*.
 * The Chairman may therefore allot a DPC to somebody holding no role at all.
 *
 * Gated on the role alone, that allotment dead-ended: it succeeded, and then the
 * allottee was refused the only screens that can move the DPC. It did not return to
 * the Chairman either, because nothing had lapsed — the DPC simply stopped, and the
 * controls the allottee was supposed to have looked as though they had disappeared.
 *
 * **This admits one more person and shows them nothing extra.** Every route behind
 * it authorizes again — `DpcAllotmentPolicy::act()` binds acceptance and scheduling
 * to the individual allottee, and the index query scopes rows to `allotted_to` for
 * anyone who is not the Chairman. What changes is only that the door opens for
 * somebody who holds a live allotment; what they find behind it is their own DPC.
 *
 * A returned or superseded allotment is not a live one, which is why `current()`
 * is the scope: once the Chairman has taken the DPC back, the door closes again.
 */
class EnsureCanWorkAnAllotment
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        if ($user->hasAnyRole([Role::Chairman->value, Role::Member->value])) {
            return $next($request);
        }

        $holdsOne = DpcAllotment::query()
            ->current()
            ->where('allotted_to', $user->id)
            ->exists();

        abort_unless($holdsOne, 403);

        return $next($request);
    }
}
