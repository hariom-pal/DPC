<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Master\Data\QualificationCatalogue;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Models\RecruitmentRuleFeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which recruitment-rule feeders still carry a minimum qualification as free
 * text that no Qualification master row matches.
 *
 * The migration that introduced `qualification_id` mapped everything it could
 * and left the rest alone rather than guessing. This is how the remainder is
 * found: it is a data-quality report, not a repair. Adding the missing
 * qualification to the master and re-running `--fix` links them.
 */
class ReportUnmappedQualificationsCommand extends Command
{
    protected $signature = 'qualifications:unmapped
        {--fix : Re-attempt the match after new qualifications have been added to the master}';

    protected $description = 'List recruitment-rule feeders whose minimum qualification is not linked to the Qualification master';

    public function handle(): int
    {
        $unmapped = RecruitmentRuleFeeder::query()
            ->with(['recruitmentRule.cadre', 'feederCadre'])
            ->whereNull('qualification_id')
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
            ['Rule', 'Target cadre', 'Feeder cadre', 'Free text'],
            $unmapped->map(static fn (RecruitmentRuleFeeder $feeder): array => [
                // Both foreign keys are non-nullable, so the relations always
                // resolve — no nullsafe needed.
                '#'.$feeder->recruitment_rule_id,
                $feeder->recruitmentRule->cadre->name,
                $feeder->feederCadre->name,
                (string) $feeder->min_qualification,
            ])->all(),
        );

        $this->newLine();
        $this->line('Add the missing qualification(s) under Administration → Qualifications, then run:');
        $this->line('  <options=bold>php artisan qualifications:unmapped --fix</>');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, RecruitmentRuleFeeder>  $unmapped
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
                $feeder->forceFill(['qualification_id' => $id])->save();
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
