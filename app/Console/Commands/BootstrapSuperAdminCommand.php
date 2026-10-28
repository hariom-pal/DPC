<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authentication\Models\User;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\UserManagement\Services\UserProvisioningService;
use App\Enums\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create the Portal Super Admin on a fresh installation, or recover its password
 * (business decision, 2026-10-26).
 *
 * Replaces the seeded administrator with a configured — and by default shared —
 * password. A random temporary password is generated, printed once to the
 * operator's terminal, stored only as a hash, and must be changed at first login;
 * the e-mail is proved by OTP at that login. Nothing here writes the password to a
 * log, the database in clear, or a notification.
 *
 * Refuses to create a second Super Admin: later administrators are provisioned in
 * User Management. `--recover` re-issues the password of an existing Super Admin,
 * with a recorded reason.
 */
class BootstrapSuperAdminCommand extends Command
{
    protected $signature = 'dpc:bootstrap-super-admin
        {--name= : Full name}
        {--email= : Official e-mail (.gov.in)}
        {--mobile= : 10-digit mobile number}
        {--recover : Issue a new temporary password to the existing Super Admin named by --email}
        {--reason= : Why the password is being recovered (required with --recover)}';

    protected $description = 'Create the Portal Super Admin (fresh installation) or recover its password';

    public function handle(UserProvisioningService $provisioning, ActivityTimelineService $timeline): int
    {
        if (! SpatieRole::query()->where('name', Role::Admin->value)->exists()) {
            $this->error('The roles are not seeded. Run: php artisan db:seed (or --class=RolesAndPermissionsSeeder) first.');

            return self::FAILURE;
        }

        return $this->option('recover') ? $this->recover($provisioning, $timeline) : $this->create($provisioning);
    }

    private function create(UserProvisioningService $provisioning): int
    {
        if (User::role(Role::Admin->value)->exists()) {
            $this->error('A Portal Super Admin already exists. Provision further administrators in User Management, or use --recover.');

            return self::FAILURE;
        }

        $data = [
            'name' => (string) ($this->option('name') ?: $this->ask('Full name')),
            'email' => mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Official e-mail (.gov.in)')))),
            'mobile' => (string) ($this->option('mobile') ?: $this->ask('Mobile (10 digits)')),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'regex:/@([A-Za-z0-9\-]+\.)*gov\.in$/i', 'unique:users,email'],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/', 'unique:users,mobile'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $issued = $provisioning->provision($data + ['designation_id' => null, 'role' => Role::Admin->value]);

        $this->info("Portal Super Admin created: {$issued->user->email}");
        $this->printPassword($issued->temporaryPassword);

        return self::SUCCESS;
    }

    private function recover(UserProvisioningService $provisioning, ActivityTimelineService $timeline): int
    {
        $email = mb_strtolower(trim((string) $this->option('email')));
        $reason = trim((string) $this->option('reason'));

        if ($email === '' || $reason === '') {
            $this->error('--recover needs --email of the Super Admin and a --reason.');

            return self::FAILURE;
        }

        $user = User::role(Role::Admin->value)->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user instanceof User) {
            $this->error('No Portal Super Admin has that e-mail.');

            return self::FAILURE;
        }

        $password = $provisioning->resetPassword($user);
        $timeline->record('user.password_recovered', $user, "Super Admin password recovered from the console: {$reason}");

        $this->info("A new temporary password was issued to {$user->email}. The previous password no longer works.");
        $this->printPassword($password);

        return self::SUCCESS;
    }

    private function printPassword(string $password): void
    {
        $this->newLine();
        // Written raw: the console formatter reads `<...>` as style tags and `\<`/`\>`
        // as escapes, and Str::password() can produce both, so a formatted line can
        // show a password that is not the one stored (about 4 in 1,000, 2026-10-26).
        $this->output->writeln('  Temporary password (shown once, not stored): '.$password, OutputInterface::OUTPUT_RAW);
        $this->newLine();
        $this->warn('Hand it over securely. It must be changed at first login, where the e-mail is also verified by OTP.');
    }
}
