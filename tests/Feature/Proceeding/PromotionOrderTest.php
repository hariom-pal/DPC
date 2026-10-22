<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Master\Models\SealedCoverReason;
use App\Domain\Proceeding\Services\ResultApprovalService;
use App\Domain\PromotionOrder\Models\DpcPromotionOrder;
use App\Domain\Signature\Models\Signature;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;
use Tests\Support\RecordingSmsSender;
use Tests\TestCase;

/**
 * Coder Instructions rule 4.3 (business decisions, 2026-10-21): the promotion order —
 * issued by the Administrative Head on the CGPSC's approved result, a signed PDF of
 * the Main List (an officer under sealed cover excluded), its link sent to the
 * officers and the committee, never rewritten and superseded by a correction.
 */
class PromotionOrderTest extends TestCase
{
    use BuildsProceedings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProceeding();

        RecordingSmsSender::reset();
        $this->app->bind(SmsSender::class, RecordingSmsSender::class);

        $this->officer(1, 'US1', $this->urCategoryId(), [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);
        $this->officer(2, 'US2', $this->urCategoryId(), [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);
        DpcEvaluationRow::query()->where('dpc_proposal_id', $this->proposal->id)->where('sn', 1)->update(['mobile' => '9876500001']);
    }

    private function approve(): void
    {
        $this->calculate();
        app(ResultApprovalService::class)->approve($this->proceeding->fresh(), $this->memberOne, request(), 2017);
    }

    private function issue(string $number = 'F-1/2026/PROMO', ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->administrativeHead)
            ->post(route('dpc-proposals.promotion-orders.store', $this->proposal), [
                'order_number' => $number, 'order_date' => now()->toDateString(), 'confirm' => '1',
            ]);
    }

    public function test_no_order_before_the_cgpsc_approves_the_result(): void
    {
        $this->actingAs($this->administrativeHead)->get(route('dpc-proposals.promotion-orders.index', $this->proposal))
            ->assertOk()
            ->assertSee('id="promotion-order-not-ready"', false);

        $this->issue()->assertSessionHasErrors('order');
        $this->assertSame(0, DpcPromotionOrder::query()->count());
    }

    public function test_the_administrative_head_issues_a_signed_pdf_of_the_main_list(): void
    {
        $this->approve();

        $this->actingAs($this->authorizedOfficer)->post(route('dpc-proposals.promotion-orders.store', $this->proposal), [
            'order_number' => 'X', 'order_date' => now()->toDateString(), 'confirm' => '1',
        ])->assertForbidden();

        $this->issue()->assertSessionHasNoErrors();

        $order = DpcPromotionOrder::query()->sole();
        $this->assertTrue($order->isIssued());
        $this->assertSame(['US1'], array_column($order->officers, 'name'), 'One vacancy: the Main List only.');

        $pdf = (string) Storage::disk((string) $order->disk)->get((string) $order->path);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(hash('sha256', $pdf), $order->content_hash);
        $this->assertSame($this->administrativeHead->id, Signature::query()->findOrFail($order->signature_id)->user_id);

        $this->actingAs($this->authorizedOfficer)->get(route('dpc-proposals.promotion-orders.download', [$this->proposal, $order]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_link_goes_to_the_officers_and_opens_only_when_signed(): void
    {
        $this->approve();
        $this->issue()->assertSessionHasNoErrors();
        $order = DpcPromotionOrder::query()->sole();

        $this->assertContains('9876500001', RecordingSmsSender::mobiles(), 'The promoted officer is told.');
        $this->assertNotNull($order->refresh()->notified_at);

        $this->get(URL::signedRoute('promotion-orders.public', ['order' => $order->uuid]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('promotion-orders.public', ['order' => $order->uuid]))->assertForbidden();
    }

    public function test_a_corrected_order_supersedes_the_first_which_is_never_rewritten(): void
    {
        $this->approve();
        $this->issue('F-1/2026/PROMO')->assertSessionHasNoErrors();
        $this->issue('F-1/2026/PROMO-CORR')->assertSessionHasNoErrors();

        [$first, $second] = DpcPromotionOrder::query()->orderBy('id')->get()->all();
        $this->assertSame([DpcPromotionOrder::STATUS_SUPERSEDED, DpcPromotionOrder::STATUS_ISSUED], [$first->status, $second->status]);
        $this->assertSame([1, 2, $first->id], [$first->version, $second->version, $second->supersedes_id]);

        $this->expectException(RuntimeException::class);
        $first->forceFill(['order_number' => 'TAMPERED'])->save();
    }

    public function test_an_officer_under_sealed_cover_is_not_promoted_by_the_order(): void
    {
        DpcEvaluationRow::query()->where('dpc_proposal_id', $this->proposal->id)->where('sn', 1)->update([
            'vigilance_pending' => true,
            'sealed_cover_reason_id' => SealedCoverReason::query()->where('code', 'de_chargesheet')->value('id'),
        ]);
        $this->approve();

        // The one seat is held for the sealed officer: nobody is promoted by an order.
        $this->issue()->assertSessionHasErrors(['order' => __('dpc.promotion_orders.nobody_promoted')]);
    }
}
