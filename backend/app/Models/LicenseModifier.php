<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class LicenseModifier extends Model {
    use HasFactory, HasUuids;
    public const UPDATED_AT = null;
    protected $fillable = ['name', 'description', 'percent_surcharge', 'is_included_in_flatrate', 'sort_order'];
    protected $casts = ['percent_surcharge' => 'float', 'is_included_in_flatrate' => 'boolean'];
}
