<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Integrations\Contracts\DirectoryProvider;
use App\Integrations\Contracts\HrmsProvider;
use App\Integrations\Contracts\SignatureProvider;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Contracts\View\View;
use Throwable;

/**
 * Integration Status — what is switched on, which provider is actually bound,
 * and what happens while each integration is off.
 *
 * This exists because a feature flag that nobody can see is a flag nobody can
 * trust. Before a phased rollout, an operator has to be able to confirm from
 * the running application — not from a `.env` file they hope matches — that
 * production is signing through CSC and not through the mock. It enumerates
 * `Feature::cases()`, so a flag added later appears here without being
 * registered a second time.
 *
 * Read-only by design: flags are environment configuration and are not
 * editable from the UI, which keeps them auditable through deployment rather
 * than changeable by whoever is logged in.
 */
class IntegrationStatusController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.integrations.index', [
            'features' => Feature::all(),
            'boundProviders' => $this->boundProviders(),
        ]);
    }

    /**
     * The provider each contract actually resolves to right now. Resolution
     * is attempted rather than assumed: a flag turned on without its provider
     * implemented throws from the container, and that is exactly the state an
     * operator most needs to see reported.
     *
     * @return array<string, string>
     */
    private function boundProviders(): array
    {
        $contracts = [
            'Signature' => SignatureProvider::class,
            'HRMS' => HrmsProvider::class,
            'SMS' => SmsSender::class,
            'Directory (LGD)' => DirectoryProvider::class,
        ];

        $resolved = [];

        foreach ($contracts as $label => $contract) {
            try {
                $instance = app($contract);
                $resolved[$label] = method_exists($instance, 'name')
                    ? (string) $instance->name()
                    : class_basename($instance);
            } catch (Throwable $e) {
                $resolved[$label] = 'UNAVAILABLE — '.$e->getMessage();
            }
        }

        return $resolved;
    }
}
