<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per FTP/SFTP camera password reset attempt (P1-M33, V042).
 *
 * Write-only in practice: the trail exists so a human can answer "who kept
 * rotating this account, and did it work", which is a question asked after the
 * fact. Nothing in the request path reads it back — the rate limit is a counter
 * in the cache, not a `COUNT(*)` on this table, so the read path stays free of
 * an extra query and stays available even if the table is being written to.
 *
 * It holds no password. The row is written after the rotation attempt, from a
 * method that receives only the user, the request IP and a boolean, so there is
 * no column and no argument through which a camera password could reach it
 * (feature doc 7.3: the portal stores no FTP password at all).
 */
class FtpPasswordReset extends Model
{
    use HasFactory;

    /**
     * `reset_at` is the only timestamp in the table (V042), and it is written
     * explicitly by the caller as the audit fact — it is not a record of when
     * the row happened to be inserted. Turning both Eloquent timestamps off
     * keeps the schema honest: there is no `updated_at` column to silently
     * write NULL into, and no second, weaker notion of "when" for a reader to
     * pick the wrong one.
     */
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'reset_at', 'ip', 'success',
    ];

    protected $casts = [
        'reset_at' => 'datetime',
        'success' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
