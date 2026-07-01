<?php

namespace App\Http\Controllers;

use App\Enums\Brand;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CouponService;
use App\Support\BrandRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin CRUD + public validation for coupons (SRP-01).
 *
 * Role-based access:
 *  - super_admin: full CRUD, brand-filtered via host (C-1)
 *  - admin: brand-bound CRUD (own brand only)
 *  - photographer: own coupons only, restricted fields
 *
 * @see features/ecommerce/08-srp-coupon-system.md
 */
class CouponController extends Controller
{
    private CouponService $couponService;

    public function __construct(CouponService $couponService)
    {
        $this->couponService = $couponService;
    }

    // ──────────────────────────────────────────────
    //  Role-Based Authorization Helper
    // ──────────────────────────────────────────────

    /**
     * Authorize that the current user can act on the given coupon.
     *
     *  - super_admin: always allowed
     *  - admin: only if coupon brand matches current brand context
     *  - photographer: only if coupon was created by this user
     */
    private function authorizeCoupon(Coupon $coupon): void
    {
        $user = auth()->user();

        if ($user->is_super_admin) {
            return;
        }

        if ($user->is_admin) {
            if ($coupon->brand->value !== BrandRegistry::currentOrDefault()->value) {
                abort(403, 'Forbidden');
            }
            return;
        }

        if ($user->is_photographer) {
            if ($coupon->created_by !== $user->id) {
                abort(403, 'Forbidden');
            }
            return;
        }

        abort(403, 'Forbidden');
    }

    // ──────────────────────────────────────────────
    //  Admin: List (paginated, role-filtered)
    // ──────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = Coupon::forCurrentBrand()
            ->orderBy('created_at', 'desc');

        // Photographer sees only their own coupons
        if ($user->is_photographer && !$user->is_super_admin && !$user->is_admin) {
            $query->where('coupons.created_by', $user->getKey());
        }

        $coupons = $query->paginate($perPage);

        return response()->json($coupons);
    }

    // ──────────────────────────────────────────────
    //  Admin: Create (role-aware field restrictions)
    // ──────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $this->validateCouponData($request, null, $user);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $validated['brand'] = BrandRegistry::currentOrDefault()->value;

        // Photographer restrictions
        if ($user->is_photographer && !$user->is_super_admin && !$user->is_admin) {
            $validated['created_by'] = $user->id;
            unset($validated['max_uses_global']);
            $validated['active'] = true;
        }

        $coupon = Coupon::create($validated);

        return response()->json(['success' => true, 'coupon' => $coupon], 201);
    }

    // ──────────────────────────────────────────────
    //  Admin: Update (role-aware)
    // ──────────────────────────────────────────────

    public function update(Request $request, string $id): JsonResponse
    {
        $coupon = Coupon::forCurrentBrand()->findOrFail($id);
        $this->authorizeCoupon($coupon);

        $user = auth()->user();
        $validated = $this->validateCouponData($request, $coupon, $user);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        // Photographer restrictions
        if ($user->is_photographer && !$user->is_super_admin && !$user->is_admin) {
            unset($validated['max_uses_global']);
            $validated['active'] = true;
        }

        $coupon->update($validated);

        return response()->json(['success' => true, 'coupon' => $coupon]);
    }

    // ──────────────────────────────────────────────
    //  Admin: Delete (role-aware, used_count check)
    // ──────────────────────────────────────────────

    public function destroy(string $id): JsonResponse
    {
        $coupon = Coupon::forCurrentBrand()->findOrFail($id);
        $this->authorizeCoupon($coupon);

        $user = auth()->user();

        // super_admin and admin can always delete; others only if unused
        if (!$user->is_super_admin && !$user->is_admin && $coupon->used_count > 0) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot delete a coupon that has already been used.',
            ], 422);
        }

        $coupon->delete();

        return response()->json(['success' => true]);
    }

    // ──────────────────────────────────────────────
    //  Gallery Coupons: List + Create
    // ──────────────────────────────────────────────

    /**
     * List coupons scoped to a specific gallery.
     */
    public function galleryCoupons(Request $request, string $galleryId): JsonResponse
    {
        $gallery = $this->findAndVerifyGallery($galleryId);

        $perPage = min((int) $request->query('per_page', 20), 100);

        // Photographers who have access to this gallery via photographer_galleries pivot
        $photographerIds = \Illuminate\Support\Facades\DB::table('photographer_galleries')
            ->where('gallery_id', $galleryId)
            ->pluck('user_id')
            ->toArray();

        $query = Coupon::forCurrentBrand()
            ->where(function ($q) use ($galleryId, $photographerIds) {
                $q->where(function ($sq) use ($galleryId) {
                    $sq->where('scope_type', 'gallery')
                       ->where('scope_id', $galleryId);
                })->orWhere(function ($sq) use ($galleryId) {
                    $sq->where('scope_type', 'meta_gallery')
                       ->where('scope_gallery_id', $galleryId);
                })->orWhere(function ($sq) use ($photographerIds) {
                    if (!empty($photographerIds)) {
                        $sq->where('scope_type', 'photographer')
                           ->whereIn('created_by', $photographerIds);
                    }
                });
            })
            ->orderBy('created_at', 'desc');

        $coupons = $query->paginate($perPage);

        return response()->json($coupons);
    }

    /**
     * Create a coupon pre-scoped to a specific gallery.
     */
    public function storeGalleryCoupon(Request $request, string $galleryId): JsonResponse
    {
        $gallery = $this->findAndVerifyGallery($galleryId);

        $user = auth()->user();
        $mergedData = array_merge($request->all(), [
            'scope_type' => 'gallery',
            'scope_id' => $galleryId,
        ]);
        $mergedRequest = new Request($mergedData);

        $validated = $this->validateCouponData($mergedRequest, null, $user);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $validated['brand'] = BrandRegistry::currentOrDefault()->value;
        $validated['scope_type'] = 'gallery';
        $validated['scope_id'] = $galleryId;

        if ($user->is_photographer && !$user->is_super_admin && !$user->is_admin) {
            $validated['created_by'] = $user->id;
            unset($validated['max_uses_global']);
            $validated['active'] = true;
        }

        $coupon = Coupon::create($validated);

        return response()->json(['success' => true, 'coupon' => $coupon], 201);
    }

    // ──────────────────────────────────────────────
    //  GalleryGroup Coupons: List + Create
    // ──────────────────────────────────────────────

    /**
     * List coupons scoped to a specific gallery group.
     */
    public function groupCoupons(Request $request, string $groupId): JsonResponse
    {
        $group = GalleryGroup::findOrFail($groupId);
        $brand = BrandRegistry::currentOrDefault();

        if ($group->brand !== null && $group->brand->value !== $brand->value) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        $galleryIds = $group->galleries()->pluck('galleries.id')->toArray();
        $photographerIds = User::whereHas('photographerGalleryGroups', function ($q) use ($groupId) {
            $q->where('gallery_groups.id', $groupId);
        })->pluck('users.id')->toArray();

        $query = Coupon::forCurrentBrand()
            ->where(function ($q) use ($groupId, $galleryIds, $photographerIds) {
                $q->where(function ($sq) use ($groupId) {
                    $sq->where('scope_type', 'meta_gallery')
                       ->where('scope_id', $groupId);
                })->orWhere(function ($sq) use ($galleryIds) {
                    if (!empty($galleryIds)) {
                        $sq->where('scope_type', 'gallery')
                           ->whereIn('scope_id', $galleryIds);
                    }
                })->orWhere(function ($sq) use ($photographerIds) {
                    if (!empty($photographerIds)) {
                        $sq->where('scope_type', 'photographer')
                           ->whereIn('created_by', $photographerIds);
                    }
                });
            })
            ->orderBy('created_at', 'desc');

        $coupons = $query->paginate($perPage);

        return response()->json($coupons);
    }

    /**
     * Create a coupon pre-scoped to a specific gallery group.
     */
    public function storeGroupCoupon(Request $request, string $groupId): JsonResponse
    {
        $group = GalleryGroup::findOrFail($groupId);
        $brand = BrandRegistry::currentOrDefault();

        if ($group->brand !== null && $group->brand->value !== $brand->value) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        $user = auth()->user();
        $mergedData = array_merge($request->all(), [
            'scope_type' => 'meta_gallery',
            'scope_id' => $groupId,
        ]);
        $mergedRequest = new Request($mergedData);

        $validated = $this->validateCouponData($mergedRequest, null, $user);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $validated['brand'] = BrandRegistry::currentOrDefault()->value;
        $validated['scope_type'] = 'meta_gallery';
        $validated['scope_id'] = $groupId;

        if ($user->is_photographer && !$user->is_super_admin && !$user->is_admin) {
            $validated['created_by'] = $user->id;
            unset($validated['max_uses_global']);
            $validated['active'] = true;
        }

        $coupon = Coupon::create($validated);

        return response()->json(['success' => true, 'coupon' => $coupon], 201);
    }

    // ──────────────────────────────────────────────
    //  Public: Validate coupon (for frontend preview)
    // ──────────────────────────────────────────────

    public function validateCoupon(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50',
            'gallery_id' => 'nullable|integer',
            'meta_gallery_id' => 'nullable|integer',
            'scope_gallery_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['valid' => false, 'error' => $validator->errors()->first()], 422);
        }

        $brand = BrandRegistry::current();
        if ($brand === null) {
            return response()->json(['valid' => false, 'error' => 'No brand context available.'], 400);
        }

        $userId = auth()->id();

        [$coupon, $error] = $this->couponService->findValidCoupon(
            $request->input('code'),
            $brand,
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
                'id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => $coupon->value,
                'scope_type' => $coupon->scope_type,
            ],
            'discount_cents' => $result['discountCents'],
        ]);
    }

    // ──────────────────────────────────────────────
    //  Shared Validation
    // ──────────────────────────────────────────────

    /**
     * Validate coupon input data for store/update.
     *
     * Role-aware: photographers have restricted scope types and forbidden fields.
     *
     * @param  Request       $request   Input data
     * @param  Coupon|null   $existing  Existing coupon (null for create)
     * @param  User|null     $user      Acting user (defaults to auth()->user())
     * @return array|JsonResponse       Validated array on success, JsonResponse on failure.
     */
    private function validateCouponData(Request $request, ?Coupon $existing = null, ?User $user = null): array|JsonResponse
    {
        $user = $user ?? auth()->user();
        $isPhotographer = $user->is_photographer && !$user->is_super_admin && !$user->is_admin;

        $scopeTypes = $isPhotographer
            ? 'in:gallery,meta_gallery,photographer'
            : 'in:global,gallery,meta_gallery,photographer,organisation';

        $rules = [
            'code' => 'required|string|max:50',
            'type' => 'required|string|in:fixed,percentage,free_items',
            'value' => 'required|numeric|min:0|max:9999999.99',
            'scope_type' => 'required|string|' . $scopeTypes,
            'scope_id' => 'nullable|string|required_if:scope_type,gallery,meta_gallery',
            'scope_gallery_id' => 'nullable|string',
            'max_uses_global' => 'nullable|integer|min:1',
            'max_uses_per_account' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date',
            'active' => 'boolean',
        ];

        // Photographer cannot set max_uses_global
        if ($isPhotographer) {
            $rules['max_uses_global'] = 'prohibited';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'error' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        // For percentage, ensure value is 0-100
        if ($data['type'] === 'percentage' && $data['value'] > 100) {
            return response()->json(['success' => false, 'error' => 'Percentage value must not exceed 100.'], 422);
        }

        // For free_items, ensure value is integer
        if ($data['type'] === 'free_items') {
            $data['value'] = (int) $data['value'];
        }

        // Unique code check within brand
        $brandValue = BrandRegistry::currentOrDefault()->value;
        $uniqueQuery = Coupon::where('brand', $brandValue)->where('code', $data['code']);
        if ($existing !== null) {
            $uniqueQuery->where('id', '!=', $existing->id);
        }
        if ($uniqueQuery->exists()) {
            return response()->json(['success' => false, 'error' => 'A coupon with this code already exists for this brand.'], 422);
        }

        // Validate organisation scope: tenant must exist in current brand
        if (isset($data['scope_type']) && $data['scope_type'] === 'organisation') {
            $tenantExists = Tenant::where('id', $data['scope_id'])->where('brand', $brandValue)->exists();
            if (!$tenantExists) {
                return response()->json(['success' => false, 'error' => 'Tenant not found for this brand.'], 422);
            }
        }

        return $data;
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * Find a gallery and verify it belongs to the current brand.
     */
    private function findAndVerifyGallery(string $galleryId): Gallery
    {
        $gallery = Gallery::findOrFail($galleryId);
        $brand = BrandRegistry::currentOrDefault();

        if ($gallery->brand !== null && $gallery->brand->value !== $brand->value) {
            abort(404, 'Not found.');
        }

        return $gallery;
    }
}
