<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The DLT Content Template ID of each SMS's System default text (2026-11-13).
 *
 * India has no common DLT Content Template ID: every SMS text is registered on the
 * DLT portal on its own and gets its own ID. The portal's System default texts are
 * kept in the application; this table holds, per message, only the ID the CGPSC
 * Administrator obtained for that text — the text itself is never copied here. A
 * message with no row has no ID, and the real gateway does not send it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_default_dlt_ids', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('dlt_template_id', 30);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_default_dlt_ids');
    }
};
