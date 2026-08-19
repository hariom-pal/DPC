<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Import\Enums\ImportTemplate;
use App\Domain\Import\Services\ImportTemplateBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Blank upload templates for the three Excel imports (S16–S18) — M11.
 *
 * **Its own screen and its own routes, and that placement is a constraint rather
 * than a preference.** The natural home for a *"Download blank template"* button is
 * beside each upload control on the S16–S18 screens — but those are **M4's, and M4
 * is architecture-frozen**: no new features. Putting the button there needs a
 * Freeze Exception under AD-22, which has not been granted.
 *
 * So the capability is delivered where it can be — a Downloads screen the
 * department reaches from its dashboard — and **the substance is available now**
 * rather than waiting on the exception. Moving the button onto the upload screens
 * is then a two-line change once the exception is granted, because the route and
 * the builder already exist. This is the same shape as M8 reaching M4's frozen
 * screens from the layout and M9 owning its own document type: work around the
 * freeze, never through it.
 */
class ImportTemplateController extends Controller
{
    public function __construct(private readonly ImportTemplateBuilder $templates) {}

    public function index(Request $request): View
    {
        $asOn = $this->asOnYear($request);

        return view('dpc.import-templates.index', [
            'templates' => ImportTemplate::cases(),
            'builder' => $this->templates,
            'asOnYear' => $asOn,
            'years' => $this->templates->years($asOn),
        ]);
    }

    public function download(Request $request, string $template): Response
    {
        $resolved = ImportTemplate::tryFrom($template);

        abort_if($resolved === null, 404);

        $asOn = $this->asOnYear($request);

        return response(
            $this->templates->csv($resolved, $asOn),
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'
                    .$this->templates->filename($resolved, $asOn).'"',
            ],
        );
    }

    /**
     * The promotion-as-on year the per-year columns are built around.
     *
     * Bounded rather than trusted: a year outside a plausible range would produce
     * column headers the importer's four-digit pattern cannot match, so the input
     * is clamped instead of being allowed to generate a broken template.
     */
    private function asOnYear(Request $request): int
    {
        $requested = $request->integer('as_on');
        $current = (int) now()->format('Y');

        if ($requested < 1950 || $requested > $current + 5) {
            return $current;
        }

        return $requested;
    }
}
