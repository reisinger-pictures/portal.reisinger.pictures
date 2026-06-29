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
    /** ATR portal — all-the.rest (reduced B2C). */
    case ATR = 'atr';

    /** Human-readable label (German UI / docs). */
    public function label(): string
    {
        return match ($this) {
            self::B2B => 'Reisinger Pictures',
            self::ATR => 'all-the.rest',
        };
    }

    /** Production domain used to identify this brand from the HTTP host. */
    public function domain(): string
    {
        return match ($this) {
            self::B2B => 'reisinger.pictures',
            self::ATR => 'all-the.rest',
        };
    }

    /** Setting/asset key prefix ('' for B2B, 'atr_' for ATR). */
    public function prefix(): string
    {
        return match ($this) {
            self::B2B => '',
            self::ATR => 'atr_',
        };
    }
}
