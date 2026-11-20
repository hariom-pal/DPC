<?php

declare(strict_types=1);

namespace Tests\Feature\CandidateVerification;

use App\Domain\Authentication\Models\OtpVerification;
use App\Domain\CandidateVerification\Enums\GrievanceStatus;
use App\Domain\CandidateVerification\Enums\ParticipantStatus;
use App\Domain\CandidateVerification\Jobs\SendVerificationNotification;
use App\Domain\CandidateVerification\Models\Grievance;
use App\Domain\CandidateVerification\Models\VerificationDecision;
use App\Domain\CandidateVerification\Models\VerificationNotification;
use App\Domain\CandidateVerification\Models\VerificationSnapshot;
use App\Domain\CandidateVerification\Services\CandidateVerificationService;
use App\Domain\CandidateVerification\Services\VerificationNotifier;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Models\Signature;
use App\Domain\Signature\Models\SignatureAttempt;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\CandidateVerification\Concerns\BuildsVerification;
use Tests\TestCase;

/**
 * Candidate Verification & Grievance (2026-11-19), the candidate's side: mobile and
 * OTP sign-in, their own record in four sections and nobody else's confidential data,
 * agreements and grievance drafts, one final submission signed once, the
 * acknowledgement, and the messages' delivery records.
 */
class CandidatePortalTest extends TestCase
{
    use BuildsVerification;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 11:30:00');
        $this->bootVerification(days: 10);
        $this->approveByAh()->assertSessionHasNoErrors();
        auth()->logout();
    }

    // ---------------------------------------------------------------- sign-in

    public function test_the_home_page_leads_a_candidate_to_the_verification_sign_in(): void
    {
        // In the Self-Inspection tile's place (user, 2026-11-20).
        $this->get('/')->assertOk()
            ->assertSee(route('candidate-verification.login'), false)
            ->assertSee(__('landing.quick_actions.candidate_verification.title'))
            ->assertDontSee(route('self-inspection.landing').'"', false);

        // …and the staff sign-in page, for an officer who would try to log in there.
        $this->get(route('login'))->assertOk()
            ->assertSee(route('candidate-verification.login'), false)
            ->assertDontSee(route('self-inspection.landing').'"', false);
    }

    public function test_a_registered_mobile_and_its_otp_sign_in_and_others_learn_nothing(): void
    {
        // An unknown number: the same answer, no code issued.
        $this->post(route('candidate-verification.code.send'), ['mobile' => '9999999999'])->assertRedirect(route('candidate-verification.code'))
            ->assertSessionHas('status', __('candidate.verification.login.sent'));
        $this->assertSame(0, OtpVerification::query()->count());

        // A registered one: a code, kept hashed.
        $this->post(route('candidate-verification.code.send'), ['mobile' => '+91 '.$this->mobileOf(1)])->assertSessionHas('status', __('candidate.verification.login.sent'));
        $otp = OtpVerification::query()->sole();
        $this->assertNotSame('123456', $otp->code_hash);
        $this->assertSame('candidate_verification', $otp->purpose);

        // Asked again at once: the cooldown.
        $this->post(route('candidate-verification.code.send'), ['mobile' => $this->mobileOf(1)])->assertSessionHas('status', __('candidate.verification.login.wait'));

        // A wrong code fails; the right one signs in to the officer's own DPC only.
        $this->post(route('candidate-verification.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post(route('candidate-verification.verify'), ['code' => '123456'])->assertRedirect(route('candidate-verification.dashboard'));
        $this->get(route('candidate-verification.dashboard'))->assertOk()
            ->assertSee('data-participant="'.$this->participant(1)->uuid.'"', false)
            ->assertDontSee('data-participant="'.$this->participant(2)->uuid.'"', false);
    }

    public function test_an_expired_code_and_too_many_attempts_are_refused(): void
    {
        $this->post(route('candidate-verification.code.send'), ['mobile' => $this->mobileOf(1)]);
        Carbon::setTestNow(now()->addSeconds((int) config('dpc.otp.ttl_seconds') + 1));
        $this->post(route('candidate-verification.verify'), ['code' => '123456'])->assertSessionHasErrors('code');

        Carbon::setTestNow(now()->addHour());
        $this->post(route('candidate-verification.code.send'), ['mobile' => $this->mobileOf(1)]);
        for ($i = 0; $i < (int) config('dpc.otp.max_attempts'); $i++) {
            $this->post(route('candidate-verification.verify'), ['code' => '000000']);
        }
        $this->post(route('candidate-verification.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->get(route('candidate-verification.dashboard'))->assertRedirect(route('candidate-verification.login'));
    }

    public function test_a_shared_mobile_asks_for_the_employee_id_and_never_picks_an_officer(): void
    {
        $this->row(2)->forceFill(['mobile' => $this->mobileOf(1)])->save();

        $this->post(route('candidate-verification.code.send'), ['mobile' => $this->mobileOf(1)]);
        $this->post(route('candidate-verification.verify'), ['code' => '123456'])->assertRedirect(route('candidate-verification.identify'));
        $this->get(route('candidate-verification.dashboard'))->assertRedirect(route('candidate-verification.login'));

        $this->post(route('candidate-verification.identify.store'), ['employee_number' => 'NOBODY'])->assertSessionHasErrors('employee_number');
        $this->post(route('candidate-verification.identify.store'), ['employee_number' => strtolower((string) $this->row(2)->employee_number)])->assertRedirect(route('candidate-verification.dashboard'));
        $this->get(route('candidate-verification.dashboard'))
            ->assertSee('data-participant="'.$this->participant(2)->uuid.'"', false)
            ->assertDontSee('data-participant="'.$this->participant(1)->uuid.'"', false);
    }

    public function test_the_session_ends_after_its_minutes_without_use(): void
    {
        $this->signIn(1);
        Carbon::setTestNow(now()->addMinutes((int) config('dpc.candidate_verification.session_minutes') + 1));
        $this->get(route('candidate-verification.dashboard'))->assertRedirect(route('candidate-verification.login'));
    }

    // ----------------------------------------------------------- own record, privacy

    public function test_the_basic_details_map_to_their_sources_and_nobody_elses_are_shown(): void
    {
        $row = $this->row(1);
        $other = $this->row(2);
        $row->forceFill(['date_of_joining_service' => '2001-07-01', 'date_of_posting_on_promotion_from_post' => '2015-03-02', 'promotion_from_post' => 'Under Secretary'])->save();
        $row = $row->fresh();
        $other->forceFill(['dob' => '1972-05-14'])->save();
        $other = $other->fresh();
        $this->signIn(1);
        $uuid = $this->participant(1)->uuid;

        $html = (string) $this->get(route('candidate-verification.section', [$uuid, 'seniority']))->assertOk()->getContent();
        foreach ([
            'name' => $row->name, 'employee_number' => (string) $row->employee_number, 'dob' => $row->dob->format('d-m-Y'),
            'mobile' => $this->mobileOf(1), 'date_of_joining_service' => '01-07-2001', 'date_of_joining_current_post' => '02-03-2015',
            'seniority_number' => (string) $row->sn, 'designation' => (string) $row->promotion_from_post,
        ] as $field => $value) {
            $this->assertMatchesRegularExpression('/data-field="'.$field.'">\s*'.preg_quote($value, '/').'\s*</u', $html, $field);
        }
        // No source for these: shown as not recorded, never invented.
        $this->assertMatchesRegularExpression('/data-field="gender">\s*<span class="text-body-secondary">'.preg_quote(__('candidate.verification.fields.unavailable'), '/').'/u', $html);

        // The list carries the other officer's name, not their date of birth or mobile.
        $this->assertStringContainsString($other->name, $html);
        $this->assertStringNotContainsString($other->dob->format('d-m-Y'), $html);
        $this->assertStringNotContainsString($this->mobileOf(2), $html);

        // Another officer's verification, by UUID: not found.
        $this->get(route('candidate-verification.section', [$this->participant(2)->uuid, 'cr']))->assertNotFound();
        $this->get(route('candidate-verification.review', $this->participant(2)->uuid))->assertNotFound();
        $this->get(route('candidate-verification.section', [$uuid, 'nonsense']))->assertNotFound();
    }

    public function test_each_section_shows_the_candidates_own_records(): void
    {
        $this->signIn(1);
        $uuid = $this->participant(1)->uuid;
        $row = $this->row(1);
        $year = (int) $row->crGrades()->max('year');

        $this->get(route('candidate-verification.section', [$uuid, 'cr']))->assertOk()->assertSee('data-cr-year="'.$year.'"', false)->assertSee('id="cr-score"', false);
        $this->get(route('candidate-verification.section', [$uuid, 'integrity']))->assertOk()->assertSee('id="section-integrity"', false);
        $this->get(route('candidate-verification.section', [$uuid, 'ipr']))->assertOk()->assertSee('id="ipr-years"', false);
    }

    // ----------------------------------------------------- decisions and grievances

    public function test_drafts_change_freely_and_every_section_is_needed_before_the_submission(): void
    {
        $this->signIn(1);
        $uuid = $this->participant(1)->uuid;

        // I Agree & Next moves on.
        $this->post(route('candidate-verification.agree', [$uuid, 'seniority']))->assertRedirect(route('candidate-verification.section', [$uuid, 'cr']));
        // A grievance: validated.
        $this->grievance($uuid, 'cr', ['remark' => ''])->assertSessionHasErrors('remark');
        $this->grievance($uuid, 'cr', ['undertaking' => null])->assertSessionHasErrors('undertaking');
        $this->grievance($uuid, 'cr', ['document' => null])->assertSessionHasErrors('document');
        $this->grievance($uuid, 'cr', ['document' => UploadedFile::fake()->create('evidence.png', 20, 'image/png')])->assertSessionHasErrors('document');
        $this->grievance($uuid, 'cr', ['document' => $this->pdf('big.pdf', (int) config('dpc.candidate_verification.max_document_kb') + 1)])->assertSessionHasErrors('document');
        $this->grievance($uuid, 'cr', ['category' => 'filing_late'])->assertSessionHasErrors('category');
        $this->grievance($uuid, 'cr', ['related_year' => 1990])->assertSessionHasErrors('document');
        $this->assertSame(0, Grievance::query()->count());

        // Saved as a draft, then edited (the PDF kept), then removed, then raised again.
        $this->grievance($uuid, 'cr')->assertRedirect(route('candidate-verification.section', [$uuid, 'integrity']));
        $first = Grievance::query()->sole();
        $this->assertSame(GrievanceStatus::Draft, $first->status);
        $this->grievance($uuid, 'cr', ['remark' => 'Corrected remark.', 'document' => null])->assertSessionHasNoErrors();
        $this->assertSame([$first->path, 'Corrected remark.'], [$first->fresh()->path, $first->fresh()->remark]);
        $this->delete(route('candidate-verification.grievance.remove', [$uuid, 'cr']))->assertSessionHasNoErrors();
        $this->assertSame(0, Grievance::query()->count());
        Storage::disk('local')->assertMissing($first->path);
        $this->grievance($uuid, 'cr')->assertSessionHasNoErrors();

        // Not yet every section: the review says so and the submission is refused.
        $this->get(route('candidate-verification.review', $uuid))->assertOk()->assertSee(__('candidate.verification.errors.section_pending', ['section' => __('candidate.verification.sections.integrity')]));
        $this->post(route('candidate-verification.submit', $uuid), ['declaration' => '1', 'consent' => '1'])->assertSessionHasErrors('verification');

        // An agreement replaces the section's draft grievance.
        $this->post(route('candidate-verification.agree', [$uuid, 'cr']))->assertSessionHasNoErrors();
        $this->assertSame(0, Grievance::query()->count());
        $this->assertSame(VerificationDecision::AGREED, VerificationDecision::query()->where('section', 'cr')->value('decision'));
    }

    // ------------------------------------------------------------- the final eSign

    public function test_one_signed_submission_and_nothing_after_it(): void
    {
        $participant = $this->verifyAndSubmit(1, ['cr', 'ipr']);

        $this->assertSame(ParticipantStatus::GrievanceSubmitted, $participant->status);
        $this->assertNotNull($participant->signature_id);
        $this->assertSame(64, strlen((string) $participant->snapshot_sha256));
        // Reproducible from the stored copy, on MySQL too (its JSON column reorders keys).
        $this->assertSame(VerificationSnapshot::hashOf((array) $participant->fresh()->submitted_snapshot), $participant->snapshot_sha256);
        $this->assertSame(['submitted'], Grievance::query()->pluck('status')->map(fn ($s) => $s->value)->unique()->values()->all());
        $this->assertSame(1, Signature::query()->where('signable_type', $participant->getMorphClass())->count(), 'One eSign, not one a section.');
        $this->assertSame(1, VerificationSnapshot::query()->where('dpc_verification_participant_id', $participant->id)->count());
        // Logged under an action that fits signature_attempts.action (20 on MySQL; SQLite
        // does not enforce it, and the longer name failed every real eSign).
        $this->assertSame(['candidate_verify'], SignatureAttempt::query()->where('signable_type', $participant->getMorphClass())->pluck('action')->unique()->values()->all());

        // The signed document carries the decisions, the grievances with their PDF hashes, and the undertaking.
        $signature = Signature::query()->where('signable_type', $participant->getMorphClass())->sole();
        $document = (string) Storage::disk($signature->signed_disk)->get($signature->signed_path);
        $this->assertStringContainsString((string) Grievance::query()->where('section', 'cr')->value('sha256'), $document);
        $this->assertStringContainsString('Undertaking: '.__('candidate.grievance.undertaking', [], 'en'), $document);
        $this->assertStringContainsString($participant->snapshot_sha256, $document);

        // Nothing after it: no new grievance, no change, a repeat changes nothing.
        $uuid = $participant->uuid;
        $this->grievance($uuid, 'seniority')->assertSessionHasErrors('document');
        $this->post(route('candidate-verification.agree', [$uuid, 'cr']))->assertSessionHasErrors('verification');
        $this->delete(route('candidate-verification.grievance.remove', [$uuid, 'cr']))->assertSessionHasErrors('verification');
        $this->assertSame($participant->signature_id, app(CandidateVerificationService::class)->submit($participant)->signature_id, 'A replayed final step: the same submission.');
        $this->assertSame(1, Signature::query()->where('signable_type', $participant->getMorphClass())->count());

        // The acknowledgement.
        $pdf = $this->get(route('candidate-verification.acknowledgement', $uuid))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    public function test_a_cancelled_or_mismatched_esign_submits_nothing(): void
    {
        $this->signIn(1);
        $uuid = $this->participant(1)->uuid;
        foreach (['seniority', 'cr', 'integrity', 'ipr'] as $section) {
            $this->post(route('candidate-verification.agree', [$uuid, $section]));
        }

        $this->post(route('candidate-verification.submit', $uuid), ['declaration' => '1', 'consent' => '1']);
        $this->post(route('candidate-verification.esign.submit', $uuid), ['decision' => 'cancel'])->assertSessionHasErrors('verification');
        $this->assertFalse($this->participant(1)->hasSubmitted());

        $this->post(route('candidate-verification.submit', $uuid), ['declaration' => '1', 'consent' => '1']);
        $this->post(route('candidate-verification.esign.submit', $uuid), ['decision' => 'sign', 'name' => 'Somebody Else'])->assertSessionHasErrors('verification');
        $this->assertFalse($this->participant(1)->hasSubmitted());
        $this->assertSame(0, Signature::query()->where('signable_type', $this->participant(1)->getMorphClass())->count());
        // The refused name is logged, under an action that fits signature_attempts.action
        // (string 20: on MySQL the longer name failed here too, before the refusal was shown).
        $this->assertSame([['action' => 'candidate_verify', 'outcome' => 'name_mismatch']], SignatureAttempt::query()->where('signable_type', $this->participant(1)->getMorphClass())->get(['action', 'outcome'])->toArray());
        $this->assertLessThanOrEqual(20, strlen(CandidateVerificationService::SIGN_ACTION));

        // A stale or replayed eSign step (no pending request): refused.
        Carbon::setTestNow(now()->addMinutes(2)); // past the rate limit these steps share
        $this->post(route('candidate-verification.esign.submit', $uuid), ['decision' => 'sign', 'name' => $this->row(1)->name])->assertSessionHasErrors('verification');
        $this->assertFalse($this->participant(1)->hasSubmitted());

        // Without the declaration or the consent: refused.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->post(route('candidate-verification.submit', $uuid), ['consent' => '1'])->assertSessionHasErrors('declaration');
    }

    public function test_a_grievance_reads_drafted_until_the_final_submission_and_submitted_after_it(): void
    {
        $this->signIn(1);
        $uuid = $this->participant(1)->uuid;
        $this->grievance($uuid, 'cr')->assertSessionHasNoErrors();
        $drafted = __('candidate.verification.decision.grievance');
        $submitted = __('candidate.verification.decision.grievance_submitted');

        $this->get(route('candidate-verification.review', $uuid))->assertOk()->assertSee($drafted)->assertDontSee($submitted);

        foreach (['seniority', 'integrity', 'ipr'] as $section) {
            $this->post(route('candidate-verification.agree', [$uuid, $section]))->assertSessionHasNoErrors();
        }
        $this->post(route('candidate-verification.submit', $uuid), ['declaration' => '1', 'consent' => '1'])->assertRedirect(route('candidate-verification.esign', $uuid));
        $this->post(route('candidate-verification.esign.submit', $uuid), ['decision' => 'sign', 'name' => $this->row(1)->name])->assertSessionHasNoErrors();
        $this->assertTrue($this->participant(1)->hasSubmitted());

        $this->get(route('candidate-verification.review', $uuid))->assertOk()->assertSee($submitted)->assertDontSee($drafted);
        $this->get(route('candidate-verification.section', [$uuid, 'cr']))->assertOk()->assertSee($submitted)->assertDontSee($drafted);
    }

    public function test_after_the_deadline_nothing_is_submitted(): void
    {
        $this->signIn(1);
        $participant = $this->participant(1);
        foreach (['seniority', 'cr', 'integrity', 'ipr'] as $section) {
            $this->post(route('candidate-verification.agree', [$participant->uuid, $section]));
        }

        Carbon::setTestNow('2026-11-30 00:00:01');
        $this->expectException(WorkflowException::class);
        app(CandidateVerificationService::class)->submit($participant->fresh());
    }

    // -------------------------------------------------------------- the messages

    public function test_a_message_is_recorded_once_skipped_without_a_contact_and_failed_when_the_gateway_fails(): void
    {
        $cycle = $this->cycle();
        $participant = $this->participant(1);

        // Once only: the opened message again adds nothing.
        app(VerificationNotifier::class)->toParticipant($participant, 'opened', ['dpc' => 'X', 'deadline' => 'Y', 'url' => 'Z']);
        $this->assertSame(1, VerificationNotification::query()->where('kind', 'opened')->where('channel', 'sms')->where('recipient', 'participant:'.$participant->id)->count());

        // No mobile: skipped, with the reason.
        $this->row(2)->forceFill(['mobile' => null])->save();
        app(VerificationNotifier::class)->toParticipant($this->participant(2)->fresh(), 'record_corrected', ['dpc' => 'X', 'section' => 'Y', 'url' => 'Z'], 'test');
        $skipped = VerificationNotification::query()->where('kind', 'record_corrected')->where('channel', 'sms')->sole();
        $this->assertSame([VerificationNotification::SKIPPED, 'No mobile number on record.'], [$skipped->status, $skipped->last_error]);

        // The gateway refuses: the attempt is recorded, and after the last try it is failed — never sent.
        $this->app->instance(SmsSender::class, new class implements SmsSender
        {
            public function send(string $mobile, string $message, ?string $templateId = null): void
            {
                throw new RuntimeException('Gateway down');
            }
        });
        $record = VerificationNotification::query()->create(['dpc_verification_cycle_id' => $cycle->id, 'kind' => 'grievance_resolved', 'channel' => 'sms', 'recipient' => 'participant:'.$participant->id, 'reference' => 'r', 'destination' => '9425000001', 'status' => 'queued']);
        $job = new SendVerificationNotification($record->id, ['dpc' => 'X', 'section' => 'Y', 'url' => 'Z']);
        try {
            $this->app->call([$job, 'handle']);
        } catch (RuntimeException) {
        }
        $this->assertSame([VerificationNotification::QUEUED, 1, 'Gateway down'], [$record->fresh()->status, $record->fresh()->attempts, $record->fresh()->last_error]);
        $job->failed(new RuntimeException('Gateway down'));
        $this->assertSame(VerificationNotification::FAILED, $record->fresh()->status);
    }
}
