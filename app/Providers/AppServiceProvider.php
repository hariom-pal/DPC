<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Authentication\Events\CredentialsIssued;
use App\Domain\Authentication\Events\OtpRequested;
use App\Domain\Authentication\Listeners\SendCredentialNotification;
use App\Domain\Authentication\Listeners\SendOtpNotification;
use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Events\DpcSittingScheduled;
use App\Domain\Cgpsc\Listeners\AnnounceSittingToParticipants;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Policies\DpcAllotmentPolicy;
use App\Domain\Circulation\Documents\CirculationDocumentRenderer;
use App\Domain\Circulation\Documents\HtmlCirculationDocumentRenderer;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Policies\DpcCirculationPolicy;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Policies\DpcProposalPolicy;
use App\Domain\Master\Services\ClassLabelRepository;
use App\Domain\Notification\Listeners\RecordInAppNotification;
use App\Domain\Notification\Listeners\RecordPublicationNotification;
use App\Domain\Notification\Listeners\RecordSupersessionNotification;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Policies\PreDpcProposalPolicy;
use App\Domain\Proceeding\Documents\DocumentRenderer;
use App\Domain\Proceeding\Events\ProceedingDocumentsPublished;
use App\Domain\Proceeding\Listeners\SendDocumentPublicationNotification;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Policies\DpcProceedingPolicy;
use App\Domain\Proceeding\Services\ProceedingCalculationService;
use App\Domain\Proceeding\Services\ProceedingDocumentService;
use App\Domain\Proceeding\Services\ResultApprovalService;
use App\Domain\Review\Documents\ReviewAwareDocumentRenderer;
use App\Domain\Review\Events\DpcSupersededByReview;
use App\Domain\Review\Listeners\SendSupersessionNotification;
use App\Domain\Review\Services\ReviewAwareApprovalService;
use App\Domain\Review\Services\ReviewAwareCalculationService;
use App\Domain\Review\Services\ReviewAwareDocumentService;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Domain\Shared\Listeners\SendWorkflowNotification;
use App\Enums\Feature;
use App\Http\View\Composers\NotificationBellComposer;
use App\Http\View\Composers\PortalNoticeComposer;
use App\Http\View\Composers\PortalStatisticsComposer;
use App\Http\View\Composers\ReviewDpcComposer;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
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
        /*
         | The Class Master's labels (change item 1), memoised **per request**.
         |
         | `scoped()` rather than `singleton()`, and that distinction is the whole
         | reason this binding exists rather than a static on the model. A
         | singleton — or a static — outlives the request under a long-lived worker
         | (Octane, or a queue worker rendering documents), so an administrator's
         | rename would not appear until the process recycled. Laravel flushes
         | scoped instances at every request boundary.
         |
         | The memo itself is not optional: `ServiceClass::label()` is called from
         | roughly thirty view sites, several inside loops over a hundred officers.
         */
        $this->app->scoped(ClassLabelRepository::class);

        // The proceedings-document renderer, behind a contract so PDF can
        // later replace HTML without touching the approval path (AQ-3).
        // Bound like every other provider: business logic depends on the
        // contract, never on the implementation.

        // A Review DPC calculates under the original's rules (AQ-11). Added
        // as a decorator over M7's service rather than inside it: M7 is
        // complete and its freeze verification must stay empty. A Fresh DPC
        // passes straight through unchanged.
        $this->app->bind(ProceedingCalculationService::class, ReviewAwareCalculationService::class);

        // Supersession takes legal effect at approval (AQ-9); Review documents
        // state what they correct (AQ-13); a superseded DPC's documents stay
        // downloadable, watermarked on delivery. All three by decoration —
        // M7's files stay untouched (AD-20).
        $this->app->bind(ResultApprovalService::class, ReviewAwareApprovalService::class);
        $this->app->bind(ProceedingDocumentService::class, ReviewAwareDocumentService::class);
        $this->app->bind(DocumentRenderer::class, ReviewAwareDocumentRenderer::class);

        // M9 Phase 3. **M9's own renderer, by architect ruling (AQ-22)** —
        // M7's `DocumentRenderer` is hard-typed to `DpcProceeding` and is
        // neither implemented nor decorated here. Bound to the contract so a
        // PDF renderer can replace the HTML one later without touching the
        // pipeline (AQ-3's reasoning, applied to M9).
        $this->app->bind(CirculationDocumentRenderer::class, HtmlCirculationDocumentRenderer::class);

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
        // Twice during M7 a newly added column silently failed to reach its
        // model — `update()` discards attributes that are not fillable and
        // reports success, so the write is lost without an error anywhere.
        // Outside production that is now a loud failure rather than a silent
        // one. Deliberately not enabled in production: a request that would
        // have half-worked should not become a 500 in front of a user.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        /*
         | Pagination renders as Bootstrap 5, because that is what the portal is
         | built in (reported 2026-09-06: *"pagination in Manage Users is
         | distorted"*).
         |
         | Laravel's default paginator markup is Tailwind, and Tailwind is not on any
         | page here — so `{{ $users->links() }}` emitted a column of unstyled
         | fragments on **every** index screen in the admin area. Manage Users is
         | simply the first list long enough to paginate, which is why it was noticed
         | there.
         |
         | Set once here rather than by publishing the views: there is nothing to
         | customise, and a published copy is one more thing to keep in step with the
         | framework.
         */
        Paginator::useBootstrapFive();

        // OTP and credential delivery are authentication infrastructure, not
        // the "notification matrix" feature — nobody can log in without them,
        // so FEATURE_NOTIFICATIONS does not gate them. Their transport is
        // governed by the SMS and e-mail gateway flags, which fall back to log
        // drivers rather than failing.
        Event::listen(OtpRequested::class, SendOtpNotification::class);
        Event::listen(CredentialsIssued::class, SendCredentialNotification::class);

        // M11's in-app channel. A **second** listener on the same event rather
        // than a change to M6's — AD-20's pattern, applied to a subscriber.
        //
        // Registered outside `Feature::Notifications`, and that is the point:
        // the flag gates *external* delivery, which needs a procured gateway
        // (TD-6) and a phased rollout. An in-app record calls nothing outside the
        // application, so gating it behind the integration flag would switch off
        // the only channel that always works.
        Event::listen(WorkflowTransitioned::class, RecordInAppNotification::class);
        // Publication and supersession are deliberately NOT workflow transitions
        // (M7 Phase 4 and M8 Phase 3 both reasoned that a fake transition would
        // have the ledger claim a state change that never happened), so each needs
        // its own in-app listener beside its own SMS one.
        Event::listen(ProceedingDocumentsPublished::class, RecordPublicationNotification::class);
        Event::listen(DpcSupersededByReview::class, RecordSupersessionNotification::class);

        /*
         | Announcing a sitting to the committee and the candidates (business
         | clarification, 2026-09-05).
         |
         | **Registered outside `Feature::Notifications`, unlike the SMS listeners
         | below it, and the reason is the same one the in-app listeners above give.**
         | This one listener carries both channels: it writes the in-app record —
         | which calls nothing outside the application — and attempts SMS and
         | e-mail through the `SmsSender` and `Mail` bindings, both of which fall
         | back to log drivers when no gateway is procured. Gating the whole
         | listener on the integration flag would switch off the in-app copy too,
         | which is the channel that always works and the only one a candidate can
         | actually receive today.
         */
        Event::listen(DpcSittingScheduled::class, AnnounceSittingToParticipants::class);

        if (Feature::Notifications->enabled()) {
            Event::listen(WorkflowTransitioned::class, SendWorkflowNotification::class);
            Event::listen(ProceedingDocumentsPublished::class, SendDocumentPublicationNotification::class);
            Event::listen(DpcSupersededByReview::class, SendSupersessionNotification::class);
        }

        Gate::policy(DpcProposal::class, DpcProposalPolicy::class);
        Gate::policy(DpcAllotment::class, DpcAllotmentPolicy::class);
        Gate::policy(DpcProceeding::class, DpcProceedingPolicy::class);
        // M9 Phase 1. A Circulation is its own aggregate (AD-24), so it has
        // its own policy rather than borrowing the proposal's.
        Gate::policy(DpcCirculation::class, DpcCirculationPolicy::class);
        // M3 predates the Policy convention; registered 2026-08-18 closing TD-26,
        // so the Pre-DPC views can ask the same question the controller asks.
        Gate::policy(PreDpcProposal::class, PreDpcProposalPolicy::class);

        // Architect Directive 3 (M8): the Review banner reaches M4's frozen
        // proposal screens from the layout rather than by editing each one.
        View::composer('layouts.app', ReviewDpcComposer::class);

        // The notification bell renders on every authenticated screen, several of
        // which belong to frozen milestones — so it reaches them from the layout
        // rather than through thirty controllers (M11).
        View::composer(['layouts.app', 'layouts.admin'], NotificationBellComposer::class);

        // Live counts for the public landing page. Presentation wiring only —
        // no business logic, no policy, and guarded so a database or cache
        // failure can never take down the front door.
        View::composer('welcome', PortalStatisticsComposer::class);

        // Suggestion 6. The notice board is master data now; the composer falls
        // back to the catalogue so an installation that has published nothing
        // still shows a notice board rather than an empty box.
        View::composer('welcome', PortalNoticeComposer::class);

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

        // Self-Inspection (M10, S50). The strictest limiter in the application,
        // and the one that most needs to be: it is the only endpoint an
        // unauthenticated caller can reach with officer data behind it, and the
        // factors that open it — a name and two dates — all appear on a
        // departmental seniority list. Keyed on the claimed name and the IP, so
        // guessing dates against one officer does not lock out another.
        RateLimiter::for('self-inspection', function (Request $request): Limit {
            $key = 'self-inspection|'
                .Str::lower(Str::squish((string) $request->input('name')))
                .'|'.$request->ip();

            return Limit::perMinutes(
                max(1, (int) round(((int) config('dpc.self_inspection.rate_limit.decay_seconds')) / 60)),
                (int) config('dpc.self_inspection.rate_limit.max_attempts'),
            )->by($key)->response(fn (Request $request, array $headers) => back()
                ->withErrors(['self_inspection' => __('Too many attempts. Please try again later, or contact your department.')])
                ->withHeaders($headers));
        });
    }
}
