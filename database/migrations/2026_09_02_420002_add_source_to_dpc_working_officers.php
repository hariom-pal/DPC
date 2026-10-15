<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the officer holding a post came to hold it (business directive,
 * 2026-09-02, second part).
 *
 * **This is what makes the Recruitment Rule's percentage usable.** The rule
 * divides a post's sanctioned strength into a promotion quota and a direct
 * quota — 50/50 on four posts is two chairs each. Until now nothing recorded
 * *which* quota each occupied chair was consuming, so the portal could only
 * multiply the finished vacancy by the percentage. That produced a promotion
 * figure with nothing behind it: four posts at 100% promotion said every
 * vacancy was the DPC's, even if a chair was held by a direct recruit.
 *
 * With `source` on each row the arithmetic runs the other way round, and each
 * step is answerable to a row a department actually filled in:
 *
 *     promotion chairs = sanctioned × promotion %
 *     promotion vacancy = promotion chairs − officers who came by promotion
 *                         and are still substantively in post
 *
 * and the same for direct, whose vacancy is not this DPC's business at all.
 *
 * **`deputation` is a third value, not a kind of promotion.** An officer on
 * deputation into the post consumes neither quota — he is temporary — so his
 * chair stays countable. That is the register's own reading: *"deputation se
 * aaya tha to 1 vacancy deputation se badh gayee."*
 *
 * Nullable, and null on a vacant row: an empty chair was not filled by anyone,
 * and a default would have invented a provenance for it. Existing rows are
 * back-filled to `promotion`, because every proposal in the system was raised
 * under a 100%-promotion rule where no other value was possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->string('source', 12)
                ->nullable()
                ->after('status')
                ->comment('promotion | deputation | direct — null on a vacant row');
        });

        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->index(['dpc_proposal_id', 'source'], 'dpc_working_officers_proposal_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->dropIndex('dpc_working_officers_proposal_source_index');
            $table->dropColumn('source');
        });
    }
};
