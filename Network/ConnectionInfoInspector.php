<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Network;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Diagnostic helper for the Connection Info page: reports which local interface a request arrived
 * on, resolves the end-user IP in a trusted-proxy-aware way, and exposes the raw proxy/forwarded
 * headers (all of which are client-controlled and must never be trusted blindly).
 *
 * The trusted-proxy list is supplied per call (entered by the user in the UI) rather than read from
 * framework config, so the page can be used to experiment with different proxy whitelists. Matching
 * accepts both plain IPs and CIDR ranges via {@see IpUtils::checkIp}.
 */
class ConnectionInfoInspector
{
    /** Forwarded/proxy headers worth surfacing — all UNTRUSTED unless the direct peer is whitelisted. */
    private const PROXY_HEADERS = [
        'X-Forwarded-For',
        'X-Real-IP',
        'Client-IP',
        'CF-Connecting-IP',
        'True-Client-IP',
        'X-Forwarded-Host',
        'X-Forwarded-Proto',
        'Forwarded',
    ];

    /**
     * Resolves the real client IP.
     *
     * Builds the hop chain nearest→furthest (REMOTE_ADDR, then X-Forwarded-For right-to-left) and
     * returns the first hop that is NOT one of the trusted proxies — the closest address whose claim
     * we have no reason to believe was forged.
     *
     * @param string[] $trustedProxies plain IPs and/or CIDR ranges
     *
     * @return array{ip:string, source:string, via:?string, chain:array<int,array{value:string, trusted:bool}>}
     */
    public function resolveClientIp(Request $request, array $trustedProxies): array
    {
        $remote = (string) $request->server->get('REMOTE_ADDR', '');

        // No trusted proxies, or the direct peer isn't one of them: REMOTE_ADDR is the truth.
        if ($trustedProxies === [] || !IpUtils::checkIp($remote, $trustedProxies)) {
            return [
                'ip' => $remote,
                'source' => 'remote_addr',
                'via' => null,
                'chain' => [['value' => $remote, 'trusted' => false]],
            ];
        }

        // Direct peer is trusted → we may inspect X-Forwarded-For (nearest hop first).
        $xffRaw = (string) $request->headers->get('X-Forwarded-For', '');
        $xff = array_values(array_filter(array_map('trim', explode(',', $xffRaw)), static fn ($v) => $v !== ''));
        $hops = array_merge([$remote], array_reverse($xff));

        $chain = [];
        $resolved = null;
        foreach ($hops as $hop) {
            $ip = $this->stripPort($hop);
            $trusted = $ip !== '' && IpUtils::checkIp($ip, $trustedProxies);
            $chain[] = ['value' => $hop, 'trusted' => $trusted];
            if ($resolved === null && !$trusted && filter_var($ip, FILTER_VALIDATE_IP)) {
                $resolved = $ip;
            }
        }

        if ($resolved !== null) {
            return ['ip' => $resolved, 'source' => 'forwarded', 'via' => $remote, 'chain' => $chain];
        }

        // Whole chain was trusted proxies — fall back to the outermost hop.
        $last = end($hops) ?: $remote;

        return ['ip' => $this->stripPort((string) $last) ?: $remote, 'source' => 'all_trusted', 'via' => null, 'chain' => $chain];
    }

    /**
     * Which local interface answered this request.
     *
     * @return array<string, string>
     */
    public function getServerInfo(Request $request): array
    {
        $https = $request->server->get('HTTPS');

        return [
            'server_addr' => (string) $request->server->get('SERVER_ADDR', ''),
            'server_port' => (string) $request->server->get('SERVER_PORT', ''),
            'server_name' => (string) $request->server->get('SERVER_NAME', ''),
            'host_header' => (string) $request->headers->get('Host', ''),
            'https' => (!empty($https) && $https !== 'off') ? 'yes' : 'no',
            'remote_addr' => (string) $request->server->get('REMOTE_ADDR', ''),
            'remote_port' => (string) $request->server->get('REMOTE_PORT', ''),
        ];
    }

    /**
     * Raw proxy / forwarded headers. These are attacker-controlled — never use them for access
     * control unless the direct peer is a proxy you operate and have whitelisted.
     *
     * @return array<string, ?string>
     */
    public function getProxyHeaders(Request $request): array
    {
        $headers = [];
        foreach (self::PROXY_HEADERS as $name) {
            $headers[$name] = $request->headers->get($name);
        }

        return $headers;
    }

    /**
     * Local network interfaces and their unicast addresses (PHP 8.3+; empty when unavailable).
     *
     * @return array<string, string[]>
     */
    public function getLocalInterfaces(): array
    {
        if (!function_exists('net_get_interfaces')) {
            return [];
        }

        $interfaces = net_get_interfaces();
        if ($interfaces === false) {
            return [];
        }

        $out = [];
        foreach ($interfaces as $name => $info) {
            $ips = [];
            foreach ($info['unicast'] ?? [] as $unicast) {
                if (!empty($unicast['address'])) {
                    $ips[] = $unicast['address'];
                }
            }
            $out[$name] = $ips;
        }

        return $out;
    }

    /**
     * Parses a comma-separated trusted-proxy list into validated IP / CIDR entries (invalid and
     * blank tokens are dropped).
     *
     * @return string[]
     */
    public function parseTrustedProxies(string $csv): array
    {
        $tokens = array_map('trim', explode(',', $csv));

        $valid = [];
        foreach ($tokens as $token) {
            if ($token !== '' && $this->isValidIpOrCidr($token)) {
                $valid[] = $token;
            }
        }

        return array_values(array_unique($valid));
    }

    /** Strips a trailing :port from an IPv4:port pair, leaving bare IPv6 (which contains colons) intact. */
    private function stripPort(string $hop): string
    {
        return substr_count($hop, ':') === 1 ? (string) preg_replace('/:\d+$/', '', $hop) : $hop;
    }

    private function isValidIpOrCidr(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (!str_contains($value, '/')) {
            return false;
        }

        [$ip, $prefix] = explode('/', $value, 2);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false && ctype_digit($prefix);
    }
}
