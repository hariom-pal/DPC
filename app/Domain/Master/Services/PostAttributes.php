<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Master\Models\DepartmentPost;
use App\Enums\ServiceClass;

/**
 * What one Department Post Mapping determines (change item 5).
 *
 * **Per post, not per pair — corrected on 2026-08-25.** This was
 * `PostPairAttributes`, which answered "what do these two posts determine
 * together" and read the Pay Scale and Class off the **To** post. Two things were
 * wrong with that:
 *
 *  - **Pay Scale and Class belong to whichever post is being described.** Promotion
 *    eligibility is judged on the officer's *current* post, so the figure a reader
 *    needs beside a Promotion From Post is that post's own scale and class — not
 *    the scale of the post nobody has been promoted into yet.
 *  - **A promotion has several feeder posts**, so "the pair" was never the right
 *    unit. Each source has its own scale, class and qualifying service.
 *
 * A value object rather than an array so a caller cannot quietly read a key that
 * was never set: `$attributes->payScaleId` is either an id or null, and
 * `$attributes->classLevel` cannot be a string that looks like a class.
 */
final readonly class PostAttributes
{
    public function __construct(
        public DepartmentPost $post,
        public ?int $payScaleId,
        public ?string $payScaleLabel,
        public ServiceClass $classLevel,
        public ?int $promotionDurationYears,
    ) {}

    /**
     * The shape the form's auto-population endpoint returns.
     *
     * Deliberately not the model: the browser needs the post's name and the three
     * derived values, and serialising the model would put `created_by`, timestamps
     * and the department's internal ids on the wire for no reason.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->post->id,
            'name' => $this->post->name,
            'pay_scale_id' => $this->payScaleId,
            'pay_scale_label' => $this->payScaleLabel,
            'class_level' => $this->classLevel->value,
            'class_label' => $this->classLevel->label(),
            'promotion_duration_years' => $this->promotionDurationYears,
        ];
    }
}
