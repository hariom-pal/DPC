<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Original DPC version and chain depth on a review link — M8 Phase 2.
 *
 * **`original_proposal_version`** (architect Directive 1) is the content
 * fingerprint of the DPC being corrected, taken from the concluded
 * proceeding that produced its result. It answers the question a Review
 * inevitably raises years later: *which version of the original was this
 * correction raised against?* The original is immutable, so the answer never
 * changes — but recording it means the claim rests on evidence rather than on
 * that assumption holding.
 *
 * **`chain_depth`** (architect Directive 2) is the number of DPCs in the chain
 * up to and including this Review — the original counts as 1, so the first
 * Review has depth 2. It is stored rather than derived because walking a chain
 * recursively to answer "how deep is this?" is exactly the query that gets
 * slower as the thing it measures grows.
 *
 * It is deliberately **not** the same as `review_number`, which is this
 * Review's position among reviews (1, 2, 3…). Depth counts DPCs; the number
 * counts corrections. They differ by one today, and would differ by more if a
 * chain ever branched.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_review_links', function (Blueprint $table) {
            $table->char('original_proposal_version', 64)->nullable()->after('original_dpc_proposal_id');
            $table->unsignedInteger('chain_depth')->default(1)->after('review_number');
        });

        // Backfill: depth is review_number + 1 for every existing link, since
        // no chain has branched.
        foreach (DB::table('dpc_review_links')->get(['id', 'review_number']) as $row) {
            DB::table('dpc_review_links')
                ->where('id', $row->id)
                ->update(['chain_depth' => $row->review_number + 1]);
        }

        Schema::table('dpc_review_links', function (Blueprint $table) {
            $table->index(['chain_uuid', 'chain_depth'], 'dpc_review_link_chain_depth_index');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_review_links', function (Blueprint $table) {
            $table->dropIndex('dpc_review_link_chain_depth_index');
            $table->dropColumn(['original_proposal_version', 'chain_depth']);
        });
    }
};
