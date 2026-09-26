<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The single source of truth for `users.ftp_slug`, which is the FTP/SFTP
 * account name (features/infrastructure/19-ftp-upload-pipeline.md, 7.2, P1-M21).
 *
 * Both write paths use this class: the user-chosen one
 * (`AuthController::updateProfile()`) and the generated one
 * (`User::nextAvailableFtpSlug()`). The format rule must not be spelled out twice.
 *
 * Decision on non-conforming legacy values — nothing is migrated, silently or
 * otherwise. `ftp_slug` is a foreign key into `storage/app/private` and into
 * `ftp/<slug>` on the host, so renaming a value moves a directory. Values that
 * predate this rule (e.g. the local part `j.doe`, which `Str::slug()` used to
 * reduce to `jdoe`) therefore stay untouched; the write path rejects them with a
 * message and the photographer picks a compliant name himself. That is the
 * "let the photographer choose, with an error path until then" branch of 7.11 —
 * the alternative, normalising on save, would orphan the folder while telling
 * nobody. Only *generated* slugs (new rows, nobody typed them, nothing depends
 * on them yet) are reduced to a valid form; see toValidBase().
 */
final class FtpSlug
{
    /** Lowercase, no `.`/`@`/`, no slash, 3–32 characters. */
    public const PATTERN = '/^[a-z0-9][a-z0-9_-]{2,31}$/';

    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 32;

    /** Base used when a generated value has nothing usable left. */
    public const FALLBACK = 'user';

    public static function isValid(string $value): bool
    {
        // preg_match returns false for malformed UTF-8; `=== 1` fails closed.
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Validation message for the API, derived from the constants so it cannot
     * drift away from them.
     */
    public static function message(): string
    {
        return 'Der FTP-Login darf nur Kleinbuchstaben, Ziffern, "-" und "_" enthalten, muss mit einem '
            .'Buchstaben oder einer Ziffer beginnen und '.self::MIN_LENGTH.' bis '.self::MAX_LENGTH
            .' Zeichen lang sein.';
    }

    /**
     * Cosmetic normalisation for a value a human typed.
     *
     * `Str::slug()` is deliberately NOT used here: it consumes exactly the
     * characters the format spec exists to reject (`a/b` becomes `ab`, `a@b`
     * becomes `a-at-b`, `j.doe` becomes `jdoe` — which also silently collides
     * with the real account `jdoe`). A login name must never change behind the
     * user's back, so only case, transliteration and whitespace are touched;
     * `.`, `@`, `/` and a leading separator survive and are rejected by
     * isValid(). The result is therefore not guaranteed to be valid — that is
     * the caller's job to check.
     */
    public static function normalize(string $value): string
    {
        $slug = Str::lower(Str::ascii(trim($value)));
        $slug = preg_replace('/\s+/u', '-', $slug) ?? $slug;
        $slug = preg_replace('/-{2,}/', '-', $slug) ?? $slug;

        // Trailing separators are cosmetics; a *leading* one is not, because the
        // first character is the positionally significant part of the spec.
        return rtrim($slug, '-_');
    }

    /**
     * Reduce a value to a base that is guaranteed to satisfy PATTERN, falling
     * back to FALLBACK. Only for generated slugs: unlike normalize() this may
     * drop characters (`j.doe` becomes `j-doe`), which is acceptable for a value
     * nobody typed and that no existing path references yet.
     */
    public static function toValidBase(string $value): string
    {
        $base = self::normalize($value);
        $base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '';
        $base = substr($base, 0, self::MAX_LENGTH);
        $base = ltrim(rtrim($base, '-_'), '-_');

        return self::isValid($base) ? $base : self::FALLBACK;
    }

    /**
     * Append a collision counter without breaking MAX_LENGTH. Digits are allowed
     * by PATTERN, so the result stays valid.
     */
    public static function withSuffix(string $base, string $suffix): string
    {
        return substr($base, 0, max(1, self::MAX_LENGTH - strlen($suffix))).$suffix;
    }
}
