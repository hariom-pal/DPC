<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\Designation;
use App\Domain\UserManagement\Services\UserProvisioningService;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Support\OfficialEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create the default Secretary, CGPSC account (2026-11-15).
 *
 * Every DPC the AH approves waits for the Secretary before it reaches the Chairman,
 * so an installation without one stops there. The Secretary works through the role
 * alone — there is no master record as there is for the Chairman — so the default
 * Secretary is the account itself.
 *
 * Same safeguards as `dpc:bootstrap-super-admin`: a random temporary password,
 * printed once to the operator's terminal, stored only as a hash and changed at
 * first login, where the e-mail is proved by OTP. Idempotent: when an active
 * Secretary already exists nothing is created. Later Secretaries are provisioned
 * in User Management.
 */
class BootstrapSecretaryCommand extends Command
{
    private const DESIGNATION = 'Secretary, CGPSC';

    protected $signature = 'dpc:bootstrap-secretary
        {--name= : Full name}
        {--email= : Official e-mail (.gov.in / .nic.in)}
        {--mobile= : 10-digit mobile number}';

    protected $description = 'Create the default Secretary, CGPSC account (once)';

    public function handle(UserProvisioningService $provisioning): int
    {
        // The role, for a database migrated but never seeded.
        SpatieRole::findOrCreate(Role::Secretary->value, 'web');

        $existing = User::role(Role::Secretary->value)->where('is_active', true)->first();

        if ($existing instanceof User) {
            $this->info("A Secretary, CGPSC already exists: {$existing->email}. Nothing created.");

            return self::SUCCESS;
        }

        $data = [
            'name' => (string) ($this->option('name') ?: $this->ask('Full name')),
            'email' => mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Official e-mail (.gov.in / .nic.in)')))),
            'mobile' => (string) ($this->option('mobile') ?: $this->ask('Mobile (10 digits)')),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', OfficialEmail::RULE, Rule::unique('users', 'email')->withoutTrashed()],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/', Rule::unique('users', 'mobile')->withoutTrashed()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $designation = Designation::query()->firstOrNew(['name' => self::DESIGNATION]);

        if (! $designation->exists) {
            $designation->forceFill(['status' => MasterStatus::Active->value])->save();
        }

        $issued = $provisioning->provision($data + ['designation_id' => $designation->id, 'role' => Role::Secretary->value]);

        $this->info("Secretary, CGPSC created: {$issued->user->email}");
        $this->newLine();
        // Raw, as in dpc:bootstrap-super-admin: the formatter would alter `<` and `\`.
        $this->output->writeln('  Temporary password (shown once, not stored): '.$issued->temporaryPassword, OutputInterface::OUTPUT_RAW);
        $this->newLine();
        $this->warn('Hand it over securely. It must be changed at first login, where the e-mail is also verified by OTP.');

        return self::SUCCESS;
    }
}
