<?php

declare(strict_types=1);

namespace App\Domain\Report\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Review\Models\DpcReviewLink;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The reports behind the Reports screen — M11.
 *
 * **Read-only over completed milestones.** Nothing here writes, and nothing
 * recomputes a business rule: the promotion figures are counted from **stored
 * calculation results**, never re-derived. A report that recalculated would be a
 * second implementation of the M5 engine (AD-19 forbids exactly that), and would
 * eventually disagree with the signed document it claims to summarise.
 *
 * **Scoped the way the screens are scoped.** A department sees its own DPCs and
 * nothing else; the Commission and the Admin see everything. The scope is applied
 * in one place — {@see scope()} — so a report added later cannot forget it.
 */
class DpcReportService
{
    /**
     * Which department to restrict to, or null for everything.
     *
     * A Chairman, a Member and the Admin hold no department and see all of it;
     * everybody else sees their own. Returning the *department id* rather than a
     * boolean keeps the decision in one expression.
     */
    public function scopeFor(User $user): ?int
    {
        return $user->hasAnyRole([Role::Admin->value, Role::Chairman->value, Role::Member->value])
            ? null
            : $user->department_id;
    }

    /**
     * The pipeline: how many DPCs sit at each stage of the process.
     *
     * The stages are the ones the PPT names, in the order it names them, so the
     * report reads as the process rather than as a set of database states. The
     * CGPSC-side stages come from the workflow ledger and the allotment record,
     * which is where M6 put them — not from a column on `dpc_proposals`.
     *
     * @return list<array{stage:string, reference:string, count:int}>
     */
    public function pipeline(?int $departmentId): array
    {
        $proposals = fn (): Builder => $this->scope(DpcProposal::query(), $departmentId);

        $inProceeding = $this->proceedingsIn($departmentId, [DpcProceedingStatus::InProceeding]);
        $resultApproved = $this->proceedingsIn($departmentId, [DpcProceedingStatus::ResultApproved]);
        $cancelled = $this->proceedingsIn($departmentId, [DpcProceedingStatus::Cancelled]);

        return [
            ['stage' => 'Drafted by the department', 'reference' => 'S12–S18', 'count' => $proposals()->where('status', DpcProposalStatus::Draft->value)->count()],
            ['stage' => 'Awaiting departmental approval', 'reference' => 'S19–S25', 'count' => $proposals()->where('status', DpcProposalStatus::Submitted->value)->count()],
            ['stage' => 'Returned for correction', 'reference' => 'S25', 'count' => $proposals()->where('status', DpcProposalStatus::Returned->value)->count()],
            ['stage' => 'Forwarded to the Commission', 'reference' => 'S25', 'count' => $proposals()->where('status', DpcProposalStatus::Approved->value)->count()],
            ['stage' => 'Allotted to a Member', 'reference' => 'S28', 'count' => $this->withCurrentAllotment($departmentId)],
            ['stage' => 'Sitting opened', 'reference' => 'S31–S33', 'count' => $inProceeding],
            ['stage' => 'Result approved', 'reference' => 'S34', 'count' => $resultApproved],
            ['stage' => 'Cancelled by the Commission', 'reference' => 'S33', 'count' => $cancelled],
        ];
    }

    /**
     * DPCs raised per year, newest first.
     *
     * Grouped on `proposal_date` rather than on `created_at`: the year a DPC
     * belongs to is the year the department filed it for, not the day a row was
     * inserted, and a backfilled proposal would otherwise land in the wrong year.
     *
     * @return list<array{year:int, count:int}>
     */
    public function byYear(?int $departmentId): array
    {
        // `toBase()` because this is an aggregate, not a set of proposals:
        // hydrating a model per group would invent a DpcProposal whose only real
        // attributes are `year` and a count.
        $rows = $this->scope(DpcProposal::query(), $departmentId)
            ->whereNotNull('proposal_date')
            ->toBase()
            ->selectRaw($this->year('proposal_date').' as year, count(*) as aggregate')
            ->groupBy('year')
            ->orderByDesc('year')
            ->get();

        /** @var list<array{year:int, count:int}> $mapped */
        $mapped = $rows->map(static fn (object $row): array => [
            'year' => (int) ($row->year ?? 0),
            'count' => (int) ($row->aggregate ?? 0),
        ])->all();

        return $mapped;
    }

    /**
     * What the approved results actually produced.
     *
     * **Counted from stored results, not recalculated.** Only results belonging to
     * the calculation run a proceeding *pinned at approval* are counted — a
     * proceeding may hold several runs, and the earlier ones are working drafts
     * that were superseded before the Commission approved anything.
     *
     * @return array{promoted:int, waiting:int, held:int, not_eligible:int}
     */
    public function outcomes(?int $departmentId): array
    {
        $runIds = $this->approvedRunIds($departmentId);

        if ($runIds === []) {
            return ['promoted' => 0, 'waiting' => 0, 'held' => 0, 'not_eligible' => 0];
        }

        $results = DpcCalculationResult::query()->whereIn('dpc_calculation_run_id', $runIds);

        return [
            'promoted' => (clone $results)->where('list_type', ListType::Main->value)->count(),
            'waiting' => (clone $results)->where('list_type', ListType::Waiting->value)->count(),
            'held' => $this->heldCount($departmentId),
            // Everything the engine placed on neither list. Counted as the
            // complement rather than as `list_type = 'none'`, so a list type added
            // later is not silently reported as "not eligible".
            'not_eligible' => (clone $results)
                ->whereNotIn('list_type', [ListType::Main->value, ListType::Waiting->value])
                ->count(),
        ];
    }

    /**
     * The variant flows: Review DPCs and Circulations.
     *
     * @return array{reviews:int, circulations:int, circulations_signed:int}
     */
    public function variants(?int $departmentId): array
    {
        $reviewIds = DpcReviewLink::query()->pluck('review_dpc_proposal_id')->all();

        $reviews = $reviewIds === []
            ? 0
            : $this->scope(DpcProposal::query(), $departmentId)->whereIn('id', $reviewIds)->count();

        $circulations = DpcCirculation::query()
            ->when($departmentId !== null, fn (Builder $q): Builder => $q->where('department_id', $departmentId));

        return [
            'reviews' => $reviews,
            'circulations' => (clone $circulations)->count(),
            // `signature_id`, not a `signed_at` column: the signature IS the
            // operative act (AQ-17), so its presence is what "signed" means here.
            'circulations_signed' => (clone $circulations)->whereNotNull('signature_id')->count(),
        ];
    }

    /**
     * One row per department — the Commission's view of who is doing what.
     *
     * Returns an empty list when the caller is scoped to a single department:
     * a department comparing itself against others is a disclosure this report
     * has no reason to make.
     *
     * @return list<array{department:string, code:string, total:int, forwarded:int, results:int}>
     */
    public function byDepartment(?int $departmentId): array
    {
        if ($departmentId !== null) {
            return [];
        }

        $rows = [];

        foreach (Department::query()->orderBy('name')->get() as $department) {
            $total = DpcProposal::query()->where('department_id', $department->id)->count();

            if ($total === 0) {
                // A registered department with no DPC yet is not a finding worth a
                // row; it would pad the table with zeroes and bury the ones doing
                // the work.
                continue;
            }

            $rows[] = [
                'department' => $department->name,
                'code' => (string) $department->code,
                'total' => $total,
                'forwarded' => DpcProposal::query()
                    ->where('department_id', $department->id)
                    ->where('status', DpcProposalStatus::Approved->value)
                    ->count(),
                'results' => $this->proceedingsIn($department->id, [DpcProceedingStatus::ResultApproved]),
            ];
        }

        return $rows;
    }

    /**
     * The ids of every calculation run a proceeding pinned at approval.
     *
     * @return list<int>
     */
    private function approvedRunIds(?int $departmentId): array
    {
        /** @var list<int> $ids */
        $ids = DpcProceeding::query()
            ->where('status', DpcProceedingStatus::ResultApproved->value)
            ->whereNotNull('final_calculation_run_id')
            ->when($departmentId !== null, fn (Builder $q): Builder => $q->whereIn(
                'dpc_proposal_id',
                DpcProposal::query()->where('department_id', $departmentId)->select('id'),
            ))
            ->pluck('final_calculation_run_id')
            ->all();

        return $ids;
    }

    private function heldCount(?int $departmentId): int
    {
        return DpcProceedingHold::query()
            ->when($departmentId !== null, fn (Builder $q): Builder => $q->whereIn(
                'dpc_proceeding_id',
                DpcProceeding::query()->whereIn(
                    'dpc_proposal_id',
                    DpcProposal::query()->where('department_id', $departmentId)->select('id'),
                )->select('id'),
            ))
            ->count();
    }

    /**
     * @param  list<DpcProceedingStatus>  $statuses
     */
    private function proceedingsIn(?int $departmentId, array $statuses): int
    {
        return DpcProceeding::query()
            ->whereIn('status', array_map(static fn (DpcProceedingStatus $s): string => $s->value, $statuses))
            ->when($departmentId !== null, fn (Builder $q): Builder => $q->whereIn(
                'dpc_proposal_id',
                DpcProposal::query()->where('department_id', $departmentId)->select('id'),
            ))
            ->count();
    }

    /**
     * Proposals with a live allotment — M6's own definition of "allotted": the
     * current allotment is the one not superseded.
     */
    private function withCurrentAllotment(?int $departmentId): int
    {
        return $this->scope(DpcProposal::query(), $departmentId)
            ->whereExists(fn (QueryBuilder $sub): QueryBuilder => $sub
                ->select(DB::raw('1'))
                ->from('dpc_allotments')
                ->whereColumn('dpc_allotments.dpc_proposal_id', 'dpc_proposals.id')
                ->whereNull('dpc_allotments.superseded_by_id'))
            ->count();
    }

    /**
     * @param  Builder<DpcProposal>  $query
     * @return Builder<DpcProposal>
     */
    private function scope(Builder $query, ?int $departmentId): Builder
    {
        return $departmentId === null ? $query : $query->where('department_id', $departmentId);
    }

    /**
     * Year extraction that works on both engines — MySQL in the application,
     * SQLite in the suite. TD-24's lesson: the expression that works only on the
     * engine the tests use is the one that ships broken.
     */
    private function year(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "cast(strftime('%Y', {$column}) as integer)"
            : "year({$column})";
    }
}
