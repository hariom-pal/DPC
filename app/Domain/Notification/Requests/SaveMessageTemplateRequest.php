<?php

declare(strict_types=1);

namespace App\Domain\Notification\Requests;

use App\Domain\Notification\Models\MessageTemplate;
use App\Domain\Notification\Templates\MessageTemplateCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Administrator's wording for one message on one channel (2026-11-12): only the
 * message's own placeholders, every placeholder it cannot do without, and a message
 * needed for access (the OTP, an invitation) never Inactive. An SMS's DLT content
 * template ID is optional when saving (2026-11-13).
 */
class SaveMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $email = $this->route('channel') === MessageTemplate::EMAIL;

        return [
            'subject' => $email ? ['required', 'string', 'max:255'] : ['prohibited'],
            'body' => ['required', 'string', 'max:'.($email ? 10000 : 1000)],
            // TRAI DLT template IDs are numeric (typically 19 digits).
            // Optional, Active or not (2026-11-13): a template may be saved before its DLT
            // ID is obtained. The Admin panel flags the missing ID, and the real gateway
            // still does not send a text without one.
            'dlt_template_id' => $email ? ['prohibited'] : ['nullable', 'regex:/^\d{10,30}$/'],
            'status' => ['required', Rule::in(MessageTemplate::STATUSES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $definition = MessageTemplateCatalog::find((string) $this->route('key'));

            if ($definition === null) {
                return;
            }

            $text = (string) $this->input('subject').' '.(string) $this->input('body');
            $unknown = array_diff(MessageTemplateCatalog::placeholdersIn($text), $definition['placeholders']);

            if ($unknown !== []) {
                $validator->errors()->add('body', __('admin.message_templates.unknown', ['names' => '{'.implode('}, {', $unknown).'}']));
            }

            $missing = array_diff($definition['required'], MessageTemplateCatalog::placeholdersIn((string) $this->input('body')));

            if ($missing !== []) {
                $validator->errors()->add('body', __('admin.message_templates.missing', ['names' => '{'.implode('}, {', $missing).'}']));
            }

            if ($definition['mandatory'] && $this->input('status') === MessageTemplate::INACTIVE) {
                $validator->errors()->add('status', __('admin.message_templates.mandatory'));
            }
        });
    }
}
