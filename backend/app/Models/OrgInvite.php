<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class OrgInvite extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'email',
        'org_id',
        'token',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime'
    ];

    public function org()
    {
        return $this->belongsTo(Org::class);
    }
}
