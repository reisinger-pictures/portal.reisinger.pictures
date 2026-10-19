---
domain: ecommerce
topic: digital-contracts
status: implemented
---

# Technical Concept: Digital Contracts & Signatures

## 1. Legal Framework & Audit Trail (SES / Clickwrap)
Das System implementiert eine Einfache Elektronische Signatur (EES / SES) via Clickwrap-Verfahren.
Da ein Vertrag mehrere Unterzeichner haben kann, wird der Audit-Trail pro Unterzeichner geführt.
Die `contract_audit_logs` protokollieren:
* **opened:** Unterzeichner öffnet den Link (erfasst IP-Adresse, User-Agent, Timestamp).
* **heartbeat:** Erfolgreiche Content-Abrufe während einer aktiven Signaturphase werden als Leseaktivität protokolliert; ein eigener per-personal-token Rate-Limiter begrenzt Polling und gedrosselte Requests erzeugen keinen Heartbeat.
* **signed:** Unterzeichner stimmt zu (erfasst finale IP, User-Agent, Timestamp und gewählte Rollen).
* **modified:** Vertrag wurde im `active`-Status durch Admin bearbeitet (kein Signer-Bezug, erfasst Admin-IP).

## 2. Multi-Signer & Signing Periods
Verträge sind nicht mehr strikt 1:1, sondern 1:n (Vertrag zu Unterzeichnern).
* **Signing Period:** Ein Vertrag hat einen Zustand (Draft, Active, Closed, Cancelled). Unterschriften können nur im `Active`-Zustand abgegeben werden. Die Periode kann manuell oder durch ein `closes_at` Datum beendet werden.
* **Rollen (Roles):** Pro Vertrag können verfügbare Rollen definiert werden (z.B. "Model", "Visagist", "Kunde"). 
* **Multiple Roles:** Ein Flag `allow_multiple_roles_per_signer` steuert, ob eine Person (z.B. Model & Visagist in Personalunion) mehrere Rollen gleichzeitig annehmen darf.
* **Join-Link vs. Direct-Link:** Unterzeichner können entweder vom Fotografen explizit eingeladen werden (Direct-Link) oder über einen generischen Join-Link selbst beitreten, ihre Daten angeben, eine Rolle wählen und unterschreiben (ideal für Fotowalks/Gruppen-TFP).

### Deadline Inheritance and Telemetry Semantics

* Öffentliche Join-, Content- und Signaturpfade prüfen `status`, `expires_at` und `closes_at` anhand der Datenbankzeit. Create-/Sign-Transaktionen serialisieren den Contract-/Template-Zugriff per Row-Lock; Signaturen verwenden zusätzlich ein bedingtes Database-Time-Update.
* Neue Template-Instanzen übernehmen die Deadline-Werte ihres Templates. Legacy-Instanzen mit `NULL`-Deadlines lösen ihre effektive Deadline dynamisch vom Template auf. Das Template ist dabei eine harte Obergrenze; bestehende Zeilen werden nicht per Mass-Update backfilled.
* `POST /api/contracts/sign/{personalToken}/page-exit` ist Telemetrie, kein Signatur- oder Autorisierungs-Gate. Ein gültiger persönlicher Token mit verknüpftem Contract wird auch nach Close/Signatur akzeptiert; der Audit-Action-Wert ist `page_exit`. Ungültige Tokens liefern `404`.
* `GET /api/contracts/sign/{personalToken}` behält den unveränderten Content-Payload bei und verwendet einen eigenen per-token Rate-Limiter. Die Grenze liegt oberhalb des regulären Frontend-Pollings; ein `429` wird vor dem Controller zurückgegeben und schreibt keinen Heartbeat (Belege: Abschnitt „Contract-Content-Limiter" unten). `page-exit` behält seinen separaten Route-Limiter.

### Contract-Content-Limiter: gemessene Grenze (2026-10-18)

Der Limiter steht als `RateLimiter::for('contract-content', ...)` in `app/Providers/AppServiceProvider.php` und erlaubt **30 Abrufe pro Minute und Personal-Token** (`Limit::perMinute(30)->by(hash('sha256', personalToken))`). Das Frontend pollt alle 5 Sekunden, also **12/min** — nominell Faktor **2,5**. Die Content-Route ist mit `App\Http\Middleware\AtomicThrottleRequests:contract-content` gedrosselt; `page-exit` bleibt bei seinem separaten `throttle:60,1`.

Gemessen gegen **MariaDB 11.4.13-MariaDB-ubu2404** in einem Wegwerf-Container, 40 gleichzeitig hinter einem Start-Gate freigegebene HTTP-Clients, 40 PHP-Server-Worker, `CACHE_STORE=database` (derselbe MariaDB-Server wie die Heartbeat-Zeilen). Reproduktionsbefehl — er druckt Version, Histogramm, Heartbeat-Zahl, Latenzen, Lock-Waits und Log-Scan und räumt Container, Volume und Temp-Dateien auf; Exit **2** = Überschreitung, **1** = Harness-/Nutzungs-/Verfügbarkeitsfehler, **0** = Grenze gehalten:

```bash
cd backend && DOCKER_BIN="$HOME/.rd/bin/docker" ./tests/scripts/contract-content-mariadb-probe.sh
```

Das Skript prüft vor dem Burst als Harness-Vorbedingung, dass die Content-Route die Middleware trägt; eine zurückgenommene Route würde sonst als Limiter-Defekt gemeldet.

**Befund, behoben am 2026-10-18.** Vor dem Fix hielt die 30er-Grenze unter Gleichzeitigkeit nicht — vier Läufe schrieben 32–37 Heartbeats, also 2–7 über dem Budget:

| Lauf | HTTP 200 | HTTP 429 | Heartbeat-Zeilen | InnoDB Row-Lock-Waits | Deadlocks | p50 / p95 / max |
|---|---|---|---|---|---|---|
| 1 | 36 | 4 | 36 | 65 | 0 | 202 / 310 / 353 ms |
| 2 | 32 | 8 | 32 | 54 | 0 | 133 / 299 / 310 ms |
| 3 | 33 | 7 | 33 | 55 | 0 | 144 / 211 / 225 ms |
| 4 | 37 | 3 | 37 | 69 | 0 | 181 / 325 / 351 ms |

In allen vier Läufen: **keine 5xx**, `Max_used_connections` stieg von 2 auf 27–34, das MariaDB-Laufzeitlog hatte **0 Zeilen**, und weder App-Log noch MariaDB-Log enthielten `SQLSTATE`, Lock-Timeout-, Deadlock- oder InnoDB-Fehler. Der Timeout-Zähler `Innodb_row_lock_timeouts` existiert in MariaDB 11.4 nicht; die 54–69 Row-Lock-Waits sind die erwartete Serialisierung auf der Contract-Zeile, keine Fehler. Eine Gegenprobe mit demselben Befehl lieferte 35 × 200, 5 × 429 und **35** Heartbeats.

**Ursache 1 — Prüfen-dann-Zählen.** `ThrottleRequests::handleRequest()` fragt `tooManyAttempts()` und ruft erst danach `RateLimiter::hit()`; zwischen Lesen und Inkrementieren können mehrere Worker denselben Wert `< 30` sehen. Die Überschreitung ist durch die Zahl gleichzeitig laufender Requests begrenzt (hier ≤ 7), nicht durch die Requestzahl (40) und nicht unbegrenzt.

**Ursache 2 — eine unbedingte Reparatur-Schreibung im Zähler selbst.** Der Umbau auf „erst zählen, dann entscheiden" zeigte in einem Lauf derselben Reihe weiterhin Überschuss (32 × 200, 8 × 429). `RateLimiter::increment()` endet mit `put($key, $amount, $decay)`, sobald ein Worker, der das `add()`-Rennen verloren hat, den Zähler von 0 auf 1 hebt. Diese Schreibung ist unbedingt: landet sie nach den Inkrementen anderer Worker, setzt sie den Zähler zurück und öffnet das Budget erneut. Die Zählung dieses Laufs passt genau zu einem Rücksprung von 3 auf 1 — 3 Anfragen davor plus 29 nachfolgende Inkremente ≤ 30 = 32 zugelassene, 8 abgelehnte. Der Lauf ist nicht getract; die Zuordnung ist aus den Zählern hergeleitet, nicht direkt beobachtet.

**Zählweise nach dem Fix.** Die Middleware ruft `RateLimiter::hit()` nicht auf. Sie erzeugt den Fensterzähler mit `add($key, 1, $decay)` (atomar, genau ein Request gewinnt) und zählt mit `increment($key)` (atomarer Read-Modify-Write, liefert den neuen Wert; auf dem `database`-Store in einer Transaktion mit Row-Lock). Beide Primitive haben keine unbedingte Schreibung, der Zähler wächst innerhalb eines Fensters also nur. `Retry-After` kommt weiter aus `RateLimiter::availableIn()` über den `:timer`-Schlüssel, den der Fenster-Erzeuger schreibt; abgelehnt wird vor dem Controller.

**Nach dem Fix, vier Läufe vom 2026-10-18 mit genau dem Befehl oben:**

| Lauf | HTTP 200 | HTTP 429 | Heartbeat-Zeilen | InnoDB Row-Lock-Waits | Deadlocks | p50 / p95 / max | Exit |
|---|---|---|---|---|---|---|---|
| 1 | 30 | 10 | 30 | 54 | 0 | 211 / 380 / 386 ms | 0 |
| 2 | 30 | 10 | 30 | 59 | 0 | 312 / 432 / 456 ms | 0 |
| 3 | 30 | 10 | 30 | 60 | 0 | 192 / 400 / 424 ms | 0 |
| 4 | 30 | 10 | 30 | 50 | 0 | 151 / 311 / 319 ms | 0 |

In allen vier Läufen zusätzlich: `Max_used_connections` 2 → 23–35, `Innodb_deadlocks` 0, MariaDB-Laufzeitlog **0 Zeilen**, Log-Scan auf beiden Seiten `none`, kein Statuscode außerhalb 200/429, und `Precondition verified: the content route carries App\Http\Middleware\AtomicThrottleRequests:contract-content.`

Rohausgabe Lauf 1 (vollständig):

```text
PHP 8.5.11 erfuellt ^8.5 (>= 8.5.0), auto_prepend_file leer.
Starting disposable MariaDB container: contract-content-mariadb-a78924171a0c (mariadb:11.4)
MariaDB server version: 11.4.13-MariaDB-ubu2404
Probe database endpoint: 127.0.0.1:32793/contract_content_probe (container only)
Simultaneous requests: 40; PHP server workers: 40
Expected named limiter budget from AppServiceProvider: 30 per minute per personal token
Cache store: database (same MariaDB; matches the production default in config/cache.php)
Disposable database migrated and seeded: php artisan migrate:fresh --seed --force
Precondition verified: the content route carries App\Http\Middleware\AtomicThrottleRequests:contract-content.
Burst wall time: 0.738s for 40 simultaneously gated requests
HTTP status histogram:
200 30
429 10
Latency ms: min=47.4 p50=210.5 p95=379.9 max=386.2 mean=235.6 n=40
Heartbeat audit rows: 30 (limiter budget: 30/minute/token; requests: 40)
MariaDB app connections observed: peak=34; active SQL peak=29
MariaDB Max_used_connections: before=2 after=35 (server-side peak since start)
MariaDB Connections total delta: 53 new client connections during the probe window
InnoDB row-lock waits delta: 54
InnoDB deadlocks delta: 0
InnoDB row-lock timeout counter: unavailable from this MariaDB build
MariaDB connection monitor samples (app connections, active SQL):
0	0
0	0
0	0
34	29
0	0
sampled max=34 active-max=29
MariaDB runtime log lines during probe: 0
Application/server error scan (SQLSTATE, lock timeout, deadlock, InnoDB, 5xx exception):
none
MariaDB error scan (lock timeout, deadlock, InnoDB error; runtime lines only):
none
Limiter verdict: strict bound held (30 heartbeat rows, limit 30/minute, 10 requests throttled).
Probe completed: 30 HTTP 200, 10 HTTP 429, 30 heartbeat rows, at least two simultaneously active MariaDB app connections, no matching database/application errors.
Cleanup verified: container contract-content-mariadb-a78924171a0c is absent.
Cleanup verified: volume abfbe7bf6c06aeba1379ac4bc0129a777c71e5635253cbf0d18958bdde2d793c is absent.
Cleanup verified: temporary files are absent.
```

Ein früherer Beleg („Zählerwerte exakt 1…40, jeder genau einmal") stützte sich auf einen temporären Trace, der **nicht im Baum geblieben ist**. Ohne reproduzierbares Artefakt ist die Zahl entfernt. Die Monotonie des Zählers ist stattdessen deterministisch gepinnt: der Rennsituationstest unten belegt, dass die Middleware den Zähler in genau dem Zustand nicht unbedingt überschreibt, in dem `RateLimiter::hit()` es täte — ein Rücksprung, der die Monotonie bräche, ist damit ausgeschlossen.

**Deterministische Belege ohne Parallelität** (SQLite `:memory:`):

```bash
cd backend && php artisan test --filter AtomicThrottleRequestsTest
cd backend && php artisan test --filter ContractContentHeartbeatMySqlTest
```

- `AtomicThrottleRequestsTest`: ein Zähler auf der Grenze wird abgelehnt, **bevor** der Handler läuft; die Route trägt die Middleware und nicht mehr `throttle:contract-content`; eine unzählbare Anfrage wird abgelehnt statt zugelassen; ein 429 schreibt keinen Heartbeat. Der Zähler wird nie unbedingt überschrieben, in **zwei** Stufen: ein sequenzieller Lauf mit Gegenbeispiel (`RateLimiter::hit()` schreibt dort unbedingt), und ein Test, der die Rennsituation selbst nachstellt — ein Store meldet das `add()`-Rennen verloren (`add()` → `false`) und liefert beim ersten `increment()` `1`, also genau den Zustand, den `RateLimiter::hit()` mit einem unbedingten `put()` repariert; die Middleware zählt in diesem Zustand korrekt und führt **kein** `put` aus. Gegen die alte Fassung („erst `hit()`, dann entscheiden") wird genau dieser Test rot — belegt am 2026-10-18 durch temporäres Zurücksetzen von `countAttempt()` auf `hit()`.
- `ContractContentHeartbeatMySqlTest` ist **neu** in dieser Änderung (nicht „unverändert") und läuft ausschließlich sequenziell gegen echtes MySQL/MariaDB: 30×200, der 31. als 429, exakt 30 Heartbeat-Zeilen; auf dem SQLite-Default überspringt er sich selbst (`1 skipped`).

**Reichweite der Aussage „ein 429 schreibt keinen Heartbeat".** Belegt ist zweierlei, nicht mehr: (1) deterministisch — nach 30×200 und einem 429 bleiben genau 30 Heartbeat-Zeilen, der Controller läuft für den abgelehnten Request also nicht; (2) in der Parallel-Messung ist die Heartbeat-Zahl in allen vier Läufen gleich der Zahl der 200er. (2) allein belegt **nicht**, dass jeder einzelne 429 ohne Heartbeat blieb — es zeigt, dass keine zusätzlichen Heartbeats entstanden.

**Begrenzt umgestellt (D-38), sonst bewusst nicht angefasst.** Owner-Entscheidung D-38 zieht genau die priorisierten sicherheits- und abrechnungsrelevanten Pfade auf die Zählen-vor-Entscheiden-Form um: die drei Checkout-Quota-Buckets und die Turnstile-Zähler (Attempt, Failure-Velocity) in `CheckoutRiskService`, die Coupon-Validierungsroute (`coupon-validate`) und die Stripe-Webhook-Route (`10,1`). Alle vier zählen über das gemeinsame Primitiv `App\Support\AtomicRateCounter` — dieselbe Zähleinheit, die `AtomicThrottleRequests` benutzt. **Alle übrigen** Limiter tragen weiter die Prüfen-dann-Zählen-Reihenfolge des Frameworks und bleiben eine dokumentierte Grenze. Sie haben zwei verschiedene Formen, und nur die erste steht in `routes/`:

* **Routen-Middleware** — was ein `throttle:`-Argument wirklich auf einer Route ist. Reproduktion: `grep -rno 'throttle:[a-zA-Z0-9,:]*' backend/routes/*.php backend/bootstrap/app.php` (gemessen 2026-10-18 u. a. `throttle:api`, `throttle:ai` aus `ai-generate`, `throttle:model` aus `model-registration`, `throttle:coupon` aus `coupon-validate`, `throttle:contract` nur aus dem Kommentar zur Content-Route, `throttle:60,1`, `throttle:10,1`). Stand nach D-38 tragen `api`, `ai-generate` und `model-registration` weiter das Framework-Middleware, dazu die positionalen Formen (Auth/Invites, Downloads, `page-exit`); `coupon-validate` und `10,1` (Stripe-Webhook) laufen über `AtomicThrottleRequests`. **`throttle:api` liegt nicht auf „allen API-Routen"**, sondern nur an einzelnen Routen in `routes/api.php`; `bootstrap/app.php` setzt gar keine `api`-Throttle.
* **Service-Zähler** — Zähler, die **nicht** als Routen-Middleware existieren, sondern im Service laufen. Reproduktion: `grep -rn 'RateLimiter::hit' backend/app` — nach D-38 trifft das nur noch den FTP-Reset-Zähler in `FtpCredentialService`; die Checkout-Quota-Buckets und die Checkout-Fehlerzähler in `CheckoutRiskService` zählen über `AtomicRateCounter`. **`throttle:checkout` liegt auf keiner Route** — `tests/Feature/CheckoutRateLimitTest.php:51,53` assertiert ausdrücklich seine Abwesenheit (`assertNotContains('throttle:checkout', …)`).

Dort kann die Grenze unter Gleichzeitigkeit weiterhin um die Zahl gleichzeitig laufender Requests überschritten werden; eine globale Umstellung wäre eine eigene Entscheidung. Ebenfalls unangetastet: `withoutOverlapping` (die Middleware hält keinen Lock um die Controller-Arbeit).

**Offene Grenzen der neuen Middleware.** Exakt ist sie nur auf Cache-Stores mit atomarem `increment`: Produktions-Default `database` (gemessen), Redis/Memcached. Der `file`-Store (Dev-Fallback ohne `CACHE_STORE`) zählt per Read-Modify-Write und ist nicht prozessübergreifend atomar. Dazu vier benannte Grenzen; die ersten drei sind heute unerreicht, die vierte tritt nur in einem Cache-Eviction-Fenster auf:

* **`Retry-After: 0` bei halbem Fenster.** Zähler und `:timer` sind zwei Schlüssel. Stirbt der Prozess zwischen `add($key)` und `add($key.':timer')`, existiert der Zähler ohne Timer; überlebt ein Alt-Timer seinen Zähler, ist es umgekehrt. In beiden Fällen liefert `RateLimiter::availableIn()` über `max(0, get(':timer') − now)` den Wert 0, und `ThrottleRequests::buildException()` setzt daraus `Retry-After: 0`. Die **Grenze bleibt korrekt** — die Ablehnungsentscheidung liest den Timer nie, sie vergleicht nur den Zähler mit `maxAttempts` —, aber der Header nennt dann 0 Sekunden statt der Restfensterdauer.
* **Stille Abhängigkeit von `config('cache.limiter')`.** Gezählt wird im injizierten `Repository` (Default-Store); `availableIn()` und `attempts()` — und damit `Retry-After` und `X-RateLimit-Remaining` — laufen im Store des `RateLimiter`-Singletons, den `Illuminate\Cache\CacheServiceProvider::register()` aus `config('cache.limiter')` baut. Solange `cache.limiter` ungesetzt bleibt (heute der Fall), ist es derselbe Store. Setzt jemand später `'limiter' => …`, zählt die Middleware weiter korrekt im Default-Store, aber die beiden Header lesen einen anderen Zähler. Seit D-38 gilt dieselbe Abhängigkeit für die Service-Zähler in `CheckoutRiskService`: `App\Support\AtomicRateCounter` zählt im injizierten `Repository`, `RateLimiter::attempts()` und `RateLimiter::availableIn()` lesen im Store des `RateLimiter`-Singletons.
* **Multi-Limit und `->after()`.** Die Middleware zählt vorab für jedes Limit. Ein `Limit::…->after()`-Limit zählt im Framework nur passende Antworten; hier ist der Request bis zur Controller-Rückgabe bereits gezählt, ein zweiter gleichzeitiger Request kann deshalb vor dem Controller abgelehnt werden, obwohl das `after()`-Limit ihn nachträglich gar nicht gezählt hätte (der Refund greift erst danach). Zusätzlich ist `RateLimiter::decrement()` kein reines Dekrement: es ruft `increment($key, $decay, −1)`, legt einen fehlenden Zähler per `add($key, 0, …)` an und kann ihn auf `−1` setzen. Heute unerreicht, weil **kein** benannter Limiter `->after()` nutzt (`grep -rn '\->after(' backend/app backend/routes` trifft nur Validator-`after()` in Requests/Controllern, kein `Limit::…->after()`).
* **Ein unbestimmbarer Zähler wird fail-closed behandelt.** `App\Support\AtomicRateCounter::count()` liefert `AtomicRateCounter::UNCOUNTABLE`, wenn der Zähler **weder angelegt noch hochgezählt** werden konnte: `add($key, 1, $decay)` schlug fehl, weil die Zeile existierte, und `increment($key)` fand sie nicht mehr vor — sie wurde also **zwischen** den beiden Aufrufen gelöscht, etwa durch ein nebenläufiges `cache:clear` (das die `cache`-Tabelle leert) oder durch eine Eviction. Das ist **nicht** „der Store ist down": ein nicht erreichbarer Store wirft bereits im `add()`, im alten Code (`RateLimiter::hit()`) genauso wie im neuen — dieser Pfad war schon immer ein 500 und bleibt unverändert. Die drei Folgen eines unbestimmbaren Zählers: die **Checkout-Quota** antwortet **429** (mit dem unveränderten Body und `Retry-After`), die **Turnstile-Attempt-Zähler** verlangen die **Sicherheitsprüfung** (403 `turnstile_required`; liegt ein Token vor, läuft die Prüfung normal weiter, der Zähler wird in diesem Fenster nur nicht erhöht), und die **Failure-Velocity-Zähler** laufen über den bestehenden Best-Effort-Pfad (`Log::warning`, für den Kunden nicht sichtbar). Die Richtung ist bewusst **fail-closed**: der alte Code rechnete einen unbestimmbaren Zähler als **0**, verbreiterte also das Budget und übersprang die Prüfung. Sichtbarer Rückstand ist deshalb eine kleine Zahl zusätzlicher 429er und zusätzlicher Sicherheitsprüfungen **innerhalb** eines Cache-Eviction-Fensters. Nachweis, dass D-38 alle priorisierten Zähler umfasst: `grep -rn 'RateLimiter::hit' backend/app` — nach D-38 ist der einzige **Aufruf** der FTP-Reset-Zähler in `FtpCredentialService` (die übrigen Treffer sind Docblock-Erwähnungen); alle übrigen Zähler laufen über `App\Support\AtomicRateCounter`.

### Join-Identität und V039-Dateninvariante

* Jeder `contract_signers`-Datensatz erhält zwei kanonische Identitätsfelder: `normalized_email` (`lower(trim(email))`) und `join_scope_key`. Der unveränderte Originalwert in `email` bleibt erhalten.
* Der Scope ist ein unveränderlicher Snapshot: `contract:<contract UUID>` für direkte Verträge und `template:<template UUID>` für **jede** Instanz, die aus einer Vorlage erzeugt wurde. Das Löschen oder Umhängen einer Vorlage ändert die Identität bestehender Signer nicht.
* V039 führt den Preflight vor dem Backfill aus. Der Preflight leitet fehlende Identitäten deterministisch ab, validiert bestehende Snapshots, und bricht bei malformed/ambiguous Zeilen ab. Für Legacy-Verträge ist `template_id = NULL` nur mit einem nicht-leeren `join_token` eindeutig direkt; bei `template_id = NULL` und `join_token = NULL` wird der Eintrag als historisch mehrdeutig (Direktabzug vs. gelöschte Vorlage) berichtet, auch wenn bereits ein Snapshot gespeichert ist. Ein nicht-null `template_id` verlangt weiterhin einen gültigen Template-Datensatz; ein vorhandener gültiger Template-Snapshot wird bei bekannter/erhaltener Template-Historie nicht neu berechnet. Bei Duplikaten werden maximal 20 Gruppen mit maximal 20 Signer-IDs pro Gruppe in einem Log/Eintrag und einer klaren Exception ausgegeben. Es werden weder Signer noch Audit-Daten gelöscht oder zusammengeführt.
* Erst nach einem sauberen Preflight werden die Felder befüllt und der Unique-Index `contract_signers_scope_normalized_email_unique` auf `(join_scope_key, normalized_email)` angelegt. MySQL/MariaDB und PostgreSQL verwenden physische `NOT NULL`-Spalten; SQLite erzwingt die erforderlichen und unveränderlichen Werte wegen der fehlenden sicheren Online-Nullability-Änderung über BEFORE INSERT/UPDATE-Trigger. Die INSERT-Guards laden den referenzierten Contract sowie den optionalen Template-Datensatz, prüfen deren Typen und vergleichen sowohl `normalized_email = LOWER(TRIM(email))` (einschließlich maximal 255 Bytes gemäß der kanonischen PHP-Grenze) als auch den daraus abgeleiteten Scope. UPDATE-Guards leiten bewusst nicht neu ab: Sie verwerfen Contract-, E-Mail- und beide Identity-Feldänderungen, damit ein Template-Relink oder eine Löschung den gespeicherten Snapshot nicht verändert. MySQL/MariaDB-Trigger-Lokale sind binär; UUIDs werden vor den Vergleichen explizit als `CHAR` normalisiert, sodass gemischte Server-/Tabellenkollationen wie `utf8mb4_uca1400_ai_ci` und `utf8mb4_unicode_ci` keinen SQLSTATE-1267-Fehler auslösen. Nicht unterstützte Datenbank-Treiber werden fail-closed abgebrochen, bevor DDL ausgeführt wird.
* Der Maintenance-Window-Rollout ist fail-closed: Schreiber/Worker anhalten, den Preflight-Bericht prüfen und die betroffenen Legacy-Zeilen manuell entscheiden, `php artisan migrate --force` ausführen, danach `php artisan db:seed --force`; erst nach erfolgreichem LaufWriter wieder starten. Ein Retry validiert bereits befüllte Snapshots, repariert nur fehlende Felder und ist nach partieller DDL-/Index-/Trigger-Ausführung vorgesehen. Während des pausierten Laufs werden die Guard-Trigger für den Backfill kurzzeitig entfernt und anschließend wiederhergestellt; auch ein fehlgeschlagener Preflight stellt den fail-closed Guard-Zustand wieder her.
* Der Unique-Index ist die letzte Autorität auch bei einem Race zwischen zwei Verbindungen. Beide Join-Writer prüfen die kanonischen Felder, fangen den passenden Unique-Constraint-Fehler erst außerhalb der DB-Transaktion ab und geben ausschließlich einen generischen 409 ohne Token, Namen oder Rollen zurück. Template-Instance und Signer werden bei jedem Signer-Fehlschlag gemeinsam zurückgerollt.
* Eine bestehende Snapshot-Spalte wird niemals aus der aktuellen Vorlage neu berechnet. Ein fehlender oder ungültiger Template-/Contract-Scope wird abgewiesen; die Migration errät keine Historie. Das Backfill verändert keine bestehende E-Mail, keinen Zeitstempel, keine Tokens und keine Audit-Zeile.
* Operative Grenzen: Der Preflight läuft in 500-Zeilen-Chunks, legt die Report-/Datenänderungen in einer Transaktion an und ist kein Online-Backfill. Ein großer Legacy-Bestand muss im Wartungsfenster vollständig geprüft werden; bei einem Report-Abbruch wird keine Teilbereinigung als Erfolg interpretiert.

## 3. Database Schema
* **`contracts`**: `id` (UUID), `status` (draft, active, closed, cancelled), `billing_details` (JSON - falls der Vertrag kostenpflichtig ist, gibt es *einen* Rechnungsempfänger), `items` & `discounts` (JSON), `terms_html` (Text), `available_roles` (JSON Array), `allow_multiple_roles_per_signer` (Boolean), `join_token` (String, für öffentliche Invites), `closes_at` (Nullable Timestamp, Auto-Ende), `content_version` (Integer, default 0 — wird bei Bearbeitung im active-Status inkrementiert), `created_at`, `updated_at`.
* **`contract_signers`**: `id` (UUID), `contract_id` (FK UUID), `name`, `email`, `normalized_email` (canonical identity), `join_scope_key` (immutable direct/template scope snapshot), `roles` (JSON Array), `personal_token` (String), `status` (invited, joined, signed), `signed_at` (Nullable Timestamp), `created_at`, `updated_at`. The durable identity index is `(join_scope_key, normalized_email)`.
* **`contract_audit_logs`**: `id` (BIGINT auto-increment), `contract_id` (FK UUID), `contract_signer_id` (FK UUID), `action` (VARCHAR: opened, heartbeat, signed), `ip_address` (VARCHAR), `user_agent` (TEXT), `created_at`.

## 4. Token Auth Architecture (Reuse Invite Pattern)
Keine JWT-Auth für Contract-Signer — simpler DB-Token-Lookup (identisch zum `GalleryInvite`-Pattern mit `Str::random(64)`):

* **Join-Link:** `contracts.join_token` (`Str::random(64)`) — öffentlich, kein Login nötig
  * GET `/api/contracts/join/{token}` → Contract-Metadaten + available_roles (analog `InviteController::check()`)
  * POST `/api/contracts/join/{token}` → Signer-Eintrag mit gewählter Rolle → returns personal_token
* **Personal Token:** `contract_signers.personal_token` (`Str::random(64)`) — pro Signer, kein JWT
  * GET `/api/contracts/sign/{personal_token}` → Contract-Content + Signer-Info (Heartbeat-Log)
  * POST `/api/contracts/sign/{personal_token}` → Clickwrap-Signature + Audit-Log
* **Code-Reuse:**
  * `Str::random(64)` Token-Generierung (identisch GalleryInvite)
  * `$request->ip()` + `$request->userAgent()` für Audit-Log
  * `AbstractBrandAwareMailable` + `Pdf::loadView()` + `attachData()` für ContractClosedMail
  * `OfferTokenService` für `%OFFER_JWT%` Marker im PDF
  * `InvoiceService`-Pattern für Auto-Invoicing

### Authoritative pricing snapshot

* Contract item/fixed-discount `price` values and quantities are canonical integer cents/whole units. Percentage-discount `price` values are integer basis points (`10% = 1000`). Contract wire integers are no larger than JavaScript `Number.MAX_SAFE_INTEGER` (`9007199254740991`). This is the contract-scoped instance of the one system-wide money rule in [`../tech/02-backend-architecture.md` § 4](../tech/02-backend-architecture.md); `discounts[].price` is the discriminator field whose unit `type` selects — money branch cents, percentage branch basis points.
* Manual invoices keep the same integer-cent prices/basis-point rates, but quantities are explicitly fixed-point hundredths: new UI payloads send `quantity_scale: 100`, so `0.25` is serialized as `qty: 25`. The marker is accepted on every manual wire line; discount rows may carry the same `qty`/`quantity_scale` metadata, which pricing ignores. Direct legacy API payloads without `quantity_scale` remain accepted when their positive decimal quantity has at most two exact places; the backend normalizes them to the same hundredths before calculating. Offer-PDF roundtrips retain the scale marker so the editor restores the decimal quantity.
* The shared invoice table exposes an explicit manual mode (`step=0.25`, `min=0.25`) and a contract mode (`step=1`, `min=1`); this keeps the existing manual product behavior visible without weakening the contract snapshot's whole-unit rule.
* New contract writes accept only canonical JSON integers. Contract reads may normalize older integer-like strings and integral floats within the bound; manual legacy quantity reads additionally accept exact decimals with at most two places. Decimal/exponent ambiguity, non-finite/non-integral contract values, and every value above the safe maximum are rejected before totals or PDFs are derived.
* Discounts are applied in stored order to the running total. Item rows use `round(price cents × quantity units / quantity scale)` and percentage deductions use `round(running total × rate / 10000)`, both with half-up-away-from-zero behavior and checked integer intermediates. PHP uses bounded integer arithmetic and the TypeScript mirror uses `BigInt` intermediates; neither path relies on unsafe floating-point multiplication. Running additions/subtractions and discount magnitudes are checked against the same safe bound; no pricing total uses or returns a float, and the final total is clamped to zero.
* The line wire boundary remains `Number.MAX_SAFE_INTEGER`, but a contract whose authoritative final total would later be persisted is limited to the shared signed 32-bit money ceiling `PersistedMoney::MAX_CENTS = 2,147,483,647` cents. Store, update, open, close, and template-instance copy preflight this ceiling before any contract/accounting mutation; the Eloquent `Order` and `InvoiceSnapshot` write guards enforce the same bound as a last line of defense.
* The editor boundary remains `Number.MAX_SAFE_INTEGER`. Editor major-unit values accept at most two decimal places, validate that precision before conversion, and then use a bounded `Math.round(value * scale)` conversion. This deliberately repairs the unavoidable binary representation of max-safe cents (`fixedPointToMajorUnits(9007199254740991)`) so item, fixed/percentage discount, and manual-quantity roundtrips return the same wire integer. Wire normalization and all running-total arithmetic remain strict integer/`BigInt` operations; rounding is never used as a pricing arithmetic shortcut.
* Legacy mixed-placement template rows may be copied only when canonicalizing the separate `items`/`discounts` partitions preserves the original line-type order. A row that would move a discount before an item fails closed with a validation error before an instance or signer is inserted; the source template remains unchanged. Canonical and order-preserving legacy rows remain readable/copyable.
* `calculated_percentage` is derived display metadata in percentage points, never the basis-point wire rate: `1000` is emitted as integer `10`, while non-whole values such as `1001` use an exact decimal string (`10.01`). The PDF fragments format integer cents through `PersistedMoney::formatCents()` (integer grouping plus two exact decimals, never `/ 100` float division), keep the existing German decimal-comma typography, and append one `%`; the focused regression runs the real manual-invoice service, Blade view, and Dompdf output.
* `GET /api/contracts/sign/{personal_token}` returns the server-calculated `contract.total` in cents from the same method used when the contract closes. The signing view treats that field as authoritative.
* For a legacy response that predates `contract.total`, the frontend may calculate a compatibility total only from valid bounded integer contract lines. It does not trust a client-editable or stale `row_total`; current API responses remain server-authoritative.
* New management writes enforce the canonical partition: ordinary `type=item` lines belong in `items`, and ordered `discount_fixed`/`discount_percent` lines belong in `discounts`. Mixed placement is rejected before persistence. Legacy rows that already contain mixed placement are normalized on read; only this explicit compatibility path is accepted.
* Contract invoice/PDF snapshots serialize the same normalized, ordered line stream used for the response and close total, so derived `row_total` values cannot diverge from the authoritative amount.

## 5. Content Versioning & Stale-Detection (Lock on First Signature)

### Problem
Ein Admin soll einen aktiven Vertrag noch korrigieren können, solange kein Unterzeichner unterschrieben hat. Sobald ein Signer jedoch die Seite geöffnet hat, darf er nicht versehentlich eine alte Version signieren.

### Lösung: `content_version`
* **`contracts.content_version`** (Integer, default 0) wird bei jeder Bearbeitung eines `active`-Vertrags **inkrementiert**.
* Der Heartbeat-Endpoint (`GET /api/contracts/sign/{personal_token}`) gibt das aktuelle `content_version` zurück.
* Das Frontend speichert die Version beim ersten Laden und vergleicht sie bei jedem Heartbeat-Tick (30s).
* **Bei Abweichung:** Frontend zeigt Warning-Banner ("Vertrag wurde geändert — bitte neu laden") und deaktiviert den Sign-Button.
* Der Sign-Endpoint (`POST /api/contracts/sign/{personal_token}`) **erfordert `content_version` im Body** und lehnt ab, wenn es nicht mit der aktuellen Vertragsversion übereinstimmt (409 Conflict).

### Guard
```php
// ContractController::update()
// Erlaubt: status === 'draft'
// Erlaubt: status === 'active' UND kein Signer mit status === 'signed'
// Verboten: status === 'active' MIT signed signer → 403
// Verboten: status === 'closed' || status === 'cancelled' → 403
```

Bei Bearbeitung eines `active`-Vertrags wird zusätzlich ein `modified`-Audit-Log-Eintrag geschrieben.

## 6. Smart Document Integration & Auto-Invoicing
1. **Auto-Invoicing Trigger:** Das Auto-Invoicing (Erstellung von `Order` und `InvoiceSnapshot`) wird erst ausgelöst, wenn der Vertrag in den Status **`closed`** übergeht UND die `total_gross` > 0 ist. Rechnungsempfänger ist die in `billing_details` definierte Person.
2. **JWT-Fallback (Polyglot PDF):** Das finale PDF enthält weiterhin den `%OFFER_JWT:{token}%` Marker für den Re-Import in den Manual Invoice Builder.

### Idempotent closure and concurrency

* `ContractCloseService::close()` is the single lifecycle claim for manual and template-instance closure. It locks the contract row and performs the conditional `active` → `closed` update, accounting writes, and one database-queue insert per unique signer/billing recipient in the same database transaction. The queue uses the application database with `after_commit=false`; a partial enqueue failure rolls back the close, accounting, and every inserted recipient job, so the manual close or final-signature request can be retried. A retry or the loser of a concurrent close observes `closed` and performs no second order, invoice, or recipient enqueue.
* Automatic closure of a template instance runs inside the same outer transaction as the final signature update. A close conflict therefore rolls back both the signature and the accounting claim; the public endpoint returns a generic retryable `409` without exposing database details.
* A contract-created invoice records the source UUID in the existing `invoice_snapshots.customer_details.contract_id` JSON field. This is the durable source identity for accounting reconciliation; the contract row remains the lifecycle/idempotency authority. It is not a new order or invoice column, so no V039+ migration is required for this closure behavior.
* With the production transactional database queue, all intended recipient jobs are durably enqueued with the close, not via a bare after-commit callback. The guarantee is at-most-once durable enqueue and accounting state, not exactly-once SMTP delivery. Queue-worker retries and terminal mail recovery remain separate operational concerns.

## 7. Final PDF Compilation & Dispatch
Erst wenn der Vertrag geschlossen wird (`status = closed`), wird das finale, unveränderliche PDF generiert:
1. **Seite 1-X:** Vertragsdetails und Text (reused pattern: `manual_offer.blade.php`).
2. **Signatur-Block:** Eine Auflistung aller Personen, die unterschrieben haben, inkl. ihrer jeweiligen Rollen.
3. **Zertifikats-Anhang:** Das Digitale Signatur-Zertifikat listet tabellarisch für **jeden** Unterzeichner die IPs, Timestamps (Opened, Signed) auf.
4. **Dispatch:** Nach der Generierung erhalten alle E-Mail-Adressen aus der `contract_signers` Tabelle das finale PDF zugesendet.

## 8. API Routes

### Management (auth:api + management + super_admin)
```
POST   /api/management/contracts              → store (create draft)
GET    /api/management/contracts              → index (list all)
GET    /api/management/contracts/{id}         → show (detail + signers)
PUT    /api/management/contracts/{id}         → update (draft OR active ohne signed signers)
POST   /api/management/contracts/{id}/open    → open (draft → active, create join_token)
POST   /api/management/contracts/{id}/close   → close (active → closed, trigger PDF+Mail+AutoInvoice)
```

### Public (no auth, token-based)
```
GET    /api/contracts/join/{token}            → metadata + available_roles
POST   /api/contracts/join/{token}            → join (name, email, roles) → personal_token
GET    /api/contracts/sign/{personal_token}   → contract content + signer info (heartbeat)
POST   /api/contracts/sign/{personal_token}   → submit clickwrap signature (erfordert content_version, 409 bei Staleness)
```

## 9. File Map

### Backend
| File | Purpose |
|------|---------|
| `database/migrations/V021__digital_contracts.php` | Tables: contracts, contract_signers, contract_audit_logs |
| `database/migrations/V039__enforce_contract_signer_identity.php` | Preflight/backfill, immutable scope snapshot, canonical unique index, driver-specific writer guards |
| `database/migrations/V022__add_content_version_to_contracts.php` | Add content_version column |
| `database/migrations/V023__add_modified_action_to_contract_audit_logs.php` | Add 'modified' to audit action enum |
| `app/Models/Contract.php` | Model + relationships |
| `app/Models/ContractSigner.php` | Model + relationships |
| `app/Models/ContractAuditLog.php` | Model + relationships |
| `database/factories/ContractFactory.php` | Test factory |
| `database/factories/ContractSignerFactory.php` | Test factory |
| `database/factories/ContractAuditLogFactory.php` | Test factory |
| `app/Http/Controllers/ContractController.php` | Management CRUD + open/close |
| `app/Http/Controllers/ContractJoinController.php` | Public join/sign endpoints |
| `app/Http/Requests/StoreContractRequest.php` | Validation |
| `app/Http/Requests/UpdateContractRequest.php` | Validation |
| `app/Services/ContractAuditService.php` | IP + User-Agent logging |
| `app/Services/ContractPdfService.php` | Multi-signer PDF generation |
| `app/Services/ContractPricingService.php` | Canonical/legacy normalization and checked integer pricing engine |
| `app/Support/PersistedMoney.php` | Shared signed-32-bit persisted-money ceiling and exact cent formatting |
| `app/Services/ContractCloseService.php` | Close orchestration (invoice + PDF + mail) |
| `app/Exceptions/ContractCloseConflictException.php` | Generic retryable close conflict |
| `app/Mail/ContractClosedMail.php` | PDF email to all signers |
| `resources/views/pdf/contract_signatures.blade.php` | PDF template |
| `routes/api.php` | Route definitions |

### Frontend
| File | Purpose |
|------|---------|
| `src/ui/management/ManagementContractView.tsx` | Contract builder/manager |
| `src/logic/contractPricing.ts` | Safe-integer contract/manual pricing, fixed-point serialization, display metadata |
| `src/logic/useContractManagement.ts` | SWR hooks for management API |
| `src/ui/ContractJoinView.tsx` | Public join page (token-based) |
| `src/ui/ContractSignView.tsx` | Public sign page (personal token) |
| `src/logic/useContractJoin.ts` | SWR hooks for public API |
| `src/App.tsx` | Add /admin-contracts, /contracts/join/:token, /contracts/sign/:token |
| `src/ui/management/ManagementDashboard.tsx` | Add contract view switch |
| `src/ui/components/Sidebar.tsx` | Add "Verträge" menu entry |

### Tests
| File | Purpose |
|------|---------|
| `tests/Feature/Contract/ContractControllerTest.php` | Management API CRUD tests |
| `tests/Feature/Contract/ContractJoinTest.php` | Public join/sign token flow |
| `tests/Feature/Contract/ContractSignerIdentityTest.php` | Model identity, direct/template conflicts, raw-writer email/scope/relation guards, non-Unicode MariaDB collation, unique rollback |
| `tests/Feature/Contract/ContractSignerIdentityMigrationTest.php` | V039 schema, deterministic backfill, replay, bounded duplicate report |
| `tests/Feature/Contract/ContractSignerIdentityRaceTest.php` | Driver-gated real MariaDB/MySQL multi-connection race (skipped on SQLite) |
| `tests/Feature/Contract/ContractCloseTest.php` | Close → invoice → PDF → mail |
| `tests/Unit/ContractAuditServiceTest.php` | Audit log unit tests |
| `src/logic/__tests__/contractPricing.test.ts` | Safe-integer/fixed-point Vitest regressions |
| `src/logic/__tests__/useContractManagement.test.ts` | Vitest hook tests |
| `src/logic/__tests__/useContractJoin.test.ts` | Vitest hook tests |
| `tests/e2e/admin/documents/contracts.spec.ts` | E2E: create → open → sign → close |
