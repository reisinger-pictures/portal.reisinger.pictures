<?php

namespace App\Mail;

use App\Enums\Brand;
use App\Support\BrandRegistry;

trait BrandAwareMail
{
    public ?Brand $brand = null;

    public function initializeBrand(?Brand $brand = null): void
    {
        $this->brand = $brand ?? BrandRegistry::current();
    }

    protected function ensureBrandContext(): void
    {
        if ($this->brand) {
            BrandRegistry::set($this->brand);
        }
    }

    protected function brandFrontendUrl(): string
    {
        if (BrandRegistry::isSrp()) {
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
        $key = BrandRegistry::isSrp() ? 'accounting_email_srp' : 'accounting_email_rp';

        return config("services.{$key}", env('ACCOUNTING_EMAIL', 'accounting@reisinger.pictures'));
    }

    protected function applyBrandFrom(): void
    {
        if (BrandRegistry::isSrp()) {
            $this->from(
                config('mail.from_srp.address', config('mail.from.address')),
                config('mail.from_srp.name', config('mail.from.name'))
            );
        } else {
            $this->from(config('mail.from.address'), config('mail.from.name'));
        }
    }
}
