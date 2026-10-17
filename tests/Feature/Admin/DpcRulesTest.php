<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\CrScoringScale;
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
            ->assertSee(__('dpcrules.ph_pointer'));

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
    }

    public function test_the_supporting_document_can_be_read_back(): void
    {
        $this->recordScale()->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('admin.dpc-rules.cr-scales.document', CrScoringScale::query()->sole()))
            ->assertOk()->assertDownload('scale.pdf');
    }

    public function test_only_the_administrator_manages_the_rules(): void
    {
        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)->get(route('admin.dpc-rules.index'))->assertForbidden();
        $this->actingAs($officer)->post(route('admin.dpc-rules.cr-scales.store'), [])->assertForbidden();
    }
}
