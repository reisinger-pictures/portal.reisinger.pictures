<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Customer extends Model
{
    use HasFactory, HasUuids, Searchable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'name',
        'company',
        'email',
        'street',
        'zip',
        'city',
        'country',
        'uid',
        'brand',
        'birthdate',
        'user_id',
        'is_model',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'birthdate' => 'date:Y-m-d',
        'is_model' => 'boolean',
    ];

    /**
     * Scope to the current brand (host-derived). See spec §3.3 / §3.4.
     */
    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function modelProfile()
    {
        return $this->hasOne(ModelProfile::class);
    }

    public function modelPhotos()
    {
        return $this->hasMany(ModelPhoto::class);
    }

    public function modelAccessTokens()
    {
        return $this->hasMany(ModelAccessToken::class);
    }

    public function actMembers()
    {
        return $this->hasMany(ActMember::class);
    }

    public function acts()
    {
        return $this->belongsToMany(Act::class, 'act_members')->withPivot(['role', 'position']);
    }

    public function scopeModels(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.is_model', true);
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'company' => $this->company,
            'email' => $this->email,
            'street' => $this->street,
            'zip' => $this->zip,
            'city' => $this->city,
            'country' => $this->country,
            'uid' => $this->uid,
            'birthdate' => $this->birthdate?->format('Y-m-d'),
            'created_at' => $this->created_at?->timestamp,
        ];
    }
}
