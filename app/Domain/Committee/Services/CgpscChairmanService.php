<?php

declare(strict_types=1);

namespace App\Domain\Committee\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Master\Models\Designation;
use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only way the CGPSC Chairman is appointed, amended or read (change register
 * of 2026-08-26, item 9 as clarified).
 *
 * **Appointing a successor is two writes that must both happen or neither.** The
 * incumbent's tenure is closed and the successor's is opened, and doing them
 * separately produces either two serving Chairmen — which `unique(active_flag)`
 * would reject, aborting a half-finished appointment — or none, which would leave
 * every DPC committee unable to seat the Chairman item 9 says is mandatory.
 *
 * So `appoint()` is one transaction, and the incumbent is read `lockForUpdate()`:
 * two Administrators appointing at the same moment is exactly how a "check then
 * write" produces the state the unique index exists to forbid.
 *
 * **This grants no role, and that separation is deliberate.** It would be one line
 * to `assignRole(Role::Chairman)` here, and it would be wrong. This record states
 * *who holds the office*; the role decides *which account may open the
 * Commission's screens*. They are usually the same person and are not the same
 * fact — an incoming Chairman may be recorded before their account exists, and an
 * outgoing one keeps their account while ceasing to hold the office. Conflating
 * them would mean appointing a Chairman silently changed somebody's permissions,
 * which is the sort of side effect an audit trail can record and nobody expects.
 */
class CgpscChairmanService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * The Chairman serving now, or null if the Commission has not recorded one.
     *
     * Null is a real answer and every caller must handle it: a fresh installation
     * has no Chairman until the Administrator appoints one, and item 9's committee
     * seat cannot be filled until they do. `DpcCommitteeService` refuses to compose
     * a committee in that state and says why, rather than inventing a Chairman.
     */
    public function serving(): ?CgpscChairman
    {
        return CgpscChairman::query()->serving()->with('user')->first();
    }

    /**
     * The Chairman who held the office on a given date.
     *
     * Used when a committee is composed against a proposal's own date, so a
     * historical committee seats the Chairman who actually held the office then.
     * Falls back to nothing rather than to the current Chairman — "we do not know
     * who was Chairman on that date" is true, and naming today's incumbent would
     * be a fabrication on a record that may be signed.
     */
    public function servingOn(Carbon $date): ?CgpscChairman
    {
        return CgpscChairman::query()
            ->servingOn($date)
            ->with('user')
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Appoint a Chairman, closing the incumbent's tenure.
     *
     * `$effectiveFrom` defaults to today. A back-dated appointment is permitted and
     * is the Administrator's explicit choice, but it may not precede the
     * incumbent's own start — a tenure that begins before the one it succeeds is
     * not a succession, and the resulting overlap would make `servingOn()`
     * ambiguous for every date in between.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function appoint(array $attributes, ?User $actor = null, ?Carbon $effectiveFrom = null): CgpscChairman
    {
        return DB::transaction(function () use ($attributes, $actor, $effectiveFrom): CgpscChairman {
            $from = ($effectiveFrom ?? Carbon::today())->startOfDay();

            $incumbent = CgpscChairman::query()->serving()->lockForUpdate()->first();

            if ($incumbent !== null) {
                if ($from->lt($incumbent->effective_from)) {
                    throw new RuntimeException(sprintf(
                        'A Chairman cannot take office on %s, before the serving Chairman took office on %s.',
                        $from->format('d-m-Y'),
                        $incumbent->effective_from->format('d-m-Y'),
                    ));
                }

                $incumbent->forceFill([
                    // The incumbent serves up to the day before the successor
                    // starts. Closing on the same day would make both of them
                    // serving on that date.
                    'effective_to' => $from->copy()->subDay(),
                    'active_flag' => null,
                ])->save();
            }

            $chairman = new CgpscChairman($this->withDesignationName($attributes));
            $chairman->effective_from = $from;
            $chairman->forceFill(['active_flag' => 1]);
            $chairman->save();

            $this->timeline->record(
                'cgpsc_chairman.appointed',
                $chairman,
                sprintf('%s recorded as CGPSC Chairman with effect from %s', $chairman->name, $from->format('d-m-Y')),
                [
                    'chairman_uuid' => $chairman->uuid,
                    'succeeded_uuid' => $incumbent?->uuid,
                    'effective_from' => $from->toDateString(),
                ],
                $actor,
            );

            return $chairman;
        });
    }

    /**
     * Replace a posted `designation_id` with the master row's name.
     *
     * The screen sends the id — a rank is a master entity and is never typed
     * (DPC-MODULE-FIX-001, second pass) — while `cgpsc_chairmen.designation` keeps
     * storing the *name*, as it always has and for the reason its own docblock
     * gives: the contact details on this row are a snapshot, so that a committee
     * composed last month still names the Chairman as they were then. Storing a
     * foreign key would let a later rename rewrite a concluded committee.
     *
     * An id that no longer resolves falls through to whatever `designation` the
     * payload carried, which is how a record made before this change amends
     * without silently losing its designation.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withDesignationName(array $attributes): array
    {
        $id = $attributes['designation_id'] ?? null;

        if (is_numeric($id)) {
            $name = Designation::query()->whereKey((int) $id)->value('name');

            if ($name !== null) {
                $attributes['designation'] = (string) $name;
            }
        }

        unset($attributes['designation_id']);

        return $attributes;
    }

    /**
     * Correct the serving Chairman's particulars.
     *
     * **Amending is not appointing**, and this deliberately cannot change who holds
     * the office: `user_id` and `effective_from` are not accepted. Correcting a
     * misspelt name is a correction; replacing the person is a succession, and a
     * succession has to close one tenure and open another so that a committee
     * composed last month still names the Chairman who sat on it. Letting an edit
     * do it silently would rewrite history in place.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function amend(CgpscChairman $chairman, array $attributes, ?User $actor = null): CgpscChairman
    {
        $chairman->fill(collect($this->withDesignationName($attributes))
            ->only(['name', 'designation', 'email', 'mobile'])
            ->all());

        $changed = array_keys($chairman->getDirty());

        $chairman->save();

        if ($changed !== []) {
            $this->timeline->record(
                'cgpsc_chairman.amended',
                $chairman,
                sprintf('CGPSC Chairman particulars amended (%s)', implode(', ', $changed)),
                ['chairman_uuid' => $chairman->uuid, 'changed' => $changed],
                $actor,
            );
        }

        return $chairman;
    }

    /**
     * Every Chairman on record, newest tenure first.
     *
     * @return Collection<int, CgpscChairman>
     */
    public function history(): Collection
    {
        return CgpscChairman::query()
            ->with('user')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }
}
