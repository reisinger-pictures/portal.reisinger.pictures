<?php

namespace App\Models;

use App\Enums\Brand;
use App\Services\ModelQuestionnaire;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 1:1-Profil eines CRM-Customers (Model). Enthält den versionsgebundenen
 * Answers-Snapshot sowie den optionalen Altersnachweis (privater Disk).
 *
 * Der Answers-Snapshot wird at rest verschlüsselt (`encrypted:array`); die
 * Such-/Filterfelder (gender, customer.city, birthdate) bleiben plaintext.
 *
 * Bewusst NICHT `Searchable`: der öffentliche, login-freie Registrierungsflow
 * darf nicht von Meilisearch abhängen. Die Admin-Model-Suche filtert per DB.
 */
class ModelProfile extends Model
{
    use HasFactory, HasUuids;

    public const LIFECYCLE_ACTIVE = 'active';

    public const LIFECYCLE_INACTIVE = 'inactive';

    public const LIFECYCLE_EXPIRED = 'expired';

    /** Months since the lifecycle anchor at which the status flips. */
    public const LIFECYCLE_INACTIVE_MONTHS = 13;

    public const LIFECYCLE_EXPIRED_MONTHS = 15;

    protected $fillable = [
        'customer_id',
        'catalog_version',
        'answers',
        'gender',
        'age_proof_required',
        'age_proof_path',
        'age_proof_uploaded_at',
        'submitted_at',
        'last_confirmed_at',
        'last_reminder_stage',
        'last_reminder_at',
    ];

    protected $casts = [
        'answers' => 'encrypted:array',
        'age_proof_required' => 'boolean',
        'age_proof_uploaded_at' => 'datetime',
        'submitted_at' => 'datetime',
        'last_confirmed_at' => 'datetime',
        'last_reminder_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ModelPhoto::class)->orderBy('position');
    }

    public function primaryPhoto(): HasOne
    {
        return $this->hasOne(ModelPhoto::class)->where('is_primary', true);
    }

    /**
     * Brand isolation: only profiles whose customer belongs to the given brand.
     */
    public function scopeForBrand(Builder $query, string $brand): Builder
    {
        return $query->whereHas('customer', fn (Builder $q) => $q->where('customers.brand', $brand));
    }

    /**
     * @return array<string, mixed>
     */
    public function answersMap(): array
    {
        return collect($this->answers ?? [])->pluck('value', 'key')->all();
    }

    /**
     * @return array<int, string>
     */
    public function categories(): array
    {
        return app(ModelQuestionnaire::class)->selectedCategories($this->answersMap());
    }

    /**
     * @return array<int, string>
     */
    public function actTypes(): array
    {
        $customer = $this->customer;
        if (! $customer) {
            return [];
        }

        return $customer->actMembers()
            ->with('act')
            ->get()
            ->pluck('act.act_type')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function brandValue(): ?string
    {
        $brand = $this->customer?->brand;

        if ($brand instanceof Brand) {
            return $brand->value;
        }

        return $brand !== null ? (string) $brand : null;
    }

    /**
     * Lifecycle anchor: the last confirmation, falling back to the original
     * submit. See features/crm/06-model-profile-iteration.md §3.2.
     */
    public function lifecycleAnchor(): ?CarbonInterface
    {
        return $this->last_confirmed_at ?? $this->submitted_at;
    }

    /**
     * Months since the lifecycle anchor (0 when no anchor exists yet).
     */
    public function lifecycleMonths(): int
    {
        $anchor = $this->lifecycleAnchor();

        if (! $anchor) {
            return 0;
        }

        return max(0, (int) floor(abs($anchor->diffInMonths(now()))));
    }

    /**
     * §3.1: active 0–13 months, inactive 13–15 months, expired ≥ 15 months.
     */
    public function lifecycleStatus(): string
    {
        $months = $this->lifecycleMonths();

        return match (true) {
            $months >= self::LIFECYCLE_EXPIRED_MONTHS => self::LIFECYCLE_EXPIRED,
            $months >= self::LIFECYCLE_INACTIVE_MONTHS => self::LIFECYCLE_INACTIVE,
            default => self::LIFECYCLE_ACTIVE,
        };
    }

    /**
     * The highest reminder stage due for this profile (t12/t13/t14) or null.
     */
    public function dueReminderStage(): ?string
    {
        $months = $this->lifecycleMonths();

        return match (true) {
            $months >= 14 => 't14',
            $months >= 13 => 't13',
            $months >= 12 => 't12',
            default => null,
        };
    }
}
