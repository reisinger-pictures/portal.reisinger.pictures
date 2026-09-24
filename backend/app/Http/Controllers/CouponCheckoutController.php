<?php

namespace App\Http\Controllers;

use App\Services\CouponService;
use App\Support\ActorIdentity;
use App\Support\BrandRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CouponCheckoutController extends Controller
{
    private CouponService $couponService;

    public function __construct(CouponService $couponService)
    {
        $this->couponService = $couponService;
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $galleryIds = $request->input('gallery_id');
        $metaGalleryIds = $request->input('meta_gallery_id');
        $scopeRules = static function (mixed $scopeIds): array {
            return is_array($scopeIds)
                ? ['nullable', 'array', 'max:100']
                : ['nullable', 'string', 'max:36'];
        };

        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50',
            'gallery_id' => $scopeRules($galleryIds),
            'gallery_id.*' => 'string|max:36',
            'meta_gallery_id' => $scopeRules($metaGalleryIds),
            'meta_gallery_id.*' => 'string|max:36',
        ]);

        if ($validator->fails()) {
            return response()->json(['valid' => false, 'error' => $validator->errors()->first()], 422);
        }

        $brandId = BrandRegistry::currentId();
        // Guests deliberately have no account-scoped coupon entitlement. Use
        // the canonical actor identity instead of a raw null auth identifier.
        $userId = ActorIdentity::registeredId(auth('api')->user());

        [$coupon, $error] = $this->couponService->findValidCoupon(
            $request->input('code'),
            $brandId,
            $request->input('gallery_id'),
            $request->input('meta_gallery_id'),
            $userId,
        );

        if ($coupon === null) {
            return response()->json(['valid' => false, 'error' => $error]);
        }

        $sampleTotalCents = 10000;
        $sampleItems = [['priceCents' => $sampleTotalCents, 'itemId' => 'sample']];
        $result = $this->couponService->applyCoupon($coupon, $sampleItems, $sampleTotalCents);

        return response()->json([
            'valid' => true,
            'coupon' => [
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => $coupon->value,
                'max_items' => $coupon->max_items,
                'package_quantity' => $coupon->package_quantity,
                'package_price_cents' => $coupon->package_price_cents,
            ],
            'discount_cents' => $result['discountCents'],
        ]);
    }
}
