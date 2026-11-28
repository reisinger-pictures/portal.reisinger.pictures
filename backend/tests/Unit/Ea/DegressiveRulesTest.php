<?php

declare(strict_types=1);

namespace Tests\Unit\Ea;

use App\Ea\Data\DegressiveRulesData;
use App\Ea\Reference\DegressiveRules;
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

    /**
     * Issue #74: die Liste ist Programmcode, kein Laufzeitartefakt. Der Leser
     * hat keinen Pfadparameter mehr und liefert genau die committete Konstante.
     */
    public function test_exceptions_come_from_the_committed_data_class(): void
    {
        $rules = new DegressiveRules;

        $this->assertSame(
            DegressiveRulesData::ALLOWED_IMMATERIAL_EXCEPTIONS,
            $rules->allowedImmaterialExceptions(),
        );
    }
}
