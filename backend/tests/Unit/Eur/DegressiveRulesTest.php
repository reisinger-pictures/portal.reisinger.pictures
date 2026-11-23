<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Reference\DegressiveRules;
use PHPUnit\Framework\TestCase;

/** EAR · DEG-Regeln aus der gepinnten Sekundärquelle, ohne zweite Liste im Code. */
class DegressiveRulesTest extends TestCase
{
    public function test_immaterial_exceptions_come_from_the_pinned_artifact(): void
    {
        $rules = new DegressiveRules;

        $this->assertSame(['digitalisation', 'eco', 'health'], $rules->allowedImmaterialExceptions());
        $this->assertTrue($rules->isAllowedImmaterialException('digitalisation'));
        $this->assertTrue($rules->isAllowedImmaterialException('health'));
        $this->assertFalse($rules->isAllowedImmaterialException('marketing'));
        $this->assertFalse($rules->isAllowedImmaterialException(null));
    }

    public function test_exceptions_are_read_from_the_given_artifact_not_hardcoded(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'deg-rules-');
        file_put_contents($path, json_encode([
            'bereiche' => [[
                'regeln' => [[
                    'id' => 'DEG-007',
                    'parameter' => [[
                        'name' => 'Zulässige Ausnahmen',
                        'wert' => 'alpha, beta',
                    ]],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $rules = new DegressiveRules($path);

            $this->assertSame(['alpha', 'beta'], $rules->allowedImmaterialExceptions());
            $this->assertTrue($rules->isAllowedImmaterialException('beta'));
            $this->assertFalse($rules->isAllowedImmaterialException('eco'));
        } finally {
            unlink($path);
        }
    }
}
