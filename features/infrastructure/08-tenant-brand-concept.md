# Tenant- & Brand-Begriff — Konzept (SOLL/Ist-Stand)

> **Status:** Beschreibt ausschließlich den **Stand des Systems** (Begriffe, Beziehungen,
> Abgrenzung). **Kein** Umsetzungsplan. Offene Punkte verweisen auf `AGENTS.todo.md`.
> Verknüpft: `features/infrastructure/06-multi-domain-branding.md`,
> `features/infrastructure/07-lightroom-multi-tenant-gap.md`.
> Erstellt 2026-06-29.

## 1. Zwei unterschiedliche Konzepte

Im System existieren **zwei klar getrennte** Konzepte, die beide umgangssprachlich mit
„Marke"/„Mandant" überlagert werden. Sie sind **nicht** synonym:

### 1.1 White-Label-Brand (Portal-Marke)

- **Bedeutung:** Das Portal läuft unter zwei öffentlichen Domains mit unterschiedlichem Branding:
  `reisinger.pictures` (B2B) und `story.reisinger.pictures` (B2C/SRP). Siehe
  `06-multi-domain-branding.md`.
- **Technischer Begriff:** `Brand` — Implementiert in `frontend/src/logic/useBrand.ts`,
  `backend/app/Http/Middleware/BrandContextMiddleware.php` (Host→Brand), markenspezifische
  Settings über das `srp_`-Präfix (SRP) vs. kein Präfix (B2B).
- **Werte:** `reisinger.pictures` | `story.reisinger.pictures`.
- **Gültigkeitsbereich:** Request-global (Host-basiert), wirkt auf Branding (Theme, Logo,
  Wasserzeichen, Bankdaten, Impressum).

### 1.2 Tenant (Kundengruppe / B2B-Mandant)

- **Bedeutung:** Eine **Kundengruppe** — ein B2B-Kunde (Firma, Organisation), der mehrere
  Portal-User bündelt, Sammelrechnung erhält und eigene Galerie-Bereiche hat.
- **Technischer Begriff:** `Tenant` — Implementiert in `backend/app/Models/Tenant.php`,
  Pivot `tenant_user`, `tenant_id` auf `galleries`/`gallery_groups`.
- **Werte:** Beliebig viele Mandanten-Instanzen (UUID-basiert).
- **Gültigkeitsbereich:** Daten-Ebene — steuert, welche Galerien/Bestellungen zu welcher
  Kundengruppe gehören und wer deren Daten sehen darf.

## 2. Warum kein Rename „Tenant → Brand"

Das Wort „Brand" ist im Code bereits **verbindlich für das White-Label-Portal** belegt
(`useBrand`, `BrandContextMiddleware`, `data-brand`-Attribut, `/brands/`-Assets). Ein Rename
des Tenant-Konzepts auf „Brand" würde zu einer **Doppelbelegung** führen (White-Label-Marke vs.
Kundengruppe) und ist daher verworfen. Beide Konzepte bleiben eigenständig.

## 3. UI-Begriff: „Mandant" ist irreführend

Aktuell wird im Frontend (Sidebar, Modals, CRM-Views) durchgehend **„Mandant"** verwendet.
Semantisch ist ein Tenant aber eine **Kundengruppe** (eine Firma mit mehreren eingeladenen
Mitarbeitern), nicht ein klassischer „Mandant" im Rechts-/Steuer-Sinn.

**Entscheidung (2026-06-29):** **„Organisation"** wurde als UI-Begriff gewählt.
Code-intern bleibt der Begriff `Tenant`/`tenant_id` unverändert — nur die deutschen UI-Labels
(~21 Stellen) müssen noch von „Mandant" auf „Organisation" aktualisiert werden (siehe `AGENTS.todo.md` T-01).

## 4. Beziehung der Konzepte zueinander

```
White-Label-Brand (Host-basiert)         Tenant (Daten-basiert)
───────────────────────────────          ───────────────────────
reisinger.pictures (B2B)        ─┐       Tenant A (Firma X)
story.reisinger.pictures (B2C/SRP) ┘     Tenant B (Firma Y)
                                       (jeder Tenant hat eigene User,
                                        Galerien, Sammelrechnungen)
```

- Die **White-Label-Brand** bestimmt Branding (Theme/Logo/Bankdaten) pro Domain.
- Der **Tenant** bestimmt Daten-Zugehörigkeit innerhalb des B2B-Bereichs.
- Beide Konzepte sind aktuell **nicht** auf DB-Ebene verzahnt (Tenant hat keine
  Brand-Spalte). Die markenspezifische Trennung von Rechnungen/Settings passiert rein über den
  Host-basierten `BrandContext` zur Request-Zeit.

## 5. Bekannte offene Punkte (verweisen auf `AGENTS.todo.md`)

Die folgenden Punkte sind **bekannte Lücken**, nicht in diesem Konzept-Doc zu lösen, sondern in
`AGENTS.todo.md` (T-01…T-04) als Aufgaben geführt:

- **T-01 UI-Begriff-Entscheidung:** Wahl des treffenden UI-Begriffs (siehe §3) + optionelle
  Anpassung der ~21 UI-Strings.
- **T-02 Queue/CLI-Brand-Leck:** `InvoiceMail` (ShouldQueue) und Cron-Jobs laufen ohne
  HTTP-Host → `config('app.brand')` ist dort leer → SRP-Rechnungen erhalten B2B-Branding im PDF.
- **T-03 Frontend Mandanten-Isolation:** B2B-Kacheln (Mandanten/CRM/Invoicing) werden auf
  `story.reisinger.pictures` für Kunden-Logins nicht ausgeblendet (rein rollenbasierte Steuerung).
- **T-04 Settings-Trennung Bugfixes:** `updateBillingDetails` speichert ungeprefixt (SRP-Daten
  unbrauchbar), `srp_*`-Dopplung in `updateLicenseTerms`, Watermark-Opacity asymmetrisch.

Dieses Doc dokumentiert nur den Stand; es enthält keine Lösungsschritte für T-01…T-04.
