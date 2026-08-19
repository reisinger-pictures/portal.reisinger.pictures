<?php

namespace App\Http\Controllers;

use App\Enums\Brand;
use App\Http\Requests\CouponStoreRequest;
use App\Http\Requests\CouponUpdateRequest;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\CouponService;
use App\Support\BrandRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CouponAdminController extends Controller
{
    private CouponService $couponService;

    public function __construct(CouponService $couponService)
    {
        $this->couponService = $couponService;
    }

    // ──────────────────────────────────────────────
    //  Role-Based Authorization Helper
    // ──────────────────────────────────────────────

    private function authorizeCoupon(Coupon $coupon): void
    {
        $svc = app(AuthorizationService::class);
        $user = auth()->user();

        if ($svc->isSuperAdmin($user)) {
            return;
        }

        if ($svc->isAdmin($user)) {
            $couponBrandValue = $coupon->brand instanceof Brand ? $coupon->brand->value : $coupon->brand;
            if ($couponBrandValue !== BrandRegistry::currentId()) {
                abort(403, 'Forbidden');
            }

            return;
        }

        if ($svc->isPhotographer($user)) {
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
        $svc = app(AuthorizationService::class);
        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = Coupon::forCurrentBrand()
            ->orderBy('created_at', 'desc');

        if ($svc->isPhotographer($user) && ! $svc->isSuperAdmin($user) && ! $svc->isAdmin($user)) {
            $query->where('coupons.created_by', $user->getKey());
        }

        $coupons = $query->paginate($perPage);

        return CouponResource::collection($coupons)->response();
    }

    // ──────────────────────────────────────────────
    //  Admin: Create (role-aware field restrictions)
    // ──────────────────────────────────────────────

    /**
     * Normalize coupon input before persistence.
     *
     * - For `photo_package` coupons, `value` / `max_items` are unused (the
     *   `value` column is NOT NULL, so default it to 0) and the Euro-based
     *   `package_price_cents` field (sent by the frontend as a flat price in €)
     *   is mapped to its integer cents representation (Stripe-conform).
     */
    private function normalizePackagePrice(array $validated): array
    {
        if (($validated['type'] ?? null) === 'photo_package') {
            if (! isset($validated['value']) || $validated['value'] === '') {
                $validated['value'] = 0;
            }
            $validated['max_items'] = null;

            if (array_key_exists('package_price_cents', $validated) && $validated['package_price_cents'] !== null) {
                $validated['package_price_cents'] = (int) round((float) $validated['package_price_cents'] * 100);
            }
        }

        return $validated;
    }

    public function store(CouponStoreRequest $request): JsonResponse
    {
        $user = auth()->user();
        $svc = app(AuthorizationService::class);
        $validated = $this->normalizePackagePrice($request->validated());

        $validated['brand'] = BrandRegistry::currentId();

        if ($svc->isPhotographer($user) && ! $svc->isSuperAdmin($user) && ! $svc->isAdmin($user)) {
            $validated['created_by'] = $user->id;
            unset($validated['max_uses_global']);
            $validated['active'] = true;
        }

        $coupon = Coupon::create($validated);

        return response()->json(['success' => true, 'coupon' => new CouponResource($coupon)], 201);
    }

    // ──────────────────────────────────────────────
    //  Admin: Update (role-aware)
    // ──────────────────────────────────────────────

    public function update(CouponUpdateRequest $request, string $id): JsonResponse
    {
        $coupon = Coupon::forCurrentBrand()->findOrFail($id);
        $this->authorizeCoupon($coupon);

        $user = auth()->user();
        $svc = app(AuthorizationService::class);
        $validated = $this->normalizePackagePrice($request->validated());

        if ($svc->isPhotographer($user) && ! $svc->isSuperAdmin($user) && ! $svc->isAdmin($user)) {
            unset($validated['max_uses_global']);
            $validated['active'] = true;
        }

        $coupon->update($validated);

        return response()->json(['success' => true, 'coupon' => new CouponResource($coupon)]);
    }

    // ──────────────────────────────────────────────
    //  Admin: Delete (role-aware, used_count check)
    // ──────────────────────────────────────────────

    public function destroy(string $id): JsonResponse
    {
        $coupon = Coupon::forCurrentBrand()->findOrFail($id);
        $this->authorizeCoupon($coupon);

        $user = auth()->user();
        $svc = app(AuthorizationService::class);

        if (! $svc->isSuperAdmin($user) && ! $svc->isAdmin($user) && $coupon->used_count > 0) {
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

    public function galleryCoupons(Request $request, string $galleryId): JsonResponse
    {
        $gallery = $this->findAndVerifyGallery($galleryId);

        $perPage = min((int) $request->query('per_page', 20), 100);

        $photographerIds = DB::table('photographer_galleries')
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
                        ->where('scope_id', $galleryId);
                })->orWhere(function ($sq) use ($photographerIds) {
                    if (! empty($photographerIds)) {
                        $sq->where('scope_type', 'photographer')
                            ->whereIn('created_by', $photographerIds);
                    }
                });
            })
            ->orderBy('created_at', 'desc');

        $coupons = $query->paginate($perPage);
        $coupons->getCollection()->transform(fn ($c) => new CouponResource($c));

        return response()->json($coupons);
    }

    public function storeGalleryCoupon(CouponStoreRequest $request, string $galleryId): JsonResponse
    {
        $gallery = $this->findAndVerifyGallery($galleryId);

        $user = auth()->user();
        $svc = app(AuthorizationService::class);
        $validated = $this->normalizePackagePrice($request->validated());

        $validated['brand'] = BrandRegistry::currentId();
        $validated['scope_type'] = 'gallery';
        $validated['scope_id'] = $galleryId;

        if ($svc->isPhotographer($user) && ! $svc->isSuperAdmin($user) && ! $svc->isAdmin($user)) {
            $validated['created_by'] = $user->id;
            unset($validated['max_uses_global']);
            $validated['active'] = true;
        }

        $coupon = Coupon::create($validated);

        return response()->json(['success' => true, 'coupon' => new CouponResource($coupon)], 201);
    }

    // ──────────────────────────────────────────────
    //  GalleryGroup Coupons: List + Create
    // ──────────────────────────────────────────────

    public function groupCoupons(Request $request, string $groupId): JsonResponse
    {
        $group = GalleryGroup::findOrFail($groupId);
        $brand = BrandRegistry::currentOrDefault();

        $groupBrandValue = $group->brand instanceof Brand ? $group->brand->value : $group->brand;
        if ($group->brand !== null && $groupBrandValue !== $brand->value) {
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
                    if (! empty($galleryIds)) {
                        $sq->where('scope_type', 'gallery')
                            ->whereIn('scope_id', $galleryIds);
                    }
                })->orWhere(function ($sq) use ($photographerIds) {
                    if (! empty($photographerIds)) {
                        $sq->where('scope_type', 'photographer')
                            ->whereIn('created_by', $photographerIds);
                    }
                });
            })
            ->orderBy('created_at', 'desc');

        $coupons = $query->paginate($perPage);
        $coupons->getCollection()->transform(fn ($c) => new CouponResource($c));

        return response()->json($coupons);
    }

    public function storeGroupCoupon(CouponStoreRequest $request, string $groupId): JsonResponse
    {
        $group = GalleryGroup::findOrFail($groupId);
        $brand = BrandRegistry::currentOrDefault();

        $groupBrandValue = $group->brand instanceof Brand ? $group->brand->value : $group->brand;
        if ($group->brand !== null && $groupBrandValue !== $brand->value) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        $user = auth()->user();
        $svc = app(AuthorizationService::class);
        $validated = $this->normalizePackagePrice($request->validated());

        $validated['brand'] = BrandRegistry::currentId();
        $validated['scope_type'] = 'meta_gallery';
        $validated['scope_id'] = $groupId;

        if ($svc->isPhotographer($user) && ! $svc->isSuperAdmin($user) && ! $svc->isAdmin($user)) {
            $validated['created_by'] = $user->id;
            unset($validated['max_uses_global']);
            $validated['active'] = true;
        }

        $coupon = Coupon::create($validated);

        return response()->json(['success' => true, 'coupon' => new CouponResource($coupon)], 201);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    private function findAndVerifyGallery(string $galleryId): Gallery
    {
        $gallery = Gallery::findOrFail($galleryId);
        $brand = BrandRegistry::currentOrDefault();

        $galleryBrandValue = $gallery->brand instanceof Brand ? $gallery->brand->value : $gallery->brand;
        if ($gallery->brand !== null && $galleryBrandValue !== $brand->value) {
            abort(404, 'Not found.');
        }

        return $gallery;
    }
}
