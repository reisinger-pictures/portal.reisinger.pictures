<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Order;
use App\Models\InvoiceSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

class InvoiceDownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_download_invoice_for_own_order()
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'paid',
            'total_amount' => 5000,
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'RE-TEST-001',
            'customer_details' => ['name' => 'Test', 'items' => []],
            'total_net' => 5000,
            'total_gross' => 5000,
            'tax_rate' => 0,
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get('/api/orders/' . $order->id . '/invoice');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_user_cannot_download_invoice_for_others_order()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = auth('api')->login($user);

        $order = Order::create([
            'user_id' => $otherUser->id,
            'status' => 'paid',
            'total_amount' => 5000,
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get('/api/orders/' . $order->id . '/invoice');

        $response->assertStatus(404);
    }

    public function test_pending_quote_request_blocks_invoice_download()
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 5000,
            'is_quote_request' => true,
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get('/api/orders/' . $order->id . '/invoice');

        $response->assertStatus(403);
    }
}
