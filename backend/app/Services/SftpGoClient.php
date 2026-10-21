<?php

namespace App\Services;

use App\Exceptions\SftpGoException;
use App\Support\FtpSlug;
use App\Values\SftpGoAdminCredentials;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * HTTP client for the SFTPGo Admin API v2 (P1-M22).
 *
 * Scope: this class speaks HTTP and nothing else. There is deliberately no
 * FTP/SFTP protocol code in the portal — the camera transport is an external
 * service (feature doc 7.6, 7.8).
 *
 * Contract, verified against `openapi/openapi.yaml` and the handler sources of
 * `drakkan/sftpgo` v2.7.6 (not guessed):
 *
 * - `GET  /api/v2/token`            — `security: [BasicAuth]`, returns
 *   `{access_token, expires_at}`. A POST here answers 405.
 * - `POST /api/v2/users`            — add a user, 201 with the user object.
 * - `GET  /api/v2/users/{username}` — read a user, 404 when unknown.
 * - `PUT  /api/v2/users/{username}` — update, 200 with `{message}`.
 * - `DELETE /api/v2/users/{username}` — 200 with `{message}`.
 * - Auth: header `X-SFTPGO-API-KEY` (preferred) or `Authorization: Bearer <jwt>`.
 *
 * Two decisions that the API forces and that are easy to get wrong:
 *
 * 1. `resetPassword()` is a read-modify-write. `updateUser`
 *    (internal/httpd/api_user.go) decodes the request body into a *fresh* user
 *    object and restores only password, username, id, recovery codes, TOTP and
 *    the last-password-change stamp. A `PUT` with just `{"password": ...}`
 *    would therefore reset `home_dir` and `permissions` and silently break a
 *    working account — the camera would authenticate and then see nothing.
 * 2. `disconnect=1` on that update, so an already connected session cannot keep
 *    using the old password after a reset.
 *
 * Secrets: a camera password is never logged, never put into an exception and
 * never kept here. The `#[\SensitiveParameter]` attributes additionally redact it
 * from stack traces, and there is no `retry()` — a retried `POST /users` would
 * turn a lost response into a duplicate account (409), which is far harder to
 * reason about than one clean error.
 */
class SftpGoClient
{
    /**
     * Connect timeout in seconds. Feature doc 7.5 requires a fixed connect and
     * total timeout and forbids relying on the Guzzle default.
     */
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const TOTAL_TIMEOUT_SECONDS = 15;

    public const API_KEY_HEADER = 'X-SFTPGO-API-KEY';

    /**
     * `status: 1` = user enabled. Named, because the value is meaningless
     * without the context (0 disables the account).
     *
     * Public because the reconciliation (P1-M30) reads the same field to tell
     * an enabled account from a disabled one: two copies of "1 = enabled"
     * would be able to disagree about what a disabled account means.
     */
    public const USER_STATUS_ENABLED = 1;

    /**
     * The instance uploads into its home directory only; no virtual folders are
     * configured. The ownership/uid model on the host is P1-M24 and is
     * deliberately not guessed here, so `uid`/`gid` stay 0 ("no change").
     */
    private const CAMERA_PERMISSIONS = ['/' => ['*']];

    private const CAMERA_DESCRIPTION = 'Portal FTP-Kamera-Zugang';

    /**
     * SFTPGo mints JWTs with a short lifetime. Renewing this much earlier keeps
     * a call from failing halfway through because the token expired between the
     * check and the request.
     */
    private const TOKEN_RENEWAL_SKEW_SECONDS = 30;

    /**
     * A token without a parsable `expires_at` is used once and never cached.
     */
    private ?string $accessToken = null;

    private ?Carbon $accessTokenExpiresAt = null;

    /**
     * Whether at least one usable credential exists. A read path can use this to
     * stay quiet instead of hitting an unconfigured service (feature doc 7.5).
     */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== null || SftpGoAdminCredentials::fromConfig() !== null;
    }

    /**
     * Creates the account for a camera. Returns the user object as SFTPGo
     * rendered it (the password is never part of that response).
     */
    public function provisionUser(string $username, #[SensitiveParameter] string $password, string $homeDir): array
    {
        $this->assertUsableUsername($username);
        $this->assertUsableHomeDir($username, $homeDir);

        $response = $this->call('provision', $username, fn (PendingRequest $request) => $request->post(
            $this->usersPath(),
            [
                'username' => $username,
                'password' => $password,
                'status' => self::USER_STATUS_ENABLED,
                'home_dir' => $homeDir,
                'description' => self::CAMERA_DESCRIPTION,
                'permissions' => self::CAMERA_PERMISSIONS,
            ],
        ));

        return $this->assertJsonObject($response, $username);
    }

    /**
     * Rotates the password of an existing account and keeps everything else
     * about it (see the read-modify-write note in the class docblock).
     */
    public function resetPassword(string $username, #[SensitiveParameter] string $password): array
    {
        $this->assertUsableUsername($username);

        $current = $this->findUser($username);
        $current['password'] = $password;

        $response = $this->call('reset_password', $username, fn (PendingRequest $request) => $request->put(
            $this->userPath($username).'?disconnect=1',
            $current,
        ));

        return $this->assertJsonObject($response, $username);
    }

    /**
     * Removes the account. A 404 is reported as `not_found` instead of being
     * swallowed: a mismatch between `users.ftp_slug` and the name in SFTPGo is
     * an open question (P1-M34) and must not be hidden by an idempotent delete.
     */
    public function deleteUser(string $username): void
    {
        $this->assertUsableUsername($username);

        $this->call('delete', $username, fn (PendingRequest $request) => $request->delete(
            $this->userPath($username),
        ));
    }

    /**
     * The virtual folders of a user, in SFTPGo's own shape.
     *
     * There is no per-user folder endpoint in 2.7.6 — `GET /api/v2/folders`
     * only accepts offset/limit/order — so the user object is the only source.
     * An account without virtual folders yields an empty list, not null.
     */
    public function listFolders(string $username): array
    {
        $user = $this->findUser($username);
        $folders = $user['virtual_folders'] ?? [];

        if (! is_array($folders) || ($folders !== [] && ! array_is_list($folders))) {
            throw SftpGoException::malformedResponse($username);
        }

        return $folders;
    }

    /**
     * The raw user object. Public because the explicit reconcile path (P1-M30)
     * needs it: the cached `ftp_account_status` is a cache, and this is how it
     * would be corrected without putting a live query into the read path.
     *
     * The `status` field is part of the answer, not a detail: it says whether
     * the account may be used at all, so a disabled account is an access that
     * was withdrawn.
     */
    public function findUser(string $username): array
    {
        $this->assertUsableUsername($username);

        $response = $this->call('find', $username, fn (PendingRequest $request) => $request->get(
            $this->userPath($username),
        ));

        return $this->assertJsonObject($response, $username);
    }

    /**
     * The event action with this name, or null when it does not exist yet.
     *
     * Contract verified against `openapi/openapi.yaml` v2.7.6: `GET
     * /api/v2/eventactions/{name}` answers 200 with a `BaseEventAction` and 404
     * when the name is unknown — the same shape as `/users/{username}`, so the
     * idempotent provisioning path reads first and then decides between POST and
     * PUT. Without the read, a second run would add a second action and a second
     * rule (409 at best, a duplicate rule at worst).
     */
    public function findEventAction(string $name): ?array
    {
        $response = $this->call(
            'event_action_find',
            $name,
            fn (PendingRequest $request) => $request->get($this->eventActionPath($name)),
            allowNotFound: true,
        );

        if ($response->status() === 404) {
            return null;
        }

        return $this->assertJsonObject($response, $name);
    }

    /**
     * Adds an event action. Returns the object as SFTPGo rendered it.
     *
     * The stored `http_config.headers` may come back masked: SFTPGo hides
     * confidential data unless `confidential_data=1` is requested. Nothing here
     * depends on reading the secret back — the provisioning command always writes
     * the full object, which is also what makes a rotated secret take effect.
     */
    public function createEventAction(array $action): array
    {
        $name = $this->requireName($action);

        $response = $this->call('event_action_create', $name, fn (PendingRequest $request) => $request->post(
            $this->eventActionsPath(),
            $action,
        ));

        return $this->assertJsonObject($response, $name);
    }

    /**
     * Replaces an event action.
     *
     * `PUT /api/v2/eventactions/{name}` takes the full `BaseEventAction`, so the
     * command builds the complete object rather than a patch. That is deliberate:
     * the endpoint has no partial update, and a "send only what changed" call
     * would silently clear the endpoint URL or the headers.
     */
    public function updateEventAction(string $name, array $action): void
    {
        $this->call('event_action_update', $name, fn (PendingRequest $request) => $request->put(
            $this->eventActionPath($name),
            $action,
        ));
    }

    /** The event rule with this name, or null when it does not exist yet. */
    public function findEventRule(string $name): ?array
    {
        $response = $this->call(
            'event_rule_find',
            $name,
            fn (PendingRequest $request) => $request->get($this->eventRulePath($name)),
            allowNotFound: true,
        );

        if ($response->status() === 404) {
            return null;
        }

        return $this->assertJsonObject($response, $name);
    }

    /**
     * Adds an event rule. The body is an `EventRuleMinimal`: the action is
     * referenced by name, not by id, so the rule can be written in the same run
     * that created the action.
     */
    public function createEventRule(array $rule): array
    {
        $name = $this->requireName($rule);

        $response = $this->call('event_rule_create', $name, fn (PendingRequest $request) => $request->post(
            $this->eventRulesPath(),
            $rule,
        ));

        return $this->assertJsonObject($response, $name);
    }

    /** Replaces an event rule (full `EventRuleMinimal`, same reasoning as the action). */
    public function updateEventRule(string $name, array $rule): void
    {
        $this->call('event_rule_update', $name, fn (PendingRequest $request) => $request->put(
            $this->eventRulePath($name),
            $rule,
        ));
    }

    /**
     * The object name is the path segment and the log label.
     *
     * It is part of the payload the caller builds, so an absent one is a
     * programming error here rather than a request the service would reject with
     * a 400 that reads like a transport problem.
     */
    private function requireName(array $payload): string
    {
        $name = $payload['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw SftpGoException::invalidRequest('event', 'Der Name der Event-Aktion bzw. -Regel fehlt.');
        }

        return $name;
    }

    /**
     * Runs one Admin API call: one log record, one exception type, no retries.
     *
     * `$allowNotFound` is for the idempotent provisioning path: "the object does
     * not exist yet" is the normal first-run state there, not a failure, and
     * turning it into an exception would make a create look like an error. It
     * does not swallow the status — the caller still inspects it — and it does
     * not downgrade a 404 for anything else.
     *
     * @param  Closure(PendingRequest): Response  $call
     */
    private function call(string $operation, string $username, Closure $call, bool $allowNotFound = false): Response
    {
        $startedAt = microtime(true);

        try {
            $response = $call($this->pendingRequest());
        } catch (ConnectionException $exception) {
            // The transport error carries the URL, not the request body, so the
            // camera password is not part of the chain.
            $this->logCall($operation, $username, null, $startedAt);

            throw SftpGoException::unreachable($username, $exception);
        }

        $expectedAbsent = $allowNotFound && $response->status() === 404;

        $this->logCall($operation, $username, $response, $startedAt, $expectedAbsent);

        if ($response->failed() && ! $expectedAbsent) {
            throw SftpGoException::fromStatus($username, $response->status());
        }

        return $response;
    }

    /**
     * A 2xx must still be a JSON object. `findUser` and `listFolders` index into
     * it, so `[]` or a scalar body is a failure, not an empty result.
     */
    private function assertJsonObject(Response $response, string $username): array
    {
        $decoded = $response->json();

        if (! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            throw SftpGoException::malformedResponse($username);
        }

        return $decoded;
    }

    /**
     * Fixed timeouts, no default. `Http::timeout()` is the *total* budget in
     * Laravel, the connect budget is `connectTimeout()` — writing `timeout(5)`
     * for both would silently drop the connect limit, so both are set here.
     */
    private function pendingRequest(): PendingRequest
    {
        $request = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TOTAL_TIMEOUT_SECONDS)
            ->acceptJson()
            ->asJson();

        $apiKey = $this->apiKey();

        if ($apiKey !== null) {
            return $request->withHeaders([self::API_KEY_HEADER => $apiKey]);
        }

        return $request->withToken($this->accessToken());
    }

    private function apiKey(): ?string
    {
        $apiKey = config('services.sftpgo.api_key');

        return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.sftpgo.base_url'), '/');
    }

    private function usersPath(): string
    {
        return $this->baseUrl().'/api/v2/users';
    }

    private function userPath(string $username): string
    {
        // The account name is a path segment. The format guard rejects slashes
        // before this is reached; the encoding is defence in depth, not
        // decoration.
        return $this->usersPath().'/'.rawurlencode($username);
    }

    private function eventActionsPath(): string
    {
        return $this->baseUrl().'/api/v2/eventactions';
    }

    private function eventActionPath(string $name): string
    {
        return $this->eventActionsPath().'/'.rawurlencode($name);
    }

    private function eventRulesPath(): string
    {
        return $this->baseUrl().'/api/v2/eventrules';
    }

    private function eventRulePath(string $name): string
    {
        return $this->eventRulesPath().'/'.rawurlencode($name);
    }

    /**
     * JWT fallback. Cached per instance: the container resolves this class per
     * request, so a queue worker re-authenticates on its own once the token
     * approaches expiry.
     */
    private function accessToken(): string
    {
        if ($this->accessToken !== null
            && $this->accessTokenExpiresAt !== null
            && $this->accessTokenExpiresAt->isAfter(now()->addSeconds(self::TOKEN_RENEWAL_SKEW_SECONDS))) {
            return $this->accessToken;
        }

        $credentials = SftpGoAdminCredentials::fromConfig();

        if ($credentials === null) {
            throw SftpGoException::notConfigured();
        }

        $startedAt = microtime(true);
        $response = $this->requestAccessToken($credentials);
        $this->logCall('auth_token', 'token', $response, $startedAt);

        if ($response->failed()) {
            throw SftpGoException::fromStatus('token', $response->status());
        }

        $decoded = $response->json();

        if (! is_array($decoded) || ! is_string($decoded['access_token'] ?? null) || $decoded['access_token'] === '') {
            throw SftpGoException::malformedResponse('token');
        }

        $expiresAt = is_string($decoded['expires_at'] ?? null)
            ? Carbon::parse($decoded['expires_at'])
            : null;

        $this->accessToken = $decoded['access_token'];
        $this->accessTokenExpiresAt = $expiresAt instanceof Carbon ? $expiresAt : null;

        return $this->accessToken;
    }

    private function requestAccessToken(SftpGoAdminCredentials $credentials): Response
    {
        try {
            return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TOTAL_TIMEOUT_SECONDS)
                ->acceptJson()
                ->withBasicAuth($credentials->username, $credentials->password)
                ->get($this->baseUrl().'/api/v2/token');
        } catch (ConnectionException $exception) {
            $this->logCall('auth_token', 'token', null, microtime(true));

            throw SftpGoException::unreachable('token', $exception);
        }
    }

    /**
     * Exactly one record per call: operation, account, status, duration and the
     * body *length*. Never the body, never a header, never a password — the
     * request body of `provisionUser` is the camera password.
     */
    private function logCall(string $operation, string $account, ?Response $response, float $startedAt, bool $expectedAbsent = false): void
    {
        $status = $response?->status();
        $context = [
            'operation' => $operation,
            'ftp_account' => $account,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'body_length' => $response === null ? null : strlen((string) $response->body()),
        ];

        if ($expectedAbsent) {
            // The documented "not there yet" of an idempotent provisioning run.
            // Logged at info level on purpose: an error line for the normal
            // first run is how a log stops being read.
            Log::info('SFTPGo Admin-API Aufruf: Objekt existiert nicht', $context);

            return;
        }

        if ($status === null || $status >= 400) {
            Log::error('SFTPGo Admin-API Aufruf fehlgeschlagen', $context);

            return;
        }

        Log::info('SFTPGo Admin-API Aufruf', $context);
    }

    /**
     * The account name is what the camera sends and what `users.ftp_slug` holds,
     * so an unusable one has to fail here instead of inside the service.
     *
     * The rule itself lives in `App\Support\FtpSlug` (P1-M21) — that class is the
     * single source of truth for the format, and this client must not spell it
     * out a second time. A legacy value that violates it is rejected with the
     * same message the profile form shows, rather than creating a broken account.
     */
    private function assertUsableUsername(string $username): void
    {
        if (! FtpSlug::isValid($username)) {
            throw SftpGoException::invalidRequest($username, FtpSlug::message());
        }
    }

    /**
     * SFTPGo requires an absolute home directory (openapi: "Must be an absolute
     * path"). Whether that directory exists is a host-side step (P1-M24), not
     * something this client guesses.
     */
    private function assertUsableHomeDir(string $username, string $homeDir): void
    {
        if ($homeDir === '' || ! str_starts_with($homeDir, '/')) {
            throw SftpGoException::invalidRequest($username, 'Das Home-Verzeichnis muss ein absoluter Pfad sein.');
        }
    }
}
