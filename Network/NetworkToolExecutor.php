<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Network;

/**
 * Runs network diagnostic tools (DNS, ping, traceroute, socket, curl, SSL cert, ciphers)
 * using pure PHP. ICMP-based tools fall back to TCP because the web process cannot open
 * raw sockets; output states the method used.
 */
class NetworkToolExecutor
{
    public const array TOOLS = ['dns', 'ping', 'traceroute', 'socket', 'curl', 'ssl-cert', 'ciphers'];

    /** Human-readable labels for each tool, used for audit history. */
    public const array TOOL_LABELS = [
        'dns' => 'DNS Lookup',
        'ping' => 'Ping',
        'traceroute' => 'Traceroute',
        'socket' => 'Socket',
        'curl' => 'Curl',
        'ssl-cert' => 'SSL Certificate',
        'ciphers' => 'Ciphers',
    ];

    /** Linux value of the IP_TTL socket option (the constant is not exposed by PHP). */
    private const int IP_TTL = 2;

    /** Common TLS (<= 1.2) cipher suites enumerated by the cipher scanner. */
    private const array CIPHER_LIST = [
        'ECDHE-ECDSA-AES128-GCM-SHA256', 'ECDHE-RSA-AES128-GCM-SHA256',
        'ECDHE-ECDSA-AES256-GCM-SHA384', 'ECDHE-RSA-AES256-GCM-SHA384',
        'ECDHE-ECDSA-CHACHA20-POLY1305', 'ECDHE-RSA-CHACHA20-POLY1305',
        'ECDHE-RSA-AES128-SHA', 'ECDHE-RSA-AES256-SHA',
        'DHE-RSA-AES128-GCM-SHA256', 'DHE-RSA-AES256-GCM-SHA384',
        'AES128-GCM-SHA256', 'AES256-GCM-SHA384',
        'AES128-SHA', 'AES256-SHA', 'AES128-SHA256', 'AES256-SHA256',
        'CAMELLIA128-SHA', 'CAMELLIA256-SHA', 'DES-CBC3-SHA',
    ];

    /**
     * @param array{host?: string, port?: int|string|null, path?: string} $params
     */
    public function run(string $tool, array $params): string
    {
        return match ($tool) {
            'dns' => $this->dns($this->host($params), $this->dnsMode($params), $this->timeoutValue($params, 10)),
            'ping' => $this->ping($this->host($params), $this->port($params, 80), $this->timeoutValue($params, 2)),
            'traceroute' => $this->traceroute($this->host($params), $this->port($params, 80), $this->timeoutValue($params, 25)),
            'socket' => $this->socket($this->host($params), $this->requirePort($params), $this->timeoutValue($params, 3)),
            'curl' => $this->curl($params, $this->timeoutValue($params, 10)),
            'ssl-cert' => $this->sslCertificate($this->host($params), $this->port($params, 443), $this->timeoutValue($params, 10)),
            'ciphers' => $this->ciphers($this->host($params), $this->port($params, 443), $this->timeoutValue($params, 5)),
            default => throw new \InvalidArgumentException(sprintf('Unknown tool "%s".', $tool)),
        };
    }

    // --- DNS -----------------------------------------------------------------

    private function dns(string $host, string $mode, int $timeout): string
    {
        $out = ["dns {$host}", ''];
        $types = $mode === 'a'
            ? ['A' => DNS_A]
            : [
                'A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX,
                'NS' => DNS_NS, 'TXT' => DNS_TXT, 'SOA' => DNS_SOA, 'SRV' => DNS_SRV, 'CAA' => DNS_CAA,
            ];

        // dns_get_record() has no timeout: a missing record type on a non-resolving host makes the
        // system resolver retry for ~8s per query, so a full lookup can block ~48s and trip the
        // gateway timeout (504). Tighten the resolver for the duration of the lookup and cap the
        // total time to the requested timeout.
        $previousResOptions = getenv('RES_OPTIONS');
        putenv('RES_OPTIONS=timeout:2 attempts:1');
        $deadline = microtime(true) + max(1, $timeout);

        $found = false;
        $aborted = false;
        try {
            foreach ($types as $label => $type) {
                if (microtime(true) >= $deadline) {
                    $aborted = true;
                    break;
                }
                $records = @dns_get_record($host, $type);
                if (!$records) {
                    continue;
                }
                foreach ($records as $r) {
                    $found = true;
                    $out[] = $this->formatDnsRecord($label, $r);
                }
            }
        } finally {
            if ($previousResOptions === false) {
                putenv('RES_OPTIONS');
            } else {
                putenv('RES_OPTIONS=' . $previousResOptions);
            }
        }

        if (!$found) {
            $out[] = 'No DNS records found.';
        }
        if ($aborted) {
            $out[] = '';
            $out[] = '(some record types were skipped: DNS lookup time limit reached)';
        }

        return implode("\n", $out);
    }

    private function dnsMode(array $params): string
    {
        return ($params['mode'] ?? '') === 'a' ? 'a' : 'full';
    }

    private function formatDnsRecord(string $label, array $r): string
    {
        $value = match ($label) {
            'A' => $r['ip'] ?? '',
            'AAAA' => $r['ipv6'] ?? '',
            'CNAME', 'NS' => $r['target'] ?? '',
            'MX' => sprintf('%s (priority %d)', $r['target'] ?? '', $r['pri'] ?? 0),
            'TXT' => $r['txt'] ?? '',
            'SOA' => sprintf('%s %s (serial %s)', $r['mname'] ?? '', $r['rname'] ?? '', $r['serial'] ?? ''),
            'SRV' => sprintf('%s:%d (priority %d, weight %d)', $r['target'] ?? '', $r['port'] ?? 0, $r['pri'] ?? 0, $r['weight'] ?? 0),
            'CAA' => sprintf('%s %s', $r['tag'] ?? '', $r['value'] ?? ''),
            default => json_encode($r),
        };

        return sprintf('%-6s %-6s %s', $label, $r['ttl'] ?? '', $value);
    }

    // --- Ping / Socket (TCP) -------------------------------------------------

    private function ping(string $host, int $port, int $timeout): string
    {
        $ip = gethostbyname($host);
        $out = [
            "ping {$host} ({$ip})",
            sprintf('ICMP is unavailable in this environment; using a TCP connect probe to port %d.', $port),
            '',
        ];

        $count = 4;
        $rtts = [];
        for ($i = 1; $i <= $count; $i++) {
            [$ok, $rtt, $err] = $this->tcpProbe($ip, $port, (float) $timeout);
            if ($ok) {
                $rtts[] = $rtt;
                $out[] = sprintf('Reply from %s:%d: time=%.1f ms', $ip, $port, $rtt);
            } else {
                $out[] = sprintf('Probe %d: no reply (%s)', $i, $err);
            }
            usleep(200000);
        }

        $received = \count($rtts);
        $loss = (int) round(($count - $received) / $count * 100);
        $out[] = '';
        $out[] = sprintf('%d probes sent, %d successful, %d%% loss', $count, $received, $loss);
        if ($rtts) {
            $out[] = sprintf(
                'rtt min/avg/max = %.1f/%.1f/%.1f ms',
                min($rtts),
                array_sum($rtts) / $received,
                max($rtts)
            );
        }

        return implode("\n", $out);
    }

    private function socket(string $host, int $port, int $timeout): string
    {
        $ip = gethostbyname($host);
        $out = ["socket {$host} {$port}", ''];

        for ($i = 1; $i <= 5; $i++) {
            [$ok, $rtt, $err] = $this->tcpProbe($ip, $port, (float) $timeout);
            $out[] = $ok
                ? sprintf('Probe %d: Connection successful, RTT=%dms', $i, (int) round($rtt))
                : sprintf('Probe %d: Connection failed (%s)', $i, $err);
        }
        $out[] = 'socket test completed';

        return implode("\n", $out);
    }

    /**
     * @return array{0: bool, 1: float, 2: string} [success, rttMs, error]
     */
    private function tcpProbe(string $ip, int $port, float $timeout): array
    {
        $errno = 0;
        $errstr = '';
        $start = microtime(true);
        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        $rtt = (microtime(true) - $start) * 1000;
        if ($fp) {
            fclose($fp);

            return [true, $rtt, ''];
        }

        return [false, $rtt, $errstr !== '' ? $errstr : ('errno ' . $errno)];
    }

    // --- Traceroute (TCP, TTL-based) -----------------------------------------

    private function traceroute(string $host, int $port, int $timeout): string
    {
        $ip = gethostbyname($host);
        $out = [
            "traceroute to {$host} ({$ip}), TCP port {$port}, max 30 hops",
            'Note: intermediate hop addresses require raw-socket privileges (unavailable here);',
            'reporting per-TTL reachability and the hop at which the destination responds.',
            '',
        ];

        $maxHops = 30;
        $deadline = microtime(true) + (float) $timeout;
        $reached = false;

        for ($ttl = 1; $ttl <= $maxHops; $ttl++) {
            if (microtime(true) > $deadline) {
                $out[] = '... aborted (time limit reached)';
                break;
            }

            $sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($sock === false) {
                $out[] = 'Unable to create socket.';
                break;
            }
            @socket_set_option($sock, IPPROTO_IP, self::IP_TTL, $ttl);
            @socket_set_nonblock($sock);

            $start = microtime(true);
            @socket_connect($sock, $ip, $port);
            $read = null;
            $write = [$sock];
            $except = [$sock];
            $ready = @socket_select($read, $write, $except, 1, 200000);
            $rtt = (microtime(true) - $start) * 1000;

            if ($ready && $write) {
                $soErr = @socket_get_option($sock, SOL_SOCKET, SO_ERROR);
                if ($soErr === 0) {
                    $out[] = sprintf('%2d  %s  %.1f ms  (destination reached)', $ttl, $ip, $rtt);
                    $reached = true;
                    @socket_close($sock);
                    break;
                }
                $out[] = sprintf('%2d  *  (%s)', $ttl, socket_strerror($soErr));
            } else {
                $out[] = sprintf('%2d  *', $ttl);
            }
            @socket_close($sock);
        }

        $out[] = '';
        $out[] = $reached ? 'Destination reached.' : 'Destination not reached within limits.';

        return implode("\n", $out);
    }

    // --- Curl ----------------------------------------------------------------

    private function curl(array $params, int $timeout): string
    {
        $url = $this->buildUrl($this->host($params, true), $this->port($params, null), (string) ($params['path'] ?? ''));
        $out = ["curl {$url}", ''];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'AaxisNetTools/1.0',
        ]);
        $response = curl_exec($ch);

        if ($response === false) {
            $out[] = 'Error: ' . curl_error($ch);
            curl_close($ch);

            return implode("\n", $out);
        }

        $info = curl_getinfo($ch);
        curl_close($ch);

        $headerSize = (int) ($info['header_size'] ?? 0);
        $headers = substr((string) $response, 0, $headerSize);
        $body = substr((string) $response, $headerSize);

        $out[] = sprintf(
            'HTTP %s | %s | %.0f ms | %d bytes',
            $info['http_code'] ?? '?',
            $info['content_type'] ?? '-',
            (float) ($info['total_time'] ?? 0) * 1000,
            strlen($body)
        );
        $out[] = '';
        $out[] = rtrim($headers);
        $out[] = '';
        $out[] = substr($body, 0, 2000);
        if (strlen($body) > 2000) {
            $out[] = '... (body truncated)';
        }

        return implode("\n", $out);
    }

    private function buildUrl(string $host, ?int $port, string $path): string
    {
        if (preg_match('#^https?://#i', $host)) {
            $base = rtrim($host, '/');
        } else {
            $scheme = $port === 443 ? 'https' : 'http';
            $base = $scheme . '://' . $host;
            if ($port !== null && $port !== 80 && $port !== 443) {
                $base .= ':' . $port;
            }
        }

        $path = trim($path);
        if ($path !== '' && !str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $base . $path;
    }

    // --- SSL certificate -----------------------------------------------------

    private function sslCertificate(string $host, int $port, int $timeout): string
    {
        $out = ["ssl-cert {$host}:{$port}", ''];

        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'capture_peer_cert_chain' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$client) {
            $out[] = sprintf('Connection failed: %s (errno %d)', $errstr, $errno);

            return implode("\n", $out);
        }

        $params = stream_context_get_params($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $chain = $params['options']['ssl']['peer_certificate_chain'] ?? [];
        fclose($client);

        if (!$cert) {
            $out[] = 'No certificate could be captured.';

            return implode("\n", $out);
        }

        $out[] = $this->describeCertificate($cert);

        if (\count($chain) > 1) {
            $out[] = '';
            $out[] = 'Certificate chain:';
            foreach ($chain as $i => $chainCert) {
                $parsed = openssl_x509_parse($chainCert);
                $out[] = sprintf('  %d. %s', $i, $parsed['name'] ?? '');
            }
        }

        return implode("\n", $out);
    }

    private function describeCertificate($cert): string
    {
        $p = openssl_x509_parse($cert);
        $validFrom = isset($p['validFrom_time_t']) ? date('Y-m-d H:i:s', $p['validFrom_time_t']) : '?';
        $validTo = isset($p['validTo_time_t']) ? date('Y-m-d H:i:s', $p['validTo_time_t']) : '?';
        $daysLeft = isset($p['validTo_time_t']) ? (int) floor(($p['validTo_time_t'] - time()) / 86400) : null;
        $san = $p['extensions']['subjectAltName'] ?? '';

        $lines = [
            'Subject:     ' . ($p['name'] ?? ''),
            'Common Name: ' . ($p['subject']['CN'] ?? '-'),
            'Issuer:      ' . ($p['issuer']['CN'] ?? ($p['issuer']['O'] ?? '-')),
            'Valid from:  ' . $validFrom . ' UTC',
            'Valid to:    ' . $validTo . ' UTC' . ($daysLeft !== null ? sprintf('  (%d days remaining)', $daysLeft) : ''),
            'Serial:      ' . ($p['serialNumberHex'] ?? ($p['serialNumber'] ?? '-')),
            'Signature:   ' . ($p['signatureTypeSN'] ?? '-'),
            'SAN:         ' . $san,
        ];

        return implode("\n", $lines);
    }

    // --- Ciphers -------------------------------------------------------------

    private function ciphers(string $host, int $port, int $timeout): string
    {
        $out = ["ciphers {$host}:{$port}", ''];

        [$ok, , $err] = $this->tcpProbe(gethostbyname($host), $port, (float) $timeout);
        if (!$ok) {
            $out[] = sprintf('Cannot connect to %s:%d (%s)', $host, $port, $err);

            return implode("\n", $out);
        }

        $negotiated = $this->negotiatedCrypto($host, $port, $timeout);
        if ($negotiated !== null) {
            $out[] = sprintf('Negotiated by default: %s, %s', $negotiated['protocol'], $negotiated['cipher_name']);
            $out[] = '';
        }

        $perCipherTimeout = min($timeout, 4);
        $out[] = 'Supported cipher suites (TLS 1.0 - 1.2):';
        $supported = [];
        foreach (self::CIPHER_LIST as $cipher) {
            if ($this->cipherSupported($host, $port, $cipher, $perCipherTimeout)) {
                $supported[] = $cipher;
                $out[] = '  [+] ' . $cipher;
            }
        }
        if (!$supported) {
            $out[] = '  (none of the probed legacy cipher suites are supported)';
        }
        $out[] = '';
        $out[] = sprintf('%d of %d probed cipher suites supported.', \count($supported), \count(self::CIPHER_LIST));

        return implode("\n", $out);
    }

    /**
     * @return array{protocol: string, cipher_name: string}|null
     */
    private function negotiatedCrypto(string $host, int $port, int $timeout): ?array
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$client) {
            return null;
        }
        $meta = stream_get_meta_data($client);
        fclose($client);
        $crypto = $meta['crypto'] ?? null;
        if (!$crypto) {
            return null;
        }

        return [
            'protocol' => (string) ($crypto['protocol'] ?? '?'),
            'cipher_name' => (string) ($crypto['cipher_name'] ?? '?'),
        ];
    }

    private function cipherSupported(string $host, int $port, string $cipher, int $timeout): bool
    {
        $context = stream_context_create(['ssl' => [
            'ciphers' => $cipher,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ]]);
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($client) {
            fclose($client);

            return true;
        }

        return false;
    }

    // --- Input helpers -------------------------------------------------------

    private function host(array $params, bool $allowUrl = false): string
    {
        $host = trim((string) ($params['host'] ?? ''));
        if ($host === '') {
            throw new \InvalidArgumentException('A host is required.');
        }
        if ($allowUrl && preg_match('#^https?://#i', $host)) {
            if (false === filter_var($host, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('Invalid URL.');
            }
            $this->assertTargetAllowed((string) (parse_url($host, PHP_URL_HOST) ?: ''));

            return $host;
        }
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $host)) {
            throw new \InvalidArgumentException('Invalid host. Use a hostname or IP address.');
        }
        $this->assertTargetAllowed($host);

        return $host;
    }

    /** Hostnames that resolve only to a cloud metadata endpoint; blocked outright. */
    private const array BLOCKED_HOSTS = ['metadata.google.internal'];

    /**
     * Rejects targets that resolve to a link-local / cloud-metadata address (169.254.0.0/16,
     * IPv6 fe80::/10, fd00:ec2::254) — the path used to steal instance credentials. RFC1918 and
     * loopback are intentionally still allowed so the tool can diagnose internal services.
     */
    private function assertTargetAllowed(string $host): void
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));
        if ($host === '') {
            return;
        }
        if (\in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new \InvalidArgumentException('This target is not permitted.');
        }

        $candidates = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $candidates[] = $host;
        } else {
            $resolved = @gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $candidates[] = $resolved;
            }
        }

        foreach ($candidates as $ip) {
            if ($this->isLinkLocal($ip)) {
                throw new \InvalidArgumentException('Link-local / metadata addresses are not permitted.');
            }
        }
    }

    private function isLinkLocal(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($ip, '169.254.');
        }
        $packed = @inet_pton($ip);
        if ($packed === false || \strlen($packed) !== 16) {
            return false;
        }
        $bytes = unpack('C*', $packed);
        // fe80::/10 link-local
        if ($bytes[1] === 0xfe && ($bytes[2] & 0xc0) === 0x80) {
            return true;
        }

        // fd00:ec2::254 (AWS IPv6 instance metadata)
        return strtolower($ip) === 'fd00:ec2::254';
    }

    private function port(array $params, ?int $default): ?int
    {
        $raw = $params['port'] ?? null;
        if ($raw === null || $raw === '') {
            return $default;
        }
        $port = (int) $raw;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Port must be between 1 and 65535.');
        }

        return $port;
    }

    private function requirePort(array $params): int
    {
        $port = $this->port($params, null);
        if ($port === null) {
            throw new \InvalidArgumentException('A port is required for this tool.');
        }

        return $port;
    }

    private function timeoutValue(array $params, int $default): int
    {
        $raw = $params['timeout'] ?? null;
        if ($raw === null || $raw === '') {
            return $default;
        }

        return max(1, min(120, (int) $raw));
    }
}
