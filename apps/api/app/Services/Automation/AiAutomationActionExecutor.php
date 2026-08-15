<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\OperationalTask;
use App\Models\Outbox;
use App\Services\GuestPortalTokenService;
use App\Services\MessageTemplateService;
use DomainException;

class AiAutomationActionExecutor extends AutomationActionExecutor
{
    public function __construct(
        AutomationTemplateRenderer $renderer,
        OutboxRecorder $outbox,
        GuestPortalTokenService $guestPortalTokens,
        InternalStaffNotificationService $internalStaffNotifications,
        MessageTemplateService $messageTemplates,
        private readonly AiWorkflowDispatcher $aiWorkflows,
    ) {
        parent::__construct($renderer, $outbox, $guestPortalTokens, $internalStaffNotifications, $messageTemplates);
    }

    /** @param array<string, mixed> $action @param array<string, mixed> $context */
    public function execute(
        Outbox $message,
        AutomationRule $rule,
        int $actionIndex,
        array $action,
        array $context,
    ): void {
        $type = $action['type'] ?? null;

        if (! in_array($type, ['ai_workflow', 'agentic_workflow', 'n8n_agent'], true)) {
            parent::execute($message, $rule, $actionIndex, $action, $context);
            return;
        }

        $eventType = is_string($action['event_type'] ?? null)
            ? $action['event_type']
            : $message->event_type;

        $result = $this->aiWorkflows->dispatch(
            $eventType,
            $context,
            is_string($action['objective'] ?? null) ? $action['objective'] : null,
        );

        $propertyId = data_get($context, 'reservation.property_id') ?? ($action['property_id'] ?? null);
        if (! is_string($propertyId) || $propertyId === '') {
            throw new DomainException('AI workflow automations require a reservation or property_id so the recommendation can be reviewed.');
        }

        $route = is_string($result['route'] ?? null) ? $result['route'] : 'needs_review';
        $summary = is_string($result['reasoning_summary'] ?? null)
            ? $result['reasoning_summary']
            : 'AI workflow recommendation requires review.';

        OperationalTask::query()->firstOrCreate(
            ['automation_key' => implode(':', [$message->id, $rule->id, $actionIndex, 'ai-workflow'])],
            [
                'property_id' => $propertyId,
                'reservation_id' => data_get($context, 'reservation.id'),
                'title' => 'AI recommendation: '.str_replace('_', ' ', $route),
                'description' => $summary,
                'status' => 'todo',
                'priority' => $route === 'needs_review' ? 'high' : 'normal',
                'metadata' => [
                    'automation_rule_id' => $rule->id,
                    'outbox_id' => $message->id,
                    'action_index' => $actionIndex,
                    'action_type' => 'ai_workflow',
                    'ai_route' => $route,
                    'ai_recommendation' => $result['recommendation'] ?? [],
                    'ai_model' => $result['model_used'] ?? null,
                    'human_approval_required' => true,
                ],
            ],
        );
    }
}
