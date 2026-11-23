<?php

namespace Tests\Unit\Auth;

use App\Services\Auth\TransientClaims;
use App\Services\Auth\TransientClaimsCodec;
use InvalidArgumentException;
use Tests\TestCase;

class TransientClaimsCodecTest extends TestCase
{
    private TransientClaimsCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->codec = new TransientClaimsCodec;
    }

    public function test_roundtrip_preserves_all_structured_claims(): void
    {
        $claims = new TransientClaims(
            guestId: 'guest-1',
            guestName: 'Anna Nom',
            transientInviteIds: ['invite-1', 'invite-2'],
            transientGalleries: ['gallery-1'],
            transientMetaGalleries: ['gallery-2'],
            transientInvites: [
                'invite-1' => [
                    'gallery_ids' => ['gallery-1'],
                    'meta_gallery_ids' => [],
                ],
            ],
        );

        $abilities = $this->codec->encode($claims);

        $this->assertCount(1, $abilities);
        $this->assertStringStartsWith(TransientClaimsCodec::VERSION_PREFIX, $abilities[0]);
        $this->assertEquals($claims, $this->codec->decode($abilities));
    }

    public function test_empty_claims_roundtrip(): void
    {
        $this->assertEquals(new TransientClaims, $this->codec->decode($this->codec->encode(new TransientClaims)));
    }

    public function test_unrelated_abilities_do_not_become_claims_or_roles(): void
    {
        // Abilities are transport, not decision (D-73/D-78). A wildcard or a
        // role-looking string never turns into a claim or an authorization.
        $decoded = $this->codec->decode(['*', 'super_admin', 'admin']);

        $this->assertEquals(new TransientClaims, $decoded);
        $this->assertNull($decoded->guestId);
        $this->assertSame([], $decoded->transientGalleries);
    }

    public function test_malformed_json_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->codec->decode([TransientClaimsCodec::VERSION_PREFIX.'{not json']);
    }

    public function test_wrong_version_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported transient claims ability version');

        $this->codec->decode(['tp2:{"guest_id":"x"}']);
    }

    public function test_unknown_field_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing or unknown fields');

        // A role smuggled into the payload must be rejected — roles are never
        // stored in abilities (D-78).
        $payload = [
            'guest_id' => null,
            'guest_name' => null,
            'transient_invite_ids' => [],
            'transient_galleries' => [],
            'transient_meta_galleries' => [],
            'transient_invites' => [],
            'roles' => ['super_admin'],
        ];
        $this->codec->decode([TransientClaimsCodec::VERSION_PREFIX.json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    public function test_wrong_field_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $payload = [
            'guest_id' => 42,
            'guest_name' => null,
            'transient_invite_ids' => [],
            'transient_galleries' => [],
            'transient_meta_galleries' => [],
            'transient_invites' => [],
        ];
        $this->codec->decode([TransientClaimsCodec::VERSION_PREFIX.json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    public function test_non_array_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->codec->decode('not-an-array');
    }

    public function test_non_string_ability_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->codec->decode([['nested']]);
    }
}
