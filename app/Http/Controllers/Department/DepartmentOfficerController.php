<?php

declare(strict_types=1);

namespace App\Http\Controllers\Department;

use App\Domain\Allotment\Models\ProposalAllotment;
use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\OtpService;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Department\Models\Department;
use App\Domain\Identity\Models\IdentityCorrection;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\UserManagement\Services\DepartmentUserAuthority;
use App\Domain\UserManagement\Services\UserProvisioningService;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The AO / AH picker on the allotment screens (2026-10-28): the Department Admin
 * types an e-mail; an existing officer is chosen at once, and a new one is added
 * on the spot — name, e-mail, mobile, gender, date of birth and department —
 * once the e-mail and mobile OTPs prove both. Answers in JSON for the picker.
 */
class DepartmentOfficerController extends Controller
{
    private const GOV_EMAIL = 'regex:/@([A-Za-z0-9\-]+\.)*gov\.in$/i';

    public function __construct(
        private readonly DepartmentUserAuthority $authority,
        private readonly OtpService $otp,
        private readonly UserProvisioningService $provisioning,
    ) {}

    public function lookup(Request $request): JsonResponse
    {
        $this->admin($request);
        $email = mb_strtolower(trim((string) $request->query('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['found' => false, 'message' => __('allotments.picker.invalid_email')], 422);
        }

        $user = User::query()->with('designation')->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user === null) {
            return response()->json(['found' => false]);
        }

        $commission = array_map(static fn (Role $role): string => $role->value, Role::commission());
        if (! $user->is_active || array_intersect($user->grantedRoleNames(), $commission) !== []) {
            return response()->json(['found' => false, 'blocked' => true, 'message' => __('allotments.picker.not_selectable')]);
        }

        return response()->json([
            'found' => true,
            'id' => $user->id,
            'name' => $user->name,
            'designation' => $user->designation?->name,
            'department' => $user->department?->name,
        ]);
    }

    /**
     * Send the OTPs that prove the new officer's e-mail and mobile.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $this->validated($request, $this->contactRules());

        if ($data instanceof JsonResponse) {
            return $data;
        }

        try {
            $this->otp->issue($data['email'], OtpChannel::Email, OtpPurpose::Registration);
            $this->otp->issue($data['mobile'], OtpChannel::Sms, OtpPurpose::Registration);
        } catch (OtpThrottled|OtpDeliveryFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['sent' => true, 'message' => __('allotments.picker.otp_sent')]);
    }

    /**
     * Add the officer once both codes are right. The account is posted to the
     * chosen department in the role it is being allotted (AO or AH), its e-mail
     * and mobile verified; a one-time password is returned to hand over.
     */
    public function store(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        $digits = 'digits:'.(int) config('dpc.otp.length');

        $data = $this->validated($request, $this->contactRules() + [
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:M,F'],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1940-01-01'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'role' => ['required', Rule::in(Role::departmentManageableValues())],
            'email_code' => ['required', $digits],
            'mobile_code' => ['required', $digits],
        ]);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        if (! $this->otp->verifyAll([[$data['email'], (string) $data['email_code']], [$data['mobile'], (string) $data['mobile_code']]], OtpPurpose::Registration)) {
            return response()->json(['message' => __('authui.otp.invalid_pair'), 'errors' => ['email_code' => [__('authui.otp.invalid_pair')]]], 422);
        }

        $issued = $this->provisioning->provision([
            'name' => trim($data['name']),
            'email' => $data['email'],
            'mobile' => $data['mobile'],
            'designation_id' => null,
            'role' => $data['role'],
            'department_id' => (int) $data['department_id'],
            'date_of_birth' => $data['date_of_birth'],
            'gender' => $data['gender'],
        ], $admin);

        $user = $issued->user;
        $user->forceFill(['email_verified_at' => now(), 'mobile_verified_at' => now()])->save();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'department' => Department::query()->whereKey($data['department_id'])->value('name'),
            'password' => $issued->temporaryPassword,
            'message' => __('allotments.picker.added', ['name' => $user->name]),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    /**
     * Correct the details of an AO or AH (2026-10-28) — the remedy, besides
     * choosing another officer, when their eSign name did not match. Only for an
     * officer who is the current AO or AH of one of the department's proposals
     * still in progress, never for a DigiLocker-verified identity; with a reason,
     * each changed field kept on record.
     */
    public function updateDetails(Request $request, User $officer): RedirectResponse
    {
        $admin = $this->admin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1940-01-01'],
            'gender' => ['nullable', 'in:M,F'],
            'designation_id' => ['nullable', 'integer', Rule::exists('designations', 'id')],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        abort_unless($this->isCurrentOfficerHere($officer, (int) $admin->department_id), 403);

        if ($officer->identity_verified_at !== null) {
            return back()->withErrors(['officer_details' => __('identity.errors.locked')]);
        }

        $changes = [];
        foreach (['name', 'date_of_birth', 'gender', 'designation_id'] as $field) {
            // A field the form did not send is left as it is.
            if (! $request->has($field)) {
                continue;
            }

            $old = $field === 'date_of_birth' ? $officer->date_of_birth?->toDateString() : ($officer->{$field} === null ? null : (string) $officer->{$field});
            $new = ($data[$field] ?? null) === null || $data[$field] === '' ? null : (string) $data[$field];

            if ($old !== $new) {
                $changes[$field] = [$old, $new];
            }
        }

        if ($changes === []) {
            return back()->with('status', __('allotments.details.unchanged'));
        }

        DB::transaction(function () use ($officer, $changes, $data, $admin): void {
            foreach ($changes as $field => [$old, $new]) {
                IdentityCorrection::query()->create([
                    'user_id' => $officer->id,
                    'stage' => IdentityCorrection::STAGE_ESIGN,
                    'field' => $field,
                    'old_value' => $old,
                    'new_value' => $new,
                    'reason' => $data['reason'],
                    'corrected_by' => $admin->id,
                ]);
            }

            $officer->forceFill(array_map(fn (array $pair): ?string => $pair[1], $changes))->save();
            app(ActivityTimelineService::class)->record('officer.details_corrected', $officer, 'Details corrected by the Department Admin: '.implode(', ', array_keys($changes)), ['fields' => array_keys($changes)], $admin);
            app(SecurityLog::class)->record('officer.details_corrected', $officer, context: ['fields' => implode(',', array_keys($changes))], actor: $admin);
        });

        return back()->with('status', __('allotments.details.saved', ['name' => $officer->fresh()?->name]));
    }

    private function isCurrentOfficerHere(User $officer, int $departmentId): bool
    {
        $inProgress = fn (string $table) => fn ($q) => $q->selectRaw('1')->from($table)
            ->whereColumn("{$table}.id", 'proposal_allotments.'.($table === 'pre_dpc_proposals' ? 'pre_dpc_proposal_id' : 'dpc_proposal_id'))
            ->where("{$table}.department_id", $departmentId)
            ->whereNotIn("{$table}.status", ['approved', 'cancelled']);

        return ProposalAllotment::query()->current()
            ->where(fn ($q) => $q->where('ao_user_id', $officer->id)->orWhere('ah_user_id', $officer->id))
            ->where(fn ($q) => $q->whereExists($inProgress('pre_dpc_proposals'))->orWhereExists($inProgress('dpc_proposals')))
            ->exists();
    }

    /**
     * Validate for the picker: a refusal is always JSON (422), never a redirect.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, mixed>|JsonResponse
     */
    private function validated(Request $request, array $rules): array|JsonResponse
    {
        $validator = Validator::make($request->all(), $rules, [
            'email.regex' => __('department.users.validation.gov_email'),
            'email.unique' => __('allotments.picker.email_taken'),
            'mobile.regex' => __('department.users.validation.mobile'),
            'mobile.unique' => __('allotments.picker.mobile_taken'),
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
        }

        return $validator->validated();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function contactRules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', self::GOV_EMAIL, Rule::unique('users', 'email')],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/', Rule::unique('users', 'mobile')],
        ];
    }

    private function admin(Request $request): User
    {
        $admin = $request->user();
        abort_unless($admin instanceof User && $this->authority->isDepartmentAdmin($admin), 403);

        return $admin;
    }
}
