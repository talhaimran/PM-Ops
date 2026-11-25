<?php

return [
    // self_hosted allows one workspace per install; cloud allows many (spec 7.0).
    'edition' => env('PM_EDITION', 'self_hosted'),

    // Requests per minute per API token (or per user/IP without a token). Applies to /api/v1 and the MCP web endpoint.
    'api_rate_limit' => (int) env('PM_API_RATE_LIMIT', 120),

    // Where Owners upgrade when a plan limit is reached (spec 7.0c). Empty: the plan-limit dialog shows no upgrade button.
    'billing_url' => env('PM_BILLING_URL'),

    // Invitation emails per hour per user (web and API), so the server can't be used to spam.
    'invite_rate_limit' => (int) env('PM_INVITE_RATE_LIMIT', 30),

    // API token the stdio MCP server (`php artisan mcp:start pm`) acts as. Create an agent at /{workspace}/settings/agents.
    'mcp_token' => env('PM_MCP_TOKEN'),

    // Seconds a blocks/precedes link waits for another one in the same workspace before "try again" (LinkService).
    'link_lock_timeout' => (int) env('PM_LINK_LOCK_TIMEOUT', 5),

    // Most issues one due date change may reach through precedes links (LinkService); more is refused, nothing saved.
    'shift_limit' => (int) env('PM_SHIFT_LIMIT', 500),
    // At most this many sub-tasks, and separately this many links, are copied by one clone (spec 7.9).
    'clone_limit' => (int) env('PM_CLONE_LIMIT', 100),

    // 3-R6: quick changes by one person to one issue join the recipient's newest unread notification for this many minutes.
    'notification_batch_minutes' => (int) env('PM_NOTIFICATION_BATCH_MINUTES', 5),

    // 3-R11: @mentions one agent may send per hour; over it the text saves, nobody is notified and the response warns.
    'agent_mention_limit' => (int) env('PM_AGENT_MENTION_LIMIT', 20),

    // How long a replayable Idempotency-Key response is kept.
    'idempotency_ttl_hours' => (int) env('PM_IDEMPOTENCY_TTL_HOURS', 24),

    // Self-hosted AI connection (spec 8, A5). With a provider here, Settings → AI is read-only ("Configured by your server")
    // and the key never leaves the server. Providers: anthropic, openai, google, openai_compatible.
    'ai' => [
        'provider' => env('PM_AI_PROVIDER'),
        'model' => env('PM_AI_MODEL'),
        'endpoint' => env('PM_AI_ENDPOINT'),
        'key' => env('PM_AI_KEY'),
    ],
];
