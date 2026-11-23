<?php

namespace App\Services\Auth;

use InvalidArgumentException;
use JsonException;

/**
 * Serializes transient claims into a versioned Sanctum ability and back
 * (Portal #9, D-73).
 *
 * The ability is a single `tp1:`-prefixed JSON string. Other abilities on the
 * same token are left alone and are NEVER interpreted as claims — in
 * particular this codec does not read roles or permissions from abilities
 * (D-78). Abilities are transport only: the authorization layer still
 * revalidates every claim against live invite state on every request through
 * `AuthorizationService::sanitizeTransientClaims()` /
 * `filterGuestTransientClaims()` (D-39, D-44). This codec is deliberately not
 * an authorization decision point.
 *
 * Decoding is strict: input is `mixed` (it comes from the database), and any
 * malformed JSON, unknown field, wrong field type or unsupported version is
 * rejected with an `InvalidArgumentException` instead of being coerced.
 */
final class TransientClaimsCodec
{
    public const VERSION_PREFIX = 'tp1:';

    private const VERSION_PATTERN = '/^tp(?<version>\d+):/';

    /** @var list<string> */
    private const FIELDS = [
        'guest_id',
        'guest_name',
        'transient_invite_ids',
        'transient_galleries',
        'transient_meta_galleries',
        'transient_invites',
    ];

    /**
     * @return list<string> an abilities array containing the encoded claims
     */
    public function encode(TransientClaims $claims): array
    {
        $payload = [
            'guest_id' => $claims->guestId,
            'guest_name' => $claims->guestName,
            'transient_invite_ids' => $claims->transientInviteIds,
            'transient_galleries' => $claims->transientGalleries,
            'transient_meta_galleries' => $claims->transientMetaGalleries,
            'transient_invites' => $claims->transientInvites,
        ];

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Transient claims could not be encoded.', 0, $exception);
        }

        return [self::VERSION_PREFIX.$json];
    }

    /**
     * @param  mixed  $abilities  the token's abilities as stored in `personal_access_tokens`
     *
     * @throws InvalidArgumentException on a malformed or unsupported payload
     */
    public function decode(mixed $abilities): TransientClaims
    {
        if (! is_array($abilities)) {
            throw new InvalidArgumentException('Transient claims abilities must be an array.');
        }

        $encoded = null;
        foreach ($abilities as $ability) {
            if (! is_string($ability)) {
                throw new InvalidArgumentException('Each ability must be a string.');
            }

            if (preg_match(self::VERSION_PATTERN, $ability, $matches) !== 1) {
                // Unrelated abilities (for example '*') are ignored, never
                // interpreted as claims or roles (D-78).
                continue;
            }

            if ($matches['version'] !== '1') {
                throw new InvalidArgumentException(sprintf(
                    'Unsupported transient claims ability version: %s',
                    $matches['version']
                ));
            }

            if ($encoded !== null) {
                throw new InvalidArgumentException('More than one transient claims ability found.');
            }

            $encoded = substr($ability, strlen(self::VERSION_PREFIX));
        }

        if ($encoded === null) {
            return new TransientClaims;
        }

        $payload = $this->decodeJson($encoded);
        $fields = array_keys($payload);
        sort($fields);
        $expected = self::FIELDS;
        sort($expected);

        if ($fields !== $expected) {
            throw new InvalidArgumentException('Transient claims payload has missing or unknown fields.');
        }

        return new TransientClaims(
            guestId: $this->nullableString($payload['guest_id'], 'guest_id'),
            guestName: $this->nullableString($payload['guest_name'], 'guest_name'),
            transientInviteIds: $this->stringList($payload['transient_invite_ids'], 'transient_invite_ids'),
            transientGalleries: $this->stringList($payload['transient_galleries'], 'transient_galleries'),
            transientMetaGalleries: $this->stringList($payload['transient_meta_galleries'], 'transient_meta_galleries'),
            transientInvites: $this->inviteRecords($payload['transient_invites']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Transient claims ability is not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('Transient claims payload must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Transient claim %s must be a string or null.', $field));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Transient claim %s must be a list of strings.', $field));
        }

        $result = [];
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new InvalidArgumentException(sprintf('Transient claim %s must contain non-empty strings.', $field));
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @return array<string, array{gallery_ids: list<string>, meta_gallery_ids: list<string>}>
     */
    private function inviteRecords(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Transient claim transient_invites must be an object.');
        }

        $records = [];
        foreach ($value as $inviteId => $record) {
            if ((! is_string($inviteId) && ! is_int($inviteId)) || (string) $inviteId === '') {
                throw new InvalidArgumentException('Transient invite ids must be non-empty strings.');
            }

            if (! is_array($record)) {
                throw new InvalidArgumentException('Each transient invite record must be an object.');
            }

            $keys = array_keys($record);
            sort($keys);
            if ($keys !== ['gallery_ids', 'meta_gallery_ids']) {
                throw new InvalidArgumentException('Transient invite records accept only gallery_ids and meta_gallery_ids.');
            }

            $records[(string) $inviteId] = [
                'gallery_ids' => $this->stringList($record['gallery_ids'], 'transient_invites.gallery_ids'),
                'meta_gallery_ids' => $this->stringList($record['meta_gallery_ids'], 'transient_invites.meta_gallery_ids'),
            ];
        }

        return $records;
    }
}
