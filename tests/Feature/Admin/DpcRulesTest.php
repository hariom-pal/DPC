<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\CrScoringScale;
use App\Domain\Eligibility\Models\PhReservationRule;
use App\Domain\Eligibility\Services\DpcRuleBook;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DPC Rules: the CR marks, qualifying scores and PH reservation, version by version,
 * each from a DPC year and on an order with its PDF (rules 2.1, 3.4, 3.5).
 */
class DpcRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);
    }

    private function pdf(string $name = 'order.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }

    /**
     * Rule 3.4's new scale.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function recordScale(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.dpc-rules.cr-scales.store'), array_replace_recursive([
            'name' => 'New online scale (rule 3.4)',
            'effective_from_year' => '2027',
            'grade_marks' => ['क+' => '9', 'क' => '7', 'ख' => '5', 'ग' => '3', 'घ' => '1'],
            'scoring_years' => '5',
            'tribal_area_bonus' => '1',
            'disqualifying_grade' => 'घ',
            'last_year_forbidden_grades' => ['I-I' => 'ग'],
            'super_seat_grade' => 'क+',
            'qualifying_scores' => ['I-I' => '35', 'II-I' => '30', 'II-II' => '27', 'default' => '25'],
            'order_number' => 'GAD/2027/11',
            'order_date' => now()->subDay()->toDateString(),
            'document' => $this->pdf('scale.pdf'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recordPh(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.dpc-rules.ph-rules.store'), [
            'effective_from_year' => '2027',
            'percentage' => '4',
            'rounding' => 'floor',
            'order_number' => 'GAD/PH/2027/1',
            'order_date' => now()->subDay()->toDateString(),
            'document' => $this->pdf('ph.pdf'),
            ...$overrides,
        ]);
    }

    private function proposalOfYear(int $year): DpcProposal
    {
        return (new DpcProposal)->forceFill(['dpc_year' => $year, 'proposal_date' => "{$year}-03-01"]);
    }

    public function test_the_screen_shows_the_built_in_rules_until_a_version_is_recorded(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dpc-rules.index', ['year' => 2027]))
            ->assertOk()
            ->assertSee(__('dpcrules.built_in'))
            ->assertSee(__('dpcrules.cr.heading', ['year' => 2027]))
            ->assertSee(__('dpcrules.ph.heading', ['year' => 2027]));

        $rules = app(DpcRuleBook::class)->ruleSetFor($this->proposalOfYear(2027));
        $this->assertSame(config('dpc.scoring.rule_version'), $rules->version);
        $this->assertSame(4, $rules->crGradeMarks['क+']);
    }

    public function test_a_new_scale_applies_from_its_dpc_year_and_earlier_years_keep_theirs(): void
    {
        $this->recordScale()->assertSessionHasNoErrors()->assertRedirect(route('admin.dpc-rules.index', ['year' => 2027]));

        $scale = CrScoringScale::query()->sole();
        $this->assertSame(['क+' => 9, 'क' => 7, 'ख' => 5, 'ग' => 3, 'घ' => 1], $scale->grade_marks);
        $this->assertSame($this->admin->id, $scale->recorded_by);

        $book = app(DpcRuleBook::class);
        $from2027 = $book->ruleSetFor($this->proposalOfYear(2028));
        $this->assertSame(9, $from2027->crGradeMarks['क+']);
        $this->assertSame(35, $from2027->qualifyingScores['I-I']);
        $this->assertStringContainsString('+cr-scale:'.$scale->uuid, $from2027->version);

        $before = $book->ruleSetFor($this->proposalOfYear(2026));
        $this->assertSame(4, $before->crGradeMarks['क+']);
        $this->assertSame(15, $before->qualifyingScores['I-I']);

        $this->actingAs($this->admin)->get(route('admin.dpc-rules.index', ['year' => 2027]))
            ->assertOk()
            ->assertSee('New online scale (rule 3.4)')
            ->assertSee('GAD/2027/11');
    }

    public function test_a_later_version_for_the_same_year_wins_and_the_earlier_stays_in_the_history(): void
    {
        $this->recordScale()->assertSessionHasNoErrors();
        $this->recordScale(['name' => 'Corrected scale', 'qualifying_scores' => ['I-I' => '36'], 'order_number' => 'GAD/2027/12'])->assertSessionHasNoErrors();

        $this->assertSame(2, CrScoringScale::query()->count());
        $this->assertSame(36, app(DpcRuleBook::class)->ruleSetFor($this->proposalOfYear(2027))->qualifyingScores['I-I']);

        $this->actingAs($this->admin)->get(route('admin.dpc-rules.index'))
            ->assertSee('New online scale (rule 3.4)')
            ->assertSee('Corrected scale');
    }

    public function test_a_scale_must_rank_the_grades_and_be_reachable_and_cite_its_order(): void
    {
        $this->recordScale(['grade_marks' => ['क' => '9']])->assertSessionHasErrors('grade_marks.क');
        $this->recordScale(['qualifying_scores' => ['I-I' => '99']])->assertSessionHasErrors('qualifying_scores.I-I');
        $this->recordScale(['document' => null])->assertSessionHasErrors('document');
        $this->recordScale(['order_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('order_date');

        $this->assertSame(0, CrScoringScale::query()->count());
    }

    public function test_the_form_starts_from_the_rules_in_force(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dpc-rules.cr-scales.create', ['year' => 2027]))
            ->assertOk()
            ->assertSee('name="grade_marks[क+]"', false)
            ->assertSee('name="qualifying_scores[I-I]"', false)
            ->assertSee('name="document"', false);

        $this->actingAs($this->admin)->get(route('admin.dpc-rules.ph-rules.create', ['year' => 2027]))
            ->assertOk()
            ->assertSee('name="percentage"', false)
            ->assertSee('value="3"', false)
            ->assertSee(__('dpcrules.ph.roundings.floor'));
    }

    public function test_the_ph_reservation_follows_the_version_of_the_dpc_year(): void
    {
        $book = app(DpcRuleBook::class);
        $this->assertSame('3.00', $book->phTermsFor(2027)['percentage']);

        $this->recordPh()->assertSessionHasNoErrors();
        $this->assertSame(1, PhReservationRule::query()->count());

        $this->assertSame('4.00', $book->phTermsFor(2027)['percentage']);
        $this->assertSame('3.00', $book->phTermsFor(2026)['percentage']);
    }

    public function test_ph_posts_drop_the_decimal_part_as_rule_two_one_says(): void
    {
        $book = app(DpcRuleBook::class);

        $this->assertSame(0, $book->phPosts(33, '3.00', 'floor'));   // 0.99
        $this->assertSame(2, $book->phPosts(67, '3.00', 'floor'));   // 2.01
        $this->assertSame(3, $book->phPosts(100, '3.00', 'floor'));  // exactly 3, never 2.999…
        $this->assertSame(1, $book->phPosts(33, '3.00', 'round'));
        $this->assertSame(1, $book->phPosts(1, '3.00', 'ceil'));
        $this->assertSame(2, $book->phPosts(100, '2.50', 'floor'));
    }

    public function test_a_ph_rule_is_refused_beside_its_field(): void
    {
        $this->recordPh(['percentage' => '101'])->assertSessionHasErrors('percentage');
        $this->recordPh(['rounding' => 'sideways'])->assertSessionHasErrors('rounding');
        $this->recordPh(['document' => null])->assertSessionHasErrors('document');

        $this->assertSame(0, PhReservationRule::query()->count());
    }

    public function test_the_supporting_document_can_be_read_back(): void
    {
        $this->recordScale()->assertSessionHasNoErrors();
        $this->recordPh()->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('admin.dpc-rules.cr-scales.document', CrScoringScale::query()->sole()))
            ->assertOk()->assertDownload('scale.pdf');
        $this->actingAs($this->admin)->get(route('admin.dpc-rules.ph-rules.document', PhReservationRule::query()->sole()))
            ->assertOk()->assertDownload('ph.pdf');
    }

    public function test_only_the_administrator_manages_the_rules(): void
    {
        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)->get(route('admin.dpc-rules.index'))->assertForbidden();
        $this->actingAs($officer)->post(route('admin.dpc-rules.cr-scales.store'), [])->assertForbidden();
        $this->actingAs($officer)->post(route('admin.dpc-rules.ph-rules.store'), [])->assertForbidden();
    }
}
