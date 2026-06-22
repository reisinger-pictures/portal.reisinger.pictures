<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Setting extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = ['key', 'value'];
}
