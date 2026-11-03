<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Master\Models\ClassMaster;
use App\Domain\Master\Services\ClassLabelRepository;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Exists;
use JsonSerializable;
use Stringable;
use ValueError;

/**
 * A service class (Class I / II / III, and any the Administrator adds).
 *
 * **The classes are the Class Master's since 2026-11-03.** The user asked for
 * "if a new one comes, it should be possible to add it". Until then this was an
 * enum: the code set was fixed and the master held only the labels.
 *
 * It keeps the enum's surface: `from()`, `tryFrom()`, `cases()`, `->value`,
 * `->label()`. One instance per code, so `===` still compares two classes. A code
 * is read from the master and never compiled in.
 *
 * **Level is the class's place in the hierarchy**: 1 is the highest, I = 1,
 * II = 2, III = 3. The promotion-hierarchy check reads it (a promotion goes to an
 * equal or lower number). The Class Master orders its list by it.
 *
 * **Eligibility rules for a new class** are keyed by class pair (`I-II`, …). A pair
 * the configuration does not name falls to the rules' own "all other cases" entry
 * (the PPT's शेष), as `RuleSet` always has.
 */
final class ServiceClass implements Castable, JsonSerializable, Stringable
{
    /** The statutory three, as the master is seeded with them. */
    public const STATUTORY = ['I' => 1, 'II' => 2, 'III' => 3];

    /** @var array<string, self> */
    private static array $instances = [];

    private function __construct(public readonly string $value) {}

    public static function from(string $code): self
    {
        $code = trim($code);

        if ($code === '') {
            throw new ValueError('A service class code cannot be empty.');
        }

        return self::$instances[$code] ??= new self($code);
    }

    public static function tryFrom(?string $code): ?self
    {
        return $code === null || trim($code) === '' ? null : self::from($code);
    }

    /**
     * The classes in use, highest first: what a form offers.
     *
     * @return list<self>
     */
    public static function cases(): array
    {
        return array_map(
            fn (ClassMaster $row): self => self::from($row->code),
            app(ClassLabelRepository::class)->inUse(),
        );
    }

    /**
     * Validation: a class in use in the master.
     */
    public static function rule(): Exists
    {
        return (new Exists('service_classes', 'code'))->withoutTrashed()->where('status', MasterStatus::Active->value);
    }

    /**
     * The administrator's name for this class in the active locale.
     *
     * Reads the Class Master through its per-request memo, so the thirty-odd view
     * sites that call this — several inside loops over a hundred officers — cost
     * one query for the whole request. Falls back to `Class-<code>` when the
     * master has no row, which is what a not-yet-seeded database and a unit test
     * both look like.
     */
    public function label(): string
    {
        return ClassMaster::labelFor($this->value);
    }

    /**
     * The class's place in the hierarchy, 1 highest; null when the master does not
     * say (a class it never held).
     */
    public function level(): ?int
    {
        // A pure unit test has no application, and so no master to read: the
        // statutory three keep their seeded levels there.
        if (! app()->bound('db')) {
            return self::STATUTORY[$this->value] ?? null;
        }

        return app(ClassLabelRepository::class)->levelFor($this->value);
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Stored as its code, as the enum was.
     *
     * @param  array<int, mixed>  $arguments
     * @return CastsAttributes<self|null, self|string|null>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            /**
             * @param  array<string, mixed>  $attributes
             */
            public function get(Model $model, string $key, mixed $value, array $attributes): ?ServiceClass
            {
                return ServiceClass::tryFrom($value === null ? null : (string) $value);
            }

            /**
             * @param  array<string, mixed>  $attributes
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): ?string
            {
                return match (true) {
                    $value instanceof ServiceClass => $value->value,
                    $value === null || $value === '' => null,
                    default => ServiceClass::from((string) $value)->value,
                };
            }
        };
    }
}
