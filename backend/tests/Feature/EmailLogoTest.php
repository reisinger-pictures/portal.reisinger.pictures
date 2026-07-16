<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Enums\Brand;
use App\Support\BrandRegistry;
use App\Mail\TestMail;
use App\Mail\ActivateAccountMail;
use App\Mail\GalleryInviteMail;
use App\Mail\NotificationMail;
use App\Mail\CustomMail;
use App\Mail\OrgInviteMail;
use App\Mail\RatingFinishedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

class EmailLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_brand_logo_url_uses_email_logo_path(): void
    {
        $mail = new TestMail('test@example.com');
        $rendered = $mail->render();

        $this->assertStringContainsString('logo-email-64.png', $rendered);
        $this->assertStringContainsString('<img', $rendered);
        $this->assertStringContainsString('alt="Logo"', $rendered);
    }

    public function test_brand_logo_url_contains_correct_frontend_domain(): void
    {
        $mail = new TestMail('test@example.com');
        $rendered = $mail->render();

        $this->assertStringContainsString('https://portal.reisinger.pictures/brands/rp/logo-email-64.png', $rendered);
    }

    public function test_all_mailables_use_correct_logo_path(): void
    {
        $mailables = [
            new TestMail('test@example.com'),
            new ActivateAccountMail('User', 'Text', 'https://example.com', 'Aktivieren', 'Account aktivieren'),
            new NotificationMail('User', '<p>Test</p>', 'Subject'),
            new CustomMail('Subject', '<p>Body</p>'),
        ];

        foreach ($mailables as $mail) {
            $rendered = $mail->render();
            $mailClass = basename(str_replace('\\', '/', get_class($mail)));

            $this->assertStringContainsString(
                'logo-email-64.png',
                $rendered,
                "{$mailClass} does not use the correct email logo path"
            );
            $this->assertStringNotContainsString(
                'android-chrome-192x192.png',
                $rendered,
                "{$mailClass} still references the old android-chrome-192x192.png"
            );
        }
    }

    public function test_email_logo_img_has_correct_dimensions(): void
    {
        $mail = new TestMail('test@example.com');
        $rendered = $mail->render();

        $this->assertStringContainsString('width="64"', $rendered);
        $this->assertStringContainsString('height="64"', $rendered);
    }
}
