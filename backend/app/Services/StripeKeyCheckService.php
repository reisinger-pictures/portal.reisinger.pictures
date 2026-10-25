<?php

namespace App\Services;

use App\Support\BrandRegistry;
use App\Support\StripeKeyMode;
use App\Support\StripeWebhookSecret;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Throwable;

/**
 * Stripe self-test ("Stripe-Selbsttest") for the super-admin diagnostic
 * endpoint `POST /api/management/settings/stripe-check`.
 *
 * Always returns all five findings in a stable order and never lets a Stripe
 * failure escape: every network check catches its own exception and turns it
 * into a `fail` finding, so an admin always sees the full verdict — including
 * the "this Stripe call itself could not be made" case. Only authentication
 * (the route middleware) can reject the request.
 *
 * The seam mirrors {@see StripePaymentService}: the StripeClient is injected so
 * tests drive a deterministic faked HTTP client through
 * `Stripe\ApiRequestor::setHttpClient()` instead of the network. It is resolved
 * lazily per network check, so a missing or empty secret key — the very
 * misconfiguration this endpoint exists to diagnose — becomes a `fail` finding
 * instead of a 500 during construction.
 */
class StripeKeyCheckService
{
    /**
     * The five webhook events the checkout/dispute flow depends on. A missing
     * one is a `warn`, not a `fail`: payments can still settle without it.
     *
     * The check compares by subset (see {@see checkWebhookEndpoint()}): every
     * event listed here must be subscribed, additional subscriptions are fine.
     *
     * @var array<int, string>
     */
    private const REQUIRED_WEBHOOK_EVENTS = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'charge.dispute.created',
        'charge.dispute.closed',
        'charge.refunded',
    ];

    private ?StripeClient $stripe;

    public function __construct(?StripeClient $stripe = null)
    {
        $this->stripe = $stripe;
    }

    /**
     * Resolve the Stripe client on demand. The SDK constructor throws on a
     * missing/empty secret key; building it inside each network check's own
     * try/catch keeps that misconfiguration a `fail` finding (see
     * {@see describeStripeError()}) instead of an escaping exception on the
     * controller's method-injection path. Checks 1 and 2 never resolve it.
     */
    private function stripe(): StripeClient
    {
        return $this->stripe ?? new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Run all five diagnostics and return a stable, ordered check list.
     *
     * @return array{checks: array<int, array{key: string, status: string, message: string}>}
     */
    public function run(?string $bundleMode): array
    {
        return [
            'checks' => [
                $this->checkLocalKeyMode(),
                $this->checkFrontendBundleMode($bundleMode),
                $this->checkBalance(),
                $this->checkAccount(),
                $this->checkWebhookEndpoint(),
            ],
        ];
    }

    /**
     * Publishable + secret keys share one mode, that mode matches APP_ENV, and
     * the webhook signing secret is resolvable — from the env var or, locally,
     * from the tunnel artifact (both via {@see StripeWebhookSecret}). No
     * network call.
     *
     * @return array{key: string, status: string, message: string}
     */
    private function checkLocalKeyMode(): array
    {
        $targetMode = StripeKeyMode::fromEnvironment(config('app.env'));
        $publishableMode = StripeKeyMode::fromKey(config('services.stripe.key'));
        $secretMode = StripeKeyMode::fromKey(config('services.stripe.secret'));
        $webhookSecret = StripeWebhookSecret::resolve();

        $problems = [];

        if ($publishableMode === null || $secretMode === null) {
            $problems[] = 'Publishable- oder Secret-Schlüssel trägt keinen erkennbaren test/live-Modus.';
        } elseif ($publishableMode !== $secretMode) {
            $problems[] = "Publishable-Schlüssel ({$publishableMode}) und Secret-Schlüssel ({$secretMode}) nutzen unterschiedliche Modi.";
        }

        $activeMode = $secretMode ?? $publishableMode;
        if ($activeMode !== null && $activeMode !== $targetMode) {
            $problems[] = "Aktiver Schlüssel läuft im {$activeMode}-Modus, erwartet wird aber {$targetMode} (abgeleitet aus APP_ENV).";
        }

        if ($webhookSecret === null) {
            $problems[] = 'STRIPE_WEBHOOK_SECRET ist nicht gesetzt.';
        }

        if ($problems !== []) {
            return self::finding('local_key_mode', 'fail', implode(' ', $problems));
        }

        $secretSource = StripeWebhookSecret::isTunnelFallback()
            ? ' (bezogen aus dem lokalen Tunnel-Artefakt, nicht aus der Env)'
            : '';

        return self::finding('local_key_mode', 'ok', "Publishable- und Secret-Schlüssel laufen konsistent im {$targetMode}-Modus, das Webhook-Secret ist gesetzt{$secretSource}.");
    }

    /**
     * Compare the frontend's reported bundle mode against the server key mode.
     * A missing key (`bundle_mode === null`) is the D-24 failure class and is a
     * `fail`, not a 422. Only the mode is compared, never a key value.
     *
     * @return array{key: string, status: string, message: string}
     */
    private function checkFrontendBundleMode(?string $bundleMode): array
    {
        if ($bundleMode === null) {
            return self::finding(
                'frontend_bundle_mode',
                'fail',
                'Im Frontend-Bundle wurde kein VITE_STRIPE_PUBLIC_KEY gefunden (bundle_mode ist leer).',
            );
        }

        $serverMode = StripeKeyMode::fromKey(config('services.stripe.key'));
        if ($serverMode === null) {
            return self::finding(
                'frontend_bundle_mode',
                'fail',
                'Der Server-Publishable-Schlüssel trägt keinen erkennbaren Modus; ein Vergleich mit dem Bundle ist nicht möglich.',
            );
        }

        if ($bundleMode !== $serverMode) {
            return self::finding(
                'frontend_bundle_mode',
                'fail',
                "Das Bundle lädt den {$bundleMode}-Modus, das Backend nutzt aber den {$serverMode}-Schlüssel.",
            );
        }

        return self::finding(
            'frontend_bundle_mode',
            'ok',
            "Bundle und Backend laufen beide im {$serverMode}-Modus.",
        );
    }

    /**
     * Retrieve the balance and confirm its livemode matches APP_ENV. A Stripe
     * error is passed through with its real code (e.g. `api_key_expired`).
     *
     * @return array{key: string, status: string, message: string}
     */
    private function checkBalance(): array
    {
        $targetMode = StripeKeyMode::fromEnvironment(config('app.env'));

        try {
            $balance = $this->stripe()->balance->retrieve();
        } catch (Throwable $exception) {
            return self::finding(
                'stripe_balance',
                'fail',
                'Stripe-Balance konnte nicht abgerufen werden: '.$this->describeStripeError($exception),
            );
        }

        $livemode = (bool) ($balance->livemode ?? false);
        if ($livemode !== ($targetMode === StripeKeyMode::LIVE)) {
            return self::finding(
                'stripe_balance',
                'fail',
                'Die Balance läuft im '.($livemode ? 'live' : 'test')."-Modus, erwartet wird aber {$targetMode}.",
            );
        }

        return self::finding('stripe_balance', 'ok', "Die Balance ist abrufbar und läuft im erwarteten {$targetMode}-Modus.");
    }

    /**
     * Retrieve the account and report the capability flags. Missing flags are a
     * `warn` (payments may still work); the account id is always named.
     *
     * @return array{key: string, status: string, message: string}
     */
    private function checkAccount(): array
    {
        try {
            $account = $this->stripe()->accounts->retrieve();
        } catch (Throwable $exception) {
            return self::finding(
                'stripe_account',
                'fail',
                'Das Stripe-Konto konnte nicht abgerufen werden: '.$this->describeStripeError($exception),
            );
        }

        $id = (string) ($account->id ?? 'unbekannt');
        $flags = ['charges_enabled', 'payouts_enabled', 'details_submitted'];
        $missing = [];
        foreach ($flags as $flag) {
            if (! ($account->{$flag} ?? false)) {
                $missing[] = $flag;
            }
        }

        if ($missing === []) {
            return self::finding(
                'stripe_account',
                'ok',
                "Konto {$id} ist vollständig freigeschaltet (charges, payouts, details).",
            );
        }

        return self::finding(
            'stripe_account',
            'warn',
            "Konto {$id} ist nicht vollständig freigeschaltet. Fehlend: ".implode(', ', $missing).'.',
        );
    }

    /**
     * Confirm a webhook endpoint exists for the app's canonical URL, is
     * enabled, and subscribes all five required events. Uses the same URL
     * construction as the rest of the app (BrandRegistry::frontendUrl()).
     *
     * @return array{key: string, status: string, message: string}
     */
    private function checkWebhookEndpoint(): array
    {
        try {
            $endpoints = $this->stripe()->webhookEndpoints->all(['limit' => 100]);
        } catch (Throwable $exception) {
            return self::finding(
                'stripe_webhook_endpoint',
                'fail',
                'Die Webhook-Endpunkte konnten nicht abgerufen werden: '.$this->describeStripeError($exception),
            );
        }

        $expectedUrl = rtrim(BrandRegistry::frontendUrl(), '/').'/api/webhooks/stripe';

        $endpoint = null;
        foreach ($endpoints->data ?? [] as $candidate) {
            if ((string) ($candidate->url ?? '') === $expectedUrl) {
                $endpoint = $candidate;
                break;
            }
        }

        if ($endpoint === null) {
            return self::finding(
                'stripe_webhook_endpoint',
                'fail',
                "Kein Webhook-Endpunkt für {$expectedUrl} gefunden.",
            );
        }

        $id = (string) ($endpoint->id ?? 'unbekannt');

        if (($endpoint->status ?? null) !== 'enabled') {
            return self::finding(
                'stripe_webhook_endpoint',
                'fail',
                "Webhook-Endpunkt {$id} für {$expectedUrl} ist deaktiviert.",
            );
        }

        $enabledEvents = $endpoint->enabled_events ?? [];
        if (! is_array($enabledEvents)) {
            $enabledEvents = [];
        }
        // Subset check, not an exact match: every required event must be
        // subscribed, but an endpoint with additional events is still complete.
        $missingEvents = array_values(array_diff(self::REQUIRED_WEBHOOK_EVENTS, $enabledEvents));

        if ($missingEvents !== []) {
            return self::finding(
                'stripe_webhook_endpoint',
                'warn',
                "Webhook-Endpunkt {$id} ist aktiv, abonniert aber nicht alle erforderlichen Ereignisse. Fehlend: ".implode(', ', $missingEvents).'.',
            );
        }

        return self::finding(
            'stripe_webhook_endpoint',
            'ok',
            "Webhook-Endpunkt {$id} ist aktiv und abonniert alle erforderlichen Ereignisse.",
        );
    }

    /**
     * Turn a thrown exception into a German, secret-free sentence. The Stripe
     * code + message are passed through verbatim (e.g. `api_key_expired`,
     * `more_permissions_required` carry more value than generic prose), then
     * redacted so no key material can leak into the JSON response.
     */
    private function describeStripeError(Throwable $exception): string
    {
        if ($exception instanceof ApiErrorException) {
            $code = $exception->getStripeCode();
            $combined = $code !== null ? "{$code}: {$exception->getMessage()}" : $exception->getMessage();
        } else {
            $combined = $exception->getMessage();
        }

        return $this->redactSecrets($combined);
    }

    /**
     * Defense-in-depth: never echo key material inside a Stripe error message.
     * The diagnostic reports the machine-readable code, not the key value that
     * produced it. `whsec_…` never appears in a Stripe error, but is redacted
     * regardless.
     *
     * No word-boundary anchor: a key glued directly to a preceding word
     * character (`…key=abcsk_test_X…`) must be caught too. Over-redacting is
     * the safe direction here; leaving a value through is not.
     */
    private function redactSecrets(string $message): string
    {
        $redacted = preg_replace(
            '/(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9*]+|whsec_[A-Za-z0-9*]+/i',
            '***entfernt***',
            $message
        );

        return $redacted ?? $message;
    }

    /**
     * @return array{key: string, status: string, message: string}
     */
    private static function finding(string $key, string $status, string $message): array
    {
        return ['key' => $key, 'status' => $status, 'message' => $message];
    }
}
