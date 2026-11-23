<?php

namespace App\Auth;

use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Request guard for the `sanctum_transient` driver (Portal #9, D-73/D-74).
 *
 * Laravel's RequestGuard caches the resolved user and its `setRequest()` only
 * swaps the request object. In a long-lived process — and in the reused test
 * application — the next request would otherwise inherit the previous
 * caller's identity. A credential-resolved user is therefore dropped on a new
 * request; an explicitly pinned user (`actingAs()` / `setUser()`) is kept so
 * the established test and tooling contract still works.
 */
final class SanctumApiGuard extends RequestGuard
{
    private bool $userPinned = false;

    public function setUser(Authenticatable $user)
    {
        $this->userPinned = true;

        return parent::setUser($user);
    }

    public function setRequest(Request $request)
    {
        $this->request = $request;

        if (! $this->userPinned) {
            $this->user = null;
        }

        return $this;
    }
}
