<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Authentication\Events\CredentialsIssued;
use App\Domain\Authentication\Events\OtpRequested;
use App\Domain\Authentication\Listeners\SendCredentialNotification;
use App\Domain\Authentication\Listeners\SendOtpNotification;
use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Policies\DpcAllotmentPolicy;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Policies\DpcProposalPolicy;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Domain\Shared\Listeners\SendWorkflowNotification;
use App\Enums\Feature;
use App\Integrations\Contracts\DirectoryProvider;
use App\Integrations\Contracts\HrmsProvider;
use App\Integrations\Contracts\SignatureProvider;
use App\Integrations\Contracts\SmsSender;
use App\Integrations\CSC\CscDigiSignProvider;
use App\Integrations\HRMS\RealHrmsProvider;
use App\Integrations\Mock\LogSmsSender;
use App\Integrations\Mock\MockHrmsProvider;
use App\Integrations\Mock\MockSignatureProvider;
use App\Integrations\Mock\SeedDirectoryProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Provider registration — the one place an external integration is
     * chosen (architect ruling, 2026-08-13).
     *
     * Every binding is driven by a `Feature` flag, and every flag defaults to
     * off, so the application boots and runs fully with no external system
     * connected. Business logic resolves the interface and never learns which
     * implementation it got; enabling a real integration later is an
     * environment variable, its credentials, and the line below — nothing
     * else.
     */
    public function register(): void
    {
        $this->app->bind(SmsSender::class, function (): SmsSender {
            // No real gateway has been contracted yet; the flag exists so
            // wiring one is configuration, not a code change.
            return Feature::SmsGateway->enabled()
                ? throw new \RuntimeException('FEATURE_SMS_GATEWAY is enabled but no SMS gateway provider is implemented. Set FEATURE_SMS_GATEWAY=false to log messages instead.')
                : new LogSmsSender;
        });

        $this->app->bind(SignatureProvider::class, function (): SignatureProvider {
            return Feature::CscDigiSign->enabled()
                ? new CscDigiSignProvider
                : new MockSignatureProvider;
        });

        $this->app->bind(HrmsProvider::class, function (): HrmsProvider {
            return Feature::Hrms->enabled()
                ? new RealHrmsProvider
                : new MockHrmsProvider;
        });

        $this->app->bind(DirectoryProvider::class, function (): DirectoryProvider {
            return Feature::LgdSync->enabled()
                ? throw new \RuntimeException('FEATURE_LGD_SYNC is enabled but no LGD API provider is implemented. Set FEATURE_LGD_SYNC=false to use the seeded district list.')
                : new SeedDirectoryProvider;
        });
    }

    public function boot(): void
    {
        // OTP and credential delivery are authentication infrastructure, not
        // the "notification matrix" feature — nobody can log in without them,
        // so FEATURE_NOTIFICATIONS does not gate them. Their transport is
        // governed by the SMS and e-mail gateway flags, which fall back to log
        // drivers rather than failing.
        Event::listen(OtpRequested::class, SendOtpNotification::class);
        Event::listen(CredentialsIssued::class, SendCredentialNotification::class);

        if (Feature::Notifications->enabled()) {
            Event::listen(WorkflowTransitioned::class, SendWorkflowNotification::class);
        }

        Gate::policy(DpcProposal::class, DpcProposalPolicy::class);
        Gate::policy(DpcAllotment::class, DpcAllotmentPolicy::class);

        $this->configureRateLimiting();
    }

    /**
     * P0 brute-force protection (2026-08-13 architect review) on the login
     * and OTP-verification endpoints. Limits are config-driven, never
     * hardcoded — see config/dpc.php `rate_limiting`.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $key = Str::lower((string) $request->input('login')).'|'.$request->ip();

            return Limit::perMinutes(
                max(1, (int) round(((int) config('dpc.rate_limiting.login.decay_seconds')) / 60)),
                (int) config('dpc.rate_limiting.login.max_attempts'),
            )->by($key)->response(fn (Request $request, array $headers) => back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => __('Too many login attempts. Please try again in a minute.')])
                ->withHeaders($headers));
        });

        RateLimiter::for('otp-verify', function (Request $request): Limit {
            $pending = $request->session()->get('pending_verification');
            $userId = is_array($pending) ? ($pending['user_id'] ?? 'anonymous') : 'anonymous';
            $key = 'otp-verify|'.$userId.'|'.$request->ip();

            return Limit::perMinutes(
                max(1, (int) round(((int) config('dpc.rate_limiting.otp_verify.decay_seconds')) / 60)),
                (int) config('dpc.rate_limiting.otp_verify.max_attempts'),
            )->by($key)->response(fn (Request $request, array $headers) => back()
                ->withErrors(['mobile_code' => __('Too many verification attempts. Please try again in a minute.')])
                ->withHeaders($headers));
        });

        // CALCULATE (M5 Phase 4). Keyed by user and proposal: one user working
        // on two proposals is not abuse, and two officers of the same
        // department must not throttle each other.
        RateLimiter::for('calculate', function (Request $request): Limit {
            // The route is behind `auth`, but the limiter is resolved without
            // assuming that: an unauthenticated request falls back to the IP
            // rather than throwing inside a rate limiter.
            $user = $request->user();
            $proposal = $request->route('dpcProposal');

            $key = 'calculate|'.($user instanceof User ? (string) $user->id : (string) $request->ip())
                .'|'.($proposal instanceof DpcProposal ? (string) $proposal->id : (string) $proposal);

            return Limit::perMinutes(
                max(1, (int) round(((int) config('dpc.rate_limiting.calculate.decay_seconds')) / 60)),
                (int) config('dpc.rate_limiting.calculate.max_attempts'),
            )->by($key)->response(fn (Request $request, array $headers) => back()
                ->withErrors(['calculate' => __('Too many calculations in a short time. Please wait a minute before recalculating.')])
                ->withHeaders($headers));
        });
    }
}
