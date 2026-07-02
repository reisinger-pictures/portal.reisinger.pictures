<?php

namespace Tests;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!app()->bound('brand.context')) {
            BrandRegistry::set(Brand::B2B);
        }
    }
}
