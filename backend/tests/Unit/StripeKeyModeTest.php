<?php

namespace Tests\Unit;

use App\Support\StripeKeyMode;
use Tests\TestCase;

class StripeKeyModeTest extends TestCase
{
    public function test_it_reads_the_mode_off_the_token_right_after_the_prefix(): void
    {
        $this->assertSame('test', StripeKeyMode::fromKey('pk_test_TYooMQauvdEDq54NiTphI7jx'));
        $this->assertSame('live', StripeKeyMode::fromKey('pk_live_TYooMQauvdEDq54NiTphI7jx'));
        $this->assertSame('test', StripeKeyMode::fromKey('sk_test_abcdefg'));
        $this->assertSame('live', StripeKeyMode::fromKey('sk_live_abcdefg'));
        $this->assertSame('test', StripeKeyMode::fromKey('rk_test_abcdefg'));
        $this->assertSame('live', StripeKeyMode::fromKey('rk_live_abcdefg'));
    }

    public function test_it_returns_null_for_an_unknown_prefix(): void
    {
        $this->assertNull(StripeKeyMode::fromKey('xx_live_abc'));
        $this->assertNull(StripeKeyMode::fromKey('zz_test_abc'));
        $this->assertNull(StripeKeyMode::fromKey('sx_test_abc'));
        $this->assertNull(StripeKeyMode::fromKey('foo_test_bar'));
    }

    public function test_it_returns_null_for_null_empty_and_whitespace_values(): void
    {
        $this->assertNull(StripeKeyMode::fromKey(null));
        $this->assertNull(StripeKeyMode::fromKey(''));
        $this->assertNull(StripeKeyMode::fromKey('   '));
    }

    public function test_it_is_case_insensitive(): void
    {
        $this->assertSame('live', StripeKeyMode::fromKey('PK_LIVE_ABC'));
        $this->assertSame('test', StripeKeyMode::fromKey('SK_TEST_ABC'));
        $this->assertSame('live', StripeKeyMode::fromKey('Rk_Live_ABC'));
    }

    /**
     * The mode is the token right after the prefix. A `_test_`/`_live_` marker
     * buried in the random portion must not flip the verdict, and a marker with
     * garbage in the prefix-adjacent position must not be honored at all.
     */
    public function test_it_ignores_a_mode_marker_that_is_not_right_after_the_prefix(): void
    {
        $this->assertSame('test', StripeKeyMode::fromKey('sk_test_random_live_tail'));
        $this->assertSame('live', StripeKeyMode::fromKey('pk_live_random_test_tail'));
        $this->assertNull(StripeKeyMode::fromKey('sk_foo_live_bar'));
        $this->assertNull(StripeKeyMode::fromKey('pk_nothing_test_tail'));
        $this->assertNull(StripeKeyMode::fromKey('sk_testx_abc'));
    }

    public function test_from_environment_maps_production_to_live_and_everything_else_to_test(): void
    {
        $this->assertSame('live', StripeKeyMode::fromEnvironment('production'));
        $this->assertSame('live', StripeKeyMode::fromEnvironment('Production'));
        $this->assertSame('test', StripeKeyMode::fromEnvironment('local'));
        $this->assertSame('test', StripeKeyMode::fromEnvironment('testing'));
        $this->assertSame('test', StripeKeyMode::fromEnvironment('staging'));
        $this->assertSame('test', StripeKeyMode::fromEnvironment(''));
        $this->assertSame('test', StripeKeyMode::fromEnvironment(null));
    }
}
