<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridApiTransport;
use Tests\TestCase;

/**
 * Choosing SendGrid in Platform Settings → Mail sends through
 * SendGrid's web API with the API key saved there.
 */
class SendgridMailerTest extends TestCase
{
    public function test_the_sendgrid_mailer_uses_the_web_api(): void
    {
        config(['services.sendgrid.key' => 'SG.test-key']);

        $transport = Mail::mailer('sendgrid')->getSymfonyTransport();

        $this->assertInstanceOf(SendgridApiTransport::class, $transport);
        $this->assertSame('sendgrid+api://api.sendgrid.com', (string) $transport);
    }

    public function test_sendgrid_can_be_chosen_with_a_secret_api_key(): void
    {
        $settings = SettingsRegistry::all();

        $this->assertArrayHasKey('sendgrid', $settings['mail.default']['options']);
        $this->assertSame('services.sendgrid.key', $settings['services.sendgrid.key']['config_key']);
        $this->assertTrue($settings['services.sendgrid.key']['secret']);
    }
}
