<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\PhotoJobStatus;
use App\Enums\ProjectStatus;
use App\Models\PhotoJob;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowLog;
use App\Services\AuthorizationService;
use App\Services\BoardPositionService;
use App\Services\BoardScopeService;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectBoardController extends Controller
{
    public function __construct(
        private readonly BoardScopeService $boardScope,
        private readonly BoardPositionService $boardPositions,
    ) {}

    /** Gate: nur Admin / Super-Admin. */
    private function authorizeUser(User $user): void
    {
        $svc = app(AuthorizationService::class);
        if (! $svc->isSuperAdmin($user) && ! $svc->isAdmin($user)) {
            abort(403, 'Forbidden');
        }

        $this->boardScope->assertActorBrand($user);
    }

    private function scopedQuery(User $user): Builder
    {
        return $this->boardScope->projects($user);
    }

    /**
     * Positions are maintained in the item owner's column. Visibility of an
     * assigned item does not grant a caller permission to renumber every
     * owner's board in the same brand.
     */
    private function positionQuery(User $user, mixed $ownerId = null): Builder
    {
        return Project::query()
            ->forBrand($this->boardScope->brand())
            ->where('owner_id', $ownerId ?? $user->getKey());
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
            'status' => 'nullable|string|in:'.implode(',', array_column(ProjectStatus::cases(), 'value')),
            'payment_status' => 'string|in:'.implode(',', array_column(PaymentStatus::cases(), 'value')),
        ]);

        $brand = $this->boardScope->brand();
        $this->boardScope->validateRelationships($user, $validated, $brand);
        $status = $validated['status'] ?? ProjectStatus::initial()->value;

        $project = $this->boardPositions->transaction(
            $brand,
            ['projects', 'photo_jobs'],
            function () use ($user, $validated, $status, $brand): Project {
                $this->boardScope->validateRelationships($user, $validated, $brand);

                $columnQuery = $this->positionQuery($user);
                $position = $this->boardPositions->appendPosition($columnQuery, $status);

                return $this->scopedQuery($user)->create(array_merge($validated, [
                    'brand' => $brand,
                    'owner_id' => $user->id,
                    'status' => $status,
                    'payment_status' => $validated['payment_status'] ?? PaymentStatus::OPEN->value,
                    'position' => $position,
                ]));
            }
        );

        return response()->json(['project' => $project->load(['owner', 'assignee'])], 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        // Resolve the item before validating relationship IDs so an item outside
        // the actor's brand/visibility scope remains a 404, not a data oracle.
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
            'status' => 'sometimes|string|in:'.implode(',', array_column(ProjectStatus::cases(), 'value')),
            'payment_status' => 'sometimes|string|in:'.implode(',', array_column(PaymentStatus::cases(), 'value')),
        ]);

        $brand = $this->boardScope->brand();
        $this->boardScope->validateRelationships($user, $validated, $brand, (string) $project->getKey());

        $updated = $this->boardPositions->transaction(
            $brand,
            ['projects', 'photo_jobs'],
            function () use ($user, $project, $validated): Project {
                $fresh = $this->scopedQuery($user)->findOrFail($project->getKey());
                $this->boardScope->validateRelationships(
                    $user,
                    $validated,
                    $this->boardScope->brand(),
                    (string) $fresh->getKey(),
                );

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

        return response()->json(['project' => $updated->load('owner', 'assignee')]);
    }

    public function move(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $this->scopedQuery($user)->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', array_column(ProjectStatus::cases(), 'value')),
            'position' => 'required|integer|min:0',
        ]);

        $brand = $this->boardScope->brand();
        $moved = $this->boardPositions->transaction(
            $brand,
            ['projects', 'photo_jobs'],
            function () use ($user, $id, $validated): Project {
                $project = $this->scopedQuery($user)->findOrFail($id);
                $oldStatus = (string) $project->status;
                $newStatus = $validated['status'];

                $this->boardPositions->positionItem(
                    $this->positionQuery($user, $project->owner_id),
                    $project,
                    $newStatus,
                    (int) $validated['position'],
                );

                if ($oldStatus !== $newStatus) {
                    WorkflowLog::create([
                        'item_type' => 'project',
                        'item_id' => $project->id,
                        'from_status' => $oldStatus,
                        'to_status' => $newStatus,
                        'user_id' => $user->id,
                    ]);
                }

                return $project;
            }
        );

        return response()->json(['project' => $moved->load('owner', 'assignee')]);
    }

    public function handoff(Request $request, $id)
    {
        $user = Auth::guard('api')->user();
        if (! app(AuthorizationService::class)->isSuperAdmin($user)) {
            abort(403, 'Forbidden');
        }
        $this->boardScope->assertActorBrand($user);

        $brand = $this->boardScope->brand();
        $photoJob = $this->boardPositions->transaction(
            $brand,
            ['projects', 'photo_jobs'],
            function () use ($user, $id): PhotoJob {
                $project = $this->scopedQuery($user)
                    ->lockForUpdate()
                    ->findOrFail($id);

                // The project row is locked above, so two concurrent handoffs
                // cannot both observe an empty linked_photo_job_id.
                if ($project->linked_photo_job_id !== null) {
                    abort(422, 'already_handed_off');
                }

                $projectBrand = BrandRegistry::normalizeId($project->brand);
                if ($projectBrand === null) {
                    abort(404);
                }

                $photoJobQuery = PhotoJob::query()
                    ->forBrand($projectBrand)
                    ->where('owner_id', $user->getKey());
                $position = $this->boardPositions->appendPosition(
                    $photoJobQuery,
                    PhotoJobStatus::initial()->value,
                );

                $photoJob = PhotoJob::create([
                    'brand' => $projectBrand,
                    'owner_id' => $user->id,
                    'title' => $project->client_name,
                    'status' => PhotoJobStatus::initial()->value,
                    'position' => $position,
                ]);

                $project->linked_photo_job_id = $photoJob->id;
                $project->save();

                return $photoJob;
            }
        );

        return response()->json(['photo_job' => $photoJob->load('owner', 'assignee')], 201);
    }

    public function destroy($id)
    {
        $user = Auth::guard('api')->user();
        $this->authorizeUser($user);

        $this->boardPositions->transaction(
            $this->boardScope->brand(),
            ['projects', 'photo_jobs'],
            function () use ($user, $id): void {
                $project = $this->scopedQuery($user)->findOrFail($id);
                $status = (string) $project->status;
                $ownerId = $project->owner_id;
                $project->delete();

                $this->boardPositions->reindexColumn(
                    $this->positionQuery($user, $ownerId),
                    $status,
                );
            }
        );

        return response()->json(['success' => true]);
    }
}
