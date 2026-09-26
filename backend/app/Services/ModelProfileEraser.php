<?php

namespace App\Services;

use App\Enums\Brand;
use App\Models\Act;
use App\Models\ActMember;
use App\Models\Customer;
use App\Models\ModelAccessToken;
use App\Models\ModelRegistrationInvite;
use App\Observers\CustomerObserver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Restlose Löschung eines Model-Profils (DSGVO-Endpoint + Lifecycle-Expiry).
 *
 * Gemeinsame Routine für beide Aufrufer (DRY): löscht Customer samt
 * ModelProfile/Snapshot, allen Fotos + verschlüsselten Dateien, Altersnachweis,
 * Zugangs-Tokens, verknüpften Registrierungs-Einladungen und act_members;
 * mitgliederlose Acts werden entfernt. Ein verknüpftes Portal-Konto wird nur
 * entknüpft (`user_id = null`), nie gelöscht.
 *
 * Die Datenbank-Löschungen laufen in einer Transaktion. Der CustomerObserver
 * erfasst die Dateipfade am tatsächlichen Lösch-Event und übergibt sie erst nach
 * dem Commit an einen retrybaren Job. Scout-Unindexierung wird ebenfalls erst
 * nach dem Commit gequeued.
 */
class ModelProfileEraser
{
    /**
     * @return array{photo_count: int, had_age_proof: bool, affected_acts: int, invite_count: int}
     */
    public function erase(Customer $customer, string $reason, ?string $actorId = null): array
    {
        $customerId = (string) $customer->getKey();
        $brand = $this->brandValue($customer);
        $committed = false;
        $stats = [];

        // The observer uses this marker to label the post-commit cleanup and to
        // make ownership explicit while the transaction is in flight.
        CustomerObserver::deferFileCleanupFor($customerId);

        try {
            $stats = DB::transaction(function () use ($customerId): array {
                $customer = Customer::query()
                    ->whereKey($customerId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Lock the child rows before the delete. This makes the paths
                // observed by CustomerObserver authoritative and prevents a
                // concurrent owner/admin write from changing the set unnoticed.
                $profile = $customer->modelProfile()->lockForUpdate()->first();
                $customer->modelPhotos()->lockForUpdate()->get(['id']);

                $photoCount = (int) $customer->modelPhotos()->count();
                $hadAgeProof = (bool) ($profile?->age_proof_path);

                // Capture affected acts before act_members cascade away. The
                // memberless set is used to remove customerless invite rows too.
                $actIds = array_values(array_unique(array_merge(
                    ActMember::query()
                        ->where('customer_id', $customer->id)
                        ->pluck('act_id')
                        ->all(),
                    Act::query()
                        ->where('manager_customer_id', $customer->id)
                        ->pluck('id')
                        ->all(),
                )));
                $this->lockActRows($actIds);
                $memberlessActIds = $this->memberlessActIds($actIds, $customer);

                $inviteQuery = $this->linkedInvitesQuery($customer->id, $memberlessActIds);
                $inviteCount = (int) $inviteQuery->count();

                // A deleted manager must not tear the whole act (and its
                // remaining members) down via the manager FK: promote the first
                // remaining member before the customer row disappears.
                $this->reassignManagedActs($customer);

                // Used registration invites contain a live token, recipient
                // email and optional label. They have no foreign key to the
                // customer, so explicitly remove them in the same transaction.
                $this->linkedInvitesQuery($customer->id, $memberlessActIds)->delete();

                ModelAccessToken::where('customer_id', $customer->id)->delete();

                // Keep model events (including the file manifest) while
                // suppressing Scout's non-transactional unlink side effect.
                // Customer::delete() performs the same suppression for the
                // deletion event and schedules the post-commit removal job.
                if ($customer->user_id !== null) {
                    Customer::withoutSyncingToSearch(function () use ($customer): void {
                        $customer->user_id = null;
                        if ($customer->save() !== true) {
                            throw new RuntimeException('Model customer unlink was cancelled.');
                        }
                    });
                }

                if ($customer->delete() !== true) {
                    throw new RuntimeException('Model customer deletion was cancelled.');
                }

                if ($actIds !== []) {
                    Act::query()
                        ->whereIn('id', $actIds)
                        ->whereDoesntHave('members')
                        ->delete();
                }

                return [
                    'photo_count' => $photoCount,
                    'had_age_proof' => $hadAgeProof,
                    'affected_acts' => count($actIds),
                    'invite_count' => $inviteCount,
                ];
            }, 3);
            $committed = true;
        } finally {
            // Covers rollback and a deleting listener that cancels before the
            // deleted event can enqueue post-commit work.
            if (! $committed) {
                CustomerObserver::clearDeletionStateFor($customerId);
            }
        }

        // The observer has already queued both the file-cleanup and Scout
        // removal jobs from the committed deletion event.
        CustomerObserver::clearDeletionStateFor($customerId);

        // Audit trail without PII: who/what deleted which customer and why.
        Log::info('model.profile.deleted', array_merge([
            'customer_id' => $customerId,
            'user_id' => $actorId,
            'brand' => $brand,
            'reason' => $reason,
        ], $stats));

        return $stats;
    }

    /**
     * Build one grouped query so an invite matching both conditions is counted
     * and removed exactly once.
     *
     * @param  array<int, string>  $memberlessActIds
     * @return Builder<ModelRegistrationInvite>
     */
    private function linkedInvitesQuery(string $customerId, array $memberlessActIds): Builder
    {
        return ModelRegistrationInvite::query()
            ->where(function (Builder $query) use ($customerId, $memberlessActIds): void {
                $query->where('customer_id', $customerId);
                if ($memberlessActIds !== []) {
                    $query->orWhereIn('act_id', $memberlessActIds);
                }
            });
    }

    /**
     * @param  array<int, string>  $actIds
     */
    private function lockActRows(array $actIds): void
    {
        if ($actIds === []) {
            return;
        }

        Act::query()->whereIn('id', $actIds)->lockForUpdate()->get(['id']);
        ActMember::query()->whereIn('act_id', $actIds)->lockForUpdate()->get(['id']);
    }

    /**
     * Acts that will be removed after this customer leaves. An invite with an
     * `act_id` but no surviving customer link is unusable/stale and is cleaned
     * along with the memberless act.
     *
     * @param  array<int, string>  $actIds
     * @return array<int, string>
     */
    private function memberlessActIds(array $actIds, Customer $customer): array
    {
        if ($actIds === []) {
            return [];
        }

        return Act::query()
            ->whereIn('id', $actIds)
            ->whereDoesntHave('members', fn (Builder $query) => $query
                ->where('customer_id', '!=', $customer->id))
            ->pluck('id')
            ->all();
    }

    /**
     * Hand management of every act led by the customer to the first remaining
     * member (lowest `position`) before the customer is deleted.
     *
     * Without a successor the act keeps its temporary manager reference (the FK
     * is nullable + nullOnDelete) and is removed by the memberless-cleanup in
     * {@see erase()} — exactly one manager role remains per surviving act.
     */
    private function reassignManagedActs(Customer $customer): void
    {
        $managedActIds = Act::where('manager_customer_id', $customer->id)->pluck('id');

        foreach ($managedActIds as $actId) {
            $successor = ActMember::where('act_id', $actId)
                ->where('customer_id', '!=', $customer->id)
                ->orderBy('position')
                ->orderBy('id')
                ->first();

            if ($successor === null) {
                continue;
            }

            // Exactly one manager per act: demote all, then promote successor.
            ActMember::where('act_id', $actId)->update(['role' => 'member']);
            $successor->forceFill(['role' => 'manager'])->save();

            Act::where('id', $actId)->update(['manager_customer_id' => $successor->customer_id]);
        }
    }

    private function brandValue(Customer $customer): ?string
    {
        $brand = $customer->brand;

        if ($brand instanceof Brand) {
            return $brand->value;
        }

        return $brand !== null ? (string) $brand : null;
    }
}
