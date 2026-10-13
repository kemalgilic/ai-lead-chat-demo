# Chat Lead Demo (Widget, FastAPI, Claude, n8n)

Visitor chats on a website. A FastAPI backend (the only place the Claude key exists) runs the
conversation with Claude tool use, and a completed lead is posted to an n8n webhook that scores it
and routes it (hot = Slack alert, otherwise nurture queue).

```
Browser widget (JS) -> FastAPI /chat (key server-side) -> Claude (tool: submit_lead) -> n8n webhook -> Slack / queue
```

## Run locally

```bash
cd backend
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env        # fill ANTHROPIC_API_KEY (leave empty = mock mode)
set -a && source .env && set +a
uvicorn main:app --port 8000

# second terminal
cd widget && python -m http.server 8080   # open http://localhost:8080/demo.html
```

## Alternative backend: PHP proxy

`backend-php/proxy.php` is a drop-in, single-file PHP port of the FastAPI backend (for hosts that
only offer PHP). Same contract: `GET` for a health check, `POST` for chat, Anthropic key stays
server-side, CORS allow-list, per-IP rate limit, tool-use flow, and forwarding qualified leads to
n8n. Session history and rate-limit counters are cached as small JSON files on disk (requires PHP's
`curl` extension, enabled by default in most builds).

```bash
cd backend-php
cp .env.example .env        # fill ANTHROPIC_API_KEY (leave empty = mock mode), set ALLOWED_ORIGINS
php -S localhost:8000

# second terminal
cd widget && python -m http.server 8080   # open http://localhost:8080/demo.html
# point the widget's data-api attribute at http://localhost:8000/proxy.php
```

`.env` is loaded automatically by `proxy.php` for local convenience (never commit it). In
production, set the real environment variables through your host/PHP-FPM config instead.

## n8n
1. Import `n8n/lead-chat-workflow.json`.
2. Assign your Slack credential to "Slack: Hot Lead Alert" (channel `#gilic-ops-alerts`).
3. Test: click "Listen for test event", set `N8N_WEBHOOK_URL` to the **test** URL, chat once.
   For production publish the workflow and use the production URL.

## Speed-to-Lead workflow

`n8n/speed-to-lead-agent.json` is a separate, more advanced demo workflow: an inbound form lead is
normalized, checked by input guardrails, scored and drafted by an LLM agent, checked by output
guardrails, routed by priority tier (hot/warm/cold), and hot leads go through a Slack approve-and-send
step before a reply goes out.

To import it:
1. In n8n, go to **Workflows -> Import from File** and select `n8n/speed-to-lead-agent.json`.
2. The workflow imports as **inactive** — review it before activating.
3. Assign credentials yourself after import: the Slack, OpenAI, Anthropic, and Gmail nodes have no
   credentials attached. Re-attach your own for each node that uses them.
4. The two Gmail nodes (`Send Templated Nurture Email`, `Send Personalized Reply`) are **disabled**
   in this demo — enable them only once you've reviewed what they send and to whom.
5. Create these n8n Data Tables before running the workflow, with columns matching what each node
   writes/reads: `leads_log`, `guardrail_incidents`, `lead_assessments`, `sent_log`,
   `nurture_sequence`.

## Security notes
- No API key in front-end code; the widget only knows the backend URL.
- Widget renders model output with `textContent` (no XSS).
- Backend: input length limits, per-IP rate limit, CORS allow-list via `ALLOWED_ORIGINS`.
- `.env` must never be committed (applies to both `backend/.env` and `backend-php/.env`).
