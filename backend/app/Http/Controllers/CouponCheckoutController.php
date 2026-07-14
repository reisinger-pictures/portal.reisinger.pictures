<?php

namespace App\Http\Controllers;

use App\Services\CouponService;
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
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50',
            'gallery_id' => 'nullable|string',
            'meta_gallery_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['valid' => false, 'error' => $validator->errors()->first()], 422);
        }

        $brandId = BrandRegistry::currentId();
        $userId = auth()->id();

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
            ],
            'discount_cents' => $result['discountCents'],
        ]);
    }
}
