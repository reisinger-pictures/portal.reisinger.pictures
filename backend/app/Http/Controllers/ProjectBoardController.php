<?php

namespace App\Http\Controllers;

use App\Enums\Brand;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\PhotoJobStatus;
use App\Models\PhotoJob;
use App\Models\Project;
use App\Support\BrandRegistry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            'notes' => 'nullable|string',
            'status' => 'nullable|string|in:' . implode(',', array_column(ProjectStatus::cases(), 'value')),
            'payment_status' => 'string|in:' . implode(',', array_column(PaymentStatus::cases(), 'value')),
        ]);

        $status = $validated['status'] ?? ProjectStatus::initial()->value;
        $maxPosition = $this->scopedQuery($user)->where('status', $status)->max('position') ?? -1;

        $project = $this->scopedQuery($user)->create(array_merge($validated, [
            'brand' => BrandRegistry::currentOrDefault(),
            'owner_id' => $user->id,
            'status' => $status,
            'payment_status' => $validated['payment_status'] ?? PaymentStatus::OPEN->value,
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
            'notes' => 'nullable|string',
            'status' => 'sometimes|string|in:' . implode(',', array_column(ProjectStatus::cases(), 'value')),
            'payment_status' => 'sometimes|string|in:' . implode(',', array_column(PaymentStatus::cases(), 'value')),
        ]);

        $oldStatus = $project->status;
        $newStatus = $validated['status'] ?? $oldStatus;

        if ($newStatus !== $oldStatus) {
            DB::transaction(function () use ($user, $project, $oldStatus, $newStatus, $validated) {
                $targetCount = $this->scopedQuery($user)
                    ->where('status', $newStatus)
                    ->where('id', '!=', $project->id)
                    ->count();

                $project->fill($validated);
                $project->position = $targetCount;
                $project->save();

                $this->reindexColumn($user, $oldStatus);
                $this->reindexColumn($user, $newStatus, $project->id, $targetCount);
            });
        } else {
            $project->fill($validated)->save();
        }

        return response()->json(['project' => $project->load('owner', 'assignee')]);
    }

    public function move(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $project = $this->scopedQuery($user)->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:anfrage,angebot,beauftragt,rechnung,bezahlt,storniert',
            'position' => 'required|integer|min:0',
        ]);

        $oldStatus = $project->status;
        $newStatus = $validated['status'];

        DB::transaction(function () use ($user, $project, $oldStatus, $newStatus, $validated) {
            $targetCount = $this->scopedQuery($user)
                ->where('status', $newStatus)
                ->where('id', '!=', $project->id)
                ->count();

            $effectivePosition = min((int) $validated['position'], $targetCount);

            $project->status = $newStatus;
            $project->position = $effectivePosition;
            $project->save();

            if ($oldStatus !== $newStatus) {
                $this->reindexColumn($user, $oldStatus);
            }

            $this->reindexColumn($user, $newStatus, $project->id, $effectivePosition);

            if ($oldStatus !== $newStatus) {
                \App\Models\WorkflowLog::create([
                    'item_type' => 'project',
                    'item_id' => $project->id,
                    'from_status' => $oldStatus,
                    'to_status' => $newStatus,
                    'user_id' => $user->id,
                ]);
            }
        });

        return response()->json(['project' => $project->load('owner', 'assignee')]);
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

    public function handoff(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        if (!$user->is_super_admin) {
            abort(403, 'Forbidden');
        }

        $project = $this->scopedQuery($user)->findOrFail($id);

        if ($project->linked_photo_job_id) {
            abort(422, 'already_handed_off');
        }

        $projectBrand = $project->brand instanceof Brand ? $project->brand->value : (string) $project->brand;
        $maxPosition = PhotoJob::query()
            ->where('brand', $projectBrand)
            ->max('position') ?? -1;

        $photoJob = PhotoJob::create([
            'brand' => $projectBrand,
            'owner_id' => $user->id,
            'title' => $project->client_name,
            'status' => PhotoJobStatus::initial()->value,
            'position' => $maxPosition + 1,
        ]);

        $project->linked_photo_job_id = $photoJob->id;
        $project->save();

        return response()->json(['photo_job' => $photoJob->load('owner', 'assignee')], 201);
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