<?php

namespace App\Http\Controllers;

use App\Enums\Brand;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\BrandRegistry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectBoardController extends Controller
{
    /** Gate: nur Admin / Super-Admin. */
    private function authorizeUser(User $user): void
    {
        if (!$user->is_super_admin && !$user->is_admin) {
            abort(403, 'Forbidden');
        }
    }

    private function scopedQuery(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $query = Project::query()
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

        $projects = $this->scopedQuery($user)
            ->with(['owner', 'assignee'])
            ->orderBy('status')
            ->orderBy('position')
            ->get();

        return response()->json(['projects' => $projects]);
    }

    public function store(Request $request)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255',
            'package' => 'nullable|string|max:255',
            'price_cents' => 'nullable|integer|min:0',
            'assignee_id' => 'nullable|exists:users,id',
            'linked_photo_job_id' => 'nullable|exists:photo_jobs,id',
        ]);

        $maxPosition = $this->scopedQuery($user)->max('position') ?? -1;

        $project = $this->scopedQuery($user)->create(array_merge($validated, [
            'brand' => BrandRegistry::currentOrDefault(),
            'owner_id' => $user->id,
            'status' => ProjectStatus::ANFRAGE->value,
            'payment_status' => 'open',
            'position' => $maxPosition + 1,
        ]));

        return response()->json(['project' => $project->load(['owner', 'assignee'])], 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $project = $this->scopedQuery($user)->findOrFail($id);

        $validated = $request->validate([
            'client_name' => 'sometimes|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255',
            'package' => 'nullable|string|max:255',
            'price_cents' => 'nullable|integer|min:0',
            'assignee_id' => 'nullable|exists:users,id',
            'linked_photo_job_id' => 'nullable|exists:photo_jobs,id',
        ]);

        $project->fill($validated)->save();

        return response()->json(['project' => $project->load('owner', 'assignee')]);
    }

    public function move(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $project = $this->scopedQuery($user)->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:anfrage,angebot,beauftragt,rechnung,bezahlt',
            'position' => 'required|integer|min:0',
        ]);

        $oldStatus = $project->status;
        $project->status = $validated['status'];
        $project->position = $validated['position'];
        $project->save();

        if ($oldStatus !== $project->status) {
            \App\Models\WorkflowLog::create([
                'item_type' => 'project',
                'item_id' => $project->id,
                'from_status' => $oldStatus,
                'to_status' => $project->status,
                'user_id' => $user->id,
            ]);
        }

        return response()->json(['project' => $project->load('owner', 'assignee')]);
    }

    public function destroy($id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $project = $this->scopedQuery($user)->findOrFail($id);
        $project->delete();

        return response()->json(['success' => true]);
    }
}