<?php

declare(strict_types=1);

namespace App\Domain\Employee\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One officer of the employee database (2026-11-02), by Employee ID. Unlike the
 * proposal-time snapshots (Seniority and Working Officers Lists) this is a live
 * reference: the latest details any of them gave, kept after DPC data is cleared.
 * Written only through `EmployeeDirectory`.
 *
 * @property int $id
 * @property string $employee_number
 * @property string $name
 * @property string|null $email
 * @property string|null $mobile
 * @property Carbon|null $date_of_birth
 * @property string|null $qualification
 * @property string|null $current_post
 * @property Carbon|null $date_of_joining_service
 * @property Carbon|null $date_of_retirement
 * @property int|null $department_id
 * @property int|null $user_id
 * @property string $source
 * @property Carbon|null $last_seen_at
 * @property-read Department|null $department
 * @property-read User|null $user
 */
class Employee extends Model
{
    public const SOURCE_WORKING_LIST = 'working_list';

    public const SOURCE_SENIORITY_LIST = 'seniority_list';

    public const SOURCE_VACANCY_MANAGEMENT = 'vacancy_management';

    public const SOURCE_USER_ACCOUNT = 'user_account';

    protected $fillable = [
        'employee_number', 'name', 'email', 'mobile', 'date_of_birth', 'qualification',
        'current_post', 'date_of_joining_service', 'date_of_retirement', 'department_id',
        'user_id', 'source', 'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'date_of_joining_service' => 'date',
            'date_of_retirement' => 'date',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
