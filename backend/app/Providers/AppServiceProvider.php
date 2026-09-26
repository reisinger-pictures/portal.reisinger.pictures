<?php

namespace App\Providers;

use App\Auth\TransientUserProvider;
use App\Contracts\PricingStrategy;
use App\Enums\UserRole;
use App\Mail\Transports\GmailRestTransport;
use App\Models\Customer;
use App\Models\Setting;
use App\Observers\CustomerObserver;
use App\Pricing\ScopeLicensingStrategy;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\AuthorizationService;
use App\Services\CouponService;
use App\Services\VolumePresetService;
use App\Support\ActorIdentity;
use App\Support\BrandRegistry;
use App\Support\CheckoutKey;
use App\Support\UuidDatabaseFailedJobProvider;
use App\Support\UuidDatabaseQueueConnector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // V001 defines jobs.id as a UUID, while Laravel's stock database
        // connector uses insertGetId() and assumes an auto-increment key. Use
        // the compatible queue writer for both normal dispatch and worker
        // releases; the UUID is only a physical queue-row key.
        $app = $this->app;
        $this->app->afterResolving('queue', function ($queue) use ($app): void {
            $queue->extend('database', fn (): UuidDatabaseQueueConnector => new UuidDatabaseQueueConnector(
                $app->make('db'),
            ));
        });

        // V001 also requires a physical UUID for failed_jobs.id, while
        // Laravel's database-uuids provider normally relies on an auto-increment
        // id. Keep the standard failed-job contract with a schema-compatible
        // provider instead of changing the deployed table shape.
        if ($app['config']->get('queue.failed.driver') === 'database-uuids') {
            $this->app->extend('queue.failer', fn ($provider, $app) => $provider instanceof UuidDatabaseFailedJobProvider
                ? $provider
                : new UuidDatabaseFailedJobProvider(
                    $app->make('db'),
                    $app['config']->get('queue.failed.database'),
                    $app['config']->get('queue.failed.table', 'failed_jobs'),
                ));
        }

        $this->app->singleton(HtmlSanitizer::class, function ($app) {
            $config = (new HtmlSanitizerConfig)
                ->allowElement('p')
                ->allowElement('h1')
                ->allowElement('h2')
                ->allowElement('h3')
                ->allowElement('h4')
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
                    $app->make(VolumePresetService::class)
                        ->resolveDefaultForBrand(BrandRegistry::currentOrDefault()),
                    $app->make(CouponService::class)
                ),
                default => new ScopeLicensingStrategy,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-catalog', fn ($user) => app(AuthorizationService::class)->isSuperAdmin($user));

        Gate::define('manage-users', function ($user) {
            $svc = app(AuthorizationService::class);

            return $svc->isAdmin($user) || $svc->isOrgAdmin($user);
        });

        Gate::define('purchase-upgrades', function ($user) {
            $svc = app(AuthorizationService::class);
            if ($svc->isReservedNullBrandActor($user) || $svc->isTransientGuest($user)) {
                return false;
            }

            return ! $svc->isClient($user) || $svc->isPrivileged($user);
        });

        Gate::define('purchase-on-invoice', function ($user) {
            $svc = app(AuthorizationService::class);
            if ($svc->isReservedNullBrandActor($user) || $svc->isTransientGuest($user)) {
                return false;
            }

            $user->loadMissing('roles');
            $roleNames = $user->roles->pluck('name')->all();
            $isClient = in_array(UserRole::CLIENT->value, $roleNames);
            $isPrivileged = in_array(UserRole::POWER_USER->value, $roleNames)
                || in_array(UserRole::ADMIN->value, $roleNames)
                || in_array(UserRole::SUPER_ADMIN->value, $roleNames);

            return $isClient || $isPrivileged;
        });

        Auth::provider('transient_eloquent', function ($app, array $config) {
            return new TransientUserProvider($app['hash'], $config['model']);
        });
        // Den neuen Custom Transport in Laravel's Mail-Manager integrieren
        Mail::extend('gmail_rest', function (array $config) {
            return new GmailRestTransport(
                $config['client_id'] ?? env('OAUTH_CLIENT_ID'),
                $config['client_secret'] ?? env('OAUTH_CLIENT_SECRET'),
                $config['refresh_token'] ?? env('OAUTH_REFRESH_TOKEN')
            );
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('app.throttle_api', 120))->by(
            $request->user('api')
                ? ActorIdentity::cacheIdentifier($request->user('api'))
                : $request->ip()
        ));

        RateLimiter::for('checkout', function (Request $request): array {
            $user = $request->user('api');
            $ip = $request->ip();

            return [
                Limit::perHour(max(1, (int) config('app.checkout_throttle_user_per_hour', 5)))
                    ->by($user ? CheckoutKey::user($user, 'checkout-quota') : CheckoutKey::ip($ip, 'checkout-quota')),
                Limit::perHour(max(1, (int) config('app.checkout_throttle_ip_per_hour', 10)))
                    ->by(CheckoutKey::ip($ip, 'checkout-quota-hour')),
                Limit::perDay(max(1, (int) config('app.checkout_throttle_ip_per_day', 30)))
                    ->by(CheckoutKey::ip($ip, 'checkout-quota-day')),
            ];
        });

        RateLimiter::for('coupon-validate', fn (Request $request) => Limit::perMinute(10)->by(
            $request->user('api')
                ? ActorIdentity::cacheIdentifier($request->user('api'))
                : $request->ip()
        ));

        // Dedicated bucket for the public model-registration endpoints. A named
        // limiter gets its own cache key (md5(name.key)) instead of sharing the
        // positional `sha1(domain|ip)` key with the auth routes — otherwise a
        // burst of login/invite traffic in E2E (or behind NAT) would consume the
        // model-registration budget. Kept bounded in every environment.
        RateLimiter::for('model-registration', fn (Request $request) => Limit::perMinute(
            (int) config('app.throttle_model_registration', 10)
        )->by($request->ip()));

        // AIS-3: both AI POST endpoints bill a provider on every call and may
        // decode a ~40M-pixel image (~160 MB) through GD. The generic
        // `throttle:api` (120/min) is far too permissive for that. This limiter
        // is registered here, not in routes/api.php: `php artisan optimize` runs
        // `route:cache` in production, which skips loading the route files, so a
        // routes-file registration would leave the named limiter undefined at
        // runtime and turn the endpoint into a 500.
        RateLimiter::for('ai-generate', fn (Request $request) => Limit::perMinute(
            max(1, (int) config('app.throttle_ai_generate', 5))
        )->by(
            $request->user('api')
                ? ActorIdentity::cacheIdentifier($request->user('api'))
                : $request->ip()
        ));

        // Reset brand state before each queue job to prevent stale config carrying over
        // between jobs in long-running queue workers (php artisan queue:work).
        // Consumers like InvoiceMail::build() call BrandRegistry::set() explicitly, so they
        // remain unaffected.
        Queue::before(function () {
            BrandRegistry::reset();
            // Also drop the memoized brand-config cache so a brand-settings write
            // (DB overlay) performed in a previous job is picked up fresh by the
            // next job. clearCache() is idempotent (no-op when nothing cached).
            BrandRegistry::clearCache();
        });

        // DSGVO general safety net: any Customer deletion also queues the
        // encrypted age proof / person photo cleanup and Scout removal after
        // the database commit.
        Customer::observe(CustomerObserver::class);
    }
}
