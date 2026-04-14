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

    public function getWatermarkImage()
    {
        $path = storage_path('app/private/watermark.svg');
        if (!file_exists($path)) abort(404);
        return response()->file($path, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-cache, no-store, must-revalidate']);
    }

    public function getWatermark()
    {
        return response()->json([
            'has_svg' => file_exists(storage_path('app/private/watermark.svg')),
            'text' => Setting::where('key', 'watermark_text')->value('value') ?? 'reisinger.pictures',
            'opacity' => (float) (Setting::where('key', 'watermark_opacity')->value('value') ?? 0.15)
        ]);
    }

    public function updateWatermark(Request $request)
    {
        $request->validate([
            'text' => 'nullable|string|max:100',
            'opacity' => 'nullable|numeric|min:0.05|max:1.0',
            'svg' => 'nullable|file|mimes:svg'
        ]);

        if ($request->hasFile('svg')) {
            $request->file('svg')->move(storage_path('app/private'), 'watermark.svg');
        }

        Setting::updateOrCreate(['key' => 'watermark_text'], ['value' => $request->text ?? '']);
        if ($request->has('opacity')) Setting::updateOrCreate(['key' => 'watermark_opacity'], ['value' => $request->opacity]);

        $oldCaches = glob(storage_path('app/private/watermark_master_*.png'));
        if ($oldCaches) array_map('unlink', $oldCaches);

        return response()->json(['success' => true]);
    }

    public function getLicenseTerms()
    {
        return response()->json([
            'editorial' => Setting::where('key', 'term_editorial')->value('value') ?? 'Nur für redaktionelle Berichterstattung zugelassen.',
            'commercial' => Setting::where('key', 'term_commercial')->value('value') ?? 'Uneingeschränkte kommerzielle Nutzung ist gestattet.',
            '1_year' => Setting::where('key', 'term_1_year')->value('value') ?? 'Nutzungsrecht befristet auf 1 Jahr ab Rechnungsdatum.',
            'unlimited' => Setting::where('key', 'term_unlimited')->value('value') ?? 'Zeitlich unbegrenztes Nutzungsrecht.',
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
            'base_price' => 'nullable|numeric|min:5',
            'term_editorial' => 'nullable|string',
            'term_commercial' => 'nullable|string',
            'term_1_year' => 'nullable|string',
            'term_unlimited' => 'nullable|string',
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
