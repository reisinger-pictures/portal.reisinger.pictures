<?php

namespace App\Http\Controllers;

use App\Models\DomainMapping;
use Illuminate\Http\Request;

class DomainMappingController extends Controller
{
    public function index()
    {
        return DomainMapping::with(['role', 'galleryGroup'])->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'domain' => 'required|string|unique:domain_mappings',
            'role_id' => 'nullable|string|exists:roles,id',
            'gallery_group_id' => 'nullable|string|exists:gallery_groups,id',
        ]);

        $mapping = DomainMapping::create($request->only(['domain', 'role_id', 'gallery_group_id']));

        return response()->json(['success' => true, 'mapping' => $mapping]);
    }

    public function destroy($id)
    {
        DomainMapping::destroy($id);
        return response()->json(['success' => true]);
    }
}
