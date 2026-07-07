<?php

namespace App\Models;

use App\Constants\TierRanks;
use App\Enums\Brand;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasUuids;

    public $guest_id = null;
    public $transient_galleries = [];
    public $transient_meta_galleries = [];

    public function getIsSuperAdminAttribute(): bool
    {
        return $this->roles()->where('name', UserRole::SUPER_ADMIN->value)->exists();
    }

    protected $visible = [
        'id', 'name', 'email', 'brand', 'billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city', 'metadata_copyright', 'can_edit_metadata', 'flatrate_level',
        'current_ftp_gallery_id', 'ftp_slug',
        'created_at', 'is_admin', 'is_photographer',
        'is_pending', 'is_org_admin', 'is_customer_manager', 'is_power_user', 'is_super_admin', 'roles', 'galleryGroups', 'galleries', 'currentFtpGallery'
    ];

    protected $fillable = [
        'name', 'email', 'password', 'brand', 'metadata_copyright', 'can_edit_metadata', 'flatrate_level',
        'can_purchase_upgrades', 'current_ftp_gallery_id', 'ftp_slug', 'org_id',
        'billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city'
    ];

    protected static function booted()
    {
        static::creating(function ($user) {
            if (empty($user->ftp_slug) && !empty($user->email)) {
                $baseSlug = \Illuminate\Support\Str::slug(explode('@', $user->email)[0]);
                $ftpSlug = $baseSlug;
                $counter = 1;
                while (static::where('ftp_slug', $ftpSlug)->exists()) {
                    $ftpSlug = $baseSlug . $counter;
                    $counter++;
                }
                $user->ftp_slug = $ftpSlug;
            }
        });
    }

    protected $casts = [
        'can_edit_metadata' => 'boolean',
        'brand' => Brand::class,
    ];

    public function getJWTIdentifier() { return $this->getKey(); }
    public function getJWTCustomClaims() { return []; }

    public function roles() { return $this->belongsToMany(Role::class, 'user_roles'); }
    public function galleryGroups() { return $this->belongsToMany(GalleryGroup::class, 'user_gallery_groups')->withPivot('wants_notifications'); }
    public function galleries() { return $this->belongsToMany(Gallery::class, 'user_galleries')->withPivot('wants_notifications'); }
    public function photographerGalleries() { return $this->belongsToMany(Gallery::class, 'photographer_galleries'); }
    public function photographerGalleryGroups() { return $this->belongsToMany(GalleryGroup::class, 'photographer_gallery_groups'); }
    public function currentFtpGallery() { return $this->belongsTo(Gallery::class, 'current_ftp_gallery_id'); }
    public function photos() { return $this->hasMany(Photo::class); }
    public function org(): BelongsTo { return $this->belongsTo(Org::class); }

    public function scopeByOrg(Builder $query, string $orgId): Builder
    {
        return $query->where('org_id', $orgId);
    }

    public function getIsPendingAttribute(): bool
    {
        if ($this->guest_id) return false;
        return $this->roles()->count() === 0 && $this->galleryGroups()->count() === 0 && $this->galleries()->count() === 0;
    }

    public function getIsPhotographerAttribute(): bool { return $this->roles()->where('name', UserRole::PHOTOGRAPHER->value)->exists(); }
    public function getIsAdminAttribute(): bool { return $this->roles()->whereIn('name', [UserRole::ADMIN->value, UserRole::SUPER_ADMIN->value])->exists(); }
    public function getIsOrgAdminAttribute(): bool { return $this->roles()->where('name', UserRole::ORG_ADMIN->value)->exists() && $this->org_id !== null; }

    /** @deprecated Use is_org_admin instead. */
    public function getIsCustomerManagerAttribute(): bool { return $this->getIsOrgAdminAttribute(); }
    public function getIsPowerUserAttribute(): bool { return $this->roles()->where('name', UserRole::POWER_USER->value)->exists(); }

    public function getAllowedGalleryIds(): array
    {
        return app(\App\Services\AccessControlService::class)->getAllowedGalleryIds($this);
    }

    public function canPhotographerAccessGallery($galleryId): bool
    {
        if ($this->is_super_admin) return true;
        if (!$this->is_photographer) return false;

        $gallery = Gallery::find($galleryId);
        if (!$gallery) return false;

        if (!$gallery->effective_restricted_photographers) return true;

        if ($this->photographerGalleries()->where('galleries.id', $galleryId)->exists()) return true;

        $groupIds = $this->photographerGalleryGroups()->pluck('gallery_groups.id')->toArray();
        if (!empty($groupIds)) {
            $allGroupIds = app(\App\Services\AccessControlService::class)->getSubGroupIds($groupIds);
            if (in_array($gallery->gallery_group_id, $allGroupIds)) return true;
        }

        return false;
    }

    public function canAccessGallery($galleryId): bool
    {
        if ($this->is_super_admin) return true;

        if ($this->is_photographer && $this->canPhotographerAccessGallery($galleryId)) {
            return true;
        }

        return in_array($galleryId, $this->getAllowedGalleryIds());
    }

    public function hasPurchasedPhoto($photoId, $requestedTier): bool
    {
        $cacheKey = "user.{$this->id}.purchased.{$photoId}.{$requestedTier}";
        $cached = cache()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $orders = \App\Models\Order::where('user_id', $this->id)
            ->whereNotIn('status', ['disputed', 'refunded', 'cancelled'])
            ->where(function ($q) {
                $q->where('is_quote_request', false)
                    ->orWhere('status', '!=', 'pending');
            })->with('invoiceSnapshot')->get();
        $reqRank = TierRanks::RANKS[$requestedTier] ?? 3;

        foreach ($orders as $order) {
            $snapshot = $order->invoiceSnapshot;
            if (!$snapshot) continue;

            $items = $snapshot->customer_details['items'] ?? [];
            foreach ($items as $item) {
                if (($item['photoId'] ?? '') === $photoId) {
                    $itemRank = TierRanks::RANKS[$item['tier'] ?? 'none'] ?? 0;
                    if ($itemRank >= $reqRank) {
                        cache()->put($cacheKey, true, 3600);
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
