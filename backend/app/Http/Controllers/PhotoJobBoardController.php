<?php

namespace App\Http\Controllers;

use App\Enums\PhotoJobStatus;
use App\Models\LightroomCatalog;
use App\Models\PhotoJob;
use App\Support\BrandRegistry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PhotoJobBoardController extends Controller
{
    private function authorizeUser(User $user): void
    {
        if (!$user->is_super_admin && !$user->is_photographer) {
            abort(403, 'Forbidden');
        }
    }

    /**
     * Flag each photo job with `lightroom_catalog_is_mine` so the frontend can
     * hide catalog names that belong to a different user's catalog list.
     * Handles both a Collection and a single model (normalized to a Collection).
     */
    private function applyCatalogPrivacy(Model|Collection $jobs): Model|Collection
    {
        $viewer = Auth::guard('api')->user();
        $ownCatalogNames = LightroomCatalog::ownedBy($viewer)
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->flip();

        $isSingle = $jobs instanceof Model;
        $items = $isSingle ? collect([$jobs]) : $jobs;

        foreach ($items as $photoJob) {
            $catalog = $photoJob->lightroom_catalog;
            $photoJob->lightroom_catalog_is_mine = $catalog !== null
                && $catalog !== ''
                && $ownCatalogNames->has((string) $catalog);
        }

        return $isSingle ? $items->first() : $items;
    }

    private function scopedQuery(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $query = PhotoJob::query()
            ->where('brand', BrandRegistry::currentOrDefault());

        if (!$user->is_super_admin) {
            $query->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhere('assignee_id', $user->id);
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $photoJobs = $this->scopedQuery($user)
            ->with(['owner', 'assignee'])
            ->orderBy('status')
            ->orderBy('position')
            ->get();

        return response()->json(['photo_jobs' => $this->applyCatalogPrivacy($photoJobs)]);
    }

    public function store(Request $request)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'lightroom_catalog' => 'nullable|string|max:255',
            'total_count' => 'nullable|integer|min:0',
            'selected_count' => 'nullable|integer|min:0',
            'target_gallery_id' => 'nullable|exists:galleries,id',
            'assignee_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'status' => 'nullable|string|in:' . implode(',', array_column(PhotoJobStatus::cases(), 'value')),
        ]);

        $status = $validated['status'] ?? PhotoJobStatus::initial()->value;
        $maxPosition = $this->scopedQuery($user)->where('status', $status)->max('position') ?? -1;

        $photoJob = $this->scopedQuery($user)->create(array_merge($validated, [
            'brand' => BrandRegistry::currentOrDefault(),
            'owner_id' => $user->id,
            'status' => $status,
            'position' => $maxPosition + 1,
        ]));

        return response()->json(['photo_job' => $this->applyCatalogPrivacy($photoJob->load('owner', 'assignee'))], 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $photoJob = $this->scopedQuery($user)->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'lightroom_catalog' => 'nullable|string|max:255',
            'total_count' => 'nullable|integer|min:0',
            'selected_count' => 'nullable|integer|min:0',
            'target_gallery_id' => 'nullable|exists:galleries,id',
            'assignee_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'status' => 'sometimes|string|in:' . implode(',', array_column(PhotoJobStatus::cases(), 'value')),
        ]);

        $oldStatus = $photoJob->status;
        $newStatus = $validated['status'] ?? $oldStatus;

        if ($newStatus !== $oldStatus) {
            DB::transaction(function () use ($user, $photoJob, $oldStatus, $newStatus, $validated) {
                $targetCount = $this->scopedQuery($user)
                    ->where('status', $newStatus)
                    ->where('id', '!=', $photoJob->id)
                    ->count();

                $photoJob->fill($validated);
                $photoJob->position = $targetCount;
                $photoJob->save();

                $this->reindexColumn($user, $oldStatus);
                $this->reindexColumn($user, $newStatus, $photoJob->id, $targetCount);
            });
        } else {
            $photoJob->fill($validated)->save();
        }

        return response()->json(['photo_job' => $this->applyCatalogPrivacy($photoJob->load('owner', 'assignee'))]);
    }

    public function move(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $photoJob = $this->scopedQuery($user)->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:' . implode(',', array_column(PhotoJobStatus::cases(), 'value')),
            'position' => 'required|integer|min:0',
        ]);

        $oldStatus = $photoJob->status;
        $newStatus = $validated['status'];

        DB::transaction(function () use ($user, $photoJob, $oldStatus, $newStatus, $validated) {
            $targetCount = $this->scopedQuery($user)
                ->where('status', $newStatus)
                ->where('id', '!=', $photoJob->id)
                ->count();

            $effectivePosition = min((int) $validated['position'], $targetCount);

            $photoJob->status = $newStatus;
            $photoJob->position = $effectivePosition;
            $photoJob->save();

            if ($oldStatus !== $newStatus) {
                $this->reindexColumn($user, $oldStatus);
            }

            $this->reindexColumn($user, $newStatus, $photoJob->id, $effectivePosition);

            if ($oldStatus !== $newStatus) {
                \App\Models\WorkflowLog::create([
                    'item_type' => 'photo_job',
                    'item_id' => $photoJob->id,
                    'from_status' => $oldStatus,
                    'to_status' => $newStatus,
                    'user_id' => $user->id,
                ]);
            }
        });

        return response()->json(['photo_job' => $this->applyCatalogPrivacy($photoJob->load('owner', 'assignee'))]);
    }

    /**
     * Renumber one status column so positions are dense (0..n-1) and the order
     * is stable. If a pinned item id is given, that item is forced to the
     * pinned position and all other items keep their relative stable order.
     */
    private function reindexColumn(User $user, string $status, ?string $pinnedItemId = null, ?int $pinnedPosition = null): void
    {
        $query = $this->scopedQuery($user)->where('status', $status);

        if ($pinnedItemId !== null) {
            $query->where('id', '!=', $pinnedItemId);
        }

        $items = $query
            ->orderBy('position')
            ->orderBy('created_at')
            ->orderBy('updated_at')
            ->get();

        $position = 0;
        foreach ($items as $item) {
            if ($pinnedItemId !== null && $position === $pinnedPosition) {
                $position++;
            }
            if ((int) $item->position !== $position) {
                $item->position = $position;
                $item->save();
            }
            $position++;
        }
    }

    public function destroy($id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $photoJob = $this->scopedQuery($user)->findOrFail($id);
        $photoJob->delete();

        return response()->json(['success' => true]);
    }
}