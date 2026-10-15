<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Domain\Master\Services\ClassLabelRepository;
use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The Class Master (change item 1) — how a service class is named and ordered.
 *
 * See the creating migration for the division of authority: the *code* set is
 * statutory and comes from {@see ServiceClass}; everything else on the row
 * belongs to the CGPSC Administrator.
 *
 * @property int $id
 * @property string $code
 * @property string $name_en
 * @property string|null $name_hi
 * @property int $sort_order
 * @property MasterStatus $status
 */
class ClassMaster extends Model implements Auditable
{
    use IsMaster;

    protected $table = 'service_classes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name_en',
        'name_hi',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        // Any write to this master discards the request's label memo, wherever the
        // write came from — the admin screen, a seeder, or a console command.
        // Hooking the model rather than the controller means a future caller
        // cannot forget.
        static::saved(static fn () => app(ClassLabelRepository::class)->flush());
        static::deleted(static fn () => app(ClassLabelRepository::class)->flush());
    }

    /**
     * The label to display for a class code, in the active locale.
     *
     * Kept here as the one entry point callers know about; the reading and the
     * memo live in {@see ClassLabelRepository}, which is request-scoped. It used
     * to be a static memo on this class, and the proposal-detail performance test
     * found why that was wrong — see the repository's own note.
     */
    public static function labelFor(string $code): string
    {
        return app(ClassLabelRepository::class)->labelFor($code);
    }

    /**
     * Discard the request's label memo.
     *
     * Kept as a passthrough because tests call it, and a test that had to know
     * about the repository to reset the model's cache would be a test coupled to
     * an implementation detail.
     */
    public static function flushCache(): void
    {
        app(ClassLabelRepository::class)->flush();
    }
}
