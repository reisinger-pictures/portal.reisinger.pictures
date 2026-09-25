<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A pre-generated photo identifier was rejected by the explicit photo
 * creation API.
 *
 * `photos.id` is the filesystem identity of a photo: it becomes the stored
 * original's file name (`{id}.{ext}`) and the key of every derivative and
 * thumbnail URL. Import writers therefore have to mint the identifier *before*
 * the row exists, which generic mass assignment cannot express safely — it
 * would also let a request payload choose the identity of the row it writes.
 *
 * `id` is consequently not fillable, and `Photo::createWithId()` is the only
 * sanctioned way to set it. It accepts canonical UUIDs and nothing else.
 *
 * The offending value is deliberately kept out of the message (it may originate
 * from untrusted input) and exposed through {@see self::identifier()} instead.
 */
final class InvalidPhotoIdentifierException extends InvalidArgumentException
{
    private function __construct(
        string $message,
        private readonly ?string $identifier = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The supplied identifier is not in canonical 8-4-4-4-12 UUID form.
     *
     * Non-canonical spellings (urn:uuid:, braced, undashed hex) are rejected
     * even though some UUID parsers accept them, because the identifier is
     * used verbatim as a file name and as a URL path segment.
     */
    public static function notACanonicalUuid(string $id): self
    {
        return new self(
            'Photo identifier must be a canonical UUID in 8-4-4-4-12 hexadecimal form.',
            $id,
        );
    }

    /**
     * The identifier was smuggled in through the mass-assignable attribute
     * array instead of the dedicated argument.
     */
    public static function suppliedInsideAttributes(): self
    {
        return new self(
            'Photo identifier must be passed as the dedicated createWithId() argument, not inside the attribute array.',
        );
    }

    /**
     * An attribute is neither mass-assignable nor a recognised derived column.
     *
     * Failing loudly here keeps a future import writer from silently losing a
     * column that used to be persisted through `forceFill()`.
     */
    public static function unmanagedAttribute(string $attribute): self
    {
        return new self(
            "Photo attribute [{$attribute}] is neither fillable nor a recognised derived import column; "
            .'add it to Photo::$fillable or to the derived import columns explicitly.',
        );
    }

    /**
     * The rejected identifier, or null when no value was at fault.
     */
    public function identifier(): ?string
    {
        return $this->identifier;
    }
}
