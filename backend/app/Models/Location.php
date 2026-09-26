<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Location extends Model
{
    use HasUuids, Searchable;

    public $timestamps = false;

    protected $fillable = [
        'type',
        'name',
        'state',
        'country',
        'iso_country',
        'postal_code',
        'population',
    ];

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'state' => $this->state,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'iso_country' => $this->iso_country,
            'population' => (int) $this->population,
        ];
    }
}
