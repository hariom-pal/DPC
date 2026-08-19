<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * `composer audit`, retried — **TD-2, closed**.
 *
 * **Three consecutive milestone finalisations needed a retry on this step**: two
 * network timeouts to packagist (`curl error 28`) falling back to a stale local
 * cache, and one that returned composer's usage text instead of running. The
 * register's own words: *"that is a reliability problem in the gate, not an
 * intermittent annoyance."*
 *
 * It matters more than an ordinary flake because of **what it looks like when it
 * fails**: a security gate reporting failure. Somebody will eventually see that,
 * assume an advisory was found, and go looking for a vulnerability that does not
 * exist — or, worse, learn to re-run it until it passes and stop reading the output.
 *
 * **Two things this does that a bare `composer audit` in the gate script cannot.**
 *
 * 1. **Retries a transport failure and only a transport failure.** A found advisory
 *    is a result and is never retried; a timeout is not a result and is.
 * 2. **Refuses a stale-cache pass.** Composer falls back to the local cache when it
 *    cannot reach packagist and still exits zero, which is the worst outcome
 *    available: a green security gate that checked nothing current. That is reported
 *    as a failure unless `--allow-cached` is passed deliberately.
 */
class SecurityAuditCommand extends Command
{
    protected $signature = 'security:audit
        {--attempts=3 : How many times to retry a transport failure}
        {--allow-cached : Accept a result composer served from its local cache}';

    protected $description = 'Run composer audit, retrying transport failures and refusing a stale-cache pass (TD-2)';

    /**
     * Text composer emits when it could not reach the repository and fell back.
     */
    private const CACHE_FALLBACK_MARKERS = [
        'from cache',
        'Downloading failed',
        'could not be fully loaded',
    ];

    /**
     * Text that means the network, not the code, is the problem.
     */
    private const TRANSPORT_MARKERS = [
        'curl error 28',
        'Operation timed out',
        'Could not resolve host',
        'Connection timed out',
        'timed out',
        // The usage-output response seen at M9 Phase 3: composer printed its own
        // help instead of running the command.
        'audit [--no-dev]',
    ];

    public function handle(): int
    {
        $attempts = max(1, (int) $this->option('attempts'));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $process = new Process(['composer', 'audit', '--format=plain'], base_path());
            $process->setTimeout(180);
            $process->run();

            $output = $process->getOutput()."\n".$process->getErrorOutput();

            if ($process->isSuccessful() && ! $this->servedFromCache($output)) {
                $this->info('No security advisories. (attempt '.$attempt.' of '.$attempts.')');

                return self::SUCCESS;
            }

            if ($process->isSuccessful()) {
                // Exit zero, but composer told us it could not reach packagist.
                if ($this->option('allow-cached')) {
                    $this->warn('No advisories, but composer served this from its local cache. Accepted because --allow-cached was passed.');

                    return self::SUCCESS;
                }

                $this->error('composer audit passed, but from its LOCAL CACHE — it could not reach the advisory database.');
                $this->line('A green security gate that checked nothing current is worse than a failing one.');
                $this->line('Re-run when the network is available, or pass --allow-cached to accept it deliberately.');

                return self::FAILURE;
            }

            if (! $this->isTransportFailure($output)) {
                // A real finding. Never retried — retrying a result until it changes
                // is how a security gate stops meaning anything.
                $this->error('composer audit reported advisories:');
                $this->line(trim($output));

                return self::FAILURE;
            }

            $this->warn("Attempt {$attempt} of {$attempts} could not reach the advisory database; retrying.");
        }

        $this->error("composer audit could not reach the advisory database in {$attempts} attempts.");
        $this->line('This is a network failure, not a finding — but it is reported as a failure, because');
        $this->line('an unverified dependency tree must not pass as a verified one.');

        return self::FAILURE;
    }

    private function servedFromCache(string $output): bool
    {
        foreach (self::CACHE_FALLBACK_MARKERS as $marker) {
            if (stripos($output, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isTransportFailure(string $output): bool
    {
        foreach (self::TRANSPORT_MARKERS as $marker) {
            if (stripos($output, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}
