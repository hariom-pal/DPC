<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Services\MasterDataPortabilityService;
use App\Domain\Master\Services\MasterImportException;
use App\Domain\Master\Support\MasterDefinition;
use App\Domain\Master\Support\MasterRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export, sample template and bulk upload, for every master the registry
 * describes (business instruction, 2026-09-06).
 *
 * **One controller for two dozen screens.** Which master is a route parameter
 * resolved through {@see MasterRegistry}, so a master gains all three features by
 * being described once. An unknown key is a 404 rather than a guess — the registry
 * is the allow-list, and a parameter that reached a model name would be a way to
 * export any table in the database.
 *
 * Authorisation is the route group's `role:admin`, as for every other master screen.
 */
class MasterDataController extends Controller
{
    public function __construct(private readonly MasterDataPortabilityService $portability) {}

    /**
     * The master as a CSV, filtered exactly as the screen behind it is.
     *
     * `?q=` is honoured on purpose: an officer who has searched for the twelve rows
     * they care about means to export those twelve, and an export that silently
     * ignored the filter would be the sort of thing nobody notices until the file is
     * already in a meeting.
     */
    public function export(Request $request, string $master): StreamedResponse
    {
        $definition = $this->definition($master);

        $query = $definition->model::query()->orderBy(
            // Ordered by whatever identifies a row, so two exports of an unchanged
            // master are the same file and can be diffed.
            $definition->unique[array_key_last($definition->unique)] ?? 'id',
        );

        return $this->portability->export(
            $definition,
            $this->portability->search($query, $request->query('q'), $definition->searchable),
        );
    }

    public function template(string $master): StreamedResponse
    {
        return $this->portability->template($this->definition($master));
    }

    /**
     * Load a filled-in template.
     *
     * Parsed here rather than in the service because a CSV arrives as an uploaded
     * *file*, and the service is given rows — which is what lets it be tested, and
     * reused, without one.
     */
    public function import(Request $request, string $master): RedirectResponse
    {
        $definition = $this->definition($master);

        $request->validate([
            /*
             | `txt` alongside `csv` because a CSV exported by Excel and re-saved
             | frequently arrives with a text MIME type, and refusing it would send
             | an officer to look for a fault in the file that is not there.
             */
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        try {
            $result = $this->portability->import($definition, $this->rows($request));
        } catch (MasterImportException $e) {
            return back()->withErrors(['file' => $e->errors]);
        }

        return back()->with('status', __('admin.master_data.imported', [
            'created' => $result['created'],
            'updated' => $result['updated'],
        ]));
    }

    /**
     * The uploaded CSV as header-keyed rows.
     *
     * The byte order mark is stripped from the first header: a file exported by this
     * very portal carries one — it is what makes Excel read the Hindi columns — and
     * left in place it would turn `code` into `\xEF\xBB\xBFcode` and every row would
     * report a missing column.
     *
     * @return list<array<string, string>>
     */
    private function rows(Request $request): array
    {
        $path = $request->file('file')?->getRealPath();

        if ($path === null || $path === false) {
            return [];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);

        if ($headers === false) {
            fclose($handle);

            return [];
        }

        $headers = array_map(
            static fn (?string $header): string => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $header) ?? ''),
            $headers,
        );

        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            // A trailing blank line is what a spreadsheet leaves behind, not a row
            // the officer meant to add.
            if ($line === [null] || implode('', array_map(static fn ($v): string => trim((string) $v), $line)) === '') {
                continue;
            }

            $row = [];

            foreach ($headers as $i => $header) {
                $row[$header] = (string) ($line[$i] ?? '');
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function definition(string $master): MasterDefinition
    {
        return MasterRegistry::find($master) ?? abort(404);
    }
}
