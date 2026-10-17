<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Demo\DemoAccountSeeder;
use Database\Seeders\Demo\DemoClock;
use Database\Seeders\Demo\DemoJourneySeeder;
use Database\Seeders\Demo\DemoMasterSeeder;
use Database\Seeders\Demo\DemoPipelineSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The demonstration database: three departments, their officers, their masters,
 * and a full set of DPCs — one taken end to end and corrected by a Review, one
 * concluded with a held officer and released by a Circulation, and one parked
 * in each intermediate workflow state.
 *
 * Intended for a **fresh** database (`php artisan demo:seed`). Re-running it
 * over existing demo data will skip the parts that are already there and
 * duplicate the parts that are not, because a DPC workflow is a sequence of
 * events rather than a set of rows to upsert.
 *
 * **Never production.** Every account shares one published password.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'DemoSeeder creates accounts with a shared, published password and must never run in production.'
            );
        }

        // Credential and workflow notifications are queued. The demo must not
        // depend on a queue worker (or on Redis) being up, and the listeners
        // are harmless inline: mail is written to the log and the SMS gateway
        // is off by default.
        config(['queue.default' => 'sync']);

        // The demonstration's proposals carry their own Working Officers charts,
        // so they are raised on the chart source, whatever new proposals default to.
        config(['dpc.vacancy_master.source_for_new_proposals' => 'chart']);

        $this->call([
            RolesAndPermissionsSeeder::class,
            DistrictSeeder::class,
            DesignationSeeder::class,
            PayScaleSeeder::class,
            LookupMasterSeeder::class,
            QualificationSeeder::class,
            DepartmentMasterSeeder::class,
            CommitteeMasterSeeder::class,

            DemoAccountSeeder::class,
            DemoMasterSeeder::class,
            DemoJourneySeeder::class,
            DemoPipelineSeeder::class,
        ]);

        // The journey seeders move the clock to write each step at the date it
        // would have happened. Every one of them restores it, including on
        // failure; this is the belt-and-braces guard.
        DemoClock::release();
    }
}
