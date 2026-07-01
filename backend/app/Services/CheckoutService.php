<?php
namespace App\Services;
use App\Contracts\PricingStrategy;
use App\Models\Order;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseUseCase;
use App\Models\Photo;
use App\Services\CouponService;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceMail;

class CheckoutService {
    protected $strategy;

    public function __construct(PricingStrategy $strategy) {
        $this->strategy = $strategy;
    }

    public function processCheckout($request, $user, $paymentMethod) {
        try {
            return DB::transaction(function () use ($request, $user, $paymentMethod) {
            $lineItems = [];
            $isQuoteRequest = false;

            // Build strategy items array
            $strategyItems = [];
            foreach ($request->items as $item) {
                $photo = Photo::with('gallery')->findOrFail($item['photoId']);
                if (!$photo->gallery->is_public && !$user->canAccessGallery($photo->gallery_id)) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => 'Zugriff verweigert'], 403));
                }

                $isItemQuote = isset($item['isQuote']) && $item['isQuote'];

                if (!$isItemQuote && !empty($item['useCaseId'])) {
                    $useCase = LicenseUseCase::find($item['useCaseId']);
                    if ($useCase) {
                        // Defense-in-depth (spec §3.6): reject cross-brand use case ids.
                        $currentBrand = BrandRegistry::current();
                        if ($currentBrand !== null && $useCase->brand !== null && $useCase->brand !== $currentBrand) {
                            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => 'Ungültige Lizenz-Auswahl.'], 422));
                        }
                        $isCommercial = $useCase->is_commercial || preg_match('/werbung|kampagne|kommerziell/i', $useCase->name . ' ' . $useCase->description);
                        if ($isCommercial && ($photo->effective_is_editorial_only || $photo->is_editorial_only)) {
                            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => "Das Bild '{$photo->filename}' ist nur für redaktionelle Nutzung freigegeben."], 403));
                        }
                    }
                }

                $strategyItems[] = [
                    'id' => $photo->id,
                    'license_use_case_id' => $item['useCaseId'] ?? '',
                    'license_modifier_ids' => $item['modifierIds'] ?? [],
                    'is_quote' => $isItemQuote,
                ];

                if ($isItemQuote) {
                    $isQuoteRequest = true;
                }
            }

            // Extract optional coupon code from request
            $couponCode = $request->input('coupon_code');

            // Pre-validate coupon code before checkout (re-validation to prevent race conditions)
            if ($couponCode !== null) {
                $couponService = app(CouponService::class);
                $brand = BrandRegistry::current();
                if ($brand !== null) {
                    $galleryId = null;
                    $metaGalleryId = null;
                    foreach ($request->items as $item) {
                        if (empty($item['isQuote'])) {
                            $photo = Photo::with('gallery')->find($item['photoId'] ?? 0);
                            if ($photo && $photo->gallery) {
                                $galleryId = $photo->gallery_id;
                                $metaGalleryId = $photo->gallery->gallery_group_id ?? null;
                                break;
                            }
                        }
                    }
                    [$validCoupon, $couponError] = $couponService->findValidCoupon(
                        $couponCode,
                        $brand,
                        $galleryId,
                        $metaGalleryId,
                        $user->id,
                    );
                    if ($validCoupon === null) {
                        return response()->json(['error' => 'Der Rabattcode ist nicht mehr gültig.'], 422);
                    }
                }
            }

            // Single pricing call via strategy (with optional coupon)
            $pricingResult = $this->strategy->calculateCart($strategyItems, $user, $couponCode);
            $totalNetCents = $pricingResult['totalCents'];
            $couponDiscountCents = (int) ($pricingResult['discountCents'] ?? 0);
            $appliedCouponId = $pricingResult['couponId'] ?? null;

            // Build line items from pricing result
            foreach ($pricingResult['items'] as $idx => $pricedItem) {
                $item = $request->items[$idx] ?? [];
                $photoId = $pricedItem['itemId'];
                $photo = Photo::find($photoId);

                $lineItems[] = [
                    'photoId' => $photoId,
                    'filename' => $photo ? ($photo->title ?: 'Bild ' . substr($photo->id, 0, 8)) : 'Unbekannt',
                    'tier' => $pricedItem['tier'] ?? ($item['tier'] ?? 'web'),
                    'useCaseId' => $item['useCaseId'] ?? null,
                    'useCaseName' => $pricedItem['useCaseName'] ?? ($item['useCaseName'] ?? 'Standard Lizenz'),
                    'modifierNames' => $pricedItem['modifierNames'] ?? ($item['modifierNames'] ?? []),
                    'price' => $pricedItem['priceCents'],
                    'isQuote' => $item['isQuote'] ?? false,
                    'notes' => $item['notes'] ?? null,
                ];
            }

            // Add tier breakdown discount lines (volume pricing) before coupon discounts
            $tierBreakdown = $pricingResult['tier_breakdown'] ?? [];
            foreach ($tierBreakdown as $bd) {
                $lineItems[] = $bd;
            }

            // Add coupon discount line item for fixed/percentage coupons
            $couponType = $pricingResult['couponType'] ?? null;
            if ($couponDiscountCents > 0 && $couponType !== null && $couponType !== 'free_items') {
                $nonQuoteItems = array_filter($lineItems, fn($li) => empty($li['isQuote']));
                $nonQuoteCount = count($nonQuoteItems);
                if ($nonQuoteCount > 0) {
                    $perImageCents = (int) round($couponDiscountCents / $nonQuoteCount);
                    $lineItems[] = [
                        'type' => 'discount_coupon',
                        'filename' => 'Coupon-Rabatt',
                        'notes' => sprintf('%d × %s €', $nonQuoteCount, number_format($perImageCents / 100, 2, ',', '.')),
                        'price' => $perImageCents,
                        'row_total' => -$couponDiscountCents,
                        'qty' => $nonQuoteCount,
                    ];
                }
            }

            if ($totalNetCents <= 0 && !$isQuoteRequest) return response()->json(['error' => 'Warenkorb hat keinen Wert.'], 400);

            $user->update($request->only(['billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city']));

            $tenant = $user->tenants()->first();
            $isLieferschein = $tenant && $tenant->invoice_frequency !== 'immediate';
            $orderStatus = $isQuoteRequest ? 'pending' : ($isLieferschein ? 'delivery_note' : ($paymentMethod === 'invoice' ? 'invoice_created' : 'pending_payment'));

            // Include coupon data when a coupon was applied
            $orderData = [
                'user_id' => $user->id,
                'status' => $orderStatus,
                'brand' => config('app.brand'),
                'total_amount' => $totalNetCents,
                'is_quote_request' => $isQuoteRequest,
            ];
            if ($appliedCouponId !== null) {
                $orderData['coupon_id'] = $appliedCouponId;
                $orderData['coupon_discount_cents'] = $couponDiscountCents;
            }
            $order = Order::create($orderData);

            // Increment coupon usage counter if a coupon was applied
            if ($appliedCouponId !== null) {
                $coupon = \App\Models\Coupon::find($appliedCouponId);
                if ($coupon) {
                    app(\App\Services\CouponService::class)->incrementUsage($coupon, $user->id);
                }
            }

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
