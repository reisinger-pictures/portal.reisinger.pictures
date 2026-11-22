<?php

namespace App\Http\Controllers\Eur;

use App\Http\Controllers\Controller;
use App\Services\SettingResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * EAR · Einstellungen (EUE-004 … EUE-009).
 *
 * Die EAR-Einstellungen liegen im bestehenden brand-scoped `settings`-Store —
 * es gibt keine zweite Firmendatenquelle. Geschrieben wird nur durch
 * `super_admin`; gelesen wird brand-scoped.
 */
class EurSettingsController extends Controller
{
    public const DEFAULT_BUSINESS_SHARE = 100;

    public const DEFAULT_SV_MODE = 'gsvg';

    public function show(SettingResolver $resolver): JsonResponse
    {
        return response()->json([
            'business_year' => 2026,
            'default_business_share' => (int) ($resolver->get('eur_default_business_share') ?? self::DEFAULT_BUSINESS_SHARE),
            'sv_mode' => (string) ($resolver->get('eur_sv_mode') ?? self::DEFAULT_SV_MODE),
            'kleinunternehmer' => filter_var($resolver->get('eur_kleinunternehmer', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function update(Request $request, SettingResolver $resolver): JsonResponse
    {
        Gate::authorize('manage-catalog');

        $data = $request->validate([
            'default_business_share' => 'required|integer|min:0|max:100|multiple_of:5',
            'sv_mode' => ['required', Rule::in(['gsvg', 'ns_gsvg', 'fsvg', 'uv_only'])],
            'kleinunternehmer' => 'required|boolean',
        ]);

        $resolver->set('eur_default_business_share', (string) $data['default_business_share']);
        $resolver->set('eur_sv_mode', (string) $data['sv_mode']);
        $resolver->set('eur_kleinunternehmer', $data['kleinunternehmer'] ? '1' : '0');

        return response()->json(['success' => true]);
    }
}
