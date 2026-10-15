<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Enums\Role;

/**
 * A committee for tests that are about something else.
 *
 * Since the business clarification of 2026-09-05 the CGPSC Chairman names who
 * presides **as part of allotting**, and the field is required — so a proposal with
 * no committee can no longer be allotted at all. That is correct in production, where
 * the committee is a mandatory section long before a proposal is forwarded, but it
 * means every test about allotment, scheduling or a proceeding now needs a committee
 * it does not otherwise care about.
 *
 * Written directly rather than through `DpcCommitteeService::compose()` on purpose.
 * That service seats the serving CGPSC Chairman from the `cgpsc_chairmen` master and
 * refuses without one, which would make every test that merely wants to allot
 * something also responsible for a master it never mentions. These rows are fixtures,
 * not a committee the tests are asserting anything about.
 */
trait SeatsDpcCommittees
{
    /**
     * Seat a minimal committee and return the row that will preside.
     *
     * The first seat carries `cgpsc_chairman` capacity, so a test can exercise
     * **Option A** of the clarification — the CGPSC Chairman presiding personally —
     * by taking the return value, and **Option B** by taking one of the members.
     *
     * @return list<DpcCommitteeMember>
     */
    protected function seatCommittee(DpcProposal $proposal, int $members = 3): array
    {
        /*
         | Idempotent: a proposal that already has a serving committee gets it back
         | rather than a second one.
         |
         | Several fixtures now seat a committee — a DPC is allotted to one of its
         | own members since 2026-09-06 — and a shared concern that also seats one
         | would otherwise stack them. The database says so first: one person holds
         | one serving seat per proposal, and a second call attaching the same
         | Commission accounts to fresh rows breaches that index.
         */
        $existing = DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->serving()
            ->orderBy('sort_order')
            ->get();

        if ($existing->isNotEmpty()) {
            return $existing->all();
        }

        $seats = [DpcCommitteeMember::query()->create([
            'dpc_proposal_id' => $proposal->id,
            'name' => 'Hon. Chairman',
            'designation' => 'Chairman, CGPSC',
            'capacity' => CommitteeCapacity::CgpscChairman->value,
            'sort_order' => 0,
            'active_flag' => 1,
        ])];

        /*
         | The members carry the required combination between them (business
         | clarification of 2026-09-06: one member from each of SC, ST and OBC, plus
         | Female, Bhar Sadhak Sachiv and Vibhag Sachiv).
         |
         | **Not decoration.** Since the same clarification, a committee is only
         | *complete* when it satisfies its composition rules, and an incomplete
         | committee blocks submission — so a fixture seating three bare members
         | would stop every test that submits a proposal, none of which is about the
         | committee at all.
         |
         | Six requirements over three members: a person holds at most one social
         | category, so the three categories take the three seats, and the last also
         | carries the three freely-combinable attributes. That is the register's own
         | worked example of coverage by the committee rather than by the member.
         */
        $categories = ['is_sc', 'is_st', 'is_obc'];

        for ($i = 1; $i <= $members; $i++) {
            $attributes = [];

            if (isset($categories[$i - 1])) {
                $attributes[$categories[$i - 1]] = true;
            }

            if ($i === $members) {
                $attributes += ['is_female' => true, 'is_bhar_sadhak_sachiv' => true, 'is_vibhag_sachiv' => true];
            }

            $seats[] = DpcCommitteeMember::query()->create($attributes + [
                'dpc_proposal_id' => $proposal->id,
                'name' => "Committee Member {$i}",
                'capacity' => CommitteeCapacity::Member->value,
                'sort_order' => $i,
                'active_flag' => 1,
            ]);
        }

        /*
         | Attach the Commission's own accounts to the seats.
         |
         | A DPC is allotted to a serving member of its **own committee** since
         | 2026-09-06, and only to one holding a portal account — the allottee signs
         | in, accepts and schedules. Every CGPSC test allots to one of the accounts
         | its setUp created, so without this each of them would have to seat that
         | account by hand before it could allot at all.
         |
         | **The seat names are left alone.** Tests assert on "Committee Member 1",
         | and the id that matters here is `user_id`, which is what the allotment
         | rule matches on. A real committee names the person; a fixture only has to
         | be allottable.
         |
         | Silent when the Commission has no accounts: a test that never allots does
         | not need them, and inventing users would give every such test two more
         | rows in `users` to account for.
         */
        $commission = User::query()
            ->role([Role::Member->value, Role::Chairman->value])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($seats as $index => $seat) {
            $account = $commission[$index] ?? null;

            if ($account === null) {
                break;
            }

            $seat->forceFill(['user_id' => $account->id])->save();
        }

        return $seats;
    }

    /**
     * Seat an **account** on this committee, and return the row.
     *
     * A DPC is allotted to a serving member of its own committee since 2026-09-06,
     * and only to one holding a portal account — the allottee has to sign in,
     * accept and schedule. `seatCommittee()` creates members with no account,
     * because most of what it serves is about composition rather than about who
     * acts; this is for the tests where somebody has to.
     *
     * Idempotent, and it *reuses* a bare seat rather than adding a fourth member:
     * the size bounds are three to six besides the Chairman, and a fixture that grew
     * the committee every time it was asked for an allottee would eventually breach
     * them for reasons no test could see.
     */
    protected function seatAccountOnCommittee(DpcProposal $proposal, User $user): DpcCommitteeMember
    {
        $existing = DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->serving()
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $spare = DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->serving()
            ->where('capacity', CommitteeCapacity::Member->value)
            ->whereNull('user_id')
            ->orderByDesc('sort_order')
            ->first();

        if ($spare !== null) {
            $spare->forceFill(['user_id' => $user->id, 'name' => $user->name])->save();

            return $spare;
        }

        return DpcCommitteeMember::query()->create([
            'dpc_proposal_id' => $proposal->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'capacity' => CommitteeCapacity::Member->value,
            'sort_order' => (int) DpcCommitteeMember::query()->where('dpc_proposal_id', $proposal->id)->max('sort_order') + 1,
            'active_flag' => 1,
        ]);
    }

    /**
     * Mark the seat this account holds as the one that presides.
     *
     * Seats the account first if it is not already on the committee. Tests used to
     * `create()` a fresh row here, which stopped working once the fixture began
     * attaching Commission accounts to the seats it makes: the composite unique
     * index on (proposal, user, active_flag) refuses a second serving row for one
     * person, which is exactly the rule it exists to enforce.
     */
    protected function designateAsDpcChairman(DpcProposal $proposal, User $user): DpcCommitteeMember
    {
        $seat = $this->seatAccountOnCommittee($proposal, $user);

        $seat->forceFill(['is_dpc_chairman' => 1])->save();

        return $seat;
    }

    /**
     * The id the allotment form expects for "who will preside".
     *
     * Defaults to the CGPSC Chairman's own seat, which is the state a Commission
     * that has not delegated is in, and seats the committee first if the proposal
     * has none — so a test that does not care can simply not think about it.
     */
    protected function presidingSeatId(DpcProposal $proposal): int
    {
        $seat = DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->serving()
            ->orderBy('sort_order')
            ->first();

        return ($seat ?? $this->seatCommittee($proposal)[0])->id;
    }
}
