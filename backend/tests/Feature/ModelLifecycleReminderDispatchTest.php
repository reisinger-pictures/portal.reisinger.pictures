<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Customer;
use App\Models\ModelAccessToken;
use App\Models\ModelProfile;
use App\Services\ModelQuestionnaire;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * D-17, second SMTP window: the lifecycle reminder must be enqueued at most
 * once **across a crash**, not once per run.
 *
 * The old implementation queued the mail and only then wrote
 * `last_reminder_stage`, with nothing coupling the two. A process that died in
 * between left a durable job in the queue and an open stage, so the next
 * scheduled run sent the same reminder again. Neither the profile test suite
 * nor the scheduler's own output can see that: the duplicate only exists
 * across two runs of two processes, and the first run reported success.
 *
 * The two crash points are simulated here, not reasoned about:
 *
 * 1. **After the enqueue, before the commit** — the jobs row exists, the
 *    transaction is still open. Nothing may survive it, and the retry may send
 *    exactly one reminder (never two).
 * 2. **The enqueue itself fails** — nothing was sent, so the claim must not
 *    survive either, or the reminder is lost forever. The already-issued
 *    profile access token is part of the same transaction and must stay usable.
 *
 * The mail is enqueued for real on the real database queue. `Mail::fake()`
 * would record the call without writing a `jobs` row, and both properties above
 * would then be asserted against a table that stayed empty — green without
 * checking the thing the test is named after.
 */
class ModelLifecycleReminderDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BrandRegistry::set(Brand::B2B);
        Storage::fake('local');
        config(['queue.default' => 'database']);
    }

    private function profileDueForT12(): ModelProfile
    {
        $customer = Customer::factory()->create([
            'brand' => 'rp',
            'is_model' => true,
            'email' => 'model@example.com',
        ]);

        return ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => ModelQuestionnaire::CURRENT,
            'answers' => [],
            'gender' => null,
            'age_proof_required' => false,
            'submitted_at' => now()->subMonths(12)->subDay(),
            'last_confirmed_at' => now()->subMonths(12)->subDay(),
        ]);
    }

    /**
     * Run the command and return the exception it threw, or null when it
     * reported success. `$this->fail()` is avoided on purpose: it throws an
     * `AssertionFailedError`, which is a `RuntimeException` and would be caught
     * by the same `catch` the crash is asserted through.
     */
    private function runLifecycleCommand(): ?RuntimeException
    {
        try {
            $this->artisan('app:process-model-lifecycle')->assertSuccessful();
        } catch (RuntimeException $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * Replaces the mailer with one that performs the **real** enqueue and then
     * dies — the exact position of the old code's duplicate window: the job is
     * durably written, nothing has marked the stage.
     */
    private function crashAfterTheEnqueue(bool $crash): void
    {
        $real = Mail::getFacadeRoot();
        $pending = Mockery::mock(PendingMail::class);
        $manager = Mockery::mock(MailManager::class);

        $manager->shouldReceive('to')->andReturnUsing(function ($recipient) use ($real, $pending, $crash) {
            $pending->shouldReceive('queue')->andReturnUsing(function ($mailable) use ($real, $recipient, $crash) {
                $real->to($recipient)->queue($mailable);

                if ($crash) {
                    throw new RuntimeException('process died after the enqueue');
                }

                return null;
            });

            return $pending;
        });

        Mail::swap($manager);
    }

    /**
     * A failing enqueue (no job written at all) — the direction in which a claim
     * written before the queue call would silently swallow the reminder.
     */
    private function failTheEnqueue(): void
    {
        $pending = Mockery::mock(PendingMail::class);
        $manager = Mockery::mock(MailManager::class);

        $manager->shouldReceive('to')->andReturn($pending);
        $pending->shouldReceive('queue')->andThrow(new RuntimeException('queue unavailable'));

        Mail::swap($manager);
    }

    public function test_a_crash_between_the_enqueue_and_the_commit_sends_the_reminder_exactly_once(): void
    {
        $profile = $this->profileDueForT12();
        $realMailer = Mail::getFacadeRoot();

        $this->crashAfterTheEnqueue(crash: true);
        $failure = $this->runLifecycleCommand();

        $this->assertNotNull($failure, 'A run that dies mid-flight must not report success.');
        $this->assertSame('process died after the enqueue', $failure->getMessage());

        // Neither half of the pair may survive the crash: the job is gone AND
        // the stage is still open, so the next run can claim it.
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNull($profile->fresh()->last_reminder_stage);
        $this->assertNull($profile->fresh()->last_reminder_at);

        // The next scheduled run — the one that used to send a second copy.
        Mail::swap($realMailer);
        $this->artisan('app:process-model-lifecycle')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('t12', $profile->fresh()->last_reminder_stage);
        $this->assertNotNull($profile->fresh()->last_reminder_at);

        // And the day after that is still silent.
        $this->artisan('app:process-model-lifecycle')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_a_failed_enqueue_keeps_the_stage_open_and_the_issued_token_usable(): void
    {
        $profile = $this->profileDueForT12();
        $realMailer = Mail::getFacadeRoot();

        $existingToken = ModelAccessToken::create([
            'customer_id' => $profile->customer_id,
            'token' => 'existing-profile-token',
            'expires_at' => now()->addHours(2),
        ]);

        $this->failTheEnqueue();
        $failure = $this->runLifecycleCommand();

        $this->assertNotNull($failure);
        $this->assertSame('queue unavailable', $failure->getMessage());

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNull($profile->fresh()->last_reminder_stage);

        // `issueFor()` revokes the previous token before it creates the new one.
        // Both writes belong to the same transaction as the enqueue, so a
        // dispatch that never happened must not cost the customer a magic link.
        $this->assertNull($existingToken->fresh()->revoked_at);
        $this->assertTrue($existingToken->fresh()->isActive());
        $this->assertSame(1, ModelAccessToken::where('customer_id', $profile->customer_id)->count());

        Mail::swap($realMailer);
        $this->artisan('app:process-model-lifecycle')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('t12', $profile->fresh()->last_reminder_stage);
        $this->assertNotNull($existingToken->fresh()->revoked_at);
    }

    public function test_a_non_production_run_without_the_transactional_queue_refuses_to_claim(): void
    {
        $profile = $this->profileDueForT12();

        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'staging');
        config(['queue.default' => 'sync']);

        try {
            $failure = $this->runLifecycleCommand();
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }

        $this->assertNotNull($failure);
        $this->assertStringContainsString('transactional database queue', $failure->getMessage());

        // Failing closed means the stage stays open, not that the reminder is
        // marked as sent without a job behind it.
        $this->assertNull($profile->fresh()->last_reminder_stage);
        $this->assertSame(0, DB::table('jobs')->count());
    }
}
