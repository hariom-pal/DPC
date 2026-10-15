<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * A public notice (suggestion 6).
 *
 * The Hindi columns are optional on purpose: a notice is often issued in English
 * first and translated after, and refusing to publish it until the translation
 * exists would mean the notice is not published. {@see PortalNotice::title()}
 * falls back rather than showing a blank.
 */
class PortalNoticeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title_en' => ['required', 'string', 'max:255'],
            'title_hi' => ['nullable', 'string', 'max:255'],
            'body_en' => ['required', 'string', 'max:2000'],
            'body_hi' => ['nullable', 'string', 'max:2000'],

            'published_from' => ['required', 'date'],

            // Not `after:published_from`: a notice published for a single day has
            // the same date on both, and that is a legitimate one-day notice.
            'published_until' => ['nullable', 'date', 'after_or_equal:published_from'],

            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title_en' => __('admin.portal_notices.form.title_en'),
            'body_en' => __('admin.portal_notices.form.body_en'),
            'published_from' => __('admin.portal_notices.form.published_from'),
            'published_until' => __('admin.portal_notices.form.published_until'),
        ];
    }
}
