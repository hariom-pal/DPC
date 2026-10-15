<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public notice board, as data (suggestion 6).
 *
 * The four notices on the landing page lived in `lang/{en,hi}/landing.php`, which
 * made publishing one a developer's job and a deployment. Content dated August
 * 2026 is stale by the time anyone reads it, and the practical effect of a
 * deployment-gated notice board is that notices stop being published at all.
 *
 * **Bilingual columns rather than a translation table.** Every other master here
 * carries `name_en`/`name_hi` side by side, and a notice has exactly two texts; a
 * separate table would buy nothing and cost a join on the busiest page in the
 * portal.
 *
 * **Dated rather than deleted.** `published_from` and `published_until` decide
 * what shows, so an expired notice stays on record — the same reasoning as every
 * other superseded thing in this schema. A null `published_until` means "until
 * further notice", which is how most government notices are actually issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_notices', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->string('title_en');
            $table->string('title_hi')->nullable();
            $table->text('body_en');
            $table->text('body_hi')->nullable();

            $table->date('published_from');
            $table->date('published_until')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The landing page's only query: what is live, in display order.
            $table->index(['status', 'published_from'], 'portal_notices_live_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_notices');
    }
};
