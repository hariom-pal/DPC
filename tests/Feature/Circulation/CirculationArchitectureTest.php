<?php

declare(strict_types=1);

namespace Tests\Feature\Circulation;

use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Models\DpcCirculationItem;
use App\Domain\Dpc\Models\DpcProposal;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * **AD-23 and AD-24, enforced.** M9 Phase 1.
 *
 * AD-23 rules that a Circulation implementation shall never invoke the
 * calculation, eligibility, zone or proceeding services, and shall never
 * recalculate, re-score, determine eligibility or a zone, or conduct a
 * proceeding. **Violation is an Architecture Defect**, not a style finding.
 *
 * **The forbidden list below is AD-23's, not the four class names in the
 * original ruling**, and the difference is the whole point of this test:
 *
 * - `ProceedingCalculationService` is **M7's own entry point** into
 *   `DpcCalculationService`. Forbidding only the callee leaves the caller open.
 * - `ReviewAwareCalculationService` is **M8's decorator, bound over M7's
 *   service in the container**. A type-hint on the M7 class resolves to *this*
 *   one, so a container binding can defeat a rule written against a name.
 *
 * The rule is about **reachability, not spelling**. A grep for four names
 * would be green while the engine was one constructor argument away.
 *
 * This is a plain PHPUnit test with no database and no framework boot: it is
 * a statement about source text, and it must stay runnable even if the
 * application cannot start.
 */
class CirculationArchitectureTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function forbidden(): array
    {
        return [
            // Named in the ruling.
            'DpcCalculationService',
            'EligibilityService',
            'ZoneOfConsiderationService',
            'ProceedingService',
            // The acts the ruling forbids, reachable without naming the above.
            'ScoringService',
            'SuperSeatService',
            'ExplainCalculationService',
            'ProceedingCalculationService',
            'ReviewAwareCalculationService',
            'ProceedingCorrectionService',
        ];
    }

    /**
     * @return list<SplFileInfo>
     */
    private function circulationSources(): array
    {
        $roots = [
            dirname(__DIR__, 3).'/app/Domain/Circulation',
            dirname(__DIR__, 3).'/app/Http/Controllers/Dpc',
            // M9 Phase 2 put the Commission's controller here. Adding the root
            // is not optional: a check that does not look at new code is a
            // check that goes green as the rule stops being enforced.
            dirname(__DIR__, 3).'/app/Http/Controllers/Cgpsc',
        ];

        $files = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php'
                    && str_contains($file->getFilename(), 'Circulation')) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    public function test_the_circulation_source_set_is_not_empty(): void
    {
        // Guards the test itself: a check that silently examines nothing is
        // worse than no check, because it reports green.
        $this->assertNotEmpty($this->circulationSources());
    }

    /**
     * The file's source with **comments removed**.
     *
     * A naive `str_contains` over raw source fails on the very docblock that
     * explains AD-23 — `CirculationService` names all ten forbidden classes in
     * prose precisely so the next reader knows why they are absent. A check
     * that flagged that would train people to delete the explanation in order
     * to go green, which is the opposite of what the rule wants.
     *
     * The rule is about what the code *reaches*, so the check reads what the
     * parser sees.
     */
    private function codeOf(SplFileInfo $file): string
    {
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        $code = '';

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    public function test_the_check_ignores_comments_and_reads_code(): void
    {
        // Guards the guard: `CirculationService` documents the forbidden list
        // in its docblock. If comment-stripping ever broke, this test tells
        // you that rather than the rule appearing to be violated.
        $service = array_values(array_filter(
            $this->circulationSources(),
            static fn (SplFileInfo $f): bool => $f->getFilename() === 'CirculationService.php',
        ));

        $this->assertNotEmpty($service);
        $this->assertStringContainsString(
            'DpcCalculationService',
            (string) file_get_contents($service[0]->getPathname()),
            'The docblock should still explain what is forbidden.'
        );
        $this->assertStringNotContainsString('DpcCalculationService', $this->codeOf($service[0]));
    }

    public function test_no_circulation_class_names_a_forbidden_service(): void
    {
        $violations = [];

        foreach ($this->circulationSources() as $file) {
            $source = $this->codeOf($file);

            foreach (self::forbidden() as $service) {
                if (str_contains($source, $service)) {
                    $violations[] = $file->getFilename().' → '.$service;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'AD-23 violation — a Circulation may never invoke a calculation, eligibility, zone or '
            ."proceeding service. This is an Architecture Defect, not a style finding.\n"
            .implode("\n", $violations)
        );
    }

    public function test_no_circulation_model_extends_the_dpc_proposal(): void
    {
        // AD-24: a Circulation is its own aggregate and shall not reuse,
        // extend or specialize `dpc_proposals`.
        foreach ([
            DpcCirculation::class,
            DpcCirculationItem::class,
        ] as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertFalse(
                $reflection->isSubclassOf(DpcProposal::class),
                "AD-24 violation — {$class} extends DpcProposal."
            );
        }
    }

    public function test_the_circulation_table_is_its_own_and_not_dpc_proposals(): void
    {
        $circulation = new DpcCirculation;

        $this->assertSame('dpc_circulations', $circulation->getTable());
        $this->assertNotSame('dpc_proposals', $circulation->getTable());
    }

    public function test_the_circulation_workflow_is_its_own_and_borrows_no_proposal_transition(): void
    {
        // Read from the config file directly — this test does not boot the
        // framework, and the point is that the definition exists in
        // configuration (AD-11) rather than in code.
        $config = require dirname(__DIR__, 3).'/config/dpc.php';

        $this->assertArrayHasKey('dpc_circulation', $config['workflows']);

        // Its own definition, under its own name. The department-side shape
        // is deliberately the same two-step M4 uses — that is reuse of a
        // *pattern*, not of the proposal's workflow, and the distinction is
        // the name the engine is asked for.
        $declared = array_keys($config['workflows']['dpc_circulation']['transitions']);
        $this->assertEqualsCanonicalizing(
            ['submit', 'approve', 'return', 'cancel', 'consent', 'return_to_department', 'sign'],
            $declared,
        );

        // Both signing points carry a signature: the department's approval
        // (AQ-23) and the Commission's consent, which is the operative act.
        $transitions = $config['workflows']['dpc_circulation']['transitions'];
        $this->assertTrue($transitions['approve']['signature']);
        $this->assertTrue($transitions['sign']['signature']);
        $this->assertFalse($transitions['consent']['signature']);
    }

    public function test_the_workflow_service_runs_under_the_circulation_workflow_name(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/Domain/Circulation/Services/CirculationWorkflowService.php'
        );

        // AD-24 in the one place it is easiest to get wrong: borrowing M4's
        // workflow name would put circulation transitions on the proposal's
        // ledger thread and make a Circulation look like a DPC.
        $this->assertStringContainsString("'dpc_circulation'", $source);
        $this->assertStringNotContainsString("'dpc_proposal'", $source);
    }
}
