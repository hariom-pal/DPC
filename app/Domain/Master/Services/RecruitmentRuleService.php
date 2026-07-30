<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

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
                    'feeder_cadre_id', 'promotion_percentage', 'min_experience_years', 'min_qualification', 'sort_order',
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
        $order = 0;
        foreach (array_values($feeders) as $feeder) {
            $rule->feeders()->create([
                'feeder_cadre_id' => $feeder['feeder_cadre_id'],
                'promotion_percentage' => $feeder['promotion_percentage'] ?? 0,
                'min_experience_years' => $feeder['min_experience_years'] ?? 0,
                'min_qualification' => $feeder['min_qualification'] ?? null,
                'sort_order' => $order++,
            ]);
        }
    }
}
