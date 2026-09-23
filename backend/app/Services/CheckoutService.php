<?php

namespace App\Services;

use App\Contracts\PricingStrategy;
use App\Mail\CustomMail;
use App\Mail\InvoiceMail;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseModifier;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Photo;
use App\Models\VolumePreset;
use App\Pricing\ScopeLicensingStrategy;
use App\Pricing\VolumeLicensingStrategy;
use App\Support\BrandRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckoutService
{
    /**
     * Kanonischer Wortlaut des Widerrufsverzichts (immutables Evidence Package).
     * Wird unveränderlich im InvoiceSnapshot abgelegt, in der Kaufmail zitiert
     * und auf der Rechnung ausgewiesen.
     */
    public const WITHDRAWAL_CONSENT_TEXT = 'Ich willige ausdrücklich ein, dass vor Ablauf der Widerrufsfrist mit der Ausführung des Vertrags über digitale Inhalte begonnen wird (sofortiger Download der digitalen Bilddaten). Mir ist bekannt, dass mit dieser Zustimmung mein Rücktritts- bzw. Widerrufsrecht für diesen Vertrag erlischt, sobald die digitalen Bilddaten zum Download bereitgestellt wurden (§ 18 Abs. 1 Z 11 FAGG).';

    protected $strategy;

    private StripePaymentService $stripePayment;

    private CouponService $couponService;

    private CheckoutEligibilityService $checkoutEligibility;

    private CheckoutRiskService $checkoutRisk;

    private CheckoutIdempotencyService $checkoutIdempotency;

    private StripeCheckoutKillSwitch $checkoutKillSwitch;

    public function __construct(
        PricingStrategy $strategy,
        ?StripePaymentService $stripePayment = null,
        ?CouponService $couponService = null,
        ?CheckoutEligibilityService $checkoutEligibility = null,
        ?CheckoutRiskService $checkoutRisk = null,
        ?CheckoutIdempotencyService $checkoutIdempotency = null,
        ?StripeCheckoutKillSwitch $checkoutKillSwitch = null,
    ) {
        $this->strategy = $strategy;
        $this->stripePayment = $stripePayment ?? app(StripePaymentService::class);
        $this->couponService = $couponService ?? app(CouponService::class);
        $this->checkoutEligibility = $checkoutEligibility ?? app(CheckoutEligibilityService::class);
        $this->checkoutRisk = $checkoutRisk ?? app(CheckoutRiskService::class);
        $this->checkoutIdempotency = $checkoutIdempotency ?? new CheckoutIdempotencyService($this->stripePayment);
        $this->checkoutKillSwitch = $checkoutKillSwitch ?? app(StripeCheckoutKillSwitch::class);
    }

    public function processCheckout($request, $user, $paymentMethod)
    {
        try {
            [$strategyItems, $isQuoteRequest] = $this->validateItems($request->items, $user);

            // Server-seitige Durchsetzung des Widerrufsverzichts (WI-A):
            // Nur echte Käufe – inkl. des quote_token-Flows – benötigen eine
            // ausdrückliche Zustimmung. Angebots-Requests (isQuote) sind ausgenommen.
            if (! $isQuoteRequest && ! $request->boolean('withdrawal_waived')) {
                throw new HttpResponseException(
                    response()->json(['error' => 'Sie müssen auf Ihr Widerrufsrecht verzichten, um digitale Bilddaten zu kaufen.'], 422)
                );
            }

            $withdrawalWaived = ! $isQuoteRequest && $request->boolean('withdrawal_waived');
            $org = $user->org;
            $isPotentialImmediateStripe = ! $isQuoteRequest
                && $paymentMethod === 'stripe'
                && ! ($org && $org->invoice_frequency !== 'immediate');

            // An exact-key or lost-key positive-value order can be resumed
            // before coupon revalidation. New carts, including carts that later
            // become free through a 100% discount, must be priced before the
            // account-age gate is applied.
            if ($isPotentialImmediateStripe) {
                $providedKey = trim((string) $request->header('Idempotency-Key', ''));
                $preflight = null;
                $exactKeyMatch = false;
                $existingOrder = null;
                if ($providedKey !== '') {
                    // Validate the key before any exact-key lookup.
                    $preflight = $this->checkoutIdempotency->identify(
                        $request,
                        $user,
                        null,
                        $paymentMethod,
                    );
                    $existingOrder = $this->checkoutIdempotency
                        ->findPositiveStripeOrderByKey($user, $preflight['key']);
                    $exactKeyMatch = $existingOrder !== null
                        && (string) $existingOrder->checkout_idempotency_key === $preflight['key'];
                }

                if ($existingOrder === null) {
                    // Recompute the fingerprint with each persisted server
                    // total; the client amount is never used for this lookup.
                    // The bounded user/brand-scoped query prevents coupon
                    // expiry/max-use from stranding a lost-key recovery.
                    $existingOrder = $this->checkoutIdempotency
                        ->findPositiveStripeOrderByFingerprint($user, $request, $paymentMethod);
                }

                if ($existingOrder !== null) {
                    // This is a known positive-value Stripe order, so the
                    // normal account-age admission gate applies before its
                    // replay/resume path.
                    $this->checkoutEligibility->assertImmediateStripeAllowed($user);
                    $persistedAmount = (int) $existingOrder->total_amount;
                    $identity = $this->checkoutIdempotency->identify(
                        $request,
                        $user,
                        $persistedAmount,
                        $paymentMethod,
                    );

                    return $this->checkoutIdempotency->execute(
                        $user,
                        $identity['key'],
                        $identity['fingerprint'],
                        $persistedAmount,
                        function (?Order $order) use (
                            $request,
                            $user,
                            $persistedAmount,
                            $identity,
                        ): JsonResponse {
                            if ($order === null) {
                                return response()->json([
                                    'error' => 'Der Checkout konnte nicht sicher fortgesetzt werden.',
                                ], 503);
                            }

                            // These checks run only when the exact/lost-key
                            // order actually needs a new/ambiguous PI. Reusable
                            // and paid orders return before this closure.
                            $this->checkoutKillSwitch->assertEnabled();
                            $this->checkoutRisk->assertCheckoutQuotaAllowed($request, $user);
                            $this->checkoutRisk->assertImmediateStripeAllowed($request, $user);

                            return $this->createImmediateStripeOrderAndRespond(
                                $order,
                                $request,
                                $user,
                                null,
                                0,
                                $persistedAmount,
                                false,
                                [],
                                null,
                                true,
                                $identity['key'],
                                $identity['fingerprint'],
                            );
                        },
                        ! $exactKeyMatch,
                    );
                }
            }

            $quoteToken = $request->input('quote_token');

            $appliedCoupon = null;

            if ($quoteToken !== null) {
                $offerTokenService = app(OfferTokenService::class);
                $tokenPayload = $offerTokenService->verify($quoteToken);
                if ($tokenPayload === null) {
                    throw new HttpResponseException(
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

            // A signed quote token must carry a positive amount. A €0/negative
            // offer would otherwise create a downloadable order for free.
            if ($quoteToken !== null && $totalNetCents <= 0) {
                return response()->json(['error' => 'Angebot ist ungültig.'], 422);
            }

            // A negative total is never legitimate.
            if ($totalNetCents < 0) {
                return response()->json(['error' => 'Warenkorb hat keinen Wert.'], 400);
            }

            // A zero total is only legitimate when a real discount reduced a
            // positive cart to zero. An empty/valueless cart (e.g. a flatrate-
            // covered cart without a coupon) stays rejected.
            if ($totalNetCents === 0 && ! $isQuoteRequest && $quoteToken === null && $couponDiscountCents <= 0) {
                return response()->json(['error' => 'Warenkorb hat keinen Wert.'], 400);
            }

            $isImmediateStripe = $isPotentialImmediateStripe
                && $totalNetCents > 0;

            if ($isImmediateStripe) {
                $this->checkoutEligibility->assertImmediateStripeAllowed($user);
                $amountCents = (int) round($totalNetCents);
                $identity = $this->checkoutIdempotency->identify(
                    $request,
                    $user,
                    $amountCents,
                    $paymentMethod,
                );
                $replayResponse = $this->checkoutIdempotency->replay(
                    $user,
                    $identity['key'],
                    $identity['fingerprint'],
                    $amountCents,
                );
                if ($replayResponse !== null) {
                    // Exact replays are resolved before the dedicated PI-attempt
                    // budget; the API throttle still applies at the route.
                    return $replayResponse;
                }

                $this->checkoutKillSwitch->assertEnabled();
                $this->checkoutRisk->assertCheckoutQuotaAllowed($request, $user);
                $this->checkoutRisk->assertImmediateStripeAllowed($request, $user);

                return $this->checkoutIdempotency->execute(
                    $user,
                    $identity['key'],
                    $identity['fingerprint'],
                    $amountCents,
                    fn (?Order $existingOrder) => $this->createImmediateStripeOrderAndRespond(
                        $existingOrder,
                        $request,
                        $user,
                        $appliedCoupon,
                        $couponDiscountCents,
                        $totalNetCents,
                        $isQuoteRequest,
                        $lineItems,
                        $customConditions,
                        $withdrawalWaived,
                        $identity['key'],
                        $identity['fingerprint'],
                    ),
                );
            }

            $order = DB::transaction(function () use ($request, $user, $paymentMethod, $appliedCoupon, $couponDiscountCents, $totalNetCents, $isQuoteRequest, $lineItems, $customConditions, $withdrawalWaived) {
                $user->update($request->only(['billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city']));

                $appliedCouponId = null;
                if ($appliedCoupon !== null) {
                    [$lockedCoupon, $couponError] = $this->couponService->lockAndRevalidateCoupon($appliedCoupon, $user->id);
                    if ($lockedCoupon === null) {
                        throw new HttpResponseException(response()->json(['error' => $couponError], 422));
                    }
                    $appliedCouponId = $lockedCoupon->id;
                }

                $order = $this->createOrder(
                    $user,
                    $totalNetCents,
                    $isQuoteRequest,
                    $paymentMethod,
                    $appliedCouponId,
                    $couponDiscountCents,
                    $withdrawalWaived,
                    null,
                    null,
                    1,
                    $request->ip(),
                );

                $this->createInvoiceSnapshot($order, $request, $user, $lineItems, $totalNetCents, $customConditions, $withdrawalWaived);

                return $order;
            });

            return $this->respondBasedOnPayment($order, $request, $user, $isQuoteRequest, $paymentMethod, $totalNetCents);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (QueryException $e) {
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
            if (! $photo->gallery->is_public && ! $user->canAccessGallery($photo->gallery_id)) {
                throw new HttpResponseException(response()->json(['error' => 'Zugriff verweigert'], 403));
            }

            $isItemQuote = isset($item['isQuote']) && $item['isQuote'];

            if (! $isItemQuote && ! empty($item['useCaseId'])) {
                $useCase = LicenseUseCase::find($item['useCaseId']);
                if ($useCase) {
                    $currentBrand = BrandRegistry::current();
                    if ($currentBrand !== null && $useCase->brand !== null && $useCase->brand !== $currentBrand) {
                        throw new HttpResponseException(response()->json(['error' => 'Ungültige Lizenz-Auswahl.'], 422));
                    }
                    $isCommercial = $useCase->is_commercial || preg_match('/werbung|kampagne|kommerziell/i', $useCase->name.' '.$useCase->description);
                    if ($isCommercial && ($photo->effective_is_editorial_only || $photo->is_editorial_only)) {
                        throw new HttpResponseException(response()->json(['error' => "Das Bild '{$photo->filename}' ist nur für redaktionelle Nutzung freigegeben."], 403));
                    }
                }
            }

            // Cross-brand license modifiers must be rejected with a 4xx instead of
            // bubbling up as a RuntimeException from the pricing strategy (500).
            if (! $isItemQuote && ! empty($item['modifierIds'])) {
                $currentBrand = BrandRegistry::current();
                if ($currentBrand !== null) {
                    $modifiers = LicenseModifier::whereIn('id', $item['modifierIds'])->get();
                    foreach ($modifiers as $modifier) {
                        if ($modifier->brand !== null && $modifier->brand !== $currentBrand) {
                            throw new HttpResponseException(response()->json(['error' => 'Ungültige Lizenz-Auswahl.'], 422));
                        }
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
            $groups[$mode.'|'.$presetKey][] = $item;
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
                    ? $presetService->resolveDefaultForBrand(BrandRegistry::currentOrDefault())
                    : VolumePreset::findOrFail($presetKey);
                $strategy = new VolumeLicensingStrategy($preset, $this->couponService);
            } else {
                $strategy = new ScopeLicensingStrategy;
            }

            $groupCouponCode = $strategy->supportsCoupons() ? $couponCode : null;
            $result = $strategy->calculateCart($groupItems, $user, $groupCouponCode);

            $allItems = array_merge($allItems, $result['items']);
            $totalCents += $result['totalCents'];
            $discountCents += (int) ($result['discountCents'] ?? 0);
            if (! empty($result['couponId'])) {
                $couponId = $result['couponId'];
            }
            if (! empty($result['tier_breakdown'])) {
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

    private function resolveCoupon(?string $couponCode, array $items, $user): ?Coupon
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
            throw new HttpResponseException(response()->json(['error' => 'Der Rabattcode ist nicht mehr gültig.'], 422));
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
                'filename' => $photo ? ($photo->title ?: 'Bild '.substr($photo->id, 0, 8)) : 'Unbekannt',
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
            $nonQuoteItems = array_filter($lineItems, fn ($li) => empty($li['isQuote']));
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

    private function createOrder(
        $user,
        int $totalNetCents,
        bool $isQuoteRequest,
        string $paymentMethod,
        $appliedCouponId,
        int $couponDiscountCents,
        bool $withdrawalWaived = false,
        ?string $checkoutIdempotencyKey = null,
        ?string $checkoutFingerprint = null,
        int $paymentIntentGeneration = 1,
        ?string $requestIp = null,
    ): Order {
        $org = $user->org;
        $isLieferschein = $org && $org->invoice_frequency !== 'immediate';
        $orderStatus = $isQuoteRequest ? 'pending' : ($isLieferschein ? 'delivery_note' : ($paymentMethod === 'invoice' ? 'invoice_created' : 'pending_payment'));

        $orderData = [
            'user_id' => $user->id,
            'status' => $orderStatus,
            'brand' => BrandRegistry::current()?->value,
            'total_amount' => $totalNetCents,
            'is_quote_request' => $isQuoteRequest,
            'payment_intent_generation' => $paymentIntentGeneration,
        ];
        if ($checkoutIdempotencyKey !== null) {
            $orderData['checkout_idempotency_key'] = $checkoutIdempotencyKey;
            $orderData['checkout_fingerprint'] = $checkoutFingerprint;
        }
        if (is_string($requestIp) && $requestIp !== '') {
            $orderData['ip_address'] = $requestIp;
        }
        if ($withdrawalWaived) {
            $orderData['withdrawal_waived'] = true;
            $orderData['withdrawal_consent_at'] = now();
        }
        if ($appliedCouponId !== null) {
            $orderData['coupon_id'] = $appliedCouponId;
            $orderData['coupon_discount_cents'] = $couponDiscountCents;

            $coupon = Coupon::find($appliedCouponId);
            if ($coupon) {
                $this->couponService->incrementUsage($coupon, $user->id);
            }
        }

        return Order::create($orderData);
    }

    private function createImmediateStripeOrderAndRespond(
        ?Order $existingOrder,
        $request,
        $user,
        $appliedCoupon,
        int $couponDiscountCents,
        int $totalNetCents,
        bool $isQuoteRequest,
        array $lineItems,
        null|string|array $customConditions,
        bool $withdrawalWaived,
        string $checkoutIdempotencyKey,
        string $checkoutFingerprint,
    ): JsonResponse {
        $order = $existingOrder;
        if ($order !== null) {
            $persistedKey = $order->checkout_idempotency_key;
            $persistedFingerprint = $order->checkout_fingerprint;
            if (! is_string($persistedKey) || $persistedKey === ''
                || ! is_string($persistedFingerprint) || $persistedFingerprint === '') {
                return $this->checkoutStateConflict('Die Checkout-Identität ist unvollständig.');
            }
            // A fingerprint fallback may arrive with a new client key. Stripe
            // metadata and the deterministic API key must remain bound to the
            // original order identity, never to that recovery handle.
            $checkoutIdempotencyKey = $persistedKey;
            $checkoutFingerprint = $persistedFingerprint;
        }

        if ($order === null) {
            $order = DB::transaction(function () use ($request, $user, $appliedCoupon, $couponDiscountCents, $totalNetCents, $isQuoteRequest, $lineItems, $customConditions, $withdrawalWaived, $checkoutIdempotencyKey, $checkoutFingerprint) {
                $user->update($request->only(['billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city']));

                $appliedCouponId = null;
                if ($appliedCoupon !== null) {
                    [$lockedCoupon, $couponError] = $this->couponService->lockAndRevalidateCoupon($appliedCoupon, $user->id);
                    if ($lockedCoupon === null) {
                        throw new HttpResponseException(response()->json(['error' => $couponError], 422));
                    }
                    $appliedCouponId = $lockedCoupon->id;
                }

                $order = $this->createOrder(
                    $user,
                    $totalNetCents,
                    $isQuoteRequest,
                    'stripe',
                    $appliedCouponId,
                    $couponDiscountCents,
                    $withdrawalWaived,
                    $checkoutIdempotencyKey,
                    $checkoutFingerprint,
                    1,
                    $request->ip(),
                );

                $this->createInvoiceSnapshot($order, $request, $user, $lineItems, $totalNetCents, $customConditions, $withdrawalWaived);

                return $order;
            });
        } else {
            // CheckoutIdempotencyService has already performed the locked,
            // conditional generation transition for a replaced PI. A null-PI
            // resume is likewise rechecked under that lock before this method
            // is called. Never reopen a paid or cancelled order here.
            $order->loadMissing('invoiceSnapshot');
        }

        $lockedOrder = $this->lockPendingOrderForPaymentCreate($order);
        if ($lockedOrder instanceof JsonResponse) {
            return $lockedOrder;
        }
        $order = $lockedOrder;

        return $this->createImmediateStripeResponse(
            $order,
            $request,
            $user,
            (int) round($totalNetCents),
            $order->checkout_idempotency_key,
            $order->checkout_fingerprint,
        );
    }

    /**
     * Re-check the order under a row lock immediately before the creator can
     * start a Stripe create. The generation transition in the idempotency
     * service is not sufficient by itself: a webhook or administrator may
     * win after that transition. A paid order is returned as a safe replay;
     * closed or changed orders never get a new actionable PI.
     */
    private function lockPendingOrderForPaymentCreate(Order $order): Order|JsonResponse
    {
        $expectedGeneration = (int) $order->payment_intent_generation;
        $expectedPaymentIntentId = $order->stripe_payment_intent_id;
        $result = DB::transaction(function () use ($order, $expectedGeneration, $expectedPaymentIntentId): array {
            $lockedOrder = Order::query()
                ->with(['invoiceSnapshot', 'user'])
                ->lockForUpdate()
                ->find($order->getKey());

            if ($lockedOrder === null) {
                return ['response' => $this->checkoutStateConflict('Der Checkout existiert nicht mehr.')];
            }
            if ($lockedOrder->status === 'paid') {
                return ['response' => $this->paidOrderReplayResponse($lockedOrder)];
            }
            if ($lockedOrder->status !== 'pending_payment'
                || $lockedOrder->stripe_payment_intent_id !== $expectedPaymentIntentId
                || (int) $lockedOrder->payment_intent_generation !== $expectedGeneration) {
                return ['response' => $this->checkoutStateConflict('Der Checkout hat sich zwischenzeitlich geändert.')];
            }

            $conditionalPending = Order::query()
                ->whereKey($lockedOrder->getKey())
                ->where('status', 'pending_payment')
                ->where('payment_intent_generation', $expectedGeneration)
                ->when(
                    $expectedPaymentIntentId === null,
                    fn ($query) => $query->whereNull('stripe_payment_intent_id'),
                    fn ($query) => $query->where('stripe_payment_intent_id', $expectedPaymentIntentId),
                )
                ->exists();
            if (! $conditionalPending) {
                return ['response' => $this->checkoutStateConflict('Der Checkout hat sich zwischenzeitlich geändert.')];
            }

            return ['order' => $lockedOrder];
        });

        if (isset($result['response'])) {
            return $result['response'];
        }

        return $result['order'];
    }

    private function paidOrderReplayResponse(Order $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'order_id' => $order->getKey(),
            'invoice_number' => $order->invoiceSnapshot?->invoice_number,
        ]);
    }

    private function checkoutStateConflict(string $message): JsonResponse
    {
        return response()->json([
            'error' => $message,
            'idempotency_conflict' => true,
        ], 409);
    }

    private function createImmediateStripeResponse(
        Order $order,
        $request,
        $user,
        int $totalNetCents,
        ?string $checkoutIdempotencyKey = null,
        ?string $checkoutFingerprint = null,
    ): JsonResponse
    {
        $this->checkoutKillSwitch->assertEnabled();

        try {
            // Capture the first trusted checkout IP before contacting Stripe.
            // A retry must never replace this evidence with a later request IP.
            $this->captureOrderIpIfMissing($order, $request);

            $generation = max(1, (int) $order->payment_intent_generation);
            $customerService = app(StripeCustomerService::class);
            $customerId = $customerService->getOrCreateCustomer($user, [
                'name' => $request->input('billing_name') ?: $user->name,
                'address' => [
                    'line1' => $request->input('billing_street'),
                    'postal_code' => $request->input('billing_zip'),
                    'city' => $request->input('billing_city'),
                    'country' => 'AT',
                ],
            ]);

            // Customer creation is outside the row lock. Re-check the
            // conditional pending state once more before the PI request.
            $lockedOrder = $this->lockPendingOrderForPaymentCreate($order);
            if ($lockedOrder instanceof JsonResponse) {
                return $lockedOrder;
            }
            $order = $lockedOrder;
            $generation = (int) $order->payment_intent_generation;

            $paymentResult = $this->stripePayment->createPaymentIntent(
                $totalNetCents,
                $order->id,
                $user->email,
                $customerId,
                $generation,
                $user->getKey(),
                $user->created_at?->getTimestamp(),
                $checkoutIdempotencyKey,
                $checkoutFingerprint,
            );

            $this->assertValidPaymentIntentResponse(
                $paymentResult,
                $order,
                $user,
                $totalNetCents,
                $generation,
                $customerId,
            );

            $clientSecret = trim((string) $paymentResult['client_secret']);
            $expectedPaymentIntentId = $order->stripe_payment_intent_id;
            $query = Order::query()
                ->whereKey($order->getKey())
                ->where('status', 'pending_payment')
                ->where('payment_intent_generation', $generation);
            if ($expectedPaymentIntentId === null || $expectedPaymentIntentId === '') {
                $query->whereNull('stripe_payment_intent_id');
            } else {
                $query->where('stripe_payment_intent_id', $expectedPaymentIntentId);
            }

            $updated = $query->update([
                'stripe_payment_intent_id' => $paymentResult['id'],
            ]);
            if ($updated !== 1) {
                throw new \RuntimeException('The order changed while the PaymentIntent was being persisted.');
            }
            $order->refresh();
        } catch (\Throwable $e) {
            // A create request can time out after Stripe accepted it. Keep the
            // order pending and retain its generation so the same checkout
            // key can safely retry the deterministic Stripe idempotency key.
            Log::error('Stripe payment failed for order {order_id}', [
                'order_id' => $order->id,
                'exception_class' => $e::class,
            ]);

            // Accounting notification is best-effort. It must never turn the
            // intentionally retryable payment failure into a different 500.
            try {
                Mail::to(BrandRegistry::configOrDefault()->accountingEmail)->queue(new CustomMail('Zahlungsfehler', 'Bestellung '.$order->id.' konnte nicht bezahlt werden. Ein technischer Fehler ist aufgetreten. Bitte kontaktieren Sie den Support.'));
            } catch (\Throwable $mailException) {
                Log::warning('Stripe payment accounting mail failed', [
                    'order_id' => $order->id,
                    'exception_class' => $mailException::class,
                ]);
            }

            return response()->json(['error' => 'Die Zahlung konnte nicht verarbeitet werden. Bitte versuche es später erneut.'], 502);
        }

        return response()->json([
            'success' => true,
            'requires_action' => true,
            'client_secret' => $clientSecret,
            'order_id' => $order->id,
            'invoice_number' => $order->invoiceSnapshot?->invoice_number,
        ]);
    }

    private function captureOrderIpIfMissing(Order $order, $request): void
    {
        $ip = $request->ip();
        if (! is_string($ip) || $ip === '') {
            return;
        }

        Order::query()
            ->whereKey($order->getKey())
            ->whereNull('ip_address')
            ->update(['ip_address' => $ip]);
        $order->refresh();
    }

    /**
     * Validate the complete, server-owned PaymentIntent create response before
     * it can become an actionable client response or local PI linkage.
     *
     * @param  array<string, mixed>  $paymentResult
     */
    private function assertValidPaymentIntentResponse(
        array $paymentResult,
        Order $order,
        $user,
        int $totalNetCents,
        int $generation,
        ?string $expectedCustomerId,
    ): void {
        $id = $paymentResult['id'] ?? null;
        $clientSecret = $paymentResult['client_secret'] ?? null;
        $status = $paymentResult['status'] ?? null;
        $amount = $paymentResult['amount'] ?? null;
        $currency = $paymentResult['currency'] ?? null;

        if (! is_string($id) || trim($id) === ''
            || ! is_string($clientSecret) || trim($clientSecret) === ''
            || ! is_string($status) || ! in_array($status, [
                'requires_payment_method',
                'requires_confirmation',
                'requires_action',
                'processing',
                'requires_capture',
            ], true)
            || ! $this->isIntegerValue($amount)
            || (int) $amount !== $totalNetCents
            || ! $this->isPositiveIntegerValue($paymentResult['created'] ?? null)
            || ! is_string($currency)
            || strtolower(trim($currency)) !== 'eur') {
            throw new \RuntimeException('Stripe PaymentIntent response failed checkout validation.');
        }

        $metadata = $paymentResult['metadata'] ?? [];
        $isLegacyOrder = $order->checkout_idempotency_key === null
            && $order->checkout_fingerprint === null;
        $expectedMetadata = [
            'order_id' => (string) $order->getKey(),
            'amount_cents' => (string) $totalNetCents,
            'currency' => 'eur',
        ];
        if (! $isLegacyOrder) {
            $expectedMetadata += [
                'portal_user_id' => (string) $user->getKey(),
                'account_created_at' => $user->created_at?->getTimestamp() === null
                    ? null
                    : (string) $user->created_at->getTimestamp(),
                'checkout_idempotency_key' => (string) $order->checkout_idempotency_key,
                'checkout_fingerprint' => (string) $order->checkout_fingerprint,
                'generation' => (string) $generation,
            ];
        }

        foreach ($expectedMetadata as $key => $expected) {
            if ($expected === null || $this->metadataString($metadata, $key) !== $expected) {
                throw new \RuntimeException('Stripe PaymentIntent response metadata failed checkout validation.');
            }
        }

        $mappedCustomer = is_string($user->stripe_customer_id) && $user->stripe_customer_id !== ''
            ? $user->stripe_customer_id
            : null;
        $returnedCustomer = $this->customerId($paymentResult['customer'] ?? null);
        if (! $isLegacyOrder) {
            if ($mappedCustomer === null) {
                if (app()->environment('production')
                    || $expectedCustomerId !== null
                    || $returnedCustomer !== null) {
                    throw new \RuntimeException('Stripe PaymentIntent response customer failed checkout validation.');
                }
            } elseif ($expectedCustomerId === null
                || $returnedCustomer === null
                || ! hash_equals($mappedCustomer, $expectedCustomerId)
                || ! hash_equals($mappedCustomer, $returnedCustomer)) {
                throw new \RuntimeException('Stripe PaymentIntent response customer failed checkout validation.');
            }
        } elseif ($mappedCustomer !== null
            && $returnedCustomer !== null
            && ! hash_equals($mappedCustomer, $returnedCustomer)) {
            throw new \RuntimeException('Stripe PaymentIntent response customer failed checkout validation.');
        }
    }

    private function isIntegerValue(mixed $value): bool
    {
        return is_int($value)
            || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1);
    }

    private function isPositiveIntegerValue(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function metadataString(mixed $metadata, string $key): ?string
    {
        $value = null;
        if (is_array($metadata)) {
            $value = $metadata[$key] ?? null;
        } elseif ($metadata instanceof \ArrayAccess && $metadata->offsetExists($key)) {
            $value = $metadata[$key];
        } elseif (is_object($metadata) && isset($metadata->{$key})) {
            $value = $metadata->{$key};
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function customerId(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        } elseif ($value instanceof \ArrayAccess && $value->offsetExists('id')) {
            $value = $value['id'];
        } elseif (is_object($value) && isset($value->id)) {
            $value = $value->id;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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
                'filename' => $photo ? ($photo->title ?: 'Bild '.substr($photo->id, 0, 8)) : 'Unbekannt',
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

    private function createInvoiceSnapshot(Order $order, $request, $user, array $lineItems, int $totalNetCents, null|string|array $customConditions = null, bool $withdrawalWaived = false): InvoiceSnapshot
    {
        $org = $user->org;
        $isLieferschein = $org && $org->invoice_frequency !== 'immediate';
        $prefix = $isLieferschein ? 'L-' : 'P-';
        $invoiceNumber = InvoiceSequence::getNextInvoiceNumber($prefix);

        $customerDetails = [
            'name' => $request->billing_name, 'company' => $request->billing_company, 'street' => $request->billing_street,
            'zip' => $request->billing_zip, 'city' => $request->billing_city, 'email' => $user->email,
            'country' => 'Österreich', 'items' => $lineItems, 'quote_message' => $request->quote_message ?? null, 'terms' => [],
        ];

        if ($customConditions !== null) {
            $customerDetails['custom_conditions'] = $customConditions;
        }

        if ($withdrawalWaived) {
            // Immutables Evidence Package: unveränderlicher Nachweis der Zustimmung.
            $customerDetails['withdrawal_consent'] = [
                'waived' => true,
                'at' => now()->toISOString(),
                'text' => self::WITHDRAWAL_CONSENT_TEXT,
            ];
        }

        return InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => $invoiceNumber,
            'brand' => $order->brand,
            'customer_details' => $customerDetails,
            'total_net' => $totalNetCents, 'total_gross' => $totalNetCents, 'tax_rate' => null,
        ]);
    }

    private function respondBasedOnPayment(Order $order, $request, $user, bool $isQuoteRequest, string $paymentMethod, int $totalNetCents): JsonResponse
    {
        $snapshot = $order->invoiceSnapshot;

        if ($isQuoteRequest) {
            return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
        }

        // A €0 order fully covered by a valid discount (100%-off coupon / free
        // package) is already settled. Stripe must never be called with amount 0.
        if ($totalNetCents === 0) {
            return $this->respondForSettledFreeOrder($order, $user);
        }

        $org = $user->org;
        $isLieferschein = $org && $org->invoice_frequency !== 'immediate';

        if ($isLieferschein || $paymentMethod === 'invoice') {
            Mail::to($user->email)->queue(new InvoiceMail($order, $snapshot));

            return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
        }

        return $this->createImmediateStripeResponse($order, $request, $user, (int) round($totalNetCents));
    }

    /**
     * Fulfil a €0 order that was fully covered by a valid discount.
     *
     * No payment is due, so no PaymentIntent is created. The order is moved to a
     * settled, download-eligible status (unless the Org invoice/Lieferschein flow
     * already assigned one) and the usual invoice/confirmation is sent.
     */
    private function respondForSettledFreeOrder(Order $order, $user): JsonResponse
    {
        if ($order->status === 'pending_payment') {
            $order->update(['status' => 'paid']);
        }

        $snapshot = $order->invoiceSnapshot;

        Mail::to($user->email)->queue(new InvoiceMail($order, $snapshot));

        return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
    }
}
