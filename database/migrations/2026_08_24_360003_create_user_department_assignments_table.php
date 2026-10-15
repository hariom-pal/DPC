<?php

declare(strict_types=1);

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user's department and role become a **dated assignment**, not a property of
 * the account.
 *
 * Business ruling, 2026-08-24 (change item 2). Government officers transfer
 * frequently: Hariom Pal is an AO in Higher Education today and an AO in Forest
 * tomorrow, and it is the *same person*, with the same login. The register's
 * instruction is exact — "Users must NEVER permanently belong to a department"
 * and "Never create duplicate users" — and `users.department_id` said the
 * opposite in the one place the whole application read it from.
 *
 * **What a transfer now is.** Deactivate the incumbent assignment (stamping
 * `effective_to`), insert a new one. Nothing is deleted and nothing is
 * overwritten, so "which department was this officer in when they signed that
 * proposal in March?" is a question the database can still answer — which it
 * could not before, because the column simply changed under the signature.
 *
 * **`active_flag` is nullable, and that is the whole mechanism.** It holds `1`
 * for the one live assignment and `NULL` for every superseded one. Neither MySQL
 * nor SQLite treats two NULLs as equal in a unique index, so
 * `unique(user_id, active_flag)` permits a user any number of past assignments
 * and *exactly one* current — enforced by the database rather than by
 * remembering to check. A plain boolean cannot express that: `unique(user_id,
 * is_active)` would also cap the history at one row.
 *
 * `role` is a plain string rather than a foreign key onto `roles`. The role
 * vocabulary is the {@see Role} enum — a fixed constant of the system, like
 * `ServiceClass` — and Spatie's `roles` table is the *permission* projection of
 * it. The assignment is the source of truth; the projection is synced from it
 * whenever an assignment activates, which is what keeps `role:` middleware and
 * every existing policy working untouched.
 *
 * **`users.department_id` and `users.level` both go.** The first is what this
 * table replaces. The second held `1` for an Authorized Officer and `2` for an
 * Administrative Head — the same fact as the role, in a second place, written on
 * registration and then read by nothing but two test fixtures. Two columns
 * asserting one fact is how they come to disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_department_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();

            // The Role enum's value. Widest current value is
            // `administrative_head` at 20 characters; 40 leaves room without
            // pretending this is free text.
            $table->string('role', 40);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // 1 = the user's current assignment, NULL = superseded. See the
            // class note: the nullability is what makes the unique index below
            // mean "at most one active", rather than "at most one of each".
            $table->unsignedTinyInteger('active_flag')->nullable();

            $table->string('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'active_flag'], 'uda_user_active_unique');

            // "Who are the Authorized Officers of Higher Education right now" is
            // the query every notification, policy and dashboard makes.
            $table->index(['department_id', 'role', 'active_flag'], 'uda_department_role_active_index');
        });

        $this->backfill();

        /*
         | Constraint, then index, then column — three statements, in that order,
         | and no engine guard.
         |
         | `users.department_id` carries both an explicit
         | `users_department_id_index` (from `create_users_table`) and a foreign
         | key onto `departments` (added later by `create_departments_table`).
         | Both engines refuse to drop the column while either still names it:
         | MySQL with error 1553 on the index and a constraint error on the key,
         | SQLite with "unknown column department_id in foreign key definition".
         |
         | They are separate `Schema::table()` calls because SQLite implements
         | `dropForeign` by rebuilding the table, and its grammar will not combine
         | a rebuild with other column work in one blueprint. One statement each is
         | also the only order in which the intent survives reading.
         */
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['department_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_department_id_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['department_id', 'level']);
        });
    }

    public function down(): void
    {
        // Restored as the two creating migrations declared them between them: an
        // indexed column, the foreign key `create_departments_table` added to it,
        // and the tiny-integer level beside it. `foreign()` on an existing table
        // compiles to nothing on SQLite, which is why it needs no guard here
        // where the drop above did.
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('department_id')->nullable()->after('mobile')->index();
            $table->unsignedTinyInteger('level')->nullable()->after('department_id')
                ->comment('1 = Authorized Officer, 2 = Administrative Head');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });

        // Restore the single-department view of the world from whichever
        // assignment is currently active. A user with several past assignments
        // necessarily loses all but the live one — the column cannot hold them,
        // which is the reason this table exists.
        foreach (DB::table('user_department_assignments')->whereNotNull('active_flag')->get() as $assignment) {
            DB::table('users')->where('id', $assignment->user_id)->update([
                'department_id' => $assignment->department_id,
                'level' => match ($assignment->role) {
                    Role::AuthorizedOfficer->value => 1,
                    Role::AdministrativeHead->value => 2,
                    default => null,
                },
            ]);
        }

        Schema::dropIfExists('user_department_assignments');
    }

    /**
     * Turn every existing department account into its first assignment.
     *
     * The role is read from Spatie rather than from `users.level`, because the
     * role is what the application actually enforces on and `level` only ever
     * covered the two department roles. A department account with no role grant
     * at all — which no code path creates, but a hand-edited database might
     * hold — is assigned nothing and left for an administrator to fix, rather
     * than being given a role this migration invented.
     *
     * `effective_from` is the account's creation date: the earliest date the
     * system can honestly claim the posting began.
     */
    private function backfill(): void
    {
        $rows = DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', 'App\Domain\Authentication\Models\User');
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereNotNull('users.department_id')
            ->whereIn('roles.name', [Role::AuthorizedOfficer->value, Role::AdministrativeHead->value])
            ->orderBy('users.id')
            ->get(['users.id as user_id', 'users.department_id', 'users.created_at', 'roles.name as role']);

        $seen = [];
        $now = now();

        foreach ($rows as $row) {
            // A user holding both department roles would otherwise breach
            // `uda_user_active_unique` mid-migration. The first wins, which is
            // deterministic because the query is ordered.
            if (isset($seen[$row->user_id])) {
                continue;
            }

            $seen[$row->user_id] = true;

            DB::table('user_department_assignments')->insert([
                'user_id' => $row->user_id,
                'department_id' => $row->department_id,
                'role' => $row->role,
                'effective_from' => ($row->created_at === null ? $now : Carbon::parse($row->created_at))->toDateString(),
                'effective_to' => null,
                'active_flag' => 1,
                'remarks' => 'Migrated from users.department_id on 2026-08-24.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
