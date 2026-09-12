<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendQuoteRequest;
use App\Mail\CustomMail;
use App\Models\Gallery;
use App\Models\Order;
use App\Models\Photo;
use App\Services\AuthorizationService;
use App\Services\ManualInvoiceService;
use App\Services\QuoteLinkService;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;

class QuoteController extends Controller
{
    public function __construct(
        private QuoteLinkService $quoteLinkService,
        private ManualInvoiceService $manualInvoiceService
    ) {}

    public function sendQuote(SendQuoteRequest $request, $id)
    {
        $user = auth('api')->user();
        $svc = app(AuthorizationService::class);

        // Brand isolation (defense in depth): never touch orders of another brand.
        $order = Order::with(['user', 'invoiceSnapshot'])
            ->where(function ($query) {
                $query->where('brand', BrandRegistry::currentId())->orWhereNull('brand');
            })
            ->findOrFail($id);

        // Only an open quote request may be answered. A paid/invoiced order must
        // never be silently cancelled by re-issuing a custom offer.
        if (! $order->is_quote_request || $order->status !== 'pending') {
            return response()->json(['error' => 'Nur offene Angebotsanfragen können beantwortet werden.'], 422);
        }

        // Ownership: non-admins may only answer quote requests for galleries they manage.
        if (! $svc->isAdmin($user)) {
            $galleryIds = $this->orderGalleryIds($order);

            if ($galleryIds === []) {
                return response()->json(['error' => 'Keine Berechtigung'], 403);
            }

            foreach ($galleryIds as $galleryId) {
                $gallery = Gallery::find($galleryId);
                if ($gallery === null || Gate::denies('manage', $gallery)) {
                    return response()->json(['error' => 'Keine Berechtigung'], 403);
                }
            }
        }

        // A quote link must always carry a positive amount (see checkout guard).
        if ((int) $request->custom_price < 1) {
            return response()->json(['error' => 'Der Angebotspreis muss größer als 0 sein.'], 422);
        }

        $order->update(['status' => 'cancelled']);

        $items = $order->invoiceSnapshot?->customer_details['items'] ?? [];
        $photoIds = array_column($items, 'photoId');

        $link = $this->quoteLinkService->generateQuoteLink($photoIds, $request->custom_price, rightsText: $request->rights_text);

        $subject = 'Individuelles Angebot';
        $body = '<p>'.nl2br(htmlspecialchars($request->message))."</p><br><p><a href=\"{$link}\">Hier geht es zum Angebot und Checkout</a></p>";

        Mail::to($order->user->email)->send(new CustomMail($subject, $body));

        return response()->json(['success' => true]);
    }

    /**
     * Distinct gallery IDs referenced by an order's invoice snapshot.
     *
     * @return array<string>
     */
    private function orderGalleryIds(Order $order): array
    {
        $items = $order->invoiceSnapshot?->customer_details['items'] ?? [];
        $photoIds = array_filter(array_column($items, 'photoId'));

        if ($photoIds === []) {
            return [];
        }

        return Photo::whereIn('id', $photoIds)
            ->pluck('gallery_id')
            ->unique()
            ->values()
            ->all();
    }

    public function generateQuoteLink(Request $request)
    {
        $svc = app(AuthorizationService::class);
        $user = auth('api')->user();
        if (! $svc->isAdmin($user) && ! $svc->isPhotographer($user)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }
        $request->validate(['photo_ids' => 'required|array', 'custom_price' => 'required|integer|min:1', 'rights_text' => 'nullable|string|max:2000']);

        $link = $this->quoteLinkService->generateQuoteLink($request->photo_ids, $request->custom_price, rightsText: $request->rights_text);

        return response()->json(['success' => true, 'link' => $link]);
    }

    public function decodeQuoteLink(Request $request)
    {
        $token = $request->query('token');
        if (! $token) {
            return response()->json(['error' => 'Ungültiges Token-Format.'], 400);
        }

        $data = $this->quoteLinkService->decode($token);
        if (! $data) {
            return response()->json(['error' => 'Angebot abgelaufen oder ungültig.'], 410);
        }

        return response()->json($data);
    }

    public function extractOffer(Request $request)
    {
        $svc = app(AuthorizationService::class);
        $user = auth('api')->user();
        if (! $user || ! $svc->isSuperAdmin($user)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        $request->validate(['pdf' => 'required|file|mimes:pdf|max:10240']);
        $content = file_get_contents($request->file('pdf')->getPathname());

        if (! preg_match('/OFFER_JWT:([A-Za-z0-9_\.\-]+)/', $content)) {
            return response()->json(['error' => 'Kein eingebettetes Angebot in diesem PDF gefunden.'], 404);
        }

        $data = $this->manualInvoiceService->extractOfferFromPdf($content);

        if (! $data) {
            return response()->json(['error' => 'Angebot nicht auslesbar oder abgelaufen.'], 400);
        }

        return response()->json($data);
    }
}
