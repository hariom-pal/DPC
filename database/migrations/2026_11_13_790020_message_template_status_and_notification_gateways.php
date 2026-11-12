<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Templates and their delivery, kept by the CGPSC Administrator (2026-11-13).
 *
 * - A template has a status instead of an on/off switch: Draft (saved, not yet in
 *   use: the message goes in the portal's default wording), Active (the message goes
 *   in this wording) or Inactive (the message is not sent). Existing rows keep what
 *   they did: on is Active, off is Inactive.
 * - The e-mail (SMTP) and SMS gateway settings, one row per channel. The password or
 *   API key is stored encrypted and never shown again; everything else is plain
 *   settings. A channel not enabled here is delivered as configured in the
 *   environment, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table): void {
            $table->string('status', 10)->default('active')->after('dlt_template_id');
        });

        DB::table('message_templates')->where('is_active', false)->update(['status' => 'inactive']);

        Schema::table('message_templates', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });

        Schema::create('notification_gateways', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 10)->unique();
            $table->boolean('is_enabled')->default(false);
            $table->json('settings')->nullable();
            // The password / API key, encrypted with the application key.
            $table->text('secret')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_gateways');

        Schema::table('message_templates', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('dlt_template_id');
        });

        DB::table('message_templates')->where('status', '!=', 'active')->update(['is_active' => false]);

        Schema::table('message_templates', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
