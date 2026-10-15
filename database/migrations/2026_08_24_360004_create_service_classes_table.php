<?php

declare(strict_types=1);

use App\Enums\ServiceClass;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The **Class Master** (change item 1): the Administrator's control over how the
 * service classes are named and presented.
 *
 * The register lists Class Master among the masters the single CGPSC
 * Administrator owns, and until now there was no such screen — Class-I, Class-II
 * and Class-III were an enum with a hardcoded English label, so a department
 * seeing "Class-I" on a Hindi screen had no way to be given "वर्ग-1" short of a
 * deployment.
 *
 * **What the Administrator can change here, and what they deliberately cannot.**
 * The bilingual name, the display order and the active flag are all theirs. The
 * `code` is not: the code set is statutory (the Chhattisgarh service rules fix
 * Class I / II / III), and it is what the M5 scoring engine keys its
 * zone-of-consideration rules on — `scoring.zone.rules` is a map of class *pairs*
 * — so a code invented at runtime would be a class the engine has no rule for
 * and would silently fall through to the uncapped default. Codes are therefore
 * seeded from {@see ServiceClass} and validated against it.
 *
 * Making the code set itself administrable is a larger change with a real effect
 * on promotion outcomes; it is recorded in `docs/SUGGESTIONS_AND_IMPROVEMENTS.md`
 * for a decision rather than taken here.
 *
 * **No data migration.** Every `class_level` column already stores the code, and
 * still does. This table adds attributes *about* a class; it does not become a
 * foreign key, because turning 119 existing `class_level` reads into joins would
 * be churn on the scoring path for no gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_classes', function (Blueprint $table): void {
            $table->id();

            // Matches the width of every `class_level` column in the schema.
            $table->string('code', 5)->unique();

            $table->string('name_en');
            $table->string('name_hi')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Seeded here rather than in a seeder: the labels are what every existing
        // screen already displays, so a deployment that ran the migration and not
        // the seeder would blank them.
        $order = 0;
        $hindi = ['I' => 'वर्ग-1', 'II' => 'वर्ग-2', 'III' => 'वर्ग-3'];

        foreach (ServiceClass::cases() as $class) {
            DB::table('service_classes')->insert([
                'code' => $class->value,
                'name_en' => 'Class-'.$class->value,
                'name_hi' => $hindi[$class->value],
                'sort_order' => $order += 10,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_classes');
    }
};
