<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Domain\Master\Models\Designation;
use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class DepartmentPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The post name comes from the **Designation master**, never typed
     * (DPC-MODULE-FIX-001, second pass — business, 2026-09-06:
     * *"Department Post Mapping, why is post name free text? We already discussed
     * it should be from the master"*).
     *
     * **Resolved into `name` here rather than stored as a foreign key**, and that is
     * deliberate. `department_posts.name` is what a superseded mapping keeps, what
     * the three officer-snapshot tables copy, and what the CSV importers match on —
     * a column read by half the application. Turning it into an id would be the
     * database redesign the instruction rules out, and would let a rename rewrite a
     * concluded proposal. So the *screen* posts an id, and the row keeps the name it
     * has always kept.
     *
     * Merging it before validation means every rule below — `required`, `max:255`,
     * and the uniqueness check on mappings in force — keeps working unchanged and
     * keeps reporting against the field the officer is looking at.
     *
     * A payload carrying `name` and no `designation_id` still validates, which is
     * what the seeders and any mapping recorded before this change rely on.
     */
    protected function prepareForValidation(): void
    {
        $id = $this->input('designation_id');

        if (! is_numeric($id)) {
            return;
        }

        $name = Designation::query()
            ->where('status', MasterStatus::Active)
            ->whereKey((int) $id)
            ->value('name');

        if ($name !== null) {
            $this->merge(['name' => (string) $name]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $id = $this->route('post')?->id;

        return [
            // A Department Admin maps posts for their own department only (2026-10-28).
            'department_id' => array_merge(['required', Rule::exists('departments', 'id')], $this->routeIs('department.*')
                ? [Rule::in([(int) $this->user()?->department_id])]
                : []),
            'name' => [
                'required', 'string', 'max:255',
                /*
                 | Unique among the mappings **in force**, not among all of them.
                 |
                 | Superseded mappings keep the same post name by design — that is
                 | what makes them a chain — so a plain uniqueness check would
                 | refuse to register a post whose earlier mapping was replaced
                 | years ago.
                 */
                Rule::unique('department_posts', 'name')
                    ->where('department_id', (int) $this->input('department_id'))
                    ->whereNotNull('active_flag')
                    ->ignore($id),
            ],
            'class_level' => ['required', new Enum(ServiceClass::class)],
            // Required (MoM of 2026-10-29): a Pre-DPC promotes only to a higher
            // pay scale, so every post's class and scale must be mapped.
            'pay_scale_id' => ['required', Rule::exists('pay_scales', 'id')],

            /*
             | Promotion Duration — the qualifying service a feeder must have on
             | the post before it can be promoted out of (item 4).
             |
             | Nullable, because the master carries posts registered before this
             | field existed and a default would be a business rule nobody set.
             | Bounded at 40 because a promotion duration longer than a career is
             | a typing error, not a rule; the lower bound is 0 rather than 1
             | because a post filled entirely by direct recruitment has no
             | qualifying period at all.
             */
            'promotion_duration_years' => ['nullable', 'integer', 'min:0', 'max:40'],

            /*
             | No sanctioned strength here (2026-10-17): it can change year by year
             | on its own order, so the Vacancy Master records it for each Vacancy
             | Year ({@see \App\Domain\Dpc\Services\SanctionedStrengthSource}).
             */

            'status' => ['required', new Enum(MasterStatus::class)],

            /*
             | The Government Orders that bear on this mapping (change register of
             | 2026-08-26, item 10). **Plural**, with Add More.
             |
             | The list as a whole is nullable, because the master holds mappings
             | registered before orders were captured and refusing to save one of
             | those would make the field impossible to add. A mapping *created*
             | from now on should carry at least one, and the screen says so — but
             | the rule is left to the Commission rather than enforced here, because
             | "every mapping must cite an order" is a business rule and not this
             | form's to invent.
             |
             | Within a row the number and the date go together: an order with a
             | date and no number cannot be cited, and one with a number and no date
             | cannot be placed in a sequence. `required_with` in both directions
             | says exactly that, and says it per row.
             */
            'orders' => ['nullable', 'array', 'max:20'],
            'orders.*.government_order_number' => [
                'nullable', 'string', 'max:120',
                'required_with:orders.*.government_order_date',
            ],
            'orders.*.government_order_date' => [
                'nullable', 'date', 'before_or_equal:today',
                'required_with:orders.*.government_order_number',
            ],

            /*
             | PDF only, and checked three ways — see the note on the single-file
             | field below, which this repeats per row.
             */
            'orders.*.document' => [
                'nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'extensions:pdf', 'max:10240',
            ],

            // A mapping is effective-dated. A new one defaults to today on the
            // form, and a back-dated one is the Administrator's explicit choice.
            'effective_from' => ['required', 'date'],

            /*
             | PDF only, and checked three ways.
             |
             | `mimes` reads the extension, `mimetypes` reads what the browser
             | claimed, and `extensions` refuses a mismatch between the two — a
             | `.pdf` that is really something else is exactly what an upload field
             | on a government portal is probed with.
             */
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
            'name.unique' => 'This department already has a post with that name. '
                .'The same post name in a *different* department is fine — they are different posts.',
            'promotion_duration_years.max' => 'Promotion Duration is in years, and 40 is already longer than a career. Check the figure.',
            'government_order_document.mimes' => 'The Government Order must be a PDF.',
            'government_order_document.mimetypes' => 'The Government Order must be a PDF.',
            'government_order_document.extensions' => 'The Government Order must be a PDF.',
            'government_order_document.max' => 'The Government Order must be 10 MB or smaller.',
            'orders.*.government_order_number.required_with' => 'Give the Government Order number as well as its date.',
            'orders.*.government_order_date.required_with' => 'Give the Government Order date as well as its number.',
            'orders.*.document.mimes' => 'Each Government Order must be a PDF.',
            'orders.*.document.mimetypes' => 'Each Government Order must be a PDF.',
            'orders.*.document.extensions' => 'Each Government Order must be a PDF.',
            'orders.*.document.max' => 'Each Government Order must be 10 MB or smaller.',
        ];
    }

    /**
     * The Government Orders this submission actually carries, keyed by the row
     * index the file input uses.
     *
     * **Rows with no number are dropped here rather than refused.** The repeater
     * renders one empty row so there is always somewhere to type, and an empty row
     * means "no further order" — not a mistake. Keying the result by the *original*
     * index matters: the uploads arrive as `orders.3.document`, so re-indexing would
     * pair an order with another row's file.
     *
     * @return array<int, array{government_order_number: string, government_order_date: Carbon}>
     */
    public function orders(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->validated()['orders'] ?? [];
        $orders = [];

        foreach ($rows as $index => $row) {
            $number = trim((string) ($row['government_order_number'] ?? ''));

            if ($number === '' || ($row['government_order_date'] ?? null) === null) {
                continue;
            }

            $orders[(int) $index] = [
                'government_order_number' => $number,
                'government_order_date' => Carbon::parse((string) $row['government_order_date']),
            ];
        }

        return $orders;
    }
}
