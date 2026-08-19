<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Support\FeederCadreResolver;
use Illuminate\Console\Command;

/**
 * Which Pre-DPC feeders name a cadre that no master row matches — and were
 * therefore omitted from the recruitment rule the approval created.
 *
 * **TD-25, made visible.** `projectToMasters()` resolves a text-only feeder by
 * name within the department. When nothing matches, the feeder is **skipped and
 * the recruitment rule is created without it** — so the rule looks complete and
 * sanctions nothing. It is that combination, not the omission itself, that makes
 * this worth a report: a rule missing its feeder cadre promotes nobody, and
 * nothing on any screen says why.
 *
 * **This is a report, not a repair, and deliberately so.** The register offers
 * two fixes — report the unmatched feeder, or refuse the approval outright.
 * Refusing is a workflow change and the Architect's call; reporting changes no
 * behaviour and no stored data. The precedent is `qualifications:unmapped`, which
 * is the same shape for the same reason. **The remaining half of TD-25 stays
 * open.**
 *
 * The projection also logs a warning at the moment it drops a feeder. That covers
 * cases from now on; this covers the ones already stored, which no log will ever
 * mention.
 */
class ReportUnmatchedPreDpcFeedersCommand extends Command
{
    protected $signature = 'pre-dpc:unmatched-feeders
        {--all : Include draft, submitted, returned and cancelled proposals, not only approved ones}';

    protected $description = 'List Pre-DPC feeders whose cadre matches no master row and were omitted from the recruitment rule (TD-25)';

    public function handle(FeederCadreResolver $resolver): int
    {
        $query = PreDpcProposal::query()->with('posts.feeders')->orderBy('department_id')->orderBy('version');

        if (! $this->option('all')) {
            // Only an approved proposal has projected, so only an approved one
            // has actually lost a feeder. A draft naming an unknown cadre is not
            // yet a finding — the AO can still fix it, and reporting it would
            // train the reader to ignore this command.
            $query->where('status', PreDpcStatus::Approved->value);
        }

        $rows = [];

        foreach ($query->get() as $proposal) {
            foreach ($proposal->posts as $post) {
                foreach ($post->feeders as $feeder) {
                    if ($resolver->resolve($feeder, $proposal->department_id) !== null) {
                        continue;
                    }

                    $rows[] = [
                        (string) $proposal->department_id,
                        'v'.$proposal->version,
                        $proposal->status->value,
                        $post->post_name,
                        $feeder->feeder_name,
                    ];
                }
            }
        }

        if ($rows === []) {
            $this->info($this->option('all')
                ? 'Every Pre-DPC feeder resolves to a cadre in its department master.'
                : 'Every feeder on every approved Pre-DPC resolves to a cadre in its department master.');

            return self::SUCCESS;
        }

        $this->warn(count($rows).' Pre-DPC feeder(s) match no cadre and were omitted from the recruitment rule:');
        $this->newLine();

        $this->table(['Dept', 'Version', 'Status', 'Post', 'Feeder named'], $rows);

        $this->newLine();
        $this->line('Each of these produced a recruitment rule <options=bold>without that feeder</> — the rule');
        $this->line('looks complete and sanctions nobody from that cadre. To repair one:');
        $this->line('  1. add the missing cadre under Administration → Cadres, then');
        $this->line('  2. amend the Pre-DPC and approve it again, which re-projects the rule.');
        $this->newLine();
        $this->line('Reported, not repaired: whether an unmatched feeder should instead refuse the');
        $this->line('approval is a workflow change and the Architect\'s decision (TD-25).');

        return self::SUCCESS;
    }
}
