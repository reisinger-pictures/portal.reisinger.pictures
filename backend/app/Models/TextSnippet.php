<?php

namespace App\Models;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Scout\Searchable;

class TextSnippet extends Model
{
    use HasUuids, Searchable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'title',
        'shortcut',
        'content_html',
        'brand'
    ];

    protected $casts = ['brand' => Brand::class];

    /**
     * Scope to the current brand (host-derived). See spec §3.3 / §3.4.
     */
    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from . '.brand', BrandRegistry::currentOrDefault()->value);
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'shortcut' => $this->shortcut,
            'content_html' => strip_tags($this->content_html),
        ];
    }
}
