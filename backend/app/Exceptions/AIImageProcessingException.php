<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A user-supplied image cannot be safely prepared for the AI pipeline.
 *
 * The controller maps this exception to a stable 422 response. Keeping it
 * separate from provider RuntimeExceptions prevents a rejected image from
 * being reported as a provider outage.
 */
final class AIImageProcessingException extends RuntimeException
{
    public const REASON_BYTES = 'bytes';

    public const REASON_PIXELS = 'pixels';

    public const REASON_DECODE = 'decode';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
