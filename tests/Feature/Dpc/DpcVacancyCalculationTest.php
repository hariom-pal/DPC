<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Derived vacancy, the Zone of Consideration, and the gates on both (business
 * directive, 2026-08-22).
 *
 *   Working Officers = rows on the Currently Working chart
 *   Total Vacancy    = Sanctioned Post Count − Working Officers
 *   Zone             = the scoring engine's zone rule, applied to Total Vacancy
 *
 * The directive's own worked example — sanctioned 10, working 7, vacancy 3, zone
 * 10 — is the first test, because it is the number the business will check.
 *
 * **Two cases where the directive's flat `(V × 2) + 4` and the deck disagree are
 * pinned here deliberately**, so that a later decision to override the deck is a
 * visible change to these assertions rather than a silent drift:
 *
 *   - one vacancy sizes the zone at 5, not 6 ({@see test_a_single_vacancy_uses_the_decks_top_five_rule});
 *   - a class pair the deck gives no formula for has no cap, and therefore no
 *     gate ({@see test_a_class_pair_with_no_zone_formula_has_no_gate}).
 */
class DpcVacancyCalculationTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private DpcProposal $proposal;

    private VacancyType $clear;

    private VacancyType $retirement;

    private ReservationCategory $ur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);
        $this->ao = User::factory()->create(['department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);

        $this->clear = VacancyType::query()->where('code', 'clear')->firstOrFail();
        $this->retirement = VacancyType::query()->where('code', 'from_retirement')->firstOrFail();
        $this->ur = ReservationCategory::query()->where('code', 'UR')->firstOrFail();

        $this->proposal = $this->proposalFor('I', 'I');
    }

    // ------------------------------------------------------------------ helpers

    private function proposalFor(string $fromClass, string $toClass): DpcProposal
    {
        $from = Cadre::query()->create([
            'department_id' => $this->department->id, 'name' => 'Feeder '.uniqid(),
            'class_level' => $fromClass, 'status' => 'active',
        ]);
        $to = Cadre::query()->create([
            'department_id' => $this->department->id, 'name' => 'Target '.uniqid(),
            'class_level' => $toClass, 'status' => 'active',
        ]);

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $to->id,
            'post_from_cadre_id' => $from->id,
            'proposal_date' => '2026-06-25',
        ]);

        return DpcProposal::query()->latest('id')->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function officers(int $count): array
    {
        return array_map(fn (int $sn): array => [
            'sn' => $sn,
            'name' => 'DS'.$sn,
            'dob' => '1972-03-20',
            'reservation_category_id' => $this->ur->id,
        ], range(1, max(0, $count)) === [0] ? [] : range(1, $count));
    }

    /**
     * @param  array<string, int>  $split  vacancy-type code => count
     * @return list<array<string, mixed>>
     */
    private function split(array $split): array
    {
        $types = ['clear' => $this->clear->id, 'from_retirement' => $this->retirement->id];

        return array_values(array_map(fn (string $code): array => [
            'vacancy_type_id' => $types[$code],
            'reservation_category_id' => $this->ur->id,
            'count' => $split[$code],
        ], array_keys($split)));
    }

    /** @param array<string, mixed> $payload */
    private function save(array $payload, ?DpcProposal $proposal = null): TestResponse
    {
        return $this->actingAs($this->ao)->put(
            route('dpc-proposals.working-officers.update', $proposal ?? $this->proposal),
            $payload,
        );
    }

    private function calculator(): VacancyCalculationService
    {
        return app(VacancyCalculationService::class);
    }

    // ------------------------------------------------ the directive's own example

    public function test_the_directives_worked_example(): void
    {
        // Sanctioned 10, imported 7 → Working 7, Total Vacancy 3, Zone 10.
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->officers(7),
            'vacancies' => $this->split(['clear' => 1, 'from_retirement' => 2]),
        ])->assertSessionHasNoErrors();

        $summary = $this->calculator()->summary($this->proposal->fresh());

        $this->assertSame(10, $summary['sanctioned']);
        $this->assertSame(7, $summary['working']);
        $this->assertSame(3, $summary['total_vacancy']);
        $this->assertSame(3, $summary['classified_total']);
        $this->assertTrue($summary['balanced']);
        $this->assertSame(10, $summary['required_seniority']);
        $this->assertSame('2 × 3 + 4', $summary['zone']->formula);
    }

    // ------------------------------------------------------- rule 4: the ceiling

    public function test_working_officers_may_not_exceed_the_sanctioned_strength(): void
    {
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->officers(12),
            'vacancies' => $this->split(['clear' => 0]),
        ])->assertSessionHasErrors(['officers' => 'Working Officers cannot exceed the sanctioned strength.']);

        // Nothing was written: the refusal is not a warning.
        $this->assertSame(0, $this->proposal->fresh()->workingOfficers()->count());
    }

    public function test_working_officers_equal_to_the_sanctioned_strength_is_allowed(): void
    {
        // A fully-staffed post has no vacancy. Unusual for a DPC, not invalid —
        // refusing it would be inventing a rule the directive does not state.
        $this->save([
            'sanctioned_post_count' => 3,
            'officers' => $this->officers(3),
            'vacancies' => [],
        ])->assertSessionHasNoErrors();

        $summary = $this->calculator()->summary($this->proposal->fresh());
        $this->assertSame(0, $summary['total_vacancy']);
        $this->assertFalse($summary['exceeds_sanctioned']);
    }

    // --------------------------------------------- rule 6: the classification sum

    public function test_the_vacancy_split_must_equal_the_calculated_total(): void
    {
        // The directive's invalid example: calculated 3, split totals 4.
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->officers(7),
            'vacancies' => $this->split(['clear' => 2, 'from_retirement' => 2]),
        ])->assertSessionHasErrors('vacancies');

        $this->assertStringContainsString(
            'The sum of all vacancy types must equal the calculated total vacancy.',
            session('errors')->first('vacancies'),
        );
    }

    public function test_a_split_that_is_short_is_refused_too(): void
    {
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->officers(7),
            'vacancies' => $this->split(['clear' => 1]),
        ])->assertSessionHasErrors('vacancies');
    }

    public function test_a_zero_in_one_type_is_fine_as_long_as_the_total_matches(): void
    {
        // The directive's valid example, with Deputation at 0.
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->officers(7),
            'vacancies' => $this->split(['clear' => 3, 'from_retirement' => 0]),
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->calculator()->summary($this->proposal->fresh())['balanced']);
    }

    /**
     * **Why this invariant is worth more than it looks.**
     *
     * The scoring engine fills seats from the sum of the vacancy rows, not from
     * the sanctioned strength. Before this rule a department could record four
     * classified vacancies against a derived total of three, and the engine
     * would have promoted four officers. The invariant makes the number M5 acts
     * on equal the number the screen shows.
     */
    public function test_the_balanced_total_is_the_number_the_engine_will_use(): void
    {
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->officers(7),
            'vacancies' => $this->split(['clear' => 2, 'from_retirement' => 1]),
        ])->assertSessionHasNoErrors();

        $proposal = $this->proposal->fresh();

        $this->assertSame(
            $this->calculator()->totalVacancy($proposal),
            (int) $proposal->vacancies()->sum('count'),
        );
    }

    // ------------------------------------------------------ the zone, and the deck

    public function test_the_zone_follows_the_vacancy(): void
    {
        foreach ([2 => 8, 3 => 10, 4 => 12, 10 => 24] as $vacancy => $expectedZone) {
            $this->assertSame(
                $expectedZone,
                $this->calculator()->requiredSeniorityCount($this->proposal, $vacancy),
                "vacancy {$vacancy} should size the zone at {$expectedZone}",
            );
        }
    }

    public function test_a_single_vacancy_uses_the_decks_top_five_rule(): void
    {
        // The directive says `(V × 2) + 4`, which would give 6. The deck's
        // sizing gives *top 5* for a single vacancy, and the deck governs
        // (AD-1) — so this is 5, and the divergence is deliberate and recorded.
        $this->assertSame(5, $this->calculator()->requiredSeniorityCount($this->proposal, 1));
    }

    public function test_no_vacancy_means_no_zone_and_no_gate(): void
    {
        $this->assertNull($this->calculator()->requiredSeniorityCount($this->proposal, 0));
    }

    public function test_a_class_pair_with_no_zone_formula_has_no_gate(): void
    {
        // The deck states a formula only for Class-I → Class-I. For every other
        // pair it says "other cases": each officer meeting the experience
        // qualification is considered, with no arithmetic cap. There is
        // therefore no exact count to require, and the gate cannot apply.
        $classTwo = $this->proposalFor('II', 'II');

        $zone = $this->calculator()->zone($classTwo, 3);

        $this->assertTrue($zone->isUncapped());
        $this->assertNull($this->calculator()->requiredSeniorityCount($classTwo, 3));
    }

    public function test_the_zone_is_the_engines_zone_and_not_a_second_implementation(): void
    {
        // AD-19: a rule is re-applied through the existing mechanism, never
        // through a second code path. Changing the configured multiplier must
        // move the proposal screen too — if it does not, there are two formulas.
        Config::set('dpc.scoring.zone.rules.I-I', [
            'single_vacancy_size' => 7, 'multiplier' => 3, 'addend' => 1,
        ]);

        $this->assertSame(10, $this->calculator()->requiredSeniorityCount($this->proposal, 3));
        $this->assertSame(7, $this->calculator()->requiredSeniorityCount($this->proposal, 1));
    }

    // ------------------------------------------- rule 3: the seniority-list gate

    /** @return list<array<string, mixed>> */
    private function seniority(int $count): array
    {
        return array_map(fn (int $sn): array => [
            'seniority_number' => $sn,
            'name' => 'US'.$sn,
            'dob' => '1964-03-20',
            'reservation_category_id' => $this->ur->id,
        ], range(1, $count));
    }

    private function withVacancyOf(int $vacancy): void
    {
        app(DpcProposalService::class)->saveVacancies(
            $this->proposal,
            $vacancy,
            $this->split(['clear' => $vacancy]),
        );
    }

    public function test_a_seniority_list_matching_the_zone_is_accepted(): void
    {
        $this->withVacancyOf(3); // zone 10

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['entries' => $this->seniority(10)],
        )->assertSessionHasNoErrors();

        $this->assertSame(10, $this->proposal->fresh()->seniorityEntries()->count());
    }

    public function test_a_seniority_list_longer_than_the_zone_is_refused(): void
    {
        // The directive's example: zone 8, uploaded 10.
        $this->withVacancyOf(2); // zone 8

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['entries' => $this->seniority(10)],
        )->assertSessionHasErrors([
            'entries' => 'The uploaded Seniority List must contain exactly 8 officers based on the calculated Zone of Consideration.',
        ]);

        $this->assertSame(0, $this->proposal->fresh()->seniorityEntries()->count());
    }

    public function test_a_seniority_list_shorter_than_the_zone_is_refused(): void
    {
        // The directive's example: zone 12, uploaded 9.
        $this->withVacancyOf(4); // zone 12

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['entries' => $this->seniority(9)],
        )->assertSessionHasErrors([
            'entries' => 'The uploaded Seniority List must contain exactly 12 officers based on the calculated Zone of Consideration.',
        ]);
    }

    public function test_the_gate_is_silent_before_the_sanctioned_strength_is_known(): void
    {
        // No vacancy can be derived yet, so no count can be required. Refusing
        // here would block the officer from filling the sections in any order
        // but one — which the S14→S18 flow does not require.
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['entries' => $this->seniority(3)],
        )->assertSessionHasNoErrors();
    }

    // ------------------------------------------------- rule 4: continuing is blocked

    public function test_an_unbalanced_proposal_cannot_be_submitted(): void
    {
        // Reached by editing sections in an order no single request validates:
        // save a balanced set, then lower the sanctioned strength on its own.
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->officers(7),
            'vacancies' => $this->split(['clear' => 3]),
        ])->assertSessionHasNoErrors();

        $this->proposal->forceFill(['sanctioned_post_count' => 5])->saveQuietly();

        $missing = app(DpcProposalCompletenessService::class)->missingSections($this->proposal->fresh());

        $this->assertContains('Working Officers & Vacancies (S16)', $missing);
    }

    public function test_a_ceiling_breach_created_after_the_fact_blocks_submission(): void
    {
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->officers(7),
            'vacancies' => $this->split(['clear' => 3]),
        ])->assertSessionHasNoErrors();

        // Sanctioned strength cut below the officer chart by a later edit.
        $this->proposal->forceFill(['sanctioned_post_count' => 4])->saveQuietly();

        $checklist = collect(app(DpcProposalCompletenessService::class)->checklist($this->proposal->fresh()))
            ->firstWhere('key', 'working_officers');

        $this->assertFalse($checklist['complete']);
        $this->assertStringContainsString('cannot exceed the sanctioned strength', $checklist['detail']);
    }

    public function test_a_seniority_list_that_misses_the_zone_blocks_submission(): void
    {
        $this->withVacancyOf(3); // zone 10
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, $this->seniority(4), $this->ao);

        $checklist = collect(app(DpcProposalCompletenessService::class)->checklist($this->proposal->fresh()))
            ->firstWhere('key', 'seniority_list');

        $this->assertFalse($checklist['complete']);
        $this->assertStringContainsString('exactly 10 officers', $checklist['detail']);
    }

    // --------------------------------------------------------- standing them down

    public function test_each_gate_can_be_stood_down_independently(): void
    {
        Config::set('dpc.vacancy.enforce_sanctioned_ceiling', false);
        Config::set('dpc.vacancy.enforce_classification_balance', false);

        // 12 officers against a sanctioned 10, and a split matching neither.
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->officers(12),
            'vacancies' => $this->split(['clear' => 1]),
        ])->assertSessionHasNoErrors();

        Config::set('dpc.vacancy.enforce_seniority_matches_zone', false);

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['entries' => $this->seniority(2)],
        )->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_screen_shows_the_three_derived_values_read_only(): void
    {
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->officers(7),
            'vacancies' => $this->split(['clear' => 3]),
        ]);

        $response = $this->actingAs($this->ao)
            ->get(route('dpc-proposals.working-officers', $this->proposal))
            ->assertOk();

        $response->assertSee('Working Officers');
        $response->assertSee('Total Vacancy');
        $response->assertSee('Zone of Consideration');
        // Read-only, so the officer cannot type over a derived number.
        $response->assertSee('id="derived_working"', escape: false);
        $response->assertSee('id="derived_vacancy"', escape: false);
        $response->assertSee('id="derived_zone"', escape: false);
        $this->assertSame(
            3,
            substr_count((string) $response->getContent(), 'readonly'),
            'all three derived fields must be readonly, and nothing else on this screen should be',
        );
    }

    public function test_the_seniority_screen_states_the_required_count(): void
    {
        $this->withVacancyOf(3);

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.seniority-list', $this->proposal))
            ->assertOk()
            ->assertSee('Zone of Consideration')
            ->assertSee('exactly 10');
    }
}
