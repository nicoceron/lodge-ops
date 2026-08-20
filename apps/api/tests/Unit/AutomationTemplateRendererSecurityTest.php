<?php

namespace Tests\Unit;

use App\Services\Automation\AutomationTemplateRenderer;
use DomainException;
use Tests\TestCase;

class AutomationTemplateRendererSecurityTest extends TestCase
{
    public function test_allowed_fields_render_and_untrusted_html_remains_plain_text(): void
    {
        $result = app(AutomationTemplateRenderer::class)->render(
            'Welcome {{guest.first_name}}',
            ['guest' => ['first_name' => '<script>alert(1)</script>']],
        );

        $this->assertSame('Welcome <script>alert(1)</script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', e($result));
    }

    public function test_unknown_or_missing_fields_are_rejected(): void
    {
        foreach (['{{guest.raw_notes}}' => ['guest' => ['raw_notes' => 'private']], '{{deposit.currency}}' => []] as $template => $context) {
            try {
                app(AutomationTemplateRenderer::class)->render($template, $context);
                $this->fail('Expected strict merge-field rejection.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('Template merge field', $exception->getMessage());
            }
        }
    }
}
