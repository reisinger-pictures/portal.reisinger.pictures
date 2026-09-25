<?php

namespace App\Http\Controllers;

use App\Http\Resources\PayoutPoolResource;
use App\Http\Resources\PhotographerStatementResource;
use App\Models\PayoutPool;
use App\Models\PhotographerStatement;
use App\Services\PayoutCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayoutController extends Controller
{
    public function adminIndex()
    {
        $pools = PayoutPool::orderBy('year', 'desc')->orderBy('month', 'desc')->get();
        $statements = PhotographerStatement::with('user:id,name,email')
            ->orderBy('year', 'desc')->orderBy('month', 'desc')->get();

        return response()->json([
            'pools' => $pools->map(fn ($p) => new PayoutPoolResource($p))->values(),
            'statements' => $statements->map(fn ($s) => new PhotographerStatementResource($s))->values(),
        ]);
    }

    public function calculate(Request $request, PayoutCalculationService $service)
    {
        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2024',
            'net_pool_cents' => 'required|integer|min:0|max:100000000',
        ]);

        DB::transaction(function () use ($request, $service) {
            $poolIdentity = [
                'year' => (int) $request->year,
                'month' => (int) $request->month,
            ];

            // createOrFirst is the race-safe insert primitive: the V040
            // natural-key index resolves a concurrent first insert, and the
            // following row lock serializes the financial update.
            $pool = PayoutPool::query()->createOrFirst(
                $poolIdentity,
                [
                    'net_pool_cents' => (int) $request->net_pool_cents,
                    'photographer_share_percent' => 50,
                ],
            );
            $pool = PayoutPool::query()
                ->whereKey($pool->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $pool->update([
                'net_pool_cents' => (int) $request->net_pool_cents,
                'photographer_share_percent' => 50,
            ]);

            // Never destroy the audit trail: approved/paid statements are locked and
            // must survive a recalculation. Lock the recalculable set before
            // deleting it so an approval cannot race the delete boundary.
            $recalculable = PhotographerStatement::query()
                ->where('year', $poolIdentity['year'])
                ->where('month', $poolIdentity['month'])
                ->whereNotIn('status', ['approved', 'paid'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($recalculable->isNotEmpty()) {
                PhotographerStatement::query()
                    ->whereKey($recalculable->modelKeys())
                    ->delete();
            }

            $service->calculatePoolShares($pool);
            $service->calculatePowerUserDelta($pool->month, $pool->year);
            $service->finalizeStatements($pool->month, $pool->year);
        });

        return response()->json(['success' => true]);
    }

    public function approveStatement($id)
    {
        DB::transaction(function () use ($id): void {
            $stmt = PhotographerStatement::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($stmt->status === 'pending') {
                $stmt->update(['status' => 'approved']);
            }
        });

        return response()->json(['success' => true]);
    }

    public function markAsPaid($id)
    {
        DB::transaction(function () use ($id): void {
            $stmt = PhotographerStatement::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($stmt->status === 'approved') {
                $stmt->update(['status' => 'paid']);
            }
        });

        return response()->json(['success' => true]);
    }

    public function myStatements()
    {
        $user = auth('api')->user();
        $statements = PhotographerStatement::where('user_id', $user->id)
            ->orderBy('year', 'desc')->orderBy('month', 'desc')->get();

        return response()->json($statements->map(fn ($s) => new PhotographerStatementResource($s))->values());
    }
}
