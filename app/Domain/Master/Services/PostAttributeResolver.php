<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Master\Models\DepartmentPost;
use Illuminate\Support\Collection;

/**
 * The one place a post's Pay Scale, Class and Promotion Duration are read off its
 * Department Post Mapping (change item 5).
 *
 * **Why a service and not three lines in a controller.** The register's instruction
 * is that once posts are chosen, *no manual selection should be required* for those
 * three fields. The same values are needed by the Pre-DPC form, by the request that
 * validates it, by the DPC proposal that inherits them, and by the JSON the browser
 * calls to fill the boxes in as you pick. Four callers, one rule — and if the
 * browser's answer and the server's answer were derived separately they would
 * eventually disagree, which on a promotion record is not a cosmetic difference.
 *
 * **All three values come from the post being asked about.** That is the correction
 * of 2026-08-25. This used to answer for a *pair*, taking Pay Scale and Class from
 * the Promotion To Post — so a screen showing a feeder post displayed the scale of
 * the post above it. Promotion eligibility is judged on the officer's current post,
 * so the scale and class a reader needs beside a feeder are the feeder's own.
 * Promotion Duration was always read from the post itself and is unchanged: it is
 * the qualifying service an officer must have *on that post*.
 *
 * **Nothing here invents a value.** A post with no pay scale registered yields
 * `null`, not a guess — and the screen shows a blank with an explanation rather than
 * a plausible number. Inventing a promotion duration would be inventing a business
 * rule.
 */
class PostAttributeResolver
{
    /**
     * One post's attributes, or null if it is not a live post of this department.
     *
     * **The department check is here rather than only in a Form Request**, and
     * deliberately so: this is also called from the JSON endpoint the form uses and
     * from the DPC inheritance path, and a post of another establishment reaching
     * either of those would produce a proposal that promotes between two
     * departments. Returning `null` is what makes every caller fail closed.
     */
    public function resolve(int $departmentId, ?int $postId): ?PostAttributes
    {
        $post = $this->post($departmentId, $postId);

        if ($post === null) {
            return null;
        }

        return new PostAttributes(
            post: $post,
            payScaleId: $post->pay_scale_id,
            payScaleLabel: $post->payScale?->label,
            classLevel: $post->class_level,
            promotionDurationYears: $post->promotion_duration_years,
        );
    }

    /**
     * Several posts at once, keyed by id, skipping any that do not resolve.
     *
     * The Pre-DPC form asks about a whole list of feeders in one request rather than
     * one call per row: a promotion with four sources should not cost four
     * round trips, and answering them together is also what keeps the rows on the
     * screen consistent with each other.
     *
     * @param  list<int>  $postIds
     * @return Collection<int, PostAttributes>
     */
    public function resolveMany(int $departmentId, array $postIds): Collection
    {
        $resolved = new Collection;

        foreach (array_unique($postIds) as $postId) {
            $attributes = $this->resolve($departmentId, $postId);

            if ($attributes !== null) {
                $resolved->put($postId, $attributes);
            }
        }

        return $resolved;
    }

    /**
     * One live post of this department, or null.
     *
     * `inForce()` rather than "not deleted": a post still in draft has not been
     * sanctioned, and a mapping the Government has superseded cannot authorise a new
     * promotion — though it stays on record for the DPCs it did authorise.
     */
    private function post(int $departmentId, ?int $postId): ?DepartmentPost
    {
        if ($postId === null) {
            return null;
        }

        return DepartmentPost::query()
            ->with('payScale')
            ->inForce()
            ->where('department_id', $departmentId)
            ->find($postId);
    }
}
