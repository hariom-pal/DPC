<?php

declare(strict_types=1);

namespace App\Domain\Import\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parsed spreadsheet row staged for review — the Import Log the architect
 * directive calls for (M4 Phase 6).
 *
 * `payload` is the row already mapped into the exact array shape the
 * DpcProposalService save method accepts, so committing is a hand-off rather
 * than a second implementation. `errors` holds the validation messages for a
 * rejected row and drives the downloadable error report.
 *
 * @property int $id
 * @property int $import_batch_id
 * @property int $row_number
 * @property array<string, mixed>|null $payload
 * @property array<string, list<string>>|null $errors
 * @property bool $is_valid
 */
class ImportRow extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'import_batch_id',
        'row_number',
        'payload',
        'errors',
        'is_valid',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'payload' => 'array',
            'errors' => 'array',
            'is_valid' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    /**
     * Flattened error messages, for the preview screen and error report.
     *
     * @return list<string>
     */
    public function errorMessages(): array
    {
        $messages = [];

        foreach ($this->errors ?? [] as $fieldMessages) {
            foreach ($fieldMessages as $message) {
                $messages[] = $message;
            }
        }

        return array_values(array_unique($messages));
    }
}
