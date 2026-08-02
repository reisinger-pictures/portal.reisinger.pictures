<?php

namespace App\Http\Controllers;

use App\Models\LightroomCatalog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class LightroomCatalogController extends Controller
{
    /** Gate: Super-Admin und Fotografen dürfen eigene Kataloge lesen und verwalten. */
    private function authorizeView(User $user): void
    {
        if (!$user->is_super_admin && !$user->is_photographer) {
            abort(403, 'Forbidden');
        }
    }

    private function scopedQuery(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return LightroomCatalog::ownedBy($user);
    }

    public function index(Request $request)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeView($user);

        $catalogs = $this->scopedQuery($user)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        return response()->json(['lightroom_catalogs' => $catalogs]);
    }

    public function store(Request $request)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeView($user);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('lightroom_catalogs', 'name')
                    ->where('user_id', $user->id),
            ],
        ]);

        $maxPosition = $this->scopedQuery($user)->max('position') ?? -1;

        $catalog = $this->scopedQuery($user)->create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'position' => $maxPosition + 1,
        ]);

        return response()->json(['lightroom_catalog' => $catalog], 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeView($user);

        $catalog = $this->scopedQuery($user)->findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('lightroom_catalogs', 'name')
                    ->ignore($catalog->id)
                    ->where('user_id', $user->id),
            ],
        ]);

        $catalog->fill($validated)->save();

        return response()->json(['lightroom_catalog' => $catalog]);
    }

    public function destroy($id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeView($user);

        $catalog = $this->scopedQuery($user)->findOrFail($id);
        $catalog->delete();

        return response()->json(['success' => true]);
    }
}
