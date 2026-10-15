<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mobile number on the officer snapshots (business instruction, 2026-09-07).
 *
 * > *I think we should have a mobile number for the candidate — it will help them
 * > log in.*
 *
 * ### What this unblocks
 *
 * `PromotionCandidateProvisioner` creates an account for every officer on a
 * Seniority List, and its own docblock records the consequence of there being
 * nothing to reach them on:
 *
 * > *No credentials are dispatched, and that is deliberate. A Seniority List carries
 * > no email address and no mobile number, so there is no channel to send them on.*
 *
 * So the account exists, its temporary password is shown to the *department* once,
 * and the officer can be reached only if somebody hands it over in person. Worse,
 * the account has no login identifier of its own at all — an employee number is not
 * one — so an officer who is handed a password has nowhere to type it.
 *
 * A mobile number is both halves at once: the identifier they sign in with, and the
 * channel an OTP or a temporary password goes to.
 *
 * ### On all three snapshots
 *
 * The three import templates share one column map, so a `MOBILE` column accepted on
 * the Seniority List and rejected on the Working Officers list would be an
 * inconsistency with no reason behind it. `dpc_evaluation_rows` needs it for the
 * reason it needed the seat category: the chart is *projected* from the seniority
 * list, and a column that stopped at the projection would be recorded by the
 * department and then lost on the screen the committee sits with.
 *
 * ### Nullable, and not unique
 *
 * Every snapshot already written predates it. And two officers may share a number —
 * a household landline in an older record, a number re-issued by a carrier — so a
 * unique index here would refuse a legitimate seniority list. Uniqueness belongs to
 * `users`, where an account is actually created, and
 * `PromotionCandidateProvisioner` already refuses to guess when two records collide.
 *
 * Additive only: two nullable columns per table, nothing altered, nothing dropped.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'dpc_working_officers',
        'dpc_seniority_entries',
        'dpc_evaluation_rows',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                // 20 rather than 10: a department may record a country code or
                // separators, and the shape is enforced by validation where it can
                // report which row is wrong, not by truncation at the column.
                $blueprint->string('mobile', 20)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('mobile');
            });
        }
    }
};
