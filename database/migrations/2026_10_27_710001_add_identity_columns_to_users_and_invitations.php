<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity on the officer record (Phases 2–4, 2026-10-27).
 *
 * - `date_of_birth`, `gender`: entered by whoever creates the officer, compared with
 *   DigiLocker; locked once verified (only the CGPSC Super Admin corrects them then).
 * - `identity_verified_at`, `identity_verification_method` (`digilocker`,
 *   `digilocker_simulated` or `manual`): the current verification, null when none
 *   or when it was revoked.
 * - `session_epoch`: raised whenever every session of the account must end
 *   (deactivation, identity correction, forced re-verification, transfer). A
 *   session carries the epoch it signed in under and is ended by the account gate
 *   when they differ.
 *
 * (The invitation carries only the department and the e-mail, 2026-10-28: the
 * invited person's details come from DigiLocker. The file keeps its name because
 * it has already run.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('designation_id');
            $table->string('gender', 1)->nullable()->after('date_of_birth');
            $table->timestamp('identity_verified_at')->nullable()->after('mobile_verification_waiver_reason');
            $table->string('identity_verification_method', 30)->nullable()->after('identity_verified_at');
            $table->unsignedInteger('session_epoch')->default(0)->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'gender', 'identity_verified_at', 'identity_verification_method', 'session_epoch']);
        });
    }
};
