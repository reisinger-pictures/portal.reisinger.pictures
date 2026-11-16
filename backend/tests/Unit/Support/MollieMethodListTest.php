<?php

namespace Tests\Unit\Support;

use App\Support\MollieMethodList;
use PHPUnit\Framework\TestCase;

class MollieMethodListTest extends TestCase
{
    public function test_parse_preserves_order_lowercases_and_drops_empty_entries(): void
    {
        $this->assertSame(
            ['paybybank', 'eps', 'creditcard'],
            MollieMethodList::parse(' PayByBank , ,EPS,, CreditCard '),
        );
    }

    public function test_parse_rejects_non_string_input(): void
    {
        $this->assertSame([], MollieMethodList::parse(null));
        $this->assertSame([], MollieMethodList::parse(['creditcard']));
    }

    public function test_the_list_wins_over_the_deprecated_single_variable(): void
    {
        $this->assertSame(
            ['paybybank', 'eps'],
            MollieMethodList::resolve('paybybank,eps', 'creditcard'),
        );
    }

    public function test_the_deprecated_single_variable_is_the_fallback_for_an_empty_or_missing_list(): void
    {
        $this->assertSame(['eps'], MollieMethodList::resolve(null, ' EPS '));
        $this->assertSame(['eps'], MollieMethodList::resolve(' , ', 'eps'));
    }

    public function test_two_empty_variables_resolve_to_an_empty_list(): void
    {
        $this->assertSame([], MollieMethodList::resolve(null, null));
        $this->assertSame([], MollieMethodList::resolve('', ''));
    }
}
