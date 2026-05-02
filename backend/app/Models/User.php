<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\Cache;
use App\Enums\UserRole;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasUuids;

    public const UPDATED_AT = null;

    public $guest_id = null;
    public $transient_galleries = [];
    public $transient_meta_galleries = [];

    public function getIsSuperAdminAttribute(): bool {
        return $this->roles()->where('name', UserRole::SUPER_ADMIN->value)->exists();
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
    public function photographerGalleries() { return $this->belongsToMany(Gallery::class, 'photographer_galleries'); }
    public function photographerGalleryGroups() { return $this->belongsToMany(GalleryGroup::class, 'photographer_gallery_groups'); }
    
    public function currentFtpGallery() { return $this->belongsTo(Gallery::class, 'current_ftp_gallery_id'); }
    public function photos() { return $this->hasMany(Photo::class); }
    public function tenants() { return $this->belongsToMany(Tenant::class); }

    public function getIsPendingAttribute(): bool {
        if ($this->guest_id) return false;
        return $this->roles()->count() === 0 && $this->galleryGroups()->count() === 0 && $this->galleries()->count() === 0;
    }

    public function getIsPhotographerAttribute(): bool {
        return $this->roles()->where('name', UserRole::PHOTOGRAPHER->value)->exists();
    }

    public function getIsAdminAttribute(): bool {
        return $this->roles()->whereIn('name', [UserRole::ADMIN->value, UserRole::SUPER_ADMIN->value])->exists();
    }

    public function getIsCustomerManagerAttribute(): bool {
        return $this->roles()->where('name', UserRole::CUSTOMER_MANAGER->value)->exists();
    }

    public function getIsPowerUserAttribute(): bool {
        return $this->roles()->where('name', UserRole::POWER_USER->value)->exists();
    }

    private function getSubGroupIds($parentIds) {
        if (empty($parentIds)) return [];

        $inIds = implode(',', array_map(function($id) { return "'" . $id . "'"; }, $parentIds));
        
        $query = "
            WITH RECURSIVE cte AS (
                SELECT id FROM gallery_groups WHERE id IN ($inIds)
                UNION ALL
                SELECT g.id FROM gallery_groups g
                INNER JOIN cte ON g.parent_id = cte.id
            )
            SELECT id FROM cte;
        ";

        $result = \Illuminate\Support\Facades\DB::select($query);
        return array_values(array_unique(array_column($result, 'id')));
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

        
        if ($this->is_photographer) {
            $unrestrictedIds = \Illuminate\Support\Facades\Cache::rememberForever('unrestricted_photographer_gallery_ids', function() {
                $allGalleries = Gallery::with('galleryGroup')->get();
                return $allGalleries->filter(fn($g) => !$g->effective_restricted_photographers)->pluck('id')->toArray();
            });
            $galleryIds = array_merge($galleryIds, $unrestrictedIds);

            $photogGalleryIds = $this->photographerGalleries()->pluck('galleries.id')->toArray();
            $galleryIds = array_merge($galleryIds, $photogGalleryIds);

            $photogGroupIds = $this->photographerGalleryGroups()->pluck('gallery_groups.id')->toArray();
            $allPhotogGroupIds = $this->getSubGroupIds($photogGroupIds);
            if (!empty($allPhotogGroupIds)) {
                $groupGalleryIds = Gallery::whereIn('gallery_group_id', $allPhotogGroupIds)->pluck('id')->toArray();
                $galleryIds = array_merge($galleryIds, $groupGalleryIds);
            }
        }

        $galleryIds = array_values(array_unique($galleryIds));
        return $galleryIds;
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
            $allGroupIds = $this->getSubGroupIds($groupIds);
            if (in_array($gallery->gallery_group_id, $allGroupIds)) return true;
        }

        return false;
    }

public function canAccessGallery($galleryId): bool
    {
        if ($this->is_super_admin) return true; // 🌟 GOD MODE
        
        // Normale Admins müssen wie alle anderen explizite Rechte besitzen
        
        if ($this->is_photographer && $this->canPhotographerAccessGallery($galleryId)) {
            return true;
        }
        
return in_array($galleryId, $this->getAllowedGalleryIds());
    }

    public function hasPurchasedPhoto($photoId, $requestedTier): bool
    {
        $orders = \App\Models\Order::where('user_id', $this->id)
            ->whereNotIn('status', ['disputed', 'refunded', 'cancelled'])
            ->where(function($q) {
                $q->where('is_quote_request', false)
                  ->orWhere('status', '!=', 'pending');
            })->with('invoiceSnapshot')->get();
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