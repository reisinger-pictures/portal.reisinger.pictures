<?php

namespace App\Services;

/**
 * A provider/config failure without a raw Mollie payload.
 *
 * The `reason` is a small closed vocabulary so it is safe to log and to map to
 * a retryable/non-retryable HTTP answer. `retryable` says whether asking again
 * later may succeed; it never says that creating a replacement payment is safe.
 */
class MolliePaymentException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly bool $retryable,
    ) {
        parent::__construct('Mollie payment operation failed: '.$reason);
    }
}
