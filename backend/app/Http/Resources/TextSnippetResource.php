<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TextSnippetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'shortcut' => $this->shortcut,
            'content_html' => $this->content_html,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
