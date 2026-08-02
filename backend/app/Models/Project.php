<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory, HasUuids;

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
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'price_cents' => 'integer',
        'payment_status' => 'string',
        'status' => 'string',
        'position' => 'integer',
    ];

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
        static::saving(function ($project) {
            $allowedStatuses = ['anfrage', 'angebot', 'beauftragt', 'rechnung', 'bezahlt'];
            if (!in_array($project->status, $allowedStatuses)) {
                throw new \InvalidArgumentException("Ungültiger Projektstatus: {$project->status}");
            }

            $allowedPaymentStatuses = ['open', 'partly_paid', 'paid'];
            if (!in_array($project->payment_status, $allowedPaymentStatuses)) {
                throw new \InvalidArgumentException("Ungültiger Zahlungsstatus: {$project->payment_status}");
            }
        });
    }
}