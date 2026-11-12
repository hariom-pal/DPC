<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\Models\MessageTemplate;
use App\Domain\Notification\Requests\SaveMessageTemplateRequest;
use App\Domain\Notification\Templates\MessageTemplateCatalog;
use App\Domain\Notification\Templates\MessageTemplates;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SMS and e-mail templates, kept by the CGPSC Administrator (2026-11-12). Without a
 * saved template a message goes out in the portal's built-in wording; "Restore"
 * deletes the saved one. Every change is audited.
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

    public function index(): View
    {
        $saved = MessageTemplate::query()->get()->keyBy(fn (MessageTemplate $t): string => $t->key.'|'.$t->channel);
        $rows = [];

        foreach (MessageTemplateCatalog::all() as $key => $definition) {
            foreach ($definition['channels'] as $channel) {
                $rows[] = ['key' => $key, 'channel' => $channel, 'definition' => $definition, 'saved' => $saved->get($key.'|'.$channel)];
            }
        }

        return view('admin.message-templates.index', ['rows' => $rows]);
    }

    public function edit(string $key, string $channel): View
    {
        $definition = $this->definition($key, $channel);
        $saved = MessageTemplate::query()->where('key', $key)->where('channel', $channel)->first();
        $default = MessageTemplateCatalog::defaultFor($key);
        $samples = array_intersect_key(self::SAMPLES, array_flip($definition['placeholders']));

        return view('admin.message-templates.form', [
            'key' => $key,
            'channel' => $channel,
            'definition' => $definition,
            'saved' => $saved,
            'default' => $default,
            'preview' => app(MessageTemplates::class)->render($key, $channel, $samples),
            'samples' => $samples,
        ]);
    }

    public function update(SaveMessageTemplateRequest $request, string $key, string $channel): RedirectResponse
    {
        $this->definition($key, $channel);

        MessageTemplate::query()->updateOrCreate(['key' => $key, 'channel' => $channel], [
            'subject' => $channel === MessageTemplate::EMAIL ? (string) $request->validated('subject') : null,
            'body' => (string) $request->validated('body'),
            'dlt_template_id' => $channel === MessageTemplate::SMS ? $request->validated('dlt_template_id') : null,
            'is_active' => (bool) $request->validated('is_active'),
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('admin.message-templates.edit', [$key, $channel])->with('status', __('admin.message_templates.saved'));
    }

    /** Restore the built-in wording. */
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
