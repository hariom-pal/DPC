<?php

declare(strict_types=1);

namespace App\Domain\SelfInspection\Requests;

use App\Domain\SelfInspection\Enums\SelfInspectionFactor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The S50 identification form.
 *
 * **The field list is derived from configuration, not written out here.** The
 * factors an officer must supply are `dpc.self_inspection.authentication.factors`
 * (BD-19), so validation is generated from the same source the matcher reads —
 * otherwise a factor added to configuration would be required by one and ignored
 * by the other, and the screen would refuse an officer who answered it correctly.
 */
class IdentifySelfInspectionRequest extends FormRequest
{
    /**
     * Open by design — this is the one screen an individual reaches without an
     * account. Whether the facility is available at all is decided by
     * `SelfInspectionAccessGuard` and the configured driver, not here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (SelfInspectionFactor::configured() as $factor) {
            $rules[$factor->value] = $factor->validationRules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (SelfInspectionFactor::configured() as $factor) {
            $attributes[$factor->value] = $factor->label();
        }

        return $attributes;
    }

    /**
     * The answers, keyed by factor.
     *
     * @return array<string, string>
     */
    public function answers(): array
    {
        $answers = [];

        foreach (SelfInspectionFactor::configured() as $factor) {
            $answers[$factor->value] = (string) $this->validated($factor->value, '');
        }

        return $answers;
    }
}
