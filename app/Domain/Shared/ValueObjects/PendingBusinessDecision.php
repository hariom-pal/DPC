<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Circulation\Exceptions\PendingBusinessDecisionException;

/**
 * A structured refusal: the system has recognised the case and is declining to
 * decide it, because deciding it would mean inventing a business rule (AD-2).
 *
 * **This is not an error.** Nothing is wrong with the request; the answer is
 * genuinely unknown, and the project's standing behaviour when a business rule
 * is unresolved is to **apply the printed rule, flag every case the unruled
 * rule could have changed, and never guess.** This object is that flag, made
 * structured so a caller — a screen, an API client, a report of everything
 * blocked on CGPSC — can act on it without parsing prose.
 *
 * **Moved to `Shared` on 2026-08-19** under the final-phase Freeze Exception. It
 * was M9's, and M10 held a deliberate copy of it because relocating a class out of
 * a built milestone was a refactor awaiting approval — recorded at the time rather
 * than left to be discovered. There is now one of it.
 *
 * Two instances today, and their shapes are why this belongs in `Shared` rather
 * than in either milestone:
 *
 * - **BD-15** — S45 names CR-unreported as a circulation ground, but resolving it
 *   always re-decides the officer's mark and can reorder the promotion list for
 *   officers who were never notified.
 * - **BD-19** — an individual officer has no way to receive an OTP, because no
 *   officer-snapshot table carries a contact channel.
 *
 * Neither is an engineering choice, and both had to be *said* rather than guessed.
 *
 * @see PendingBusinessDecisionException
 */
final readonly class PendingBusinessDecision
{
    /**
     * @param  string  $code  the register identifier, e.g. `BD-15`
     * @param  string  $title  one line, in business language
     * @param  string  $question  what CGPSC is being asked
     * @param  list<array<string, mixed>>  $affected  the specific cases this blocks
     * @param  list<string>  $notDone  what the system deliberately did *not* do
     * @param  string|null  $route  where the register entry lives
     */
    public function __construct(
        public string $code,
        public string $title,
        public string $question,
        public array $affected = [],
        public array $notDone = [],
        public ?string $route = null,
    ) {}

    /**
     * The shape a screen, an API client or a timeline entry reads.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => 'pending_business_decision',
            'code' => $this->code,
            'title' => $this->title,
            'question' => $this->question,
            'affected' => $this->affected,
            'not_done' => $this->notDone,
            'route' => $this->route,
        ];
    }

    /**
     * One line for a flash message or a log.
     */
    public function summary(): string
    {
        return "{$this->code} — {$this->title}";
    }
}
