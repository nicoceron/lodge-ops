<?php

namespace Tests\Feature;

use App\Services\Automation\AiAutomationActionExecutor;
use App\Services\Automation\AiWorkflowDispatcher;
use App\Services\Automation\AutomationActionExecutor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAutomationWiringTest extends TestCase
{
    public function test_automation_executor_resolves_to_ai_capable_executor(): void
    {
        $this->assertInstanceOf(
            AiAutomationActionExecutor::class,
            $this->app->make(AutomationActionExecutor::class),
        );
    }

    public function test_ai_workflow_dispatcher_posts_structured_context_to_n8n(): void
    {
        config()->set('services.ai_automation.n8n_webhook', 'https://automation.example.test/webhook');

        Http::fake([
            'automation.example.test/*' => Http::response([
                'route' => 'needs_review',
                'reasoning_summary' => 'Review required.',
                'recommendation' => ['requires_human_approval' => true],
            ]),
        ]);

        $response = $this->app->make(AiWorkflowDispatcher::class)->dispatch(
            'reservation.confirmed',
            ['reservation' => ['id' => 'reservation-1']],
        );

        $this->assertSame('needs_review', $response['route']);

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://automation.example.test/webhook'
            && $request['event_type'] === 'reservation.confirmed'
            && $request['context']['reservation']['id'] === 'reservation-1'
        );
    }
}
