<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * There is one committee: the DPC Committee. Its rules are the Administrator's.
 *
 * The Committee Type master offered a choice that does not exist, and the composition
 * the Commission prescribes — the CGPSC Chairman, an SC, an ST, an OBC and a female
 * member (one member may cover several), one Bhar Sadhak Sachiv and one Vibhag
 * Sachiv — lived only in `config/dpc.php`. So:
 *
 *  - `committee_configurations` becomes the effective-dated DPC Committee rules: size,
 *    the attributes the committee must cover (`required_categories`), and whether one
 *    member must be SC, ST or OBC. Existing DPC Committee rows take today's values
 *    from configuration, so nothing a committee is checked against changes.
 *  - The configuration of any other committee type, and `committee_types`, are removed.
 *  - Eight columns no code has ever read (quorum, voting method, majority, tie-break,
 *    digital signature, remarks, external member, secretary) and `chairman_mandatory`
 *    (the Chairman is mandatory by rule, never by setting) are dropped.
 *
 * `down()` restores the type master with the DPC Committee, the columns with their
 * former defaults, and points every configuration at that type. The rows of other
 * committee types and the values of the dropped columns are not recoverable from
 * here; restore the backup for them.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const UNUSED = [
        'chairman_mandatory', 'quorum', 'voting_method', 'majority_type', 'tie_break_rule',
        'digital_signature_required', 'remarks_required', 'external_member_allowed', 'secretary_required',
    ];

    public function up(): void
    {
        $dpcType = DB::table('committee_types')->where('code', 'dpc_committee')->value('id');

        DB::table('committee_configurations')
            ->when($dpcType !== null, fn ($query) => $query->where('committee_type_id', '!=', $dpcType))
            ->delete();

        Schema::table('committee_configurations', function (Blueprint $table): void {
            $table->json('required_categories')->nullable()->after('max_members');
            $table->boolean('requires_reserved_category')->default(false)->after('required_categories');
        });

        DB::table('committee_configurations')->update([
            'required_categories' => json_encode(array_values((array) config('dpc.committee.required_categories', []))),
            'requires_reserved_category' => (bool) config('dpc.committee.require_reserved_category', false),
        ]);

        Schema::table('committee_configurations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('committee_type_id');
        });

        Schema::table('committee_configurations', function (Blueprint $table): void {
            $table->dropColumn(self::UNUSED);
        });

        Schema::dropIfExists('committee_types');
    }

    public function down(): void
    {
        Schema::create('committee_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_hi');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        $dpcType = DB::table('committee_types')->insertGetId([
            'code' => 'dpc_committee', 'name_en' => 'DPC Committee', 'name_hi' => 'डीपीसी समिति',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::table('committee_configurations', function (Blueprint $table): void {
            $table->foreignId('committee_type_id')->nullable()->after('id')->constrained('committee_types')->cascadeOnDelete();
            $table->boolean('chairman_mandatory')->default(true);
            $table->unsignedSmallInteger('quorum')->default(1);
            $table->string('voting_method', 60)->default('chairman_decision');
            $table->string('majority_type', 60)->default('not_applicable');
            $table->string('tie_break_rule', 120)->default('chairman_decision');
            $table->boolean('digital_signature_required')->default(true);
            $table->boolean('remarks_required')->default(false);
            $table->boolean('external_member_allowed')->default(false);
            $table->boolean('secretary_required')->default(false);
        });

        DB::table('committee_configurations')->update(['committee_type_id' => $dpcType]);

        Schema::table('committee_configurations', function (Blueprint $table): void {
            $table->dropColumn(['required_categories', 'requires_reserved_category']);
        });
    }
};
