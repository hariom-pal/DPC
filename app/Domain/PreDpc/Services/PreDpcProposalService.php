<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Data\QualificationCatalogue;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Services\RecruitmentRuleService;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Support\FeederCadreResolver;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Services\SignatureService;
use App\Enums\Feature;
use App\Enums\MasterStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pre-DPC Proposal workflow (PPT S7–S11). Draft authoring by the AO, approval
 * by the AH via a signature step-up, and — on approval — projection into the
 * Cadre + Recruitment Rule masters (single source of truth, architect Q1).
 * Approved proposals are immutable; amendments create a new version (Q3).
 */
class PreDpcProposalService
{
    /**
     * Normalised qualification name/code → id, built once per projection so a
     * nine-post ladder does not re-read the master nine times.
     *
     * @var array<string, int>|null
     */
    private ?array $qualificationIndex = null;

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly SignatureService $signatures,
        private readonly RecruitmentRuleService $rules,
        private readonly FeederCadreResolver $feederCadres,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(Department $department, User $actor, array $data): PreDpcProposal
    {
        return DB::transaction(function () use ($department, $actor, $data): PreDpcProposal {
            $proposal = new PreDpcProposal([
                'department_id' => $department->id,
                'version' => $this->nextVersion($department->id),
                'status' => PreDpcStatus::Draft->value,
                'is_current' => false,
                'government_order_number' => $data['government_order_number'] ?? null,
                'effective_from' => $data['effective_from'] ?? null,
                'prepared_by' => $actor->id,
            ]);
            $proposal->save();
            $this->syncPosts($proposal, $data['posts'] ?? []);

            return $proposal;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(PreDpcProposal $proposal, array $data): PreDpcProposal
    {
        return DB::transaction(function () use ($proposal, $data): PreDpcProposal {
            $proposal->fill([
                'government_order_number' => $data['government_order_number'] ?? null,
                'effective_from' => $data['effective_from'] ?? null,
            ])->save();
            $proposal->posts()->delete();
            $this->syncPosts($proposal, $data['posts'] ?? []);

            return $proposal;
        });
    }

    public function submit(PreDpcProposal $proposal, User $actor, Request $request): void
    {
        $from = $proposal->status->value;
        $def = $this->engine->assertAllowed('pre_dpc', 'submit', $from, $actor);
        $this->guardComplete($proposal);

        DB::transaction(function () use ($proposal, $actor, $request, $from, $def): void {
            $proposal->forceFill(['status' => PreDpcStatus::Submitted->value, 'submitted_at' => now(), 'prepared_by' => $actor->id])->save();
            $this->engine->record($proposal, 'pre_dpc', 'submit', $from, $def, $actor, $request, null, null, $proposal->department_id);
        });
    }

    public function approve(PreDpcProposal $proposal, User $actor, Request $request): void
    {
        $from = $proposal->status->value;
        $def = $this->engine->assertAllowed('pre_dpc', 'approve', $from, $actor);
        $this->assertSignatureAvailable();

        DB::transaction(function () use ($proposal, $actor, $request, $from, $def): void {
            $signature = $this->signatures->sign($actor, $proposal, $this->renderDocument($proposal), "pre-dpc-v{$proposal->version}.txt");

            PreDpcProposal::query()
                ->where('department_id', $proposal->department_id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $proposal->forceFill([
                'status' => PreDpcStatus::Approved->value,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'signature_id' => $signature->id,
                'is_current' => true,
            ])->save();

            $this->projectToMasters($proposal);
            $this->engine->record($proposal, 'pre_dpc', 'approve', $from, $def, $actor, $request, null, $signature->id, $proposal->department_id);
        });
    }

    public function return(PreDpcProposal $proposal, User $actor, string $remarks, Request $request): void
    {
        $from = $proposal->status->value;
        $def = $this->engine->assertAllowed('pre_dpc', 'return', $from, $actor);

        DB::transaction(function () use ($proposal, $actor, $remarks, $request, $from, $def): void {
            $proposal->forceFill(['status' => PreDpcStatus::Returned->value, 'returned_at' => now(), 'return_remarks' => $remarks])->save();
            $this->engine->record($proposal, 'pre_dpc', 'return', $from, $def, $actor, $request, $remarks, null, $proposal->department_id);
        });
    }

    public function cancel(PreDpcProposal $proposal, User $actor, Request $request): void
    {
        $from = $proposal->status->value;
        $def = $this->engine->assertAllowed('pre_dpc', 'cancel', $from, $actor);

        DB::transaction(function () use ($proposal, $actor, $request, $from, $def): void {
            $proposal->forceFill(['status' => PreDpcStatus::Cancelled->value, 'cancelled_at' => now()])->save();
            $this->engine->record($proposal, 'pre_dpc', 'cancel', $from, $def, $actor, $request, null, null, $proposal->department_id);
        });
    }

    /**
     * Amend an approved proposal: clone into a fresh Draft version.
     */
    public function amend(PreDpcProposal $proposal, User $actor): PreDpcProposal
    {
        return DB::transaction(function () use ($proposal, $actor): PreDpcProposal {
            $proposal->load('posts.feeders');

            $draft = new PreDpcProposal([
                'department_id' => $proposal->department_id,
                'version' => $this->nextVersion($proposal->department_id),
                'parent_version_id' => $proposal->id,
                'status' => PreDpcStatus::Draft->value,
                'is_current' => false,
                'government_order_number' => $proposal->government_order_number,
                'effective_from' => $proposal->effective_from,
                'prepared_by' => $actor->id,
            ]);
            $draft->save();

            foreach ($proposal->posts as $post) {
                // `cadre_id` and `qualification_id` are carried across with
                // everything else. An amendment starts from what was approved,
                // and dropping the master references would quietly downgrade a
                // referenced proposal into a text one — the new draft would
                // still *look* right, because the names come with it, and would
                // re-resolve by name at the next projection.
                $newPost = $draft->posts()->create($post->only([
                    'post_name', 'cadre_id', 'class_level', 'pay_scale_id', 'direct_recruitment_pct', 'promotion_recruitment_pct', 'sort_order',
                ]));
                foreach ($post->feeders as $feeder) {
                    $newPost->feeders()->create($feeder->only([
                        'feeder_name', 'feeder_cadre_id', 'promotion_percentage', 'min_experience_years', 'qualification_id', 'min_qualification', 'sort_order',
                    ]));
                }
            }

            return $draft;
        });
    }

    private function nextVersion(int $departmentId): int
    {
        return (int) PreDpcProposal::query()->where('department_id', $departmentId)->max('version') + 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $posts
     */
    private function syncPosts(PreDpcProposal $proposal, array $posts): void
    {
        $order = 0;
        foreach (array_values($posts) as $postData) {
            $post = $proposal->posts()->create([
                'post_name' => $postData['post_name'],
                // The Cadre master reference the screen now chooses. Nullable,
                // because a caller that posts only `post_name` is still valid
                // and its cadre is resolved at projection, as it always was.
                'cadre_id' => $postData['cadre_id'] ?? null,
                'class_level' => $postData['class_level'],
                'pay_scale_id' => $postData['pay_scale_id'] ?? null,
                'direct_recruitment_pct' => $postData['direct_recruitment_pct'] ?? 0,
                'promotion_recruitment_pct' => $postData['promotion_recruitment_pct'] ?? 0,
                'sort_order' => $order++,
            ]);

            $feederOrder = 0;
            foreach (array_values($postData['feeders'] ?? []) as $feederData) {
                $post->feeders()->create([
                    'feeder_name' => $feederData['feeder_name'],
                    'feeder_cadre_id' => $feederData['feeder_cadre_id'] ?? null,
                    'promotion_percentage' => $feederData['promotion_percentage'] ?? 0,
                    'min_experience_years' => $feederData['min_experience_years'] ?? 0,
                    // Chosen from the Qualification master by the screen. When
                    // only text arrives it stays null here and is resolved at
                    // projection, which is where it used to be resolved for
                    // every caller.
                    'qualification_id' => $feederData['qualification_id'] ?? null,
                    'min_qualification' => $feederData['min_qualification'] ?? null,
                    'sort_order' => $feederOrder++,
                ]);
            }
        }
    }

    private function guardComplete(PreDpcProposal $proposal): void
    {
        if ($proposal->posts()->count() < 1) {
            throw new WorkflowException('Add at least one sanctioned post before submitting.');
        }

        if (($proposal->government_order_number === null) || ($proposal->effective_from === null)) {
            throw new WorkflowException('Government Order number and effective date are required before submitting.');
        }
    }

    /**
     * Production must never sign a legally significant approval with the mock
     * provider (architect ruling, 2026-08-13). Keyed on the feature flag,
     * which is the single switch deciding which provider is bound — checking
     * anything else could drift out of step with what actually signs.
     */
    private function assertSignatureAvailable(): void
    {
        if (app()->environment('production') && Feature::CscDigiSign->disabled()) {
            throw new WorkflowException(
                'Digital signing is unavailable: CSC DigiSign is not enabled on this environment, and a Pre-DPC '
                .'proposal may not be approved without a legally valid digital signature. Contact the system administrator.'
            );
        }
    }

    private function renderDocument(PreDpcProposal $proposal): string
    {
        $proposal->loadMissing('posts.feeders');
        $lines = ["PRE-DPC PROPOSAL v{$proposal->version} — Department #{$proposal->department_id}", "GO: {$proposal->government_order_number}"];
        foreach ($proposal->posts as $post) {
            $lines[] = "- {$post->post_name} ({$post->class_level->value}) DR {$post->direct_recruitment_pct}% / PR {$post->promotion_recruitment_pct}%";
        }

        return implode("\n", $lines);
    }

    /**
     * Project the approved proposal into the Cadre + Recruitment Rule masters
     * (single source of truth). Every approval creates a new recruitment-rule
     * version per target cadre.
     */
    private function projectToMasters(PreDpcProposal $proposal): void
    {
        $proposal->load('posts.feeders');

        foreach ($proposal->posts as $post) {
            // A post chosen from the Cadre master is already the master row —
            // the screen picked it. Creating one from its name would, at best,
            // find the same row again and, at worst, mint a duplicate whenever
            // the department later renamed the cadre.
            if ($post->cadre_id !== null) {
                continue;
            }

            $cadre = Cadre::query()->updateOrCreate(
                ['department_id' => $proposal->department_id, 'name' => $post->post_name],
                ['class_level' => $post->class_level->value, 'pay_scale_id' => $post->pay_scale_id, 'status' => MasterStatus::Active->value],
            );
            $post->forceFill(['cadre_id' => $cadre->id])->save();
        }

        $proposal->load('posts.feeders');

        foreach ($proposal->posts as $post) {
            $feeders = [];
            foreach ($post->feeders as $feeder) {
                $feederCadre = $this->feederCadres->resolve($feeder, $proposal->department_id);

                if ($feederCadre === null) {
                    // TD-25, made visible. The feeder is still dropped — whether
                    // an unmatched feeder should instead *refuse* the approval is
                    // a workflow change and the Architect's call — but it is no
                    // longer dropped in silence. Warning, not info: a recruitment
                    // rule created without one of its feeder cadres looks complete
                    // and sanctions nothing, which is the worst of the outcomes
                    // available here.
                    Log::warning('Pre-DPC feeder matched no cadre and was omitted from the recruitment rule', [
                        'department_id' => $proposal->department_id,
                        'pre_dpc_proposal_id' => $proposal->id,
                        'pre_dpc_version' => $proposal->version,
                        'post_name' => $post->post_name,
                        'feeder_name' => $feeder->feeder_name,
                        'technical_debt' => 'TD-25',
                    ]);

                    continue;
                }

                $feeders[] = [
                    'feeder_cadre_id' => $feederCadre->id,
                    'promotion_percentage' => $feeder->promotion_percentage,
                    'min_experience_years' => $feeder->min_experience_years,
                    // The screen now chooses the qualification from the master,
                    // so the common case is a straight copy.
                    //
                    // Falling back to matching the text keeps two older paths
                    // working: a proposal drafted before 2026-08-18, and any
                    // caller that posts only `min_qualification`. Text that
                    // matches nothing is carried through unresolved rather than
                    // dropped, so it stays visible and is reported by
                    // `php artisan qualifications:unmapped`.
                    'qualification_id' => $feeder->qualification_id
                        ?? $this->resolveQualificationId($feeder->min_qualification),
                    'min_qualification' => $feeder->min_qualification,
                ];
            }

            $this->rules->create([
                'cadre_id' => $post->cadre_id,
                'government_order_number' => (string) $proposal->government_order_number,
                'direct_recruitment_pct' => $post->direct_recruitment_pct,
                'promotion_recruitment_pct' => $post->promotion_recruitment_pct,
                'effective_from' => $proposal->effective_from,
                'status' => MasterStatus::Active->value,
            ], $feeders, null);
        }
    }

    /**
     * Match a hand-entered qualification against the Qualification master.
     *
     * Compares a normalised form (case-folded, punctuation and whitespace
     * stripped) against both the code and the English name, so "Post-Graduate",
     * "post graduate" and "PG" all resolve to the same row. Returns null when
     * nothing matches — an unresolved qualification is a data-quality finding to
     * be reported, never a reason to reject an approved Pre-DPC.
     *
     * Soft-deleted and withdrawn rows are excluded from matching but an already
     * stored reference is untouched; see Qualification::scopeSelectable().
     */
    private function resolveQualificationId(?string $text): ?int
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $this->qualificationIndex ??= Qualification::query()
            ->selectable()
            ->get(['id', 'code', 'name_en'])
            ->reduce(static function (array $index, Qualification $qualification): array {
                foreach ([$qualification->code, $qualification->name_en] as $candidate) {
                    $key = QualificationCatalogue::matchKey((string) $candidate);

                    if ($key !== '' && ! isset($index[$key])) {
                        $index[$key] = $qualification->id;
                    }
                }

                return $index;
            }, []);

        return $this->qualificationIndex[QualificationCatalogue::matchKey($text)] ?? null;
    }
}
