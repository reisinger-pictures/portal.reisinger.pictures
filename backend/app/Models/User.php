<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\Cache;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasUuids;

    public const UPDATED_AT = null;

    public $guest_id = null;
    public $transient_galleries = [];
    public $transient_meta_galleries = [];

    public function getIsSuperAdminAttribute(): bool {
        return $this->roles()->where('name', 'super_admin')->exists();
    }

    protected $visible = [
        'id', 'name', 'email', 'billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city', 'metadata_copyright', 'can_edit_metadata', 'flatrate_level',  
        'current_ftp_gallery_id', 'ftp_slug',
        'billing_name',
        'billing_company',
        'billing_street',
        'billing_zip',
        'billing_city', 'created_at', 'is_admin', 'is_photographer', 
        'is_pending', 'is_customer_manager', 'is_power_user', 'is_super_admin', 'roles', 'galleryGroups', 'galleries', 'currentFtpGallery'
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'metadata_copyright',
        'can_edit_metadata',
        'flatrate_level',
        'can_purchase_upgrades',
        'current_ftp_gallery_id',
        'ftp_slug'
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
        
    ];

    public function getJWTIdentifier() { return $this->getKey(); }
    public function getJWTCustomClaims() { return []; }

    public function roles() { return $this->belongsToMany(Role::class, 'user_roles'); }
    public function galleryGroups() { return $this->belongsToMany(GalleryGroup::class, 'user_gallery_groups')->withPivot('wants_notifications'); }
    public function galleries() { return $this->belongsToMany(Gallery::class, 'user_galleries')->withPivot('wants_notifications'); }
    
    public function currentFtpGallery() { return $this->belongsTo(Gallery::class, 'current_ftp_gallery_id'); }
    public function photos() { return $this->hasMany(Photo::class); }
    public function tenants() { return $this->belongsToMany(Tenant::class); }

    public function getIsPendingAttribute(): bool {
        if ($this->guest_id) return false;
        return $this->roles()->count() === 0 && $this->galleryGroups()->count() === 0 && $this->galleries()->count() === 0;
    }

    public function getIsPhotographerAttribute(): bool {
        return $this->roles()->where('name', 'photographer')->exists();
    }

    public function getIsAdminAttribute(): bool {
        return $this->roles()->where('name', 'admin')->exists();
    }

    public function getIsCustomerManagerAttribute(): bool {
        return $this->roles()->where('name', 'customer_manager')->exists();
    }

    public function getIsPowerUserAttribute(): bool {
        return $this->roles()->where('name', 'power_user')->exists();
    }

    private function getSubGroupIds($parentIds) {
        $allIds = $parentIds;
        $children = GalleryGroup::whereIn('parent_id', $parentIds)->pluck('id')->toArray();
        if (!empty($children)) {
            $allIds = array_merge($allIds, $this->getSubGroupIds($children));
        }
        return array_unique($allIds);
    }

    public function getAllowedGalleryIds(): array
    {
        if ($this->guest_id) {
            return $this->transient_galleries ?? [];
        }

        // Admins haben keinen globalen Zugriff mehr auf private Galerien

        // 1. Direct assignments
        $galleryIds = $this->galleries()->pluck('galleries.id')->toArray();

        // 2. Group assignments (recursive)
        $groupIds = $this->galleryGroups()->pluck('gallery_groups.id')->toArray();
        $allGroupIds = $this->getSubGroupIds($groupIds);
        
        if (!empty($allGroupIds)) {
            $groupGalleryIds = Gallery::whereIn('gallery_group_id', $allGroupIds)->pluck('id')->toArray();
            $galleryIds = array_unique(array_merge($galleryIds, $groupGalleryIds));
        }

        // 3. Domain Mapping (Only applies to Delivery galleries!)
        // 3. Tenant Integration (Only applies to Delivery galleries!)
        foreach ($this->tenants as $tenant) {
            $tenantGroupIds = $tenant->galleryGroups()->pluck('gallery_groups.id')->toArray();
            $domainGroupIds = $this->getSubGroupIds($tenantGroupIds);
            
            if (!empty($domainGroupIds)) {
                $domainGalleryIds = Gallery::whereIn('gallery_group_id', $domainGroupIds)
                                           ->where('type', 'delivery')
                                           ->pluck('id')->toArray();
                $galleryIds = array_unique(array_merge($galleryIds, $domainGalleryIds));
            }
        }

        if (!empty($this->transient_galleries)) {
            $galleryIds = array_unique(array_merge($galleryIds, $this->transient_galleries));
        }

        return $galleryIds;
    }

    public function canAccessGallery($galleryId): bool
    {
        if ($this->is_super_admin) return true; // 🌟 GOD MODE
        
        // Normale Admins müssen wie alle anderen explizite Rechte besitzen
        return in_array($galleryId, $this->getAllowedGalleryIds());
    }

    public function hasPurchasedPhoto($photoId, $requestedTier): bool
    {
        $orders = \App\Models\Order::where('user_id', $this->id)->with('invoiceSnapshot')->get();
        $ranks = ['none' => 0, 'web' => 1, 'print' => 2, 'original' => 3];
        $reqRank = $ranks[$requestedTier] ?? 3;

        foreach ($orders as $order) {
            $snapshot = $order->invoiceSnapshot;
            if (!$snapshot) continue;
            
            $items = $snapshot->customer_details['items'] ?? [];
            foreach ($items as $item) {
                if (($item['photoId'] ?? '') === $photoId) {
                    $itemRank = $ranks[$item['tier'] ?? 'none'] ?? 0;
                    if ($itemRank >= $reqRank) return true;
                }
            }
        }
        return false;
    }
}