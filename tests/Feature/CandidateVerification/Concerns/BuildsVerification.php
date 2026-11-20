<?php

declare(strict_types=1);

namespace Tests\Feature\CandidateVerification\Concerns;

use App\Domain\Authentication\Models\User;
use App\Domain\CandidateVerification\Models\VerificationCycle;
use App\Domain\CandidateVerification\Models\VerificationParticipant;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\WorkflowStageTimeline;
use App\Domain\Master\Models\DepartmentPost;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;

/**
 * A DPC submitted to its AH, its officers with mobile numbers, Employee IDs and their
 * Promotion Candidate accounts, and the Administrator's days for the verification
 * (2026-11-19) — through the application's own routes, as the other DPC suites do.
 */
trait BuildsVerification
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;

    protected Department $department;

    protected User $ao;

    protected User $ah;

    protected DpcProposal $proposal;

    protected function bootVerification(int $days = 10): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake('local');
        config(['features.csc_digisign' => false, 'dpc.candidate_verification.enabled' => true, 'features.sms_gateway' => false]);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();
        $this->ah = User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create();
        $from = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), $this->dpcStorePayload($this->sanctioningPreDpc($from->id, $to->id)));
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();

        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();

        foreach ($this->proposal->evaluationRows()->orderBy('sn')->get() as $row) {
            $row->forceFill(['mobile' => $this->mobileOf($row->sn), 'employee_number' => $row->employee_number ?: 'EMP-'.$row->sn])->save();
            $candidate = User::factory()->assignedTo($this->department->id, Role::PromotionCandidate)->create(['name' => $row->name, 'employee_number' => $row->fresh()->employee_number]);
            $candidate->forceFill(['employee_number' => $row->fresh()->employee_number])->save();
        }

        WorkflowStageTimeline::query()->updateOrCreate(['stage' => DpcStage::CandidateVerification->value], ['days' => $days]);
    }

    protected function mobileOf(int $sn): string
    {
        return '94250'.str_pad((string) $sn, 5, '0', STR_PAD_LEFT);
    }

    protected function approveByAh(): TestResponse
    {
        return $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal));
    }

    protected function cycle(): VerificationCycle
    {
        return VerificationCycle::query()->where('dpc_proposal_id', $this->proposal->id)->sole();
    }

    protected function row(int $sn = 1): DpcEvaluationRow
    {
        return $this->proposal->evaluationRows()->where('sn', $sn)->firstOrFail();
    }

    protected function participant(int $sn = 1): VerificationParticipant
    {
        return VerificationParticipant::query()->where('dpc_evaluation_row_id', $this->row($sn)->id)->sole();
    }

    /** Sign in through the portal as the officer of this seniority number. */
    protected function signIn(int $sn = 1): void
    {
        auth()->logout();
        $this->post(route('candidate-verification.code.send'), ['mobile' => $this->mobileOf($sn)])->assertRedirect(route('candidate-verification.code'));
        $this->post(route('candidate-verification.verify'), ['code' => '123456'])->assertRedirect(route('candidate-verification.dashboard'));
    }

    protected function pdf(string $name = 'evidence.pdf', int $kb = 20): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    /** @param array<string, mixed> $overrides */
    protected function grievance(string $participant, string $section, array $overrides = []): TestResponse
    {
        return $this->post(route('candidate-verification.grievance.save', [$participant, $section]), array_replace([
            'category' => config('dpc.candidate_verification.categories.'.$section)[0],
            'remark' => 'The record is wrong.',
            'document' => $this->pdf(),
            'undertaking' => '1',
        ], $overrides));
    }

    /** Agree on every section but the ones given, which get grievances; then submit with the simulated eSign. */
    protected function verifyAndSubmit(int $sn, array $grievances = []): VerificationParticipant
    {
        $this->signIn($sn);
        $uuid = $this->participant($sn)->uuid;
        foreach (['seniority', 'cr', 'integrity', 'ipr'] as $section) {
            in_array($section, $grievances, true)
                ? $this->grievance($uuid, $section)->assertSessionHasNoErrors()
                : $this->post(route('candidate-verification.agree', [$uuid, $section]))->assertSessionHasNoErrors();
        }
        $this->post(route('candidate-verification.submit', $uuid), ['declaration' => '1', 'consent' => '1'])->assertRedirect(route('candidate-verification.esign', $uuid));
        $this->post(route('candidate-verification.esign.submit', $uuid), ['decision' => 'sign', 'name' => $this->row($sn)->name])->assertSessionHasNoErrors();

        return $this->participant($sn)->fresh();
    }
}
