<?php

namespace App\Providers;

use App\Support\EnvironmentChecker;
use Illuminate\Cache\RateLimiting\Limit;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        EnvironmentChecker::assertValid();

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
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
