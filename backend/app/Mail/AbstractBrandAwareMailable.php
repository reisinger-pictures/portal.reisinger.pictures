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
        $config = $this->brand ? BrandRegistry::configForBrand($this->brand->value) : null;

        if ($config?->frontendUrl) {
            return rtrim($config->frontendUrl, '/');
        }

        return rtrim(config('app.frontend_url'), '/');
    }

    protected function brandLogoUrl(): string
    {
        return $this->brandFrontendUrl() . '/android-chrome-192x192.png';
    }

    protected function brandBcc(): string
    {
        $config = $this->brand ? BrandRegistry::configForBrand($this->brand->value) : null;

        return $config?->accountingEmail ?? config('services.accounting_email', 'accounting@reisinger.pictures');
    }

    protected function applyBrandFrom(): void
    {
        $config = $this->brand ? BrandRegistry::configForBrand($this->brand->value) : null;

        if ($config?->fromAddress) {
            $this->from($config->fromAddress, $config->fromName ?? config('mail.from.name'));
        } else {
            $this->from(config('mail.from.address'), config('mail.from.name'));
        }
    }
}
