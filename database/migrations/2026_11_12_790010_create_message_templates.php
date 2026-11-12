<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS and e-mail templates the CGPSC Administrator keeps (2026-11-12). A row holds
 * the Administrator's own wording for one message on one channel; without a row the
 * portal sends its built-in wording, so nothing changes until a template is edited.
 * Deleting the row restores the built-in wording. Every change is audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60);
            $table->string('channel', 10);
            $table->string('subject')->nullable();
            $table->text('body');
            // The DLT template ID the SMS is registered under (TRAI rules).
            $table->string('dlt_template_id', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['key', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
