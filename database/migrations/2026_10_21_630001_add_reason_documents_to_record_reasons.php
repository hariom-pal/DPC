<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coder Instructions rule 3.2: the document a missing record is justified with
 * follows its reason (business decision, 2026-10-21) — the NRC for an NRC, the leave
 * sanction for long leave, and both the suspension and the reinstatement orders for
 * a suspension.
 *
 * Each reason names the document it needs and, where it needs two, the second; a gap
 * whose reason needs a second document carries it beside the first. Additive:
 * nullable columns, and the three rule-3.2 reasons get their documents named.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{0: string, 1: string, 2: string|null, 3: string|null}>
     */
    private const DOCUMENTS = [
        'nrc_issued' => ['NRC copy', 'अनापत्ति प्रमाण-पत्र (NRC) की प्रति', null, null],
        'long_leave' => ['Leave sanction order', 'अवकाश स्वीकृति आदेश', null, null],
        'suspended' => ['Suspension order', 'निलंबन आदेश', 'Reinstatement order', 'बहाली आदेश'],
    ];

    public function up(): void
    {
        Schema::table('record_reasons', function (Blueprint $table): void {
            $table->string('document_label_en')->nullable();
            $table->string('document_label_hi')->nullable();
            $table->string('second_document_label_en')->nullable();
            $table->string('second_document_label_hi')->nullable();
        });

        Schema::table('dpc_evaluation_record_gaps', function (Blueprint $table): void {
            $table->string('second_disk', 40)->nullable();
            $table->string('second_path')->nullable();
            $table->string('second_original_name')->nullable();
            $table->unsignedBigInteger('second_size_bytes')->nullable();
            $table->char('second_sha256', 64)->nullable();
        });

        foreach (self::DOCUMENTS as $code => [$en, $hi, $secondEn, $secondHi]) {
            DB::table('record_reasons')->where('code', $code)->whereNull('document_label_en')->update([
                'document_label_en' => $en,
                'document_label_hi' => $hi,
                'second_document_label_en' => $secondEn,
                'second_document_label_hi' => $secondHi,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('dpc_evaluation_record_gaps', function (Blueprint $table): void {
            $table->dropColumn(['second_disk', 'second_path', 'second_original_name', 'second_size_bytes', 'second_sha256']);
        });

        Schema::table('record_reasons', function (Blueprint $table): void {
            $table->dropColumn(['document_label_en', 'document_label_hi', 'second_document_label_en', 'second_document_label_hi']);
        });
    }
};
