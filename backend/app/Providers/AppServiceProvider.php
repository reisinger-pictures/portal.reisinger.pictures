<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use App\Mail\Transports\GmailRestTransport;
use App\Contracts\PricingStrategy;
use App\Models\Setting;
use App\Pricing\ScopeLicensingStrategy;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\CouponService;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(HtmlSanitizer::class, function ($app) {
            $config = (new HtmlSanitizerConfig())
                ->allowElement('p')
                ->allowElement('strong')
                ->allowElement('em')
                ->allowElement('u')
                ->allowElement('s')
                ->allowElement('ul')
                ->allowElement('ol')
                ->allowElement('li')
                ->allowElement('br')
                ->allowElement('a', ['href', 'title', 'target', 'rel'])
                ->allowElement('table')
                ->allowElement('thead')
                ->allowElement('tbody')
                ->allowElement('tr')
                ->allowElement('th')
                ->allowElement('td');
            
            return new HtmlSanitizer($config);
        });

        $this->app->bind(PricingStrategy::class, function ($app) {
            $strategy = Setting::where('key', 'pricing_strategy')
                ->where('brand', BrandRegistry::currentOrDefault())
                ->value('value') ?? 'scope_licensing';

            return match ($strategy) {
                'volume_licensing' => new VolumeLicensingStrategy(
                    $app->make(SettingResolver::class),
                    $app->make(CouponService::class)
                ),
                default => new ScopeLicensingStrategy(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-catalog', function ($user) {
            return $user->is_super_admin;
        });

        Gate::define('purchase-upgrades', function ($user) {
            $isClient = $user->roles()->where('name', \App\Enums\UserRole::CLIENT->value)->exists();
            $isPrivileged = $user->is_power_user || $user->is_admin || $user->is_super_admin || $user->is_photographer;
            return !$isClient || $isPrivileged;
        });

        Gate::define('purchase-on-invoice', function ($user) {
            $isClient = $user->roles()->where('name', \App\Enums\UserRole::CLIENT->value)->exists();
            return $isClient || $user->is_power_user || $user->is_admin || $user->is_super_admin;
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

        // Reset brand state before each queue job to prevent stale config carrying over
        // between jobs in long-running queue workers (php artisan queue:work).
        // Consumers like InvoiceMail::build() call BrandRegistry::set() explicitly, so they
        // remain unaffected.
        Queue::before(function () {
            BrandRegistry::reset();
        });
    }
}
