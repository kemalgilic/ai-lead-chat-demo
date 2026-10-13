"""
Chat backend for the lead-qualification + booking demo.

- The Anthropic API key lives ONLY here (env var), never in the browser.
- Browser -> POST /chat -> Claude (tool use) -> POST to n8n webhook -> reply.
- Runs without a key in MOCK mode so the flow can be tested end to end.
"""
import os
import re
import time
from collections import defaultdict, deque

import httpx
from fastapi import FastAPI, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field

ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY", "")
MODEL = os.getenv("ANTHROPIC_MODEL", "claude-sonnet-4-5")
N8N_WEBHOOK_URL = os.getenv("N8N_WEBHOOK_URL", "")
ALLOWED_ORIGINS = os.getenv("ALLOWED_ORIGINS", "*").split(",")
MOCK = not ANTHROPIC_API_KEY

SYSTEM_PROMPT = """You are the website assistant of a dental practice with three locations.
Goal: answer briefly, qualify the visitor and book a consultation.
Collect, one question at a time and conversationally: full name, email, phone (optional),
what they need, how urgent it is, and preferred day/time.
Never invent prices, medical advice or availability. If unsure, say a team member will confirm.
As soon as you have name, email, need and urgency, call the tool submit_lead exactly once.
Keep replies under 60 words. Reply in the visitor's language."""

TOOLS = [
    {
        "name": "submit_lead",
        "description": "Submit a qualified lead and booking request to the CRM workflow.",
        "input_schema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "email": {"type": "string"},
                "phone": {"type": "string"},
                "need": {"type": "string", "description": "What the visitor wants"},
                "urgency": {"type": "string", "enum": ["low", "medium", "high"]},
                "preferred_time": {"type": "string"},
            },
            "required": ["name", "email", "need", "urgency"],
        },
    }
]

app = FastAPI(title="Lead Chat Backend")
app.add_middleware(
    CORSMiddleware,
    allow_origins=ALLOWED_ORIGINS,
    allow_methods=["POST", "GET"],
    allow_headers=["*"],
)

SESSIONS: dict[str, list] = defaultdict(list)
HITS: dict[str, deque] = defaultdict(deque)
EMAIL_RE = re.compile(r"[\w.+-]+@[\w-]+\.[\w.-]+")


class ChatIn(BaseModel):
    session_id: str = Field(min_length=6, max_length=64)
    message: str = Field(min_length=1, max_length=1000)


def rate_limit(ip: str, limit: int = 20, window: int = 60) -> None:
    now = time.time()
    q = HITS[ip]
    while q and now - q[0] > window:
        q.popleft()
    if len(q) >= limit:
        raise HTTPException(429, "Too many requests")
    q.append(now)


async def push_to_n8n(lead: dict) -> bool:
    if not N8N_WEBHOOK_URL:
        print("[n8n] N8N_WEBHOOK_URL not set, lead only logged:", lead)
        return False
    async with httpx.AsyncClient(timeout=15) as client:
        r = await client.post(N8N_WEBHOOK_URL, json=lead)
        return r.status_code < 300


def mock_reply(history: list) -> tuple[str, dict | None]:
    """Tiny scripted flow so the whole pipeline is testable without an API key."""
    user_msgs = [m["content"] for m in history if m["role"] == "user" and isinstance(m["content"], str)]
    text = " ".join(user_msgs)
    email = EMAIL_RE.search(text)
    if email and len(user_msgs) >= 3:
        lead = {
            "name": user_msgs[0][:60],
            "email": email.group(0),
            "need": user_msgs[1][:200],
            "urgency": "high" if re.search(r"urgent|asap|today|pain|schmerz|dringend", text, re.I) else "medium",
            "preferred_time": "",
        }
        return "Thanks! I have passed your request to the team. You will get a confirmation shortly.", lead
    prompts = [
        "Hi! I can help you book a visit. What is your name?",
        "Nice to meet you. What do you need help with, and how urgent is it?",
        "Got it. What is your email so we can confirm the appointment?",
    ]
    return prompts[min(len(user_msgs) - 1, 2)], None


@app.get("/health")
def health():
    return {"ok": True, "mode": "mock" if MOCK else "claude", "n8n": bool(N8N_WEBHOOK_URL)}


@app.post("/chat")
async def chat(body: ChatIn, request: Request):
    rate_limit(request.client.host if request.client else "unknown")
    history = SESSIONS[body.session_id]
    history.append({"role": "user", "content": body.message})

    lead = None
    reply = ""

    if MOCK:
        reply, lead = mock_reply(history)
    else:
        from anthropic import AsyncAnthropic

        client = AsyncAnthropic(api_key=ANTHROPIC_API_KEY)
        resp = await client.messages.create(
            model=MODEL,
            max_tokens=400,
            system=SYSTEM_PROMPT,
            tools=TOOLS,
            messages=history,
        )
        history.append({"role": "assistant", "content": resp.content})
        for block in resp.content:
            if block.type == "text":
                reply += block.text
            elif block.type == "tool_use" and block.name == "submit_lead":
                lead = block.input
                history.append(
                    {
                        "role": "user",
                        "content": [{"type": "tool_result", "tool_use_id": block.id, "content": "Lead submitted."}],
                    }
                )
        if lead and not reply:
            reply = "Thanks! Your request is with the team, you will get a confirmation shortly."

    sent = False
    if lead:
        lead["session_id"] = body.session_id
        lead["source"] = "website_chat"
        sent = await push_to_n8n(lead)

    if MOCK:
        history.append({"role": "assistant", "content": reply})
    return {"reply": reply, "lead_submitted": bool(lead), "n8n_delivered": sent}
