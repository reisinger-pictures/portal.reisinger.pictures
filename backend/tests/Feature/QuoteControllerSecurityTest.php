<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\ManagementMiddleware;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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
        $user = User::factory()->create();
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
