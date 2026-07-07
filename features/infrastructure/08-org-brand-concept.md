# Org- & Brand-Begriff — Konzept

> **Status:** Beschreibt die Begriffe, Beziehungen und Abgrenzung von Brand und Org.
> Verknüpft: `features/infrastructure/06-multi-domain-branding.md`,
> `features/infrastructure/07-lightroom-multi-Org-gap.md`.

## 1. Zwei unterschiedliche Konzepte

Im System existieren **zwei klar getrennte** Konzepte, die beide umgangssprachlich mit
„Marke"/„Mandant" überlagert werden. Sie sind **nicht** synonym:

### 1.1 White-Label-Brand (Portal-Marke)

- **Bedeutung:** Das Portal läuft unter zwei öffentlichen Domains mit unterschiedlichem Branding:
  `reisinger.pictures` (B2B) und `buy.reisinger.pictures` (B2C/SRP). Siehe
  `06-multi-domain-branding.md`.
- **Technischer Begriff:** `Brand` — Implementiert in `frontend/src/logic/useBrand.ts`,
  `backend/app/Http/Middleware/BrandContextMiddleware.php` (Host→Brand), markenspezifische
  Settings über das `srp_`-Präfix (SRP) vs. kein Präfix (B2B).
- **Werte:** `reisinger.pictures` | `buy.reisinger.pictures`.
- **Gültigkeitsbereich:** Request-global (Host-basiert), wirkt auf Branding (Theme, Logo,
  Wasserzeichen, Bankdaten, Impressum).

### 1.2 Org (Kundengruppe / B2B-Mandant)

- **Bedeutung:** Eine **Kundengruppe** — ein B2B-Kunde (Firma, Organisation), der mehrere
  Portal-User bündelt, Sammelrechnung erhält und eigene Galerie-Bereiche hat.
- **Technischer Begriff:** `Org` — Implementiert in `backend/app/Models/Org.php`,
  `users.org_id` (direct FK) + pivots `gallery_org` / `gallery_group_org`.
- **Werte:** Beliebig viele Mandanten-Instanzen (UUID-basiert).
- **Gültigkeitsbereich:** Daten-Ebene — steuert, welche Galerien/Bestellungen zu welcher
  Kundengruppe gehören und wer deren Daten sehen darf.

## 2. Warum kein Rename „Tenant → Brand"

Das Wort „Brand" ist im Code bereits **verbindlich für das White-Label-Portal** belegt
(`useBrand`, `BrandContextMiddleware`, `data-brand`-Attribut, `/brands/`-Assets). Ein Rename
des Org-Konzepts auf „Brand" würde zu einer **Doppelbelegung** führen (White-Label-Marke vs.
Kundengruppe) und ist daher verworfen. Beide Konzepte bleiben eigenständig.

## 3. UI-Begriff: „Mandant" ist irreführend

Aktuell wird im Frontend (Sidebar, Modals, CRM-Views) durchgehend **„Mandant"** verwendet.
Semantisch ist ein Org aber eine **Kundengruppe** (eine Firma mit mehreren eingeladenen
Mitarbeitern), nicht ein klassischer „Mandant" im Rechts-/Steuer-Sinn.

**Entscheidung (2026-06-29):** **„Organisation"** wurde als UI-Begriff gewählt.
Code-intern bleibt der Begriff `Org`/`org_id` unverändert — nur die deutschen UI-Labels
(~21 Stellen) müssen noch von „Mandant" auf „Organisation" aktualisiert werden (siehe `AGENTS.todo.md` T-01).

## 4. Beziehung der Konzepte zueinander

```
White-Label-Brand (Host-basiert)         Org (Daten-basiert)
───────────────────────────────          ───────────────────────
reisinger.pictures (B2B)        ─┐       Org A (Firma X)
buy.reisinger.pictures (B2C/SRP) ┘      Org B (Firma Y)
                                       (jeder Org hat eigene User,
                                        Galerien, Sammelrechnungen)
```

- Die **White-Label-Brand** bestimmt Branding (Theme/Logo/Bankdaten) pro Domain.
- Der **Org** bestimmt Daten-Zugehörigkeit innerhalb des B2B-Bereichs.
- Beide Konzepte sind aktuell **nicht** auf DB-Ebene verzahnt (Org hat eine
  Brand-Spalte (`orgs.brand`)). Die markenspezifische Trennung von Rechnungen/Settings passiert rein über den
   Host-basierten `BrandContext` zur Request-Zeit.
