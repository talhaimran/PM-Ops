<?php

// Owner review of PR 4: Reverb accepts WebSocket connections only from the app's own origins, never '*'.

function reverbOrigins(array $env): array
{
    $old = [];
    foreach ($env as $key => $value) {
        $old[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        setTestEnv($key, $value);
    }
    try {
        return (require config_path('reverb.php'))['apps']['apps'][0]['allowed_origins'];
    } finally {
        foreach ($old as $key => [$put, $envValue, $serverValue]) {
            setTestEnv($key, $put === false ? null : $put);
            $envValue === null ? null : $_ENV[$key] = $envValue;
            $serverValue === null ? null : $_SERVER[$key] = $serverValue;
        }
    }
}

/** env() reads $_SERVER and $_ENV before getenv(), so set (or clear) all three. */
function setTestEnv(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    } else {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}

test('without REVERB_ALLOWED_ORIGINS only the APP_URL host may connect', function () {
    expect(reverbOrigins(['REVERB_ALLOWED_ORIGINS' => null, 'APP_URL' => 'https://pm.example.com']))->toBe(['pm.example.com']);
});

test('an empty REVERB_ALLOWED_ORIGINS (as in .env.example) falls back to the APP_URL host', function () {
    expect(reverbOrigins(['REVERB_ALLOWED_ORIGINS' => '', 'APP_URL' => 'https://pm.example.com']))->toBe(['pm.example.com']);
});

test('REVERB_ALLOWED_ORIGINS lists hosts, comma separated, spaces trimmed', function () {
    expect(reverbOrigins(['REVERB_ALLOWED_ORIGINS' => 'pm.example.com, *.pm.example.com', 'APP_URL' => 'https://x.test']))
        ->toBe(['pm.example.com', '*.pm.example.com']);
});

test('the loaded config never allows every origin', function () {
    expect(config('reverb.apps.apps.0.allowed_origins'))->not->toContain('*')->not->toBeEmpty();
});
