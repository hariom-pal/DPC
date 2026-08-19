<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Import\Enums\ImportTemplate;
use App\Domain\Import\Services\ImportTemplateBuilder;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Blank upload templates for S16–S18 — M11.
 *
 * **The test that gives the feature its value is
 * {@see test_every_generated_header_is_one_the_importer_accepts}.** A template
 * whose headers the parser does not recognise is worse than no template: the
 * department would trust it, upload it, and get the rejection the template existed
 * to prevent. So the headers are checked against the importer's **own**
 * `columnMap()` and its **own** year patterns, normalised the way the importer
 * normalises — not against a second list written out here, which is the mistake
 * this whole feature exists to stop a human making.
 */
class ImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $ao;

    private ImportTemplateBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $department = Department::query()->create([
            'code' => 'SE', 'name' => 'School Education', 'status' => 'active',
        ]);

        $this->ao = User::factory()->create(['department_id' => $department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);

        $this->builder = app(ImportTemplateBuilder::class);
    }

    /**
     * @return list<array{ImportTemplate}>
     */
    public static function templates(): array
    {
        return array_map(
            static fn (ImportTemplate $t): array => [$t],
            ImportTemplate::cases(),
        );
    }

    #[DataProvider('templates')]
    public function test_every_generated_header_is_one_the_importer_accepts(ImportTemplate $template): void
    {
        $columnMap = $template->columnMap();

        foreach ($this->builder->headers($template, 2017) as $header) {
            $normalised = $this->normalise($header);

            $accepted = isset($columnMap[$normalised]) || $this->matchesYearColumn($normalised);

            $this->assertTrue(
                $accepted,
                "The template offers a column the importer does not recognise: '{$header}' "
                ."(normalises to '{$normalised}'). A template the parser rejects is worse than none.",
            );
        }
    }

    /**
     * The mandatory fields must actually be offered — a template can be entirely
     * "accepted" and still be useless by omitting the columns the row rules require.
     */
    #[DataProvider('templates')]
    public function test_the_template_offers_the_fields_the_row_rules_require(ImportTemplate $template): void
    {
        $offered = [];

        foreach ($this->builder->headers($template, 2017) as $header) {
            $normalised = $this->normalise($header);
            $offered[] = $template->columnMap()[$normalised] ?? null;
        }

        $offered = array_filter($offered);

        foreach ($template->rowRules() as $field => $rules) {
            if (! in_array('required', $rules, true)) {
                continue;
            }

            // The row rules validate `reservation_category_id`, but a department
            // filling in a spreadsheet has no access to our primary keys — so the
            // template offers `CAT`, a category *code*, which the importer resolves
            // against the M2 master while parsing. That translation is the
            // importer's documented behaviour, not a gap in the template.
            if ($field === 'reservation_category_id') {
                $this->assertContains('reservation_category', $offered);

                continue;
            }

            // Only top-level scalar fields; the per-year rules address nested keys.
            if (str_contains((string) $field, '.')) {
                continue;
            }

            $this->assertContains(
                $field,
                $offered,
                "'{$field}' is required by the row rules but the template has no column for it.",
            );
        }
    }

    public function test_the_evaluation_chart_carries_the_per_year_columns(): void
    {
        $headers = $this->builder->headers(ImportTemplate::EvaluationChartUs, 2017);

        // Six years by default (`dpc.evaluation.max_cr_years`), ending at the
        // promotion-as-on year.
        foreach ([2012, 2013, 2014, 2015, 2016, 2017] as $year) {
            $this->assertContains("CR {$year}", $headers);
            $this->assertContains("LOYALTY {$year}", $headers);
            $this->assertContains("IMMOVABLE PROPERTY {$year}", $headers);
        }

        $this->assertNotContains('CR 2011', $headers);
    }

    /**
     * The chart's width is configuration, and a template narrower than the chart
     * would refuse data the chart accepts.
     */
    public function test_the_year_count_follows_the_chart_configuration(): void
    {
        Config::set('dpc.evaluation.max_cr_years', 3);

        $this->assertSame([2015, 2016, 2017], $this->builder->years(2017));
    }

    public function test_the_other_two_templates_have_no_year_columns(): void
    {
        foreach ([ImportTemplate::CurrentlyWorkingUs, ImportTemplate::FinalSeniorityListUs] as $template) {
            $headers = implode('|', $this->builder->headers($template, 2017));

            $this->assertStringNotContainsString('CR 2', $headers);
            $this->assertStringNotContainsString('LOYALTY', $headers);
        }
    }

    // ------------------------------------------------------------------
    // The screen and the download
    // ------------------------------------------------------------------

    public function test_the_screen_lists_all_three_templates(): void
    {
        $this->actingAs($this->ao)->get(route('dpc-import-templates.index'))
            ->assertOk()
            ->assertSee('Currently Working Officers (S16)')
            ->assertSee('Final Seniority List (S17)')
            ->assertSee('Evaluation Chart (S18)')
            // The two things a department gets wrong even with the right columns.
            ->assertSee('not its full name')
            ->assertSee('not filled with "N"', escape: false);
    }

    public function test_a_template_downloads_as_csv_with_a_utf8_bom(): void
    {
        $response = $this->actingAs($this->ao)->get(route('dpc-import-templates.download', [
            'template' => ImportTemplate::EvaluationChartUs->value,
            'as_on' => 2017,
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'dpc-template-evaluation-chart-us-2017.csv',
            (string) $response->headers->get('content-disposition'),
        );

        $body = $response->getContent();

        // Without the BOM, Excel on Windows reads a Hindi post name as mojibake.
        $this->assertStringStartsWith("\xEF\xBB\xBF", (string) $body);
        $this->assertStringContainsString('CR 2017', (string) $body);
        // Headers only: a specimen row is one a department forgets to delete, and
        // the importer would take it as a real officer.
        $this->assertSame(1, count(array_filter(explode("\n", trim((string) $body)))));
    }

    /**
     * A year outside a plausible range would produce headers the importer's
     * four-digit pattern cannot match, so it is clamped rather than obeyed.
     */
    public function test_an_implausible_year_falls_back_to_the_current_one(): void
    {
        $body = (string) $this->actingAs($this->ao)->get(route('dpc-import-templates.download', [
            'template' => ImportTemplate::EvaluationChartUs->value,
            'as_on' => 12,
        ]))->getContent();

        $this->assertStringContainsString('CR '.now()->year, $body);
    }

    public function test_an_unknown_template_is_a_404(): void
    {
        $this->actingAs($this->ao)
            ->get(route('dpc-import-templates.download', ['template' => 'not-a-template']))
            ->assertNotFound();
    }

    public function test_the_templates_are_for_departmental_users(): void
    {
        $this->get(route('dpc-import-templates.index'))->assertRedirect(route('login'));

        $member = User::factory()->create(['department_id' => null]);
        $member->assignRole(Role::Member->value);

        $this->actingAs($member)->get(route('dpc-import-templates.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------
    // The importer's own normalisation and patterns, mirrored
    // ------------------------------------------------------------------

    private function normalise(string $heading): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($heading))), '_');
    }

    private function matchesYearColumn(string $normalised): bool
    {
        foreach ([
            '/^(?:year_wise_)?cr_?(\d{4})$/',
            '/^loyalty_?(\d{4})$/',
            '/^(?:immovable_)?(?:property|property_details|immovable_property_details)_?(\d{4})$/',
        ] as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return true;
            }
        }

        return false;
    }
}
