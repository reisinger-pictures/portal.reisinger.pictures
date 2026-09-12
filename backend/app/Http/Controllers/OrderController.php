<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Http\Resources\OrderResource;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::where('user_id', auth()->id())
            ->with('invoiceSnapshot')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(OrderResource::collection($orders)->resolve());
    }

    public function indexAdmin()
    {
        $user = auth('api')->user();

        $query = Order::with(['user', 'invoiceSnapshot'])->orderBy('created_at', 'desc');

        // Brand scoping: brand-bound admins only see orders of their own brand.
        // Cross-brand users (brand = null, e.g. super_admin) see all brands.
        if ($user->brand !== null) {
            $query->where('brand', $user->brand);
        }

        return response()->json(\App\Http\Resources\OrderResource::collection($query->get())->resolve());
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string|in:pending,invoice_created,pending_payment,paid,overdue,cancelled,disputed,refunded,delivery_note,archived_in_collective']);

        $user = auth('api')->user();

        $query = Order::query();

        // Brand scoping: brand-bound admins must not mutate orders of another
        // brand (404 instead of leaking existence). Cross-brand users may act
        // on any brand.
        if ($user->brand !== null) {
            $query->where('brand', $user->brand);
        }

        $query->findOrFail($id)->update(['status' => $request->status]);

        return response()->json(['success' => true]);
    }
}
