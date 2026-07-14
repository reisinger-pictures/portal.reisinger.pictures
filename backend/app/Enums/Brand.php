<?php

namespace App\Enums;

/**
 * Brand (White-Label) of the portal.
 *
 * Stored as a short code on multiple tables (users, galleries, gallery_groups,
 * orgs, orders, invoice_snapshots). `null` means explicitly cross-brand
 * (e.g. Super-Admin).
 */
enum Brand: string
{
    /** B2B portal — reisinger.pictures (full admin/CRM/invoicing). */
    case B2B = 'rp';

    public function prefix(): string
    {
        return '';
    }

    /** Internal brand identifier (same as ->value, explicit for self-documenting code). */
    public function id(): string
    {
        return $this->value;
    }

    /** Production domain for this brand. */
    public function domain(): string
    {
        return 'portal.reisinger.pictures';
    }
}
