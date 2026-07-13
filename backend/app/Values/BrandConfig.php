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
