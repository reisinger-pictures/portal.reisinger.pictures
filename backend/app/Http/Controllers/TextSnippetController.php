<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TextSnippet;

class TextSnippetController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');
        if ($q && strlen($q) >= 2) {
            return response()->json(TextSnippet::search($q)->orderBy('created_at', 'desc')->take(20)->get());
        }

        return response()->json(TextSnippet::orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'shortcut' => 'nullable|string|min:1|max:100|unique:text_snippets,shortcut',
            'content_html' => 'nullable|string',
        ]);

        $sanitizer = app(\Symfony\Component\HtmlSanitizer\HtmlSanitizer::class);
        $validated['content_html'] = $sanitizer->sanitize($validated['content_html'] ?? '');
        $snippet = TextSnippet::create($validated);
        return response()->json(['success' => true, 'snippet' => $snippet]);
    }

    public function update(Request $request, $id)
    {
        $snippet = TextSnippet::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'shortcut' => 'nullable|string|min:1|max:100|unique:text_snippets,shortcut,' . $id,
            'content_html' => 'nullable|string',
        ]);

        if (isset($validated['content_html'])) {
            $sanitizer = app(\Symfony\Component\HtmlSanitizer\HtmlSanitizer::class);
            $validated['content_html'] = $sanitizer->sanitize($validated['content_html']);
        }
        $snippet->update($validated);
        return response()->json(['success' => true, 'snippet' => $snippet]);
    }

    public function destroy($id)
    {
        TextSnippet::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}
