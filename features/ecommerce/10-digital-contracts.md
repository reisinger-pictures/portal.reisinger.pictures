---
domain: ecommerce
topic: digital-contracts
status: planned
---

# Technical Concept: Digital Contracts & Signatures

## 1. Legal Framework & Audit Trail (SES / Clickwrap)
Das System implementiert eine Einfache Elektronische Signatur (EES / SES) via Clickwrap-Verfahren.
Da ein Vertrag mehrere Unterzeichner haben kann, wird der Audit-Trail pro Unterzeichner geführt.
Die `contract_audit_logs` protokollieren:
* **opened:** Unterzeichner öffnet den Link (erfasst IP-Adresse, User-Agent, Timestamp).
* **heartbeat:** Aktives Lesen wird protokolliert.
* **signed:** Unterzeichner stimmt zu (erfasst finale IP, User-Agent, Timestamp und gewählte Rollen).

## 2. Multi-Signer & Signing Periods
Verträge sind nicht mehr strikt 1:1, sondern 1:n (Vertrag zu Unterzeichnern).
* **Signing Period:** Ein Vertrag hat einen Zustand (Draft, Active, Closed). Unterschriften können nur im `Active`-Zustand abgegeben werden. Die Periode kann manuell oder durch ein `closes_at` Datum beendet werden.
* **Rollen (Roles):** Pro Vertrag können verfügbare Rollen definiert werden (z.B. "Model", "Visagist", "Kunde"). 
* **Multiple Roles:** Ein Flag `allow_multiple_roles_per_signer` steuert, ob eine Person (z.B. Model & Visagist in Personalunion) mehrere Rollen gleichzeitig annehmen darf.
* **Join-Link vs. Direct-Link:** Unterzeichner können entweder vom Fotografen explizit eingeladen werden (Direct-Link) oder über einen generischen Join-Link selbst beitreten, ihre Daten angeben, eine Rolle wählen und unterschreiben (ideal für Fotowalks/Gruppen-TFP).

## 3. Database Schema
* **`contracts`**: `id` (UUID), `status` (draft, active, closed, cancelled), `billing_details` (JSON - falls der Vertrag kostenpflichtig ist, gibt es *einen* Rechnungsempfänger), `items` & `discounts` (JSON), `terms_html` (Text), `available_roles` (JSON Array), `allow_multiple_roles_per_signer` (Boolean), `join_token` (für öffentliche Invites), `closes_at` (Auto-Ende).
* **`contract_signers`**: `id`, `contract_id`, `name`, `email`, `roles` (JSON Array), `personal_token`, `status` (invited, joined, signed), `signed_at`.
* **`contract_audit_logs`**: `id`, `contract_id`, `contract_signer_id`, `action` (Enum), `ip_address`, `user_agent`, `created_at`.

## 4. Smart Document Integration & Auto-Invoicing
1. **Auto-Invoicing Trigger:** Das Auto-Invoicing (Erstellung von `Order` und `InvoiceSnapshot`) wird erst ausgelöst, wenn der Vertrag in den Status **`closed`** übergeht UND die `total_gross` > 0 ist. Rechnungsempfänger ist die in `billing_details` definierte Person.
2. **JWT-Fallback (Polyglot PDF):** Das finale PDF enthält weiterhin den `%OFFER_JWT:{token}%` Marker für den Re-Import in den Manual Invoice Builder.

## 5. Final PDF Compilation & Dispatch
Erst wenn der Vertrag geschlossen wird (`status = closed`), wird das finale, unveränderliche PDF generiert:
1.  **Seite 1-X:** Vertragsdetails und Text.
2.  **Signatur-Block:** Eine Auflistung aller Personen, die unterschrieben haben, inkl. ihrer jeweiligen Rollen.
3.  **Zertifikats-Anhang:** Das Digitale Signatur-Zertifikat listet tabellarisch für **jeden** Unterzeichner die IPs, Timestamps (Opened, Signed) auf.
4.  **Dispatch:** Nach der Generierung erhalten alle E-Mail-Adressen aus der `contract_signers` Tabelle das finale PDF zugesendet.
