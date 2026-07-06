<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Services\QuoteLinkService;
use App\Http\Requests\SendQuoteRequest;

class QuoteController extends Controller
{
    public function __construct(
        private QuoteLinkService $quoteLinkService,
        private \App\Services\ManualInvoiceService $manualInvoiceService
    ) {}

    public function sendQuote(SendQuoteRequest $request, $id)
    {
        $user = auth('api')->user();

        $validated = $request->validated();

        $order = Order::with(['user', 'invoiceSnapshot'])->findOrFail($id);
        $order->update(['status' => 'cancelled']);

        $items = $order->invoiceSnapshot->customer_details['items'] ?? [];
        $photoIds = array_column($items, 'photoId');

        $link = $this->quoteLinkService->generateQuoteLink($photoIds, $request->custom_price);

        $subject = "Individuelles Angebot";
        $body = "<p>" . nl2br(htmlspecialchars($request->message)) . "</p><br><p><a href=\"{$link}\">Hier geht es zum Angebot und Checkout</a></p>";

        \Illuminate\Support\Facades\Mail::to($order->user->email)->send(new \App\Mail\CustomMail($subject, $body));

        return response()->json(['success' => true]);
    }

    public function generateQuoteLink(Request $request)
    {
        $user = auth('api')->user();
        if (!$user->is_admin && !$user->is_photographer) return response()->json(['error' => 'Keine Berechtigung'], 403);
        $request->validate(['photo_ids' => 'required|array', 'custom_price' => 'required|integer']);

        $link = $this->quoteLinkService->generateQuoteLink($request->photo_ids, $request->custom_price);

        return response()->json(['success' => true, 'link' => $link]);
    }

    public function decodeQuoteLink(Request $request)
    {
        $token = $request->query('token');
        if (!$token) return response()->json(['error' => 'Ungültiges Token-Format.'], 400);

        $data = $this->quoteLinkService->decode($token);
        if (!$data) return response()->json(['error' => 'Angebot abgelaufen oder ungültig.'], 410);

        return response()->json($data);
    }

    public function extractOffer(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->is_super_admin) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        $request->validate(['pdf' => 'required|file|mimes:pdf|max:10240']);
        $content = file_get_contents($request->file('pdf')->getPathname());

        if (!preg_match('/OFFER_JWT:([A-Za-z0-9_\.\-]+)/', $content)) {
            return response()->json(['error' => 'Kein eingebettetes Angebot in diesem PDF gefunden.'], 404);
        }

        $data = $this->manualInvoiceService->extractOfferFromPdf($content);

        if (!$data) {
            return response()->json(['error' => 'Angebot nicht auslesbar oder abgelaufen.'], 400);
        }

        return response()->json($data);
    }
}
