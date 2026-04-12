<?php

namespace App\Models;

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
        'content_html'
    ];

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
