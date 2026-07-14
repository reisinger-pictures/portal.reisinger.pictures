<?php

namespace App\Values;

readonly class BrandConfig
{
    public function __construct(
        public string $id,
        public string $name,
        public string $theme,
        public string $portalName,
        public ?string $impressumUrl,
        public ?string $logoPath,
        public array $features,
        public array $hostnames,
        public bool $isActive = true,
        public ?string $frontendUrl = null,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
        public ?string $accountingEmail = null,
        public string $primaryColor = '#1E5631',
        public string $secondaryColor = '#A4B494',
    ) {}

    public function prefix(): string
    {
        return '';
    }

    public function domain(): string
    {
        return $this->hostnames[0] ?? '';
    }

    public function hasFeature(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }
}
