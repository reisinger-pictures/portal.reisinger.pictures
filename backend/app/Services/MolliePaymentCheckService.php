<?php

namespace App\Services;

/**
 * Mollie self-test ("Mollie-Verbindungstest") for the super-admin diagnostic
 * endpoint `POST /api/management/settings/mollie-check`.
 *
 * Always returns all five findings in a fixed order and never lets a provider
 * failure escape: every check catches its own exception and turns it into a
 * `fail` finding, so an admin always sees the full verdict — including the
 * "Mollie itself could not be asked" case. Only authentication (the route
 * middleware) can reject the request.
 *
 * The answer an operator actually needs is "can this installation take a
 * payment right now, and in which mode?" (owner decision D-63). That is the
 * checkout gate {@see MolliePaymentService::canCreatePayment()}, reported
 * directly as the last finding.
 *
 * No secret ever reaches a message: the key value is never printed, and every
 * assembled sentence runs through {@see self::redactSecrets()} so a future
 * provider text cannot leak one either.
 */
class MolliePaymentCheckService
{
    public function __construct(private readonly MolliePaymentService $mollie) {}

    /**
     * Run all five diagnostics and return a stable, ordered check list.
     *
     * @return array{checks: array<int, array{key: string, status: string, message: string}>}
     */
    public function run(): array
    {
        return [
            'checks' => [
                $this->checkKeyMode(),
                $this->checkWebhookUrl(),
                $this->checkMethods(),
                $this->checkAccount(),
                $this->checkReadiness(),
            ],
        ];
    }

    /**
     * Is a key configured, and which mode does its prefix carry? No provider
     * call, and the key value is never named.
     *
     * @return array{key: string, status: string, message: string}
     */
    private function checkKeyMode(): array
    {
        $mode = $this->mollie->mode();

        if ($mode === null) {
            return $this->finding(
                'key_mode',
                'fail',
                'MOLLIE_KEY ist nicht gesetzt oder trägt kein erkennbares test_/live_-Präfix; ohne gültigen Schlüssel ist die Zahlungsanlage deaktiviert.',
            );
        }

        return $this->finding(
            'key_mode',
            'ok',
            "MOLLIE_KEY ist gesetzt und läuft im {$mode}-Modus.",
        );
    }

    /**
     * Is `MOLLIE_WEBHOOK_URL` set, HTTPS and of a usable shape? Mandatory in
     * every environment: a missing or plain-HTTP value disables payment
     * creation with `503`. The URL itself is not a secret.
     *
     * @return array{key: string, status: string, message: string}
     */
    private function checkWebhookUrl(): array
    {
        $webhookUrl = config('services.mollie.webhook_url');
        $configured = is_string($webhookUrl) ? trim($webhookUrl) : '';
        $issue = $this->mollie->webhookUrlIssue();

        if ($issue === 'webhook_url_missing') {
            return $this->finding(
                'webhook_url',
                'fail',
                'MOLLIE_WEBHOOK_URL ist nicht gesetzt. Mollie verlangt bei jeder Zahlungsanlage eine öffentlich erreichbare HTTPS-URL, deshalb ist die Zahlungsanlage deaktiviert (503).',
            );
        }

        if ($issue !== null) {
            return $this->finding(
                'webhook_url',
                'fail',
                "MOLLIE_WEBHOOK_URL ist keine gültige HTTPS-URL mit Host ({$configured}); die Zahlungsanlage ist deshalb deaktiviert (503).",
            );
        }

        return $this->finding(
            'webhook_url',
            'ok',
            "MOLLIE_WEBHOOK_URL ist gesetzt und HTTPS: {$configured}",
        );
    }

    /**
     * The configured allowlist: non-empty, and free of a forbidden BNPL or a
     * malformed id. A broken list arms the same fail-closed `503`.
     *
     * @return array{key: string, status: string, message: string}
     */
    private function checkMethods(): array
    {
        $issue = $this->mollie->methodListIssue();

        if ($issue !== null && $issue['reason'] === 'not_a_list') {
            return $this->finding(
                'methods',
                'fail',
                'MOLLIE_PAYMENT_METHODS ist keine Liste; die Zahlungsanlage ist deaktiviert (503).',
            );
        }

        if ($issue !== null && $issue['reason'] === 'forbidden_method') {
            return $this->finding(
                'methods',
                'fail',
                "Die Methodenliste enthält »{$issue['method']}«, eine BNPL-/Ratenzahlungsfamilie, die eigene Belege ausstellt. Die ganze Liste ist deaktiviert (503).",
            );
        }

        if ($issue !== null) {
            return $this->finding(
                'methods',
                'fail',
                "Die Methodenliste enthält die ungültige ID »{$issue['method']}«. Die ganze Liste ist deaktiviert (503).",
            );
        }

        $methods = $this->mollie->paymentMethods();

        if ($methods === []) {
            return $this->finding(
                'methods',
                'fail',
                'MOLLIE_PAYMENT_METHODS ist leer; ohne zugelassene Methode ist die Zahlungsanlage deaktiviert (503).',
            );
        }

        $list = implode(', ', $methods);

        return $this->finding(
            'methods',
            'ok',
            "Zugelassene Methoden: {$list} (Standard: {$methods[0]}).",
        );
    }

    /**
     * Exactly one authenticated provider call: prove that the key works and
     * report the profile facts. A provider failure — or a missing key — is a
     * `fail` finding, never an exception and never a 500.
     *
     * @return array{key: string, status: string, message: string}
     */
    private function checkAccount(): array
    {
        try {
            $account = $this->mollie->account();
        } catch (MolliePaymentException $exception) {
            return $this->finding(
                'account',
                'fail',
                'Das Mollie-Konto konnte nicht abgerufen werden: '.$this->describeReason($exception->reason),
            );
        } catch (\Throwable) {
            // Defense in depth: the self-test must answer with a verdict for
            // any configuration, so even an unexpected failure stays a finding.
            return $this->finding(
                'account',
                'fail',
                'Das Mollie-Konto konnte nicht abgerufen werden: unerwarteter Fehler bei der Provider-Abfrage.',
            );
        }

        $profile = $account['profile_id'] ?? 'unbekannt';
        $accountMode = $account['mode'] ?? 'unbekannt';
        $status = strtolower((string) ($account['status'] ?? ''));

        if ($status === 'verified') {
            return $this->finding(
                'account',
                'ok',
                "Profil {$profile} ist erreichbar und verifiziert; der Schlüssel funktioniert im ".($this->mollie->mode() ?? 'unbekannten').'-Modus.',
            );
        }

        if ($status === 'blocked') {
            return $this->finding(
                'account',
                'fail',
                "Profil {$profile} ist im Status »blocked«; Mollie nimmt für dieses Profil keine Zahlungen an.",
            );
        }

        return $this->finding(
            'account',
            'warn',
            "Profil {$profile} (Modus {$accountMode}) ist erreichbar, aber der Status ist »".($status !== '' ? $status : 'unbekannt').'« (nicht verifiziert).',
        );
    }

    /**
     * The same boolean the checkout gate uses, so the operator sees directly
     * whether a payment can be created right now.
     *
     * @return array{key: string, status: string, message: string}
     */
    private function checkReadiness(): array
    {
        $issue = $this->mollie->configurationIssue();

        if ($issue !== null || ! $this->mollie->canCreatePayment()) {
            $reason = match ($issue) {
                'credentials_missing' => 'kein gültiger MOLLIE_KEY',
                'methods_missing' => 'keine zugelassene Zahlungsmethode',
                'webhook_url_missing' => 'MOLLIE_WEBHOOK_URL fehlt',
                'webhook_url_invalid' => 'MOLLIE_WEBHOOK_URL ist keine gültige HTTPS-URL',
                default => 'unbekannte Fehlkonfiguration',
            };

            return $this->finding(
                'readiness',
                'fail',
                "Zahlungen können derzeit nicht angelegt werden (canCreatePayment() = false): {$reason}.",
            );
        }

        return $this->finding(
            'readiness',
            'ok',
            'Zahlungen können angelegt werden (canCreatePayment() = true).',
        );
    }

    /**
     * Map the adapter's closed reason vocabulary to an operator sentence. The
     * adapter never carries a provider payload, so no raw text can leak here.
     */
    private function describeReason(string $reason): string
    {
        return match ($reason) {
            'not_configured' => 'es ist kein gültiger MOLLIE_KEY konfiguriert.',
            'request_failed' => 'Mollie hat die Anfrage abgelehnt (Schlüssel ungültig, abgelaufen oder ohne Berechtigung).',
            'rate_limited' => 'Mollie hat die Anfrage wegen zu vieler Aufrufe abgelehnt.',
            'provider_unavailable' => 'Mollie ist derzeit nicht erreichbar (Serverfehler).',
            'provider_error' => 'Mollie ist nicht erreichbar oder antwortet nicht wie erwartet.',
            default => 'Mollie meldet den Fehler »'.$reason.'«.',
        };
    }

    /**
     * Defense in depth: no Mollie key material may appear in the JSON, even if
     * a future message carries text built elsewhere. Mollie keys are
     * `test_…`/`live_…`; over-redacting is the safe direction.
     */
    private function redactSecrets(string $message): string
    {
        $redacted = preg_replace('/\b(?:test|live)_[A-Za-z0-9]{6,}/', '***entfernt***', $message);

        return $redacted ?? $message;
    }

    /**
     * @return array{key: string, status: string, message: string}
     */
    private function finding(string $key, string $status, string $message): array
    {
        return ['key' => $key, 'status' => $status, 'message' => $this->redactSecrets($message)];
    }
}
