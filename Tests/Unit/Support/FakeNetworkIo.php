<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support;

use Aaxis\Bundle\DevToolsBundle\Network\NetworkIoInterface;

/**
 * Scriptable stand-in for the network primitives: nothing is resolved, connected or slept on. Answers
 * come from the public properties; every call is recorded in $calls.
 */
final class FakeNetworkIo implements NetworkIoInterface
{
    /** @var array<int, array{string, array<int, mixed>}> */
    public array $calls = [];

    /** Time handed out by now(); it advances by $tick on every call. */
    public float $clock = 1000.0;
    public float $tick = 0.0;

    /** @var float[] explicit values for the next now() calls (consumed first) */
    public array $nowQueue = [];

    /** @var array<string, string> host => resolved address (unlisted hosts resolve to themselves) */
    public array $resolutions = [];

    /** @var array<int, array<int, array<string, mixed>>|false> DNS_* type => records */
    public array $dns = [];
    public ?\Throwable $dnsThrows = null;
    /** @var string|false|null RES_OPTIONS seen by the most recent dnsRecords() call */
    public string|false|null $resOptionsDuringLookup = null;

    /** @var array<int, array{0: bool, 1: int, 2: string}> consumed one per tcpConnect(); the last one repeats */
    public array $tcp = [[true, 0, '']];

    /** @var array<int, array{created: bool, connected: bool, error: string|null, rtt: float}> consumed one per traceHop(); the last one repeats */
    public array $hops = [['created' => true, 'connected' => false, 'error' => null, 'rtt' => 0.0]];

    /** @var array{response: string|false, error: string, info: array<string, mixed>} */
    public array $curl = ['response' => false, 'error' => 'not configured', 'info' => []];

    /** @var array{connected: bool, errno: int, errstr: string, cert: mixed, chain: array<int, mixed>} */
    public array $peer = ['connected' => false, 'errno' => 0, 'errstr' => 'not configured', 'cert' => null, 'chain' => []];

    /** @var array<string, mixed>|null */
    public ?array $crypto = null;

    /** @var string[] ciphers the fake server accepts */
    public array $supportedCiphers = [];

    #[\Override]
    public function now(): float
    {
        $this->calls[] = ['now', []];
        if ($this->nowQueue !== []) {
            return array_shift($this->nowQueue);
        }
        $value = $this->clock;
        $this->clock += $this->tick;

        return $value;
    }

    #[\Override]
    public function pause(int $microseconds): void
    {
        $this->calls[] = ['pause', [$microseconds]];
    }

    #[\Override]
    public function resolve(string $host, bool $silent = false): string
    {
        $this->calls[] = ['resolve', [$host, $silent]];

        return $this->resolutions[$host] ?? $host;
    }

    #[\Override]
    public function dnsRecords(string $host, int $type): array|false
    {
        $this->calls[] = ['dnsRecords', [$host, $type]];
        $this->resOptionsDuringLookup = getenv('RES_OPTIONS');
        if ($this->dnsThrows !== null) {
            throw $this->dnsThrows;
        }

        return $this->dns[$type] ?? false;
    }

    #[\Override]
    public function tcpConnect(string $ip, int $port, float $timeout): array
    {
        $this->calls[] = ['tcpConnect', [$ip, $port, $timeout]];
        $result = \count($this->tcp) > 1 ? array_shift($this->tcp) : $this->tcp[0];

        return $result;
    }

    #[\Override]
    public function traceHop(string $ip, int $port, int $ttl): array
    {
        $this->calls[] = ['traceHop', [$ip, $port, $ttl]];

        return \count($this->hops) > 1 ? array_shift($this->hops) : $this->hops[0];
    }

    #[\Override]
    public function curl(string $url, int $timeout): array
    {
        $this->calls[] = ['curl', [$url, $timeout]];

        return $this->curl;
    }

    #[\Override]
    public function tlsPeer(string $host, int $port, int $timeout): array
    {
        $this->calls[] = ['tlsPeer', [$host, $port, $timeout]];

        return $this->peer;
    }

    #[\Override]
    public function tlsCrypto(string $host, int $port, int $timeout): ?array
    {
        $this->calls[] = ['tlsCrypto', [$host, $port, $timeout]];

        return $this->crypto;
    }

    #[\Override]
    public function tlsHandshake(string $host, int $port, int $timeout, array $sslOptions): bool
    {
        $this->calls[] = ['tlsHandshake', [$host, $port, $timeout, $sslOptions]];

        return \in_array($sslOptions['ciphers'] ?? null, $this->supportedCiphers, true);
    }

    /**
     * Arguments of every recorded call of one primitive.
     *
     * @return array<int, array<int, mixed>>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_map(
            static fn (array $call) => $call[1],
            array_filter($this->calls, static fn (array $call) => $call[0] === $method)
        ));
    }
}
