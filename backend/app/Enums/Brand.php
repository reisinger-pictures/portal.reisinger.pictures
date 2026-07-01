<?php

namespace App\Enums;

/**
 * Brand (White-Label) of the portal.
 *
 * Stored as a short code on multiple tables (users, galleries, gallery_groups,
 * tenants, orders, invoice_snapshots) — see V019. `null` means explicitly
 * cross-brand (e.g. Super-Admin). See features/infrastructure/12-brand-registry-and-settings-fixes.md.
 */
enum Brand: string
{
    /** B2B portal — reisinger.pictures (full admin/CRM/invoicing). */
    case B2B = 'rp';
    /** SRP portal — buy.reisinger.pictures (reduced B2C). */
    case SRP = 'srp';

    /** Human-readable label (German UI / docs). */
    public function label(): string
    {
        return match ($this) {
            self::B2B => 'Reisinger Foto Portal',
            self::SRP => 'Reisinger Foto Portal',
        };
    }

    /** Production domain used to identify this brand from the HTTP host. */
    public function domain(): string
    {
        return match ($this) {
            self::B2B => 'reisinger.pictures',
            self::SRP => 'buy.reisinger.pictures',
        };
    }

    /** Setting/asset key prefix ('' for B2B, 'srp_' for SRP). */
    public function prefix(): string
    {
        return match ($this) {
            self::B2B => '',
            self::SRP => 'srp_',
        };
    }
}
