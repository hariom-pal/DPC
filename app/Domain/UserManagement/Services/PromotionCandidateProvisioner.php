<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcSeniorityEntry;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Give every officer on a Seniority List a login (change item 17).
 *
 * The register: *"Every officer appearing in the Seniority List must have login
 * access. Role: Promotion Candidate… If login already exists, Reuse it. Otherwise
 * automatically create the account."*
 *
 * **Reuse is keyed on the employee number**, which is the only identifier a
 * Seniority List carries that denotes a *person*. Matching on name was considered
 * and rejected: two officers share a name, and a promotion account matched by name
 * is a promotion account handed to the wrong person. An entry with no employee
 * number is therefore skipped and reported rather than guessed at — see
 * {@see provision()}'s return value, which the screen shows the officer so the
 * gap is visible rather than silent.
 *
 * **Reuse means reuse, and never means moving anybody.** An officer who already
 * holds an account keeps it — "never create duplicate users" is the register's
 * instruction in item 2 and item 17 alike — but the account's *posting* is left
 * exactly as it is. That is the correction of 2026-08-25: an employee number is
 * issued by a department, so the same number in two departments may be one
 * transferred officer or two different officers, and the portal cannot tell. It
 * used to assume the first and re-post the account, which transferred a serving
 * officer out of their own department whenever another department happened to
 * reuse their number. Now it assumes neither and reports the clash — the ruling
 * is BD-27, and {@see ensureCandidateRole()} carries the argument.
 *
 * **The mobile number is what makes the account reachable** (business instruction,
 * 2026-09-07: *"we should have a mobile number for the candidate — it will help
 * them log in"*).
 *
 * Until then a Seniority List carried neither an e-mail address nor a mobile, so an
 * account was created that had **no login identifier at all** — an employee number
 * is not one — and no channel to send a password on. The department was shown the
 * temporary password once and had to hand it over in person, to an officer who then
 * had nothing to type it against. The feature looked delivered and was not.
 *
 * Where the list now carries a mobile it becomes both halves at once: what the
 * officer signs in with, and where the credentials go. `CredentialsIssued` is
 * dispatched for those, and not for the rest — an officer with no number is
 * unreachable, and pretending otherwise would be worse than saying so.
 *
 * The password is returned either way, so the screen can display it once for the
 * accounts nobody can be told about.
 */
class PromotionCandidateProvisioner
{
    public function __construct(
        private readonly ActivityTimelineService $timeline,
        private readonly DepartmentAssignmentService $assignments,
    ) {}

    /**
     * The ten digits that identify an Indian mobile number, or null.
     *
     * Mirrors `CommitteeMemberDirectory::normaliseMobile()`, so the same number
     * typed `+91 94250-12345` on one screen and `9425012345` on another is one
     * number everywhere. Anything that does not end in ten digits is not a mobile
     * number and returns null rather than a truncation — an account reachable on a
     * fragment of a number is worse than one that is not reachable at all.
     */
    private function normaliseMobile(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) < 10 ? null : substr($digits, -10);
    }

    /**
     * Provision or reuse an account for every officer on this proposal's Seniority
     * List.
     *
     * Idempotent: called again after a chart is re-uploaded, it creates nothing for
     * officers who already have an account and adds accounts only for the ones the
     * new chart introduced.
     *
     * @return array{created: array<string, string>, reused: list<string>, skipped: list<string>, conflicts: list<string>}
     *                                                                                                                     created: employee number => the temporary password, to show once;
     *                                                                                                                     reused: employee numbers already holding an account;
     *                                                                                                                     skipped: names of entries carrying no employee number;
     *                                                                                                                     conflicts: employee numbers already held by an officer posted elsewhere (BD-27)
     */
    public function provision(DpcProposal $proposal): array
    {
        $created = [];
        $reused = [];
        $skipped = [];
        $conflicts = [];

        $department = $proposal->department()->firstOrFail();

        foreach ($proposal->seniorityEntries()->get() as $entry) {
            $employeeNumber = trim((string) $entry->employee_number);

            if ($employeeNumber === '') {
                $skipped[] = (string) $entry->name;

                continue;
            }

            $existing = User::query()->where('employee_number', $employeeNumber)->first();

            if ($existing !== null) {
                if ($this->ensureCandidateRole($existing, $proposal)) {
                    $conflicts[] = $employeeNumber;
                } else {
                    $reused[] = $employeeNumber;
                }

                continue;
            }

            $password = Str::password(10, symbols: false);

            DB::transaction(function () use ($entry, $employeeNumber, $password, $department, $proposal): void {
                $user = new User([
                    'name' => (string) $entry->name,
                    'is_active' => true,
                    'must_change_password' => true,

                    /*
                     | Both channels stamped verified.
                     |
                     | Not a shortcut: OTP verification exists to prove the holder
                     | controls the email and mobile on the account, and this account
                     | has neither. Leaving them unverified would put the officer in
                     | a login loop asking for a code that can never be sent.
                     */
                    'email_verified_at' => now(),
                    'mobile_verified_at' => now(),
                ]);

                $user->employee_number = $employeeNumber;
                $user->password = Hash::make($password);

                /*
                 | The number the Seniority List carries, if it carries one.
                 |
                 | Written through the property rather than mass-assigned for the same
                 | reason the employee number is: both identify the account, and
                 | neither is the department's to set through a payload.
                 */
                $mobile = $this->normaliseMobile((string) $entry->mobile);

                if ($mobile !== null) {
                    $user->mobile = $mobile;
                }

                $user->save();

                // The candidature is departmental — the officer belongs to the
                // department whose Seniority List names them — so the role is
                // granted the same way every other departmental role is: by a dated
                // assignment (change item 2).
                $this->assignments->assign(
                    $user,
                    $department,
                    Role::PromotionCandidate,
                    remarks: sprintf('Named on the Seniority List of %s.', $proposal->dpc_code ?? 'a DPC proposal'),
                );
            });

            $created[$employeeNumber] = $password;
        }

        if ($created !== [] || $reused !== [] || $conflicts !== []) {
            $this->timeline->record(
                'dpc_proposal.candidate_accounts_provisioned',
                $proposal,
                sprintf(
                    '%d promotion-candidate account(s) created, %d reused%s.',
                    count($created),
                    count($reused),
                    $conflicts === [] ? '' : sprintf(', %d needing the Administrator', count($conflicts)),
                ),
                [
                    // The employee numbers, never the passwords. A timeline entry is
                    // permanent and readable by every administrator.
                    'created' => array_keys($created),
                    'reused' => $reused,
                    'skipped' => $skipped,
                    'conflicts' => $conflicts,
                ],
            );
        }

        return ['created' => $created, 'reused' => $reused, 'skipped' => $skipped, 'conflicts' => $conflicts];
    }

    /**
     * Make sure a reused account can reach the DPC it has been named on — without
     * ever moving the officer.
     *
     * **An existing account is not re-posted if it already holds a role in this
     * department.** An Authorized Officer who appears on their own department's
     * Seniority List — which happens, because an AO is an officer too — must not be
     * demoted to a candidate by the act of being considered for promotion. Their
     * candidature is established by their employee number appearing on the list,
     * not by their role.
     *
     * **And an account posted to a *different* department is not moved either, which
     * is the correction of 2026-08-25.** This method used to call `assign()` in that
     * case, and `assign()` retires the incumbent posting: so when a second department
     * uploaded a list carrying an employee number the first department had already
     * used, the officer holding that number was **transferred out of the department
     * they actually work in** — losing their departmental role — by nothing more than
     * another department's data entry. Two readings of that situation exist and the
     * portal cannot tell them apart from a number:
     *
     *  - the same officer, transferred, whose number followed them; or
     *  - two different officers, each numbered by their own department.
     *
     * Under the first reading re-posting is right. Under the second it is a
     * confidentiality breach and the destruction of a live posting. So this does
     * neither: it leaves the posting exactly as it is and returns the mismatch for
     * {@see provision()} to report, which is the same thing the portal already does
     * with an entry carrying no employee number. The ruling is BD-27.
     *
     * @return bool whether the posting disagreed with the proposal's department
     */
    private function ensureCandidateRole(User $user, DpcProposal $proposal): bool
    {
        $assignment = $user->activeAssignment;

        if ($assignment === null) {
            // Between postings, so nothing is being taken away: post them as a
            // candidate of the department that named them.
            $this->assignments->assign(
                $user,
                $proposal->department()->firstOrFail(),
                Role::PromotionCandidate,
                remarks: sprintf('Named on the Seniority List of %s.', $proposal->dpc_code ?? 'a DPC proposal'),
            );

            return false;
        }

        return $assignment->department_id !== $proposal->department_id;
    }

    /**
     * Issue a fresh temporary password for a candidate named on this proposal.
     *
     * **Why the department does this and not the officer themselves.** A candidate
     * account has no mobile number and no e-mail — a Seniority List carries
     * neither — so there is no channel to send a one-time code to, and a
     * self-service reset has nothing to authenticate against except the three facts
     * printed on the list itself. Those facts are already accepted for *reading*
     * one's own data (BD-19), and reading is not the same as taking over an
     * account. So the reset stays with the department, which is where credential
     * handover already sits.
     *
     * **The guard is the Seniority List, not the role.** The employee number must
     * appear on *this* proposal's list, so an Authorized Officer can reissue for the
     * officers their own department has named and for nobody else. Checking the role
     * instead would let any department reset any candidate in the portal.
     *
     * Returns the new password so the screen can show it once; it is stored only as
     * a hash.
     */
    public function reissuePassword(DpcProposal $proposal, string $employeeNumber, User $actor): string
    {
        $employeeNumber = trim($employeeNumber);

        $onThisList = $proposal->seniorityEntries()
            ->where('employee_number', $employeeNumber)
            ->exists();

        if ($employeeNumber === '' || ! $onThisList) {
            throw new RuntimeException(
                'That employee number is not on this proposal\'s Seniority List. A password is reissued only for '
                .'an officer the proposal itself names.'
            );
        }

        $candidate = User::query()->where('employee_number', $employeeNumber)->first();

        if ($candidate === null) {
            throw new RuntimeException(
                'No account holds that employee number yet. Saving the Seniority List is what creates one.'
            );
        }

        $password = Str::password(10, symbols: false);

        DB::transaction(function () use ($candidate, $password): void {
            $candidate->forceFill([
                'password' => Hash::make($password),
                // Reissued means temporary: the officer sets their own on first use.
                'must_change_password' => true,
            ])->save();
        });

        // The employee number, never the password — a timeline entry is permanent
        // and readable by every administrator.
        $this->timeline->record(
            'dpc_proposal.candidate_password_reissued',
            $proposal,
            sprintf('Temporary password reissued for employee number %s by %s.', $employeeNumber, $actor->name),
            ['employee_number' => $employeeNumber],
        );

        return $password;
    }

    /**
     * The Seniority List entries an account is named on, across every DPC.
     *
     * **Derived from the employee number rather than stored as a link**, and that
     * is the design: a Seniority List is re-uploaded during drafting, and a stored
     * link would have to be reconciled on every upload — the kind of bookkeeping
     * that eventually leaves a candidate attached to a chart they are no longer on.
     * Deriving it means the answer is always exactly what the current charts say.
     *
     * **And it is scoped to the departments this account has actually been posted
     * to.** An employee number identifies a person *within a department* — it is the
     * department that issues it — so two departments can each hold an officer
     * numbered 1024 without either being wrong. Matching on the number alone
     * therefore let one account see the other officer's entry, their name and their
     * DPC: the disclosure item 17 exists to prevent. Scoping by posting closes it
     * whichever way {@see BD-27} is eventually ruled, because an officer who was
     * never posted to a department cannot be the officer that department listed.
     *
     * Past postings count as well as the present one. A DPC is a historical record,
     * and an officer who has since transferred is still entitled to the exercise
     * they were considered in.
     *
     * @return Builder<DpcSeniorityEntry>
     */
    public static function entriesFor(User $user): Builder
    {
        $employeeNumber = (string) $user->employee_number;

        if ($employeeNumber === '') {
            // No employee number means no candidature. A query that matches
            // nothing rather than a null, so every caller can treat this as a
            // query and none has to remember the special case.
            return DpcSeniorityEntry::query()->whereRaw('1 = 0');
        }

        $departmentIds = $user->assignments()->pluck('department_id')->unique()->all();

        return DpcSeniorityEntry::query()
            ->where('employee_number', $employeeNumber)
            ->whereHas('proposal', fn ($query) => $query->whereIn('department_id', $departmentIds));
    }
}
