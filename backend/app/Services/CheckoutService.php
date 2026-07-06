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
    private StripePaymentService $stripePayment;
    private CouponService $couponService;

    public function __construct(
        PricingStrategy $strategy,
        ?StripePaymentService $stripePayment = null,
        ?CouponService $couponService = null,
    ) {
        $this->strategy = $strategy;
        $this->stripePayment = $stripePayment ?? app(StripePaymentService::class);
        $this->couponService = $couponService ?? app(CouponService::class);
    }

    public function processCheckout($request, $user, $paymentMethod) {
        try {
            [$strategyItems, $isQuoteRequest] = $this->validateItems($request->items, $user);

            $couponCode = $request->input('coupon_code');
            $appliedCoupon = $this->resolveCoupon($couponCode, $request->items, $user);

            $pricingResult = $this->strategy->calculateCart($strategyItems, $user, $couponCode);
            $totalNetCents = $pricingResult['totalCents'];
            $couponDiscountCents = (int) ($pricingResult['discountCents'] ?? 0);
            $appliedCouponId = $pricingResult['couponId'] ?? null;

            $lineItems = $this->buildLineItems($pricingResult, $request->items);

            if ($totalNetCents <= 0 && !$isQuoteRequest) {
                return response()->json(['error' => 'Warenkorb hat keinen Wert.'], 400);
            }

            $order = DB::transaction(function () use ($request, $user, $paymentMethod, $appliedCouponId, $couponDiscountCents, $totalNetCents, $isQuoteRequest, $lineItems) {
                $user->update($request->only(['billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city']));

                $order = $this->createOrder($user, $totalNetCents, $isQuoteRequest, $paymentMethod, $appliedCouponId, $couponDiscountCents);

                $this->createInvoiceSnapshot($order, $request, $user, $lineItems, $totalNetCents);

                return $order;
            });

            return $this->respondBasedOnPayment($order, $request, $user, $isQuoteRequest, $paymentMethod, $totalNetCents);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            return $e->getResponse();
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'lock wait timeout')) {
                return response()->json(['error' => 'Server ist derzeit überlastet. Bitte versuche es in einigen Sekunden erneut.'], 503);
            }
            throw $e;
        }
    }

    private function validateItems(array $items, $user): array
    {
        $strategyItems = [];
        $isQuoteRequest = false;

        foreach ($items as $item) {
            $photo = Photo::with('gallery')->findOrFail($item['photoId']);
            if (!$photo->gallery->is_public && !$user->canAccessGallery($photo->gallery_id)) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => 'Zugriff verweigert'], 403));
            }

            $isItemQuote = isset($item['isQuote']) && $item['isQuote'];

            if (!$isItemQuote && !empty($item['useCaseId'])) {
                $useCase = LicenseUseCase::find($item['useCaseId']);
                if ($useCase) {
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

        return [$strategyItems, $isQuoteRequest];
    }

    private function resolveCoupon(?string $couponCode, array $items, $user): ?\App\Models\Coupon
    {
        if ($couponCode === null) {
            return null;
        }

        $brand = BrandRegistry::current();
        if ($brand === null) {
            return null;
        }

        $galleryId = null;
        $metaGalleryId = null;
        foreach ($items as $item) {
            if (empty($item['isQuote'])) {
                $photo = Photo::with('gallery')->find($item['photoId'] ?? 0);
                if ($photo && $photo->gallery) {
                    $galleryId = $photo->gallery_id;
                    $metaGalleryId = $photo->gallery->gallery_group_id ?? null;
                    break;
                }
            }
        }

        [$validCoupon, $couponError] = $this->couponService->findValidCoupon(
            $couponCode, $brand, $galleryId, $metaGalleryId, $user->id,
        );
        if ($validCoupon === null) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => 'Der Rabattcode ist nicht mehr gültig.'], 422));
        }

        [$validCoupon, $couponError] = $this->couponService->lockAndRevalidateCoupon($validCoupon, $user->id);
        if ($validCoupon === null) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => $couponError], 422));
        }

        return $validCoupon;
    }

    private function buildLineItems(array $pricingResult, array $requestItems): array
    {
        $lineItems = [];

        foreach ($pricingResult['items'] as $idx => $pricedItem) {
            $item = $requestItems[$idx] ?? [];
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

        $tierBreakdown = $pricingResult['tier_breakdown'] ?? [];
        foreach ($tierBreakdown as $bd) {
            $lineItems[] = $bd;
        }

        $couponDiscountCents = (int) ($pricingResult['discountCents'] ?? 0);
        $couponType = $pricingResult['couponType'] ?? null;
        if ($couponDiscountCents > 0 && $couponType !== null) {
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

        return $lineItems;
    }

    private function createOrder($user, int $totalNetCents, bool $isQuoteRequest, string $paymentMethod, $appliedCouponId, int $couponDiscountCents): Order
    {
        $tenant = $user->tenant;
        $isLieferschein = $tenant && $tenant->invoice_frequency !== 'immediate';
        $orderStatus = $isQuoteRequest ? 'pending' : ($isLieferschein ? 'delivery_note' : ($paymentMethod === 'invoice' ? 'invoice_created' : 'pending_payment'));

        $orderData = [
            'user_id' => $user->id,
            'status' => $orderStatus,
            'brand' => BrandRegistry::current()?->value,
            'total_amount' => $totalNetCents,
            'is_quote_request' => $isQuoteRequest,
        ];
        if ($appliedCouponId !== null) {
            $orderData['coupon_id'] = $appliedCouponId;
            $orderData['coupon_discount_cents'] = $couponDiscountCents;

            $coupon = \App\Models\Coupon::find($appliedCouponId);
            if ($coupon) {
                $this->couponService->incrementUsage($coupon, $user->id);
            }
        }

        return Order::create($orderData);
    }

    private function createInvoiceSnapshot(Order $order, $request, $user, array $lineItems, int $totalNetCents): InvoiceSnapshot
    {
        $tenant = $user->tenant;
        $isLieferschein = $tenant && $tenant->invoice_frequency !== 'immediate';
        $prefix = $isLieferschein ? 'L-' : 'P-';
        $invoiceNumber = \App\Models\InvoiceSequence::getNextInvoiceNumber($prefix);

        return InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => $invoiceNumber,
            'brand' => $order->brand,
            'customer_details' => [
                'name' => $request->billing_name, 'company' => $request->billing_company, 'street' => $request->billing_street,
                'zip' => $request->billing_zip, 'city' => $request->billing_city, 'email' => $user->email,
                'country' => 'Österreich', 'items' => $lineItems, 'quote_message' => $request->quote_message ?? null, 'terms' => [],
            ],
            'total_net' => $totalNetCents, 'total_gross' => $totalNetCents, 'tax_rate' => 0.00,
        ]);
    }

    private function respondBasedOnPayment(Order $order, $request, $user, bool $isQuoteRequest, string $paymentMethod, int $totalNetCents): \Illuminate\Http\JsonResponse
    {
        $snapshot = $order->invoiceSnapshot;

        if ($isQuoteRequest) {
            return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
        }

        $tenant = $user->tenant;
        $isLieferschein = $tenant && $tenant->invoice_frequency !== 'immediate';

        if ($isLieferschein || $paymentMethod === 'invoice') {
            Mail::to($user->email)->queue(new InvoiceMail($order, $snapshot));
            return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
        }

        $paymentResult = $this->stripePayment->createPaymentIntent((int) round($totalNetCents), $order->id, $user->email);
        $order->update(['ip_address' => $request->ip(), 'stripe_payment_intent_id' => $paymentResult['id']]);
        return response()->json(['success' => true, 'requires_action' => true, 'client_secret' => $paymentResult['client_secret'], 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
    }
}
