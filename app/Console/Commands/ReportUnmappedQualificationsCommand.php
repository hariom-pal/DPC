<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Master\Data\QualificationCatalogue;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Models\RecruitmentRuleSource;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which recruitment-rule feeders still carry a minimum qualification as free
 * text that no Qualification master row matches.
 *
 * The migration that introduced the master link mapped everything it could and
 * left the rest alone rather than guessing. This is how the remainder is found: it
 * is a data-quality report, not a repair. Adding the missing qualification to the
 * master and re-running `--fix` links them.
 *
 * **"Unmapped" reads off the pivot since 2026-08-26** (change register item 11).
 * The link used to be `recruitment_rule_sources.qualification_id`, a single
 * nullable column; a source may now accept several qualifications, so the question
 * is whether it has *any* master row attached. `doesntHave('qualifications')` is
 * that question, and it is the same question the old `whereNull` asked — a source
 * with free text and no master link at all.
 */
class ReportUnmappedQualificationsCommand extends Command
{
    protected $signature = 'qualifications:unmapped
        {--fix : Re-attempt the match after new qualifications have been added to the master}';

    protected $description = 'List recruitment-rule feeders whose minimum qualification is not linked to the Qualification master';

    public function handle(): int
    {
        $unmapped = RecruitmentRuleSource::query()
            ->with(['recruitmentRule.post', 'promotionFromPost'])
            ->doesntHave('qualifications')
            ->whereNotNull('min_qualification')
            ->where('min_qualification', '<>', '')
            ->get();

        if ($unmapped->isEmpty()) {
            $this->info('Every recruitment-rule feeder with a minimum qualification is linked to the Qualification master.');

            return self::SUCCESS;
        }

        if ($this->option('fix')) {
            return $this->fix($unmapped);
        }

        $this->warn($unmapped->count().' feeder(s) carry a qualification that matches no master row:');
        $this->newLine();

        $this->table(
            ['Rule', 'Target post', 'Feeder post', 'Free text'],
            $unmapped->map(static fn (RecruitmentRuleSource $feeder): array => [
                // Both foreign keys are non-nullable, so the relations always
                // resolve — no nullsafe needed.
                '#'.$feeder->recruitment_rule_id,
                $feeder->recruitmentRule->post->name,
                $feeder->promotionFromPost->name,
                (string) $feeder->min_qualification,
            ])->all(),
        );

        $this->newLine();
        $this->line('Add the missing qualification(s) under Administration → Qualifications, then run:');
        $this->line('  <options=bold>php artisan qualifications:unmapped --fix</>');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, RecruitmentRuleSource>  $unmapped
     */
    private function fix(iterable $unmapped): int
    {
        $index = [];

        foreach (Qualification::query()->selectable()->get(['id', 'code', 'name_en']) as $qualification) {
            foreach ([$qualification->code, $qualification->name_en] as $candidate) {
                $key = QualificationCatalogue::matchKey((string) $candidate);

                if ($key !== '' && ! isset($index[$key])) {
                    $index[$key] = $qualification->id;
                }
            }
        }

        $linked = 0;

        foreach ($unmapped as $feeder) {
            $id = $index[QualificationCatalogue::matchKey((string) $feeder->min_qualification)] ?? null;

            if ($id !== null) {
                // `syncWithoutDetaching` rather than `attach`: this command is
                // re-run after the master is extended, and attaching a row that is
                // already there would violate the pivot's unique index rather than
                // being the no-op the operator expects.
                $feeder->qualifications()->syncWithoutDetaching([$id => ['sort_order' => 0]]);
                $linked++;
            }
        }

        $this->info("Linked {$linked} feeder(s) to the Qualification master.");

        if ($linked === 0) {
            $this->warn('Nothing matched. The master still has no entry for the text listed above.');
        }

        return self::SUCCESS;
    }
}
