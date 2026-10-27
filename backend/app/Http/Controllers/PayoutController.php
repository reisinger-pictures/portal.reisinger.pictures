<?php

namespace App\Http\Controllers;

use App\Exceptions\PayoutChronologyException;
use App\Http\Resources\PayoutPoolResource;
use App\Http\Resources\PhotographerStatementResource;
use App\Models\PayoutCorrection;
use App\Models\PayoutPool;
use App\Models\PhotographerStatement;
use App\Services\PayoutCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2024',
            'net_pool_cents' => 'sometimes|required|integer|min:0|max:100000000',
        ]);

        try {
            return DB::transaction(function () use ($validated, $service) {
                $poolIdentity = [
                    'year' => (int) $validated['year'],
                    'month' => (int) $validated['month'],
                ];
                $service->assertChronological($poolIdentity['month'], $poolIdentity['year']);

                $existingPool = PayoutPool::query()
                    ->where($poolIdentity)
                    ->lockForUpdate()
                    ->first();

                $hasCalculationEvidence = PhotographerStatement::query()
                    ->where($poolIdentity)
                    ->exists();
                if (! $hasCalculationEvidence) {
                    $hasCalculationEvidence = PayoutCorrection::query()
                        ->where('settled_month', $poolIdentity['month'])
                        ->where('settled_year', $poolIdentity['year'])
                        ->whereNotNull('settled_at')
                        ->exists();
                }

                if (
                    (! $hasCalculationEvidence || $existingPool === null)
                    && ! array_key_exists('net_pool_cents', $validated)
                ) {
                    throw ValidationException::withMessages([
                        'net_pool_cents' => ['The net pool cents field is required.'],
                    ]);
                }

                $netPoolCents = array_key_exists('net_pool_cents', $validated)
                    ? (int) $validated['net_pool_cents']
                    : (int) $existingPool->net_pool_cents;

                // createOrFirst resolves a concurrent first insert through the
                // V040 natural-key index; the following row lock serializes updates.
                $pool = PayoutPool::query()->createOrFirst(
                    $poolIdentity,
                    [
                        'net_pool_cents' => $netPoolCents,
                        'photographer_share_percent' => 50,
                    ],
                );
                $pool = PayoutPool::query()
                    ->whereKey($pool->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $poolUpdates = ['photographer_share_percent' => 50];
                if (array_key_exists('net_pool_cents', $validated)) {
                    $poolUpdates['net_pool_cents'] = $netPoolCents;
                }
                $pool->update($poolUpdates);

                // Lock all month rows before using the model helper to preserve
                // approved/paid audit records and remove only recalculable rows.
                $recalculable = PhotographerStatement::query()
                    ->where('year', $poolIdentity['year'])
                    ->where('month', $poolIdentity['month'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->reject(fn (PhotographerStatement $statement): bool => $statement->isLocked());
                if ($recalculable->isNotEmpty()) {
                    PhotographerStatement::query()
                        ->whereKey($recalculable->modelKeys())
                        ->delete();
                }

                $service->calculatePoolShares($pool);
                $service->calculatePowerUserDelta($pool->month, $pool->year);
                $service->finalizeStatements($pool->month, $pool->year);

                return response()->json(['success' => true]);
            });
        } catch (PayoutChronologyException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function approveStatement($id)
    {
        return DB::transaction(function () use ($id) {
            $stmt = PhotographerStatement::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($stmt->status === 'pending') {
                $stmt->update(['status' => 'approved']);
            }

            return response()->json(['success' => true]);
        });
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
