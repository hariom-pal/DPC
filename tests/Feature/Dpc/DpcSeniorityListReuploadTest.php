<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Import\Enums\ImportBatchStatus;
use App\Domain\Import\Enums\ImportTemplate;
use App\Domain\Import\Models\ImportBatch;
use App\Domain\Master\Models\DepartmentPost;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Replacing the Seniority List while the proposal is still the department's.
 *
 * Raised from UAT (2026-08-23): an officer reported the Seniority List upload as
 * "locked, and it will not accept a new file". The proposal had been submitted,
 * so the lock was correct — but nothing on the screen said so, and nothing here
 * pinned that an *unlocked* proposal accepts a second file at all. Both halves
 * are covered below, because "it is locked when it should be" and "it is not
 * locked when it should not be" are the two claims that report puts in doubt.
 */
class DpcSeniorityListReuploadTest extends TestCase
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         | Manual entry is off by default since 2026-09-07 — the two grids are
         | upload-only — and the routes are gated on the same setting the screens
         | are. Switched on for this class, which exercises those routes directly.
         |
         | Kept as tests rather than deleted: the instruction was *"hide it, in case
         | we want it back"*, and a feature nobody tests is not one that can come
         | back.
         */
        config([
            'dpc.manual_entry.working_officers' => true,
            'dpc.manual_entry.seniority_list' => true,
        ]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);

        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $this->ah = User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create();

        // Class-I → Class-I: the one pair the deck gives a zone formula for, so
        // the exact-count gate is live and a replacement file has to satisfy it.
        $postFrom = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($postFrom->id, $postTo->id)),
        );

        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows, string $name = 'upload.csv'): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /**
     * A two-officer Seniority List whose names carry the given suffix, so two
     * successive uploads are distinguishable in the assertions.
     */
    private function seniorityFile(string $suffix): UploadedFile
    {
        return $this->csv([
            ['SN', 'EMPLOYEE NO', 'NAME OF US', 'DOB', 'QUAL', 'CAT', 'DATE OF JOINING IN SERVICE', 'DATE OF POSTING ON US'],
            ['1', "EMP-{$suffix}-1", "US1-{$suffix}", '20-03-1964', '11TH', 'UR', '01-04-1985', '01-01-2012'],
            ['2', "EMP-{$suffix}-2", "US2-{$suffix}", '12-05-1968', '11TH', 'OBC', '13-03-1986', '01-01-2012'],
        ], "seniority-{$suffix}.csv");
    }

    private function uploadSeniority(string $suffix): ImportBatch
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.store', $this->proposal), [
            'template' => ImportTemplate::FinalSeniorityListUs->value,
            'file' => $this->seniorityFile($suffix),
        ]);

        return ImportBatch::query()->latest('id')->firstOrFail();
    }

    private function commit(ImportBatch $batch): void
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]));
    }

    /**
     * The zone gate counts officers, so the vacancy has to be one that asks for
     * exactly two — otherwise the replacement is refused for arithmetic reasons
     * and the test proves nothing about locking.
     */
    private function makeZoneAcceptTwo(): void
    {
        // Class-I → Class-I with no vacancy leaves the zone unrequired, which is
        // what lets a two-row list stand on its own here.
        $this->proposal->forceFill(['sanctioned_post_count' => null])->save();
        $this->proposal->refresh();
    }

    public function test_a_draft_seniority_list_can_be_replaced_repeatedly(): void
    {
        $this->makeZoneAcceptTwo();

        foreach (['a', 'b', 'c'] as $round) {
            $batch = $this->uploadSeniority($round);
            $this->assertSame(ImportBatchStatus::Previewed, $batch->status, "round {$round} did not reach preview");

            $this->commit($batch);
            $this->assertSame(ImportBatchStatus::Committed, $batch->refresh()->status, "round {$round} did not commit");

            // Each commit *replaces* the section rather than appending to it.
            $names = $this->proposal->seniorityEntries()->orderBy('seniority_number')->pluck('name')->all();
            $this->assertSame(["US1-{$round}", "US2-{$round}"], $names, "round {$round} did not replace the list");
        }
    }

    public function test_the_seniority_screen_stays_reachable_after_a_list_already_exists(): void
    {
        $this->makeZoneAcceptTwo();
        $this->commit($this->uploadSeniority('a'));

        // An existing list is not itself a lock: the screen and its upload form
        // must still be there.
        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.seniority-list', $this->proposal))
            ->assertOk()
            ->assertSee('Upload from Excel');
    }

    public function test_the_manual_save_also_replaces_an_existing_list(): void
    {
        $this->makeZoneAcceptTwo();
        $this->commit($this->uploadSeniority('a'));

        $this->actingAs($this->ao)->put(route('dpc-proposals.seniority-list.update', $this->proposal), [
            'entries' => [
                [
                    'seniority_number' => 1,
                    'employee_number' => 'EMP-MANUAL-1',
                    'name' => 'Manual Officer',
                    'dob' => '1970-01-01',
                    'reservation_category_id' => $this->proposal->seniorityEntries()->value('reservation_category_id'),
                ],
            ],
        ])->assertRedirect();

        $this->assertSame(['Manual Officer'], $this->proposal->seniorityEntries()->pluck('name')->all());
    }

    public function test_a_returned_proposal_accepts_a_new_seniority_list_again(): void
    {
        $this->makeProposalSubmittable($this->proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));
        $this->assertTrue($this->proposal->refresh()->status->isTerminal() === false);

        // Locked while it sits with the Administrative Head.
        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.seniority-list', $this->proposal))
            ->assertForbidden();

        $this->actingAs($this->ah)->post(route('dpc-proposals.return', $this->proposal), [
            'return_remarks' => 'The seniority list needs to be replaced with the corrected file.',
        ]);

        // ...and unlocked the moment it comes back.
        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.seniority-list', $this->proposal))
            ->assertOk()
            ->assertSee('Upload from Excel');
    }

    public function test_the_detail_screen_states_why_the_seniority_list_is_locked(): void
    {
        $this->makeProposalSubmittable($this->proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        // The regression this test exists for: the Manage button used to simply
        // vanish, so a locked section was indistinguishable from a broken one.
        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertSee('Locked');
    }
}
