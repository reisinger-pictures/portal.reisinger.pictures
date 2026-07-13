<?php

namespace App\Models;

use App\Values\BrandConfig;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $table = 'brands';

    protected $fillable = [
        'id', 'name', 'theme', 'portal_name', 'impressum_url',
        'logo_path', 'hostnames', 'features', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'hostnames' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function toConfig(): BrandConfig
    {
        return new BrandConfig(
            id: $this->id,
            name: $this->name,
            theme: $this->theme,
            portalName: $this->portal_name,
            impressumUrl: $this->impressum_url,
            logoPath: $this->logo_path,
            features: $this->features ?? [],
            hostnames: $this->hostnames ?? [],
            isActive: $this->is_active,
        );
    }
}
