<?php

// laravel/mcp settings for OAuth dynamic client registration (A-D3); the rest keep the package defaults.
$list = fn (string $key, string $default) => array_values(array_filter(array_map('trim', explode(',', (string) env($key, $default)))));

return [
    // Hosts an MCP client may send people back to after consent. A bare host means https; localhost and 127.0.0.1
    // also allow any http://localhost:port callback (desktop clients).
    'redirect_domains' => array_map(fn (string $d) => str_contains($d, '://') ? rtrim($d, '/') : "https://{$d}",
        $list('PM_MCP_REDIRECT_DOMAINS', 'localhost,127.0.0.1,claude.ai,claude.com')),

    // App schemes (cursor://…) allowed as callbacks. javascript, data, file and vbscript are always refused.
    'custom_schemes' => $list('PM_MCP_REDIRECT_SCHEMES', 'cursor,vscode'),
];
