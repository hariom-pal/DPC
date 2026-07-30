<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Events\CredentialsIssued;
use App\Events\OtpRequested;
use App\Listeners\SendCredentialNotification;
use App\Listeners\SendOtpNotification;
use App\Signature\Contracts\SignatureProvider;
use App\Signature\Providers\CscDigiSignProvider;
use App\Signature\Providers\MockSignatureProvider;
use App\Sms\LogSmsSender;
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
    }

    public function boot(): void
    {
        Event::listen(OtpRequested::class, SendOtpNotification::class);
        Event::listen(CredentialsIssued::class, SendCredentialNotification::class);
    }
}
