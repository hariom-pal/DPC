<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\CandidateVerification\Models\VerificationParticipant;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Services\DpcStageService;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingDocument;
use Illuminate\Support\Carbon;

/**
 * Where a candidate's DPC stands and, once the Commission has published it, their own
 * result (2026-11-20) — what the withdrawn "My DPC" screen showed, now on the
 * Candidate Portal. Read only.
 *
 * The result is whether the candidate was promoted and nothing more — never a mark,
 * a rank or another officer's row — and only once a proceedings document is
 * published: publication is the Commission's act, and before it there is no outcome
 * to state. A sealed cover on the Main List is not a promotion.
 */
final class CandidateOutcome
{
    public function __construct(private readonly DpcStageService $stages) {}

    /** The DPC's status, and the stage it is at where one applies. */
    public function stage(DpcEvaluationRow $row): string
    {
        $proposal = $row->proposal;
        $stage = $this->stages->stageOf($proposal);

        return $stage === null ? $proposal->status->label() : $proposal->status->label().' — '.$stage->label();
    }

    /**
     * Whether the candidate was promoted, once the result is published; null before.
     *
     * @return array{promoted: bool, declared_at: Carbon}|null
     */
    public function result(DpcEvaluationRow $row): ?array
    {
        $employee = trim((string) $row->employee_number);
        $proceeding = DpcProceeding::query()->where('dpc_proposal_id', $row->dpc_proposal_id)->latest('id')->first();

        if ($employee === '' || $proceeding === null || $proceeding->final_calculation_run_id === null) {
            return null;
        }

        $published = DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->whereNotNull('published_at')
            ->orderBy('published_at')
            ->first();

        if ($published === null) {
            return null;
        }

        $result = DpcCalculationResult::query()
            ->where('dpc_calculation_run_id', $proceeding->final_calculation_run_id)
            ->where('employee_number', $employee)
            ->first(['list_type', 'verdict']);

        if ($result === null) {
            return null;
        }

        return [
            'promoted' => $result->list_type === ListType::Main && $result->status() !== RemarkCode::SealedCover,
            'declared_at' => $published->published_at,
        ];
    }

    /** Whether the candidate may still accept or raise a grievance on this verification. */
    public static function actionable(?VerificationParticipant $participant): bool
    {
        return $participant !== null && ! $participant->hasSubmitted() && $participant->cycle->acceptsSubmissions();
    }
}
