<?php

namespace App\Models;

use App\Support\EmailNormalizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrgInvite extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'email',
        'org_id',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function setEmailAttribute(string $email): void
    {
        $this->attributes['email'] = EmailNormalizer::normalize($email);
    }

    public function org()
    {
        return $this->belongsTo(Org::class);
    }
}
