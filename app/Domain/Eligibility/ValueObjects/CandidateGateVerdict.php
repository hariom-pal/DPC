<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\ValueObjects;

use App\Domain\Eligibility\Enums\RemarkCode;

/**
 * What the pre-score gates decided about one candidate (change register of
 * 2026-08-27, items 11 to 20).
 *
 * **`null` status means "no gate had anything to say"** — the candidate passed
 * every hard check and the *score* decides, which is `EligibilityService`'s job.
 * That is deliberately different from `Eligible`: this object never awards
 * eligibility, it only withholds it. Returning `Eligible` here would let a candidate
 * who has not been scored look promotable.
 *
 * The reasons are accumulated rather than short-circuited, for the same reason
 * `EligibilityService` accumulates its own: an officer refused promotion is entitled
 * to know every ground, and a tribunal will ask for them.
 */
final readonly class CandidateGateVerdict
{
    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $gapCitations  the justified missing records that produced a CIR
     * @param  list<string>  $advisories  findings that must be surfaced but must not decide
     */
    private function __construct(
        public ?RemarkCode $status,
        public array $reasons,
        public array $gapCitations = [],
        public array $advisories = [],
    ) {}

    /**
     * The same verdict with findings attached that are **reported, not applied**.
     *
     * An advisory is a check the engine could not make safely. It reaches the run's
     * pending decisions so the Commission sees it, and it changes no status — which
     * is the only honest treatment of a rule that has not been settled. Guessing in
     * either direction would either refuse a promotion the rules allow or grant one
     * they may not.
     *
     * @param  list<string>  $advisories
     */
    public function with(array $advisories): self
    {
        if ($advisories === []) {
            return $this;
        }

        return new self(
            $this->status,
            $this->reasons,
            $this->gapCitations,
            [...$this->advisories, ...$advisories],
        );
    }

    /**
     * No gate refused anything; the score decides.
     */
    public static function passed(): self
    {
        return new self(null, []);
    }

    /**
     * Rejected outright — items 11 and 13.
     *
     * @param  list<string>  $reasons
     */
    public static function rejected(array $reasons): self
    {
        return new self(RemarkCode::Rejected, $reasons);
    }

    /**
     * The recruitment rule is not satisfied — item 12, subject to BD-32.
     *
     * @param  list<string>  $reasons
     */
    public static function ruleNotSatisfied(array $reasons, RemarkCode $status): self
    {
        return new self($status, $reasons);
    }

    /**
     * A mandatory record is missing but justified — item 19.
     *
     * @param  list<string>  $reasons
     * @param  list<string>  $citations
     */
    public static function circulation(array $reasons, array $citations): self
    {
        return new self(RemarkCode::Circulation, $reasons, $citations);
    }

    /**
     * Whether a gate settled the status, so the score cannot override it.
     */
    public function isDecided(): bool
    {
        return $this->status !== null;
    }
}
