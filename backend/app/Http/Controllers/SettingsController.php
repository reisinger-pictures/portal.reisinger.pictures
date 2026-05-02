<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    public function getSystemInfo()
    {
        $timestamp = Cache::rememberForever('laravel_build_time', function () {
            $directories = [app_path(), base_path('routes'), base_path('config'), base_path('resources/views')];
            $maxTime = 0;
            foreach ($directories as $dir) {
                if (!is_dir($dir)) continue;
                $files = File::allFiles($dir);
                foreach ($files as $file) {
                    $time = $file->getMTime();
                    if ($time > $maxTime) $maxTime = $time;
                }
            }
            return $maxTime ?: time();
        });

        $latestMigration = \Illuminate\Support\Facades\DB::table('migrations')->orderBy('id', 'desc')->value('migration');
        $dbVersion = \Illuminate\Support\Facades\DB::table('migrations')->count();
        if ($latestMigration && preg_match('/V(\d+)__/', $latestMigration, $matches)) {
            $dbVersion = (int)$matches[1];
        }

        return response()->json([
            'laravel_build_time' => date('c', $timestamp),
            'php_version' => phpversion(),
            'laravel_version' => app()->version(),
            'db_version' => $dbVersion
        ]);
    }

    public function getWatermarkSvg()
    {
        $path = \Illuminate\Support\Facades\Storage::disk('photos')->path('_watermarks/watermark.svg');
        if (!file_exists($path)) abort(404);
        return response()->file($path, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-cache, no-store, must-revalidate']);
    }

    public function getWatermark()
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('photos');
        return response()->json([
            'has_svg' => $disk->exists('_watermarks/watermark.svg'),
            'opacity' => (float) (Setting::where('key', 'watermark_opacity')->value('value') ?? 0.15)
        ]);
    }

    public function updateWatermark(Request $request)
    {
        $request->validate([
            'opacity' => 'nullable|numeric|min:0.05|max:1.0',
            'svg' => 'nullable|file',
            'bucket_500' => 'nullable|file',
            'bucket_1000' => 'nullable|file',
            'bucket_2000' => 'nullable|file',
            'bucket_500_sel' => 'nullable|file',
            'bucket_1000_sel' => 'nullable|file',
            'bucket_2000_sel' => 'nullable|file',
        ]);

        if ($request->has('opacity')) Setting::updateOrCreate(['key' => 'watermark_opacity'], ['value' => $request->opacity]);
        
        $disk = \Illuminate\Support\Facades\Storage::disk('photos');
        $dir = '_watermarks';
        if (!$disk->exists($dir)) $disk->makeDirectory($dir);

        if ($request->hasFile('svg')) $disk->putFileAs($dir, $request->file('svg'), 'watermark.svg');
        if ($request->hasFile('bucket_500')) $disk->putFileAs($dir, $request->file('bucket_500'), 'master_500.png');
        if ($request->hasFile('bucket_1000')) $disk->putFileAs($dir, $request->file('bucket_1000'), 'master_1000.png');
        if ($request->hasFile('bucket_2000')) $disk->putFileAs($dir, $request->file('bucket_2000'), 'master_2000.png');
        if ($request->hasFile('bucket_500_sel')) $disk->putFileAs($dir, $request->file('bucket_500_sel'), 'master_selection_500.png');
        if ($request->hasFile('bucket_1000_sel')) $disk->putFileAs($dir, $request->file('bucket_1000_sel'), 'master_selection_1000.png');
        if ($request->hasFile('bucket_2000_sel')) $disk->putFileAs($dir, $request->file('bucket_2000_sel'), 'master_selection_2000.png');

        // Cache-Busting: Lösche alle generierten Wasserzeichen-Bilder asynchron im Hintergrund
        dispatch(function () {
            $disk = \Illuminate\Support\Facades\Storage::disk('photos');
            $directories = $disk->directories();
            foreach ($directories as $dir) {
                // Nur Galerie-Ordner (UUIDs) durchsuchen
                if (\Illuminate\Support\Str::isUuid($dir)) {
                    $disk->deleteDirectory($dir . '/_watermarked');
                    $disk->deleteDirectory($dir . '/_thumbs/_watermarked');
                }
            }
        });

        return response()->json(['success' => true]);
    }

    public function getLicenseTerms()
    {
        return response()->json([
            'editorial' => Setting::where('key', 'term_editorial')->value('value') ?? 'Nur für redaktionelle Berichterstattung zugelassen.',
            'commercial' => Setting::where('key', 'term_commercial')->value('value') ?? 'Uneingeschränkte kommerzielle Nutzung ist gestattet.',
            '1_year' => Setting::where('key', 'term_1_year')->value('value') ?? 'Nutzungsrecht befristet auf 1 Jahr ab Rechnungsdatum.',
            'unlimited' => Setting::where('key', 'term_unlimited')->value('value') ?? 'Zeitlich unbegrenztes Nutzungsrecht.',
            'territory_national' => Setting::where('key', 'term_territory_national')->value('value') ?? 'Nutzung nur im Inland.',
            'territory_international' => Setting::where('key', 'term_territory_international')->value('value') ?? 'Weltweite Nutzung gestattet.',
            'mult_commercial' => Setting::where('key', 'mult_commercial')->value('value') ?? '2.0',
            'mult_unlimited' => Setting::where('key', 'mult_unlimited')->value('value') ?? '1.5',
            'mult_international' => Setting::where('key', 'mult_international')->value('value') ?? '1.5',
            'web' => Setting::where('key', 'term_web')->value('value') ?? 'Auflösung optimiert für Web & Social Media.',
            'print' => Setting::where('key', 'term_print')->value('value') ?? 'Hohe Auflösung für den Druck.',
            'original' => Setting::where('key', 'term_original')->value('value') ?? 'Maximale Originalauflösung.',
            'base_price' => Setting::where('key', 'base_price')->value('value') ?? '35.00',
            'bank_iban' => Setting::where('key', 'bank_iban')->value('value') ?? '',
            'bank_bic' => Setting::where('key', 'bank_bic')->value('value') ?? '',
            'bank_holder' => Setting::where('key', 'bank_holder')->value('value') ?? '',
            'company_street' => Setting::where('key', 'company_street')->value('value') ?? '',
            'company_zip' => Setting::where('key', 'company_zip')->value('value') ?? '',
            'company_city' => Setting::where('key', 'company_city')->value('value') ?? '',
            'company_country' => Setting::where('key', 'company_country')->value('value') ?? '',
            'company_email' => Setting::where('key', 'company_email')->value('value') ?? 'hello@reisinger.pictures'
        ]);
    }

    public function updateLicenseTerms(Request $request)
    {
        $validated = $request->validate([
            'base_price' => 'nullable|integer|min:500',
            'term_editorial' => 'nullable|string',
            'term_commercial' => 'nullable|string',
            'term_1_year' => 'nullable|string',
            'term_unlimited' => 'nullable|string',
            'term_territory_national' => 'nullable|string',
            'term_territory_international' => 'nullable|string',
            'mult_commercial' => 'required|numeric|min:1',
            'mult_unlimited' => 'required|numeric|min:1',
            'mult_international' => 'required|numeric|min:1',
            'term_web' => 'nullable|string',
            'term_print' => 'nullable|string',
            'term_original' => 'nullable|string',
            'bank_holder' => 'nullable|string',
            'bank_iban' => 'nullable|string',
            'bank_bic' => 'nullable|string',
            'company_street' => 'nullable|string',
            'company_zip' => 'nullable|string',
            'company_city' => 'nullable|string',
            'company_country' => 'nullable|string',
            'company_email' => 'nullable|string',
        ]);

        foreach ($validated as $key => $value) {
            if ($value !== null) {
                Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }
        return response()->json(['success' => true]);
    }
}
