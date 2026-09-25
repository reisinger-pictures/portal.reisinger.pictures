<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\ManagementMiddleware;
use App\Mail\CustomMail;
use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\QuoteLinkService;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Mockery;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Regression test for P0-B6: `QuoteController::sendQuote()` must scope the order by
 * brand, only cancel open quote requests, and require management rights on the
 * referenced gallery.
 */
class QuoteControllerSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['brand' => 'rp']);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    private function orderForPhoto(Photo $photo, array $overrides = []): Order
    {
        $customer = User::factory()->create();

        $order = Order::create(array_merge([
            'user_id' => $customer->id,
            'status' => 'pending',
            'is_quote_request' => true,
            'total_amount' => 0,
            'brand' => 'rp',
        ], $overrides));

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'A-'.strtoupper(Str::random(8)),
            'brand' => 'rp',
            'customer_details' => ['items' => [['photoId' => $photo->id, 'filename' => 'Testbild']]],
            'total_net' => 0,
            'total_gross' => 0,
            'tax_rate' => null,
        ]);

        return $order->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'custom_price' => 50000,
            'message' => 'Hier ist mein Angebot.',
            'rights_text' => null,
        ], $overrides);
    }

    private function useFailingQuoteTransport(): void
    {
        // Mail::fake() also replaces the container binding; reach the real manager
        // so the queue can execute the injected transport.
        $mailRoot = Mail::getFacadeRoot();
        $mailManager = $mailRoot instanceof MailFake
            ? $mailRoot->manager
            : app('mail.manager');
        $mailManager->extend('quote_fault', static fn (): AbstractTransport => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new \RuntimeException('quote SMTP transport unavailable');
            }

            public function __toString(): string
            {
                return 'quote_fault';
            }
        });
        Mail::swap($mailManager);
        Config::set([
            'mail.default' => 'quote_fault',
            'mail.mailers.quote_fault' => ['transport' => 'quote_fault'],
            'queue.default' => 'sync',
        ]);
    }

    public function test_admin_can_answer_pending_quote_request(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $order = $this->orderForPhoto($photo);

        $token = auth('api')->login($this->userWithRole(UserRole::ADMIN->value));

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_paid_order_cannot_be_cancelled(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $order = $this->orderForPhoto($photo, ['status' => 'paid']);

        $token = auth('api')->login($this->userWithRole(UserRole::ADMIN->value));

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
            ->assertStatus(422);

        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_order_of_another_brand_is_not_reachable(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $order = $this->orderForPhoto($photo, ['brand' => 'other-brand']);

        $token = auth('api')->login($this->userWithRole(UserRole::ADMIN->value));

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
            ->assertStatus(404);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_photographer_cannot_answer_quote_for_foreign_gallery(): void
    {
        // Disable the global management gate so the controller-level ownership guard is exercised.
        $this->withoutMiddleware(ManagementMiddleware::class);

        $gallery = Gallery::factory()->create(['is_public' => false, 'restricted_photographers' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $order = $this->orderForPhoto($photo);

        $token = auth('api')->login($this->userWithRole(UserRole::PHOTOGRAPHER->value));

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_photographer_cannot_cancel_unrelated_same_brand_pending_quote(): void
    {
        // Exercise the controller relationship guard independently of the
        // coarse ManagementMiddleware route gate.
        $this->withoutMiddleware(ManagementMiddleware::class);

        $actor = $this->userWithRole(UserRole::PHOTOGRAPHER->value);
        $ownGallery = Gallery::factory()->create([
            'is_public' => false,
            'restricted_photographers' => true,
        ]);
        $actor->photographerGalleries()->attach($ownGallery);

        $otherGallery = Gallery::factory()->create([
            'is_public' => false,
            'restricted_photographers' => true,
        ]);
        $otherPhoto = Photo::factory()->create(['gallery_id' => $otherGallery->id]);
        $order = $this->orderForPhoto($otherPhoto);

        $token = auth('api')->login($actor);
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
            ->assertForbidden();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, DownloadLog::count());
    }

    public function test_missing_or_invalid_snapshot_item_does_not_cancel_quote(): void
    {
        $this->withoutMiddleware(ManagementMiddleware::class);
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $order = $this->orderForPhoto($photo);
        $order->invoiceSnapshot->update([
            'customer_details' => ['items' => [['photoId' => (string) Str::uuid()]]],
        ]);

        $token = auth('api')->login($this->userWithRole(UserRole::ADMIN->value));
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
            ->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_selection_snapshot_item_does_not_cancel_quote(): void
    {
        $this->withoutMiddleware(ManagementMiddleware::class);
        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            'is_public' => false,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $order = $this->orderForPhoto($photo);

        $token = auth('api')->login($this->userWithRole(UserRole::ADMIN->value));
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
            ->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_status_change_during_quote_generation_blocks_claim_and_mail(): void
    {
        $this->withoutMiddleware(ManagementMiddleware::class);
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $order = $this->orderForPhoto($photo);

        $this->mock(QuoteLinkService::class, function ($mock) use ($order): void {
            $mock->shouldReceive('generateQuoteLink')
                ->once()
                ->andReturnUsing(function () use ($order): string {
                    // Simulate an administrator/webhook transition while the
                    // signed offer is being assembled.
                    $order->update(['status' => 'paid']);

                    return 'https://example.test/quote-token';
                });
        });

        $token = auth('api')->login($this->userWithRole(UserRole::ADMIN->value));
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
            ->assertStatus(409);

        $this->assertSame('paid', $order->fresh()->status);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_sequential_duplicate_send_quote_sends_only_one_offer(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $order = $this->orderForPhoto($photo);
        $token = auth('api')->login($this->userWithRole(UserRole::ADMIN->value));

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
            ->assertOk();
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
            ->assertStatus(422);

        Mail::assertQueued(CustomMail::class, 1);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_transport_failure_rolls_back_quote_and_retry_queues_one_intent(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $order = $this->orderForPhoto($photo);
        $token = auth('api')->login($this->userWithRole(UserRole::ADMIN->value));

        $this->useFailingQuoteTransport();
        try {
            $this->withHeaders(['Authorization' => "Bearer $token"])
                ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
                ->assertStatus(503);
        } finally {
            Mail::fake();
        }

        $this->assertSame('pending', $order->fresh()->status);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
            ->assertOk();

        Mail::assertQueued(CustomMail::class, 1);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_enqueue_failure_rolls_back_quote_and_retry_queues_one_intent(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $order = $this->orderForPhoto($photo);
        $token = auth('api')->login($this->userWithRole(UserRole::ADMIN->value));

        // Fail after the conditional claim, at the queue boundary.
        $pendingMail = Mockery::mock(PendingMail::class);
        $pendingMail->shouldReceive('queue')
            ->once()
            ->andThrow(new \RuntimeException('quote mail enqueue unavailable'));
        $mailFactory = Mockery::mock(Factory::class);
        $mailFactory->shouldReceive('to')
            ->once()
            ->with($order->user->email)
            ->andReturn($pendingMail);
        $mailRoot = Mail::getFacadeRoot();
        $originalMailManager = $mailRoot instanceof MailFake
            ? $mailRoot->manager
            : app('mail.manager');
        Mail::swap($mailFactory);

        try {
            $this->withHeaders(['Authorization' => "Bearer $token"])
                ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
                ->assertStatus(503);
        } finally {
            Mail::swap($originalMailManager);
            Mail::fake();
        }

        $this->assertSame('pending', $order->fresh()->status);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload())
            ->assertOk();

        Mail::assertQueued(CustomMail::class, 1);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_non_positive_custom_price_is_rejected(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $order = $this->orderForPhoto($photo);

        $token = auth('api')->login($this->userWithRole(UserRole::ADMIN->value));

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orders/{$order->id}/send-quote", $this->payload(['custom_price' => 0]))
            ->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->status);
    }
}
