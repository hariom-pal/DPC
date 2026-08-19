<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditTrailService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit-trail screen — M11.
 *
 * **Read-only, and that is a design constraint rather than a limitation.** An
 * audit record that can be edited from the application that writes it is not an
 * audit record. There is no create, update, delete or purge route here, and none
 * should be added: retention is permanent by ruling (Decision 3, 2026-08-13).
 *
 * **CGPSC Admin only**, by the route's `role:admin` middleware. Whether a
 * department should be able to read its own slice of the trail is a real question
 * and a reasonable feature — but it is a disclosure decision, not an engineering
 * one, and the safe default is the narrow one. Recorded rather than assumed.
 */
class AuditTrailController extends Controller
{
    /**
     * The filters this screen understands. Named once, so the index, the export
     * and the view cannot disagree about what is filterable.
     */
    private const FILTERS = ['source', 'event', 'actor', 'subject_type', 'subject_id', 'from', 'to'];

    public function __construct(private readonly AuditTrailService $trail) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('admin.audit-trail.index', [
            'entries' => $this->trail->paginate($filters)->appends($filters),
            'filters' => $filters,
            'sources' => $this->trail->sources(),
            'counts' => $this->trail->counts(),
            'subjectTypes' => $this->trail->subjectTypes(),
        ]);
    }

    /**
     * One entry, in full.
     *
     * The row in the list is a projection onto nine shared columns; this is the
     * record itself — the remarks and signature on a transition, the properties on
     * a timeline event, the old and new values on a data change.
     */
    public function show(Request $request, string $source, int $id): View
    {
        $entry = $this->trail->find($source, $id);

        abort_if($entry === null, 404);

        return view('admin.audit-trail.show', [
            'source' => $source,
            'sources' => $this->trail->sources(),
            'entry' => $entry,
        ]);
    }

    /**
     * The filtered trail as CSV.
     *
     * Streamed rather than built in memory: the whole point of an audit table is
     * that it grows without bound, so an export that loads it first is an export
     * that stops working exactly when the record becomes valuable.
     *
     * Formula injection is neutralised the same way the import error report does
     * it — a cell beginning `=`, `+`, `-` or `@` is a formula to a spreadsheet, and
     * an audit export is precisely the file somebody opens in Excel without
     * thinking.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        $filename = 'audit-trail-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($filters): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['Source', 'Occurred at', 'Event', 'Summary', 'Actor', 'Subject type', 'Subject id', 'UUID']);

            foreach ($this->trail->stream($filters) as $entry) {
                fputcsv($handle, array_map(
                    [$this, 'neutralise'],
                    [
                        (string) ($entry->source ?? ''),
                        (string) ($entry->occurred_at ?? ''),
                        (string) ($entry->event ?? ''),
                        (string) ($entry->summary ?? ''),
                        (string) ($entry->actor_name ?? ''),
                        (string) ($entry->subject_type ?? ''),
                        (string) ($entry->subject_id ?? ''),
                        (string) ($entry->uuid ?? ''),
                    ],
                ));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (self::FILTERS as $key) {
            $value = trim((string) $request->query($key, ''));

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * A leading `=`, `+`, `-` or `@` makes a spreadsheet treat the cell as a
     * formula. Prefixed with a tab so the value still reads correctly.
     */
    private function neutralise(string $value): string
    {
        return $value !== '' && str_contains('=+-@', $value[0]) ? "\t".$value : $value;
    }
}
