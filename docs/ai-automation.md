# AI automation architecture

LodgeOps can extend its existing event-driven automation engine through n8n and a Python/LangGraph service without giving the model direct write access to reservation or financial state.

## Flow

1. Laravel records domain events through the existing transactional outbox.
2. An automation rule with action type `ai_workflow`, `agentic_workflow`, or `n8n_agent` invokes the n8n webhook.
3. n8n performs workflow orchestration and calls the LangGraph service.
4. LangGraph evaluates the event and returns one of `draft_communication`, `create_task`, or `needs_review` with a structured recommendation.
5. Laravel stores the result as an idempotent operational task with `human_approval_required=true`.
6. Staff review the recommendation before any guest-facing, access-related, or financial action occurs.

## Local services

- n8n: `http://localhost:5678`
- LangGraph/FastAPI service: `http://localhost:8080`
- Agent health: `GET http://localhost:8080/health`

Import `n8n/workflows/lodgeops-agent-orchestration.json` into the local n8n instance and activate it. The Laravel default webhook is `http://n8n:5678/webhook/lodgeops-agent`.

## Optional LLM provider

The LangGraph workflow works deterministically without an external model. To add model-assisted routing, configure an OpenAI-compatible endpoint:

```env
LLM_BASE_URL=
LLM_API_KEY=
LLM_MODEL=
```

The model is only allowed to return a structured recommendation. The workflow marks every recommendation as requiring human approval.

## Example automation action

```json
{
  "type": "ai_workflow",
  "objective": "Review this reservation event and recommend the safest useful next action."
}
```

This design keeps domain writes, tenancy, authorization, idempotency, and auditability inside Laravel while n8n and LangGraph handle orchestration and recommendation logic.
