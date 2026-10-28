<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\Role;
use Database\Seeders\Support\PptPromotionRules;

/**
 * Raising a DPC in a fixture, now that a DPC comes from a Pre-DPC (change item
 * 11).
 *
 * **Why the Pre-DPC is written directly rather than driven through its workflow.**
 * A fixture needs *an approved order to exist*; it is not testing how one comes to
 * be approved. Driving the real workflow here would make every DPC test depend on
 * a fake filesystem for the signature and on the Administrative Head's role, and a
 * failure in Pre-DPC approval would then break sixty tests that have nothing to
 * say about it. The workflow itself is covered by `PreDpcWorkflowTest` and
 * `PreDpcExerciseTest`, which is where a break in it should surface.
 *
 * **The DPC, by contrast, is raised over HTTP** through the real controller,
 * request and service — because that path is what most of these tests are
 * exercising the consequences of.
 */
trait RaisesDpcProposals
{
    use AllotsProposals;

    /**
     * An approved, active Pre-DPC sanctioning promotion from `$from` into `$to`.
     *
     * The values on the order are copied off the posts exactly as
     * `PostAttributeResolver` would (item 5) — the Pay Scale and Class from the
     * **To** post because they describe the post being filled, and the qualifying
     * service from the **From** post because that is what an officer serves.
     *
     * **One order, one Promotion To Post, several sources.** Asking for a second
     * feeder into a post this fixture has already sanctioned adds a source to the
     * existing order rather than minting a second one — which is both what the
     * unique index permits and what the business does: a single Government Order
     * lists every post its promotion may draw on.
     */
    protected function sanctioningPreDpc(DepartmentPost|int $from, DepartmentPost|int $to, int $year = 2026): PreDpcProposal
    {
        // Ids are accepted as readily as models: most fixtures already hold the id
        // because that is what the old payload sent.
        $from = $from instanceof DepartmentPost ? $from : DepartmentPost::query()->findOrFail($from);

        // A DPC is raised only under a Class Master promotion rule (2026-10-21). A
        // test that records its own rules has them already; any other gets the PPT's,
        // on the Eligible CR Grades' points, none of whose years is mandatory.
        PptPromotionRules::record(['I-I' => 35, 'II-I' => 31, 'II-II' => 27, 'default' => 25], tribalBonus: 2, superSeatPoints: 9);
        $to = $to instanceof DepartmentPost ? $to : DepartmentPost::query()->findOrFail($to);

        /*
         | Reuse the order if this exercise already has one in force.
         |
         | Item 9 permits exactly one active approved Pre-DPC per (department, to
         | post, year), and the unique index enforces it. A fixture that raises two
         | DPCs for the same exercise — which is legitimate, and is what item 19's
         | "additional vacancies" case looks like — must therefore raise both under
         | the *same* order rather than minting a second one.
         */
        $order = PreDpcProposal::query()
            ->activeApproved()
            ->where('department_id', $from->department_id)
            ->where('promotion_to_post_id', $to->id)
            ->where('dpc_year', $year)
            ->first();

        /*
         | `forceCreate`, because this fixture writes an order that is *already
         | approved and in force*.
         |
         | `status`, `active_flag`, `approved_at` and the rest are not mass-assignable
         | on `PreDpcProposal` — the service sets every one of them with `forceFill`,
         | after a `lockForUpdate` and a signature. That is the point: the fields that
         | say an order carries the force of a Government Order must not be settable
         | from a payload. Forcing them here is a fixture standing in for a workflow
         | it is not testing, and saying so.
         */
        $order ??= PreDpcProposal::query()->forceCreate([
            // Unique per fixture without needing the sequence generator: the
            // number's *format* is asserted in `PreDpcExerciseTest`, and a
            // predictable fixture number keeps failures readable.
            'pre_dpc_number' => sprintf('PDPC-%d-FIX-%04d', $year, PreDpcProposal::query()->count() + 1),
            'department_id' => $from->department_id,
            'dpc_year' => $year,
            'promotion_to_post_id' => $to->id,

            // The post being filled. Until 2026-08-26 the committee member-level
            // rule read this, which is why it was set here; that rule was withdrawn
            // with the Commission Members master (BD-29). Kept because
            // `class_level` as the class of the promotion post (item 16).
            'pay_scale_id' => $to->pay_scale_id,
            'class_level' => $to->class_level->value,

            'direct_recruitment_pct' => 0,
            'promotion_recruitment_pct' => 100,
            'version' => 1,
            'status' => PreDpcStatus::Approved->value,
            'active_flag' => 1,
            'government_order_number' => 'GO/FIXTURE/'.$year,
            'effective_from' => $year.'-01-01',
            'approved_at' => now(),
        ]);

        // `firstOrCreate` and not `create`: asking twice for the same feeder is a
        // fixture reusing an exercise, not an attempt to name it twice.
        $order->promotionSources()->firstOrCreate(
            ['promotion_from_post_id' => $from->id],
            [
                /*
                 | Every fixture source takes the whole quota, so an order that ends
                 | up with two of them sums to 200%.
                 |
                 | Deliberate. The shares-total-100 rule lives in
                 | `SavePreDpcProposalRequest` and is tested there against the real
                 | form; this writes to the model directly, and rebalancing existing
                 | rows each time a feeder is added would make a fixture's stored
                 | figures depend on the order its tests happen to call it in.
                 */
                'promotion_percentage' => 100,

                'min_experience_years' => 5,
                'experience_unit' => 'years',

                // The qualifying service on the post the officer currently holds.
                'promotion_duration_years' => $from->promotion_duration_years,
                'sort_order' => $order->promotionSources()->count(),
            ],
        );

        // Allotted as the Department Admin would (2026-10-26) to the department's
        // AO, when the test has created one; a test with several AOs re-allots.
        if ($this->departmentHasOfficer($order->department_id, Role::AuthorizedOfficer) && app(ProposalAllotmentService::class)->current($order) === null) {
            $this->allot($order);
        }

        return $order->load('promotionSources');
    }

    /**
     * The two fields the DPC screen still asks for, plus the order to raise under.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function dpcStorePayload(PreDpcProposal $preDpc, array $overrides = []): array
    {
        return array_replace([
            'pre_dpc_proposal_id' => $preDpc->id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'legal_stay' => '0',
        ], $overrides);
    }

    /**
     * Sanction the exercise, then raise a DPC under it over HTTP.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function raiseDpc(User $actor, DepartmentPost|int $from, DepartmentPost|int $to, array $overrides = [], int $year = 2026): DpcProposal
    {
        $preDpc = $this->sanctioningPreDpc($from, $to, $year);

        // The order is allotted to this AO (2026-10-26), and the DPC inherits it.
        $this->allot($preDpc, $actor);

        // Named explicitly rather than left to be inferred: the order may already
        // sanction several feeders, and the caller asked for this one.
        $payload = $this->dpcStorePayload($preDpc, array_replace(
            ['promotion_from_post_id' => $from instanceof DepartmentPost ? $from->id : $from],
            $overrides,
        ));

        $this->actingAs($actor)->post(route('dpc-proposals.store'), $payload);

        return DpcProposal::query()->latest('id')->firstOrFail();
    }
}
