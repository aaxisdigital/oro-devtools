<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Network;

/**
 * The raw network / clock primitives {@see NetworkToolExecutor} builds its diagnostics from (DNS,
 * TCP connects, curl, TLS handshakes, sleeping). Kept behind an interface so the executor's
 * orchestration and formatting can be exercised without touching a real network.
 */
interface NetworkIoInterface
{
    /** Current Unix time in seconds, with microseconds. */
    public function now(): float;

    public function pause(int $microseconds): void;

    /**
     * Resolves a hostname to an IPv4 address; returns the input unchanged when it cannot be resolved.
     */
    public function resolve(string $host, bool $silent = false): string;

    /**
     * @return array<int, array<string, mixed>>|false
     */
    public function dnsRecords(string $host, int $type): array|false;

    /**
     * Opens (and immediately closes) a TCP connection.
     *
     * @return array{0: bool, 1: int, 2: string} [connected, errno, errstr]
     */
    public function tcpConnect(string $ip, int $port, float $timeout): array;

    /**
     * One non-blocking TCP connect attempt with the given IP TTL.
     *
     * @return array{created: bool, connected: bool, error: string|null, rtt: float} "error" carries the
     *         socket error text when the attempt was answered with a failure, null when it just timed out
     */
    public function traceHop(string $ip, int $port, int $ttl): array;

    /**
     * Fetches a URL (headers included in the response), without following redirects.
     *
     * @return array{response: string|false, error: string, info: array<string, mixed>}
     */
    public function curl(string $url, int $timeout): array;

    /**
     * Performs a TLS handshake capturing the peer certificate and its chain.
     *
     * @return array{connected: bool, errno: int, errstr: string, cert: mixed, chain: array<int, mixed>}
     */
    public function tlsPeer(string $host, int $port, int $timeout): array;

    /**
     * The crypto metadata (protocol, cipher_name, ...) of a default TLS handshake, or null when the
     * handshake fails.
     *
     * @return array<string, mixed>|null
     */
    public function tlsCrypto(string $host, int $port, int $timeout): ?array;

    /**
     * Whether a TLS handshake using the given SSL context options completes.
     *
     * @param array<string, mixed> $sslOptions "ssl" stream-context options
     */
    public function tlsHandshake(string $host, int $port, int $timeout, array $sslOptions): bool;
}
