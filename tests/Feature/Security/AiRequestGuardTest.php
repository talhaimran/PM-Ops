<?php

use App\Models\User;
use App\Services\AiEndpoint;
use App\Services\AiRequestGuard;
use App\Services\AiSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

// CLAUDE.md "Agents and AI": every outbound AI request resolves DNS, refuses non-public answers, pins the checked IP and
// doesn't follow redirects. Resolution is faked through the guard's seam, except one .invalid lookup.

beforeEach(fn () => config(['pm.edition' => 'self_hosted']));
afterEach(fn () => AiRequestGuard::resolveUsing(null));

function resolvesTo(array $ips): void
{
    AiRequestGuard::resolveUsing(fn (string $host) => $ips);
}

function guardRefusal(string $endpoint): string
{
    try {
        AiRequestGuard::options($endpoint);
    } catch (ValidationException $e) {
        return $e->getMessage();
    }

    return 'allowed';
}

test('a static problem is refused with AiEndpoint\'s message, before any lookup', function () {
    AiRequestGuard::resolveUsing(fn () => throw new RuntimeException('looked up'));

    foreach (['http://api.example.com', 'https://user:pw@api.example.com', 'https://10.0.0.1', 'https://metadata.google.internal'] as $endpoint) {
        expect(guardRefusal($endpoint))->toBe(AiEndpoint::problem($endpoint));
    }
});

test('a public answer is pinned, redirects are off and timeouts are set', function () {
    resolvesTo(['93.184.216.34']);

    $options = AiRequestGuard::options('https://api.example.com/v1');

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['api.example.com:443:93.184.216.34'])
        ->and($options['connect_timeout'])->toBeGreaterThan(0)
        ->and($options['timeout'])->toBeGreaterThan(0);
});

test('every answer must be public: one private answer refuses', function () {
    resolvesTo(['93.184.216.34', '10.0.0.5']);

    expect(guardRefusal('https://api.example.com'))->toContain('private');
});

test('loopback, metadata, IPv4-mapped, unique-local and Azure platform answers are refused', function (string $ip) {
    resolvesTo([$ip]);

    expect(guardRefusal('https://api.example.com'))->toContain('private');
})->with(['127.0.0.1', '169.254.169.254', '::ffff:10.0.0.1', 'fd00::1', '168.63.129.16', '100.64.0.1', 'not-an-ip']);

test('a host that resolves to nothing is refused', function () {
    resolvesTo([]);

    expect(guardRefusal('https://api.example.com'))->toContain("couldn't be resolved");
});

test('the real resolver reads A and AAAA records and refuses a name with none', function () {
    expect(guardRefusal('https://nothing-here.invalid'))->toContain("couldn't be resolved");
});

test('localhost is allowed self-hosted, pinned to 127.0.0.1, and refused on Cloud', function () {
    AiRequestGuard::resolveUsing(fn () => throw new RuntimeException('looked up'));

    expect(AiRequestGuard::options('http://localhost:11434')['curl'][CURLOPT_RESOLVE])->toBe(['localhost:11434:127.0.0.1'])
        ->and(AiRequestGuard::options('http://localhost.:11434')['curl'][CURLOPT_RESOLVE])->toBe(['localhost.:11434:127.0.0.1'])
        ->and(AiRequestGuard::options('http://[::1]:11434')['curl'][CURLOPT_RESOLVE])->toBe(['[::1]:11434:[::1]']);

    config(['pm.edition' => 'cloud']);
    expect(guardRefusal('http://localhost:11434'))->toBe('A local model address is only allowed on a self-hosted install.');
});

test('an IP-literal host is pinned as itself without a lookup', function () {
    AiRequestGuard::resolveUsing(fn () => throw new RuntimeException('looked up'));

    expect(AiRequestGuard::options('https://93.184.216.34:8443')['curl'][CURLOPT_RESOLVE])->toBe(['93.184.216.34:8443:93.184.216.34'])
        ->and(AiRequestGuard::options('https://[2606:4700::1]')['curl'][CURLOPT_RESOLVE])->toBe(['[2606:4700::1]:443:[2606:4700::1]']);
});

test('an IPv6 answer is pinned in brackets, and the first answer is pinned once all pass', function () {
    resolvesTo(['2606:4700::1', '93.184.216.34']);

    expect(AiRequestGuard::options('https://api.example.com')['curl'][CURLOPT_RESOLVE])->toBe(['api.example.com:443:[2606:4700::1]']);
});

test('the port comes from the address, or the scheme default', function () {
    resolvesTo(['93.184.216.34']);

    expect(AiRequestGuard::options('https://api.example.com:8443/v1')['curl'][CURLOPT_RESOLVE])->toBe(['api.example.com:8443:93.184.216.34']);
});

test('a trailing dot is looked up without it and pinned with it, since curl keys its pin by the host as written', function () {
    $asked = null;
    AiRequestGuard::resolveUsing(function (string $host) use (&$asked) {
        $asked = $host;

        return ['93.184.216.34'];
    });

    expect(AiRequestGuard::options('https://API.Example.com./v1')['curl'][CURLOPT_RESOLVE])->toBe(['api.example.com.:443:93.184.216.34'])
        ->and($asked)->toBe('api.example.com');
});

test('a proxy from the environment is turned off, since a proxy would resolve the host itself', function () {
    resolvesTo(['93.184.216.34']);

    expect(AiRequestGuard::options('https://api.example.com')['proxy'])->toBe('');
});

test('http() does not follow a redirect', function () {
    resolvesTo(['93.184.216.34']);
    Http::fake(['api.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

    $response = AiRequestGuard::http('https://api.example.com/v1')->post('https://api.example.com/v1/chat');

    expect($response->status())->toBe(302);
    Http::assertSentCount(1);
});

test('http() refuses a request to a host other than the checked one, so it never resolves unpinned', function () {
    resolvesTo(['93.184.216.34']);
    Http::fake();

    expect(fn () => AiRequestGuard::http('https://api.example.com')->get('https://other.example.com/x'))->toThrow(ValidationException::class)
        ->and(fn () => AiRequestGuard::http('https://api.example.com')->get('https://api.example.com:8443/x'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

test('an endpoint from the server environment (PM_AI_*) goes through the same checks', function () {
    $owner = User::factory()->create();
    $workspace = makeWorkspace($owner, 'acme');
    config(['pm.ai' => ['provider' => 'openai_compatible', 'model' => 'm', 'endpoint' => 'https://llm.example.com/v1', 'key' => 'sk-test']]);
    $endpoint = app(AiSettingsService::class)->for($workspace)['endpoint'];

    resolvesTo(['192.168.1.10']);
    expect(guardRefusal($endpoint))->toContain('private');

    resolvesTo(['93.184.216.34']);
    expect(AiRequestGuard::options($endpoint)['curl'][CURLOPT_RESOLVE])->toBe(['llm.example.com:443:93.184.216.34']);
});
