<?php

declare(strict_types=1);

namespace App\Domain\SelfInspection\ValueObjects;

/**
 * A structured refusal: the case is recognised, and deciding it would mean
 * inventing a business rule (AD-2), so it is declined and named.
 *
 * **Deliberately a copy of `App\Domain\Circulation\ValueObjects\PendingBusinessDecision`,
 * not a reuse of it.** M9 is built and awaiting closure, and moving a class out
 * of it into `Shared` is a refactor of a completed milestone — which needs
 * Architect approval, and would be the wrong thing to do quietly in the middle
 * of building M10. **The right home for this is `app/Domain/Shared/`, and it
 * should be extracted the next time a freeze window opens**; two copies of one
 * shape is exactly the drift TD-20 describes, and it is recorded rather than
 * left to be discovered.
 *
 * The instance here is **BD-19**: how an individual officer authenticates, when
 * no officer snapshot carries a contact channel to send an OTP to.
 */
final readonly class PendingBusinessDecision
{
    /**
     * @param  string  $code  the register identifier, e.g. `BD-19`
     * @param  string  $title  one line, in business language
     * @param  string  $question  what CGPSC is being asked
     * @param  list<string>  $notDone  what the system deliberately did *not* do
     * @param  string|null  $route  where the register entry lives
     */
    public function __construct(
        public string $code,
        public string $title,
        public string $question,
        public array $notDone = [],
        public ?string $route = 'docs/PENDING_BUSINESS_DECISIONS.md',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => 'pending_business_decision',
            'code' => $this->code,
            'title' => $this->title,
            'question' => $this->question,
            'not_done' => $this->notDone,
            'route' => $this->route,
        ];
    }

    public function summary(): string
    {
        return "{$this->code} — {$this->title}";
    }
}
