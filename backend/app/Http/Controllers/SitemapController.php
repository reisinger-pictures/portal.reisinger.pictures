<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SitemapController extends Controller
{
    public function galleries(Request $request)
    {
        $baseUrl = rtrim(config('app.frontend_url'), '/');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        Gallery::where('is_public', true)->chunk(100, function ($galleries) use (&$xml, $baseUrl) {
            foreach ($galleries as $gallery) {
                $lastMod = $gallery->created_at ? Carbon::parse($gallery->created_at)->toAtomString() : now()->toAtomString();
                
                $xml .= '  <url>' . "\n";
                $xml .= '    <loc>' . $baseUrl . '/' . htmlspecialchars($gallery->full_path) . '</loc>' . "\n";
                $xml .= '    <lastmod>' . $lastMod . '</lastmod>' . "\n";
                $xml .= '    <changefreq>weekly</changefreq>' . "\n";
                $xml .= '    <priority>0.8</priority>' . "\n";
                $xml .= '  </url>' . "\n";
            }
        });
        
        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'text/xml');
    }

    public function images(Request $request)
    {
        $baseUrl = rtrim(config('app.frontend_url'), '/');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        
        Photo::whereHas('gallery', function ($query) {
            $query->where('is_public', true);
        })->with('gallery')->chunk(100, function ($photos) use (&$xml, $baseUrl) {
            foreach ($photos as $photo) {
                $pageUrl = $baseUrl . '/photos/' . $photo->id;
                $imageUrl = $baseUrl . '/api/media/' . $photo->gallery->slug . '/' . $photo->filename;
                
                $xml .= '  <url>' . "\n";
                $xml .= '    <loc>' . $pageUrl . '</loc>' . "\n";
                $xml .= '    <image:image>' . "\n";
                $xml .= '      <image:loc>' . htmlspecialchars($imageUrl) . '</image:loc>' . "\n";
                if ($photo->gallery->name) {
                    $xml .= '      <image:title>' . htmlspecialchars($photo->gallery->name . ' - ' . $photo->filename) . '</image:title>' . "\n";
                }
                $xml .= '    </image:image>' . "\n";
                $xml .= '  </url>' . "\n";
            }
        });
        
        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'text/xml');
    }
}
