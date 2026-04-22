<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PayoutPool;
use App\Models\PhotographerStatement;
use App\Services\PayoutCalculationService;

class PayoutController extends Controller
{
    public function adminIndex()
    {
        $user = auth('api')->user();
        if (!$user->is_super_admin) return response()->json(['error' => 'Forbidden'], 403);
        
        $pools = PayoutPool::orderBy('year', 'desc')->orderBy('month', 'desc')->get();
        $statements = PhotographerStatement::with('user:id,name,email')
            ->orderBy('year', 'desc')->orderBy('month', 'desc')->get();
        
        return response()->json(['pools' => $pools, 'statements' => $statements]);
    }

    public function calculate(Request $request, PayoutCalculationService $service)
    {
        $user = auth('api')->user();
        if (!$user->is_super_admin) return response()->json(['error' => 'Forbidden'], 403);

        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2024',
            'net_pool_cents' => 'required|integer|min:0'
        ]);

        // Create or update the pool for the given month
        $pool = PayoutPool::updateOrCreate(
            ['month' => $request->month, 'year' => $request->year],
            ['net_pool_cents' => $request->net_pool_cents, 'photographer_share_percent' => 50]
        );

        // Clear existing statements for this month to cleanly recalculate
        PhotographerStatement::where('month', $request->month)
            ->where('year', $request->year)->delete();

        $service->calculatePoolShares($pool);
        $service->calculatePowerUserDelta($request->month, $request->year);
        $service->finalizeStatements($request->month, $request->year);

        return response()->json(['success' => true]);
    }

    public function approveStatement($id)
    {
        $user = auth('api')->user();
        if (!$user->is_super_admin) return response()->json(['error' => 'Forbidden'], 403);

        $stmt = PhotographerStatement::findOrFail($id);
        if ($stmt->status === 'pending') {
            $stmt->update(['status' => 'approved']);
        }
        return response()->json(['success' => true]);
    }

    public function markAsPaid($id)
    {
        $user = auth('api')->user();
        if (!$user->is_super_admin) return response()->json(['error' => 'Forbidden'], 403);

        $stmt = PhotographerStatement::findOrFail($id);
        if ($stmt->status === 'approved') {
            $stmt->update(['status' => 'paid']);
        }
        return response()->json(['success' => true]);
    }

    public function myStatements()
    {
        $user = auth('api')->user();
        $statements = PhotographerStatement::where('user_id', $user->id)
            ->orderBy('year', 'desc')->orderBy('month', 'desc')->get();
            
        return response()->json($statements);
    }
}
