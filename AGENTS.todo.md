# 📝 Projekt-Master-Backlog

Aktuelle DB Version: 16

- [x] Verschiebe `CalculatorSettingsCard` in eine eigene Datei unter `frontend/src/ui/management/components/CalculatorSettingsCard.tsx`.
- [x] Refaktoriere `CalculatorSettingsCard` auf `react-hook-form` und integriere ein Zod-Validierungsschema (`calculatorSettingsSchema`), das die Backend-Regeln abbildet (`min(0)` für Preise, `int().min(1)` für Bilder).
- [x] Prüfe das TypeScript-Interface von `useLicenseTerms` und füge `calc_base_price` (vorzugsweise als optionalen String oder Number) hinzu.
- [x] Ergänze im HTML-Input für `imagesPerHour` das Attribut `step="1"`, um die Integer-Validierung des Backends visuell zu unterstützen.
- [x] Prüfe, ob für den neuen Datenbank-Key `calc_base_price` ein Standardwert über eine Datenbank-Migration oder einen Seeder initialisiert werden muss.
