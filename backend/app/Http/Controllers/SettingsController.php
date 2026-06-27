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
        $pfx = config('app.brand') === 'all-the.rest' ? 'atr_' : '';
        $path = \Illuminate\Support\Facades\Storage::disk('photos')->path('_watermarks/' . $pfx . 'watermark.svg');
        if (!file_exists($path)) {
            $path = \Illuminate\Support\Facades\Storage::disk('photos')->path('_watermarks/watermark.svg');
        }
        if (!file_exists($path)) abort(404);
        return response()->file($path, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-cache, no-store, must-revalidate']);
    }

    public function getWatermark()
    {
        $pfx = config('app.brand') === 'all-the.rest' ? 'atr_' : '';
        $disk = \Illuminate\Support\Facades\Storage::disk('photos');
        return response()->json([
            'has_svg' => $disk->exists('_watermarks/' . $pfx . 'watermark.svg'),
            'opacity' => (float) (Setting::where('key', $pfx . 'watermark_opacity')->value('value') ?? Setting::where('key', 'watermark_opacity')->value('value') ?? 0.15)
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

        $pfx = config('app.brand') === 'all-the.rest' ? 'atr_' : '';
        if ($request->hasFile('svg')) $disk->putFileAs($dir, $request->file('svg'), $pfx . 'watermark.svg');
        if ($request->hasFile('bucket_500')) $disk->putFileAs($dir, $request->file('bucket_500'), $pfx . 'master_500.png');
        if ($request->hasFile('bucket_1000')) $disk->putFileAs($dir, $request->file('bucket_1000'), $pfx . 'master_1000.png');
        if ($request->hasFile('bucket_2000')) $disk->putFileAs($dir, $request->file('bucket_2000'), $pfx . 'master_2000.png');
        if ($request->hasFile('bucket_500_sel')) $disk->putFileAs($dir, $request->file('bucket_500_sel'), $pfx . 'master_selection_500.png');
        if ($request->hasFile('bucket_1000_sel')) $disk->putFileAs($dir, $request->file('bucket_1000_sel'), $pfx . 'master_selection_1000.png');
        if ($request->hasFile('bucket_2000_sel')) $disk->putFileAs($dir, $request->file('bucket_2000_sel'), $pfx . 'master_selection_2000.png');

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

    /**
     * R-01 (security/naming): Lizenzbedingungen + Preisfaktoren — KEINE Bank-/Firmendaten.
     * Öffentlich (Gallery-/License-Selector-/Calculator-Flows). Sensible Billing-/Impressum-Daten
     * liegen bewusst im separaten, auth-geschützten Endpunkt getBillingDetails() (SRP-Trennung).
     */
    public function getLicenseTerms()
    {
        return response()->json([
            'editorial' => Setting::where('key', 'term_editorial')->value('value'),
            'commercial' => Setting::where('key', 'term_commercial')->value('value'),
            '1_year' => Setting::where('key', 'term_1_year')->value('value'),
            'unlimited' => Setting::where('key', 'term_unlimited')->value('value'),
            'territory_national' => Setting::where('key', 'term_territory_national')->value('value'),
            'territory_international' => Setting::where('key', 'term_territory_international')->value('value'),
            'mult_commercial' => Setting::where('key', 'mult_commercial')->value('value'),
            'mult_unlimited' => Setting::where('key', 'mult_unlimited')->value('value'),
            'mult_international' => Setting::where('key', 'mult_international')->value('value'),
            'web' => Setting::where('key', 'term_web')->value('value'),
            'print' => Setting::where('key', 'term_print')->value('value'),
            'original' => Setting::where('key', 'term_original')->value('value'),
            'base_price' => Setting::where('key', 'base_price')->value('value'),
            'calc_base_price' => Setting::where('key', 'calc_base_price')->value('value'),
            'calc_hourly_rate' => Setting::where('key', 'calc_hourly_rate')->value('value'),
            'calc_images_per_hour' => Setting::where('key', 'calc_images_per_hour')->value('value'),
            'calc_outdoor_multiplier' => Setting::where('key', 'calc_outdoor_multiplier')->value('value'),
            'atr_base_price' => Setting::where('key', 'atr_base_price')->value('value'),
            'atr_setup_fee' => Setting::where('key', 'atr_setup_fee')->value('value'),
            'atr_privacy_fee' => Setting::where('key', 'atr_privacy_fee')->value('value'),
            'atr_extra_image_fee' => Setting::where('key', 'atr_extra_image_fee')->value('value'),
        ]);
    }

    /**
     * R-01: Bankverbindung & Impressum — NUR authentifiziert (ClientOrdersView, Management).
     * Getrennt von den Lizenzbedingungen: Lizenztexte sind public-safe, Billing-/Firmendaten
     * sind sensibel und dürfen anonym nicht exponiert werden.
     */
    public function getBillingDetails()
    {
        return response()->json([
            'bank_iban' => Setting::where('key', config('app.brand') === 'all-the.rest' ? 'atr_bank_iban' : 'bank_iban')->value('value') ?? Setting::where('key', 'bank_iban')->value('value') ?? '',
            'bank_bic' => Setting::where('key', config('app.brand') === 'all-the.rest' ? 'atr_bank_bic' : 'bank_bic')->value('value') ?? Setting::where('key', 'bank_bic')->value('value') ?? '',
            'bank_holder' => Setting::where('key', config('app.brand') === 'all-the.rest' ? 'atr_bank_holder' : 'bank_holder')->value('value') ?? Setting::where('key', 'bank_holder')->value('value') ?? '',
            'company_street' => Setting::where('key', config('app.brand') === 'all-the.rest' ? 'atr_company_street' : 'company_street')->value('value') ?? Setting::where('key', 'company_street')->value('value') ?? '',
            'company_zip' => Setting::where('key', config('app.brand') === 'all-the.rest' ? 'atr_company_zip' : 'company_zip')->value('value') ?? Setting::where('key', 'company_zip')->value('value') ?? '',
            'company_city' => Setting::where('key', config('app.brand') === 'all-the.rest' ? 'atr_company_city' : 'company_city')->value('value') ?? Setting::where('key', 'company_city')->value('value') ?? '',
            'company_country' => Setting::where('key', config('app.brand') === 'all-the.rest' ? 'atr_company_country' : 'company_country')->value('value') ?? Setting::where('key', 'company_country')->value('value') ?? '',
            'company_email' => Setting::where('key', config('app.brand') === 'all-the.rest' ? 'atr_company_email' : 'company_email')->value('value') ?? Setting::where('key', 'company_email')->value('value') ?? 'hello@reisinger.pictures',
        ]);
    }

    public function updateLicenseTerms(Request $request)
    {
        // R-01 (naming/SRP): nur Lizenzbedingungen + Preisfaktoren. Bank-/Firmendaten gehören
        // in updateBillingDetails() (separater Endpunkt).
        $validated = $request->validate([
            'base_price' => 'nullable|integer|min:500',
            'calc_base_price' => 'nullable|numeric|min:0',
            'calc_hourly_rate' => 'nullable|numeric|min:0',
            'calc_images_per_hour' => 'nullable|integer|min:1',
            'calc_outdoor_multiplier' => 'nullable|numeric|min:0.1|max:1',
            'atr_base_price' => 'nullable|numeric|min:0',
            'atr_setup_fee' => 'nullable|numeric|min:0',
            'atr_privacy_fee' => 'nullable|numeric|min:0',
            'atr_extra_image_fee' => 'nullable|numeric|min:0',
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
        ]);

        $pfx = config('app.brand') === 'all-the.rest' ? 'atr_' : '';
        foreach ($validated as $key => $value) {
            if ($value !== null) {
                Setting::updateOrCreate(['key' => $pfx . $key], ['value' => $value]);
            }
        }
        return response()->json(['success' => true]);
    }

    /**
     * R-01: Bankverbindung & Impressum speichern (nur Lizenzbedingungen-unabhängige Felder).
     */
    public function updateBillingDetails(Request $request)
    {
        $validated = $request->validate([
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
