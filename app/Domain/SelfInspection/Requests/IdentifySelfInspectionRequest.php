<?php

declare(strict_types=1);

namespace App\Domain\SelfInspection\Requests;

use App\Domain\SelfInspection\Enums\SelfInspectionAuthDriver;
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
        /*
         | Under the OTP driver the form is two boxes over two steps, not a set of
         | factors (business instruction, 2026-09-07). The mobile number comes
         | first; the code comes second, with the number held in the session — so
         | which of the two is being validated is decided by whether a code was
         | posted, not by a hidden field the caller controls.
         */
        if (SelfInspectionAuthDriver::configured()->usesOtp()) {
            // "Use a different number" posts neither, and validating either would
            // refuse the one action whose whole purpose is to clear them.
            if ($this->boolean('restart')) {
                return [];
            }

            return $this->has('code')
                ? ['code' => ['required', 'string', 'digits:6']]
                : ['mobile' => ['required', 'string', 'regex:/^[6-9]\\d{9}$/']];
        }

        $rules = [];

        foreach (SelfInspectionFactor::configured() as $factor) {
            $rules[$factor->value] = $factor->validationRules();
        }

        return $rules;
    }

    /**
     * Whether this submission is the second step — an officer entering the code.
     */
    public function isCodeStep(): bool
    {
        return SelfInspectionAuthDriver::configured()->usesOtp() && $this->has('code');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        if (SelfInspectionAuthDriver::configured()->usesOtp()) {
            return [
                'mobile' => (string) __('selfinspection.otp.mobile_label'),
                'code' => (string) __('selfinspection.otp.code_label'),
            ];
        }

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
