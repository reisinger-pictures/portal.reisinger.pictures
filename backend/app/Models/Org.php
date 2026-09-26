<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Enums\AutoJoinPolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Org extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'domain',
        'invoice_frequency',
        'brand',
        'default_role_id',
        'default_flatrate_level',
        'shared_flatrate_cents',
        'can_purchase_upgrades',
        'auto_join_policy',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'can_purchase_upgrades' => 'boolean',
        'auto_join_policy' => AutoJoinPolicy::class,
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'org_id');
    }

    public function galleryGroups()
    {
        return $this->belongsToMany(GalleryGroup::class);
    }

    public function defaultRole()
    {
        return $this->belongsTo(Role::class, 'default_role_id');
    }
}
