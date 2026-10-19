<?php

namespace App\Support;

/**
 * The contract of a *direct* gallery assignment (D-43).
 *
 * `user_galleries` is the client grant table: it is what
 * `AuthorizationService::getAllowedGalleryIds()` reads as the direct grants
 * of an account, and what D-39 re-validates at read time. A direct assignment
 * may therefore only carry delivery galleries — a selection gallery is a
 * private rating surface that reaches a client through an invite, not through
 * a direct grant. A write that accepted a non-delivery id would produce a
 * grant the reader voids, i.e. a silent no-op for the admin.
 *
 * Both writers of the client grant table share this contract, so the *write*
 * rule and the rejection message exist exactly once:
 *
 * - `UserController::update()` — the user editor (`gallery_ids`).
 * - `GalleryController::syncAccess()` — the gallery access dialog.
 *
 * The delivery-only property itself has a second expression: the reader,
 * `AuthorizationService::safeDirectGalleryIds()`, re-validates a persistent
 * client grant when it is read (D-39). The write rule and the reader are not
 * identical — see {@see self::allowsType()} for the one difference.
 */
final class DirectGalleryGrant
{
    /**
     * The gallery type a direct assignment may carry.
     */
    public const ALLOWED_TYPE = 'delivery';

    /**
     * The single answer both writers reject a non-delivery assignment with.
     */
    public const REJECTION_MESSAGE = 'Es dürfen nur Liefergalerien direkt zugewiesen werden.';

    /**
     * Whether a gallery of the given type may be carried by a direct
     * assignment.
     *
     * The only type a direct assignment may carry is `delivery`, so the
     * reachable rejection is a `selection` gallery. The column is
     * `enum('selection','delivery') NOT NULL DEFAULT 'delivery'`
     * (`database/migrations/V001__initial_portal_schema.php`) and no later
     * migration changes it, so a gallery row always carries a type. An id
     * that does not exist never reaches this check either: the request
     * validation refuses it with 422 (`UpdateUserRequest`:
     * `gallery_ids.* => exists:galleries,id`).
     *
     * The reader (`AuthorizationService::safeDirectGalleryIds()`) tolerates a
     * gallery row without a type, this write rule does not. The difference is
     * unreachable with the current schema and stays fail-closed here.
     */
    public static function allowsType(?string $type): bool
    {
        return $type === self::ALLOWED_TYPE;
    }
}
