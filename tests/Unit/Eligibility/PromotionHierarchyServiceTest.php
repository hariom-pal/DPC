<?php

declare(strict_types=1);

namespace Tests\Unit\Eligibility;

use App\Domain\Eligibility\Services\PromotionHierarchyService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\PayScale;
use App\Enums\ServiceClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Item 11 of the change register of 2026-08-27 — promotion goes up, or not at all.
 *
 * A unit test with no database, because the service is pure: it is handed two posts
 * and answers. The posts are unsaved models, which is enough — the service reads
 * `class_level` and the related pay scale and nothing else.
 */
final class PromotionHierarchyServiceTest extends TestCase
{
    /**
     * The register's three named rejections, and the cases either side of them.
     *
     * @return array<string, array{ServiceClass, ?int, ?int, ServiceClass, ?int, ?int, bool, ?string}>
     */
    public static function pairs(): array
    {
        return [
            // ---- The ordinary promotion -----------------------------------
            'lower class to higher class' => [
                ServiceClass::II, 10, 4200, ServiceClass::I, 12, 5400, true, null,
            ],
            'same class, higher scale' => [
                ServiceClass::I, 12, 5400, ServiceClass::I, 14, 6600, true, null,
            ],

            // ---- The register's three rejections --------------------------
            'higher class to lower class' => [
                ServiceClass::I, 12, 5400, ServiceClass::II, 10, 4200, false, 'a demotion',
            ],
            'higher pay scale to lower pay scale' => [
                ServiceClass::I, 14, 5400, ServiceClass::I, 12, 5400, false, 'a lower scale',
            ],
            'higher grade pay to lower grade pay' => [
                ServiceClass::I, 12, 6600, ServiceClass::I, 12, 5400, false, 'grade pay',
            ],

            /*
             | **Business clarification of 2026-09-06.** Equal used to pass on every
             | axis: the register forbade higher-to-lower and said nothing about a
             | lateral move, and Class-I to Class-I promotions are real.
             |
             | Business then narrowed it — Class-I to Class-I is the one same-class
             | exemption, *and only to a higher pay scale*. A move that changes
             | neither the class nor the scale is not a promotion, so identical is
             | now refused.
             */
            'identical on all three' => [
                ServiceClass::I, 12, 5400, ServiceClass::I, 12, 5400, false, 'the same pay scale',
            ],
            'same class, same scale, higher grade pay is still not a promotion' => [
                ServiceClass::II, 10, 4200, ServiceClass::II, 10, 4800, false, 'the same pay scale',
            ],

            /*
             | Cross-class is untouched by that narrowing. Class-III into Class-II on
             | one scale is a promotion, because the class itself went up — and a
             | rule that refused it would have been the same-class rule applied to
             | the wrong pairs.
             */
            'lower class to higher class on the same scale' => [
                ServiceClass::III, 10, 4200, ServiceClass::II, 10, 4200, true, null,
            ],

            /*
             | The same-class rule is skipped, not failed, when either scale has no
             | level — the same choice the other two comparisons make, and for the
             | same reason: an incomplete master must not become a promotion freeze.
             */
            'same class with no levels recorded is skipped, not failed' => [
                ServiceClass::I, null, null, ServiceClass::I, null, null, true, null,
            ],

            /*
             | An unanswerable check is not a failure.
             |
             | A pay scale registered before this register has no level and no grade
             | pay. Reading those nulls as a demotion would make every officer under
             | such a scale ineligible the day the migration ran — an incomplete
             | master would become a promotion freeze.
             */
            'pay scale with no level recorded is skipped, not failed' => [
                ServiceClass::II, null, null, ServiceClass::I, null, null, true, null,
            ],
            'a demotion is still caught when only the class is recorded' => [
                ServiceClass::I, null, null, ServiceClass::III, null, null, false, 'a demotion',
            ],
        ];
    }

    #[DataProvider('pairs')]
    public function test_the_hierarchy_is_validated_on_class_pay_scale_and_grade_pay(
        ServiceClass $fromClass,
        ?int $fromLevel,
        ?int $fromGradePay,
        ServiceClass $toClass,
        ?int $toLevel,
        ?int $toGradePay,
        bool $expectedValid,
        ?string $expectedFailureMentions,
    ): void {
        $verdict = (new PromotionHierarchyService)->verdict(
            $this->post($fromClass, $fromLevel, $fromGradePay),
            $this->post($toClass, $toLevel, $toGradePay),
        );

        $this->assertSame($expectedValid, $verdict['valid']);

        if ($expectedFailureMentions === null) {
            $this->assertSame([], $verdict['failures']);

            return;
        }

        $this->assertStringContainsString(
            $expectedFailureMentions,
            implode(' ', $verdict['failures']),
            'a refusal has to name which of the three comparisons refused it',
        );
    }

    /**
     * A skipped comparison is reported as skipped, not silently passed.
     *
     * This is the difference between "the pay scales were compared and the promotion
     * is upward" and "nobody recorded the levels". Both produce `valid: true`, and a
     * reader deciding whether to trust the verdict needs to know which happened.
     */
    public function test_a_verdict_says_which_comparisons_it_could_make(): void
    {
        $verdict = (new PromotionHierarchyService)->verdict(
            $this->post(ServiceClass::II, null, 4200),
            $this->post(ServiceClass::I, 12, null),
        );

        $this->assertTrue($verdict['valid']);
        $this->assertContains('class', $verdict['checked']);
        $this->assertContains('pay_scale', $verdict['skipped']);
        $this->assertContains('grade_pay', $verdict['skipped']);
    }

    /**
     * Every failure is reported, not just the first.
     *
     * An officer refused promotion is entitled to every ground at once; discovering
     * a second reason after answering the first is how an appeal is built.
     */
    public function test_all_three_failures_are_reported_together(): void
    {
        $verdict = (new PromotionHierarchyService)->verdict(
            $this->post(ServiceClass::I, 14, 6600),
            $this->post(ServiceClass::III, 10, 4200),
        );

        $this->assertFalse($verdict['valid']);
        $this->assertCount(3, $verdict['failures']);
    }

    private function post(ServiceClass $class, ?int $level, ?int $gradePay): DepartmentPost
    {
        $post = new DepartmentPost([
            'name_en' => 'Under Secretary',
            'class_level' => $class->value,
        ]);
        $post->class_level = $class->value;

        $scale = new PayScale(['level' => $level, 'grade_pay' => $gradePay]);
        $scale->level = $level;
        $scale->grade_pay = $gradePay;

        // Set the relation without touching a database: the service only reads it.
        $post->setRelation('payScale', $scale);

        return $post;
    }
}
