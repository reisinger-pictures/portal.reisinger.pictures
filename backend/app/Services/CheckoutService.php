<?php

namespace App\Services;

use App\Contracts\PricingStrategy;
use App\Mail\CustomMail;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseModifier;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Models\VolumePreset;
use App\Pricing\ScopeLicensingStrategy;
use App\Pricing\VolumeLicensingStrategy;
use App\Support\ActorIdentity;
use App\Support\BrandRegistry;
use App\Support\PersistedMoney;
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

    private InvoiceMailDispatcher $invoiceMailDispatcher;

    public function __construct(
        PricingStrategy $strategy,
        ?StripePaymentService $stripePayment = null,
        ?CouponService $couponService = null,
        ?CheckoutEligibilityService $checkoutEligibility = null,
        ?CheckoutRiskService $checkoutRisk = null,
        ?CheckoutIdempotencyService $checkoutIdempotency = null,
        ?StripeCheckoutKillSwitch $checkoutKillSwitch = null,
        ?InvoiceMailDispatcher $invoiceMailDispatcher = null,
    ) {
        $this->strategy = $strategy;
        $this->stripePayment = $stripePayment ?? app(StripePaymentService::class);
        $this->couponService = $couponService ?? app(CouponService::class);
        $this->checkoutEligibility = $checkoutEligibility ?? app(CheckoutEligibilityService::class);
        $this->checkoutRisk = $checkoutRisk ?? app(CheckoutRiskService::class);
        $this->checkoutIdempotency = $checkoutIdempotency ?? new CheckoutIdempotencyService($this->stripePayment);
        $this->checkoutKillSwitch = $checkoutKillSwitch ?? app(StripeCheckoutKillSwitch::class);
        $this->invoiceMailDispatcher = $invoiceMailDispatcher ?? app(InvoiceMailDispatcher::class);
    }

    public function processCheckout($request, $user, $paymentMethod)
    {
        try {
            // A transient guest has no durable account, billing identity, or
            // Stripe Customer mapping. Do not turn one into a shadow user or
            // let the old null-user path create an ownerless checkout. Guest
            // Stripe checkout remains explicitly unsupported until that flow
            // has a verified, guest-safe payment/receipt contract.
            if (! $user instanceof User || ! ActorIdentity::isRegistered($user)) {
                return response()->json([
                    'error' => 'Der Checkout ist für Gäste derzeit nicht verfügbar. Bitte melde dich mit einem Portal-Konto an.',
                    'guest_checkout_unsupported' => true,
                ], 403);
            }

            // Resolve an exact non-immediate claim before token, item, and
            // coupon validation. The persisted fingerprint and order owner
            // bind the replay to the original request; mutable catalog,
            // authorization, and coupon state must not strand a committed
            // invoice, delivery note, settled-free order, or quote response.
            $nonImmediateReplay = $this->checkoutIdempotency->replayNonImmediateByKey(
                $user,
                $request,
                $paymentMethod,
                fn (Order $order): JsonResponse => $this->respondForExistingNonImmediateOrder($order, $user),
            );
            if ($nonImmediateReplay !== null) {
                return $nonImmediateReplay;
            }

            $quoteToken = $request->input('quote_token');
            $quoteTokenPayload = null;
            $quotePhotos = [];

            if ($quoteToken !== null) {
                [$quoteTokenPayload, $quotePhotos] = $this->resolveQuoteToken(
                    $quoteToken,
                    $user,
                    $request->input('items', []),
                );
            }

            [$strategyItems, $isQuoteRequest] = $this->validateItems($request->items, $user);

            // A signed quote is a fixed-price purchase, never a reactive quote
            // request. Do not let a client-supplied isQuote flag downgrade the
            // flow and bypass the withdrawal/download safeguards.
            if ($quoteTokenPayload !== null) {
                $isQuoteRequest = false;
            }

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
                            $quoteTokenPayload,
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
                                $quoteTokenPayload,
                            );
                        },
                        ! $exactKeyMatch,
                    );
                }
            }

            $appliedCoupon = null;

            if ($quoteTokenPayload !== null) {
                $totalNetCents = $quoteTokenPayload['price'];
                $couponDiscountCents = 0;

                $lineItems = $this->buildQuoteLineItems($quoteTokenPayload, $quotePhotos);
                $customConditions = $quoteTokenPayload['rights_text'] ?? null;
            } else {
                $couponCode = $request->input('coupon_code');

                $groups = $this->groupItemsByLicensingMode($strategyItems);
                $volumeCouponItems = $this->collectVolumeCouponItems($groups);
                $hasVolumeGroup = $volumeCouponItems !== [];

                if ($hasVolumeGroup || count($groups) > 1) {
                    $pricingResult = $this->calculateMultiStrategyCart($groups, $user);

                    // Validate once against every volume item. The discount is
                    // applied below to the aggregate volume subtotal, never
                    // once per pricing group. A zero-value volume subtotal has
                    // no real discount to consume.
                    if ($hasVolumeGroup
                        && $couponCode !== null
                        && (int) ($pricingResult['coupon_subtotal_cents'] ?? 0) > 0) {
                        $appliedCoupon = $this->resolveCoupon($couponCode, $volumeCouponItems, $user);
                        if ($appliedCoupon !== null) {
                            $pricingResult = $this->applyCartCoupon($pricingResult, $appliedCoupon);
                        }
                    }
                } else {
                    // A single scope group may use the injected strategy for
                    // compatibility with callers/tests. Never let the injected
                    // volume strategy override an explicit gallery scope mode.
                    $scopeStrategy = $this->scopeStrategy();
                    $supportsCoupons = $scopeStrategy->supportsCoupons();
                    if ($supportsCoupons && $couponCode !== null) {
                        $appliedCoupon = $this->resolveCoupon($couponCode, $strategyItems, $user);
                    }

                    $pricingResult = $scopeStrategy->calculateCart(
                        $strategyItems,
                        $user,
                        $supportsCoupons ? $couponCode : null,
                    );
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

            try {
                PersistedMoney::assertFitsCents($totalNetCents, 'checkout total');
            } catch (\InvalidArgumentException) {
                return response()->json([
                    'error' => 'Der Gesamtbetrag überschreitet das zulässige gespeicherte Betragslimit.',
                ], 422);
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
                        $quoteTokenPayload,
                    ),
                );
            }

            return $this->processNonImmediateCheckout(
                $request,
                $user,
                $paymentMethod,
                $totalNetCents,
                $isQuoteRequest,
                $appliedCoupon,
                $couponDiscountCents,
                $lineItems,
                $customConditions,
                $withdrawalWaived,
                $quoteTokenPayload,
            );
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'lock wait timeout')) {
                return response()->json(['error' => 'Server ist derzeit überlastet. Bitte versuche es in einigen Sekunden erneut.'], 503);
            }
            throw $e;
        }
    }

    /**
     * Persist and answer an invoice, settled-free, delivery-note, or reactive
     * quote checkout under the same browser key claim used by Stripe checkout.
     * The V036 identity namespace is intentionally shared, while no
     * Stripe-specific admission, quota, kill-switch, or customer/PI work is
     * introduced on this branch. Exact-key replay is safe; a lost browser key
     * is intentionally a new-order boundary.
     */
    private function processNonImmediateCheckout(
        $request,
        $user,
        string $paymentMethod,
        int $totalNetCents,
        bool $isQuoteRequest,
        ?Coupon $appliedCoupon,
        int $couponDiscountCents,
        array $lineItems,
        null|string|array $customConditions,
        bool $withdrawalWaived,
        ?array $quoteTokenPayload,
    ): JsonResponse {
        $identity = $this->checkoutIdempotency->identify(
            $request,
            $user,
            $totalNetCents,
            $paymentMethod,
        );

        return $this->checkoutIdempotency->executeNonImmediate(
            $user,
            $identity['key'],
            $identity['fingerprint'],
            $totalNetCents,
            function (?Order $existingOrder) use (
                $request,
                $user,
                $paymentMethod,
                $totalNetCents,
                $isQuoteRequest,
                $appliedCoupon,
                $couponDiscountCents,
                $lineItems,
                $customConditions,
                $withdrawalWaived,
                $quoteTokenPayload,
                $identity,
            ): JsonResponse {
                if ($existingOrder !== null) {
                    return $this->safeNonImmediateResponse(
                        fn (): JsonResponse => $this->respondForExistingNonImmediateOrder($existingOrder, $user),
                        $existingOrder,
                    );
                }

                $order = DB::transaction(function () use ($request, $user, $paymentMethod, $appliedCoupon, $couponDiscountCents, $totalNetCents, $isQuoteRequest, $lineItems, $customConditions, $withdrawalWaived, $quoteTokenPayload, $identity) {
                    if ($quoteTokenPayload !== null) {
                        // Re-read and re-authorize immediately before persistence. A
                        // gallery assignment, brand, expiry, or hidden state may have
                        // changed since the initial token validation above.
                        $this->assertQuotePhotosAuthorized(
                            $user,
                            $quoteTokenPayload['photos'],
                            $quoteTokenPayload['brand'],
                        );
                    }

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
                        $identity['key'],
                        $identity['fingerprint'],
                        1,
                        $request->ip(),
                    );

                    $this->createInvoiceSnapshot($order, $request, $user, $lineItems, $totalNetCents, $customConditions, $withdrawalWaived);

                    return $order;
                });

                return $this->safeNonImmediateResponse(
                    fn (): JsonResponse => $this->respondBasedOnPayment($order, $request, $user, $isQuoteRequest, $paymentMethod, $totalNetCents),
                    $order,
                );
            },
            false,
        );
    }

    /**
     * Resolve, bind, and authorize a quote token before any checkout work.
     *
     * @return array{0: array<string, mixed>, 1: array<string, Photo>}
     */
    private function resolveQuoteToken(mixed $token, $user, mixed $requestItems): array
    {
        if (! is_string($token) || trim($token) === '') {
            throw new HttpResponseException(
                response()->json(['error' => 'Angebot ist abgelaufen oder ungültig.'], 422)
            );
        }

        $currentBrand = BrandRegistry::currentIdOrNull();
        if ($currentBrand === null) {
            throw new HttpResponseException(
                response()->json(['error' => 'Angebot ist abgelaufen oder ungültig.'], 422)
            );
        }

        $payload = app(OfferTokenService::class)->verifyQuote(
            $token,
            $currentBrand,
            requirePositivePrice: false,
        );
        if ($payload === null) {
            throw new HttpResponseException(
                response()->json(['error' => 'Angebot ist abgelaufen oder ungültig.'], 422)
            );
        }
        if ($payload['price'] < 1) {
            throw new HttpResponseException(
                response()->json(['error' => 'Angebot ist ungültig.'], 422)
            );
        }

        $this->assertQuoteItemsMatch($payload['photos'], $requestItems);
        $photos = $this->assertQuotePhotosAuthorized($user, $payload['photos'], $payload['brand']);

        return [$payload, $photos];
    }

    /**
     * The signed offer is the source of truth for the item identity. A client
     * may not substitute a public placeholder for a private token photo (or
     * add/remove photos) while retaining the offer's price.
     *
     * @param  array<int, string>  $tokenPhotoIds
     */
    private function assertQuoteItemsMatch(array $tokenPhotoIds, mixed $requestItems): void
    {
        if (! is_array($requestItems) || $requestItems === []) {
            throw new HttpResponseException(
                response()->json(['error' => 'Angebot und Warenkorb stimmen nicht überein.'], 422)
            );
        }

        $requestPhotoIds = [];
        foreach ($requestItems as $item) {
            if (! is_array($item) || ! isset($item['photoId']) || ! is_string($item['photoId']) || trim($item['photoId']) === '') {
                throw new HttpResponseException(
                    response()->json(['error' => 'Angebot und Warenkorb stimmen nicht überein.'], 422)
                );
            }

            $isQuote = filter_var($item['isQuote'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isQuote === true) {
                throw new HttpResponseException(
                    response()->json(['error' => 'Angebot und Warenkorb stimmen nicht überein.'], 422)
                );
            }

            $requestPhotoIds[] = $item['photoId'];
        }

        if (count($requestPhotoIds) !== count($tokenPhotoIds)
            || count(array_unique($requestPhotoIds)) !== count($requestPhotoIds)
            || count(array_unique($tokenPhotoIds)) !== count($tokenPhotoIds)) {
            throw new HttpResponseException(
                response()->json(['error' => 'Angebot und Warenkorb stimmen nicht überein.'], 422)
            );
        }

        $sortedTokenIds = $tokenPhotoIds;
        $sortedRequestIds = $requestPhotoIds;
        sort($sortedTokenIds);
        sort($sortedRequestIds);
        if ($sortedTokenIds !== $sortedRequestIds) {
            throw new HttpResponseException(
                response()->json(['error' => 'Angebot und Warenkorb stimmen nicht überein.'], 422)
            );
        }
    }

    /**
     * Re-read the token targets and enforce the same access/deliverability
     * rules immediately before a quote order is persisted.
     *
     * @param  array<int, string>  $photoIds
     * @return array<string, Photo>
     */
    private function assertQuotePhotosAuthorized($user, array $photoIds, string $expectedBrand): array
    {
        $activeBrand = BrandRegistry::currentIdOrNull();
        $expectedBrand = trim($expectedBrand);
        if ($activeBrand === null || $activeBrand === '' || $expectedBrand === '' || ! hash_equals($activeBrand, $expectedBrand)) {
            throw new HttpResponseException(
                response()->json(['error' => 'Angebot gehört zu einer anderen Marke.'], 422)
            );
        }

        $ids = [];
        foreach ($photoIds as $photoId) {
            if (! is_string($photoId) || trim($photoId) === '' || strlen($photoId) > 255) {
                throw new HttpResponseException(
                    response()->json(['error' => 'Angebot ist ungültig.'], 422)
                );
            }
            $ids[] = $photoId;
        }

        if ($ids === [] || count($ids) > 500 || count(array_unique($ids)) !== count($ids)) {
            throw new HttpResponseException(
                response()->json(['error' => 'Angebot ist ungültig.'], 422)
            );
        }

        $photos = Photo::with('gallery')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (Photo $photo): string => (string) $photo->getKey());

        if ($photos->count() !== count($ids)) {
            throw new HttpResponseException(
                response()->json(['error' => 'Angebot enthält ein nicht verfügbares Foto.'], 422)
            );
        }

        foreach ($ids as $photoId) {
            /** @var Photo $photo */
            $photo = $photos->get($photoId);
            $gallery = $photo->gallery;
            if ($gallery === null || ! BrandRegistry::galleryTreeMatchesCurrent($gallery)) {
                throw new HttpResponseException(
                    response()->json(['error' => 'Angebot enthält ein nicht verfügbares Foto.'], 422)
                );
            }
            if ($gallery->isSelection()) {
                throw new HttpResponseException(
                    response()->json(['error' => 'Auswahl-Galerien können nicht angeboten werden.'], 422)
                );
            }
            if ($photo->effective_is_hidden || $gallery->effective_is_hidden) {
                throw new HttpResponseException(
                    response()->json(['error' => 'Angebot enthält ein nicht lieferbares Foto.'], 422)
                );
            }
            if ($gallery->expires_at !== null && $gallery->expires_at->isPast()) {
                throw new HttpResponseException(
                    response()->json(['error' => 'Angebot enthält ein nicht lieferbares Foto.'], 422)
                );
            }
            if (! $gallery->effective_is_public && ($user === null || ! $user->canAccessGallery((string) $gallery->getKey()))) {
                throw new HttpResponseException(
                    response()->json(['error' => 'Zugriff verweigert'], 403)
                );
            }
        }

        return $photos->all();
    }

    private function validateItems(array $items, $user): array
    {
        $strategyItems = [];
        $isQuoteRequest = false;

        foreach ($items as $item) {
            $photo = Photo::with('gallery')->findOrFail($item['photoId']);
            $gallery = $photo->gallery;
            if (! $gallery instanceof Gallery || ! BrandRegistry::galleryTreeMatchesCurrent($gallery)) {
                throw new HttpResponseException(response()->json(['error' => 'Foto nicht gefunden.'], 404));
            }
            if ($gallery->isSelection()) {
                throw new HttpResponseException(
                    response()->json(['error' => 'Auswahl-Galerien können nicht bezahlt werden.'], 422)
                );
            }
            if (! $gallery->effective_is_public && ! $user->canAccessGallery($photo->gallery_id)) {
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
        $injectedVolumeFallback = $this->strategy instanceof VolumeLicensingStrategy
            && ! Setting::query()
                ->where('key', 'pricing_strategy')
                ->where('brand', BrandRegistry::currentIdOrNull() ?? BrandRegistry::currentOrDefault()->value)
                ->exists();

        foreach ($strategyItems as $item) {
            $gallery = Gallery::find($item['gallery_id']);
            $mode = $gallery ? $gallery->effective_licensing_mode : 'scope_licensing';

            // A manually injected volume strategy remains the fallback for
            // legacy callers that have not yet created the brand setting. An
            // explicit gallery override, or an existing brand setting, always
            // wins over that compatibility fallback.
            if ($mode === 'scope_licensing'
                && $gallery?->licensing_mode === null
                && $injectedVolumeFallback) {
                $mode = 'volume_licensing';
            }

            // Resolve a null gallery override to the concrete brand-default ID
            // so it shares the retroactive tier with a gallery that explicitly
            // selects that same default preset.
            $presetKey = $mode === 'volume_licensing'
                ? app(VolumePresetService::class)->resolveForGallery($gallery)->id
                : 'default';
            $groups[$mode.'|'.$presetKey][] = $item;
        }

        return $groups;
    }

    /**
     * Return the non-quote items from volume groups. Coupons are validated and
     * applied against this complete eligible set, not against the first item
     * of a cart or each pricing group independently.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectVolumeCouponItems(array $groups): array
    {
        $items = [];
        foreach ($groups as $groupKey => $groupItems) {
            if (! str_starts_with((string) $groupKey, 'volume_licensing')) {
                continue;
            }

            foreach ($groupItems as $item) {
                if (empty($item['is_quote'])) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    private function scopeStrategy(): PricingStrategy
    {
        // The service binding follows the brand default. A gallery-level
        // scope override must not inherit that injected volume strategy.
        if ($this->strategy instanceof VolumeLicensingStrategy) {
            return new ScopeLicensingStrategy;
        }

        return $this->strategy;
    }

    private function strategyForGroup(string $mode, string $presetKey): PricingStrategy
    {
        if ($mode !== 'volume_licensing') {
            return $this->scopeStrategy();
        }

        $presetService = app(VolumePresetService::class);
        $preset = $presetKey === 'default'
            ? $presetService->resolveDefaultForBrand(BrandRegistry::currentOrDefault())
            : VolumePreset::findOrFail($presetKey);

        return new VolumeLicensingStrategy($preset, $this->couponService);
    }

    /**
     * Calculate all effective pricing groups without applying a coupon. The
     * caller applies one validated coupon to the combined volume subtotal.
     */
    private function calculateMultiStrategyCart(array $groups, $user): array
    {
        $allItems = [];
        $totalCents = 0;
        $discountCents = 0;
        $couponId = null;
        $couponType = null;
        $allTierBreakdown = [];
        $volumePricedItems = [];
        $volumeSubtotalCents = 0;
        $volumeItemCount = 0;

        foreach ($groups as $groupKey => $groupItems) {
            [$mode, $presetKey] = explode('|', $groupKey, 2);
            $strategy = $this->strategyForGroup($mode, $presetKey);
            $result = $strategy->calculateCart($groupItems, $user, null);

            $allItems = array_merge($allItems, $result['items']);
            $totalCents += (int) $result['totalCents'];
            $discountCents += (int) ($result['discountCents'] ?? 0);
            if (! empty($result['couponId'])) {
                $couponId = $result['couponId'];
            }
            if (! empty($result['couponType'])) {
                $couponType = $result['couponType'];
            }
            if (! empty($result['tier_breakdown'])) {
                $allTierBreakdown = array_merge($allTierBreakdown, $result['tier_breakdown']);
            }

            if ($mode !== 'volume_licensing') {
                continue;
            }

            $volumeSubtotalCents += (int) $result['totalCents'];
            if (array_key_exists('coupon_items', $result) && is_array($result['coupon_items'])) {
                foreach ($result['coupon_items'] as $couponItem) {
                    $volumePricedItems[] = $couponItem;
                    $volumeItemCount++;
                }

                continue;
            }

            // Compatibility fallback for injected strategy implementations that
            // predate the explicit effective-price coupon representation.
            $quoteItemIds = [];
            foreach ($groupItems as $groupItem) {
                if (! empty($groupItem['is_quote'])) {
                    $quoteItemIds[(string) ($groupItem['id'] ?? '')] = true;
                }
            }
            foreach ($result['items'] as $pricedItem) {
                if (isset($quoteItemIds[(string) $pricedItem['itemId']])) {
                    continue;
                }

                $volumePricedItems[] = $pricedItem;
                $volumeItemCount++;
            }
        }

        return [
            'items' => $allItems,
            'totalCents' => $totalCents,
            'discountCents' => $discountCents,
            'couponId' => $couponId,
            'couponType' => $couponType,
            'tier_breakdown' => $allTierBreakdown,
            'coupon_items' => $volumePricedItems,
            'coupon_subtotal_cents' => $volumeSubtotalCents,
            'coupon_item_count' => $volumeItemCount,
        ];
    }

    private function applyCartCoupon(array $pricingResult, Coupon $coupon): array
    {
        $couponItems = $pricingResult['coupon_items'] ?? [];
        $couponSubtotalCents = (int) ($pricingResult['coupon_subtotal_cents'] ?? 0);
        if ($couponItems === [] || $couponSubtotalCents <= 0) {
            return $pricingResult;
        }

        $applied = $this->couponService->applyCoupon(
            $coupon,
            $couponItems,
            $couponSubtotalCents,
        );

        $pricingResult['totalCents'] = (int) $pricingResult['totalCents']
            - $couponSubtotalCents
            + (int) $applied['totalCents'];
        $pricingResult['discountCents'] = (int) $applied['discountCents'];
        $pricingResult['couponId'] = $coupon->id;
        $pricingResult['couponType'] = $coupon->type;

        return $pricingResult;
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

        $photoIds = [];
        foreach ($items as $item) {
            $isQuote = $item['is_quote'] ?? $item['isQuote'] ?? false;
            if ($isQuote) {
                continue;
            }

            $photoId = $item['photoId'] ?? $item['id'] ?? null;
            if ($photoId !== null && $photoId !== '') {
                $photoIds[] = $photoId;
            }
        }

        $galleryIds = [];
        $metaGalleryIds = [];
        if ($photoIds !== []) {
            $photos = Photo::with('gallery')
                ->whereIn('id', array_values(array_unique($photoIds)))
                ->get();
            foreach ($photos as $photo) {
                if ($photo->gallery_id !== null) {
                    $galleryIds[] = (string) $photo->gallery_id;
                }
                if ($photo->gallery?->gallery_group_id !== null) {
                    $metaGalleryIds[] = (string) $photo->gallery->gallery_group_id;
                }
            }
        }

        [$validCoupon] = $this->couponService->findValidCoupon(
            $couponCode,
            $brand,
            array_values(array_unique($galleryIds)),
            array_values(array_unique($metaGalleryIds)),
            $user->getKey(),
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
            $couponItemCount = (int) ($pricingResult['coupon_item_count'] ?? 0);
            if ($couponItemCount <= 0) {
                $couponItemCount = count(array_filter(
                    $lineItems,
                    fn (array $lineItem): bool => ! empty($lineItem['photoId']) && empty($lineItem['isQuote']),
                ));
            }
            if ($couponItemCount > 0) {
                $perImageCents = (int) round($couponDiscountCents / $couponItemCount);
                $lineItems[] = [
                    'type' => 'discount_coupon',
                    'filename' => 'Coupon-Rabatt',
                    'notes' => sprintf('%d × %s €', $couponItemCount, number_format($perImageCents / 100, 2, ',', '.')),
                    'price' => $perImageCents,
                    'row_total' => -$couponDiscountCents,
                    'qty' => $couponItemCount,
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
            'user_id' => ActorIdentity::registeredId($user),
            'guest_id' => ActorIdentity::guestId($user),
            'status' => $orderStatus,
            'brand' => BrandRegistry::current()?->value ?? BrandRegistry::currentId(),
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
        ?array $quoteTokenPayload = null,
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

        if ($order === null && $quoteTokenPayload !== null) {
            // A lost-key recovery can race with the idempotency transition. If
            // this call has to create the order, rebuild its immutable line
            // items from the signed token instead of accepting the empty arrays
            // used by the replay-only path above.
            $quotePhotos = $this->assertQuotePhotosAuthorized(
                $user,
                $quoteTokenPayload['photos'],
                $quoteTokenPayload['brand'],
            );
            $lineItems = $this->buildQuoteLineItems($quoteTokenPayload, $quotePhotos);
            $customConditions = $quoteTokenPayload['rights_text'] ?? null;
        }

        if ($order === null) {
            $order = DB::transaction(function () use ($request, $user, $appliedCoupon, $couponDiscountCents, $totalNetCents, $isQuoteRequest, $lineItems, $customConditions, $withdrawalWaived, $checkoutIdempotencyKey, $checkoutFingerprint, $quoteTokenPayload) {
                if ($quoteTokenPayload !== null) {
                    $this->assertQuotePhotosAuthorized(
                        $user,
                        $quoteTokenPayload['photos'],
                        $quoteTokenPayload['brand'],
                    );
                }

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
    ): JsonResponse {
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

    /**
     * @param  array<string, Photo>  $photos
     */
    private function buildQuoteLineItems(array $tokenPayload, array $photos): array
    {
        $photoIds = array_values($tokenPayload['photos']);
        $totalPrice = $tokenPayload['price'];
        $count = count($photoIds);
        $baseItemCents = intdiv($totalPrice, $count);
        $remainderCents = $totalPrice % $count;

        $lineItems = [];
        foreach ($photoIds as $index => $photoId) {
            $photo = $photos[$photoId] ?? null;
            if (! $photo instanceof Photo) {
                throw new HttpResponseException(
                    response()->json(['error' => 'Angebot enthält ein nicht verfügbares Foto.'], 422)
                );
            }

            $lineItems[] = [
                'photoId' => $photoId,
                'filename' => $photo->title ?: 'Bild '.substr((string) $photo->getKey(), 0, 8),
                'tier' => 'original',
                'useCaseId' => null,
                'useCaseName' => 'Angebot (Festpreis)',
                'modifierNames' => [],
                // Allocate the remainder to the final line so the immutable
                // snapshot always sums exactly to the signed total.
                'price' => $baseItemCents + ($index === $count - 1 ? $remainderCents : 0),
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

        // Freeze organization ownership at purchase time. Collective invoice
        // generation must not infer this from the user's later org_id value.
        // A delivery-note order must never reach persistence without that
        // immutable attribution; failing here rolls the transaction back.
        $orgId = $org?->getKey() ?? $user->org_id;
        if ($order->status === 'delivery_note'
            && ($orgId === null || trim((string) $orgId) === '')) {
            throw new \LogicException('A delivery-note checkout requires an organization.');
        }

        if ($orgId !== null && trim((string) $orgId) !== '') {
            $customerDetails[InvoiceSnapshot::PURCHASE_ORG_ID_KEY] = (string) $orgId;
        }

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

    private function safeNonImmediateResponse(callable $responder, ?Order $order): JsonResponse
    {
        try {
            return $responder();
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Non-immediate checkout response failed after order claim', [
                'order_id' => $order?->id,
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'error' => 'Der Checkout konnte nicht abgeschlossen werden. Bitte versuche es erneut.',
            ], 503);
        }
    }

    /**
     * Finalize an exact-key claim found before server-side coupon revalidation.
     * The persisted order state, rather than the current request's mutable
     * flags, is authoritative for the response and the durable mail-enqueue
     * side effect.
     */
    private function respondForExistingNonImmediateOrder(Order $order, User $user): JsonResponse
    {
        // Re-read the durable state before any mail side effect. An admin may
        // have cancelled, archived, or otherwise closed the claim after the
        // identity lookup; a stale model must not turn that terminal state into
        // a successful replay.
        $order->refresh();
        $order->loadMissing('invoiceSnapshot');
        $snapshot = $order->invoiceSnapshot;
        if ($snapshot === null) {
            Log::warning('Non-immediate checkout replay has no invoice snapshot', [
                'order_id' => $order->id,
            ]);

            return response()->json([
                'error' => 'Der Checkout konnte nicht abgeschlossen werden. Bitte versuche es erneut.',
            ], 503);
        }

        if ((bool) $order->is_quote_request) {
            if ($order->status !== 'pending') {
                return $this->checkoutStateConflict('Dieser Checkout-Versuch ist bereits abgeschlossen. Bitte aktualisiere den Warenkorb und versuche es erneut.');
            }

            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'invoice_number' => $snapshot->invoice_number,
            ]);
        }

        if ((int) $order->total_amount === 0) {
            return $this->respondForSettledFreeOrder($order, $user);
        }

        if (! in_array($order->status, ['invoice_created', 'delivery_note', 'paid'], true)) {
            return $this->checkoutStateConflict('Dieser Checkout-Versuch ist bereits abgeschlossen. Bitte aktualisiere den Warenkorb und versuche es erneut.');
        }

        $this->queueInvoiceMailOnce($order, $user);

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'invoice_number' => $snapshot->invoice_number,
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
            $this->queueInvoiceMailOnce($order, $user);

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
        $settled = Order::query()
            ->whereKey($order->getKey())
            ->whereIn('status', ['pending_payment', 'invoice_created'])
            ->update(['status' => 'paid']);
        if ($settled === 1) {
            $order->status = 'paid';
        } else {
            // A concurrent admin transition (for example to cancelled or
            // delivery_note) wins; never reopen that terminal/collective state.
            $order->refresh();
            if (! in_array($order->status, ['paid', 'delivery_note'], true)) {
                return $this->checkoutStateConflict('Dieser Checkout-Versuch ist bereits abgeschlossen. Bitte aktualisiere den Warenkorb und versuche es erneut.');
            }
        }

        $snapshot = $order->invoiceSnapshot;

        $this->queueInvoiceMailOnce($order, $user);

        return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
    }

    /**
     * Queue the checkout invoice through the durable snapshot claim.
     *
     * The dispatcher writes its non-expiring marker and the queue insert in
     * one database transaction. A retry after a queue failure can therefore
     * try again, while cache eviction, a crash after queueing, or a marker
     * failure cannot create a second enqueue. This is not an exactly-once SMTP
     * delivery guarantee: worker retries and terminal failed_jobs handling are
     * deliberately separate operational concerns.
     */
    private function queueInvoiceMailOnce(Order $order, User $user): void
    {
        $this->invoiceMailDispatcher->queueOnce($order, $user);
    }
}
