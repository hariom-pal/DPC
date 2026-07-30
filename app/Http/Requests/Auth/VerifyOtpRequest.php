<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $digits = 'digits:'.(int) config('dpc.otp.length');

        return [
            'email_code' => ['nullable', $digits],
            'mobile_code' => ['required', $digits],
        ];
    }
}
