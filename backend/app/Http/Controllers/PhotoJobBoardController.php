<?php

namespace App\Http\Controllers;

use App\Enums\PhotoJobStatus;
use App\Models\LightroomCatalog;
use App\Models\PhotoJob;
use App\Models\User;
use App\Models\WorkflowLog;
use App\Services\AuthorizationService;
use App\Services\BoardPositionService;
use App\Services\BoardScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PhotoJobBoardController extends Controller
{
    public function __construct(
        private readonly BoardScopeService $boardScope,
        private readonly BoardPositionService $boardPositions,
    ) {}

    private function authorizeUser(User $user): void
    {
        $svc = app(AuthorizationService::class);
        if (! $svc->isSuperAdmin($user) && ! $svc->isPhotographer($user)) {
            abort(403, 'Forbidden');
        }

        $this->boardScope->assertActorBrand($user);
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

    private function scopedQuery(User $user): Builder
    {
        return $this->boardScope->photoJobs($user);
    }

    /**
     * Positions are maintained in the item owner's column. Visibility of an
     * assigned item does not grant a caller permission to renumber every
     * owner's board in the same brand.
     */
    private function positionQuery(User $user, mixed $ownerId = null): Builder
    {
        return PhotoJob::query()
            ->forBrand($this->boardScope->brand())
            ->where('owner_id', $ownerId ?? $user->getKey());
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
            // The schema has NOT NULL counters with a default of zero.  Omitted
            // values use that default, while explicit null is rejected.
            'total_count' => 'sometimes|integer|min:0',
            'selected_count' => 'sometimes|integer|min:0',
            'target_gallery_id' => 'nullable|exists:galleries,id',
            'assignee_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'status' => 'nullable|string|in:'.implode(',', array_column(PhotoJobStatus::cases(), 'value')),
        ]);

        $brand = $this->boardScope->brand();
        $this->boardScope->validateRelationships($user, $validated, $brand);
        $status = $validated['status'] ?? PhotoJobStatus::initial()->value;

        $photoJob = $this->boardPositions->transaction(
            $brand,
            ['photo_jobs', 'projects'],
            function () use ($user, $validated, $status, $brand): PhotoJob {
                $this->boardScope->validateRelationships($user, $validated, $brand);

                $columnQuery = $this->positionQuery($user);
                $position = $this->boardPositions->appendPosition($columnQuery, $status);

                return $this->scopedQuery($user)->create(array_merge($validated, [
                    'brand' => $brand,
                    'owner_id' => $user->id,
                    'status' => $status,
                    'position' => $position,
                ]));
            }
        );

        return response()->json(['photo_job' => $this->applyCatalogPrivacy($photoJob->load('owner', 'assignee'))], 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        // Resolve the item before validating relationship IDs so an item outside
        // the actor's brand/visibility scope remains a 404, not a data oracle.
        $photoJob = $this->scopedQuery($user)->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'lightroom_catalog' => 'nullable|string|max:255',
            'total_count' => 'sometimes|integer|min:0',
            'selected_count' => 'sometimes|integer|min:0',
            'target_gallery_id' => 'nullable|exists:galleries,id',
            'assignee_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'status' => 'sometimes|string|in:'.implode(',', array_column(PhotoJobStatus::cases(), 'value')),
        ]);

        $brand = $this->boardScope->brand();
        $this->boardScope->validateRelationships($user, $validated, $brand);

        $updated = $this->boardPositions->transaction(
            $brand,
            ['photo_jobs', 'projects'],
            function () use ($user, $photoJob, $validated): PhotoJob {
                $fresh = $this->scopedQuery($user)->findOrFail($photoJob->getKey());
                $this->boardScope->validateRelationships($user, $validated, $this->boardScope->brand());

                $oldStatus = (string) $fresh->status;
                $newStatus = $validated['status'] ?? $oldStatus;
                $attributes = $validated;
                unset($attributes['status']);

                $fresh->fill($attributes);
                $positionQuery = $this->positionQuery($user, $fresh->owner_id);

                if ($newStatus !== $oldStatus) {
                    $this->boardPositions->positionItem(
                        $positionQuery,
                        $fresh,
                        $newStatus,
                        null,
                    );
                } else {
                    $fresh->save();
                    $this->boardPositions->reindexColumn(
                        $positionQuery,
                        $newStatus,
                    );
                }

                return $fresh;
            }
        );

        return response()->json(['photo_job' => $this->applyCatalogPrivacy($updated->load('owner', 'assignee'))]);
    }

    public function move(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $this->scopedQuery($user)->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', array_column(PhotoJobStatus::cases(), 'value')),
            'position' => 'required|integer|min:0',
        ]);

        $brand = $this->boardScope->brand();
        $moved = $this->boardPositions->transaction(
            $brand,
            ['photo_jobs', 'projects'],
            function () use ($user, $id, $validated): PhotoJob {
                $photoJob = $this->scopedQuery($user)->findOrFail($id);
                $oldStatus = (string) $photoJob->status;
                $newStatus = $validated['status'];

                $this->boardPositions->positionItem(
                    $this->positionQuery($user, $photoJob->owner_id),
                    $photoJob,
                    $newStatus,
                    (int) $validated['position'],
                );

                if ($oldStatus !== $newStatus) {
                    WorkflowLog::create([
                        'item_type' => 'photo_job',
                        'item_id' => $photoJob->id,
                        'from_status' => $oldStatus,
                        'to_status' => $newStatus,
                        'user_id' => $user->id,
                    ]);
                }

                return $photoJob;
            }
        );

        return response()->json(['photo_job' => $this->applyCatalogPrivacy($moved->load('owner', 'assignee'))]);
    }

    public function destroy($id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $this->boardPositions->transaction(
            $this->boardScope->brand(),
            ['photo_jobs', 'projects'],
            function () use ($user, $id): void {
                $photoJob = $this->scopedQuery($user)->findOrFail($id);
                $status = (string) $photoJob->status;
                $ownerId = $photoJob->owner_id;
                $photoJob->delete();

                $this->boardPositions->reindexColumn(
                    $this->positionQuery($user, $ownerId),
                    $status,
                );
            }
        );

        return response()->json(['success' => true]);
    }
}
