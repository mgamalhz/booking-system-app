<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        Telescope::filter(fn (IncomingEntry $entry): bool => app()->environment('local')
            || $entry->isReportableException()
            || $entry->isFailedRequest()
            || $entry->isFailedJob()
            || $entry->hasMonitoredTag());
    }

    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters([
            '_token', 'password', 'password_confirmation', 'token', 'api_token',
            'access_token', 'refresh_token', 'authorization',
        ]);

        Telescope::hideRequestHeaders([
            'authorization', 'cookie', 'x-api-key', 'x-csrf-token', 'x-xsrf-token',
        ]);
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', fn ($user = null): bool => $user !== null
            && in_array(strtolower((string) $user->email), array_map(
                'strtolower',
                array_filter(array_map('trim', explode(',', (string) config('telescope.allowed_emails'))))
            ), true));
    }

    protected function authorization(): void
    {
        $this->gate();
        Telescope::auth(fn ($request): bool => Gate::check('viewTelescope', [$request->user()]));
    }
}
