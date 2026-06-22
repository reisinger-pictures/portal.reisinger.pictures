<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class LicenseUseCase extends Model {
    use HasFactory, HasUuids;
    public const UPDATED_AT = null;
    protected $fillable = ['name', 'description', 'base_price', 'flatrate_tier', 'sort_order', 'is_commercial'];
    protected $casts = ['base_price' => 'integer', 'is_commercial' => 'boolean'];
}
