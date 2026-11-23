<?php

declare(strict_types=1);

namespace App\Eur\Reference;

use InvalidArgumentException;
use RuntimeException;

/**
 * EAR · DEG-Regeln als eigene, gepinnte Sekundärquelle (Portal #53).
 *
 * Die DEG-Regeln zählen nicht in den Floor von 131 und liegen deshalb in
 * einem eigenen Artefakt. Diese Lesegrenze liefert ausschließlich strukturierte
 * Werte aus jenem Artefakt — hier die zulässigen Ausnahmen für DEG-007. Es
 * gibt im Portal keine zweite, hartcodierte Liste dieser Ausnahmen.
 */
final class DegressiveRules
{
    private const PATH = __DIR__.'/../../../../features/eur/cleanroom/artifacts/business-rules/degressive-afa-rules.json';

    private const RULE_ID = 'DEG-007';

    private const PARAMETER = 'Zulässige Ausnahmen';

    public function __construct(private readonly string $path = self::PATH) {}

    /**
     * DEG-007: `digitalisation`, `eco`, `health` — gelesen aus dem Parameter
     * des gepinnten Artefakts, in der dortigen Reihenfolge.
     *
     * @return list<string>
     */
    public function allowedImmaterialExceptions(): array
    {
        $value = $this->parameterValue(self::RULE_ID, self::PARAMETER);
        $parts = array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $part): bool => $part !== '',
        ));

        if ($parts === []) {
            throw new RuntimeException(sprintf('Regel %s: Parameter "%s" ist leer.', self::RULE_ID, self::PARAMETER));
        }

        return $parts;
    }

    public function isAllowedImmaterialException(?string $exception): bool
    {
        return $exception !== null && in_array($exception, $this->allowedImmaterialExceptions(), true);
    }

    private function parameterValue(string $ruleId, string $name): string
    {
        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new RuntimeException('Gepinntes DEG-Artefakt nicht lesbar: '.$this->path);
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException('DEG-Artefakt ist kein Dokument.');
        }

        foreach ($data['bereiche'] ?? [] as $bereich) {
            foreach ($bereich['regeln'] ?? [] as $rule) {
                if (($rule['id'] ?? null) !== $ruleId) {
                    continue;
                }

                foreach ($rule['parameter'] ?? [] as $parameter) {
                    if (($parameter['name'] ?? null) === $name) {
                        return (string) ($parameter['wert'] ?? '');
                    }
                }
            }
        }

        throw new InvalidArgumentException(sprintf('Regel %s hat keinen Parameter "%s".', $ruleId, $name));
    }
}
