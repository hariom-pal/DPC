<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * The seat a person holds on a DPC committee (PPT S15 "CAPACITY").
 *
 * **Two values, not three, and the missing one is the point.** There are two
 * chairmen in this process and only one of them is a *seat*:
 *
 *  - The **CGPSC Chairman** holds a seat on every committee. It is theirs by
 *    office, the system adds it, and the Authorized Officer can neither create nor
 *    remove it (change register of 2026-08-26, item 9). That is
 *    {@see self::CgpscChairman}.
 *  - The **DPC Chairman** is a *designation made on top of a seat*: the CGPSC
 *    Chairman nominates one committee member to conduct this particular DPC, or
 *    takes it themselves. That is `dpc_committee_members.is_dpc_chairman`, a flag
 *    beside the capacity rather than a third value in it.
 *
 * Modelling the DPC Chairman as a capacity was the obvious first move and is
 * wrong: when the CGPSC Chairman conducts a DPC themselves — which the register
 * says they may — one person would need two capacities on one seat, and the row
 * would have to say either `cgpsc_chairman` or `dpc_chairman` while both are true.
 * A flag says both without contradiction.
 */
enum CommitteeCapacity: string
{
    /**
     * The serving CGPSC Chairman, added by the system on every committee.
     *
     * Exactly one row per proposal carries this, and no request may create or
     * remove it — see `SaveDpcCommitteeRequest` and `DpcCommitteeService`.
     */
    case CgpscChairman = 'cgpsc_chairman';

    /**
     * An ordinary committee member, searched or created when the DPC is composed.
     */
    case Member = 'member';

    /**
     * The catalogue key for this capacity's label.
     */
    public function labelKey(): string
    {
        return 'dpc.committee.capacities.'.$this->value;
    }

    public function label(): string
    {
        return (string) __($this->labelKey());
    }

    /**
     * Whether this seat is the one the system owns.
     *
     * Asked wherever a screen or a request decides whether the Authorized Officer
     * may touch a row, so the rule lives here rather than as a repeated
     * `=== CgpscChairman` that one caller eventually gets backwards.
     */
    public function isSystemOwned(): bool
    {
        return $this === self::CgpscChairman;
    }

    /**
     * The capacities an Authorized Officer may select on the committee screen.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [self::Member];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $capacity): string => $capacity->value, self::cases());
    }
}
