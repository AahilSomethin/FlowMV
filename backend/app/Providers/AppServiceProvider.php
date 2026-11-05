<?php

namespace App\Providers;

use App\Services\Integrations\ClamAvDocumentScanner;
use App\Services\Integrations\DocumentScanner;
use App\Services\Integrations\EmailHttp;
use App\Services\Integrations\EmailProviders;
use App\Services\Integrations\EmailQaTransport;
use App\Services\Integrations\SlackApi;
use App\Services\Integrations\SlackQaTransport;
use App\Services\Integrations\SmsApi;
use App\Services\Integrations\SmsQaTransport;
use App\Services\Integrations\TelegramApi;
use App\Services\Integrations\TelegramQaTransport;
use App\Support\DemoEnvironment;
use App\Support\EnvironmentChecker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsApi::class, function () {
            $api = new SmsApi(new Factory);
            if (config('sms.simulated')) {
                app(SmsQaTransport::class)->attach($api);
            }

            return $api;
        });
        $this->app->singleton(SlackApi::class, function () {
            $api = new SlackApi(new Factory);
            if (config('slack.simulated')) {
                app(SlackQaTransport::class)->attach($api);
            }

            return $api;
        });
        $this->app->bind(DocumentScanner::class, function () {
            if (! config('email_integrations.simulated')) {
                return app(ClamAvDocumentScanner::class);
            }
            app(EmailQaTransport::class)->assertLocal();

            return new class implements DocumentScanner
            {
                public function scan(#[\SensitiveParameter] string $bytes): string
                {
                    return str_contains($bytes, 'FLOWMV_QA_MALWARE_MARKER') ? 'infected' : 'clean';
                }
            };
        });
        $this->app->singleton(EmailHttp::class, function () {
            $http = new EmailHttp(new Factory, app(EmailProviders::class));
            if (config('email_integrations.simulated')) {
                app(EmailQaTransport::class)->attach($http);
            }

            return $http;
        });
        $this->app->singleton(TelegramApi::class, function () {
            $api = new TelegramApi(new Factory);
            if (config('integrations.telegram_simulated')) {
                app(TelegramQaTransport::class)->attach($api);
            }

            return $api;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        EnvironmentChecker::assertValid();
        if ($this->app->environment('demo')) {
            app(DemoEnvironment::class)->assertOwned();
        }

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('integration-step-up', function (Request $request) {
            return Limit::perMinute(5)->by('integration-confirm:'.$request->user()?->getAuthIdentifier().'|'.$request->ip());
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        RateLimiter::for('invitations', function (Request $request) {
            return Limit::perMinute(10)->by('user:'.$request->user()?->getAuthIdentifier());
        });

        RateLimiter::for('invitation-accept', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->getAuthIdentifier().'|'.$request->ip());
        });

        RateLimiter::for('verification-send', function (Request $request) {
            return Limit::perMinute(6)->by('user:'.$request->user()?->getAuthIdentifier());
        });

        Event::listen(MessageSending::class, function (MessageSending $event): void {
            if (! $this->app->environment('local') || $this->app->runningUnitTests()) {
                return;
            }

            $body = $event->message->getHtmlBody();

            if (! is_string($body) || $body === '') {
                $body = $event->message->getTextBody();
            }

            if (! is_string($body) || $body === '') {
                return;
            }

            $directory = storage_path('framework/mail-preview');
            File::ensureDirectoryExists($directory);
            File::put($directory.DIRECTORY_SEPARATOR.now()->format('YmdHisv').'-'.Str::uuid().'.html', $body);
        });
    }
}
