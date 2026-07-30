<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Authentication\Events\CredentialsIssued;
use App\Domain\Authentication\Events\OtpRequested;
use App\Domain\Authentication\Listeners\SendCredentialNotification;
use App\Domain\Authentication\Listeners\SendOtpNotification;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Domain\Shared\Listeners\SendWorkflowNotification;
use App\Integrations\Contracts\DirectoryProvider;
use App\Integrations\Contracts\SignatureProvider;
use App\Integrations\Contracts\SmsSender;
use App\Integrations\CSC\CscDigiSignProvider;
use App\Integrations\Mock\LogSmsSender;
use App\Integrations\Mock\MockSignatureProvider;
use App\Integrations\Mock\SeedDirectoryProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsSender::class, function (): SmsSender {
            return match ((string) config('dpc.sms.driver')) {
                'log' => new LogSmsSender,
                default => throw new \RuntimeException('Unsupported SMS driver ['.config('dpc.sms.driver').'].'),
            };
        });

        $this->app->bind(SignatureProvider::class, function (): SignatureProvider {
            return match ((string) config('dpc.signature.provider')) {
                'mock' => new MockSignatureProvider,
                'csc_digisign' => new CscDigiSignProvider,
                default => throw new \RuntimeException('Unsupported signature provider ['.config('dpc.signature.provider').'].'),
            };
        });

        $this->app->bind(DirectoryProvider::class, function (): DirectoryProvider {
            return match ((string) config('dpc.directory.provider')) {
                'seed' => new SeedDirectoryProvider,
                default => throw new \RuntimeException('Unsupported directory provider ['.config('dpc.directory.provider').'].'),
            };
        });
    }

    public function boot(): void
    {
        Event::listen(OtpRequested::class, SendOtpNotification::class);
        Event::listen(CredentialsIssued::class, SendCredentialNotification::class);
        Event::listen(WorkflowTransitioned::class, SendWorkflowNotification::class);
    }
}
