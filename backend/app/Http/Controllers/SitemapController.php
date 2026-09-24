<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\Photo;
use App\Support\BrandRegistry;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SitemapController extends Controller
{
    public function galleries(Request $request)
    {
        $baseUrl = BrandRegistry::frontendUrl();
        $currentBrand = BrandRegistry::currentIdOrNull();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        if ($currentBrand !== null) {
            Gallery::where('is_public', true)
                ->where('type', 'delivery')
                ->where('brand', $currentBrand)
                ->where('is_hidden', false)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->chunk(100, function ($galleries) use (&$xml, $baseUrl) {
                    foreach ($galleries as $gallery) {
                        // Hidden/public/type and the complete parent-group chain
                        // are effective access rules, not merely raw columns.
                        if ($gallery->isSelection()
                            || ! $gallery->effective_is_public
                            || ! BrandRegistry::galleryTreeMatchesCurrent($gallery)
                            || $gallery->effective_is_hidden) {
                            continue;
                        }

                        $lastMod = $gallery->created_at ? Carbon::parse($gallery->created_at)->toAtomString() : now()->toAtomString();

                        $xml .= '  <url>'."\n";
                        $xml .= '    <loc>'.$baseUrl.'/'.htmlspecialchars($gallery->full_path).'</loc>'."\n";
                        $xml .= '    <lastmod>'.$lastMod.'</lastmod>'."\n";
                        $xml .= '    <changefreq>weekly</changefreq>'."\n";
                        $xml .= '    <priority>0.8</priority>'."\n";
                        $xml .= '  </url>'."\n";
                    }
                });
        }

        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'text/xml');
    }

    public function images(Request $request)
    {
        $baseUrl = BrandRegistry::frontendUrl();
        $currentBrand = BrandRegistry::currentIdOrNull();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

        if ($currentBrand !== null) {
            Photo::where('is_hidden', false)
                ->whereHas('gallery', function ($query) use ($currentBrand) {
                    $query->where('is_public', true)
                        ->where('type', 'delivery')
                        ->where('brand', $currentBrand)
                        ->where('is_hidden', false)
                        ->where(function ($q) {
                            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                })->with('gallery')->chunk(100, function ($photos) use (&$xml, $baseUrl) {
                    foreach ($photos as $photo) {
                        // Hidden/public/type and the complete parent-group chain
                        // are effective access rules, not merely raw columns.
                        if (! $photo->gallery instanceof Gallery
                            || $photo->gallery->isSelection()
                            || ! $photo->gallery->effective_is_public
                            || ! BrandRegistry::galleryTreeMatchesCurrent($photo->gallery)
                            || $photo->effective_is_hidden) {
                            continue;
                        }

                        $pageUrl = $baseUrl.'/photos/'.$photo->id;
                        $imageUrl = $baseUrl.'/api/media/'.$photo->gallery_id.'/'.$photo->filename;

                        $xml .= '  <url>'."\n";
                        $xml .= '    <loc>'.$pageUrl.'</loc>'."\n";
                        $xml .= '    <image:image>'."\n";
                        $xml .= '      <image:loc>'.htmlspecialchars($imageUrl).'</image:loc>'."\n";
                        if ($photo->gallery->name) {
                            $xml .= '      <image:title>'.htmlspecialchars($photo->gallery->name.' - '.$photo->filename).'</image:title>'."\n";
                        }
                        $xml .= '    </image:image>'."\n";
                        $xml .= '  </url>'."\n";
                    }
                });
        }

        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'text/xml');
    }
}
