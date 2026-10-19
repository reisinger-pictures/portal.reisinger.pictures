<?php

namespace App\Console\Commands;

use App\Exceptions\SftpGoException;
use App\Services\SftpGoClient;
use Illuminate\Console\Command;

/**
 * Provisions the SFTPGo upload-event rule that starts the automatic import (D-36).
 *
 * The rule lives in the SFTPGo **data provider**, not in the compose file: it is
 * application state that has to survive a redeploy and has to be updatable
 * without a stack recreate. A compose environment variable could not express it
 * (SFTPGo 2.6+ reads its configuration from the provider), and hand-editing the
 * web UI is not reproducible. So this is a versioned, idempotent command over
 * the Admin API — the same path `ftp:provision-folders` uses for the inbox
 * directories.
 *
 * ## What it writes
 *
 * 1. An **event action** of type `1` (HTTP): `POST` to the internal endpoint,
 *    with the shared secret in the `X-FTP-Event-Secret` header, a 10 s timeout,
 *    and a JSON body that carries the `username` and `action` fields the
 *    endpoint reads (`UPLOAD_EVENT_BODY`).
 * 2. An **event rule** with trigger `1` (filesystem event), condition
 *    `fs_events: ["upload"]`, status enabled, bound to that action.
 *
 * ## Idempotency
 *
 * Both objects are looked up by name first. Found → `PUT` with the full object
 * (the API has no partial update, see `SftpGoClient::updateEventAction()`);
 * missing → `POST`. Running the command twice therefore produces one action and
 * one rule, and a changed secret or URL is written over the existing object —
 * which is the whole reason the read comes first: a blind `POST` would answer
 * 409 on the second run and leave the *old* secret in place while reporting a
 * failure.
 *
 * ## Failure shape
 *
 * A missing URL, a URL without the `http(s)://` scheme SFTPGo requires, a
 * missing secret, or an unconfigured/unreachable SFTPGo ends with `FAILURE` and
 * a named reason. The action is written before the rule because
 * SFTPGo validates that a rule's action exists; if the rule write then fails,
 * the command says so explicitly instead of pretending to have finished. The
 * half-state it can leave — an action that no rule uses — changes nothing about
 * the running system: no event is delivered until the rule exists, and the next
 * run repairs it.
 *
 * Usage (from `backend/`):
 *
 *     php artisan ftp:provision-events
 *     php artisan ftp:provision-events --dry-run
 */
class ProvisionFtpEvents extends Command
{
    protected $signature = 'ftp:provision-events
                            {--dry-run : Print the objects that would be written without touching SFTPGo}';

    protected $description = 'Provision the SFTPGo event action and rule that trigger the automatic FTP import (D-36)';

    /** SFTPGo event action type `1` = HTTP. */
    private const ACTION_TYPE_HTTP = 1;

    /** SFTPGo event trigger `1` = filesystem event. */
    private const TRIGGER_FILESYSTEM = 1;

    /** SFTPGo rule status `1` = enabled. */
    private const RULE_STATUS_ENABLED = 1;

    /**
     * The HTTP action's request timeout, in seconds.
     *
     * SFTPGo validates it as `1 <= timeout <= 180`
     * (`internal/dataprovider/eventrule.go`, `EventActionHTTPConfig::isTimeoutNotValid`
     * called from `validate()` on both `POST /eventactions` and `PUT
     * /eventactions/{name}`). The field is `json:"timeout,omitempty"`, so leaving
     * it out writes `0` and the Admin API answers **400** — the action could not
     * be created at all. 10 s is far above the endpoint's work (one row lookup and
     * one enqueue) and far below the ceiling.
     *
     * Public so the test can assert it stays inside the 1..180 contract.
     */
    public const HTTP_TIMEOUT_SECONDS = 10;

    /**
     * The request body SFTPGo sends for one upload event.
     *
     * SFTPGo sends **no** body and no default query parameters of its own
     * (`internal/common/eventmanager.go`, `getHTTPRuleActionBody`): an action
     * without `body` and without `parts` reaches the endpoint as an empty POST.
     * The endpoint reads `username` (= `users.ftp_slug`) and `action`
     * (`FtpEventController::handleUpload`), so the action has to carry the body
     * that names them; `fs_path` and `file_size` ride along so the delivered body
     * is the whole event rather than a two-field subset.
     *
     * The placeholder names are SFTPGo's own, read from
     * `internal/common/eventmanager.go` `getStringReplacements()` (v2.7.6), not
     * guessed: `{{.Name}}` is the account (`EventParams.Name` is the connection's
     * username for a filesystem event, `internal/common/actions.go`),
     * `{{.Event}}` the operation (`upload` / `first-upload`), `{{.VirtualPath}}`
     * the file path and `{{.FileSize}}` the byte count.
     *
     * `Content-Type: application/json` is part of the body contract, not
     * decoration: SFTPGo only JSON-escapes the substituted values when the
     * action's headers name a JSON content type
     * (`EventActionHTTPConfig::HasJSONBody()`), and the JSON-escape is what keeps
     * a path containing a quote from producing a broken body. Without the header
     * the same placeholders are substituted unescaped.
     */
    public const UPLOAD_EVENT_BODY = '{"action":"{{.Event}}","username":"{{.Name}}","fs_path":"{{.VirtualPath}}","file_size":"{{.FileSize}}"}';

    /** The content type the body above is written in and the endpoint parses. */
    public const UPLOAD_EVENT_CONTENT_TYPE = 'application/json';

    public function handle(SftpGoClient $client): int
    {
        $url = config('ftp.events.url');
        $secret = config('ftp.events.secret');
        $header = config('ftp.events.secret_header');
        $actionName = config('ftp.events.action_name');
        $ruleName = config('ftp.events.rule_name');

        if (! is_string($url) || trim($url) === '') {
            $this->error('FTP_EVENT_URL ist nicht gesetzt.');
            $this->error('Der Endpunkt ist APP_URL + /api/internal/ftp/upload-event, sofern APP_URL gesetzt ist.');

            return self::FAILURE;
        }

        if (! self::isAbsoluteHttpUrl($url)) {
            // Caught before the request, because SFTPGo would answer 400 after
            // it: `EventActionHTTPConfig::validate()` rejects an endpoint that
            // does not start with `http://` or `https://`. Naming the fault
            // here is the difference between "the variable is wrong" and "the
            // provisioning failed", which look the same in a stack recreate.
            $this->error('FTP_EVENT_URL ist keine absolute http(s)-URL.');
            $this->error('SFTPGo lehnt einen Endpunkt ohne http:// oder https:// mit HTTP 400 ab.');

            return self::FAILURE;
        }

        if (! is_string($secret) || $secret === '') {
            $this->error('FTP_EVENT_SECRET ist nicht gesetzt.');
            $this->error('Ohne Secret lehnt der Endpunkt jedes Event mit 401 ab (fail closed).');

            return self::FAILURE;
        }

        if (! is_string($header) || $header === '' || ! is_string($actionName) || ! is_string($ruleName)) {
            $this->error('Die Event-Konfiguration (Header, Aktions- und Regelname) ist unvollständig.');

            return self::FAILURE;
        }

        $action = $this->actionPayload($actionName, $url, $header, $secret);
        $rule = $this->rulePayload($ruleName, $actionName);

        if ($this->option('dry-run')) {
            $this->line('Aktion '.$actionName.' -> POST '.$url);
            $this->line('Header '.$header.': *** (Secret nicht ausgegeben)');
            $this->line('Regel '.$ruleName.': fs_events=[upload], status=enabled');
            $this->line('Dry run: SFTPGo wurde nicht kontaktiert.');

            return self::SUCCESS;
        }

        if (! $client->isConfigured()) {
            $this->error('SFTPGo ist nicht konfiguriert: weder SFTPGO_API_KEY noch Admin-Zugangsdaten sind gesetzt.');
            $this->error('Nichts wurde geschrieben.');

            return self::FAILURE;
        }

        $actionWritten = false;

        try {
            if ($client->findEventAction($actionName) === null) {
                $client->createEventAction($action);
                $this->info("Event-Aktion '{$actionName}' angelegt.");
            } else {
                $client->updateEventAction($actionName, $action);
                $this->info("Event-Aktion '{$actionName}' aktualisiert.");
            }

            $actionWritten = true;

            if ($client->findEventRule($ruleName) === null) {
                $client->createEventRule($rule);
                $this->info("Event-Regel '{$ruleName}' angelegt.");
            } else {
                $client->updateEventRule($ruleName, $rule);
                $this->info("Event-Regel '{$ruleName}' aktualisiert.");
            }
        } catch (SftpGoException $exception) {
            $this->error('SFTPGo-Provisionierung fehlgeschlagen: '.$exception->getMessage());

            if ($actionWritten) {
                $this->warn("Die Aktion '{$actionName}' existiert, die Regel '{$ruleName}' fehlt.");
                $this->warn('Es wird kein Event zugestellt, bis die Regel geschrieben ist — den Befehl erneut ausführen.');
            } else {
                $this->warn('Es wurde nichts geschrieben.');
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Whether the URL is one SFTPGo's HTTP action accepts.
     *
     * This is deliberately the same rule SFTPGo applies, not a stricter URL
     * parser: `EventActionHTTPConfig.validate()`
     * (`internal/dataprovider/eventrule.go`, v2.7.6) calls
     * `util.IsStringPrefixInSlice($endpoint, ['http://', 'https://'])`, which is
     * a **case-sensitive prefix** test (`internal/util/util.go`,
     * `strings.HasPrefix`). Anything it would reject — a bare host, `ftp://`,
     * `HTTP://` — must be refused here, or the command posts an action the
     * Admin API answers 400 to and reports a generic provisioning failure.
     *
     * The same predicate is inlined in `config/ftp.php` for the `APP_URL`
     * fallback; the two have to agree, so both name the SFTPGo source.
     */
    private static function isAbsoluteHttpUrl(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    /**
     * The HTTP action, in SFTPGo's `BaseEventAction` shape.
     *
     * `headers` is a list of `{key, value}` pairs, not an object: the API models
     * it as `KeyValue[]`, and an object would be silently dropped by the decoder.
     *
     * Three fields are load-bearing beyond the endpoint and method:
     *
     * - `timeout` (1..180, see `HTTP_TIMEOUT_SECONDS`): SFTPGo rejects an action
     *   whose timeout is 0 with a 400, and an omitted `omitempty` field *is* 0.
     * - `body`: SFTPGo sends no default body, so without this the endpoint would
     *   receive an empty POST and answer 422 `malformed` — a failed action in
     *   SFTPGo's log and no import.
     * - the `Content-Type` header: it is what makes SFTPGo JSON-escape the
     *   substituted placeholder values (see `UPLOAD_EVENT_BODY`).
     */
    public function actionPayload(string $name, string $url, string $header, string $secret): array
    {
        return [
            'name' => $name,
            'description' => 'Portal: FTP-Upload-Event an den Import-Endpunkt (D-36)',
            'type' => self::ACTION_TYPE_HTTP,
            'options' => [
                'http_config' => [
                    'endpoint' => $url,
                    'method' => 'POST',
                    'timeout' => self::HTTP_TIMEOUT_SECONDS,
                    'headers' => [
                        ['key' => $header, 'value' => $secret],
                        ['key' => 'Content-Type', 'value' => self::UPLOAD_EVENT_CONTENT_TYPE],
                    ],
                    'body' => self::UPLOAD_EVENT_BODY,
                ],
            ],
        ];
    }

    /**
     * The rule, in SFTPGo's `EventRuleMinimal` shape.
     *
     * The condition is `fs_events: ["upload"]` and not "every filesystem event":
     * a delete or a rename is not an upload, and letting those through would
     * enqueue a job per housekeeping action.
     */
    private function rulePayload(string $name, string $actionName): array
    {
        return [
            'name' => $name,
            'description' => 'Portal: nach jedem Kamera-Upload den FTP-Import anstoßen (D-36)',
            'status' => self::RULE_STATUS_ENABLED,
            'trigger' => self::TRIGGER_FILESYSTEM,
            'conditions' => [
                'fs_events' => ['upload'],
            ],
            'actions' => [
                ['name' => $actionName, 'order' => 1],
            ],
        ];
    }
}
