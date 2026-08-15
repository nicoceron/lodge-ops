from __future__ import annotations

import json
import os
from typing import Any, Literal, TypedDict

import httpx
from fastapi import FastAPI
from langgraph.graph import END, START, StateGraph
from pydantic import BaseModel, Field


class WorkflowRequest(BaseModel):
    event_type: str
    context: dict[str, Any] = Field(default_factory=dict)
    objective: str = "Recommend the safest useful next operational action."


class WorkflowResponse(BaseModel):
    route: Literal["draft_communication", "create_task", "needs_review"]
    reasoning_summary: str
    recommendation: dict[str, Any]
    model_used: str | None = None


class AgentState(TypedDict, total=False):
    event_type: str
    context: dict[str, Any]
    objective: str
    route: Literal["draft_communication", "create_task", "needs_review"]
    reasoning_summary: str
    recommendation: dict[str, Any]
    model_used: str | None


app = FastAPI(title="LodgeOps Agent Service", version="1.0.0")


def _fallback_route(state: AgentState) -> AgentState:
    event_type = state["event_type"]
    reservation = state.get("context", {}).get("reservation") or {}

    if event_type in {"reservation.confirmed", "reservation.created"}:
        route: Literal["draft_communication", "create_task", "needs_review"] = "draft_communication"
        recommendation = {
            "subject": "Reservation confirmed",
            "body": "Prepare a concise confirmation with the next guest-preparation steps.",
        }
        summary = "A confirmed reservation normally benefits from a guest-facing follow-up."
    elif event_type in {"payment.failed", "deposit.overdue", "reservation.attention_required"}:
        route = "create_task"
        recommendation = {
            "title": "Review reservation exception",
            "priority": "high",
            "reservation_id": reservation.get("id"),
        }
        summary = "The event represents an exception that should be handled by staff."
    else:
        route = "needs_review"
        recommendation = {"event_type": event_type}
        summary = "No deterministic action is configured for this event type."

    return {
        **state,
        "route": route,
        "reasoning_summary": summary,
        "recommendation": recommendation,
        "model_used": None,
    }


async def _llm_route(state: AgentState) -> AgentState:
    base_url = os.getenv("LLM_BASE_URL", "").rstrip("/")
    api_key = os.getenv("LLM_API_KEY", "")
    model = os.getenv("LLM_MODEL", "")

    if not (base_url and api_key and model):
        return _fallback_route(state)

    prompt = {
        "objective": state["objective"],
        "event_type": state["event_type"],
        "context": state.get("context", {}),
        "allowed_routes": ["draft_communication", "create_task", "needs_review"],
        "rules": [
            "Return JSON only.",
            "Do not execute side effects.",
            "Prefer needs_review when information is incomplete or the action may affect money, access, or a guest commitment.",
        ],
        "response_schema": {
            "route": "draft_communication | create_task | needs_review",
            "reasoning_summary": "short explanation",
            "recommendation": "object",
        },
    }

    async with httpx.AsyncClient(timeout=20.0) as client:
        response = await client.post(
            f"{base_url}/v1/chat/completions",
            headers={"Authorization": f"Bearer {api_key}"},
            json={
                "model": model,
                "temperature": 0.1,
                "messages": [
                    {
                        "role": "system",
                        "content": "You are an operations decision assistant. Produce safe, reviewable recommendations and never claim to have executed an action.",
                    },
                    {"role": "user", "content": json.dumps(prompt)},
                ],
                "response_format": {"type": "json_object"},
            },
        )
        response.raise_for_status()
        payload = response.json()

    parsed = json.loads(payload["choices"][0]["message"]["content"])
    route = parsed.get("route")
    if route not in {"draft_communication", "create_task", "needs_review"}:
        return _fallback_route(state)

    return {
        **state,
        "route": route,
        "reasoning_summary": str(parsed.get("reasoning_summary", "Model recommendation.")),
        "recommendation": parsed.get("recommendation") if isinstance(parsed.get("recommendation"), dict) else {},
        "model_used": model,
    }


def _finalize(state: AgentState) -> AgentState:
    recommendation = dict(state.get("recommendation", {}))
    recommendation["requires_human_approval"] = True
    return {**state, "recommendation": recommendation}


builder = StateGraph(AgentState)
builder.add_node("route", _llm_route)
builder.add_node("finalize", _finalize)
builder.add_edge(START, "route")
builder.add_edge("route", "finalize")
builder.add_edge("finalize", END)
graph = builder.compile()


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/v1/workflows/reservation-assistant", response_model=WorkflowResponse)
async def reservation_assistant(request: WorkflowRequest) -> WorkflowResponse:
    state = await graph.ainvoke(
        {
            "event_type": request.event_type,
            "context": request.context,
            "objective": request.objective,
        }
    )
    return WorkflowResponse(
        route=state["route"],
        reasoning_summary=state["reasoning_summary"],
        recommendation=state["recommendation"],
        model_used=state.get("model_used"),
    )
