<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TextSnippet;
use App\Support\BrandRegistry;
use Illuminate\Validation\Rule;

class TextSnippetController extends Controller
{
    public function index(Request $request)
    {
        $brand = BrandRegistry::currentOrDefault()->value;

        $q = $request->query('q');
        if ($q && strlen($q) >= 2) {
            return response()->json(
                TextSnippet::search($q)
                    ->query(fn($query) => $query->where('brand', $brand))
                    ->orderBy('created_at', 'desc')
                    ->take(20)
                    ->get()
            );
        }

        return response()->json(TextSnippet::where('brand', $brand)->orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $brand = BrandRegistry::currentOrDefault()->value;

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'shortcut' => ['nullable', 'string', 'min:1', 'max:100', Rule::unique('text_snippets', 'shortcut')->where(fn($query) => $query->where('brand', $brand))],
            'content_html' => 'nullable|string',
        ]);

        $sanitizer = app(\Symfony\Component\HtmlSanitizer\HtmlSanitizer::class);
        $validated['content_html'] = $sanitizer->sanitize($validated['content_html'] ?? '');
        $validated['brand'] = $brand;
        $snippet = TextSnippet::create($validated);
        return response()->json(['success' => true, 'snippet' => $snippet]);
    }

    public function update(Request $request, $id)
    {
        $brand = BrandRegistry::currentOrDefault()->value;
        $snippet = TextSnippet::forCurrentBrand()->findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'shortcut' => ['nullable', 'string', 'min:1', 'max:100', Rule::unique('text_snippets', 'shortcut')->where(fn($query) => $query->where('brand', $brand))->ignore($id)],
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
        TextSnippet::forCurrentBrand()->findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}
