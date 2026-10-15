<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * **A Department Post Mapping is authorised by several Government Orders, not one**
 * (change register of 2026-08-26, item 10).
 *
 * > *Department Post Mapping currently supports only one Government Order and one
 * > PDF. This is insufficient. Support multiple Government Orders. Each entry
 * > should contain Government Order / Circular Number, Government Order Date, PDF
 * > Upload. Provide Add More. Previous Government Orders must remain available for
 * > audit.*
 *
 * ### What was wrong with the old shape
 *
 * Two structures each held half of an order and neither held an order:
 *
 *  - `department_posts.government_order_number` / `government_order_date` — one
 *    number and one date, on the mapping itself;
 *  - `department_post_documents` — *versions of one PDF*, with
 *    `unique(department_post_id, is_current)` making "one current file" a
 *    constraint.
 *
 * So the number lived on one table, the file on another, and there could only ever
 * be one of each. A mapping amended by a corrigendum could record the corrigendum's
 * PDF only by *retiring the original order's scan* — which is precisely the loss
 * the register says must not happen.
 *
 * ### The new shape
 *
 * One row per Government Order, carrying its own number, its own date and its own
 * PDF. `unique(department_post_id, government_order_number)` stops the same order
 * being attached twice; nothing stops a mapping having ten of them.
 *
 * **The hash survives the move, and it is the reason the file is evidence.** A
 * Government Order is the legal reference for a mapping, and a stored PDF that
 * cannot be shown to be the one that was uploaded is a reference to nothing.
 * `sha256` is recorded at upload, as `department_post_documents` did and as the
 * proceeding and circulation documents do.
 *
 * ### Superseding a mapping is still a different thing
 *
 * Two kinds of history meet here and conflating them would lose one:
 *
 *  - **Superseding a mapping** — the Government changes the pay scale or the class
 *    — creates a *new `department_posts` row*, with its own orders, and closes the
 *    previous one. A DPC conducted last year still points at the mapping it ran
 *    under.
 *  - **Adding an order** leaves the mapping alone and records another instrument
 *    that bears on it.
 *
 * This table is the second. The first is `effective_from` / `effective_to` /
 * `active_flag` on `department_posts`, untouched here.
 *
 * ### Reversibility
 *
 * `down()` rebuilds both old structures and puts back **the earliest order** as the
 * mapping's single order, with its PDF as the one current document. That is lossy
 * and cannot not be: the old shape has one slot and the new one permits many. The
 * loss is stated here rather than discovered, and the earliest is chosen because it
 * is the order that originally authorised the mapping — the one the old column
 * meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_post_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('department_post_id')
                ->constrained()
                ->cascadeOnDelete();

            // The instrument itself. Both required: an order with no number cannot
            // be cited, and one with no date cannot be placed in a sequence.
            $table->string('government_order_number', 120);
            $table->date('government_order_date');

            /*
             | The PDF. Nullable as a group, because a department may record the
             | citation of an order whose scan has not reached them yet — refusing
             | the number until the file arrives would mean the portal holds *less*
             | than the department knows. `hasDocument()` on the model is what every
             | caller asks rather than testing `path` for null.
             */
            $table->string('disk', 40)->nullable();
            $table->string('path')->nullable();

            // What the department called it. Shown on screen, never used to build a
            // path — a filename from a browser is user input.
            $table->string('original_name')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['department_post_id', 'government_order_number'],
                'department_post_orders_number_unique',
            );

            $table->index(['department_post_id', 'government_order_date'], 'department_post_orders_date_index');
        });

        $this->carryForward();

        Schema::dropIfExists('department_post_documents');

        Schema::table('department_posts', function (Blueprint $table): void {
            $table->dropColumn(['government_order_number', 'government_order_date']);
        });
    }

    public function down(): void
    {
        Schema::table('department_posts', function (Blueprint $table): void {
            $table->string('government_order_number', 120)->nullable()->after('promotion_duration_years');
            $table->date('government_order_date')->nullable()->after('government_order_number');
        });

        Schema::create('department_post_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('department_post_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedTinyInteger('is_current')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['department_post_id', 'is_current'], 'department_post_documents_current_unique');
        });

        $this->carryBack();

        Schema::dropIfExists('department_post_orders');
    }

    /**
     * Fold the mapping's single order and its current document into one order row.
     *
     * Three cases, and each is handled as what it is rather than as a default:
     *
     *  - **a number and a file** — one order row carrying both;
     *  - **a number and no file** — an order that was cited but never scanned; the
     *    row is created without a document, which is what the nullable file columns
     *    exist for;
     *  - **a file and no number** — a scan attached before orders were recorded.
     *    It gets a row whose number says so in words. Inventing a plausible order
     *    number would put a false citation on a legal reference, and discarding the
     *    file would destroy the only copy the portal holds.
     *
     * Superseded documents — earlier versions of one mapping's scan — are carried
     * across too, each as its own order, because the register's rule is that
     * previous orders remain available for audit and a retired scan is the closest
     * thing the old shape had to a previous order.
     */
    private function carryForward(): void
    {
        if (! Schema::hasTable('department_post_documents')) {
            return;
        }

        $now = now();
        $rows = [];

        $mappings = DB::table('department_posts')
            ->select('id', 'government_order_number', 'government_order_date', 'created_at')
            ->get()
            ->keyBy('id');

        $documents = DB::table('department_post_documents')
            ->orderBy('department_post_id')
            ->orderByDesc('is_current')
            ->orderBy('id')
            ->get()
            ->groupBy('department_post_id');

        foreach ($mappings as $mappingId => $mapping) {
            $attached = $documents->get($mappingId, collect());
            $number = trim((string) ($mapping->government_order_number ?? ''));
            $date = $mapping->government_order_date ?? $mapping->created_at;
            $order = 0;

            foreach ($attached as $document) {
                $rows[] = [
                    'uuid' => (string) Str::uuid(),
                    'department_post_id' => $mappingId,
                    'government_order_number' => $this->numberFor($number, $order, (int) $document->id),
                    'government_order_date' => $date,
                    'disk' => $document->disk,
                    'path' => $document->path,
                    'original_name' => $document->original_name,
                    'size_bytes' => $document->size_bytes,
                    'sha256' => $document->sha256,
                    'sort_order' => $order,
                    'uploaded_by' => $document->uploaded_by,
                    'created_at' => $document->created_at ?? $now,
                    'updated_at' => $now,
                ];

                $order++;
            }

            // A number that was recorded with no scan behind it.
            if ($attached->isEmpty() && $number !== '') {
                $rows[] = [
                    'uuid' => (string) Str::uuid(),
                    'department_post_id' => $mappingId,
                    'government_order_number' => $number,
                    'government_order_date' => $date,
                    'disk' => null,
                    'path' => null,
                    'original_name' => null,
                    'size_bytes' => null,
                    'sha256' => null,
                    'sort_order' => 0,
                    'uploaded_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('department_post_orders')->insert($chunk);
        }
    }

    /**
     * The order number for a carried-forward document.
     *
     * The mapping holds one number and may have several scans, and
     * `unique(department_post_id, government_order_number)` will not take the same
     * number twice — so only the first keeps it unqualified. The rest are
     * distinguished by the document they came from rather than by a counter,
     * because a counter would renumber them if this ever ran twice.
     */
    private function numberFor(string $number, int $index, int $documentId): string
    {
        if ($number === '') {
            return 'Not recorded (document #'.$documentId.')';
        }

        return $index === 0 ? $number : $number.' (document #'.$documentId.')';
    }

    /**
     * Put the earliest order back on the mapping, with its PDF as the current
     * document. Lossy by construction — see the class docblock.
     */
    private function carryBack(): void
    {
        $orders = DB::table('department_post_orders')
            ->orderBy('department_post_id')
            ->orderBy('government_order_date')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('department_post_id');

        $now = now();

        foreach ($orders as $mappingId => $group) {
            $first = $group->first();

            DB::table('department_posts')->where('id', $mappingId)->update([
                'government_order_number' => $first->government_order_number,
                'government_order_date' => $first->government_order_date,
            ]);

            if ($first->path === null) {
                continue;
            }

            DB::table('department_post_documents')->insert([
                'uuid' => (string) Str::uuid(),
                'department_post_id' => $mappingId,
                'disk' => $first->disk,
                'path' => $first->path,
                'original_name' => $first->original_name,
                'size_bytes' => $first->size_bytes,
                'sha256' => $first->sha256,
                'is_current' => 1,
                'uploaded_by' => $first->uploaded_by,
                'created_at' => $first->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }
    }
};
