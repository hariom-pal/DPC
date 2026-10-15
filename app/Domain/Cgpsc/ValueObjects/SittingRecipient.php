<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\ValueObjects;

/**
 * One person to be told that a DPC sitting has been fixed or moved.
 *
 * A value object rather than an array or a `stdClass`, for the reason this project
 * uses them everywhere else: a caller cannot read a key nobody set, and
 * `$recipient->mobile` is a `?string` rather than whatever the row happened to
 * hold.
 *
 * **It deliberately flattens two different sources into one shape.** A committee
 * member is a `dpc_committee_members` row whose contact details may belong to the
 * membership rather than to any account; an officer under consideration is a
 * seniority-list entry matched to an account by employee number. What the notifier
 * needs from both is identical — a name, whatever channels exist, and an account id
 * if there is one — so it is handed this and never learns which it is looking at.
 *
 * `userId` is nullable because an external committee member may have no account,
 * and `mobile` and `email` are nullable because a Seniority List carries neither.
 * Those three nulls are the whole reason the notifier reports how many people it
 * could not reach.
 */
final readonly class SittingRecipient
{
    public function __construct(
        public string $name,
        public ?int $userId = null,
        public ?string $mobile = null,
        public ?string $email = null,
    ) {}

    /**
     * Whether any external channel exists for this person.
     *
     * The negative of this is what the notifier counts as "unreachable" — today,
     * every candidate.
     */
    public function isReachable(): bool
    {
        return $this->mobile !== null || $this->email !== null;
    }
}
