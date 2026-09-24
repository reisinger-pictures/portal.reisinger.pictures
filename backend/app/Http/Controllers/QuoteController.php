<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendQuoteRequest;
use App\Mail\CustomMail;
use App\Models\Gallery;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\ManualInvoiceService;
use App\Services\QuoteLinkService;
use App\Support\BrandRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        if (! $user) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        $currentBrand = BrandRegistry::currentIdOrNull();
        if ($currentBrand === null) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        // Brand isolation (defense in depth): a quote mutation is only ever
        // considered for a complete, current-host order.  The relationship
        // check below is deliberately separate from this lookup: a same-brand
        // order is not automatically related to the acting photographer.
        $order = Order::with(['user', 'invoiceSnapshot'])
            ->where('brand', $currentBrand)
            ->findOrFail($id);

        // Only an open quote request may be answered. A paid/invoiced order must
        // never be silently cancelled by re-issuing a custom offer.
        if (! $order->is_quote_request || $order->status !== 'pending') {
            return response()->json(['error' => 'Nur offene Angebotsanfragen können beantwortet werden.'], 422);
        }

        // A quote link must always carry a positive amount (see checkout guard).
        if ((int) $request->custom_price < 1) {
            return response()->json(['error' => 'Der Angebotspreis muss größer als 0 sein.'], 422);
        }

        // Validate the persisted relationship and the complete item set before
        // changing the order.  The old implementation generated the link and
        // cancelled the order without proving that the actor could manage every
        // referenced gallery, allowing a same-brand but unrelated order to be
        // superseded.
        try {
            $photoIds = $this->authorizeSendQuoteTarget($order, $user);
            $link = $this->quoteLinkService->generateQuoteLink(
                $photoIds,
                (int) $request->custom_price,
                rightsText: $request->rights_text,
                issuer: $user,
            );
        } catch (AuthorizationException) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Die Fotoauswahl ist ungültig oder nicht lieferbar.'], 422);
        }

        if (! $this->claimPendingQuote($order)) {
            return response()->json([
                'error' => 'Das Angebot wurde zwischenzeitlich anderweitig aktualisiert.',
            ], 409);
        }

        $order->refresh();
        $subject = 'Individuelles Angebot';
        $body = '<p>'.nl2br(htmlspecialchars($request->message))."</p><br><p><a href=\"{$link}\">Hier geht es zum Angebot und Checkout</a></p>";

        Mail::to($order->user->email)->send(new CustomMail($subject, $body));

        return response()->json(['success' => true]);
    }

    /**
     * Atomically claim the pending quote before any mail is emitted.  The row
     * lock serializes contenders on databases that support it; the conditional
     * UPDATE remains the correctness boundary on SQLite and other engines.
     */
    private function claimPendingQuote(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $locked = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof Order
                || ! $locked->is_quote_request
                || $locked->status !== 'pending') {
                return false;
            }

            return Order::query()
                ->whereKey($locked->getKey())
                ->where('is_quote_request', true)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']) === 1;
        });
    }

    /**
     * Prove that the quote request is a valid current-host target and that the
     * acting management user is authorized to manage every gallery in its
     * persisted snapshot.  This is intentionally stricter than a brand check:
     * a photographer must not be able to cancel another photographer's quote
     * merely because both orders carry the same brand.
     *
     * @return array<int, string>
     */
    private function authorizeSendQuoteTarget(Order $order, User $user): array
    {
        $currentBrand = BrandRegistry::currentIdOrNull();
        if ($currentBrand === null
            || ! BrandRegistry::resourceMatchesCurrent($order->brand)
            || ! BrandRegistry::resourceMatchesCurrent($order->invoiceSnapshot?->brand)) {
            throw new AuthorizationException('Keine Berechtigung');
        }

        if (! $order->user) {
            throw new \InvalidArgumentException('Die Bestellung hat keinen gültigen Kunden.');
        }

        $customerDetails = $order->invoiceSnapshot?->customer_details;
        $items = is_array($customerDetails) && is_array($customerDetails['items'] ?? null)
            ? $customerDetails['items']
            : [];
        if (! is_array($items) || $items === [] || count($items) > QuoteLinkService::MAX_PHOTOS) {
            throw new \InvalidArgumentException('Die Fotoauswahl ist ungültig.');
        }

        $photoIds = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException('Die Fotoauswahl ist ungültig.');
            }

            $photoId = $item['photoId'] ?? null;
            if (! is_string($photoId) || trim($photoId) === '' || strlen(trim($photoId)) > 255) {
                throw new \InvalidArgumentException('Die Fotoauswahl ist ungültig.');
            }

            $photoIds[] = trim($photoId);
        }

        if (count(array_unique($photoIds)) !== count($photoIds)) {
            throw new \InvalidArgumentException('Die Fotoauswahl ist ungültig.');
        }

        $photos = Photo::with('gallery')
            ->whereIn('id', $photoIds)
            ->get()
            ->keyBy(fn (Photo $photo): string => (string) $photo->getKey());
        if ($photos->count() !== count($photoIds)) {
            throw new \InvalidArgumentException('Die Fotoauswahl ist ungültig.');
        }

        $authorization = app(AuthorizationService::class);
        foreach ($photoIds as $photoId) {
            $photo = $photos->get($photoId);
            $gallery = $photo?->gallery;
            if (! $photo instanceof Photo || ! $gallery instanceof Gallery) {
                throw new \InvalidArgumentException('Die Fotoauswahl ist ungültig.');
            }
            if (! BrandRegistry::galleryTreeMatchesCurrent($gallery)) {
                throw new AuthorizationException('Keine Berechtigung');
            }
            if ($gallery->type !== 'delivery') {
                throw new \InvalidArgumentException('Auswahl-Galerien können nicht angeboten werden.');
            }
            if (! $authorization->canManageGallery($user, (string) $gallery->getKey())) {
                throw new AuthorizationException('Keine Berechtigung');
            }
        }

        return $photoIds;
    }

    public function generateQuoteLink(Request $request)
    {
        $user = auth('api')->user();
        if (! $user) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        $request->validate([
            'photo_ids' => ['required', 'array', 'min:1', 'max:'.QuoteLinkService::MAX_PHOTOS],
            'photo_ids.*' => ['string', 'distinct'],
            'custom_price' => ['required', 'integer', 'min:1'],
            'rights_text' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $link = $this->quoteLinkService->generateQuoteLink(
                $request->input('photo_ids', []),
                (int) $request->input('custom_price'),
                rightsText: $request->input('rights_text'),
                issuer: $user,
            );
        } catch (AuthorizationException) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Die Fotoauswahl ist ungültig oder nicht lieferbar.'], 422);
        }

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
