<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\Cache;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    public const UPDATED_AT = null;

    protected $visible = [
        'id', 'name', 'email', 'metadata_copyright', 'can_edit_metadata', 
        'current_ftp_gallery_id', 'created_at', 'is_admin', 'is_photographer', 
        'is_pending', 'roles', 'galleryGroups', 'galleries', 'currentFtpGallery'
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'metadata_copyright',
        'can_edit_metadata',
        'current_ftp_gallery_id'
    ];

    protected $casts = [
        'can_edit_metadata' => 'boolean'
    ];

    public function getJWTIdentifier() { return $this->getKey(); }
    public function getJWTCustomClaims() { return []; }

    public function roles() { return $this->belongsToMany(Role::class, 'user_roles'); }
    public function galleryGroups() { return $this->belongsToMany(GalleryGroup::class, 'user_gallery_groups'); }
    public function galleries() { return $this->belongsToMany(Gallery::class, 'user_galleries'); }
    
    public function currentFtpGallery() { return $this->belongsTo(Gallery::class, 'current_ftp_gallery_id'); }

    public function getIsPendingAttribute(): bool {
        return $this->roles()->count() === 0 && $this->galleryGroups()->count() === 0 && $this->galleries()->count() === 0;
    }

    public function getIsPhotographerAttribute(): bool {
        return $this->roles()->where('name', 'photographer')->exists();
    }

    public function getIsAdminAttribute(): bool {
        return $this->roles()->where('name', 'admin')->exists();
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
        if ($this->is_admin) {
            return Gallery::pluck('id')->toArray();
        }

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
        if ($this->email) {
            $domain = substr(strrchr($this->email, "@"), 1);
            $mapping = DomainMapping::where('domain', $domain)->first();
            
            if ($mapping && $mapping->gallery_group_id) {
                $domainGroupIds = $this->getSubGroupIds([$mapping->gallery_group_id]);
                $domainGalleryIds = Gallery::whereIn('gallery_group_id', $domainGroupIds)
                                           ->where('type', 'delivery')
                                           ->pluck('id')->toArray();
                $galleryIds = array_unique(array_merge($galleryIds, $domainGalleryIds));
            }
        }

        return $galleryIds;
    }

    public function canAccessGallery($galleryId): bool
    {
        if ($this->is_admin) return true;
        return in_array($galleryId, $this->getAllowedGalleryIds());
    }
}
