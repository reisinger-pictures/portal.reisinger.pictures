<?php
namespace App\Services;
use App\Contracts\PricingStrategy;
use App\Models\Gallery;
use App\Models\Order;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseUseCase;
use App\Models\Photo;
use App\Pricing\ScopeLicensingStrategy;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\CouponService;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\CustomMail;
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

            $quoteToken = $request->input('quote_token');

            $appliedCoupon = null;

            if ($quoteToken !== null) {
                $offerTokenService = app(\App\Services\OfferTokenService::class);
                $tokenPayload = $offerTokenService->verify($quoteToken);
                if ($tokenPayload === null) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(
                        response()->json(['error' => 'Angebot ist abgelaufen oder ungültig.'], 422)
                    );
                }

                $totalNetCents = (int) $tokenPayload['price'];
                $couponDiscountCents = 0;

                $lineItems = $this->buildQuoteLineItems($tokenPayload);
                $customConditions = $tokenPayload['rights_text'] ?? null;
            } else {
                $couponCode = $request->input('coupon_code');

                $groups = $this->groupItemsByLicensingMode($strategyItems);

                $hasVolumeGroup = collect(array_keys($groups))->contains(fn ($key) => str_starts_with($key, 'volume_licensing'));

                if ($hasVolumeGroup || count($groups) > 1) {
                    if ($hasVolumeGroup && $couponCode !== null) {
                        $appliedCoupon = $this->resolveCoupon($couponCode, $request->items, $user);
                    }

                    $pricingResult = $this->calculateMultiStrategyCart($groups, $user, $hasVolumeGroup ? $couponCode : null);
                } else {
                    if ($this->strategy->supportsCoupons() && $couponCode !== null) {
                        $appliedCoupon = $this->resolveCoupon($couponCode, $request->items, $user);
                    }

                    $pricingResult = $this->strategy->calculateCart($strategyItems, $user, $this->strategy->supportsCoupons() ? $couponCode : null);
                }

                $totalNetCents = $pricingResult['totalCents'];
                $couponDiscountCents = (int) ($pricingResult['discountCents'] ?? 0);

                $lineItems = $this->buildLineItems($pricingResult, $request->items);
                $customConditions = null;
            }

            if ($totalNetCents <= 0 && !$isQuoteRequest && $quoteToken === null) {
                return response()->json(['error' => 'Warenkorb hat keinen Wert.'], 400);
            }

            $order = DB::transaction(function () use ($request, $user, $paymentMethod, $appliedCoupon, $couponDiscountCents, $totalNetCents, $isQuoteRequest, $lineItems, $customConditions) {
                $user->update($request->only(['billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city']));

                $appliedCouponId = null;
                if ($appliedCoupon !== null) {
                    [$lockedCoupon, $couponError] = $this->couponService->lockAndRevalidateCoupon($appliedCoupon, $user->id);
                    if ($lockedCoupon === null) {
                        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => $couponError], 422));
                    }
                    $appliedCouponId = $lockedCoupon->id;
                }

                $order = $this->createOrder($user, $totalNetCents, $isQuoteRequest, $paymentMethod, $appliedCouponId, $couponDiscountCents);

                $this->createInvoiceSnapshot($order, $request, $user, $lineItems, $totalNetCents, $customConditions);

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
                'gallery_id' => $photo->gallery_id,
            ];

            if ($isItemQuote) {
                $isQuoteRequest = true;
            }
        }

        return [$strategyItems, $isQuoteRequest];
    }

    private function groupItemsByLicensingMode(array $strategyItems): array
    {
        $groups = [];
        foreach ($strategyItems as $item) {
            $gallery = Gallery::find($item['gallery_id']);
            $mode = $gallery ? $gallery->effective_licensing_mode : 'scope_licensing';
            $presetKey = $mode === 'volume_licensing'
                ? ($gallery?->volume_preset_id ?: 'default')
                : 'default';
            $groups[$mode . '|' . $presetKey][] = $item;
        }
        return $groups;
    }

    private function calculateMultiStrategyCart(array $groups, $user, ?string $couponCode): array
    {
        $allItems = [];
        $totalCents = 0;
        $discountCents = 0;
        $couponId = null;
        $allTierBreakdown = [];

        foreach ($groups as $groupKey => $groupItems) {
            [$mode, $presetKey] = explode('|', $groupKey, 2);

            if ($mode === 'volume_licensing') {
                $presetService = app(VolumePresetService::class);
                $preset = $presetKey === 'default'
                    ? $presetService->resolveDefaultForBrand(\App\Support\BrandRegistry::currentOrDefault())
                    : \App\Models\VolumePreset::findOrFail($presetKey);
                $strategy = new VolumeLicensingStrategy($preset, $this->couponService);
            } else {
                $strategy = new ScopeLicensingStrategy();
            }

            $groupCouponCode = $strategy->supportsCoupons() ? $couponCode : null;
            $result = $strategy->calculateCart($groupItems, $user, $groupCouponCode);

            $allItems = array_merge($allItems, $result['items']);
            $totalCents += $result['totalCents'];
            $discountCents += (int) ($result['discountCents'] ?? 0);
            if (!empty($result['couponId'])) {
                $couponId = $result['couponId'];
            }
            if (!empty($result['tier_breakdown'])) {
                $allTierBreakdown = array_merge($allTierBreakdown, $result['tier_breakdown']);
            }
        }

        return [
            'items' => $allItems,
            'totalCents' => $totalCents,
            'discountCents' => $discountCents,
            'couponId' => $couponId,
            'tier_breakdown' => $allTierBreakdown,
        ];
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

        return $validCoupon;
    }

    private function buildLineItems(array $pricingResult, array $requestItems): array
    {
        $lineItems = [];
        $requestItemsById = collect($requestItems)->keyBy('photoId')->toArray();

        foreach ($pricingResult['items'] as $pricedItem) {
            $photoId = $pricedItem['itemId'];
            $item = $requestItemsById[$photoId] ?? [];
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
        $org = $user->org;
        $isLieferschein = $org && $org->invoice_frequency !== 'immediate';
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

    private function buildQuoteLineItems(array $tokenPayload): array
    {
        $photoIds = $tokenPayload['photos'] ?? [];
        $totalPrice = (int) ($tokenPayload['price'] ?? 0);
        $count = count($photoIds);
        $perItemCents = $count > 0 ? (int) round($totalPrice / $count) : 0;

        $lineItems = [];
        foreach ($photoIds as $photoId) {
            $photo = Photo::find($photoId);
            $lineItems[] = [
                'photoId' => $photoId,
                'filename' => $photo ? ($photo->title ?: 'Bild ' . substr($photo->id, 0, 8)) : 'Unbekannt',
                'tier' => 'original',
                'useCaseId' => null,
                'useCaseName' => 'Angebot (Festpreis)',
                'modifierNames' => [],
                'price' => $perItemCents,
                'isQuote' => false,
                'notes' => null,
            ];
        }
        return $lineItems;
    }

    private function createInvoiceSnapshot(Order $order, $request, $user, array $lineItems, int $totalNetCents, null|string|array $customConditions = null): InvoiceSnapshot
    {
        $org = $user->org;
        $isLieferschein = $org && $org->invoice_frequency !== 'immediate';
        $prefix = $isLieferschein ? 'L-' : 'P-';
        $invoiceNumber = \App\Models\InvoiceSequence::getNextInvoiceNumber($prefix);

        $customerDetails = [
            'name' => $request->billing_name, 'company' => $request->billing_company, 'street' => $request->billing_street,
            'zip' => $request->billing_zip, 'city' => $request->billing_city, 'email' => $user->email,
            'country' => 'Österreich', 'items' => $lineItems, 'quote_message' => $request->quote_message ?? null, 'terms' => [],
        ];

        if ($customConditions !== null) {
            $customerDetails['custom_conditions'] = $customConditions;
        }

        return InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => $invoiceNumber,
            'brand' => $order->brand,
            'customer_details' => $customerDetails,
            'total_net' => $totalNetCents, 'total_gross' => $totalNetCents, 'tax_rate' => null,
        ]);
    }

    private function respondBasedOnPayment(Order $order, $request, $user, bool $isQuoteRequest, string $paymentMethod, int $totalNetCents): \Illuminate\Http\JsonResponse
    {
        $snapshot = $order->invoiceSnapshot;

        if ($isQuoteRequest) {
            return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
        }

        $org = $user->org;
        $isLieferschein = $org && $org->invoice_frequency !== 'immediate';

        if ($isLieferschein || $paymentMethod === 'invoice') {
            Mail::to($user->email)->queue(new InvoiceMail($order, $snapshot));
            return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
        }

        try {
            $paymentResult = $this->stripePayment->createPaymentIntent((int) round($totalNetCents), $order->id, $user->email);
            $order->update(['ip_address' => $request->ip(), 'stripe_payment_intent_id' => $paymentResult['id']]);
        } catch (\Throwable $e) {
            $order->update(['status' => 'cancelled']);
            Log::error('Stripe payment failed for order {order_id}: {message}', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            Mail::to(BrandRegistry::configOrDefault()->accountingEmail)->queue(new CustomMail('Zahlungsfehler', 'Bestellung ' . $order->id . ' konnte nicht bezahlt werden. Ein technischer Fehler ist aufgetreten. Bitte kontaktieren Sie den Support.'));
            return response()->json(['error' => 'Die Zahlung konnte nicht verarbeitet werden. Bitte versuche es später erneut.'], 502);
        }
        return response()->json(['success' => true, 'requires_action' => true, 'client_secret' => $paymentResult['client_secret'], 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
    }
}
