<?php

namespace App\Services;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\RequestInterface;

/**
 * CLAUDE.md "Agents and AI": the only way out to an AI endpoint. On top of AiEndpoint's static checks it resolves the
 * host, refuses it unless every answer is public, pins the connection to the checked IP and turns redirects off. It never
 * reads the AI key; callers add it from AiSettingsService::key().
 */
class AiRequestGuard
{
    private static ?Closure $resolver = null;

    /** Tests only: fn (string $host): list<string> of IPs, in place of the DNS lookup; null restores it. */
    public static function resolveUsing(?Closure $fn): void
    {
        self::$resolver = $fn;
    }

    /**
     * A client for the endpoint's own scheme, host and port; a request anywhere else is refused, since it wouldn't be pinned.
     * Callers must not override its `proxy`, `curl` or `allow_redirects` options.
     */
    public static function http(string $endpoint): PendingRequest
    {
        $options = self::options($endpoint);
        [$host, $port, $scheme] = self::hostAndPort($endpoint);

        // The pin is a curl option; Guzzle's stream handler (`stream: true`, or no curl extension) refuses it rather than skip it.
        // shortcut: no streamed replies on the curl path (it buffers the body), step B needs another approach for streaming.
        return Http::withOptions($options)
            ->withRequestMiddleware(function (RequestInterface $request) use ($host, $port, $scheme) {
                $uri = $request->getUri();
                if ([$uri->getScheme(), $uri->getHost(), $uri->getPort() ?? self::defaultPort($uri->getScheme())] !== [$scheme, $host, $port]) {
                    self::refuse("AI requests can only go to the checked endpoint's scheme, host and port.");
                }

                return $request;
            });
    }

    /**
     * Guzzle options that connect only to the checked IP. @throws ValidationException with a plain message when refused
     * shortcut: a caller using these with its own client doesn't get http()'s scheme/host/port check, use http() unless that's added.
     */
    public static function options(string $endpoint): array
    {
        if ($problem = AiEndpoint::problem($endpoint)) {
            self::refuse($problem);
        }
        [$host, $port] = self::hostAndPort($endpoint);
        $bare = rtrim(trim($host, '[]'), '.');

        $ips = match (true) {
            $bare === 'localhost' => ['127.0.0.1'], // only reached self-hosted: problem() refuses it on Cloud
            filter_var($bare, FILTER_VALIDATE_IP) !== false => [$bare],
            default => (self::$resolver ?? self::lookup(...))($bare),
        };
        if (! $ips) {
            self::refuse("The AI endpoint's host couldn't be resolved.");
        }
        // problem() has already allowed localhost, 127.0.0.1 and [::1] (self-hosted only); everything else must be public.
        if (! in_array(rtrim($host, '.'), AiEndpoint::LOCAL_HOSTS, true) && array_filter($ips, fn ($ip) => ! AiEndpoint::isPublicIp((string) $ip))) {
            self::refuse("The AI endpoint's host resolves to a private or reserved address (private, loopback, link-local, reserved, shared, multicast or cloud metadata), so it can't be used.");
        }
        // shortcut: only the first answer is pinned, so an AAAA-first host fails without an IPv6 route; try the next answer or prefer A if that shows up.
        $ip = str_contains($ips[0], ':') ? "[{$ips[0]}]" : $ips[0];

        return [
            'allow_redirects' => false,
            'connect_timeout' => 5,
            'timeout' => 60,
            // A proxy (e.g. from HTTPS_PROXY) would resolve the host itself and bypass the pin; '' turns it off.
            // shortcut: self-hosted egress proxies can't be used, add an explicit proxy setting if installs need it.
            'proxy' => '',
            // Pinning the checked IP is what stops DNS rebinding between this check and curl's connect: curl never
            // resolves the host again. Its key is the host as written in the URL (trailing dot included).
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
        ];
    }

    /** @return array{string, int, string} The lowercased host as written in the URL (brackets and trailing dot kept), the port and the scheme. */
    private static function hostAndPort(string $endpoint): array
    {
        $url = parse_url($endpoint);
        $scheme = strtolower($url['scheme']);

        return [strtolower($url['host']), $url['port'] ?? self::defaultPort($scheme), $scheme];
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    /** @return list<string> */
    private static function lookup(string $host): array
    {
        // @: a failed lookup warns; no answer is refused by the caller.
        // shortcut: dns_get_record ignores /etc/hosts and nsswitch (fails closed), switch resolvers if hosts-file or split-horizon names are needed.
        return self::ipsFrom(@dns_get_record($host, DNS_A | DNS_AAAA) ?: []);
    }

    /** The A and AAAA addresses in dns_get_record() output; other records (e.g. CNAME) are dropped. @return list<string> */
    public static function ipsFrom(array $records): array
    {
        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }

    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages(['endpoint' => $message]);
    }
}
