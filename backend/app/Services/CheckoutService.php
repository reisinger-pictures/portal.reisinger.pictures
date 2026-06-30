<?php
namespace App\Services;
use App\Models\Order;
use App\Models\InvoiceSnapshot;
use App\Models\Photo;
use App\Models\LicenseUseCase;
use App\Models\LicenseModifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceMail;

class CheckoutService {
    protected $pricingService;

    public function __construct(PricingService $pricingService) {
        $this->pricingService = $pricingService;
    }

    public function processCheckout($request, $user, $paymentMethod) {
        try {
            return DB::transaction(function () use ($request, $user, $paymentMethod) {
            $totalNetCents = 0;
            $lineItems = [];
            $isQuoteRequest = false;

            foreach ($request->items as $item) {
                $photo = Photo::with('gallery')->findOrFail($item['photoId']);
                if (!$photo->gallery->is_public && !$user->canAccessGallery($photo->gallery_id)) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => 'Zugriff verweigert'], 403));
                }
                
                $isItemQuote = isset($item['isQuote']) && $item['isQuote'];
                
                if (!$isItemQuote && !empty($item['useCaseId'])) {
                    $useCase = \App\Models\LicenseUseCase::find($item['useCaseId']);
                    if ($useCase) {
                        // Defense-in-depth (spec §3.6): reject cross-brand use case ids.
                        $currentBrand = \App\Support\BrandRegistry::current();
                        if ($currentBrand !== null && $useCase->brand !== null && $useCase->brand !== $currentBrand) {
                            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => 'Ungültige Lizenz-Auswahl.'], 422));
                        }
                        $isCommercial = $useCase->is_commercial || preg_match('/werbung|kampagne|kommerziell/i', $useCase->name . ' ' . $useCase->description);
                        if ($isCommercial && ($photo->effective_is_editorial_only || $photo->is_editorial_only)) {
                            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => "Das Bild '{$photo->filename}' ist nur für redaktionelle Nutzung freigegeben."], 403));
                        }
                    }
                }

                $itemCents = 0;
                $tier = $item['tier'] ?? 'web';
                $useCaseName = $item['useCaseName'] ?? 'Standard Lizenz';
                $modifierNames = $item['modifierNames'] ?? [];

                if ($isItemQuote) {
                    $isQuoteRequest = true;
                    $itemCents = 0;
                } else {
                    $pricingResult = $this->pricingService->calculateItemPriceCents(
                        $item['useCaseId'] ?? '', 
                        $item['modifierIds'] ?? [], 
                        $user->flatrate_level ?? 'none'
                    );
                    $itemCents = $pricingResult['total_cents'];
                    $tier = $pricingResult['tier'];
                    $useCaseName = $pricingResult['use_case_name'];
                    $modifierNames = $pricingResult['modifier_names'];
                }
                
                $totalNetCents += $itemCents;

                $lineItems[] = [
                    'photoId' => $photo->id,
                    'filename' => $photo->title ?: 'Bild ' . substr($photo->id, 0, 8),
                    'tier' => $tier,
                    'useCaseId' => $item['useCaseId'] ?? null,
                    'useCaseName' => $useCaseName,
                    'modifierNames' => $modifierNames,
                    'price' => $itemCents,
                    'isQuote' => $isItemQuote,
                    'notes' => $item['notes'] ?? null,
                ];
            }

            if ($totalNetCents <= 0 && !$isQuoteRequest) return response()->json(['error' => 'Warenkorb hat keinen Wert.'], 400);

            $user->update($request->only(['billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city']));

            $tenant = $user->tenants()->first();
            $isLieferschein = $tenant && $tenant->invoice_frequency !== 'immediate';
            $orderStatus = $isQuoteRequest ? 'pending' : ($isLieferschein ? 'delivery_note' : ($paymentMethod === 'invoice' ? 'invoice_created' : 'pending_payment'));

            $order = Order::create(['user_id' => $user->id, 'status' => $orderStatus, 'brand' => config('app.brand'), 'total_amount' => $totalNetCents, 'is_quote_request' => $isQuoteRequest]);

            $prefix = $isLieferschein ? 'L-' : 'P-';
            $invoiceNumber = \App\Models\InvoiceSequence::getNextInvoiceNumber($prefix);

            $snapshot = InvoiceSnapshot::create([
                'order_id' => $order->id,
                'invoice_number' => $invoiceNumber,
                'brand' => $order->brand,
                'customer_details' => [
                    'name' => $request->billing_name, 'company' => $request->billing_company, 'street' => $request->billing_street,
                    'zip' => $request->billing_zip, 'city' => $request->billing_city, 'email' => $user->email,
                    'country' => 'Österreich', 'items' => $lineItems, 'quote_message' => $request->quote_message ?? null, 'terms' => [] 
                ],
                'total_net' => $totalNetCents, 'total_gross' => $totalNetCents, 'tax_rate' => 0.00
            ]);

            if ($isQuoteRequest) {
                return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
            }

            if ($isLieferschein || $paymentMethod === 'invoice') {
                if (!$isQuoteRequest) Mail::to($user->email)->queue(new InvoiceMail($order, $snapshot));
                return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
            }

            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => (int) round($totalNetCents), 'currency' => 'eur', 'metadata' => ['order_id' => $order->id], 'receipt_email' => $user->email,
            ], ['idempotency_key' => 'pi_' . $order->id]);

            $order->update(['ip_address' => $request->ip(), 'stripe_payment_intent_id' => $paymentIntent->id]);
            return response()->json(['success' => true, 'requires_action' => true, 'client_secret' => $paymentIntent->client_secret, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
        });
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            return $e->getResponse();
        }
    }
}
