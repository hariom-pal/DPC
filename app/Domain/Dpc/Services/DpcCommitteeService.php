<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Services\CgpscChairmanService;
use App\Domain\Committee\Services\CommitteeMemberDirectory;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\Designation;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Composing, chairing and amending a DPC committee (change register of
 * 2026-08-26, items 6 to 9).
 *
 * Split out of `DpcProposalService`, which had grown a `saveCommittee()` that
 * spoke to a master this register withdrew. The committee is now four operations
 * with different actors and different rules, and putting them beside the
 * proposal's other sections obscured that:
 *
 * | Operation | Actor | When |
 * |---|---|---|
 * | {@see compose()} | Authorized Officer | while the proposal is editable |
 * | {@see designateChairman()} | CGPSC Chairman | once the committee exists |
 * | {@see replace()} | CGPSC Administrator | **any stage** |
 * | {@see seatCgpscChairman()} | the system | every compose |
 *
 * ### Nothing here grants a role
 *
 * The register is explicit: *"Do NOT assign any permanent Commission Member role to
 * a user. Committee Membership is valid only for that specific DPC."* There is no
 * `assignRole()` on any path in this class, and `CommitteeMembershipTest` pins it
 * by composing a committee and asserting the seated user's roles are unchanged.
 * A member created here gets an account precisely so they are not duplicated next
 * time — not so they can sign in as anything.
 */
class DpcCommitteeService
{
    public function __construct(
        private readonly ActivityTimelineService $timeline,
        private readonly CgpscChairmanService $chairmen,
        private readonly CommitteeMemberDirectory $directory,
    ) {}

    /**
     * Record the committee an Authorized Officer has composed.
     *
     * **The CGPSC Chairman's seat is placed by this method, not taken from the
     * payload** (item 9). Whatever the officer submitted, the serving Chairman is
     * seated and any `cgpsc_chairman` row in the payload is discarded — the officer
     * *"should not manually add or remove the Chairman"*, and the way to guarantee
     * that is to not read it from them.
     *
     * **Serving members are replaced wholesale; replaced ones are never touched.**
     * Re-composing deletes the rows that are currently serving and writes the new
     * list. A member who was formally *replaced* (item 9e) is a historical record
     * with an actor and a reason attached, and re-saving the section must not erase
     * it — `serving()` is what keeps the delete off them.
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    public function compose(DpcProposal $proposal, array $members, User $actor): DpcProposal
    {
        if (! $proposal->status->isEditable()) {
            throw new WorkflowException(
                'The committee can no longer be edited — the proposal is not in Draft or Returned status.'
            );
        }

        DB::transaction(function () use ($proposal, $members, $actor): void {
            $designated = $this->designatedUserId($proposal);

            $proposal->committeeMembers()->serving()->delete();

            $this->seatCgpscChairman($proposal, $designated);

            $order = 1;

            foreach ($members as $row) {
                // The Chairman's seat is the system's. A payload row claiming it is
                // ignored rather than refused, because the request has already
                // rejected the ones that were tampered with; this is the belt to
                // that braces.
                if (($row['capacity'] ?? null) === CommitteeCapacity::CgpscChairman->value) {
                    continue;
                }

                $user = $this->resolveMember($row, $actor);

                $proposal->committeeMembers()->create($this->rowFor($row, $user, $order, $designated));

                $order++;
            }
        });

        $this->timeline->record(
            'dpc_proposal.committee_updated',
            $proposal,
            sprintf('DPC committee recorded (%d member(s) besides the Chairman)', count($members)),
            ['member_count' => count($members)],
            $actor,
        );

        return $proposal->fresh(['committeeMembers.user']) ?? $proposal;
    }

    /**
     * Seat the serving CGPSC Chairman on this committee (item 9).
     *
     * **Refuses rather than improvises when no Chairman is recorded.** A fresh
     * installation has none until the Administrator appoints one, and item 9 makes
     * the seat mandatory — so the honest outcome is to stop and say which screen
     * fixes it. Seating nobody would produce a committee that silently fails its
     * own composition rule; seating "whoever holds the chairman role" would seat a
     * person the Commission never named, on a record that gets signed.
     */
    private function seatCgpscChairman(DpcProposal $proposal, ?int $designatedUserId): DpcCommitteeMember
    {
        /*
         | The Chairman who held office on the proposal's own date, so a committee
         | composed against a back-dated proposal seats the right person. Falls back
         | to the serving Chairman when the proposal predates any recorded tenure —
         | which is every proposal raised before this register.
         */
        $chairman = $this->chairmen->servingOn($proposal->proposal_date ?? Carbon::today())
            ?? $this->chairmen->serving();

        if ($chairman === null) {
            throw new RuntimeException(
                'No CGPSC Chairman is recorded, and every DPC committee must include one. '
                .'Record the serving Chairman under Administration → CGPSC Chairman, then compose the committee.'
            );
        }

        return $proposal->committeeMembers()->create([
            'user_id' => $chairman->user_id,
            'name' => $chairman->name,
            'designation' => $chairman->designation,
            'mobile' => $chairman->mobile,
            'email' => $chairman->email,
            'capacity' => CommitteeCapacity::CgpscChairman->value,
            'sort_order' => 0,
            'active_flag' => 1,

            // The Chairman keeps the conduct of the DPC if they had taken it.
            'is_dpc_chairman' => $chairman->user_id !== null && $chairman->user_id === $designatedUserId ? 1 : null,
        ]);
    }

    /**
     * The account behind a posted member row, creating one where the person is new.
     *
     * Item 6's rule, applied server-side: an e-mail or mobile that already belongs
     * to somebody resolves to **that** account rather than making a second one. The
     * browser asks the same question as the officer types, but the browser's answer
     * is a convenience and this one is the guarantee — a form submitted from a
     * stale page would otherwise duplicate the very account the warning prevented.
     *
     * The created account holds **no role** and no password. It exists so the
     * person is findable next time, which is the only thing item 6 asks of it.
     *
     * @param  array<string, mixed>  $row
     */
    private function resolveMember(array $row, User $actor): ?User
    {
        /*
         | An account the officer picked from the search takes precedence over
         | everything else, and looking at it *first* is the whole point.
         |
         | Option 1 of item 6 posts a `user_id` and nothing else — the name and the
         | contact details are the account's, not the form's. Reading the e-mail and
         | mobile first would find neither, conclude the person was new, and either
         | create a duplicate or drop the selection on the floor.
         */
        $selected = isset($row['user_id']) && $row['user_id'] !== ''
            ? User::query()->find((int) $row['user_id'])
            : null;

        if ($selected !== null) {
            return $selected;
        }

        $email = $this->text($row['email'] ?? null);
        $mobile = $this->text($row['mobile'] ?? null);

        $existing = $this->directory->existing($email, $mobile);

        if ($existing !== null) {
            return $existing;
        }

        if ($email === null && $mobile === null) {
            // A member with neither channel cannot be looked up and cannot be
            // notified. Recorded on the row alone, as external members always were.
            return null;
        }

        $user = new User([
            'name' => $this->text($row['name'] ?? null) ?? 'Committee member',
            'email' => $email,
            'mobile' => $mobile,
            'is_active' => true,

            /*
             | No password is set, so `Hash::check` fails against anything and the
             | account cannot be signed into until somebody deliberately issues
             | credentials. That is the intended state: the register asks for the
             | person to be *recorded*, not admitted.
             */
            'password' => Str::password(32),
            'must_change_password' => true,
        ]);

        $user->save();

        $this->timeline->record(
            'user.created_for_committee',
            $user,
            sprintf('%s recorded while composing a DPC committee', $user->name),
            ['user_id' => $user->id, 'granted_roles' => []],
            $actor,
        );

        return $user;
    }

    /**
     * The designation this member holds, resolved from the Designation master.
     *
     * The screen posts `designation_id` — a Post, or any other rank, is a master
     * entity and is never typed (DPC-MODULE-FIX-001, second pass). What is *stored*
     * is still the name, in the `designation` varchar the column has always been,
     * for the same reason the officer snapshots store a post name: a committee that
     * has sat is a record of who sat on it, and it must not change its own history
     * because somebody later renamed a master row.
     *
     * The free-text `designation` is read only when no id arrives — a committee
     * composed before this change, re-posted unaltered, still resolves. The id wins
     * where both are present, because the id is what the current screen sends.
     *
     * @param  array<string, mixed>  $row
     */
    private function designationName(array $row): ?string
    {
        $id = $row['designation_id'] ?? null;

        if (is_numeric($id)) {
            $name = Designation::query()->whereKey((int) $id)->value('name');

            if ($name !== null) {
                return (string) $name;
            }
        }

        return $this->text($row['designation'] ?? null);
    }

    /**
     * The row to write for one posted member.
     *
     * The identity fields are taken from the resolved account where there is one,
     * so a submitted id can never be paired with a spoofed display name; the
     * attributes are taken from the payload, because they are properties of *this
     * membership* and exist nowhere else.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowFor(array $row, ?User $user, int $order, ?int $designatedUserId): array
    {
        $attributes = [];

        foreach (DpcCommitteeMember::ATTRIBUTE_COLUMNS as $column) {
            $attributes[$column] = filter_var($row[$column] ?? false, FILTER_VALIDATE_BOOL);
        }

        return $attributes + [
            'user_id' => $user?->id,
            'name' => $user->name ?? (string) $this->text($row['name'] ?? null),
            'designation' => $this->designationName($row),
            'mobile' => $user->mobile ?? $this->text($row['mobile'] ?? null),
            'email' => $user->email ?? $this->text($row['email'] ?? null),
            'capacity' => CommitteeCapacity::Member->value,
            'sort_order' => $order,
            'active_flag' => 1,

            // A re-compose keeps the sitting DPC Chairman if that person is still
            // on the committee. Losing the designation because the section was
            // saved again would send the CGPSC Chairman back to redo it.
            'is_dpc_chairman' => $user !== null && $user->id === $designatedUserId ? 1 : null,
        ];
    }

    /**
     * Which account currently conducts this DPC, if one has been designated.
     */
    private function designatedUserId(DpcProposal $proposal): ?int
    {
        return $proposal->committeeMembers()
            ->serving()
            ->whereNotNull('is_dpc_chairman')
            ->value('user_id');
    }

    /**
     * The CGPSC Chairman designates who conducts this DPC (item 9, as clarified).
     *
     * > *During DPC, the CGPSC Chairman will create the DPC Chairman from the DPC
     * > members, or become the DPC Chairman themselves.*
     *
     * So the candidate set is *this committee* and nothing wider — a person who is
     * not on the committee cannot conduct its sitting. The CGPSC Chairman's own
     * seat is in that set, which is what lets them take it themselves without a
     * separate operation.
     *
     * Designation moves rather than accumulates: `unique(dpc_proposal_id,
     * is_dpc_chairman)` would refuse a second one, so the incumbent is cleared
     * first, in the same transaction.
     */
    public function designateChairman(DpcProposal $proposal, DpcCommitteeMember $member, User $actor): DpcCommitteeMember
    {
        if ($member->dpc_proposal_id !== $proposal->id) {
            throw new RuntimeException('That member is not on this DPC\'s committee.');
        }

        if (! $member->isServing()) {
            throw new RuntimeException('A replaced member cannot be made DPC Chairman.');
        }

        return DB::transaction(function () use ($proposal, $member, $actor): DpcCommitteeMember {
            $proposal->committeeMembers()
                ->whereNotNull('is_dpc_chairman')
                ->update(['is_dpc_chairman' => null]);

            $member->forceFill(['is_dpc_chairman' => 1])->save();

            $this->timeline->record(
                'dpc_proposal.dpc_chairman_designated',
                $proposal,
                sprintf('%s designated DPC Chairman', $member->name),
                ['member_id' => $member->id, 'user_id' => $member->user_id],
                $actor,
            );

            return $member->refresh();
        });
    }

    /**
     * Substitute a committee member, keeping the one who stood down (item 9e).
     *
     * **Permitted at any stage**, which is the instruction, and which is why this
     * does not consult `status->isEditable()` as {@see compose()} does. A member
     * transferred or taken ill between the committee being recorded and the sitting
     * being held is an ordinary event, and refusing it after submission would
     * leave the department with no way to record what actually happened.
     *
     * The outgoing row is **kept and marked**, never deleted or edited into the
     * incoming member. A proceeding, a document or a signature that already cites
     * the outgoing member stays truthful, and "who was on this committee, and when
     * did that change" remains answerable from the data.
     *
     * The successor inherits the outgoing member's attributes by default — a
     * replacement is normally like-for-like, and a committee that silently lost its
     * only ST member because the substitution form defaulted to blank would fail
     * its composition rules at the sitting. The caller may override them.
     *
     * @param  array<string, mixed>  $incoming
     */
    public function replace(
        DpcProposal $proposal,
        DpcCommitteeMember $outgoing,
        array $incoming,
        User $actor,
        string $reason,
    ): DpcCommitteeMember {
        if ($outgoing->dpc_proposal_id !== $proposal->id) {
            throw new RuntimeException('That member is not on this DPC\'s committee.');
        }

        if (! $outgoing->isServing()) {
            throw new RuntimeException('That member has already been replaced.');
        }

        return DB::transaction(function () use ($proposal, $outgoing, $incoming, $actor, $reason): DpcCommitteeMember {
            $user = $this->resolveMember($incoming, $actor);

            if ($user !== null && $user->id === $outgoing->user_id) {
                throw new RuntimeException('The replacement names the member being replaced.');
            }

            $inherited = [];

            foreach (DpcCommitteeMember::ATTRIBUTE_COLUMNS as $column) {
                $inherited[$column] = array_key_exists($column, $incoming)
                    ? filter_var($incoming[$column], FILTER_VALIDATE_BOOL)
                    : (bool) $outgoing->{$column};
            }

            $successor = $proposal->committeeMembers()->create($inherited + [
                'user_id' => $user?->id,
                'name' => $user->name ?? (string) $this->text($incoming['name'] ?? null),
                'designation' => $this->designationName($incoming) ?? $outgoing->designation,
                'mobile' => $user->mobile ?? $this->text($incoming['mobile'] ?? null),
                'email' => $user->email ?? $this->text($incoming['email'] ?? null),

                // The successor takes the seat, whichever seat it was — including
                // the CGPSC Chairman's, when the office itself has changed hands
                // mid-DPC.
                'capacity' => $outgoing->capacity->value,
                'sort_order' => $outgoing->sort_order,
                'active_flag' => 1,
                'is_dpc_chairman' => $outgoing->is_dpc_chairman,
                'replaces_id' => $outgoing->id,
            ]);

            $outgoing->forceFill([
                'active_flag' => null,
                'is_dpc_chairman' => null,
                'replaced_at' => now(),
                'replaced_by' => $actor->id,
                'replacement_reason' => $reason,
            ])->save();

            $this->timeline->record(
                'dpc_proposal.committee_member_replaced',
                $proposal,
                sprintf('%s replaced %s on the DPC committee', $successor->name, $outgoing->name),
                [
                    'outgoing_member_id' => $outgoing->id,
                    'incoming_member_id' => $successor->id,
                    'reason' => $reason,
                ],
                $actor,
            );

            return $successor;
        });
    }

    /**
     * Trim a posted string to null when it holds nothing.
     *
     * An empty text input arrives as `''`, and `''` stored in `email` would make
     * two members with no e-mail collide on any uniqueness check that treats it as
     * a value. Null is what "not given" means.
     */
    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
