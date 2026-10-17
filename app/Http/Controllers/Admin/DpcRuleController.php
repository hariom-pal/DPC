<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Eligibility\Models\CrScoringScale;
use App\Domain\Eligibility\Models\PhReservationRule;
use App\Domain\Eligibility\Requests\RecordCrScaleRequest;
use App\Domain\Eligibility\Requests\RecordPhRuleRequest;
use App\Domain\Eligibility\Services\DpcRuleBook;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DPC Rules: the CR marks and qualifying scores (rules 3.4, 3.5) and the PH
 * reservation (rule 2.1), version by version, each from a DPC year and on an order.
 *
 * Versions are recorded and read, never edited or deleted — a change is a new version.
 */
class DpcRuleController extends Controller
{
    public function __construct(private readonly DpcRuleBook $rules) {}

    public function index(Request $request): View
    {
        $year = $this->year($request);

        return view('admin.dpc-rules.index', [
            'year' => $year,
            'cr' => $this->rules->crTermsFor($year),
            'ph' => $this->rules->phTermsFor($year),
            'crScales' => CrScoringScale::query()->with('recorder')->orderByDesc('effective_from_year')->orderByDesc('id')->get(),
            'phRules' => PhReservationRule::query()->with('recorder')->orderByDesc('effective_from_year')->orderByDesc('id')->get(),
            'grades' => RecordCrScaleRequest::grades(),
            'pairs' => RecordCrScaleRequest::classPairs(),
        ]);
    }

    public function createCrScale(Request $request): View
    {
        $year = $this->year($request);

        return view('admin.dpc-rules.cr-form', [
            'year' => $year,
            'current' => $this->rules->crTermsFor($year),
            'grades' => RecordCrScaleRequest::grades(),
            'pairs' => RecordCrScaleRequest::classPairs(),
            'gatedPairs' => RecordCrScaleRequest::gatedPairs(),
        ]);
    }

    public function storeCrScale(RecordCrScaleRequest $request): RedirectResponse
    {
        $scale = $this->rules->recordCrScale($request->safe()->except('document'), $this->document($request), $request->user());

        return redirect()->route('admin.dpc-rules.index', ['year' => $scale->effective_from_year])
            ->with('status', __('dpcrules.flash.cr_recorded', ['year' => $scale->effective_from_year]));
    }

    public function crScaleDocument(CrScoringScale $crScale): StreamedResponse
    {
        return $this->stream($crScale->disk, $crScale->path, $crScale->original_name);
    }

    public function createPhRule(Request $request): View
    {
        $year = $this->year($request);

        return view('admin.dpc-rules.ph-form', [
            'year' => $year,
            'current' => $this->rules->phTermsFor($year),
            'roundings' => PhReservationRule::ROUNDINGS,
        ]);
    }

    public function storePhRule(RecordPhRuleRequest $request): RedirectResponse
    {
        $rule = $this->rules->recordPhRule($request->safe()->except('document'), $this->document($request), $request->user());

        return redirect()->route('admin.dpc-rules.index', ['year' => $rule->effective_from_year])
            ->with('status', __('dpcrules.flash.ph_recorded', ['year' => $rule->effective_from_year]));
    }

    public function phRuleDocument(PhReservationRule $phRule): StreamedResponse
    {
        return $this->stream($phRule->disk, $phRule->path, $phRule->original_name);
    }

    private function document(Request $request): UploadedFile
    {
        $file = $request->file('document');
        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }

    private function year(Request $request): int
    {
        $year = (int) $request->query('year', (string) now()->year);

        return $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
    }

    private function stream(string $disk, string $path, string $name): StreamedResponse
    {
        $storage = Storage::disk($disk);

        abort_unless($storage->exists($path), 404);

        return $storage->download($path, $name, ['Content-Type' => 'application/pdf']);
    }
}
