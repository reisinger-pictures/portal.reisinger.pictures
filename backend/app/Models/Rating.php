<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Rating extends Model
{
    use HasUuids;
    public $timestamps = false;
    protected $fillable = ['photo_id', 'user_id', 'guest_id', 'guest_name', 'rating', 'comment'];
    protected $casts = ['rating' => 'integer'];
}
