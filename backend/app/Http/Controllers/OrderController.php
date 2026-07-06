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
        return response()->json(\App\Http\Resources\OrderResource::collection(
            Order::with(['user', 'invoiceSnapshot'])->orderBy('created_at', 'desc')->get()
        )->resolve());
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string|in:pending,invoice_created,pending_payment,paid,overdue,cancelled,disputed,refunded,delivery_note,archived_in_collective']);
        Order::findOrFail($id)->update(['status' => $request->status]);
        return response()->json(['success' => true]);
    }
}
