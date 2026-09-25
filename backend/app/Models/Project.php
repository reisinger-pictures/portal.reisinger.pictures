<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Enums\Brand;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Support\ModelStatusGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory, HasUuids;

    /**
     * Current workflow states, derived from the board enums.
     *
     * Persisted rows may still carry a legacy value outside these lists; such a
     * row must stay savable through unrelated board operations (see
     * {@see ModelStatusGuard::assertTransitionAllowed()}).
     *
     * @return array<int, string>
     */
    public static function allowedStatuses(): array
    {
        return array_column(ProjectStatus::cases(), 'value');
    }

    /** @return array<int, string> */
    public static function allowedPaymentStatuses(): array
    {
        return array_column(PaymentStatus::cases(), 'value');
    }

    protected $fillable = [
        'brand',
        'owner_id',
        'assignee_id',
        'client_name',
        'email',
        'phone',
        'package',
        'price_cents',
        'payment_status',
        'status',
        'position',
        'linked_photo_job_id',
        'notes',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'price_cents' => 'integer',
        'payment_status' => 'string',
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

    public function linkedPhotoJob()
    {
        return $this->belongsTo(PhotoJob::class, 'linked_photo_job_id');
    }

    protected static function booted()
    {
        static::saving(function (Project $project) {
            // Transition guard: only an actually written status is validated so
            // a legacy row stays savable through unrelated board operations.
            ModelStatusGuard::assertTransitionAllowed(
                $project,
                'status',
                self::allowedStatuses(),
                'Projektstatus',
            );

            ModelStatusGuard::assertTransitionAllowed(
                $project,
                'payment_status',
                self::allowedPaymentStatuses(),
                'Zahlungsstatus',
            );
        });
    }
}
