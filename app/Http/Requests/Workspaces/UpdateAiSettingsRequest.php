<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\AiProviderType;
use App\Services\AiSettingsService;
use App\Services\CurrentWorkspace;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Spec 8 (A5): the provider form. A blank key keeps the stored one; the key field is never flashed back (bootstrap/app.php). */
class UpdateAiSettingsRequest extends FormRequest
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** IPv4 ranges never a provider: this network, private, shared (CGNAT), loopback, link-local (incl. metadata), documentation, benchmarking, multicast, reserved. */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** The scheme is case-insensitive; store it in lowercase. */
    protected function prepareForValidation(): void
    {
        if (is_string($endpoint = $this->input('endpoint'))) {
            $this->merge(['endpoint' => preg_replace_callback('/^[a-z][a-z0-9+.-]*(?=:)/i', fn ($m) => strtolower($m[0]), $endpoint)]);
        }
    }

    public function rules(): array
    {
        $provider = AiProviderType::tryFrom((string) $this->input('provider'));
        $endpoint = $this->input('endpoint');
        $ai = app(AiSettingsService::class);
        $workspace = app(CurrentWorkspace::class)->get();
        // Providers that need a key ask for one the first time, and again when the provider or endpoint moves.
        $needsKey = $provider?->needsKey() && (! $ai->hasKey($workspace) || $ai->keyMoves($workspace, $provider, is_string($endpoint) ? $endpoint : null));

        return [
            'provider' => ['required', Rule::enum(AiProviderType::class)],
            'model' => ['required', 'string', 'max:100'],
            'endpoint' => [Rule::requiredIf($provider === AiProviderType::OpenAiCompatible), 'nullable', 'string', 'max:255', $this->endpointRule(...)],
            'api_key' => [Rule::requiredIf((bool) $needsKey), 'nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['api_key' => 'API key'];
    }

    /** Save-time only; the request-time SSRF guard (DNS resolve, pinned IP, redirects) is required before any provider call — see CLAUDE.md. */
    private function endpointRule(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }
        $url = parse_url($value);
        $scheme = $url['scheme'] ?? '';
        $host = rtrim(strtolower($url['host'] ?? ''), '.');
        $local = in_array($host, self::LOCAL_HOSTS, true);
        if (! $host || ! ($scheme === 'https' || ($scheme === 'http' && $local))) {
            $fail('Use an https:// address, or http://localhost for a model on this machine.');
        } elseif (isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            $fail('Leave the login, query and fragment out of the address. The key goes in the API key field.');
        } elseif ($local) {
            if (config('pm.edition') === 'cloud') {
                $fail('A local model address is only allowed on a self-hosted install.');
            }
        } elseif (! $this->isPublicHost($host)) {
            $fail('Use a public host name or IP address. Private, loopback, link-local, reserved, shared (100.64.0.0/10), multicast and cloud-metadata addresses are not allowed.');
        }
    }

    private function isPublicHost(string $host): bool
    {
        if ($host === 'metadata' || $host === 'instance-data' || str_ends_with($host, '.internal')) {
            return false;
        }
        if (str_starts_with($host, '[')) {
            $ip = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

            return $ip !== false && $this->isPublicIpv6(inet_pton($ip));
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->isPublicIpv4(ip2long($host));
        }

        // A last label of digits or 0x… is read as an IP by the resolver (2130706433, 127.1, 0x7f.0.0.1), not as a name.
        return ! preg_match('/(^|\.)(0x[0-9a-f]*|\d+)$/', $host);
    }

    private function isPublicIpv4(int $ip): bool
    {
        foreach (self::BLOCKED_V4 as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = $bits == 0 ? 0 : (-1 << (32 - (int) $bits)) & 0xFFFFFFFF;
            if (($ip & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /** @param  string  $b  16 packed bytes */
    private function isPublicIpv6(string $b): bool
    {
        $v4 = fn (string $four) => $this->isPublicIpv4(unpack('N', $four)[1]);
        $zeros = fn (int $n) => substr($b, 0, $n) === str_repeat("\0", $n);

        return match (true) {
            $zeros(10) && substr($b, 10, 2) === "\xff\xff" => $v4(substr($b, 12)), // ::ffff:a.b.c.d
            $zeros(12) => $v4(substr($b, 12)), // ::a.b.c.d, ::1 and :: (0.0.0.0/8 is blocked)
            substr($b, 0, 12) === "\x00\x64\xff\x9b".str_repeat("\0", 8) => $v4(substr($b, 12)), // 64:ff9b::/96 (NAT64)
            (ord($b[0]) & 0xFE) === 0xFC => false, // fc00::/7
            ord($b[0]) === 0xFE && (ord($b[1]) & 0xC0) === 0x80 => false, // fe80::/10
            ord($b[0]) === 0xFF => false, // ff00::/8
            default => true,
        };
    }
}
