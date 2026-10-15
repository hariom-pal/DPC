<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Requests;

use App\Domain\Authentication\Models\User;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Posting an officer to a department, or transferring them (change item 2).
 */
class AssignUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'department_id' => [
                'required',
                Rule::exists('departments', 'id')->where('status', DepartmentStatus::Registered->value),
            ],

            // Only the departmental roles. A Commission role is not a posting and
            // is set on the account itself.
            'role' => ['required', Rule::in(Role::departmentalValues())],

            /*
             | Optional, defaulting to today in the service.
             |
             | A back-dated posting is permitted — transfer orders routinely reach
             | the portal after they take effect — and so is a future-dated one, so
             | an administrator can enter next month's order today. What is refused
             | below is a start date that would overlap the posting it replaces.
             */
            'effective_from' => ['nullable', 'date'],

            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $user = $this->route('user');

            if (! $user instanceof User) {
                return;
            }

            $incumbent = $user->activeAssignment;

            if ($incumbent === null) {
                return;
            }

            /*
             | A new posting must begin after the current one began.
             |
             | Without this, a transfer dated before the posting it supersedes
             | produces a history in which the officer was in Forest before they
             | were in Higher Education, and the closing date the service computes
             | for the incumbent (the day before the new one starts) would fall
             | before its own start date. The history is the whole reason this
             | table exists, so it is worth refusing rather than storing.
             */
            if ($this->filled('effective_from')
                && $this->date('effective_from')?->startOfDay()->lessThanOrEqualTo($incumbent->effective_from)) {
                $validator->errors()->add('effective_from', sprintf(
                    'This officer\'s current posting began on %s. A transfer must take effect after that date — '
                    .'correct the current posting first if the date on the order is earlier.',
                    $incumbent->effective_from->format('d-m-Y'),
                ));
            }

            if ((int) $this->input('department_id') === $incumbent->department_id
                && (string) $this->input('role') === $incumbent->role->value) {
                $validator->errors()->add('department_id', sprintf(
                    'This officer is already %s in %s. Choose a different department or role, '
                    .'or release them if the posting has simply ended.',
                    $incumbent->role->label(),
                    $incumbent->department === null ? 'that department' : $incumbent->department->name,
                ));
            }
        });
    }
}
