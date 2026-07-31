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

class EmailCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function mailables(): array
    {
        return [
            'test' => new TestMail('test@example.com'),
            'activate' => new ActivateAccountMail('Max', 'Bitte aktiviere deinen Account.', 'https://example.com/a', 'Aktivieren', 'Aktivieren'),
            'invite' => new GalleryInviteMail('Galerie', 'https://example.com/g'),
            'notification' => new NotificationMail('Max', '<p>Hallo</p>', 'Betreff'),
            'custom' => new CustomMail('Betreff', '<p>Body</p>'),
            'org_invite' => new OrgInviteMail('ACME', 'https://example.com/o'),
            'rating_finished' => new RatingFinishedMail('Max', 'Erika', 'erika@example.com', 'Galerie'),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_all_mailables_use_xhtml_email_skeleton(): void
    {
        foreach ($this->mailables() as $name => $mail) {
            $html = $mail->render();

            $this->assertStringContainsString(
                '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"',
                $html,
                "{$name}: fehlt XHTML-Transitional-Doctype"
            );
            $this->assertStringContainsString(
                '<html xmlns="http://www.w3.org/1999/xhtml"',
                $html,
                "{$name}: fehlt xmlns-Attribut"
            );
        }
    }

    public function test_all_mailables_include_mobile_and_responsive_headers(): void
    {
        foreach ($this->mailables() as $name => $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('name="viewport"', $html, "{$name}: fehlt Viewport-Meta");
            $this->assertStringContainsString('name="color-scheme"', $html, "{$name}: fehlt Color-Scheme-Meta");
            $this->assertStringContainsString(
                '@media only screen and (max-width: 600px)',
                $html,
                "{$name}: fehlt responsiver Breakpoint"
            );
            $this->assertStringContainsString(
                '-webkit-text-size-adjust: 100%',
                $html,
                "{$name}: fehlt -webkit-text-size-adjust"
            );
            $this->assertStringContainsString(
                'prefers-color-scheme: dark',
                $html,
                "{$name}: fehlt Dark-Mode-Handling"
            );
        }
    }

    public function test_all_mailables_use_outlook_safe_table_layout(): void
    {
        foreach ($this->mailables() as $name => $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('<!--[if mso]>', $html, "{$name}: fehlen MSO-Conditionals");
            $this->assertStringContainsString('role="presentation"', $html, "{$name}: fehlt role=presentation");
            $this->assertStringContainsString('max-width:600px', $html, "{$name}: fehlt 600px-Container");
            $this->assertStringContainsString('width="600"', $html, "{$name}: fehlt Outlook-Fixbreite 600");
        }
    }

    public function test_all_mailables_include_preheader_and_websafe_fonts(): void
    {
        foreach ($this->mailables() as $name => $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('mso-hide:all', $html, "{$name}: fehlt Preheader");
            $this->assertStringContainsString(
                'font-family:Arial,Helvetica,sans-serif',
                $html,
                "{$name}: fehlt Websafe-Font-Stack"
            );
            $this->assertStringNotContainsString('@@media', $html, "{$name}: Blade-@media-Escape nicht kompiliert");
        }
    }
}
