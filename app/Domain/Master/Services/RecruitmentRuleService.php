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
            $postId = (int) $data['post_id'];
            $rule = new RecruitmentRule($data);
            $rule->version = $this->nextVersion($postId);

            if ($document !== null) {
                [$rule->go_document_disk, $rule->go_document_path] = $this->storeDocument($postId, $rule->version, $document);
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
                [$rule->go_document_disk, $rule->go_document_path] = $this->storeDocument((int) $rule->post_id, $rule->version, $document);
            }

            $rule->save();
            $rule->promotionSources()->delete();
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
            $clone->version = $this->nextVersion((int) $rule->post_id);
            $clone->status = MasterStatus::Draft;
            $clone->save();

            foreach ($rule->promotionSources as $feeder) {
                $copy = $clone->promotionSources()->create($feeder->only([
                    'promotion_from_post_id', 'promotion_percentage', 'min_experience_years',
                    'min_experience_unit', 'min_qualification', 'sort_order',
                ]));

                /*
                 | The accepted qualifications travel with the version (change
                 | register of 2026-08-26, item 11). They are a pivot rather than a
                 | column, so `replicate()` and `only()` cannot carry them — a new
                 | version that silently lost its qualification list would be a rule
                 | that had quietly stopped requiring a degree.
                 */
                $copy->qualifications()->attach(
                    $feeder->qualifications->mapWithKeys(fn ($qualification, $index): array => [
                        $qualification->id => ['sort_order' => $index],
                    ])->all(),
                );
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

    private function nextVersion(int $postId): int
    {
        return (int) RecruitmentRule::query()->where('post_id', $postId)->max('version') + 1;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function storeDocument(int $postId, int $version, UploadedFile $document): array
    {
        $disk = (string) config('dpc.documents.disk');
        $path = "recruitment-rules/{$postId}/{$version}/".Str::uuid().'.'.$document->getClientOriginalExtension();
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
            $qualificationIds = $this->qualificationIds($feeder);

            $source = $rule->promotionSources()->create([
                'promotion_from_post_id' => $feeder['promotion_from_post_id'],
                'promotion_percentage' => $feeder['promotion_percentage'] ?? 0,
                'min_experience_years' => $feeder['min_experience_years'] ?? 0,
                'min_experience_unit' => $this->experienceUnit($feeder['min_experience_unit'] ?? null),
                // The legacy text column is kept in step with the master, so a
                // rule written today reads the same as one written before the
                // Qualification master existed. Callers that still pass raw text
                // (the Pre-DPC projection resolves it, but may find no match)
                // keep their text, which is how an unmapped value stays visible.
                'min_qualification' => $this->qualificationText($qualificationIds, $feeder['min_qualification'] ?? null),
                'sort_order' => $order++,
            ]);

            $source->qualifications()->attach(
                collect($qualificationIds)
                    ->mapWithKeys(fn (int $id, int $index): array => [$id => ['sort_order' => $index]])
                    ->all(),
            );
        }
    }

    /**
     * The qualifications a posted feeder row accepts (change register item 11).
     *
     * Reads `qualification_ids` and tolerates the singular `qualification_id`,
     * because a rule may be saved by a caller written before the field became
     * plural — the Pre-DPC projection is one, and refusing its payload would break
     * the projection rather than telling anybody about it.
     *
     * De-duplicated and re-indexed: the same qualification named twice is one
     * qualification, and `unique(recruitment_rule_source_id, qualification_id)`
     * would refuse the second copy rather than ignoring it.
     *
     * @param  array<string, mixed>  $feeder
     * @return list<int>
     */
    private function qualificationIds(array $feeder): array
    {
        $raw = $feeder['qualification_ids'] ?? $feeder['qualification_id'] ?? [];

        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, (array) $raw),
            static fn (int $id): bool => $id > 0,
        )));

        return $ids;
    }

    /**
     * The legacy text to store beside the pivot: the masters' own names when there
     * are any, otherwise whatever text the caller supplied.
     *
     * @param  list<int>  $qualificationIds
     */
    private function qualificationText(array $qualificationIds, ?string $fallback): ?string
    {
        if ($qualificationIds === []) {
            return $fallback;
        }

        $names = Qualification::query()
            ->whereIn('id', $qualificationIds)
            ->orderByRaw($this->orderByIds($qualificationIds))
            ->pluck('name_en')
            ->all();

        return $names === [] ? $fallback : implode(' / ', $names);
    }

    /**
     * Keep `whereIn` results in the order the caller listed them.
     *
     * The legacy text column mirrors what the screen shows, and the screen shows
     * the qualifications in entry order — so the text has to follow the same order
     * rather than whatever the database returns.
     *
     * @param  list<int>  $ids
     */
    private function orderByIds(array $ids): string
    {
        $cases = [];

        foreach ($ids as $position => $id) {
            $cases[] = sprintf('WHEN id = %d THEN %d', $id, $position);
        }

        return 'CASE '.implode(' ', $cases).' ELSE '.count($ids).' END';
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
     * (a 50%-promotion post with two equal feeders is captured as 25 and 25),
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
