<?php

namespace Tests\Feature;

use App\Mail\TemplateMail;
use App\Models\App;
use App\Models\EmailTemplate;
use App\Models\Installation;
use App\Services\EmailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailTemplateEscapingTest extends TestCase
{
    use RefreshDatabase;

    private function template(array $overrides = []): EmailTemplate
    {
        return new EmailTemplate(array_merge([
            'subject' => 'News for {{store_name}}',
            'body' => '<p>Hi {{store_name}}, you asked for {{request_title}}.</p>',
        ], $overrides));
    }

    public function test_values_are_escaped_in_the_body(): void
    {
        $body = $this->template()->renderBody([
            'store_name' => '<a href="https://evil.example">Click</a>',
            'request_title' => '<script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString('<a href', $body);
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('&lt;a href=&quot;https://evil.example&quot;&gt;Click&lt;/a&gt;', $body);
        // The template's own HTML is untouched
        $this->assertStringStartsWith('<p>Hi ', $body);
    }

    public function test_subject_is_plain_text_and_not_entity_encoded(): void
    {
        $subject = $this->template()->renderSubject(['store_name' => 'Tom & Jerry']);

        $this->assertSame('News for Tom & Jerry', $subject);
    }

    public function test_a_value_cannot_expand_another_variable(): void
    {
        $body = $this->template(['body' => '{{request_title}} / {{store_name}}'])->renderBody([
            'request_title' => '{{store_name}}',
            'store_name' => 'Real Store',
        ]);

        $this->assertSame('{{store_name}} / Real Store', $body);
    }

    public function test_a_merchant_controlled_store_name_reaches_the_email_as_text(): void
    {
        Mail::fake();

        $app = App::create(['app_name' => 'Test App', 'app_url' => 'https://test-app.example.com']);
        EmailTemplate::create([
            'app_id' => $app->id,
            'type' => 'install',
            'subject' => 'Welcome {{store_name}}',
            'body' => '<p>Welcome {{store_name}}</p>',
            'is_active' => true,
        ]);
        $installation = Installation::create([
            'app_id' => $app->id,
            'store_name' => '<img src=x onerror=alert(1)>',
            'store_url' => 'evil.myshopify.com',
            'email' => 'owner@example.com',
            'is_active' => true,
            'install_count' => 1,
            'installed_at' => now(),
        ]);

        $this->assertTrue((new EmailTemplateService())->sendInstallationEmail($installation));

        Mail::assertSent(TemplateMail::class, function (TemplateMail $mail) {
            $html = $mail->render();

            return ! str_contains($html, '<img src=x')
                && str_contains($html, '&lt;img src=x onerror=alert(1)&gt;');
        });
    }
}
