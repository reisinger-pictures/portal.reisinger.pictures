# Birthdate & Age Verification (CRM)

## Problem
Bei Akt-/Bikini-Shootings ist eine Altersverifikation rechtlich erforderlich. Das Geburtsdatum soll im CRM (Customer) hinterlegt werden können, und das Alter soll in Verträgen/Angeboten automatisch berechnet und angezeigt werden.

## Data Model

### Customer (CRM)
| Column | Type | Constraints |
|--------|------|-------------|
| `birthdate` | `date` | nullable |

- `birthdate` ist ein reines CRM-Feld auf dem Customer-Model
- Keine Speicherung auf User/Contract/ContractSigner
- Alter wird **stets zur Laufzeit berechnet** (birthdate vs. Referenzdatum), nie gespeichert

### Age Calculation
```php
// Alter in Jahren zum Referenzdatum
$age = $birthdate->diffInYears($referenceDate);
```

- Referenzdatum: `Vertragsdatum` (contract.created_at / closes_at) oder `Leistungsdatum`
- Bei online-signierten Verträgen: `signed_at` des Signers

## API

### Customer Resource (CustomerResource)
Erweiterung um:
```json
{
  "id": "...",
  "name": "...",
  "birthdate": "2000-03-15",
  ...
}
```

### Customer Controller
- `birthdate` in `store`/`update` Validation: `nullable|date|before:today|before:-10years`
- Mindestalter 10 Jahre (Plausibilitätsprüfung)

## Frontend

### CustomerModal
- Neues Feld "Geburtsdatum" (`<input type="date">`)
- Optional, kein `required`
- Zod-Schema: `z.string().optional()`

## Verträge / Angebote

### Contract PDF (`pdf/contract_signatures.blade.php`)
- Wenn `billing_details.birthdate` gesetzt ist: berechnetes Alter anzeigen
- Position: neben Name/Adresse des Vertragspartners
- Format: "Alter: 25 Jahre (geb. 15.03.2000)"

### Online Sign View (`ContractSignView.tsx`)
- Wenn `contract.billing_details.birthdate` gesetzt ist: Alter berechnen und anzeigen
- Position: im billing-details Abschnitt
- Referenzdatum: aktuelles Datum (zum Signierzeitpunkt)

## Migration
- V026: `ALTER TABLE customers ADD birthdate DATE NULL`

## Security / Compliance
- Keine Speicherung des Alters — nur live berechnet
- birthdate unterliegt DSGVO (Löschfristen wie andere CRM-Daten)
