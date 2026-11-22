<?php

namespace App\Services;

/**
 * Spec 8 (A5): what an AI endpoint address may be. Used by the form on save and by AiSettingsService to decide whether a
 * connection (the server's or a workspace's) is usable. Static checks only; the request-time SSRF guard (DNS resolve,
 * pinned IP, redirects) is required before any provider call, see CLAUDE.md.
 */
class AiEndpoint
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * IPv4 ranges never a provider: IANA special-purpose blocks (this network, private, shared/CGNAT, loopback, link-local
     * incl. cloud metadata, IETF assignments, documentation, 6to4 relay, benchmarking), multicast and reserved (incl. broadcast).
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** @return string|null A plain message saying what is allowed, or null when the address is acceptable. */
    public static function problem(string $endpoint): ?string
    {
        $url = parse_url($endpoint);
        $scheme = strtolower($url['scheme'] ?? '');
        $host = rtrim(strtolower($url['host'] ?? ''), '.');
        $local = in_array($host, self::LOCAL_HOSTS, true);

        return match (true) {
            ! $host || ! ($scheme === 'https' || ($scheme === 'http' && $local)) => 'Use an https:// address, or http://localhost for a model on this machine.',
            isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) => 'Leave the login, query and fragment out of the address. The key goes in the API key field.',
            $local => config('pm.edition') === 'cloud' ? 'A local model address is only allowed on a self-hosted install.' : null,
            ! self::isPublicHost($host) => 'Use a public host name or IP address. Private, loopback, link-local, reserved, shared (100.64.0.0/10), multicast and cloud-metadata addresses are not allowed.',
            default => null,
        };
    }

    private static function isPublicHost(string $host): bool
    {
        if ($host === 'metadata' || $host === 'instance-data' || str_ends_with($host, '.internal')) {
            return false;
        }
        if (str_starts_with($host, '[')) {
            $ip = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

            return $ip !== false && self::isPublicIpv6(inet_pton($ip));
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::isPublicIpv4(ip2long($host));
        }

        // A last label of digits or 0x… is read as an IP by the resolver (2130706433, 127.1, 0x7f.0.0.1), not as a name.
        return ! preg_match('/(^|\.)(0x[0-9a-f]*|\d+)$/', $host);
    }

    private static function isPublicIpv4(int $ip): bool
    {
        foreach (self::BLOCKED_V4 as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = (-1 << (32 - (int) $bits)) & 0xFFFFFFFF;
            if (($ip & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * An allowlist: embedded IPv4 (::ffff:a.b.c.d, ::a.b.c.d, 64:ff9b::/96) is checked as IPv4; otherwise only global unicast
     * 2000::/3 passes, minus 2001::/23 (IETF protocol assignments: Teredo, benchmarking, ORCHID), 2001:db8::/32 and 3fff::/20
     * (documentation) and 2002::/16 (6to4). Everything else is refused: ::, ::1, 100::/64, 64:ff9b:1::/48, fc00::/7, fe80::/10, ff00::/8, ...
     *
     * @param  string  $b  16 packed bytes
     */
    private static function isPublicIpv6(string $b): bool
    {
        $v4 = fn (string $four) => self::isPublicIpv4(unpack('N', $four)[1]);
        $zeros = fn (int $n) => substr($b, 0, $n) === str_repeat("\0", $n);
        [$b0, $b1, $b2, $b3] = array_values(unpack('C4', $b));

        return match (true) {
            $zeros(10) && substr($b, 10, 2) === "\xff\xff" => $v4(substr($b, 12)),
            $zeros(12) => $v4(substr($b, 12)), // 0.0.0.0/8 is blocked, so :: and ::1 fall out here
            substr($b, 0, 12) === "\x00\x64\xff\x9b".str_repeat("\0", 8) => $v4(substr($b, 12)),
            ($b0 & 0xE0) !== 0x20 => false,
            $b0 === 0x20 && $b1 === 0x01 && ($b2 & 0xFE) === 0 => false,
            $b0 === 0x20 && $b1 === 0x01 && $b2 === 0x0D && $b3 === 0xB8 => false,
            $b0 === 0x3F && $b1 === 0xFF && ($b2 & 0xF0) === 0 => false,
            $b0 === 0x20 && $b1 === 0x02 => false,
            default => true,
        };
    }
}
