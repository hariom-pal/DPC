<?php

declare(strict_types=1);

namespace App\Domain\SelfInspection\Services;

use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Which chart rows are open to self-inspection at all.
 *
 * **This is the boundary, and it is one query stated once** — the authenticator
 * matches against it, and the screen reads through it. Two statements of "which
 * rows may an officer see" is how one of them stops matching the other, and here
 * the consequence would be showing an officer somebody else's record.
 *
 * Three rules, all configuration (`dpc.self_inspection`):
 *
 * 1. **The proposal must be under consideration** — `submitted` or `approved` by
 *    default. A `draft` is still being typed; presenting it as the data under
 *    consideration would invite an officer to object to figures the department
 *    has not finished entering. `returned` and `cancelled` fail from the other
 *    end.
 * 2. **The sitting must not have produced a result** — after that, correcting the
 *    chart is no longer what the officer is being invited to do; the route is a
 *    Review DPC, which is the department's to raise.
 * 3. **The whole screen can be switched off**, because a facility that reads
 *    officer data without an account is one an operator may want closed outside a
 *    DPC season.
 */
class SelfInspectionAccessGuard
{
    /**
     * Whether the facility is switched on at all.
     */
    public function enabled(): bool
    {
        return (bool) config('dpc.self_inspection.enabled', true);
    }

    /**
     * Every evaluation row currently open to inspection.
     *
     * Eager-loads the three per-year children S51 renders, so the screen makes
     * one pass rather than one query per officer per year.
     *
     * @return Collection<int, DpcEvaluationRow>
     */
    public function inspectableRows(): Collection
    {
        if (! $this->enabled()) {
            /** @var Collection<int, DpcEvaluationRow> $empty */
            $empty = DpcEvaluationRow::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        return DpcEvaluationRow::query()
            ->with(['crGrades', 'loyaltyFlags', 'propertyFlags', 'reservationCategory', 'proposal.department'])
            ->whereHas('proposal', fn (Builder $query): Builder => $this->constrainProposal($query))
            ->orderBy('dpc_proposal_id')
            ->orderBy('sn')
            ->get();
    }

    /**
     * Whether this specific row is still open — re-checked on every request, so a
     * session opened before the result was approved does not outlive it.
     */
    public function isInspectable(DpcEvaluationRow $row): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return DpcProposal::query()
            ->whereKey($row->dpc_proposal_id)
            ->where(fn (Builder $query): Builder => $this->constrainProposal($query))
            ->exists();
    }

    /**
     * The three rules, as a query constraint.
     *
     * Generic in the model because it is applied both to a `DpcProposal` query
     * directly and to the sub-builder `whereHas('proposal', ...)` hands over,
     * which is typed only as a `Model` builder. One statement of the rule, used
     * from both places — see the class doc for why that matters here.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function constrainProposal(Builder $query): Builder
    {
        /** @var list<string> $statuses */
        $statuses = (array) config('dpc.self_inspection.visible_proposal_statuses', ['submitted', 'approved']);

        $query->whereIn('status', $statuses === [] ? ['submitted', 'approved'] : $statuses);

        if ((bool) config('dpc.self_inspection.close_after_result', true)) {
            // A subquery rather than `whereDoesntHave('proceedings')`, because
            // `DpcProposal` has no `proceedings` relation and **M4 is frozen** —
            // adding one would be a change inside a frozen milestone for the
            // convenience of a later one. The subquery reads the same rows.
            $query->whereNotExists(
                fn (QueryBuilder $sub): QueryBuilder => $sub
                    ->select(DB::raw('1'))
                    ->from('dpc_proceedings')
                    ->whereColumn('dpc_proceedings.dpc_proposal_id', 'dpc_proposals.id')
                    ->where('dpc_proceedings.status', DpcProceedingStatus::ResultApproved->value),
            );
        }

        return $query;
    }

    /**
     * Whether a result has been approved on this proposal — the fact rule 2 turns
     * on, exposed so the screen can explain *why* it is closed rather than merely
     * showing nothing.
     */
    public function resultApproved(DpcProposal $proposal): bool
    {
        return DpcProceeding::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('status', DpcProceedingStatus::ResultApproved->value)
            ->exists();
    }
}
