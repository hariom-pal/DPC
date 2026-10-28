<?php

declare(strict_types=1);

namespace App\Domain\Department\Requests;

use App\Domain\Department\Exceptions\InvitationRefused;
use App\Domain\Department\Models\Department;
use App\Domain\Department\Services\DepartmentInvitationService;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The CGPSC Super Admin's invitation (2026-10-28): the department and the
 * official e-mail of the person it nominated. Everything else about the person
 * comes from them and from DigiLocker.
 */
class StoreDepartmentInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(Role::Admin->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'email' => ['required', 'email', 'max:255', 'regex:/@([A-Za-z0-9\-]+\.)*gov\.in$/i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['email.regex' => __('department.users.validation.gov_email')];
    }

    /**
     * The department's own e-mail (2026-10-28), checked here so the refusal comes
     * before anything is sent — and names the department when it is another's.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $department = Department::query()->find($this->integer('department_id'));

            if ($validator->errors()->isNotEmpty() || $department === null) {
                return;
            }

            try {
                app(DepartmentInvitationService::class)->assertDepartmentEmail($department, (string) $this->input('email'));
            } catch (InvitationRefused $e) {
                $validator->errors()->add('email', $e->getMessage());
            }
        }];
    }
}
