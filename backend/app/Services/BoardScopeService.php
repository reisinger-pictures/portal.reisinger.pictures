<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\PhotoJob;
use App\Models\Project;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Shared brand and relationship rules for the two management boards.
 *
 * A board is always bound to the active host brand.  A relationship accepted by
 * a board must belong to that same brand as well; a legacy null-brand row is
 * never a valid assignee, linked job, or target gallery.  Cross-brand actors
 * may operate on the active board, but that does not make a cross-brand
 * relationship valid.
 */
class BoardScopeService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function brand(): string
    {
        return BrandRegistry::currentIdOrNull() ?? BrandRegistry::currentOrDefault()->value;
    }

    /**
     * Fail closed when a brand-bound actor reaches a board for another host.
     * Only the separately gated, trusted null-brand Super-Admin may cross
     * brands; null relationships themselves are never accepted.
     */
    public function assertActorBrand(User $actor): void
    {
        $actorBrand = BrandRegistry::normalizeId($actor->brand);

        if ($actorBrand === null && ! $this->authorization->isTrustedCrossBrandActor($actor)) {
            abort(403, 'Forbidden (Brand Isolation)');
        }

        if ($actorBrand !== null && $actorBrand !== $this->brand()) {
            abort(403, 'Forbidden (Brand Isolation)');
        }
    }

    public function projects(User $actor, ?string $brand = null): Builder
    {
        $this->assertActorBrand($actor);
        $brand ??= $this->brand();

        $query = Project::query()->forBrand($brand);

        if (! $this->authorization->isSuperAdmin($actor)) {
            $query->where(function (Builder $visible) use ($actor): void {
                $visible->where('owner_id', $actor->getKey())
                    ->orWhere('assignee_id', $actor->getKey());
            });
        }

        return $query;
    }

    public function photoJobs(User $actor, ?string $brand = null): Builder
    {
        $this->assertActorBrand($actor);
        $brand ??= $this->brand();

        $query = PhotoJob::query()->forBrand($brand);

        if (! $this->authorization->isSuperAdmin($actor)) {
            $query->where(function (Builder $visible) use ($actor): void {
                $visible->where('owner_id', $actor->getKey())
                    ->orWhere('assignee_id', $actor->getKey());
            });
        }

        return $query;
    }

    /**
     * Validate only relationship fields that were explicitly present.  Null is
     * intentionally accepted for optional foreign keys so the frontend can
     * explicitly clear an assignee, link, or target gallery.
     *
     * @param  array<string, mixed>  $data
     */
    public function validateRelationships(
        User $actor,
        array $data,
        ?string $brand = null,
        ?string $projectId = null,
    ): void {
        $brand ??= $this->brand();

        $errors = [];

        if (array_key_exists('assignee_id', $data)) {
            $errors = array_merge($errors, $this->validateAssignee($data['assignee_id'], $brand));
        }

        if (array_key_exists('linked_photo_job_id', $data)) {
            $errors = array_merge(
                $errors,
                $this->validateLinkedPhotoJob($actor, $data['linked_photo_job_id'], $brand, $projectId),
            );
        }

        if (array_key_exists('target_gallery_id', $data)) {
            $errors = array_merge($errors, $this->validateTargetGallery($actor, $data['target_gallery_id'], $brand));
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function validateAssignee(mixed $assigneeId, string $brand): array
    {
        if ($assigneeId === null) {
            return [];
        }

        $assignee = User::query()->find($assigneeId);

        if ($assignee === null || BrandRegistry::normalizeId($assignee->brand) !== $brand) {
            return [
                'assignee_id' => ['The selected assignee is not available for this board brand.'],
            ];
        }

        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function validateLinkedPhotoJob(
        User $actor,
        mixed $photoJobId,
        string $brand,
        ?string $projectId,
    ): array {
        if ($photoJobId === null) {
            return [];
        }

        $photoJob = PhotoJob::query()->find($photoJobId);

        if ($photoJob === null || BrandRegistry::normalizeId($photoJob->brand) !== $brand) {
            return [
                'linked_photo_job_id' => ['The selected photo job is not available for this board brand.'],
            ];
        }

        // A handoff creates a one-project relationship.  Do not let two
        // projects point at the same production job through the update API.
        $alreadyLinked = Project::query()
            ->where('linked_photo_job_id', $photoJob->getKey())
            ->when($projectId !== null, fn (Builder $query) => $query->whereKeyNot($projectId))
            ->exists();

        if ($alreadyLinked) {
            return [
                'linked_photo_job_id' => ['The selected photo job is already linked to another project.'],
            ];
        }

        // Brand equality alone is not enough for a non-super-admin: linking a
        // job that the actor cannot see would create a relationship to hidden
        // board metadata.
        if (! $this->authorization->isSuperAdmin($actor)
            && ! $this->photoJobs($actor, $brand)->whereKey($photoJob->getKey())->exists()) {
            return [
                'linked_photo_job_id' => ['The selected photo job is not accessible to this user.'],
            ];
        }

        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function validateTargetGallery(User $actor, mixed $galleryId, string $brand): array
    {
        if ($galleryId === null) {
            return [];
        }

        $gallery = Gallery::query()->find($galleryId);

        if ($gallery === null || ! BrandRegistry::galleryTreeMatchesBrand($gallery, $brand)) {
            return [
                'target_gallery_id' => ['The selected gallery is not available for this board brand.'],
            ];
        }

        if (! $this->authorization->canAccessGallery($actor, (string) $gallery->getKey())) {
            return [
                'target_gallery_id' => ['The selected gallery is not accessible to this user.'],
            ];
        }

        return [];
    }
}
