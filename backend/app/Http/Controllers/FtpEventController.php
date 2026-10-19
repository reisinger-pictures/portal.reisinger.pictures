<?php

namespace App\Http\Controllers;

use App\Jobs\ImportFtpBatch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The internal endpoint SFTPGo posts its filesystem events to (D-36).
 *
 * SFTPGo's event manager can call an HTTP action after an upload; that call is
 * what turns the import from "the photographer clicks" into "the upload starts
 * the import". The endpoint is deliberately not a user-facing API:
 *
 * - **No JWT, no session, no `management` middleware.** The caller is a service
 *   in the same compose network, not a logged-in person, and it has no way to
 *   hold a portal credential. The shared secret in `FTP_EVENT_SECRET` is the
 *   entire authentication, compared with `hash_equals()` so the comparison does
 *   not leak the secret's prefix through timing.
 * - **No throttle.** An ordinary shooting is one event per file, so a burst of
 *   dozens of events within a second is normal traffic, not abuse; a limiter
 *   sized for humans would drop exactly the events the feature exists for. The
 *   endpoint is cheap by construction: it resolves one row and enqueues one job,
 *   it never touches the inbox. The secret is what keeps strangers out, and the
 *   queue is what bounds the work a burst can cause.
 * - **Fail closed.** Without a configured secret every event is rejected with
 *   401 — an unconfigured portal must not accept events it cannot authenticate.
 *
 * ## What "no effect" looks like on the wire
 *
 * An unknown slug and an account without a target gallery both answer **200**
 * with `accepted: false`. The reason is that SFTPGo treats a non-2xx response as
 * a *failed action*: it logs an error for every event and can disable an action
 * that keeps failing. Both cases are ordinary, expected states in the portal —
 * the account was deleted, or the photographer has not chosen a gallery yet
 * (which is D-36's explicit boundary: **no target gallery, no import, files stay
 * where they are**) — and neither is a fault of the event. Reporting them as
 * errors would fill the provider's log with portal-side states and could cost
 * the rule its enabled status, which would silently stop every *valid* event
 * too. The body names the reason, so the case is still distinguishable.
 *
 * The endpoint does no heavy work: it resolves the account, checks that a target
 * exists, and enqueues `ImportFtpBatch`. The batch itself runs on the queue, and
 * it never takes the photographer's import lock here — that lock belongs to the
 * run, not to the trigger.
 */
class FtpEventController extends Controller
{
    /**
     * Event actions that mean "a file finished uploading".
     *
     * `upload` is what the provisioned rule subscribes to. `first-upload` is
     * accepted as well because it is the same fact reported for the first file
     * of a session; ignoring it would make the very first upload of a shooting
     * wait for the next event.
     */
    private const UPLOAD_ACTIONS = ['upload', 'first-upload'];

    public function handleUpload(Request $request)
    {
        $secret = config('ftp.events.secret');
        $header = config('ftp.events.secret_header');

        if (! is_string($secret) || $secret === '' || ! is_string($header) || $header === '') {
            Log::warning('SFTPGo-Upload-Event abgelehnt: kein Event-Secret konfiguriert.');

            return response()->json(['accepted' => false, 'reason' => 'not_configured'], 401);
        }

        $provided = $request->header($header);

        // `hash_equals()` rather than `===`: a timing side channel on a shared
        // secret is cheap to close and the comparison is the whole guard.
        if (! is_string($provided) || $provided === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['accepted' => false, 'reason' => 'unauthorized'], 401);
        }

        $username = $request->input('username');
        if (! is_string($username) || $username === '') {
            return response()->json(['accepted' => false, 'reason' => 'malformed'], 422);
        }

        $action = $request->input('action');
        if (! is_string($action) || ! in_array($action, self::UPLOAD_ACTIONS, true)) {
            return response()->json(['accepted' => false, 'reason' => 'ignored_event'], 200);
        }

        // The event names the SFTPGo account, which is `users.ftp_slug` (7.2).
        $user = User::query()->where('ftp_slug', $username)->first();
        if ($user === null) {
            return response()->json(['accepted' => false, 'reason' => 'unknown_account'], 200);
        }

        if (! $user->current_ftp_gallery_id) {
            return response()->json(['accepted' => false, 'reason' => 'no_target_gallery'], 200);
        }

        ImportFtpBatch::dispatch($user->id, 1, 0, true);

        return response()->json(['accepted' => true, 'dispatched' => true], 200);
    }
}
