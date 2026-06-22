# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A modern stateless SaaS platform for photographers to deliver images and sell licenses with integrated e-commerce and multi-tenant B2B customer management.

**Tech Stack:**
- **Backend:** Laravel 13 (PHP 8.3), JWT auth (php-open-source-saver/jwt-auth), Meilisearch
- **Frontend:** React 19, TypeScript, Vite, Tailwind CSS v4, DaisyUI, SWR
- **Database:** MariaDB 11.4
- **Storage:** Local filesystem (configurable to S3)

## Development Commands

### Backend
```bash
cd backend

# Setup (first time or after .env changes)
composer install
cp .env.local.example .env.local
php artisan key:generate
php artisan migrate

# Development
composer run dev           # Starts server, queue worker, and logs

# Testing
# The project uses Laravel Herd for PHP - the PHP binary is located at:
# ~/.config/herd/bin/php.bat (Windows)
# Run all tests
php ./vendor/bin/phpunit
# Run specific test
php ./vendor/bin/phpunit --filter testName
# Run specific test file
php ./vendor/bin/phpunit tests/Feature/SpecificTest.php

# Maintenance
php artisan meilisearch:import   # Rebuild search index
php artisan cache:clear
php artisan queue:work           # Process background jobs
```

### Frontend
```bash
cd frontend

npm install
npm run dev          # Vite dev server on port 4321
npm run build        # Production build
npm run lint         # ESLint
npm run test:e2e     # Playwright E2E tests
```

### Docker Services
```bash
# Start all services (DB, Meilisearch, Mailpit)
docker compose -f docker-compose.local.yml up -d

# Stop gracefully
docker compose -f docker-compose.local.yml down
```

## Architecture Overview

### Authentication Flow
Two distinct authentication paths:

1. **Admins/Photographers:** Email + password login → JWT stored in `rp_jwt` cookie
2. **Guests (Customers):** Magic link (`/invite/{token}`) → Optional identification → JWT with transient gallery access

**Key implementation:** `AuthController`, `InviteController`, `TransientUserProvider`

### Gallery & Permission System

**Hierarchical Structure:**
- `GalleryGroup` → nested `GalleryGroup` (recursive) or `Gallery`
- Permissions cascade through the hierarchy
- `effective_*` attributes on models compute inherited values

**Access Control (`User::getAllowedGalleryIds()`):**
- Direct gallery assignments
- Group assignments (recursive via `getSubGroupIds()`)
- Tenant mappings (for B2B customers, delivery galleries only)
- Photographer-specific assignments
- Transient access via JWT claims (`transient_galleries`)

**Important:** Admins no longer have global access to private galleries (as of recent updates).

### User Roles (`App\Enums\UserRole`)
- `super_admin` - Full system access, license catalog management
- `admin` - Tenant management, all galleries they have access to
- `photographer` - FTP upload, assigned galleries/photographer groups
- `customer_manager` - User/tenant management only
- `power_user` - Extended client permissions
- `client` - Regular customer

### Photo Storage & Derivatives

**Storage paths:**
- Original photos: `../photos/{gallery_id}/originals/{id}.{ext}`
- Derivatives: `../photos/{gallery_id}/_thumbs/{size}/{id}.webp`
- Watermarked: `../photos/{gallery_id}/watermarked/_thumbs/{size}/{id}.webp`
- FTP inbox: `../ftp/{ftp_slug}/...`

**On-demand generation:** `FileDeliveryController` creates derivatives lazily. Sizes: 250, 400, 800, 1024, 1200, 2000px.

**Watermark logic:** `Photo::requiresWatermark()` determines if watermark is needed based on:
- Gallery's `effective_is_free_download`
- User's role (admin/photographer = no watermark)
- User's flatrate level (web+ = no watermark)

### Services Layer

Key services in `backend/app/Services/`:
- `PhotoProcessingService` - ExifTool metadata extraction, applies gallery defaults
- `CheckoutService` - Stripe checkout, order creation
- `PricingService` - License pricing calculations
- `PayoutCalculationService` - Photographer commission calculations
- `InvoiceService` - Invoice PDF generation
- `WatermarkService` - Watermark application
- `ImageProcessor` - Image manipulation

### Frontend Architecture

**Key patterns:**
- Custom hooks in `frontend/src/logic/use*.ts` for data fetching (SWR-based)
- Context providers: `CartProvider`, `UIProvider`
- Route organization: `ui/management/*`, `ui/client/*`, `ui/anonymous/*`
- Lazy loading with React Suspense

**API communication:**
- Base URL: `/api` (proxied to backend in dev)
- JWT via `rp_jwt` cookie (httpOnly)
- Error handling via `setGlobalErrorCallback`

### Testing

**Backend (PHPUnit):**
- Located in `backend/tests/Feature/`
- Uses test database on port 3307
- Test Meilisearch on port 7701
- Test mail server on port 1026

**Frontend (Playwright):**
- Located in `frontend/tests/e2e/`
- Organized by role: `auth/`, `admin/`, `photographer/`, `client/`, `delivery/`, `selection/`
- Helpers in `frontend/tests/e2e/helpers/`

## Important Concepts

### Magic Link Authentication
Guest access uses invite tokens stored as `GalleryInvite`. When redeemed:
- Creates transient JWT with `transient_galleries` array
- Optionally identifies user (email) for account linking
- No password required unless gallery has password

### Multi-Tenancy
`Tenant` model represents B2B customers:
- Linked to `GalleryGroup` via many-to-many
- Collective invoicing (`ProcessCollectiveInvoices` command)
- Tenant-specific invites via `TenantInvite`

### FTP Upload System
Photographers get unique `ftp_slug` (derived from email):
- Upload to `../ftp/{ftp_slug}/`
- `FtpController::process()` imports photos to `current_ftp_gallery_id`
- Supports Lightroom Classic publishing

### License Catalog
Configurable licensing system:
- `LicenseUseCase` - Usage types (editorial, commercial, etc.)
- `LicenseModifier` - Pricing modifiers (exclusivity, territory, duration)
- `Product` - Tiered products (web, print, original) with base prices

### Payout System
`PhotographerStatement` tracks earnings:
- Commission calculated per order line item
- Configurable commission rates via `Setting` model
- Approval workflow → payout

## Common Tasks

**Add new API endpoint:**
1. Add route in `backend/routes/api.php`
2. Add controller method (include middleware as needed)
3. Update frontend API wrapper in `frontend/src/api.ts`

**Add new role permission:**
- Update `UserRole` enum
- Modify `ManagementMiddleware` for access rules
- Update `getAllowedGalleryIds()` if gallery access affected

**Modify gallery hierarchy behavior:**
- Check `getSubGroupIds()` for recursive logic
- Update `effective_*` computed attributes
- Clear cache keys (`gallery_tree_admin`, `unrestricted_photographer_gallery_ids`)

**Debug JWT issues:**
- Check `config/jwt.php` settings
- Verify `TransientUserProvider` handles guest tokens correctly
- Inspect `rp_jwt` cookie in browser dev tools

**Run specific test:**
```bash
./vendor/bin/phpunit --filter testMethodName
./vendor/bin/phpunit tests/Feature/SpecificTest.php
npm run test:e2e -- tests/e2e/path/to/test.spec.ts
```
