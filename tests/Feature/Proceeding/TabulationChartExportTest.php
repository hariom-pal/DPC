<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationCrGrade;
use App\Domain\Master\Models\IntegrityOption;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;
use Tests\TestCase;

/**
 * The Tabulation Chart (2026-11-18): CR 1 to CR 5 and PF 1 to PF 5 with each year
 * beneath, Fit / Unfit by colour (named for screen readers only), and the whole
 * table as an Excel workbook — to the chart's own readers, with nothing a
 * spreadsheet would run as a formula.
 */
class TabulationChartExportTest extends TestCase
{
    use BuildsProceedings;

    private const INJECTION = '=HYPERLINK("http://evil.example","x")';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProceeding();
        $this->officer(1, self::INJECTION, $this->urCategoryId(), [2021 => 'क+', 2022 => 'क+', 2023 => 'क', 2024 => 'क', 2025 => 'क']);
        $this->officer(2, '@SUM(1+1)', $this->urCategoryId(), [2021 => 'क', 2022 => 'क', 2023 => 'क', 2024 => 'ख', 2025 => 'क']);
        $this->memberOne->forceFill(['must_change_password' => false, 'email_verified_at' => now()])->save();
    }

    private function dpcYear(): int
    {
        return (int) ($this->proposal->dpc_year ?: $this->proposal->proposal_date->year);
    }

    public function test_the_header_carries_cr_and_pf_one_to_five_with_their_years(): void
    {
        $year = $this->dpcYear();
        $response = $this->actingAs($this->memberOne)->get(route('dpc-proposals.tabulation-chart', $this->proposal))->assertOk();

        $order = [];
        foreach (range(1, 5) as $n) {
            $order[] = __('dpc.tabulation.cr_n', ['n' => $n]);
            $order[] = (string) ($year - $n);
        }
        foreach (range(1, 5) as $n) {
            $order[] = __('dpc.tabulation.property_n', ['n' => $n]);
            $order[] = (string) ($year - $n);
        }
        $response->assertSeeInOrder($order)->assertSee('id="tabulation-download"', false);
    }

    public function test_integrity_is_a_colour_with_its_name_for_screen_readers_and_no_word_on_screen(): void
    {
        $fit = IntegrityOption::query()->create(['code' => 'ok_t', 'name_en' => 'Certified', 'name_hi' => 'प्रमाणित', 'fit' => true, 'needs_departmental_view' => false, 'sort_order' => 1]);
        $unfit = IntegrityOption::query()->create(['code' => 'bad_t', 'name_en' => 'Doubtful', 'name_hi' => 'संदिग्ध', 'fit' => false, 'needs_departmental_view' => false, 'sort_order' => 2]);
        $rows = $this->proposal->evaluationRows()->orderBy('sn')->get();
        DpcEvaluationCrGrade::query()->where('dpc_evaluation_row_id', $rows[0]->id)->where('year', $this->dpcYear() - 1)->update(['integrity_option_id' => $fit->id, 'tribal_area' => true]);
        DpcEvaluationCrGrade::query()->where('dpc_evaluation_row_id', $rows[1]->id)->where('year', $this->dpcYear() - 1)->update(['integrity_option_id' => $unfit->id]);

        $html = $this->actingAs($this->memberOne)->get(route('dpc-proposals.tabulation-chart', $this->proposal))->assertOk()
            ->assertSee('class="cell-fit"', false)->assertSee('class="cell-unfit"', false)
            ->assertSee('title="Certified"', false)
            ->assertSee(__('dpc.tabulation.tribal_short'))
            // The legend's marks name their meaning to a screen reader.
            ->assertSee('role="img" aria-label="'.__('ui.fit_mark.fit').'"', false)
            ->assertSee('role="img" aria-label="'.__('ui.fit_mark.unfit').'"', false)
            ->getContent();

        // No visible Fit / Unfit word: only inside aria-label attributes.
        $visible = strip_tags((string) preg_replace('/aria-label="[^"]*"/', '', (string) $html));
        $this->assertDoesNotMatchRegularExpression('/\bUnfit\b|\bFIT\b/', $visible);
    }

    public function test_the_workbook_is_the_whole_table_for_the_charts_readers_with_no_formula(): void
    {
        $response = $this->actingAs($this->memberOne)->get(route('dpc-proposals.tabulation-chart.download', $this->proposal))->assertOk();
        $file = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $file);
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));

        $sheet = IOFactory::load($file->getFile()->getPathname())->getActiveSheet();
        $found = [];
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType(), 'No cell is a formula: '.$cell->getCoordinate());
                $found[] = (string) $cell->getValue();
            }
        }

        // The typed names come through as text, unchanged.
        $this->assertContains(self::INJECTION, $found);
        $this->assertContains('@SUM(1+1)', $found);
        // CR 1 to CR 5, each year beneath.
        $this->assertContains(__('dpc.tabulation.cr_n', ['n' => 1]), $found);
        $this->assertContains(__('dpc.tabulation.cr_n', ['n' => 5]), $found);
        $this->assertContains((string) ($this->dpcYear() - 1), $found);
        $this->assertContains((string) ($this->dpcYear() - 5), $found);
    }

    public function test_the_workbook_is_refused_to_whoever_cannot_read_the_chart(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dpc-proposals.tabulation-chart.download', $this->proposal))->assertForbidden();
        // The Chairman, CGPSC reads the chart (viewRecords), so the workbook too: the same readers as the screen.
        $this->actingAs($this->chairman)->get(route('dpc-proposals.tabulation-chart', $this->proposal))->assertOk();
        $this->get(route('dpc-proposals.tabulation-chart.download', $this->proposal))->assertOk();
        $this->actingAs($this->authorizedOfficer)->get(route('dpc-proposals.tabulation-chart', $this->proposal))->assertForbidden();
        $this->get(route('dpc-proposals.tabulation-chart.download', $this->proposal))->assertForbidden();
        auth()->logout();
        $this->get(route('dpc-proposals.tabulation-chart.download', $this->proposal))->assertRedirect();
    }
}
