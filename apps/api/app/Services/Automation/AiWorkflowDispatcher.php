<?php

namespace App\Services\Automation;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiWorkflowDispatcher
{
    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function dispatch(string $eventType, array $context, ?string $objective = null): array
    {
        $url = (string) config('services.ai_automation.n8n_webhook');
        if ($url === '') {
            throw new RuntimeException('AI automation webhook is not configured.');
        }

        $response = $this->client()->post($url, [
            'event_type' => $eventType,
            'context' => $context,
            'objective' => $objective ?? 'Recommend the safest useful next operational action.',
        ]);

        $response->throw();
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('AI automation workflow returned an invalid response.');
        }

        return $payload;
    }

    private function client(): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('services.ai_automation.timeout', 25))
            ->retry([250, 1000], throw: false);

        $token = (string) config('services.ai_automation.token');
        if ($token !== '') {
            $request = $request->withHeader('X-LodgeOps-Automation-Token', $token);
        }

        return $request;
    }
}
