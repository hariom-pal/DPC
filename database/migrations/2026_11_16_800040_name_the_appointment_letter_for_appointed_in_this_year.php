<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Appointed in this year" is justified with the appointment letter (2026-11-16):
 * the reason names its document, so the screen asks for it by that name. Only a
 * label the Administrator has not set is filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('record_reasons')->where('code', 'newly_appointed')->whereNull('document_label_en')
            ->update(['document_label_en' => 'Appointment letter', 'document_label_hi' => 'नियुक्ति पत्र']);
    }

    public function down(): void
    {
        DB::table('record_reasons')->where('code', 'newly_appointed')->where('document_label_en', 'Appointment letter')
            ->update(['document_label_en' => null, 'document_label_hi' => null]);
    }
};
