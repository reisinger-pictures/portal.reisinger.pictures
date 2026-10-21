---
domain: infrastructure
topic: email-system
status: active
---

# Technical Concept: Email System & Notifications

## 1. Hardcoded Templates (Blade)
- Um die Architektur und das Deployment zu vereinfachen, wurde das datenbankgestützte Template-System (EmailTemplate Model) entfernt.
- **Architektur-Richtlinie (STRIKT):** E-Mails werden **ausschließlich** über native Laravel Blade Views gerendert (z.B. `custom.blade.php`, `activate.blade.php`, `notification.blade.php`).
- **Verbot von Inline-HTML:** Das Verwenden von `Mail::html()` mit hartcodierten HTML-Strings innerhalb von Controllern ist untersagt, um das Corporate Design (Logo, Layout) konsistent über alle System-E-Mails hinweg zu garantieren.

## 2. Opt-In & Benachrichtigungs-Management (DSGVO / UX)

- **Strikte Geschäftslogik (Client-Only):** Allgemeine E-Mail-Benachrichtigungen zu Galerien werden **ausschließlich** an Kunden versendet. Diese Regel ist strikt und das System ist nicht dafür vorgesehen, Fotografen über diesen Weg Updates zu schicken.
- **Skalierbares UI-Design (Component Reuse):** Obwohl logisch nur Kunden benachrichtigt werden, sollen UI-Komponenten (wie Opt-In-Toggles) so generisch und rollenunabhängig wie möglich gebaut werden. Das Ziel ist es, "ganz andere UIs" für verschiedene Rollen zu vermeiden. Bestehende Komponenten sollen stattdessen leicht für andere Rollen oder zukünftige Features "upgegradet" werden können.
- **Standardmäßig Deaktiviert:** Kunden und zugewiesene Benutzer erhalten nicht automatisch E-Mails über Galerie-Updates. Das Galerie-Abonnement hat **keine Flag-Spalte**: die Zeile in `gallery_notification_subscriptions` *ist* das Abonnement, und sie existiert nur, solange der Benutzer abonniert hat (siehe 2.1). Das Gruppen-Abonnement hat **keine Flag-Spalte** mehr: die Zeile in `group_notification_subscriptions` *ist* das Abonnement (siehe 2.2).
- **Lokaler Toggle:** Kunden können in der jeweiligen Galerieansicht (`DeliveryView` / `SelectionView`) Benachrichtigungen gezielt aktivieren.
- **Zentrale Verwaltung:** Über die zentrale Ansicht "Benachrichtigungen" (`/notifications`) können Nutzer ihre Abonnements für alle zugewiesenen Meta-Galerien (Gruppen) und Einzel-Galerien zentral verwalten.
- **Filterung beim Versand:** Der `MailController` filtert beim manuellen oder automatischen Versand strikt nach Nutzern, die das Abonnement aktiviert haben.

### 2.1 Das Galerie-Abonnement hat eine eigene Tabelle (Owner-Entscheidung 2026-10-20)

**Problem (gemessen).** `NotificationController::toggleGalleryOptIn()` schrieb das Abonnement in `user_galleries` — dieselbe Pivot-Tabelle, die den Mandanten-Grant trägt (`AuthorizationService::getAllowedGalleryIds()`, Schritt 1 „direct assignments"). Ein Abonnement erzeugte damit einen Grant:

- bei einer **Auswahl**-Galerie gewährt die Zeile nichts (D-39 filtert Nicht-Delivery-Galerien aus dem Reader heraus),
- bei einer **Delivery**-Galerie wird die Zeile als Grant gewertet — und sie **überlebt die Org-Mitgliedschaft**, die das Abonnement gerechtfertigt hat.

Messung auf dem unveränderten Stand (`backend/tests/Feature/Notification/GalleryNotificationSubscriptionTest.php`): die beiden Regressionstests waren vor der Änderung rot — das Opt-in legte eine `user_galleries`-Zeile an, und `getAllowedGalleryIds()` lieferte die Galerie weiterhin, nachdem `users.org_id` geleert war.

**Entscheidung.** Das Galerie-Abonnement bekommt eine eigene Tabelle `gallery_notification_subscriptions`. Die Alternative — ein Opt-in-Marker in derselben Grant-Pivot — ist verworfen: die Pivot enthielte die Zeilen weiter, und jeder künftige Reader von `user_galleries` müsste wissen, welche Zeilen Grants sind. Ein Abonnement ist keine Berechtigung.

**Schema (V047).**

| Spalte | Typ | Regel |
|---|---|---|
| `id` | bigint, PK | Surrogatschlüssel |
| `user_id` | uuid, FK → `users.id` | `cascadeOnDelete` — eine Abonnementzeile ohne Konto wäre unzugehörig |
| `gallery_id` | uuid, FK → `galleries.id` | `cascadeOnDelete` — mit der Galerie verfällt das Abonnement |
| `created_at`, `updated_at` | timestamp | |

**Indizes.**
- `gallery_notification_subscriptions_user_gallery_unique` — **unique** über `(user_id, gallery_id)`. Das ist der natürliche Schlüssel: ein Benutzer hat pro Galerie höchstens ein Abonnement.
- `gallery_notification_subscriptions_gallery_id_index` — über `(gallery_id)`. Das ist die Abfrage, die der Mailversand stellt („wer ist für diese Galerie abonniert?“). Die Unique-Index auf `(user_id, gallery_id)` beantwortet die Gegenrichtung („welche Galerien hat dieser Benutzer abonniert?“).

**Backfill.** Jede Zeile in `user_galleries` mit `wants_notifications = 1` wird zu genau einer Zeile in `gallery_notification_subscriptions` (`user_id`, `gallery_id` übernommen, Zeitstempel = Migrationszeitpunkt). Es geht **kein** Abonnement verloren. Die Pivot-Zeilen selbst werden **nicht** angefasst: weder gelöscht noch umgeflaggt.

**Rollback.** `down()` lässt ausschließlich die neue Tabelle fallen. Weil `up()` die Pivot-Zeilen nicht verändert, trägt das Legacy-Flag das Abonnement nach dem Rollback unverändert weiter. Was der Rollback verliert, sind Abonnements, die **nach** der Migration geschrieben wurden — das ist die dokumentierte Kostenlage dieses Features.

**Legacy-Spalte.** `user_galleries.wants_notifications` ist nach der Migration eine Legacy-Spalte: die Anwendung liest und schreibt sie nicht mehr. Die Grant-Relation `User::galleries()` lädt sie nicht einmal mehr aus (`withPivot('wants_notifications')` ist entfernt); die Migration und das Reparatur-Kommando lesen die Spalte direkt aus der Tabelle. Sie bleibt in der Datenbank, weil die Reparaturliste sie als Merkmal braucht; ein späterer Rollout kann sie fallen lassen, nachdem die Reparaturliste abgearbeitet ist.

**IDOR-Ausnahme (eng, dokumentiert).** `toggleGalleryOptIn()` prüft die Verknüpfung des Aufrufers mit der Galerie über `canAccessGallery()`, sobald ein Request eine **Abonnementzeile anlegen würde**: ohne verifizierte Verknüpfung antwortet der Request mit `403`. Ein Request für eine Galerie, für die der Aufrufer **bereits abonniert** ist, wird stattdessen aus der eigenen Abonnementzeile des Aufrufers beantwortet — er ändert nur diese Zeile des Aufrufers und gewährt nichts: er entfernt sie entweder (Widerruf) oder aktualisiert ihr `updated_at` (wiederholter Subscribe-Request). Er legt nie eine Zeile an, die es nicht gibt, und gewährt nie Zugang. Grund: ein Abonnement überlebt den Zugang, der es gerechtfertigt hat, und der Aufrufer muss das Übrige widerrufen können. Die Ausnahme ist in [Roles & Access Management](../auth/01-roles-and-access.md) §4 festgehalten. Sie weitet keinen Zugriff auf — das **Anlegen** einer Abonnementzeile bleibt vollständig geschützt, und die Zeile eines anderen Benutzers wird nie gelesen oder geschrieben.

**Namens-Offenlegung in der Einstellungs-Ansicht.** `/api/notifications/preferences` listet auch ein Abonnement ohne Grant, damit es widerrufbar bleibt. Der **Name** einer Galerie, die der Aufrufer nicht mehr erreichen kann, wird dabei **nicht** offengelegt: der Eintrag trägt stattdessen den Platzhalter `Nicht zugängliche Galerie`; die ID bleibt erhalten, weil sie zum Widerrufen nötig ist. Maßgeblich ist der aktuelle Erreichbarkeitsbefund des Grant-Readers (`getAllowedGalleryIds()`) — nur eine dort enthaltene Galerie wird namentlich genannt. Dieselbe Regel gilt für die direkt zugewiesenen Galerien der Liste: ein Legacy-Grant einer fremden Marke wird dadurch ebenfalls nicht mehr benannt. Offengelegt bleiben die ID und der Galerietyp (`delivery`/`selection`) — mehr braucht der Anzeigezustand nicht.

**Die Legacy-Mehrdeutigkeit (bleibt offen — mit dokumentiertem Residual).** Jede `user_galleries`-Zeile mit `wants_notifications = 1` ist mehrdeutig:

1. eine **reine Opt-in-Zeile** — die Zeile entstand durch das Opt-in selbst und wirkt heute als Grant (der Defekt), oder
2. ein **echter Grant**, der zusätzlich abonniert wurde.

Das Flag allein trennt die beiden Fälle nicht: die Pivot führt keinen Zeitstempel, keine Provenienz und keine zweite Spalte, die „Zeile aus dem Grant-Pfad“ von „Zeile aus dem Opt-in-Pfad“ unterscheidet. Der Grant-Pfad legt die Zeile mit demselben Default an (`wants_notifications = 0`), den das Opt-in danach auf `1` dreht.

Daraus folgt verbindlich:

- Die Migration **löscht keine Pivot-Zeile**. Ein echter Grant darf nicht versehentlich entzogen werden.
- Die Migration **übernimmt den Abonnement-Zustand** für jede Zeile mit Opt-in in die neue Tabelle.
- Die **reinen Opt-in-Zeilen, die weiterhin als Grant wirken**, kann die Migration nicht auflösen. Eine belastbare Regel gibt es nicht: „die Galerie gehört zu keinem legitimen Grant-Set dieses Benutzers“ ist nicht berechenbar, weil die untersuchte Zeile *selbst* der direkte Grant ist und jeder andere Grant-Pfad (Gruppen-Grant, Org-Mitgliedschaft, Fotografen-Oberfläche, transiente Einladung) inzwischen zurückgezogen sein kann — die Pivot-Zeile trägt dann zu Recht den Zugang. Ein Reader, der die geflaggten Zeilen stillschweigend ignorierte, würde echte Grants ohne Audit-Trail und ohne Prüfung des Owners entziehen.
- Der **Grant-Reader braucht daher keine Änderung**: für neue Zeilen ist das Opt-in gar nicht mehr im Grant-Pfad, für die Legacy-Zeilen bleibt der Zustand bis zur Reparatur durch den Owner bestehen.

**Residual mit prüfbarem Kommando (Variante b).** Der Rest wird nicht stillschweigend bereinigt, sondern über ein Kommando mit Dry-Run als Standard sichtbar gemacht und danach bewusst ausgeführt:

```bash
# Dry-Run: listet jede geflaggte Zeile mit ihrer Einstufung und löscht nichts
php artisan notifications:prune-legacy-opt-in-grants
php artisan notifications:prune-legacy-opt-in-grants --only=<user_id>:<gallery_id> [--only=<user_id>:<gallery_id> …]

# Löscht ausschließlich die ausgewählten Zeilen, die zugleich `candidate` sind
php artisan notifications:prune-legacy-opt-in-grants --only=<user_id>:<gallery_id> --apply

# Löscht ALLE `candidate`-Zeilen — auch die echten Grants unter ihnen
php artisan notifications:prune-legacy-opt-in-grants --apply
```

Das Kommando klassifiziert jede geflaggte Pivot-Zeile über den **eigenen** Grant-Reader (`AuthorizationService::getAllowedGalleryIds()`), einmal mit und einmal ohne die Zeile:

| Klasse | Bedeutung | Behandlung |
|---|---|---|
| `redundant` | Die Galerie ist auch ohne die Zeile erreichbar — ein anderer Grant-Pfad trägt den Zugang. | **behalten**, nur gemeldet |
| `inert` | Die Zeile gewährt auch mit sich selbst nichts (Auswahl-Galerie nach D-39, Marken-Mismatch, …). | **behalten**, nur gemeldet |
| `orphaned` | Der User- oder der Galerie-Datensatz existiert nicht mehr. | **behalten**, nur gemeldet und mitgezählt |
| `unmatched` | Das ausgewählte Paar trifft keine geflaggte Zeile. | **nichts zu löschen**; gemeldet, mitgezählt, Exit-Code 1 (Ausnahme: die Datenbank hat überhaupt keine geflaggte Zeile → Exit-Code 0) |
| `candidate` | Die Zeile ist die **einzige** aktuelle Begründung für den Zugang zu einer Delivery-Galerie. | **vorgeschlagen**; gelöscht mit `--apply` — ohne Auswahl **alle** Kandidaten, mit Auswahl **nur** die ausgewählten |

**Was `candidate` bedeutet — und was nicht.** `candidate` heißt: *die Zeile ist heute die einzige Begründung für den Zugang*. Das Flag `wants_notifications` **kann nicht** unterscheiden, ob die Zeile durch das Opt-in entstanden ist (der Defekt) oder ob sie ein echter, direkt erteilter Grant ist, der zusätzlich abonniert wurde und dessen sonstige Begründigung inzwischen entzogen wurde. Beide Fälle erzeugen dieselbe Form. Eine `candidate`-Zeile ist deshalb **kein** Beweis für eine reine Opt-in-Zeile: sie muss **einzeln geprüft** — oder von Hand repariert — werden, bevor sie entfernt wird.

**Das Auswählen einzelner Zeilen.** `--only=<user_id>:<gallery_id>` (wiederholbar) oder `--only-ids=<datei>` (eine Zeile `user_id:gallery_id` pro Dateizeile) beschränkt die Aktion auf genau diese Zeilen. `--apply` löscht **ausschließlich** die ausgewählten Zeilen, die zugleich `candidate` sind. Eine ausgewählte Zeile, die **nicht** `candidate` ist, wird **nicht** gelöscht, sondern als solche gemeldet. Eine `candidate`-Zeile, die **nicht** ausgewählt wurde, bleibt ebenfalls stehen — sie wird nur gemeldet.

**Eine Auswahl, die nichts trifft.** Ein ausgewähltes Paar, das auf **keine** geflaggte Zeile passt, wird ebenfalls gemeldet — mit der Einstufung `unmatched`, einer eigenen Warnzeile und einem Zähler in der Zusammenfassung (`unmatched: N`). Die beiden Fälle werden unterschieden: es existiert eine Pivot-Zeile für das Paar, trägt aber kein Opt-in-Flag („there is nothing to repair“), oder es existiert überhaupt keine Pivot-Zeile für das Paar (vermutlich ein Tippfehler in der ID). Ein solcher Lauf endet mit **Exit-Code 1**: die Auswahl war nicht vollständig erfüllbar, und ein Tippfehler darf nicht wie eine gelungene Reparatur aussehen.

Die eine Ausnahme: eine Datenbank, in der **überhaupt keine** geflaggte Zeile liegt, braucht keine Reparatur. Dort endet der Lauf mit Exit-Code **0** — ein Skript, das Zeilen auswählt und auf eine saubere Datenbank trifft, darf nicht wie ein Fehlschlag aussehen. Beide Exit-Codes sind im Kommando-Docblock festgehalten.

**`--apply` ohne Auswahl.** Das Kommando löscht dann **alle** `candidate`-Zeilen — und damit auch die echten Grants unter ihnen. Das ist ein grobes Werkzeug; es existiert für den Fall, dass die gesamte Liste geprüft wurde. Der sichere Weg ist die zeilenweise Auswahl.

**`--limit`.** Die Vorgabe begrenzt die Zahl der geprüften Zeilen (Standard 1000, `0` = keine Grenze). Wurde die Liste gekürzt, verweigert `--apply` **ohne** Auswahl die Löschung (Exit-Code 1, nichts gelöscht) — eine gekürzte Liste, die aussieht wie eine vollständige, wäre gefährlicher als ein Fehler. Mit Auswahl sind die ausgewählten Zeilen immer vollständig geprüft (sie liegen nie hinter dem Limit), dann darf `--apply` laufen.

Der Dry-Run nennt für jede Zeile Benutzer, Galerie, Galerietyp, Einstufung, Auswahl und Begründung, damit der Owner die Liste lesen und entscheiden kann. Ohne `--apply` wird nichts gelöscht.

**Nicht Teil dieser Entscheidung.** Die Gruppen-Opt-in-Pivot `user_gallery_groups` trägt denselben strukturellen Fehler: ein Gruppen-Opt-in (`toggleGroupOptIn()`) erzeugt einen **Gruppen-Grant**, und der Gruppen-Grant umfasst den ganzen Subtree. Diese Entscheidung deckt nur das Galerie-Abonnement ab; für die Gruppen-Pivot ist eine eigene Owner-Entscheidung offen — sie liegt inzwischen vor: [D-46](owner-decisions.md#d-46-the-group-notification-subscription-has-its-own-table), [AD-10](tech/07-architectural-decisions.md) und Abschnitt 2.2.

### 2.2 Das Gruppen-Abonnement hat eine eigene Tabelle (Owner-Entscheidung 2026-10-21)

**Problem (gemessen).** `NotificationController::toggleGroupOptIn()` schrieb das Abonnement in `user_gallery_groups` — dieselbe Pivot-Tabelle, die den **Gruppen-Grant** trägt (`AuthorizationService::getAllowedGalleryIds()`, Schritt 2 „group assignments (recursive)“). Ein Abonnement erzeugte damit einen Grant, und der Gruppen-Grant umfasst den **ganzen Subtree** der Gruppe.

Messung auf dem unveränderten Stand (`backend/tests/Feature/Notification/GroupNotificationSubscriptionTest.php`, 2026-10-21):

| Messung | Ergebnis |
|---|---|
| Schreibziel des Gruppen-Opt-ins (HTTP `200`) | `user_gallery_groups.wants_notifications = 1` auf der **Grant-Zeile** (keine eigene Abonnementtabelle) |
| `getAllowedGalleryIds()` nach dem Opt-in | **2 von 2** Galerien des Subtrees (Gruppe + Kindgruppe) |
| `getAllowedGalleryIds()` **nachdem `users.org_id` geleert war** | **2 von 2** — die geflaggte Zeile überlebt die Org-Mitgliedschaft, die das Abonnement gerechtfertigt hat |
| Alt-Opt-in-Zeile (Opt-in-Pfad vor dem IDOR-Guard vom 2026-07-12, der die Pivot-Zeile selbst anlegte) | gewährt denselben Subtree mit **und** ohne Org-Mitgliedschaft |
| Entzug der Gruppen-Mitgliedschaft (`sync()` auf `gallery_group_ids`) | **löscht** die Pivot-Zeile — und damit das Abonnement mit: Abo und Grant waren eine Zeile |

Der Defekt hat damit **zwei** Seiten: das Abo erzeugt (und verlängert) einen Grant über den ganzen Subtree, **und** es geht mit dem Grant verloren, sobald die Mitgliedschaft endet. Ein Abonnement ist keine Berechtigung — und es ist auch kein Bestandteil des Grants.

**Entscheidung.** Das Gruppen-Abonnement bekommt eine eigene Tabelle `group_notification_subscriptions`, analog zum Galerie-Abonnement (2.1). Die Alternative — ein Opt-in-Marker in derselben Grant-Pivot — ist verworfen: die Pivot enthielte die Zeilen weiter, und jeder künftige Reader von `user_gallery_groups` müsste wissen, welche Zeilen Grants sind.

**Schema (V048).**

| Spalte | Typ | Regel |
|---|---|---|
| `id` | bigint, PK | Surrogatschlüssel |
| `user_id` | uuid, FK → `users.id` | `cascadeOnDelete` — eine Abonnementzeile ohne Konto wäre unzugehörig |
| `gallery_group_id` | uuid, FK → `gallery_groups.id` | `cascadeOnDelete` — mit der Gruppe verfällt das Abonnement |
| `created_at`, `updated_at` | timestamp | |

**Indizes.**
- `group_notification_subscriptions_user_group_unique` — **unique** über `(user_id, gallery_group_id)`. Das ist der natürliche Schlüssel: ein Benutzer hat pro Gruppe höchstens ein Abonnement.
- `group_notification_subscriptions_gallery_group_id_index` — über `(gallery_group_id)`. Das ist die Abfrage, die der Mailversand stellt („wer ist für diese Gruppe abonniert?“); der Unique-Index auf `(user_id, gallery_group_id)` beantwortet die Gegenrichtung („welche Gruppen hat dieser Benutzer abonniert?“).

**Backfill.** Jede Zeile in `user_gallery_groups` mit `wants_notifications = 1` wird zu genau einer Zeile in `group_notification_subscriptions` (`user_id`, `gallery_group_id` übernommen, Zeitstempel = Migrationszeitpunkt). Es geht **kein** Abonnement verloren. Die Pivot-Zeilen selbst werden **nicht** angefasst: weder gelöscht noch umgeflaggt.

**Rollback.** `down()` lässt ausschließlich die neue Tabelle fallen. Weil `up()` die Pivot-Zeilen nicht verändert, trägt das Legacy-Flag das Abonnement nach dem Rollback unverändert weiter. Was der Rollback verliert, sind Abonnements, die **nach** der Migration geschrieben wurden — das ist die dokumentierte Kostenlage dieses Features.

**Legacy-Spalte.** `user_gallery_groups.wants_notifications` ist nach der Migration eine Legacy-Spalte: die Anwendung liest und schreibt sie nicht mehr. Die Grant-Relation `User::galleryGroups()` lädt sie nicht einmal mehr aus (`withPivot('wants_notifications')` ist entfernt); die Migration und das Reparatur-Kommando lesen die Spalte direkt aus der Tabelle. Sie bleibt in der Datenbank, weil die Reparaturliste sie als Merkmal braucht; ein späterer Rollout kann sie fallen lassen, nachdem die Reparaturliste abgearbeitet ist.

**IDOR-Ausnahme (eng, dokumentiert).** `toggleGroupOptIn()` prüft die Verknüpfung des Aufrufers mit der Gruppe über die Pivot `user_gallery_groups`, sobald ein Request eine **Abonnementzeile anlegen würde**: ohne verifizierte Verknüpfung antwortet der Request mit `403` — das Verhalten bleibt gegenüber dem Stand vor dieser Entscheidung unverändert. Ein Request für eine Gruppe, für die der Aufrufer **bereits abonniert** ist, wird stattdessen aus der eigenen Abonnementzeile des Aufrufers beantwortet — er ändert nur diese Zeile des Aufrufers und gewährt nichts: er entfernt sie entweder (Widerruf) oder aktualisiert ihr `updated_at` (wiederholter Subscribe-Request). Er legt nie eine Zeile an, die es nicht gibt, und gewährt nie Zugang. Grund: ein Abonnement überlebt den Zugang, der es gerechtfertigt hat, und der Aufrufer muss das Übrige widerrufen können. Die Ausnahme ist in [Roles & Access Management](../auth/01-roles-and-access.md) §4 festgehalten.

**Namens-Offenlegung in der Einstellungs-Ansicht.** `/api/notifications/preferences` listet auch ein Gruppen-Abonnement ohne Grant, damit es widerrufbar bleibt. Der **Name** einer Gruppe, die der Aufrufer nicht mehr erreichen kann, wird dabei **nicht** offengelegt: der Eintrag trägt stattdessen den Platzhalter `Nicht zugänglicher Ordner`; die ID bleibt erhalten, weil sie zum Widerrufen nötig ist. Maßgeblich ist derselbe Erreichbarkeitsbefund wie bei den Galerien (der Grant-Reader `getAllowedGalleryIds()`), auf den Gruppen bezogen: eine Gruppe ist erreichbar, wenn der Aufrufer ihr zugewiesen ist oder eine Galerie ihres Subtrees erreichen kann. **Die Zuweisung allein genügt:** eine Gruppe, deren Subtree nichts gewährt (eine leere Gruppe oder eine durch D-39 gesperrte), ist trotzdem eine Gruppe des Aufrufers und wird benannt — die Zuweisung ist die eigene Angabe des Aufrufers, nicht der Inhalt des Grants. Umgekehrt bleibt eine Gruppe, der der Aufrufer **weder zugewiesen ist noch eine Galerie ihres Subtrees erreichen kann**, redigiert; ein Abonnement allein ist keine Zuweisung und kein Zugriff. Offengelegt bleiben die ID und der Typ (`group`) — mehr braucht der Anzeigezustand nicht. Die Antwortform bleibt unverändert: `groups[]` trägt weiterhin `id`, `name`, `type`, `wants_notifications`.

**Die Legacy-Mehrdeutigkeit (bleibt offen — mit dokumentiertem Residual).** Jede `user_gallery_groups`-Zeile mit `wants_notifications = 1` ist mehrdeutig:

1. eine **reine Opt-in-Zeile** — die Zeile entstand durch das Opt-in selbst und wirkt heute als Grant über den ganzen Subtree (der Defekt), oder
2. ein **echter Grant**, der zusätzlich abonniert wurde.

Das Flag allein trennt die beiden Fälle nicht: die Pivot führt keinen Zeitstempel, keine Provenienz und keine zweite Spalte, die „Zeile aus dem Grant-Pfad“ von „Zeile aus dem Opt-in-Pfad“ unterscheidet. Der Grant-Pfad legt die Zeile mit demselben Default an (`wants_notifications = 0`), den das Opt-in danach auf `1` dreht.

Daraus folgt verbindlich:

- Die Migration **löscht keine Pivot-Zeile**. Ein echter Grant darf nicht versehentlich entzogen werden.
- Die Migration **übernimmt den Abonnement-Zustand** für jede Zeile mit Opt-in in die neue Tabelle.
- Die **reinen Opt-in-Zeilen, die weiterhin als Grant wirken**, kann die Migration nicht auflösen. Eine belastbare Regel gibt es nicht: „die Gruppe gehört zu keinem legitimen Grant-Set dieses Benutzers“ ist nicht berechenbar, weil die untersuchte Zeile *selbst* der Gruppen-Grant ist und jeder andere Grant-Pfad (Direktzuweisung, Org-Mitgliedschaft, Fotografen-Oberfläche, transiente Einladung) inzwischen zurückgezogen sein kann — die Pivot-Zeile trägt dann zu Recht den Zugang. Ein Reader, der die geflaggten Zeilen stillschweigend ignorierte, würde echte Grants ohne Audit-Trail und ohne Prüfung des Owners entziehen.
- Der **Grant-Reader braucht daher keine Änderung**: für neue Zeilen ist das Opt-in gar nicht mehr im Grant-Pfad, für die Legacy-Zeilen bleibt der Zustand bis zur Reparatur durch den Owner bestehen.

**Residual mit prüfbarem Kommando (Variante b).** Der Rest wird nicht stillschweigend bereinigt, sondern über ein Kommando mit Dry-Run als Standard sichtbar gemacht und danach bewusst ausgeführt:

```bash
# Dry-Run: listet jede geflaggte Zeile mit ihrer Einstufung und löscht nichts
php artisan notifications:prune-legacy-group-opt-in-grants
php artisan notifications:prune-legacy-group-opt-in-grants --only=<user_id>:<gallery_group_id> [--only=<user_id>:<gallery_group_id> …]

# Löscht ausschließlich die ausgewählten Zeilen, die zugleich `candidate` sind
php artisan notifications:prune-legacy-group-opt-in-grants --only=<user_id>:<gallery_group_id> --apply

# Löscht ALLE `candidate`-Zeilen — auch die echten Grants unter ihnen
php artisan notifications:prune-legacy-group-opt-in-grants --apply
```

Das Kommando klassifiziert jede geflaggte Pivot-Zeile über den **eigenen** Grant-Reader (`AuthorizationService::getAllowedGalleryIds()`), einmal mit und einmal ohne die Zeile:

| Klasse | Bedeutung | Behandlung |
|---|---|---|
| `redundant` | Die Gruppe ist auch ohne die Zeile erreichbar — ein anderer Grant-Pfad trägt den Zugang. | **behalten**, nur gemeldet |
| `inert` | Die Zeile gewährt auch mit sich selbst nichts (leere Gruppe, Marken-Mismatch, D-39-Sperre des Subtrees, …). | **behalten**, nur gemeldet |
| `orphaned` | Der User- oder der Gruppen-Datensatz existiert nicht mehr. **Defensive Klassifikation:** beide Fremdschlüssel von `user_gallery_groups` löschen mit (`ON DELETE CASCADE`, `V001`), ein gelöschter User oder eine gelöschte Gruppe löscht die Pivot-Zeile also selbst. Der Fall ist nur mit aufgeschobener Fremdschlüsselprüfung erreichbar — in einer intakten Datenbank sieht der Operator ihn nie. | **behalten**, nur gemeldet und mitgezählt |
| `unmatched` | Das ausgewählte Paar trifft keine geflaggte Zeile. | **nichts zu löschen**; gemeldet, mitgezählt, Exit-Code 1 (Ausnahme: die Datenbank hat überhaupt keine geflaggte Zeile → Exit-Code 0) |
| `candidate` | Die Zeile ist die **einzige** aktuelle Begründung für den Zugang zum Subtree der Gruppe. | **vorgeschlagen**; gelöscht mit `--apply` — ohne Auswahl **alle** Kandidaten, mit Auswahl **nur** die ausgewählten |

**Was `candidate` bedeutet — und was nicht.** `candidate` heißt: *die Zeile ist heute die einzige Begründung für den Zugang*. Das Flag `wants_notifications` **kann nicht** unterscheiden, ob die Zeile durch das Opt-in entstanden ist (der Defekt) oder ob sie ein echter, direkt erteilter Grant ist, der zusätzlich abonniert wurde und dessen sonstige Begründigung inzwischen entzogen wurde. Beide Fälle erzeugen dieselbe Form. Eine `candidate`-Zeile ist deshalb **kein** Beweis für eine reine Opt-in-Zeile: sie muss **einzeln geprüft** — oder von Hand repariert — werden, bevor sie entfernt wird.

**Das Auswählen einzelner Zeilen.** `--only=<user_id>:<gallery_group_id>` (wiederholbar) oder `--only-ids=<datei>` (eine Zeile `user_id:gallery_group_id` pro Dateizeile) beschränkt die Aktion auf genau diese Zeilen. `--apply` löscht **ausschließlich** die ausgewählten Zeilen, die zugleich `candidate` sind. Eine ausgewählte Zeile, die **nicht** `candidate` ist, wird **nicht** gelöscht, sondern als solche gemeldet. Eine `candidate`-Zeile, die **nicht** ausgewählt wurde, bleibt ebenfalls stehen — sie wird nur gemeldet.

**Eine Auswahl, die nichts trifft.** Ein ausgewähltes Paar, das auf **keine** geflaggte Zeile passt, wird ebenfalls gemeldet — mit der Einstufung `unmatched`, einer eigenen Warnzeile und einem Zähler in der Zusammenfassung (`unmatched: N`). Die beiden Fälle werden unterschieden: es existiert eine Pivot-Zeile für das Paar, trägt aber kein Opt-in-Flag („there is nothing to repair“), oder es existiert überhaupt keine Pivot-Zeile für das Paar (vermutlich ein Tippfehler in der ID). Ein solcher Lauf endet mit **Exit-Code 1**: die Auswahl war nicht vollständig erfüllbar, und ein Tippfehler darf nicht wie eine gelungene Reparatur aussehen.

Die eine Ausnahme: eine Datenbank, in der **überhaupt keine** geflaggte Zeile liegt, braucht keine Reparatur. Dort endet der Lauf mit Exit-Code **0** — ein Skript, das Zeilen auswählt und auf eine saubere Datenbank trifft, darf nicht wie ein Fehlschlag aussehen. Beide Exit-Codes sind im Kommando-Docblock festgehalten.

**`--apply` ohne Auswahl.** Das Kommando löscht dann **alle** `candidate`-Zeilen — und damit auch die echten Grants unter ihnen. Das ist ein grobes Werkzeug; es existiert für den Fall, dass die gesamte Liste geprüft wurde. Der sichere Weg ist die zeilenweise Auswahl.

**`--limit`.** Die Vorgabe begrenzt die Zahl der geprüften Zeilen (Standard 1000, `0` = keine Grenze). Wurde die Liste gekürzt, verweigert `--apply` **ohne** Auswahl die Löschung (Exit-Code 1, nichts gelöscht) — eine gekürzte Liste, die aussieht wie eine vollständige, wäre gefährlicher als ein Fehler. Mit Auswahl sind die ausgewählten Zeilen immer vollständig geprüft (sie liegen nie hinter dem Limit), dann darf `--apply` laufen.

Der Dry-Run nennt für jede Zeile Benutzer (E-Mail), Gruppe (Name), Einstufung, Auswahl und Begründung, damit der Owner die Liste lesen und entscheiden kann. **Die Spalte `selection` nennt zusätzlich das Paar `user_id:gallery_group_id`**, das `--only` nimmt — die Namen sind zum Lesen da, das Paar zum Auswählen; der Owner muss es nicht von Hand zuordnen. Ohne `--apply` wird nichts gelöscht.

## 3. Custom Messages & Preview
- Für manuelle E-Mails (z. B. der "E-Mail senden" Action-Button im Fotografen-Dashboard) existiert ein Modal, in dem der Fotograf eine individuelle HTML-Nachricht verfassen kann.
- Dieses Modal bietet einen Live-Preview-Toggle, der die eingegebenen Variablen (z. B. `{user_name}`, `{link}`) durch Dummy-Daten ersetzt und das finale HTML rendert.

## 4. Brand-Aware Emails (EMAIL-01–04)
- **Alle 13 Mailable-Klassen erben von `AbstractBrandAwareMailable`** — geprüft mit
  `grep -l 'extends AbstractBrandAwareMailable' backend/app/Mail/*.php | wc -l` → 13, und
  `ls -1 backend/app/Mail/*.php | wc -l` → 14 Dateien, davon eine abstrakte Basis.
  **Korrigiert 2026-09-28:** diese Passage behauptete *alle 7 Mail-Klassen* und nannte ein
  **Trait** `BrandAwareMail`. Es sind 13 konkrete Klassen, und die gemeinsame Basis ist eine
  **abstrakte Klasse**, kein Trait. Das Symbol `BrandAwareMail` existiert nirgends im Repo
  (`find backend -iname 'BrandAwareMail*'` ist leer).
- Die Basisklasse stellt sicher:
  - **Brand Context Restoration:** über die Eigenschaft `public ?Brand $brand = null` und
    `initializeBrand(?Brand $brand = null)`, die `BrandRegistry::current()` als Rückfall nutzt —
    beide in `AbstractBrandAwareMailable.php:17-24`. **Korrigiert 2026-09-28:** die Passage nannte
    eine Methode `ensureBrandContext()`; die existiert nirgends (`grep -rn ensureBrandContext
    backend/app backend/tests` ist leer).
  - **Frontend URL (EMAIL-01):** `brandFrontendUrl()` in `AbstractBrandAwareMailable.php:27`.
  - **Logo URL (EMAIL-02):** `brandLogoUrl()` — von den Mails über `$this->brandLogoUrl()`
    aufgerufen, z. B. `ModelInviteMail.php:28`.
  - **Sender (EMAIL-03):** `applyBrandFrom()` in `AbstractBrandAwareMailable.php:53`.
  - **BCC (EMAIL-04):** `brandBcc()` in `AbstractBrandAwareMailable.php:46`.
- Die Basisklasse erbt zusätzlich `implements ShouldQueue` mit `$tries = 3` und
  `$backoff = [30, 60, 120]` (`AbstractBrandAwareMailable.php:18` und `:20`) — deshalb greifen
  die Retry-Semantiken aus D-17 auch dort, wo der Aufrufer synchron sendet.
- Templates erhalten `$logoUrl` via `->with()` und nutzen `??=` als Fallback.
- Siehe `backend/app/Mail/AbstractBrandAwareMailable.php` und
  `features/infrastructure/06-multi-domain-branding.md`.


## 5. Local Testing
- Der lokale `Mailpit`-Service fängt alle ausgehenden E-Mails im Development-/CI-Testmodus ab.
- **Zustellungsnachweis:** PHPUnit-Integrationstests (mindestens der Invoice-/Checkout-Pfad) prüfen über die Mailpit-API die tatsächlich angekommene Nachricht und parse HTML/Token-Links. `Mail::fake()` ist ausschließlich für deterministische Enqueue-/Fault-Injection-Tests erlaubt und liefert keinen SMTP-Zustellungsnachweis. E2E-Tests verwenden Mailpit ebenfalls für die End-to-End-Linkprüfung.

## 6. Production SMTP Gate
- Im Produktionsbetrieb muss `MAIL_MAILER=smtp` gesetzt sein. `MAIL_SCHEME=smtp|smtps` und `MAIL_REQUIRE_TLS=true` sind zusammen mit `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` und `MAIL_FROM_NAME` Pflichtwerte. `MAIL_ENCRYPTION` ist in Laravel 13 kein gültiger SMTP-Schlüssel und darf nicht als TLS-Nachweis verwendet werden.
- `smtp` verwendet Symfony STARTTLS nur mit `require_tls=true`; `smtps` verwendet implizites TLS. Der effektive Transport wird vor Queue-, Scheduler- und PHP-FPM-Start geprüft. Der Gate validiert nur Konfiguration und Topologie, nicht die Zustellung oder Erreichbarkeit des SMTP-Providers.
- Die Queue kann einen SMTP-Transportfehler retryen. Invoice-Mail garantiert deshalb höchstens einen durable Enqueue-Vorgang, nicht exactly-once SMTP-Zustellung. Terminale Jobs bleiben in `failed_jobs` und benötigen eine dokumentierte Operator-Recovery.
- Mailpit darf ausschließlich als lokales/CI-Testfixture dokumentiert werden. Ein Mailpit-Test ist kein Live-Nachweis für den Produktions-SMTP- oder den Provider-Zustellungsstatus.
- Die Betriebsprüfung und die Grenzen der Queue-Semantik stehen im [Production Operations Runbook](29-production-operations-runbook.md).
