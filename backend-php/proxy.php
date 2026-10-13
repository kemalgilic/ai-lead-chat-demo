<?php
/**
 * Chat backend for the lead-qualification + booking demo (PHP port of backend/main.py).
 *
 * - The Anthropic API key lives ONLY here (env var), never in the browser.
 * - Browser -> POST proxy.php -> Claude (tool use) -> POST to n8n webhook -> reply.
 * - Runs without a key in MOCK mode so the flow can be tested end to end.
 * - GET proxy.php -> health check.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Optional .env loading, for convenience with `php -S` (which has no built-in
// dotenv support). Never commit a real .env; this only reads local files and
// does not override variables already set in the real environment.
// ---------------------------------------------------------------------------

$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

// ---------------------------------------------------------------------------
// Configuration (env vars only - never hardcode secrets here)
// ---------------------------------------------------------------------------

$ANTHROPIC_API_KEY = getenv('ANTHROPIC_API_KEY') ?: '';
$MODEL = getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-5';
$N8N_WEBHOOK_URL = getenv('N8N_WEBHOOK_URL') ?: '';
$ALLOWED_ORIGINS = array_filter(array_map('trim', explode(',', getenv('ALLOWED_ORIGINS') ?: '')));
$MOCK = $ANTHROPIC_API_KEY === '';

// Where session history / rate-limit counters are cached between requests.
// Override with STORAGE_DIR if the default temp dir isn't writable/shared.
$STORAGE_DIR = getenv('STORAGE_DIR') ?: (sys_get_temp_dir() . '/chat-demo-proxy');
if (!is_dir($STORAGE_DIR)) {
    mkdir($STORAGE_DIR, 0700, true);
}

const SYSTEM_PROMPT = <<<PROMPT
You are the website assistant of a dental practice with three locations.
Goal: answer briefly, qualify the visitor and book a consultation.
Collect, one question at a time and conversationally: full name, email, phone (optional),
what they need, how urgent it is, and preferred day/time.
Never invent prices, medical advice or availability. If unsure, say a team member will confirm.
As soon as you have name, email, need and urgency, call the tool submit_lead exactly once.
Keep replies under 60 words. Reply in the visitor's language.
PROMPT;

const TOOLS = [
    [
        'name' => 'submit_lead',
        'description' => 'Submit a qualified lead and booking request to the CRM workflow.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'phone' => ['type' => 'string'],
                'need' => ['type' => 'string', 'description' => 'What the visitor wants'],
                'urgency' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'preferred_time' => ['type' => 'string'],
            ],
            'required' => ['name', 'email', 'need', 'urgency'],
        ],
    ],
];

const EMAIL_RE = '/[\w.+-]+@[\w-]+\.[\w.-]+/';

// ---------------------------------------------------------------------------
// CORS - only the configured origin(s) may call this endpoint
// ---------------------------------------------------------------------------

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function json_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/** Simple per-IP sliding-window rate limit backed by a flock'ed file. */
function rate_limit(string $storageDir, string $ip, int $limit = 20, int $window = 60): void
{
    $path = $storageDir . '/rl_' . sha1($ip) . '.json';
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        return; // fail open rather than break the demo on a storage issue
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $hits = $raw ? json_decode($raw, true) : [];
    if (!is_array($hits)) {
        $hits = [];
    }
    $now = microtime(true);
    $hits = array_values(array_filter($hits, fn($t) => $now - $t <= $window));
    if (count($hits) >= $limit) {
        flock($fh, LOCK_UN);
        fclose($fh);
        json_error(429, 'Too many requests');
    }
    $hits[] = $now;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($hits));
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** Load/save the per-session chat history (bounded to keep the demo lightweight). */
function load_history(string $storageDir, string $sessionId): array
{
    $path = $storageDir . '/session_' . sha1($sessionId) . '.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function save_history(string $storageDir, string $sessionId, array $history): void
{
    $path = $storageDir . '/session_' . sha1($sessionId) . '.json';
    $history = array_slice($history, -40); // keep last 40 turns
    file_put_contents($path, json_encode($history), LOCK_EX);
}

function push_to_n8n(string $webhookUrl, array $lead): bool
{
    if ($webhookUrl === '') {
        error_log('[n8n] N8N_WEBHOOK_URL not set, lead only logged: ' . json_encode($lead));
        return false;
    }
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($lead),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status > 0 && $status < 300;
}

/** Tiny scripted flow so the whole pipeline is testable without an API key. */
function mock_reply(array $history): array
{
    $userMsgs = array_values(array_map(
        fn($m) => $m['content'],
        array_filter($history, fn($m) => $m['role'] === 'user' && is_string($m['content']))
    ));
    $text = implode(' ', $userMsgs);
    $lead = null;
    if (preg_match(EMAIL_RE, $text, $m) && count($userMsgs) >= 3) {
        $lead = [
            'name' => substr($userMsgs[0], 0, 60),
            'email' => $m[0],
            'need' => substr($userMsgs[1], 0, 200),
            'urgency' => preg_match('/urgent|asap|today|pain|schmerz|dringend/i', $text) ? 'high' : 'medium',
            'preferred_time' => '',
        ];
        return ['Thanks! I have passed your request to the team. You will get a confirmation shortly.', $lead];
    }
    $prompts = [
        'Hi! I can help you book a visit. What is your name?',
        'Nice to meet you. What do you need help with, and how urgent is it?',
        'Got it. What is your email so we can confirm the appointment?',
    ];
    $idx = min(count($userMsgs) - 1, 2);
    return [$prompts[max($idx, 0)], null];
}

/** Call the Anthropic Messages API with tool use. Returns [replyText, lead|null, assistantContentBlocks]. */
function call_claude(string $apiKey, string $model, array $history): array
{
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'max_tokens' => 400,
            'system' => SYSTEM_PROMPT,
            'tools' => TOOLS,
            'messages' => $history,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Anthropic API request failed: ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $body, true);
    if ($status >= 300 || !is_array($data)) {
        throw new RuntimeException('Anthropic API error (' . $status . '): ' . $body);
    }

    $reply = '';
    $lead = null;
    $content = $data['content'] ?? [];
    foreach ($content as $block) {
        if (($block['type'] ?? '') === 'text') {
            $reply .= $block['text'];
        } elseif (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'submit_lead') {
            $lead = $block['input'];
        }
    }
    return [$reply, $lead, $content];
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'ok' => true,
        'mode' => $MOCK ? 'mock' : 'claude',
        'n8n' => $N8N_WEBHOOK_URL !== '',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Method not allowed');
}

rate_limit($STORAGE_DIR, client_ip());

$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    json_error(400, 'Invalid JSON body');
}

$sessionId = $body['session_id'] ?? '';
$message = $body['message'] ?? '';

if (!is_string($sessionId) || strlen($sessionId) < 6 || strlen($sessionId) > 64) {
    json_error(422, 'session_id must be a string between 6 and 64 characters');
}
if (!is_string($message) || strlen($message) < 1 || strlen($message) > 1000) {
    json_error(422, 'message must be a string between 1 and 1000 characters');
}

$history = load_history($STORAGE_DIR, $sessionId);
$history[] = ['role' => 'user', 'content' => $message];

$lead = null;
$reply = '';

if ($MOCK) {
    [$reply, $lead] = mock_reply($history);
} else {
    try {
        [$reply, $lead, $assistantContent] = call_claude($ANTHROPIC_API_KEY, $MODEL, $history);
    } catch (Throwable $e) {
        error_log('[claude] ' . $e->getMessage());
        json_error(502, 'Upstream chat service error');
    }
    $history[] = ['role' => 'assistant', 'content' => $assistantContent];
    if ($lead !== null) {
        $toolUseId = null;
        foreach ($assistantContent as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'submit_lead') {
                $toolUseId = $block['id'];
                break;
            }
        }
        if ($toolUseId !== null) {
            $history[] = [
                'role' => 'user',
                'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => $toolUseId,
                    'content' => 'Lead submitted.',
                ]],
            ];
        }
        if ($reply === '') {
            $reply = 'Thanks! Your request is with the team, you will get a confirmation shortly.';
        }
    }
}

$sent = false;
if ($lead !== null) {
    $lead['session_id'] = $sessionId;
    $lead['source'] = 'website_chat';
    $sent = push_to_n8n($N8N_WEBHOOK_URL, $lead);
}

if ($MOCK) {
    $history[] = ['role' => 'assistant', 'content' => $reply];
}
save_history($STORAGE_DIR, $sessionId, $history);

echo json_encode([
    'reply' => $reply,
    'lead_submitted' => $lead !== null,
    'n8n_delivered' => $sent,
]);
