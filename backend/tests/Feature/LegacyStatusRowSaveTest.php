<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\PhotoJobStatus;
use App\Enums\ProjectStatus;
use App\Models\Order;
use App\Models\PhotoJob;
use App\Models\Project;
use App\Models\User;
use App\Support\ModelStatusGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P1-M14 (non-contract slice): a persisted legacy status must not turn an
 * unrelated save into a 500.
 *
 * The status guards only validate an attribute that is actually written, so a
 * row that was persisted with a pre-enum value stays writable through
 * bookkeeping paths (payment failures, notes, consent snapshots), while any
 * *new* value outside the allow-list is still rejected.
 */
class LegacyStatusRowSaveTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_ORDER_STATUS = 'processing';

    // -----------------------------------------------------------------
    // Order — plain string column, a real legacy row can be persisted.
    // -----------------------------------------------------------------

    public function test_order_with_legacy_status_saves_unrelated_attributes(): void
    {
        $id = $this->legacyOrder();

        $order = Order::query()->findOrFail($id);
        $order->update([
            'payment_failure_count' => 2,
            'last_payment_decline_code' => 'insufficient_funds',
            'withdrawal_waived' => true,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $id,
            'status' => self::LEGACY_ORDER_STATUS,
            'payment_failure_count' => 2,
            'withdrawal_waived' => true,
        ]);
    }

    public function test_order_with_legacy_status_can_still_transition_to_a_valid_status(): void
    {
        $id = $this->legacyOrder();

        $order = Order::query()->findOrFail($id);
        $order->update(['status' => 'paid']);

        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'paid']);
    }

    public function test_order_still_rejects_an_unknown_status_transition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $order = Order::query()->findOrFail($this->legacyOrder());
        $order->update(['status' => 'definitely-not-a-status']);
    }

    public function test_order_rejects_an_explicit_null_status_transition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $order = Order::query()->findOrFail($this->legacyOrder());
        $order->update(['status' => null]);
    }

    public function test_order_without_legacy_status_still_guards_the_allow_list(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        Order::create([
            'user_id' => $user->id,
            'brand' => Brand::B2B,
            'status' => 'nope',
            'total_amount' => 100,
        ]);
    }

    // -----------------------------------------------------------------
    // Project — DB enum, so the legacy state is reproduced on the model.
    // -----------------------------------------------------------------

    public function test_project_with_legacy_status_saves_unrelated_attributes(): void
    {
        $project = $this->projectWithLegacyStatus([
            'status' => 'wartung',
            'payment_status' => 'teilbezahlt_alt',
        ]);

        $project->update(['notes' => 'Kunde hat nachgefragt', 'position' => 4]);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'status' => ProjectStatus::ANFRAGE->value,
            'notes' => 'Kunde hat nachgefragt',
            'position' => 4,
        ]);
    }

    public function test_project_with_legacy_status_can_still_transition_to_a_valid_status(): void
    {
        $project = $this->projectWithLegacyStatus([
            'status' => 'wartung',
            'payment_status' => 'teilbezahlt_alt',
        ]);

        $project->update([
            'status' => ProjectStatus::BEZAHLT->value,
            'payment_status' => 'paid',
        ]);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'status' => ProjectStatus::BEZAHLT->value,
            'payment_status' => 'paid',
        ]);
    }

    #[DataProvider('projectStatusAttributes')]
    public function test_project_still_rejects_unknown_status_transitions(string $attribute): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->projectWithLegacyStatus([
            'status' => 'wartung',
            'payment_status' => 'teilbezahlt_alt',
        ])->update([$attribute => 'not-a-valid-value']);
    }

    /** @return array<string, array<int, string>> */
    public static function projectStatusAttributes(): array
    {
        return [
            'workflow status' => ['status'],
            'payment status' => ['payment_status'],
        ];
    }

    // -----------------------------------------------------------------
    // PhotoJob — DB enum (V027), legacy state reproduced on the model.
    // -----------------------------------------------------------------

    public function test_photo_job_with_legacy_status_saves_unrelated_attributes(): void
    {
        $photoJob = $this->photoJobWithLegacyStatus('shooting');

        $photoJob->update([
            'notes' => 'Rohdaten unterwegs',
            'total_count' => 12,
            'selected_count' => 3,
        ]);

        $this->assertDatabaseHas('photo_jobs', [
            'id' => $photoJob->id,
            'status' => PhotoJobStatus::IMPORTIERT->value,
            'total_count' => 12,
            'selected_count' => 3,
        ]);
    }

    public function test_photo_job_with_legacy_status_can_still_transition_to_a_valid_status(): void
    {
        $photoJob = $this->photoJobWithLegacyStatus('shooting');

        $photoJob->update(['status' => PhotoJobStatus::EXPORTIERT->value]);

        $this->assertDatabaseHas('photo_jobs', [
            'id' => $photoJob->id,
            'status' => PhotoJobStatus::EXPORTIERT->value,
        ]);
    }

    public function test_photo_job_still_rejects_an_unknown_status_transition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->photoJobWithLegacyStatus('shooting')->update(['status' => 'retouched']);
    }

    // -----------------------------------------------------------------
    // Guard contract
    // -----------------------------------------------------------------

    #[DataProvider('modelClassesWithAStatusGuard')]
    public function test_guard_reports_known_values_only(string $modelClass, string $knownStatus): void
    {
        $this->assertContains('status', (new $modelClass)->getFillable());
        $this->assertTrue(ModelStatusGuard::isKnown($knownStatus, $this->allowedStatuses($modelClass)));
        $this->assertFalse(ModelStatusGuard::isKnown('legacy_value', $this->allowedStatuses($modelClass)));
        $this->assertFalse(ModelStatusGuard::isKnown(null, $this->allowedStatuses($modelClass)));
    }

    /** @return array<string, array<int, string>> */
    public static function modelClassesWithAStatusGuard(): array
    {
        return [
            'order' => [Order::class, 'pending'],
            'project' => [Project::class, ProjectStatus::ANFRAGE->value],
            'photo job' => [PhotoJob::class, PhotoJobStatus::IMPORTIERT->value],
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<int, string>
     */
    private function allowedStatuses(string $modelClass): array
    {
        return match ($modelClass) {
            Order::class => Order::ALLOWED_STATUSES,
            Project::class => Project::allowedStatuses(),
            PhotoJob::class => PhotoJob::allowedStatuses(),
            default => [],
        };
    }

    /**
     * A persisted order row whose status predates the current allow-list.
     */
    private function legacyOrder(): string
    {
        $id = (string) Str::uuid();

        // Direct write: `orders.status` is a plain string column, so a row from
        // before the allow-list existed is representable.
        DB::table('orders')->insert([
            'id' => $id,
            'user_id' => null,
            'guest_id' => null,
            'brand' => Brand::B2B->value,
            'status' => self::LEGACY_ORDER_STATUS,
            'total_amount' => 4200,
            'is_quote_request' => false,
            'withdrawal_waived' => false,
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $legacyAttributes
     */
    private function projectWithLegacyStatus(array $legacyAttributes): Project
    {
        $project = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => User::factory()->create(['brand' => Brand::B2B]),
        ]);

        return $this->withLegacyAttributes($project, $legacyAttributes);
    }

    private function photoJobWithLegacyStatus(string $legacyStatus): PhotoJob
    {
        $photoJob = PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => User::factory()->create(['brand' => Brand::B2B]),
        ]);

        return $this->withLegacyAttributes($photoJob, ['status' => $legacyStatus]);
    }

    /**
     * Load a row whose status column holds a pre-enum value.
     *
     * `projects.status` / `photo_jobs.status` are database enums, so a legacy
     * value can only reach the model (production import, migration applied
     * without its data backfill, direct SQL). Syncing the raw attributes
     * reproduces exactly that model state: the value is the persisted one, the
     * attribute is not dirty, and an unrelated save must not rewrite or
     * reject it.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $legacyAttributes
     * @return TModel
     */
    private function withLegacyAttributes(Model $model, array $legacyAttributes): Model
    {
        $model->setRawAttributes(
            array_merge($model->getAttributes(), $legacyAttributes),
            true,
        );

        return $model;
    }
}
