<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Master\Enums\ExperienceUnit;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Enums\MasterStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Manages versioned recruitment rules. Rules are never overwritten once past
 * Draft: a new version is cloned instead. The Government Order PDF is stored on
 * the configured documents disk (S3 in production); only metadata is persisted.
 */
class RecruitmentRuleService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $feeders
     */
    public function create(array $data, array $feeders, ?UploadedFile $document): RecruitmentRule
    {
        return DB::transaction(function () use ($data, $feeders, $document): RecruitmentRule {
            $cadreId = (int) $data['cadre_id'];
            $rule = new RecruitmentRule($data);
            $rule->version = $this->nextVersion($cadreId);

            if ($document !== null) {
                [$rule->go_document_disk, $rule->go_document_path] = $this->storeDocument($cadreId, $rule->version, $document);
            }

            $rule->save();
            $this->syncFeeders($rule, $feeders);
            $this->timeline->record('recruitment_rule.created', $rule, "Recruitment rule v{$rule->version} created");

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $feeders
     */
    public function updateDraft(RecruitmentRule $rule, array $data, array $feeders, ?UploadedFile $document): RecruitmentRule
    {
        return DB::transaction(function () use ($rule, $data, $feeders, $document): RecruitmentRule {
            $rule->fill($data);

            if ($document !== null) {
                [$rule->go_document_disk, $rule->go_document_path] = $this->storeDocument((int) $rule->cadre_id, $rule->version, $document);
            }

            $rule->save();
            $rule->feeders()->delete();
            $this->syncFeeders($rule, $feeders);
            $this->timeline->record('recruitment_rule.updated', $rule, "Recruitment rule v{$rule->version} updated");

            return $rule;
        });
    }

    /**
     * Clone a rule into a fresh Draft version (never overwrite an existing one).
     */
    public function newVersion(RecruitmentRule $rule): RecruitmentRule
    {
        return DB::transaction(function () use ($rule): RecruitmentRule {
            $clone = $rule->replicate(['version', 'status', 'go_document_disk', 'go_document_path']);
            $clone->version = $this->nextVersion((int) $rule->cadre_id);
            $clone->status = MasterStatus::Draft;
            $clone->save();

            foreach ($rule->feeders as $feeder) {
                $clone->feeders()->create($feeder->only([
                    'feeder_cadre_id', 'promotion_percentage', 'min_experience_years',
                    'min_experience_unit', 'qualification_id', 'min_qualification', 'sort_order',
                ]));
            }

            $this->timeline->record('recruitment_rule.versioned', $clone, "New version v{$clone->version} cloned from v{$rule->version}");

            return $clone;
        });
    }

    public function delete(RecruitmentRule $rule): void
    {
        $this->timeline->record('recruitment_rule.deleted', $rule, "Recruitment rule v{$rule->version} deleted");
        $rule->delete();
    }

    private function nextVersion(int $cadreId): int
    {
        return (int) RecruitmentRule::query()->where('cadre_id', $cadreId)->max('version') + 1;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function storeDocument(int $cadreId, int $version, UploadedFile $document): array
    {
        $disk = (string) config('dpc.documents.disk');
        $path = "recruitment-rules/{$cadreId}/{$version}/".Str::uuid().'.'.$document->getClientOriginalExtension();
        Storage::disk($disk)->put($path, (string) $document->get());

        return [$disk, $path];
    }

    /**
     * @param  array<int, array<string, mixed>>  $feeders
     */
    private function syncFeeders(RecruitmentRule $rule, array $feeders): void
    {
        $feeders = $this->normaliseShares(array_values($feeders));

        $order = 0;
        foreach ($feeders as $feeder) {
            $qualificationId = isset($feeder['qualification_id']) && $feeder['qualification_id'] !== ''
                ? (int) $feeder['qualification_id']
                : null;

            $rule->feeders()->create([
                'feeder_cadre_id' => $feeder['feeder_cadre_id'],
                'promotion_percentage' => $feeder['promotion_percentage'] ?? 0,
                'min_experience_years' => $feeder['min_experience_years'] ?? 0,
                'min_experience_unit' => $this->experienceUnit($feeder['min_experience_unit'] ?? null),
                'qualification_id' => $qualificationId,
                // The legacy text column is kept in step with the master, so a
                // rule written today reads the same as one written before the
                // Qualification master existed. Callers that still pass raw text
                // (the Pre-DPC projection resolves it, but may find no match)
                // keep their text, which is how an unmapped value stays visible.
                'min_qualification' => $this->qualificationText($qualificationId, $feeder['min_qualification'] ?? null),
                'sort_order' => $order++,
            ]);
        }
    }

    /**
     * The text to store alongside `qualification_id`: the master's own name when
     * there is one, otherwise whatever text the caller supplied.
     */
    private function qualificationText(?int $qualificationId, ?string $fallback): ?string
    {
        if ($qualificationId === null) {
            return $fallback;
        }

        return Qualification::query()->whereKey($qualificationId)->value('name_en') ?? $fallback;
    }

    /**
     * Years unless the caller says otherwise — the Pre-DPC projection and any
     * older caller pass no unit, and every value they have ever stored was years.
     */
    private function experienceUnit(mixed $unit): string
    {
        $parsed = is_string($unit) ? ExperienceUnit::tryFrom($unit) : null;

        return ($parsed ?? ExperienceUnit::Years)->value;
    }

    /**
     * Make the feeder shares total exactly 100, preserving their ratio.
     *
     * `promotion_percentage` is a share of the rule's promotion quota, so the
     * shares on one rule must total 100. The admin form validates that before it
     * ever reaches here, and a rejected form is the right answer for a human
     * typing numbers.
     *
     * This exists for the **other** caller: the Pre-DPC projection. The
     * department's Pre-DPC screens record the feeder percentage against all posts
     * (a 50%-promotion cadre with two equal feeders is captured as 25 and 25),
     * and those screens are not being changed. Normalising on the way into the
     * master is the translation between the two representations — it keeps the
     * ratio the department expressed (25:25 stays one-to-one) while satisfying
     * the master's own invariant. Without it an approved Pre-DPC would write
     * rules the admin screen would then refuse to save.
     *
     * A set whose shares are all zero is left alone: there is no ratio to
     * preserve, and inventing 100 would be fabricating a policy decision.
     *
     * @param  list<array<string, mixed>>  $feeders
     * @return list<array<string, mixed>>
     */
    private function normaliseShares(array $feeders): array
    {
        if ($feeders === []) {
            return $feeders;
        }

        $total = 0.0;
        foreach ($feeders as $feeder) {
            $total += (float) ($feeder['promotion_percentage'] ?? 0);
        }

        if ($total <= 0.0 || abs($total - 100.0) < 0.01) {
            return $feeders;
        }

        // Scale all but the last and give the last the remainder, so rounding
        // cannot leave the total at 99.99.
        $running = 0.0;
        $last = count($feeders) - 1;

        foreach ($feeders as $index => $feeder) {
            $share = $index === $last
                ? round(100.0 - $running, 2)
                : round((float) ($feeder['promotion_percentage'] ?? 0) / $total * 100, 2);

            $running += $share;
            $feeders[$index]['promotion_percentage'] = $share;
        }

        return $feeders;
    }
}
