<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * Where a DPC stands and whose move it is (MoM of 16 November 2026, points 5, 6 and
 * 8): the Authorized Officer preparing it, the Administrative Head verifying and
 * approving it, the Secretary, CGPSC, the Chairman, CGPSC allotting it, and the DPC
 * Committee evaluating it. Each stage has its own timeline, set by the CGPSC
 * Administrator.
 */
enum DpcStage: string
{
    case Ao = 'ao';
    case Ah = 'ah';
    case Secretary = 'secretary';
    case Chairman = 'chairman';
    case Committee = 'committee';

    public function label(): string
    {
        return (string) __('dpc.stage.'.$this->value.'.label');
    }

    /** What is to be done at this stage. */
    public function action(): string
    {
        return (string) __('dpc.stage.'.$this->value.'.action');
    }

    /** The stage the DPC goes to next, or null after the committee. */
    public function next(): ?self
    {
        return match ($this) {
            self::Ao => self::Ah,
            self::Ah => self::Secretary,
            self::Secretary => self::Chairman,
            self::Chairman => self::Committee,
            self::Committee => null,
        };
    }
}
