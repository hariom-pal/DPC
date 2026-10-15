<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Master\Models\ClassMaster;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Give every test its own disk, clear the Class Master's memo, and make sure
     * the Commission has a Chairman.
     *
     * **The disk had to move here, and for the same reason the memo is here.**
     * Ten test files faked `local` and six did not, so a document or signature
     * test wrote **real files into `storage/app/private`** — a hundred of them per
     * run of one test class. Two consequences, and the second is the serious one:
     * a developer's storage directory filled with orphans after every run, and a
     * test asserting that a document exists could be satisfied by a file the
     * *previous* run left behind. An evidence-integrity test that passes on
     * yesterday's evidence is not testing anything.
     *
     * `local` is the only disk the application writes to — documents, signatures,
     * import batches and Government Order PDFs all land there — so faking it once
     * isolates all of them. Laravel clears the faked directory itself.
     *
     * The same argument covers the memo below: a cache that has to be remembered
     * per test is a cache that will be forgotten, and so is a faked disk.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        ClassMaster::flushCache();

        $this->ensureCgpscChairman();
    }

    /**
     * Record a CGPSC Chairman, because item 9 makes one a precondition for
     * everything downstream.
     *
     * The change register of 2026-08-26 requires every DPC committee to include the
     * CGPSC Chairman, and `DpcCommitteeService` refuses to compose a committee when
     * no Chairman is on record. That turns "a Chairman exists" into a precondition
     * of the *whole* DPC workflow — not of the committee screen alone — so a fixture
     * that omits it fails at submission with a message about a master nobody was
     * testing.
     *
     * It lives here rather than in each fixture for the reason already written
     * above about the faked disk: a precondition that has to be remembered per test
     * is one that will be forgotten. A test that cares about the Chairman
     * specifically — appointment, succession, the missing-Chairman refusal — deletes
     * or replaces this row, which is cheap and explicit.
     *
     * **Guarded on the table existing.** Some tests run without `RefreshDatabase`,
     * and a few exercise migrations directly; touching a table that is not there
     * would turn those into errors about the Chairman.
     */
    private function ensureCgpscChairman(): void
    {
        if (! Schema::hasTable('cgpsc_chairmen')) {
            return;
        }

        if (CgpscChairman::query()->serving()->exists()) {
            return;
        }

        $chairman = new CgpscChairman([
            'name' => 'Dr. Ajay Kumar Shrivastava',
            'designation' => 'Chairman, Chhattisgarh Public Service Commission',
            'effective_from' => now()->subYear()->toDateString(),
        ]);

        // `active_flag` is not fillable — appointment is the service's job, and the
        // service writes a timeline entry a fixture does not want. Forced here for
        // the same reason the fixtures force `PreDpcProposal`'s workflow columns.
        $chairman->forceFill(['active_flag' => 1])->save();
    }
}
