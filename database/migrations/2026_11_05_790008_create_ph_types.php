<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The PH Type master (2026-11-05): the kind of disability a PH officer has, by the
 * codes the Government of India uses when it identifies posts for persons with
 * benchmark disabilities under the RPwD Act, 2016 (OL = One Leg, OAL = One Arm and
 * One Leg, and so on), grouped by the Act's categories (a) to (e). An ordinary
 * master: the Administrator adds, corrects or withdraws a type without a deployment.
 *
 * The officer's type is recorded with the PH answers of Employee Details.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string}> code, English, Hindi, RPwD category
     */
    private const TYPES = [
        ['B', 'Blind', 'पूर्ण दृष्टिहीन', 'a'],
        ['LV', 'Low Vision', 'अल्प दृष्टि', 'a'],
        ['D', 'Deaf', 'बधिर', 'b'],
        ['HH', 'Hard of Hearing', 'श्रवण बाधित (कम सुनाई देना)', 'b'],
        ['OA', 'One Arm', 'एक भुजा प्रभावित', 'c'],
        ['OL', 'One Leg', 'एक पैर प्रभावित', 'c'],
        ['BA', 'Both Arms', 'दोनों भुजाएँ प्रभावित', 'c'],
        ['BL', 'Both Legs', 'दोनों पैर प्रभावित', 'c'],
        ['OAL', 'One Arm and One Leg', 'एक भुजा एवं एक पैर प्रभावित', 'c'],
        ['BLOA', 'Both Legs and One Arm', 'दोनों पैर एवं एक भुजा प्रभावित', 'c'],
        ['BLA', 'Both Legs and Both Arms', 'दोनों पैर एवं दोनों भुजाएँ प्रभावित', 'c'],
        ['MW', 'Muscular Weakness', 'मांसपेशीय दुर्बलता', 'c'],
        ['CP', 'Cerebral Palsy', 'प्रमस्तिष्क घात', 'c'],
        ['LC', 'Leprosy Cured', 'कुष्ठ रोग मुक्त', 'c'],
        ['DW', 'Dwarfism', 'बौनापन', 'c'],
        ['AAV', 'Acid Attack Victim', 'तेजाब हमला पीड़ित', 'c'],
        ['MDY', 'Muscular Dystrophy', 'मांसपेशीय दुर्विकास', 'c'],
        ['ASD', 'Autism Spectrum Disorder', 'स्वलीनता (ऑटिज़्म)', 'd'],
        ['ID', 'Intellectual Disability', 'बौद्धिक दिव्यांगता', 'd'],
        ['SLD', 'Specific Learning Disability', 'विशिष्ट अधिगम दिव्यांगता', 'd'],
        ['MI', 'Mental Illness', 'मानसिक रुग्णता', 'd'],
        ['DB', 'Deaf-Blindness', 'बधिर-दृष्टिहीनता', 'e'],
        ['MD', 'Multiple Disabilities', 'बहु-दिव्यांगता', 'e'],
    ];

    public function up(): void
    {
        Schema::create('ph_types', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('code', 20);
            $table->string('name_en');
            $table->string('name_hi');
            // The RPwD Act's category, (a) to (e).
            $table->string('category', 1)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            // A deleted type can be entered again (2026-11-03): only live rows are unique.
            $table->unsignedTinyInteger('live_flag')->nullable()->virtualAs('CASE WHEN deleted_at IS NULL THEN 1 END');
            $table->unique(['code', 'live_flag'], 'ph_types_code_live_unique');
        });

        $now = now();

        foreach (self::TYPES as $index => [$code, $en, $hi, $category]) {
            DB::table('ph_types')->insert([
                'uuid' => (string) Str::uuid(),
                'code' => $code,
                'name_en' => $en,
                'name_hi' => $hi,
                'category' => $category,
                'sort_order' => ($index + 1) * 10,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('dpc_evaluation_rows', function (Blueprint $table): void {
            $table->foreignId('ph_type_id')->nullable()->after('is_ph')->constrained('ph_types')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_evaluation_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ph_type_id');
        });

        Schema::dropIfExists('ph_types');
    }
};
