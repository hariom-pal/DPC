<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\ClassMaster;
use App\Domain\Master\Models\CrGradeValue;
use App\Domain\Master\Models\PromotionCrRule;
use App\Domain\Master\Services\PromotionCrRules;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Class Master → a class → its promotion rules, and the Eligible CR Grades they use.
 */
class PromotionCrRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ClassMaster $classOne;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);
        $this->classOne = ClassMaster::query()->where('code', 'I')->firstOrFail();

        foreach ([9, 7, 5, 3, 1] as $value) {
            CrGradeValue::query()->create(['value' => $value, 'effective_from' => '2020-01-01']);
        }
    }

    /**
     * Class I to I: 5 years of CR, 5 of Loyalty, 3 of Property Declaration.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function record(array $overrides = []): TestResponse
    {
        $years = [];
        for ($before = 1; $before <= 5; $before++) {
            $years[$before] = [
                'cr_min_marks' => $before === 1 ? '7' : '5',
                'cr_mandatory' => $before === 1 ? '1' : '0',
                'loyalty_mandatory' => '1',
                'property_mandatory' => '1',
            ];
        }

        return $this->actingAs($this->admin)->post(route('admin.service-classes.promotion-rules.store', $this->classOne), array_replace_recursive([
            'from_class' => 'I',
            'effective_from_year' => '2026',
            'cr_years' => '5',
            'loyalty_years' => '5',
            'property_years' => '3',
            'qualifying_average' => '6',
            'super_seat_grade' => '9',
            'tribal_bonus' => '1',
            'years' => $years,
        ], $overrides));
    }

    public function test_a_class_opens_its_promotion_rules_from_the_same_class_or_the_one_below(): void
    {
        $this->actingAs($this->admin)->get(route('admin.service-classes.index'))
            ->assertOk()
            ->assertSee(route('admin.service-classes.promotion-rules.index', $this->classOne), false);

        $this->actingAs($this->admin)->get(route('admin.service-classes.promotion-rules.index', $this->classOne))
            ->assertOk()
            ->assertSee(__('admin.promotion_rules.pair', ['from' => 'Class-I', 'to' => 'Class-I']))
            ->assertSee(__('admin.promotion_rules.pair', ['from' => 'Class-II', 'to' => 'Class-I']))
            ->assertDontSee(__('admin.promotion_rules.pair', ['from' => 'Class-III', 'to' => 'Class-I']));
    }

    public function test_the_old_cr_marks_screen_is_gone(): void
    {
        $this->assertFalse(Route::has('admin.dpc-rules.index'));
    }

    public function test_a_rule_records_the_counts_and_each_years_requirement(): void
    {
        $this->actingAs($this->admin)->get(route('admin.service-classes.promotion-rules.create', [$this->classOne, 'from' => 'I', 'year' => 2026]))
            ->assertOk()
            ->assertSee(__('admin.promotion_rules.year_label', ['n' => 1, 'year' => 2025]))
            ->assertSee('name="years[1][cr_min_marks]"', false)
            ->assertSee('name="tribal_bonus"', false);

        $this->record()->assertSessionHasNoErrors()->assertRedirect(route('admin.service-classes.promotion-rules.index', $this->classOne));

        $rule = PromotionCrRule::query()->with('years')->sole();
        $this->assertSame(['I', 'I', 2026, 5, 5, 3], [$rule->to_class, $rule->from_class, $rule->effective_from_year, $rule->cr_years, $rule->loyalty_years, $rule->property_years]);
        $this->assertSame([9, 1, '6.00'], [$rule->super_seat_grade, $rule->tribal_bonus, (string) $rule->qualifying_average]);
        $this->assertCount(5, $rule->years);

        $last = $rule->years->firstWhere('years_before', 1);
        $this->assertSame([7, true, true, true], [$last->cr_min_marks, $last->cr_mandatory, $last->loyalty_mandatory, $last->property_mandatory]);
        $fifth = $rule->years->firstWhere('years_before', 5);
        $this->assertSame(5, $fifth->cr_min_marks);
        $this->assertFalse($fifth->cr_mandatory, 'The fifth CR may be missing.');
        $this->assertFalse($fifth->property_mandatory, 'Property is needed for three years only.');

        $this->actingAs($this->admin)->get(route('admin.service-classes.promotion-rules.index', $this->classOne))
            ->assertOk()
            ->assertSee(__('admin.promotion_rules.in_force_now', ['year' => now()->year]), false);
    }

    public function test_a_rule_applies_from_its_year_until_a_newer_one(): void
    {
        $this->record()->assertSessionHasNoErrors();
        $this->record(['effective_from_year' => '2028', 'qualifying_average' => '7'])->assertSessionHasNoErrors();

        $rules = app(PromotionCrRules::class);
        $this->assertNull($rules->ruleFor('I', 'I', 2025));
        $this->assertSame('6.00', (string) $rules->ruleFor('I', 'I', 2027)?->qualifying_average);
        $this->assertSame('7.00', (string) $rules->ruleFor('I', 'I', 2030)?->qualifying_average);
        $this->assertNull($rules->ruleFor('I', 'II', 2027), 'Each promotion has its own rule.');
    }

    public function test_marks_come_from_the_eligible_grades_and_each_cr_year_needs_them(): void
    {
        $this->record(['years' => [1 => ['cr_min_marks' => '8']]])->assertSessionHasErrors('years.1.cr_min_marks');
        $this->record(['years' => [3 => ['cr_min_marks' => '']]])->assertSessionHasErrors('years.3.cr_min_marks');
        $this->record(['from_class' => 'III'])->assertSessionHasErrors('from_class');

        // No grade is valid yet in 2018 (the rule-3.4 grades are valid from 2019).
        $this->record(['effective_from_year' => '2018'])->assertSessionHasErrors('effective_from_year');

        $this->assertSame(0, PromotionCrRule::query()->count());
    }

    /**
     * The five grades of the Coder Instructions, rule 3.4, are there from the start.
     */
    public function test_the_coder_instruction_grades_are_seeded(): void
    {
        $seeded = CrGradeValue::query()->whereDate('effective_from', '2019-01-01')->orderByDesc('value')->get();

        $this->assertSame([9, 7, 5, 3, 1], $seeded->pluck('value')->all());
        $this->assertStringContainsString('उत्कृष्ट', (string) $seeded->first()->remarks);
        $this->assertSame([9, 7, 5, 3, 1], app(PromotionCrRules::class)->gradesForYear(2026));

        $this->actingAs($this->admin)->get(route('admin.cr-grades.index'))
            ->assertOk()
            ->assertSee('बहुत अच्छा')
            ->assertSee('Remarks')
            ->assertDontSee('ui.remarks');
    }

    public function test_an_eligible_grade_names_the_cr_grade_it_scores(): void
    {
        // The rule-3.4 values carry their grade codes from the migration.
        $this->assertSame(['क+' => 9, 'क' => 7, 'ख' => 5, 'ग' => 3, 'घ' => 1], app(PromotionCrRules::class)->pointsForYear(2026));

        $this->actingAs($this->admin)->post(route('admin.cr-grades.store'), ['value' => '10', 'grade_code' => 'क+', 'effective_from' => '2028-01-01'])
            ->assertSessionHasNoErrors();
        $this->assertSame(10, app(PromotionCrRules::class)->pointsForYear(2028)['क+']);

        $this->actingAs($this->admin)->post(route('admin.cr-grades.store'), ['value' => '12', 'grade_code' => 'Z', 'effective_from' => '2028-01-01'])
            ->assertSessionHasErrors('grade_code');

        $this->actingAs($this->admin)->get(route('admin.cr-grades.index'))
            ->assertOk()
            ->assertSee('name="grade_code"', false);
    }

    public function test_eligible_grades_are_valid_from_their_date(): void
    {
        $this->actingAs($this->admin)->post(route('admin.cr-grades.store'), ['value' => '10', 'effective_from' => '2027-01-01'])
            ->assertSessionHasNoErrors();

        $rules = app(PromotionCrRules::class);
        $this->assertNotContains(10, $rules->gradesForYear(2026));
        $this->assertContains(10, $rules->gradesForYear(2027));

        // The same value twice on the same date is refused beside the field.
        $this->actingAs($this->admin)->post(route('admin.cr-grades.store'), ['value' => '10', 'effective_from' => '2027-01-01'])
            ->assertSessionHasErrors('value');

        // A value already in force cannot be removed; an upcoming one can.
        $inForce = CrGradeValue::query()->where('value', 9)->firstOrFail();
        $this->actingAs($this->admin)->delete(route('admin.cr-grades.destroy', $inForce))->assertSessionHasErrors('value');
        $this->assertModelExists($inForce);

        $upcoming = CrGradeValue::query()->where('value', 10)->firstOrFail();
        $this->travelTo('2026-10-17');
        $this->actingAs($this->admin)->delete(route('admin.cr-grades.destroy', $upcoming))->assertSessionHasNoErrors();
        $this->assertModelMissing($upcoming);
    }

    /**
     * A grade is made inactive from a date, never deleted: every DPC year keeps the
     * grades that were valid on its 1 January, and the window stays on record.
     */
    public function test_a_grade_is_made_inactive_with_its_window_kept_for_earlier_years(): void
    {
        $this->travelTo('2026-10-17');
        $grade = CrGradeValue::query()->create(['value' => 11, 'effective_from' => '2021-01-01']);
        $deactivate = fn (array $data) => $this->actingAs($this->admin)->patch(route('admin.cr-grades.deactivate', $grade), $data);

        // Not into the past, and not without a reason.
        $deactivate(['effective_to' => '2025-12-31', 'deactivation_reason' => 'Order'])->assertSessionHasErrors('effective_to');
        $deactivate(['effective_to' => '2026-12-31'])->assertSessionHasErrors('deactivation_reason');

        $deactivate(['effective_to' => '2026-12-31', 'deactivation_reason' => 'GAD order 12/2026'])->assertSessionHasNoErrors();

        $grade->refresh();
        $this->assertSame('2026-12-31', $grade->effective_to?->toDateString());
        $this->assertSame($this->admin->id, $grade->deactivated_by);
        $this->assertNotNull($grade->deactivated_at);

        $rules = app(PromotionCrRules::class);
        $this->assertContains(11, $rules->gradesForYear(2022));
        $this->assertContains(11, $rules->gradesForYear(2026), 'Valid on 1 January 2026, so 2026 keeps it.');
        $this->assertNotContains(11, $rules->gradesForYear(2027));

        // Once only: the window it was valid for is not rewritten.
        $deactivate(['effective_to' => '2027-06-30', 'deactivation_reason' => 'Again'])->assertSessionHasErrors('effective_to');
        $this->assertSame('2026-12-31', $grade->fresh()->effective_to?->toDateString());

        // Brought back later as a new window; an overlapping one is refused.
        $this->actingAs($this->admin)->post(route('admin.cr-grades.store'), ['value' => '11', 'effective_from' => '2026-12-01'])
            ->assertSessionHasErrors('value');
        $this->actingAs($this->admin)->post(route('admin.cr-grades.store'), ['value' => '11', 'effective_from' => '2028-01-01'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, CrGradeValue::query()->where('value', 11)->count());
        $this->assertNotContains(11, $rules->gradesForYear(2027));
        $this->assertContains(11, $rules->gradesForYear(2028));

        $this->travelTo('2027-02-01');
        $this->actingAs($this->admin)->get(route('admin.cr-grades.index'))
            ->assertOk()
            ->assertSee('31-12-2026')
            ->assertSee('Inactive')
            ->assertSee('GAD order 12/2026');
    }

    public function test_a_grades_remarks_can_be_edited_and_the_old_wording_stays_in_the_audit(): void
    {
        config(['audit.console' => true]);
        $grade = CrGradeValue::query()->create(['value' => 12, 'effective_from' => '2021-01-01', 'remarks' => 'Old wording']);

        $this->actingAs($this->admin)->patch(route('admin.cr-grades.remarks', $grade), ['remarks' => 'क+ — उत्कृष्ट'])
            ->assertSessionHasNoErrors();

        $grade->refresh();
        $this->assertSame('क+ — उत्कृष्ट', $grade->remarks);
        $this->assertSame(12, $grade->value, 'Only the remarks change.');
        $this->assertSame('2021-01-01', $grade->effective_from->toDateString());

        $this->assertTrue(
            $grade->audits()->get()->contains(fn ($audit): bool => ($audit->old_values['remarks'] ?? null) === 'Old wording'),
            'The previous wording is kept in the audit trail.',
        );

        $this->actingAs($this->admin)->patch(route('admin.cr-grades.remarks', $grade), ['remarks' => str_repeat('x', 256)])
            ->assertSessionHasErrors('remarks');
    }

    public function test_only_the_administrator_sets_the_rules(): void
    {
        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)->get(route('admin.service-classes.promotion-rules.index', $this->classOne))->assertForbidden();
        $this->actingAs($officer)->post(route('admin.cr-grades.store'), ['value' => '4', 'effective_from' => '2027-01-01'])->assertForbidden();
        $this->actingAs($officer)->patch(route('admin.cr-grades.remarks', CrGradeValue::query()->firstOrFail()), ['remarks' => 'x'])->assertForbidden();
        $this->actingAs($officer)->patch(route('admin.cr-grades.deactivate', CrGradeValue::query()->firstOrFail()), [
            'effective_to' => now()->toDateString(), 'deactivation_reason' => 'x',
        ])->assertForbidden();
    }
}
