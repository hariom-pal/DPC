<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authentication\Models\User;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Enums\Role;
use Database\Seeders\Demo\DemoAccountSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;

/**
 * Publish the demonstration login credentials, and make sure every role the
 * end-to-end walkthrough needs actually has an account.
 *
 * **This exists because a password that cannot be read is a login that cannot be
 * used.** Every account is stored as a hash, so there is nothing to recover: the
 * only honest way to produce a credential sheet is to *set* the passwords and then
 * print what was set. That is what this does.
 *
 * **It refuses in production, and the refusal is the point.** The output is a list
 * of working logins for every role in the portal, including the Administrator. On a
 * live installation that file is the whole system, and no flag makes it run there.
 *
 * **It creates nobody.** It used to invent an Authorized Officer and an Administrative
 * Head for Higher Education, on the argument that a second registered department is
 * the only way to see by hand that one department's DPC is invisible to another's
 * officers. That argument lost: {@see DemoAccountSeeder}
 * settles on **one** department, *"so the presenter never has to explain why the record
 * on screen is in a different department from the one they just showed"* — and this
 * command ran after the seeder and quietly put the second one back. A command whose
 * job is to publish passwords has no business deciding how many departments the
 * demonstration has. Cross-department isolation is pinned by the test suite, which is
 * where a security property belongs; it does not need a standing account to prove it.
 *
 * What it does instead is **report**: if accounts exist for more than one department,
 * it names them and says so, rather than silently widening the demonstration.
 */
class DemoCredentialsCommand extends Command
{
    protected $signature = 'demo:credentials
        {--password= : The shared password to set (defaults to the demo seeder\'s)}
        {--no-pdf : Write the Markdown only}';

    protected $description = 'Reset demo passwords, create any missing demo users, and write the credential sheet';

    private const MARKDOWN = 'docs/demo/DEMO_LOGIN_CREDENTIALS.md';

    private const PDF = 'docs/demo/DEMO_LOGIN_CREDENTIALS.pdf';

    /**
     * The order roles are presented in — the order the workflow runs in, so the
     * sheet reads like the walkthrough rather than like the database.
     *
     * @var list<Role>
     */
    private const ORDER = [
        Role::Admin,
        Role::AuthorizedOfficer,
        Role::AdministrativeHead,
        Role::Chairman,
        Role::Member,
        Role::PromotionCandidate,
        Role::Individual,
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refused: this writes a file containing a working password for every role, including the Administrator.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: DemoAccountSeeder::PASSWORD);

        $reset = $this->resetPasswords($password);

        $markdown = $this->render($password);
        File::ensureDirectoryExists(base_path('docs/demo'));
        File::put(base_path(self::MARKDOWN), $markdown);

        $this->info(sprintf('%d password(s) reset to %s.', $reset, $password));
        $this->line('Wrote '.self::MARKDOWN);
        $this->reportDepartments();

        if (! $this->option('no-pdf')) {
            $this->writePdf($markdown);
        }

        return self::SUCCESS;
    }

    /**
     * Say how many departments the accounts actually span.
     *
     * **A report, never a repair.** One department is what the walkthrough is built
     * for, but an operator who deliberately set up a second has a reason, and a
     * credentials command is the wrong place to overrule it — this one publishes
     * passwords and must not be a thing that deletes accounts behind your back.
     * Naming them is enough: the state becomes visible at the moment it would
     * otherwise surprise somebody mid-demonstration.
     */
    private function reportDepartments(): void
    {
        $departments = UserDepartmentAssignment::query()
            ->with('department')
            ->get()
            ->pluck('department.name')
            ->filter()
            ->unique()
            ->values();

        if ($departments->count() <= 1) {
            $this->line(sprintf(
                'One department: %s.',
                $departments->first() ?? 'none assigned yet',
            ));

            return;
        }

        $this->warn(sprintf('%d departments hold accounts, not one:', $departments->count()));

        foreach ($departments as $name) {
            $this->line("  - {$name}");
        }

        $this->line('The walkthrough is written for a single department. Remove the others, or expect to explain them.');
    }

    /**
     * Set one known password on every account, and clear the first-login prompt.
     *
     * **The prompt is cleared deliberately, and only here.** `must_change_password`
     * is correct behaviour and stays in force for real accounts; on a sheet whose
     * whole purpose is "sign in as this person and try the workflow", being stopped
     * at a change-password screen seven times is friction with nothing to teach.
     */
    private function resetPasswords(string $password): int
    {
        $hash = Hash::make($password);
        $count = 0;

        foreach (User::query()->get() as $user) {
            $user->forceFill([
                'password' => $hash,
                'must_change_password' => false,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'mobile_verified_at' => $user->mobile_verified_at ?? now(),
            ])->save();

            $count++;
        }

        return $count;
    }

    private function render(string $password): string
    {
        $lines = [];
        $lines[] = '# Demonstration login credentials';
        $lines[] = '';
        $lines[] = '**e-DPC Portal · Chhattisgarh Public Service Commission**';
        $lines[] = sprintf('Generated %s for the `%s` environment.', now()->format('d-m-Y H:i'), app()->environment());
        $lines[] = '';
        $lines[] = '> **Development and demonstration only.** Every account below shares one password, the';
        $lines[] = '> first-login password change is switched off, and both contact channels are marked';
        $lines[] = '> verified. None of that is true of a real installation, and this file is never';
        $lines[] = '> generated on one — `demo:credentials` refuses to run in production.';
        $lines[] = '';
        $lines[] = sprintf('**Password for every account below: `%s`**', $password);
        $lines[] = '';
        $lines[] = '**Signing in** — the login screen accepts an **e-mail address**, a **10-digit mobile';
        $lines[] = 'number** or an **employee number**, and works out which you gave it. Promotion Candidates';
        $lines[] = 'have only an employee number.';
        $lines[] = '';
        $lines[] = '**Accounts holding more than one role** show an *Acting as* control in the header. The role';
        $lines[] = 'selected there gates what the session may do, so an account acting as a Member cannot approve';
        $lines[] = 'as an Authorized Officer until it switches back. The Role(s) column below names everything';
        $lines[] = 'each account may switch to.';
        $lines[] = '';

        $lines[] = '## Who exists, by role';
        $lines[] = '';
        $lines[] = '| Role | Accounts |';
        $lines[] = '|---|---|';

        foreach (self::ORDER as $role) {
            $lines[] = sprintf('| %s | %d |', $role->label(), $this->usersFor($role)->count());
        }

        $lines[] = sprintf('| **Total** | **%d** |', User::query()->count());
        $lines[] = '';

        foreach (self::ORDER as $role) {
            $users = $this->usersFor($role);

            $lines[] = sprintf('## %s (%d)', $role->label(), $users->count());
            $lines[] = '';

            if ($users->isEmpty()) {
                $lines[] = $this->emptyRoleNote($role);
                $lines[] = '';

                continue;
            }

            if ($role === Role::PromotionCandidate) {
                // Candidature is derived from the Seniority Lists rather than
                // stored, so an account can exist with nothing to show. Said here
                // because "I logged in and My DPC is empty" is otherwise a bug
                // report rather than the expected state after a reset.
                $lines[] = 'These accounts were provisioned by earlier Seniority Lists. **My DPC will be empty until a';
                $lines[] = 'list names their employee number again** — candidature is read from the lists, not stored on';
                $lines[] = 'the account, so saving a Seniority List that carries one of these numbers reconnects it.';
                $lines[] = '';
            }

            /*
             | Every field the register of 2026-08-26 names, and one it implies.
             |
             | **All roles**, not just the section's, because item 9 makes one
             | account able to hold several — a reader who saw only the heading role
             | would not know the account they are signing in as can switch, which
             | is one of the things the walkthrough is for. The section's own role is
             | emboldened so the row still reads in context.
             */
            $lines[] = '| Name | Login ID | E-mail | Mobile | Employee no. | Department | Active assignment | Role(s) | Status |';
            $lines[] = '|---|---|---|---|---|---|---|---|---|';

            foreach ($users as $user) {
                $assignment = $user->activeAssignment;

                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s | %s | %s | %s | %s |',
                    $user->name,
                    $this->loginId($user),
                    $user->email ?: '—',
                    $user->mobile ?: '—',
                    $user->employee_number ?: '—',
                    $assignment === null ? '—' : $assignment->department->name,
                    $assignment === null
                        ? '—'
                        : sprintf('%s, from %s', $assignment->role->label(), $assignment->effective_from->format('d-m-Y')),
                    $this->roleList($user, $role),
                    $user->is_active ? 'Active' : 'Inactive',
                );
            }

            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = '*Regenerate with `php artisan demo:credentials`. Every password is reset each time it runs.*';

        return implode("\n", $lines)."\n";
    }

    /**
     * @return Collection<int, User>
     */
    private function usersFor(Role $role): Collection
    {
        return User::query()
            ->role($role->value)
            ->with(['activeAssignment.department'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Every role the account holds, with the section's own emboldened.
     *
     * Read through `grantedRoles()` rather than the `roles` relation, because
     * `ResolveActiveRole` constrains that relation on an HTTP request — and a
     * console command that read it would be fine today and wrong the moment
     * anything shares the code.
     */
    private function roleList(User $user, Role $section): string
    {
        $roles = $user->grantedRoles();

        if ($roles === []) {
            return '—';
        }

        return collect($roles)
            ->map(fn (Role $held): string => $held === $section ? '**'.$held->label().'**' : $held->label())
            ->implode(', ');
    }

    /**
     * What to type into the login box.
     *
     * A candidate has no e-mail and no mobile — a Seniority List carries neither —
     * so their employee number is the only identifier they have.
     */
    private function loginId(User $user): string
    {
        return $user->email ?: ($user->mobile ?: ($user->employee_number ?: '—'));
    }

    private function emptyRoleNote(Role $role): string
    {
        if ($role === Role::Individual) {
            return 'No accounts, and none is needed: an officer under consideration reaches Self-Inspection '
                .'**without signing in**, so nothing in the portal is gated on this role. It is kept for the '
                .'audit trail of accounts that once held it.';
        }

        if ($role === Role::PromotionCandidate) {
            // Zero is the *correct* state before a walkthrough, and saying only
            // "none" invites somebody to go and seed some. Committing the Seniority
            // List is what provisions them, under the employee numbers the list
            // carries, and the one-time passwords are shown once on that screen.
            return 'None, and that is the state a walkthrough should start from: committing the Seniority '
                .'List **provisions these accounts itself**, under the employee numbers the list carries, '
                .'and shows their one-time passwords once. Accounts already standing under those numbers '
                .'would be matched instead of created, and nothing would be reported as provisioned.';
        }

        return 'No accounts hold this role.';
    }

    /**
     * Render the sheet to PDF.
     *
     * Chromium's headless print is used because the portal ships no PDF library —
     * documents are HTML by design (see the Architecture Handbook). If it is not
     * installed the Markdown still stands on its own, and this says so rather than
     * failing the command: the credential sheet is the deliverable, and the PDF is
     * a convenience.
     */
    private function writePdf(string $markdown): void
    {
        $binary = collect(['chromium', 'chromium-browser', 'google-chrome'])
            ->first(fn (string $candidate): bool => Process::run(['which', $candidate])->successful());

        if ($binary === null) {
            $this->warn('No Chromium found, so '.self::PDF.' was not written. The Markdown above is complete.');

            return;
        }

        /*
         | Staged through the home directory, and not by choice.
         |
         | Chromium is commonly installed as a **snap**, which is confined: it can
         | read and write under `$HOME` and almost nowhere else. Pointed at a path
         | inside the project it reports success and writes into its own private
         | mount namespace, so the file never appears — a failure that looks exactly
         | like a success from the outside.
         |
         | The directory is **not** hidden, and that is the second half of the same
         | problem: snapd's home interface excludes dot-directories, so a staging
         | path of `~/.dpc-demo-credentials` is refused with "Permission denied"
         | while `~/dpc-demo-credentials` is allowed. Both failures cost an hour to
         | find once; the comment is here so they cost nobody else one.
         */
        $staging = rtrim((string) (getenv('HOME') ?: sys_get_temp_dir()), '/').'/dpc-demo-credentials';
        File::ensureDirectoryExists($staging);

        $html = $staging.'/credentials.html';
        $pdf = $staging.'/credentials.pdf';

        File::put($html, $this->html($markdown));
        File::delete($pdf);

        $result = Process::timeout(180)->run([
            $binary,
            '--headless',
            '--disable-gpu',
            '--no-sandbox',
            '--no-pdf-header-footer',
            '--print-to-pdf='.$pdf,
            'file://'.$html,
        ]);

        if ($result->successful() && File::exists($pdf)) {
            File::ensureDirectoryExists(base_path('docs/demo'));
            // Delete first: File::copy() takes no overwrite flag, and a stale PDF
            // beside a regenerated Markdown is worse than no PDF at all.
            File::delete(base_path(self::PDF));
            File::copy($pdf, base_path(self::PDF));
            File::deleteDirectory($staging);

            $this->line('Wrote '.self::PDF);

            return;
        }

        File::deleteDirectory($staging);

        $this->warn('Chromium could not write the PDF. The Markdown above is complete.');
    }

    /**
     * Wrap the Markdown in enough HTML to print sensibly.
     *
     * A deliberately small converter rather than a Markdown library: the sheet is
     * headings, paragraphs, list items and pipe tables, and pulling in a dependency
     * to render six constructs on a demo document is not worth the supply chain.
     */
    private function html(string $markdown): string
    {
        $body = '';
        $rows = [];

        foreach (explode("\n", $markdown) as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '|')) {
                // The `|---|---|` separator row carries no content.
                if (preg_match('/^[\s\-:|]+$/', $trimmed) !== 1) {
                    $rows[] = array_map('trim', explode('|', trim($trimmed, '|')));
                }

                continue;
            }

            // Any non-table line ends the table that was being collected.
            $body .= $this->tableHtml($rows);
            $rows = [];

            if ($trimmed === '' || $trimmed === '---') {
                continue;
            }

            if (str_starts_with($trimmed, '## ')) {
                $body .= '<h2>'.$this->inline(substr($trimmed, 3)).'</h2>';
            } elseif (str_starts_with($trimmed, '# ')) {
                $body .= '<h1>'.$this->inline(substr($trimmed, 2)).'</h1>';
            } elseif (str_starts_with($trimmed, '> ')) {
                $body .= '<p class="note">'.$this->inline(substr($trimmed, 2)).'</p>';
            } elseif (str_starts_with($trimmed, '- ')) {
                $body .= '<p class="item">• '.$this->inline(substr($trimmed, 2)).'</p>';
            } else {
                $body .= '<p>'.$this->inline($trimmed).'</p>';
            }
        }

        $body .= $this->tableHtml($rows);

        $style = 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10pt;color:#111;margin:24px}'
            .'h1{font-size:17pt;margin:0 0 4px}h2{font-size:12pt;margin:18px 0 6px;border-bottom:1px solid #ccc;padding-bottom:3px}'
            .'p{margin:3px 0}p.note{background:#fff8e1;border-left:3px solid #e0a800;padding:6px 8px;margin:6px 0}'
            .'p.item{margin:2px 0 2px 10px}'
            .'table{border-collapse:collapse;width:100%;margin:6px 0 12px;font-size:8.5pt}'
            .'th,td{border:1px solid #bbb;padding:4px 6px;text-align:left;vertical-align:top}'
            .'th{background:#f0f0f0}code{background:#f4f4f4;padding:1px 4px;border-radius:3px}';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<title>Demonstration login credentials</title><style>'.$style.'</style></head><body>'
            .$body.'</body></html>';
    }

    /**
     * One pipe table, or nothing when there are no rows to render.
     *
     * A method rather than the by-reference closure this started as: the closure
     * mutated two captured variables from three call sites, which is exactly the
     * shape that makes a reader — and an analyser — unable to say what is in them.
     *
     * @param  list<list<string>>  $rows
     */
    private function tableHtml(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $html = '<table>';

        foreach ($rows as $index => $cells) {
            $tag = $index === 0 ? 'th' : 'td';
            $html .= '<tr>';

            foreach ($cells as $cell) {
                $html .= "<{$tag}>".$this->inline($cell)."</{$tag}>";
            }

            $html .= '</tr>';
        }

        return $html.'</table>';
    }

    private function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $escaped = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);

        return (string) preg_replace('/`(.+?)`/', '<code>$1</code>', $escaped);
    }
}
