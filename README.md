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

## Security notes
- No API key in front-end code; the widget only knows the backend URL.
- Widget renders model output with `textContent` (no XSS).
- Backend: input length limits, per-IP rate limit, CORS allow-list via `ALLOWED_ORIGINS`.
- `.env` must never be committed (applies to both `backend/.env` and `backend-php/.env`).
