<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationCrGrade;
use App\Domain\Master\Models\IntegrityOption;
use App\Domain\Master\Models\PromotionCrRule;
use App\Domain\Master\Services\PromotionCrRules;
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

    public function test_the_header_carries_cr_one_to_five_and_each_cell_its_own_year(): void
    {
        $year = $this->dpcYear();
        $response = $this->actingAs($this->memberOne)->get(route('dpc-proposals.tabulation-chart', $this->proposal))->assertOk();

        // CR 1 to CR 5 across the header; PF 1 to PF 5 with their years.
        $order = array_map(fn (int $n): string => __('dpc.tabulation.cr_n', ['n' => $n]), range(1, 5));
        foreach (range(1, 5) as $n) {
            $order[] = __('dpc.tabulation.property_n', ['n' => $n]);
            $order[] = (string) ($year - $n);
        }
        $response->assertSeeInOrder($order)->assertSee('id="tabulation-download"', false)
            ->assertDontSee('data-cr-column="6"', false);

        // Each CR cell carries its own year, latest first.
        $response->assertSeeInOrder(array_map(fn (int $n): string => 'data-cr-cell="'.($year - $n).'" data-cr-state="counted"', range(1, 5)), false);
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

    /**
     * 2026-11-19: a middle CR year not counted stands in its own column, yellow, and the
     * older year counted in its place follows, yellow — the row one column longer, in
     * the order the CR window takes them. The year before the DPC year is never replaced.
     */
    public function test_a_year_not_counted_keeps_its_column_and_the_older_year_follows(): void
    {
        $year = $this->dpcYear();
        $row = $this->officer(3, 'With a gap', $this->urCategoryId(), [
            $year - 6 => 'ख', $year - 5 => 'क', $year - 4 => 'क', $year - 3 => 'क+', $year - 2 => 'क', $year - 1 => 'क',
        ]);
        // Three years back put up without a grading (NG): the sixth year back is counted instead.
        DpcEvaluationCrGrade::query()->where('dpc_evaluation_row_id', $row->id)->where('year', $year - 3)->update(['put_up' => true, 'has_grading' => false, 'grade' => null]);
        // The substituted year's own Integrity goes with it.
        $unfit = IntegrityOption::query()->create(['code' => 'sub_t', 'name_en' => 'Doubtful integrity', 'name_hi' => 'संदिग्ध', 'fit' => false, 'needs_departmental_view' => false, 'sort_order' => 3]);
        DpcEvaluationCrGrade::query()->where('dpc_evaluation_row_id', $row->id)->where('year', $year - 6)->update(['integrity_option_id' => $unfit->id]);

        $html = (string) $this->actingAs($this->memberOne)->get(route('dpc-proposals.tabulation-chart', $this->proposal))->assertOk()
            ->assertSee('data-cr-column="6"', false)->assertDontSee('data-cr-column="7"', false)
            ->assertSeeInOrder([
                'data-cr-cell="'.($year - 1).'" data-cr-state="counted"',
                'data-cr-cell="'.($year - 2).'" data-cr-state="counted"',
                'data-cr-cell="'.($year - 3).'" data-cr-state="skipped"',
                'data-cr-cell="'.($year - 4).'" data-cr-state="counted"',
                'data-cr-cell="'.($year - 5).'" data-cr-state="counted"',
                'data-cr-cell="'.($year - 6).'" data-cr-state="replacement"',
            ], false)
            // PF years are their own: the CR substitution moves none of them.
            ->assertSeeInOrder(array_map(fn (int $n): string => __('dpc.tabulation.property_n', ['n' => $n]), range(1, 5)))
            ->getContent();
        // Yellow, with NG; the replacement yellow with its grade and its own Integrity's dot.
        $this->assertMatchesRegularExpression('/class="cell-substituted"\s+data-cr-cell="'.($year - 3).'"[^>]*>\s*<span class="tab-cell-year">'.($year - 3).'<\/span>\s*\(NG\)/u', $html);
        $this->assertMatchesRegularExpression('/class="cell-substituted"\s+data-cr-cell="'.($year - 6).'"[^>]*title="Doubtful integrity"[^>]*>\s*<span class="tab-cell-year">'.($year - 6).'<\/span>\s*<strong>ख<\/strong>\s*<span class="fit-mark fit-mark-unfit"/u', $html);

        // The last year missing: counted where it stands, nothing added for it.
        DpcEvaluationCrGrade::query()->where('dpc_evaluation_row_id', $row->id)->where('year', $year - 1)->update(['put_up' => false, 'grade' => null]);
        $this->get(route('dpc-proposals.tabulation-chart', $this->proposal))->assertOk()
            ->assertSee('data-cr-cell="'.($year - 1).'" data-cr-state="counted"', false)
            ->assertDontSee('data-cr-cell="'.($year - 7).'"', false)
            ->assertDontSee('data-cr-column="7"', false);

        // The workbook says the same.
        $file = $this->get(route('dpc-proposals.tabulation-chart.download', $this->proposal))->assertOk()->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $file);
        $values = [];
        foreach (IOFactory::load($file->getFile()->getPathname())->getActiveSheet()->getRowIterator() as $sheetRow) {
            foreach ($sheetRow->getCellIterator() as $cell) {
                $values[] = trim((string) preg_replace('/\s+/u', ' ', (string) $cell->getValue()));
            }
        }
        $this->assertContains(__('dpc.tabulation.cr_n', ['n' => 6]), $values);
        $this->assertContains(($year - 3).': (NG)', $values);
        $this->assertContains(($year - 6).': ख [Doubtful integrity]', $values);
        $this->assertContains(($year - 1).': ('.__('dpc.tabulation.missing').')', $values);
    }

    /** CR and PF columns follow the promotion rule's Years of CR; five without a rule (2026-11-18). */
    public function test_the_columns_follow_the_rules_years_of_cr(): void
    {
        $year = $this->dpcYear();
        $rule = PromotionCrRule::query()->create([
            'to_class' => 'I', 'from_class' => 'I', 'effective_from_year' => 2020,
            'cr_years' => 3, 'loyalty_years' => 3, 'property_years' => 3,
            'qualifying_total' => 18, 'qualifying_average' => 6, 'super_seat_grade' => 9, 'tribal_bonus' => 2,
        ]);
        $this->proposal->forceFill(['promotion_cr_rule_id' => $rule->id])->save();

        $this->actingAs($this->memberOne)->get(route('dpc-proposals.tabulation-chart', $this->proposal))->assertOk()
            ->assertSee('data-cr-column="3"', false)->assertDontSee('data-cr-column="4"', false)
            ->assertSee(__('dpc.tabulation.property_n', ['n' => 3]))->assertDontSee(__('dpc.tabulation.property_n', ['n' => 4]))
            ->assertSeeInOrder([__('dpc.tabulation.cr_n', ['n' => 3]), __('dpc.tabulation.property_n', ['n' => 1]), (string) ($year - 1), __('dpc.tabulation.property_n', ['n' => 3]), (string) ($year - 3)]);

        $file = $this->get(route('dpc-proposals.tabulation-chart.download', $this->proposal))->assertOk()->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $file);
        $values = [];
        foreach (IOFactory::load($file->getFile()->getPathname())->getActiveSheet()->getRowIterator() as $sheetRow) {
            foreach ($sheetRow->getCellIterator() as $cell) {
                $values[] = (string) $cell->getValue();
            }
        }
        $this->assertContains(__('dpc.tabulation.cr_n', ['n' => 3]), $values);
        $this->assertNotContains(__('dpc.tabulation.cr_n', ['n' => 4]), $values);
        $this->assertNotContains(__('dpc.tabulation.property_full', ['n' => 4]), $values);

        // No rule: five.
        $this->proposal->forceFill(['promotion_cr_rule_id' => null])->save();
        $rule->years()->delete();
        $rule->delete();
        $this->app->forgetInstance(PromotionCrRules::class);
        $this->get(route('dpc-proposals.tabulation-chart', $this->proposal))->assertSee('data-cr-column="5"', false)->assertDontSee('data-cr-column="6"', false);
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
