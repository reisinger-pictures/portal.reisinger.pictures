<?php

namespace App\Http\Controllers;

use App\Exceptions\FtpAuditWriteException;
use App\Exceptions\FtpCredentialException;
use App\Exceptions\SftpGoException;
use App\Services\FtpCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Camera credential endpoint (P1-M33).
 *
 * A separate controller from `FtpController` on purpose. That one is the import
 * pipeline, and feature doc 7.8 fixes its shape: `getInboxPath()`,
 * `setTarget()`, `status()` and `process()` stay as they are, with no SFTPGo
 * contact in the read or import path. The reset is the opposite of that — it is
 * nothing but an SFTPGo write — so putting it in `FtpController` would have made
 * the class do two unrelated jobs and given the import path a reason to change.
 */
class FtpCredentialController extends Controller
{
    public function __construct(
        private readonly FtpCredentialService $credentials,
    ) {}

    /**
     * Rotates the calling user's camera password and shows the new one once.
     *
     * There is no request input beyond the authenticated user: the account is
     * always `auth('api')->user()`. A `user_id` in the payload would be an IDOR
     * with a body — resetting another photographer's camera credential is
     * exactly the abuse the per-account quota and the audit trail exist to make
     * visible, so the surface for it is not offered at all.
     *
     * Both failure families are translated into an honest status instead of a
     * 500: the quota into 429 with a `Retry-After`, the service into whatever
     * the exception's reason describes. A photographer who gets a 502 here still
     * knows their password did not change — which matters, because the old
     * password is not restored if a partial success were reported as a failure
     * (feature doc 7.3: the reset is the only recovery path).
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $user = auth('api')->user();

        try {
            $password = $this->credentials->resetAndShow($user, $request->ip());
        } catch (FtpAuditWriteException $exception) {
            // The password was rotated but could not be recorded, so it is not
            // handed out: an untraceable credential is not one the photographer
            // can safely rely on, and the camera is now on a password nobody
            // wrote down. The exception's message already says both.
            return response()->json(['error' => $exception->getMessage()], 500);
        } catch (FtpCredentialException $exception) {
            return $this->fromCredentialException($exception);
        } catch (SftpGoException $exception) {
            return response()->json(
                ['error' => $exception->getMessage()],
                $this->statusForServiceFailure($exception),
            );
        }

        return response()->json([
            'success' => true,
            'password' => $password,
            'password_notice' => 'Dieses Passwort wird genau einmal angezeigt. Es ist nicht gespeichert und nicht wiederherstellbar — bei Verlust kann nur ein neues erzeugt werden.',
        ]);
    }

    /**
     * 429 for the quota, 422 for every other portal-side precondition. The reason
     * is not exposed in the payload: the message is the whole contract, and a
     * client cannot act on a machine-readable variant of it.
     */
    private function fromCredentialException(FtpCredentialException $exception): JsonResponse
    {
        if ($exception->reason !== FtpCredentialException::REASON_RATE_LIMITED) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['error' => $exception->getMessage()], 429, [
            'Retry-After' => (string) ($exception->retryAfterSeconds ?? 1),
        ]);
    }

    /**
     * How an Admin-API failure is reported to the photographer.
     *
     * "Not configured" and "unreachable" are 503: the portal is fine, the
     * dependency is not, and a retry is the correct answer. An unknown account is
     * 404 because that is a real, actionable state — the cache column from P1-M30
     * says the account exists while the service disagrees, which is precisely the
     * mismatch P1-M34 has to resolve. Everything else is 502: the service
     * answered, and the answer was not usable.
     */
    private function statusForServiceFailure(SftpGoException $exception): int
    {
        return match ($exception->reason) {
            SftpGoException::REASON_NOT_CONFIGURED,
            SftpGoException::REASON_UNREACHABLE => 503,
            SftpGoException::REASON_NOT_FOUND => 404,
            SftpGoException::REASON_ALREADY_EXISTS => 409,
            SftpGoException::REASON_INVALID_REQUEST => 422,
            default => 502,
        };
    }
}
