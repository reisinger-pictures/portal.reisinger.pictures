<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Mail;
use App\Mail\Transports\GmailRestTransport;
use Illuminate\Support\Facades\Gate;

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
        Gate::define('manage-catalog', function ($user) {
            return $user->is_super_admin;
        });

        \Illuminate\Support\Facades\Auth::provider('transient_eloquent', function ($app, array $config) {
            return new \App\Auth\TransientUserProvider($app['hash'], $config['model']);
        });
        // Den neuen Custom Transport in Laravel's Mail-Manager integrieren
        Mail::extend('gmail_rest', function (array $config) {
            return new GmailRestTransport(
                $config['client_id'] ?? env('OAUTH_CLIENT_ID'),
                $config['client_secret'] ?? env('OAUTH_CLIENT_SECRET'),
                $config['refresh_token'] ?? env('OAUTH_REFRESH_TOKEN')
            );
        });
    }
}
