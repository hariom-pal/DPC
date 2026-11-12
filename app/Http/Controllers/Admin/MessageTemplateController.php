<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\Models\MessageTemplate;
use App\Domain\Notification\Models\SmsDefaultDltId;
use App\Domain\Notification\Requests\SaveMessageTemplateRequest;
use App\Domain\Notification\Templates\MessageTemplateCatalog;
use App\Domain\Notification\Templates\MessageTemplates;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SMS and e-mail templates, kept by the CGPSC Administrator (2026-11-12), each
 * channel on its own screen (2026-11-13). A template is Draft, Active or Inactive;
 * without an Active one a message goes out in the portal's default wording, and
 * "Restore default" deletes the saved one. Every change is audited.
 */
class MessageTemplateController extends Controller
{
    /** Sample values the preview fills the placeholders with. */
    private const SAMPLES = [
        'code' => '482913', 'minutes' => '10', 'login' => 'officer@cg.gov.in', 'url' => 'https://odpc.cgpsc.gov.in/login',
        'department' => 'Public Health Engineering Department', 'hours' => '72', 'link' => 'https://odpc.cgpsc.gov.in/link',
        'role' => 'Authorized Officer', 'dpc' => 'DPC-2026-PHE-0001', 'date' => '15-12-2026', 'time' => '11:00',
        'instructions' => 'Please attend on time.', 'number' => 'F-2/2026/PHE/PROMO', 'post' => 'Executive Engineer',
        'original' => 'DPC-2026-PHE-0001', 'review' => 'DPC-2026-PHE-0002', 'review_number' => '1',
        'workflow' => 'dpc_proposal', 'event' => 'approve', 'status' => 'approved', 'remarks' => ' Remarks: As discussed.',
    ];

    public function index(string $channel): View
    {
        abort_unless(in_array($channel, [MessageTemplate::SMS, MessageTemplate::EMAIL], true), 404);

        $saved = MessageTemplate::query()->where('channel', $channel)->get()->keyBy('key');
        $rows = [];

        $templates = app(MessageTemplates::class);

        foreach (MessageTemplateCatalog::all() as $key => $definition) {
            if (in_array($channel, $definition['channels'], true)) {
                // The text that goes out now, and its DLT ID (2026-11-13).
                $rows[] = ['key' => $key, 'channel' => $channel, 'definition' => $definition, 'saved' => $saved->get($key), 'source' => $templates->source($key, $channel)];
            }
        }

        // SMS texts that would go out without a DLT Content Template ID.
        $missingDlt = $channel === MessageTemplate::SMS
            ? array_values(array_filter($rows, fn (array $row): bool => $row['source'] !== null && $row['source']['dlt_template_id'] === null))
            : [];

        return view('admin.message-templates.index', ['rows' => $rows, 'channel' => $channel, 'missingDlt' => $missingDlt]);
    }

    public function edit(string $key, string $channel): View
    {
        $definition = $this->definition($key, $channel);
        $saved = MessageTemplate::query()->where('key', $key)->where('channel', $channel)->first();
        $default = MessageTemplateCatalog::defaultFor($key, $channel);
        $samples = array_intersect_key(self::SAMPLES, array_flip($definition['placeholders']));

        return view('admin.message-templates.form', [
            'key' => $key,
            'channel' => $channel,
            'definition' => $definition,
            'saved' => $saved,
            'default' => $default,
            'preview' => app(MessageTemplates::class)->render($key, $channel, $samples),
            'samples' => $samples,
            'source' => app(MessageTemplates::class)->source($key, $channel),
            'defaultDltId' => $channel === MessageTemplate::SMS ? app(MessageTemplates::class)->defaultDltId($key) : null,
        ]);
    }

    public function update(SaveMessageTemplateRequest $request, string $key, string $channel): RedirectResponse
    {
        $this->definition($key, $channel);

        MessageTemplate::query()->updateOrCreate(['key' => $key, 'channel' => $channel], [
            'subject' => $channel === MessageTemplate::EMAIL ? (string) $request->validated('subject') : null,
            'body' => (string) $request->validated('body'),
            'dlt_template_id' => $channel === MessageTemplate::SMS ? $request->validated('dlt_template_id') : null,
            'status' => (string) $request->validated('status'),
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('admin.message-templates.edit', [$key, $channel])->with('status', __('admin.message_templates.saved'));
    }

    /**
     * The DLT Content Template ID of a message's System default SMS text (2026-11-13):
     * saved against the text the application holds, without copying or changing it.
     * Blank removes it.
     */
    public function updateDefaultDlt(Request $request, string $key): RedirectResponse
    {
        $this->definition($key, MessageTemplate::SMS);
        $id = $request->validate([
            'default_dlt_template_id' => ['nullable', 'regex:/^\d{10,30}$/'],
        ], ['default_dlt_template_id.regex' => (string) __('admin.message_templates.dlt_format')])['default_dlt_template_id'] ?? null;

        if ($id === null || $id === '') {
            SmsDefaultDltId::query()->where('key', $key)->first()?->delete();
        } else {
            SmsDefaultDltId::query()->updateOrCreate(['key' => $key], ['dlt_template_id' => (string) $id, 'updated_by' => $request->user()?->id]);
        }

        return redirect()->route('admin.message-templates.edit', [$key, MessageTemplate::SMS])->with('status', __('admin.message_templates.default_dlt_saved'));
    }

    /** Restore the System default text. */
    public function destroy(Request $request, string $key, string $channel): RedirectResponse
    {
        $this->definition($key, $channel);
        MessageTemplate::query()->where('key', $key)->where('channel', $channel)->first()?->delete();

        return redirect()->route('admin.message-templates.edit', [$key, $channel])->with('status', __('admin.message_templates.restored'));
    }

    /**
     * @return array{label: string, when: string, channels: list<string>, placeholders: list<string>, required: list<string>, mandatory: bool, default: callable(array<string, string>): array{subject: string, body: string}}
     */
    private function definition(string $key, string $channel): array
    {
        $definition = MessageTemplateCatalog::find($key);
        abort_if($definition === null || ! in_array($channel, $definition['channels'], true), 404);

        return $definition;
    }
}
