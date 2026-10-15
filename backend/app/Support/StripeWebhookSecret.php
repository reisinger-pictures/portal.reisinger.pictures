<?php

namespace App\Support;

use App\Http\Controllers\WebhookController;
use App\Services\StripeKeyCheckService;

/**
 * Single source of truth for resolving the Stripe webhook signing secret.
 *
 * The order is: the configured `STRIPE_WEBHOOK_SECRET`, otherwise the
 * auto-tunneler's private artifact. That artifact only exists for
 * local/tunnel workflows; it must never become an accepted signing secret on
 * a production-like host with an unset env var, so the fallback is gated on
 * the `local`/`testing` environment *and* the file's existence.
 *
 * Both the ingress ({@see WebhookController}) and the
 * super-admin self-test ({@see StripeKeyCheckService}) resolve
 * the secret through this class, so the rule lives in exactly one place
 * instead of drifting between the two callers.
 */
final class StripeWebhookSecret
{
    /**
     * Relative to `storage_path()`; written by the local auto-tunneler.
     */
    private const TUNNEL_ARTIFACT_PATH = 'app/private/stripe_secret.txt';

    /**
     * The value from configuration only (comma-separated list preserved for
     * multi-domain infrastructure), or null when unset/blank.
     */
    public static function fromEnvironment(): ?string
    {
        $configured = trim((string) config('services.stripe.webhook_secret'));

        return $configured !== '' ? $configured : null;
    }

    /**
     * The value from the local tunnel artifact, or null when the environment
     * is production-like, the artifact is absent, or it is blank.
     */
    public static function fromTunnelArtifact(): ?string
    {
        if (! app()->environment(['local', 'testing'])) {
            return null;
        }

        $secretFile = storage_path(self::TUNNEL_ARTIFACT_PATH);
        if (! file_exists($secretFile)) {
            return null;
        }

        $secret = trim((string) file_get_contents($secretFile));

        return $secret !== '' ? $secret : null;
    }

    /**
     * The effective signing secret: configuration first, the tunnel artifact
     * as the local-only fallback. Null means "no secret configured at all".
     */
    public static function resolve(): ?string
    {
        return self::fromEnvironment() ?? self::fromTunnelArtifact();
    }

    /**
     * True when the effective secret comes from the tunnel artifact rather
     * than configuration. Callers use it to name the source in diagnostics.
     */
    public static function isTunnelFallback(): bool
    {
        return self::fromEnvironment() === null && self::fromTunnelArtifact() !== null;
    }
}
