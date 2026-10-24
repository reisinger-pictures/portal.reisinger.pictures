# Organisation (B2B-Kunden-Gruppe) — Core Feature

Status: SOLL (Target State)  
Stand: 2026-07-03  
Autor: Florian Reisinger (Senior Architekt)

---

## 1. Konzept

Eine **Organisation** gruppiert mehrere Endkunden (User) zu einer wirtschaftlichen Einheit.
Typische Beispiele: Redaktion einer Zeitung, PR-Agentur, Unternehmenskommunikation.

### Abgrenzung zu Brand

| Konzept | Sichtbarkeit | Erkennung | Zweck |
|---------|-------------|-----------|-------|
| **Brand** | Unsichtbar für User | Automatisch via URL (Hostname) | White-Label (rp/srp), Logo, Theme |
| **Organisation** | Sichtbar im Admin und für zugeordnete User | Manuelle Erstellung + Auto-Join per Domain + Invites | B2B-Kunden-Gruppierung, Flatrate, Sammelrechnung |

Brands laufen ausschließlich im Hintergrund. Organisationen sind das sichtbare B2B-Feature.

---

## 2. Organisations-Modell

```php
// SOLL
[
    'id'                  => 'UUID',
    'name'                => 'string',           // Firmenname
    'domain'              => 'string|null',       // E-Mail-Domain für Auto-Join (z.B. "zeitung.at")
    'invoice_frequency'   => 'immediate|monthly|quarterly',
    'default_role_id'     => 'UUID|null',         // Organization default role (D-57)
    'default_flatrate_level' => 'none|web|print|original', // SOLL, fehlt
    'can_purchase_upgrades'  => 'boolean',        // Dürfen User trotz Flatrate kaufen? (SOLL, fehlt)
    'auto_join_policy'    => 'immediate|requires_invite|disabled', // SOLL, fehlt
    'brand'               => 'rp|srp',            // existiert
    'created_at', 'updated_at' => 'timestamps',
];
```

### 2.1. Auto-Join-Policy

| Policy | Verhalten |
|--------|-----------|
| `immediate` | User mit passender Domain wird bei Registration **sofort** zugeordnet (altes DomainMapping-Verhalten). |
| `requires_invite` | User mit passender Domain erhält **keine** automatische Zuordnung. Organisation wird benachrichtigt → Admin/Customer Manager muss via Invite freigeben. |
| `disabled` | Kein Auto-Join. Nur manuelle Invites möglich. |

### 2.2. Flatrate-Vererbung

- Organisation definiert `default_flatrate_level` + `can_purchase_upgrades`
- Beim Auto-Join oder Invite-Redeem erbt der User diese Werte
- User kann abweichende Werte haben (per Admin-Override)
- Power-User (`can_purchase_upgrades = true`) kann trotz Flatrate höhere Auflösungen kaufen (Delta-Pricing)

### 2.3. Rollen-Zuweisung

- Altes DomainMapping konnte **jede** Role zuweisen (admin, photographer, client)
- SOLL: `default_role_id` auf Organisation definiert die Rolle für Auto-Join
- Fallback: `client` (wie aktuell hartcodiert)
- Mapping-Löschung revoziert die Rolle (NEU — war im alten System ein Bug, dass persistierte Rollen nicht entfernt wurden)

### 2.4. Per-organization default role

Portal admins can configure an organization's default role as `client`, `power_user`, or `org_admin`. `null` resolves to `client`. The value is applied when a user joins through domain auto-join, is created by an org admin, accepts an organization invite (including a pending invite accepted during registration), or is newly added through `OrgController::syncUsers()`. Editing the default does not change existing members. Only portal admins may change the setting. The UI explains that choosing `org_admin` grants organization-scoped user-management rights to each joining account.

`photographer`, `admin`, and `super_admin` are not valid organization defaults. A photographer role is tied to the FTP credential lifecycle, while the global admin roles exceed an organization's scope. An unsupported legacy value resolves to `client` for new assignments and is surfaced for repair. Organization member removal and organization deletion fail closed until the setting is repaired; they must not silently detach an unsupported specialized role. Existing photographer roles are removed only through the lock-aware FTP revocation path.

---

## 3. User-Zuordnung

### 3.0. Einzelne Organisations-Mitgliedschaft

Ein User kann zu einem Zeitpunkt genau einer Organisation angehören. Das
Akzeptieren einer Einladung zu einer anderen Organisation ist die ausdrückliche
Einwilligung zum Wechsel. Bei diesem Wechsel werden vor der Zuweisung die
organisationsbezogenen Rollen `client`, `power_user` und `org_admin` der
bisherigen Mitgliedschaft entfernt; anschließend erhält der User genau die
Default-Rolle der Zielorganisation. `photographer`, `admin` und `super_admin`
bleiben erhalten. Eine manuelle Zuordnung über `OrgController::syncUsers()` darf
eine bestehende Mitgliedschaft dagegen nicht stillschweigend verschieben und
wird mit einem Konflikt abgelehnt. Ein User ohne `org_id` kann beitreten und
erhält die Default-Rolle der Zielorganisation. Domain-Auto-Join überschreibt
keine bestehende Mitgliedschaft. Ein Beitritt zur selben Organisation bleibt
idempotent.

Bei einer expliziten Entfernung aus einer Organisation werden die
organisationsbezogenen Rollen `client`, `power_user` und `org_admin` entfernt.
Dadurch kann eine spätere Mitgliedschaft in einer anderen Organisation keine
delegierten Rechte der vorherigen Organisation übernehmen. Die bestehenden
Flatrate- und Upgrade-Aufräumregeln bei Entfernung bzw. Organisationslöschung
bleiben davon unberührt.

Es gibt drei Wege, User einer Organisation zuzuordnen:

### 3.1. Auto-Join per Domain (Registration)

```
User registriert sich mit max@zeitung.at
  → Extrahiere Domain: "zeitung.at"
  → Finde Organisation mit domain = "zeitung.at"
  → Prüfe auto_join_policy:
      - immediate:  User sofort zuordnen + default_role + default_flatrate
      - requires_invite: Keine Zuordnung, Organisation benachrichtigen
      - disabled:   Keine Aktion
  → Prüfe Brand-Konflikt: Organisation.brand muss zum aktuellen Request-Brand passen
```

### 3.2. Manueller Invite (existiert bereits)

```
Admin/Customer Manager erstellt Invite für beliebige E-Mail
  → Invite-Link wird versendet
  → Empfänger klickt Link:
      - Ohne Account: Registration → Redeem
      - Mit Account:  Login → Redeem
  → User wird Organisation zugeordnet
```

**Cross-Domain-Invites**: `bla@example.com` kann zu Organisation `zeitung.at` eingeladen werden — unabhängig von der Domain.

### 3.3. Bestehende User zuordnen (Admin)

```
Admin sucht User per Name/E-Mail
  → Klickt "Organisation zuweisen"
  → User wird sofort zugeordnet
  → User erbt default_flatrate_level + default_role (optional)
```

---

## 4. Implikationen für existierende Migrationen

### 4.1. DomainMapping → Org

- `domain_mappings` Tabelle wurde in V004 **gelöscht** und durch `tenants` ersetzt
- Das alte Auto-Join Verhalten (immediate, jede Role) ging verloren
- SOLL: `tenants` um `default_role_id`, `default_flatrate_level`, `can_purchase_upgrades`, `auto_join_policy` erweitern

### 4.2. Doppelte Org-Zuordnung (V017)

- `galleries.tenant_id` + `gallery_groups.tenant_id` (direkt) + `gallery_group_org` (Pivot) = zwei konkurrierende Quellen
- SOLL: **Eine Quelle**. Entweder direkte `tenant_id` ODER Pivot, nicht beide.

### 4.3. Invoice Frequency

- `invoice_frequency` existiert auf `tenants` wird aber nirgends ausgewertet
- SOLL: Cron-Job `app:process-collective-invoices` muss `invoice_frequency` auswerten

---

---
