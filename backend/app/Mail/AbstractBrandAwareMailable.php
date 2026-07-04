<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Queue\SerializesModels;

abstract class AbstractBrandAwareMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public ?Brand $brand = null;

    public $tries = 3;

    public $backoff = [30, 60, 120];

    public function initializeBrand(?Brand $brand = null): void
    {
        $this->brand = $brand ?? BrandRegistry::current();
    }

    protected function brandFrontendUrl(): string
    {
        if ($this->brand === Brand::SRP) {
            return rtrim(config('app.frontend_url_srp', 'https://buy.reisinger.pictures'), '/');
        }

        return rtrim(config('app.frontend_url'), '/');
    }

    protected function brandLogoUrl(): string
    {
        return $this->brandFrontendUrl() . '/android-chrome-192x192.png';
    }

    protected function brandBcc(): string
    {
        $key = $this->brand === Brand::SRP ? 'accounting_email_srp' : 'accounting_email_rp';

        return config("services.{$key}", env('ACCOUNTING_EMAIL', 'accounting@reisinger.pictures'));
    }

    protected function applyBrandFrom(): void
    {
        if ($this->brand === Brand::SRP) {
            $this->from(
                config('mail.from_srp.address', config('mail.from.address')),
                config('mail.from_srp.name', config('mail.from.name'))
            );
        } else {
            $this->from(config('mail.from.address'), config('mail.from.name'));
        }
    }
}
