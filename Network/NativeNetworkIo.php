<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Network;

/**
 * The real implementation of {@see NetworkIoInterface}: thin wrappers over PHP's DNS / socket / curl /
 * stream functions. Deliberately free of logic — everything decisive lives in NetworkToolExecutor.
 */
final class NativeNetworkIo implements NetworkIoInterface
{
    /** Linux value of the IP_TTL socket option (the constant is not exposed by PHP). */
    private const int IP_TTL = 2;

    #[\Override]
    public function now(): float
    {
        return microtime(true);
    }

    #[\Override]
    public function pause(int $microseconds): void
    {
        usleep($microseconds);
    }

    #[\Override]
    public function resolve(string $host, bool $silent = false): string
    {
        return $silent ? @gethostbyname($host) : gethostbyname($host);
    }

    #[\Override]
    public function dnsRecords(string $host, int $type): array|false
    {
        return @dns_get_record($host, $type);
    }

    #[\Override]
    public function tcpConnect(string $ip, int $port, float $timeout): array
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if ($fp) {
            fclose($fp);

            return [true, 0, ''];
        }

        return [false, $errno, $errstr];
    }

    #[\Override]
    public function traceHop(string $ip, int $port, int $ttl): array
    {
        $sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($sock === false) {
            return ['created' => false, 'connected' => false, 'error' => null, 'rtt' => 0.0];
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

        $connected = false;
        $error = null;
        if ($ready && $write) {
            $soErr = @socket_get_option($sock, SOL_SOCKET, SO_ERROR);
            if ($soErr === 0) {
                $connected = true;
            } else {
                $error = socket_strerror($soErr);
            }
        }
        @socket_close($sock);

        return ['created' => true, 'connected' => $connected, 'error' => $error, 'rtt' => $rtt];
    }

    #[\Override]
    public function curl(string $url, int $timeout): array
    {
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
        $error = curl_error($ch);
        $info = $response === false ? [] : curl_getinfo($ch);

        return ['response' => $response, 'error' => $error, 'info' => $info];
    }

    #[\Override]
    public function tlsPeer(string $host, int $port, int $timeout): array
    {
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
        $client = $this->tlsConnect($host, $port, $timeout, $context, $errno, $errstr);
        if (!$client) {
            return ['connected' => false, 'errno' => $errno, 'errstr' => $errstr, 'cert' => null, 'chain' => []];
        }

        $params = stream_context_get_params($client);
        fclose($client);

        return [
            'connected' => true,
            'errno' => 0,
            'errstr' => '',
            'cert' => $params['options']['ssl']['peer_certificate'] ?? null,
            'chain' => $params['options']['ssl']['peer_certificate_chain'] ?? [],
        ];
    }

    #[\Override]
    public function tlsCrypto(string $host, int $port, int $timeout): ?array
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $errno = 0;
        $errstr = '';
        $client = $this->tlsConnect($host, $port, $timeout, $context, $errno, $errstr);
        if (!$client) {
            return null;
        }
        $meta = stream_get_meta_data($client);
        fclose($client);

        return $meta['crypto'] ?? null;
    }

    #[\Override]
    public function tlsHandshake(string $host, int $port, int $timeout, array $sslOptions): bool
    {
        $context = stream_context_create(['ssl' => $sslOptions]);
        $errno = 0;
        $errstr = '';
        $client = $this->tlsConnect($host, $port, $timeout, $context, $errno, $errstr);
        if ($client) {
            fclose($client);

            return true;
        }

        return false;
    }

    /**
     * @param resource $context
     *
     * @return resource|false
     */
    private function tlsConnect(string $host, int $port, int $timeout, $context, int &$errno, string &$errstr)
    {
        return @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
    }
}
