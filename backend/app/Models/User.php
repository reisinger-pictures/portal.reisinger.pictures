<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Constants\TierRanks;
use App\Services\AuthorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, HasUuids, Notifiable;

    public $guest_id = null;

    public $transient_galleries = [];

    public $transient_meta_galleries = [];

    public function getIsSuperAdminAttribute(): bool
    {
        return app(AuthorizationService::class)->isSuperAdmin($this);
    }

    protected $visible = [
        'id', 'name', 'email', 'brand', 'billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city', 'metadata_copyright', 'can_edit_metadata', 'flatrate_level',
        'current_ftp_gallery_id', 'ftp_slug',
        'created_at', 'is_admin', 'is_photographer',
        'is_pending', 'is_org_admin', 'is_power_user', 'is_super_admin', 'roles', 'galleryGroups', 'galleries', 'currentFtpGallery',
    ];

    protected $fillable = [
        'name', 'email', 'password', 'brand', 'metadata_copyright', 'can_edit_metadata', 'flatrate_level',
        'can_purchase_upgrades', 'current_ftp_gallery_id', 'ftp_slug', 'org_id',
        'billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city',
    ];

    protected static function booted()
    {
        static::creating(function ($user) {
            if (empty($user->ftp_slug) && ! empty($user->email)) {
                $baseSlug = Str::slug(explode('@', $user->email)[0]);
                $ftpSlug = $baseSlug;
                $counter = 1;
                while (static::where('ftp_slug', $ftpSlug)->exists()) {
                    $ftpSlug = $baseSlug.$counter;
                    $counter++;
                }
                $user->ftp_slug = $ftpSlug;
            }
        });
    }

    protected $casts = [
        'can_edit_metadata' => 'boolean',
        'brand' => AsBrand::class,
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function galleryGroups()
    {
        return $this->belongsToMany(GalleryGroup::class, 'user_gallery_groups')->withPivot('wants_notifications');
    }

    public function galleries()
    {
        return $this->belongsToMany(Gallery::class, 'user_galleries')->withPivot('wants_notifications');
    }

    public function photographerGalleries()
    {
        return $this->belongsToMany(Gallery::class, 'photographer_galleries');
    }

    public function photographerGalleryGroups()
    {
        return $this->belongsToMany(GalleryGroup::class, 'photographer_gallery_groups');
    }

    public function currentFtpGallery()
    {
        return $this->belongsTo(Gallery::class, 'current_ftp_gallery_id');
    }

    public function photos()
    {
        return $this->hasMany(Photo::class);
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function scopeByOrg(Builder $query, string $orgId): Builder
    {
        return $query->where('org_id', $orgId);
    }

    public function getIsPendingAttribute(): bool
    {
        return app(AuthorizationService::class)->isPending($this);
    }

    public function getIsPhotographerAttribute(): bool
    {
        return app(AuthorizationService::class)->isPhotographer($this);
    }

    public function getIsAdminAttribute(): bool
    {
        return app(AuthorizationService::class)->isAdmin($this);
    }

    public function getIsOrgAdminAttribute(): bool
    {
        return app(AuthorizationService::class)->isOrgAdmin($this);
    }

    public function getIsPowerUserAttribute(): bool
    {
        return app(AuthorizationService::class)->isPowerUser($this);
    }

    public function getAllowedGalleryIds(): array
    {
        return app(AuthorizationService::class)->getAllowedGalleryIds($this);
    }

    public function canPhotographerAccessGallery(string $galleryId): bool
    {
        return app(AuthorizationService::class)->canPhotographerAccessGallery($this, $galleryId);
    }

    public function canAccessGallery(string $galleryId): bool
    {
        return app(AuthorizationService::class)->canAccessGallery($this, $galleryId);
    }

    public function hasPurchasedPhoto($photoId, $requestedTier): bool
    {
        $cacheKey = "user.{$this->id}.purchased.{$photoId}.{$requestedTier}";
        $cached = cache()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $orders = Order::where('user_id', $this->id)
            ->whereNotIn('status', ['disputed', 'refunded', 'cancelled'])
            ->where(function ($q) {
                $q->where('is_quote_request', false)
                    ->orWhere('status', '!=', 'pending');
            })->with('invoiceSnapshot')->get();
        $reqRank = TierRanks::RANKS[$requestedTier] ?? 3;

        foreach ($orders as $order) {
            $snapshot = $order->invoiceSnapshot;
            if (! $snapshot) {
                continue;
            }

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
