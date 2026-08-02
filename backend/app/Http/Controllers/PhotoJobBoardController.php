<?php

namespace App\Http\Controllers;

use App\Enums\PhotoJobStatus;
use App\Models\PhotoJob;
use App\Support\BrandRegistry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PhotoJobBoardController extends Controller
{
    private function authorizeUser(User $user): void
    {
        if (!$user->is_super_admin && !$user->is_photographer) {
            abort(403, 'Forbidden');
        }
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

        return response()->json(['photo_jobs' => $photoJobs]);
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
            'is_private' => 'nullable|boolean',
            'assignee_id' => 'nullable|exists:users,id',
        ]);

        $maxPosition = $this->scopedQuery($user)->max('position') ?? -1;

        $photoJob = $this->scopedQuery($user)->create(array_merge($validated, [
            'brand' => BrandRegistry::currentOrDefault(),
            'owner_id' => $user->id,
            'status' => PhotoJobStatus::SHOOTING->value,
            'position' => $maxPosition + 1,
        ]));

        return response()->json(['photo_job' => $photoJob->load('owner', 'assignee')], 201);
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
            'is_private' => 'nullable|boolean',
            'assignee_id' => 'nullable|exists:users,id',
        ]);

        $photoJob->fill($validated)->save();

        return response()->json(['photo_job' => $photoJob->load('owner', 'assignee')]);
    }

    public function move(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $photoJob = $this->scopedQuery($user)->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:shooting,culling,bearbeitung,export,veroeffentlicht',
            'position' => 'required|integer|min:0',
        ]);

        $oldStatus = $photoJob->status;
        $photoJob->status = $validated['status'];
        $photoJob->position = $validated['position'];
        $photoJob->save();

        if ($oldStatus !== $photoJob->status) {
            \App\Models\WorkflowLog::create([
                'item_type' => 'photo_job',
                'item_id' => $photoJob->id,
                'from_status' => $oldStatus,
                'to_status' => $photoJob->status,
                'user_id' => $user->id,
            ]);
        }

        return response()->json(['photo_job' => $photoJob->load('owner', 'assignee')]);
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