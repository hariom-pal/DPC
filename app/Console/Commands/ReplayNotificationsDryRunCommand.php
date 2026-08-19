<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notification\Services\NotificationReplayDryRun;
use Illuminate\Console\Command;

/**
 * `php artisan notifications:replay --dry-run` — **TD-10, closed**.
 *
 * **There is no wet run, and the flag is required anyway.** `--dry-run` must be
 * passed explicitly even though nothing else is possible: the command's name
 * contains the word "replay", and somebody will one day type it expecting delivery.
 * Requiring the flag means they get a refusal that says so rather than a report they
 * mistake for an outcome.
 *
 * @see NotificationReplayDryRun for what it reports and why it reads the ledger
 */
class ReplayNotificationsDryRunCommand extends Command
{
    protected $signature = 'notifications:replay
        {--dry-run : Required. Report what would be sent; nothing is dispatched}
        {--workflow= : Restrict to one workflow, e.g. dpc_cgpsc}
        {--event= : Restrict to one event, e.g. allot}
        {--subject-type= : Fully-qualified subject class}
        {--subject-id= : Subject key}
        {--from= : Earliest occurred_at date (YYYY-MM-DD)}
        {--to= : Latest occurred_at date (YYYY-MM-DD)}
        {--limit=1000 : Maximum ledger rows to consider}
        {--detail : List every entry, not just the summary}';

    protected $description = 'Report which notifications a replay of the workflow ledger would send (TD-10). Sends nothing.';

    public function handle(NotificationReplayDryRun $replay): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Refusing to run without --dry-run.');
            $this->line('This command only ever reports; there is no mode that sends. The flag is');
            $this->line('required so that nobody runs it expecting delivery and reads the report as one.');

            return self::FAILURE;
        }

        $report = $replay->run([
            'workflow' => $this->option('workflow'),
            'event' => $this->option('event'),
            'subject_type' => $this->option('subject-type'),
            'subject_id' => $this->option('subject-id'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
        ], max(1, (int) $this->option('limit')));

        $this->info('Dry run — nothing was sent, and nothing was written.');
        $this->newLine();

        $this->line(sprintf(
            '  %d ledger transition(s) → <options=bold>%d notification(s)</> would be sent to %d recipient(s).',
            $report['totals']['transitions'],
            $report['totals']['notifications'],
            count($report['by_recipient']),
        ));

        if ($report['by_event'] !== []) {
            $this->newLine();
            $this->table(['Event', 'Transitions'], array_map(
                static fn (string $event, int $count): array => [$event, $count],
                array_keys($report['by_event']),
                array_values($report['by_event']),
            ));
        }

        if ($report['by_recipient'] !== []) {
            $this->table(['Recipient', 'Notifications'], array_map(
                static fn (string $name, int $count): array => [$name, $count],
                array_keys($report['by_recipient']),
                array_values($report['by_recipient']),
            ));
        }

        // The catalogue's own point: a dry run is most useful when it surfaces what
        // *cannot* be replayed. Printed last so it is the thing left on screen.
        if ($report['unresolvable'] !== []) {
            $this->newLine();
            $this->warn(count($report['unresolvable']).' transition(s) could not be resolved to a recipient:');
            $this->table(['Event', 'Why'], array_map(
                static fn (array $row): array => [
                    $row['workflow'].'.'.$row['event'],
                    $row['reason'],
                ],
                $report['unresolvable'],
            ));
        } else {
            $this->newLine();
            $this->info('Every transition in range resolves to at least one active recipient.');
        }

        if ($this->option('detail')) {
            $this->newLine();
            $this->table(['When', 'Event', 'Title', 'Recipients'], array_map(
                static fn (array $entry): array => [
                    (string) $entry['occurred_at'],
                    $entry['workflow'].'.'.$entry['event'],
                    $entry['title'],
                    implode(', ', $entry['recipients']) ?: '—',
                ],
                $report['entries'],
            ));
        }

        $this->newLine();
        $this->line('  Nothing above has been sent. Re-run with <options=bold>--detail</> for every entry.');

        return self::SUCCESS;
    }
}
