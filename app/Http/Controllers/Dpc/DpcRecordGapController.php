<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Requests\RecordGapRequest;
use App\Domain\Dpc\Services\RecordGapService;
use App\Domain\Master\Models\RecordReason;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Missing mandatory records for one officer (change register of 2026-08-27, items 14
 * to 19).
 *
 * **A screen of its own rather than more fields on the Evaluation Chart**, for three
 * reasons that all point the same way. The chart is one form saving every officer at
 * once, and a file upload per missing year per officer would make it a multipart form
 * whose partial failure loses everybody's data. The reason and the PDF are a
 * *statement about* a year rather than data *in* it — item 19 treats them as a
 * justification, not a value. And the officer arrives here from a specific row with a
 * specific year in mind, which a wide grid does not support.
 *
 * Everything here is gated on `update` for the proposal: recording why a record is
 * missing is editing the proposal, and it is what moves a candidate to CIR.
 */
class DpcRecordGapController extends Controller
{
    public function __construct(private readonly RecordGapService $gaps) {}

    /**
     * The gaps already recorded for one officer, and the form to record another.
     */
    public function index(DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): View
    {
        $this->authorize('update', $dpcProposal);
        $this->assertRowBelongsToProposal($dpcProposal, $evaluationRow);

        $evaluationRow->loadMissing('crGrades', 'loyaltyFlags', 'recordGaps.reason', 'recordGaps.recordedBy');

        return view('dpc-proposals.record-gaps', [
            'proposal' => $dpcProposal,
            'row' => $evaluationRow,
            'reasons' => RecordReason::query()->selectable()->get(),

            /*
             | The years the officer's own chart carries.
             |
             | Offered rather than a free-text year, because a gap is about a year the
             | chart knows: a CR gap on a year nobody expected a CR for would hold a
             | promotion for nothing. Loyalty gaps are narrower still — item 15 ties
             | the certificate to a CR year — so the view separates the two lists.
             */
            'crYears' => $evaluationRow->crGrades->pluck('year')->sort()->values(),

            'requiredCrCount' => (int) config('dpc.merit.required_cr_count', 5),
        ]);
    }

    public function store(RecordGapRequest $request, DpcProposal $dpcProposal, DpcEvaluationRow $evaluationRow): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);
        $this->assertRowBelongsToProposal($dpcProposal, $evaluationRow);

        $reason = RecordReason::query()->findOrFail($request->integer('record_reason_id'));

        $this->gaps->record(
            $evaluationRow,
            (string) $request->string('kind'),
            $request->integer('year'),
            $reason,
            $request->input('remarks'),
            $request->file('document'),
            $request->user(),
            $request->file('second_document'),
        );

        return redirect()
            ->route('dpc-proposals.record-gaps.index', [$dpcProposal, $evaluationRow])
            ->with('status', __('dpc.record_gaps.recorded'));
    }

    public function destroy(
        Request $request,
        DpcProposal $dpcProposal,
        DpcEvaluationRow $evaluationRow,
        DpcEvaluationRecordGap $recordGap,
    ): RedirectResponse {
        $this->authorize('update', $dpcProposal);
        $this->assertRowBelongsToProposal($dpcProposal, $evaluationRow);
        abort_unless($recordGap->dpc_evaluation_row_id === $evaluationRow->id, 404);

        $this->gaps->withdraw($recordGap, $request->user());

        return redirect()
            ->route('dpc-proposals.record-gaps.index', [$dpcProposal, $evaluationRow])
            ->with('status', __('dpc.record_gaps.withdrawn'));
    }

    /**
     * The supporting document behind one gap.
     *
     * Streamed from a private disk rather than served from `public/`: the PDF is a
     * departmental record about a named officer, and a guessable public URL would
     * publish it.
     */
    public function download(
        Request $request,
        DpcProposal $dpcProposal,
        DpcEvaluationRow $evaluationRow,
        DpcEvaluationRecordGap $recordGap,
    ): StreamedResponse {
        $this->authorize('view', $dpcProposal);
        $this->assertRowBelongsToProposal($dpcProposal, $evaluationRow);
        abort_unless($recordGap->dpc_evaluation_row_id === $evaluationRow->id, 404);

        // Rule 3.2: `?part=second` is the second document a reason needs.
        $second = $request->query('part') === 'second';
        abort_unless($second ? $recordGap->hasSecondDocument() : $recordGap->hasDocument(), 404);

        $disk = Storage::disk((string) ($second ? $recordGap->second_disk : $recordGap->disk));
        $path = (string) ($second ? $recordGap->second_path : $recordGap->path);

        abort_unless($disk->exists($path), 404);

        return $disk->download($path, (string) ($second ? $recordGap->second_original_name : $recordGap->original_name), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * A row from another proposal is a 404, not a redirect.
     *
     * Both models are resolved independently by the router, so nothing else stops
     * `/dpc-proposals/{a}/officers/{row-of-b}/missing-records` from reading and
     * writing across proposals.
     */
    private function assertRowBelongsToProposal(DpcProposal $proposal, DpcEvaluationRow $evaluationRow): void
    {
        abort_unless($evaluationRow->dpc_proposal_id === $proposal->id, 404);
    }
}
