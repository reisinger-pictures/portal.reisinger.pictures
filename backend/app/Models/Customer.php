<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Scout\Searchable;

class Customer extends Model
{
    use HasUuids, Searchable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'name',
        'company',
        'email',
        'street',
        'zip',
        'city',
        'country',
        'uid'
    ];

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
        ];
    }
}
