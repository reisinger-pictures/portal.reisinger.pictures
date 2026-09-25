<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Enums\Brand;
use App\Enums\PhotoJobStatus;
use App\Support\ModelStatusGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhotoJob extends Model
{
    use HasFactory, HasUuids;

    protected $attributes = [
        'total_count' => 0,
        'selected_count' => 0,
    ];

    protected $fillable = [
        'brand',
        'owner_id',
        'assignee_id',
        'title',
        'lightroom_catalog',
        'total_count',
        'selected_count',
        'target_gallery_id',
        'status',
        'position',
        'notes',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'total_count' => 'integer',
        'selected_count' => 'integer',
        'status' => 'string',
        'position' => 'integer',
    ];

    public function scopeForBrand(Builder $query, string|Brand|null $brand): Builder
    {
        if ($brand === null) {
            return $query->whereNull('brand');
        }

        return $query->where('brand', $brand instanceof Brand ? $brand->value : $brand);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function targetGallery()
    {
        return $this->belongsTo(Gallery::class, 'target_gallery_id');
    }

    /**
     * Current production workflow states, derived from the board enum.
     *
     * Persisted rows may still carry a legacy value outside this list; it must
     * not break saves that leave the status column untouched (see
     * {@see ModelStatusGuard::assertTransitionAllowed()}).
     *
     * @return array<int, string>
     */
    public static function allowedStatuses(): array
    {
        return array_column(PhotoJobStatus::cases(), 'value');
    }

    protected static function booted()
    {
        static::saving(function (PhotoJob $photoJob) {
            // Transition guard: only an actually written status is validated so
            // a legacy row stays savable through unrelated board operations.
            ModelStatusGuard::assertTransitionAllowed(
                $photoJob,
                'status',
                self::allowedStatuses(),
                'Bildbearbeitungsstatus',
            );

            foreach (['total_count', 'selected_count'] as $countField) {
                if ($photoJob->{$countField} === null) {
                    throw new \InvalidArgumentException("Photo job {$countField} must not be null.");
                }
            }
        });
    }
}
