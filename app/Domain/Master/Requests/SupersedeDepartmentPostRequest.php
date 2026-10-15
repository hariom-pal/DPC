<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\ServiceClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * A new Government Order replacing a Department Post Mapping.
 *
 * **Neither the department nor the post name is accepted.** A supersession
 * re-authorises *the same post in the same department* on new terms; taking either
 * from the request would let one order quietly turn into a different mapping, and
 * the chain the effective dating exists to preserve would break. Both are read from
 * the mapping being superseded — see `DepartmentPostMappingService::supersede()`.
 *
 * **The order number is required here and optional on the ordinary form**, and the
 * difference is the point: a mapping registered before orders were captured has
 * none and cannot be given one honestly, but a supersession *is* a new order, so
 * there is always one to cite.
 */
class SupersedeDepartmentPostRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'class_level' => ['required', new Enum(ServiceClass::class)],
            'pay_scale_id' => ['nullable', Rule::exists('pay_scales', 'id')],
            'promotion_duration_years' => ['nullable', 'integer', 'min:0', 'max:40'],

            /*
             | Sanctioned Strength travels with the supersession (correction of
             | 2026-09-06).
             |
             | **A superseding order re-authorises the post on new terms, and the
             | sanctioned strength is one of those terms** — a Government Order
             | that changes an establishment is the commonest reason to supersede a
             | mapping at all. Before this it was absent from the payload *and*
             | from `DepartmentPostMappingService::supersede()`, so superseding a
             | mapping silently dropped the figure to `NULL` and broke every DPC
             | that depended on it. Nothing surfaced that, because the field could
             | not be set anywhere either.
             |
             | The form pre-fills it from the mapping being replaced, so an order
             | that changes only the pay scale carries the strength through
             | unchanged rather than requiring it to be retyped from memory.
             */
            'sanctioned_strength' => ['required', 'integer', 'min:1', 'max:100000'],

            'government_order_number' => ['required', 'string', 'max:120'],
            'government_order_date' => ['required', 'date', 'before_or_equal:today'],

            // Whether it is after the mapping it replaces is checked by the
            // service, which holds the incumbent under a write lock and is
            // therefore the only place that can answer it without a race.
            'effective_from' => ['required', 'date'],

            'government_order_document' => [
                'nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'extensions:pdf', 'max:10240',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'government_order_number.required' => 'A supersession is a new Government Order, so its number is required.',
            'government_order_date.required' => 'A supersession is a new Government Order, so its date is required.',
            'government_order_document.mimes' => 'The Government Order must be a PDF.',
            'government_order_document.mimetypes' => 'The Government Order must be a PDF.',
            'government_order_document.extensions' => 'The Government Order must be a PDF.',
            'government_order_document.max' => 'The Government Order must be 10 MB or smaller.',
        ];
    }
}
