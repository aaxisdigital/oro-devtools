<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Network;

use Aaxis\Bundle\DevToolsBundle\Network\NativeNetworkIo;
use Aaxis\Bundle\DevToolsBundle\Network\NetworkToolExecutor;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\FakeNetworkIo;
use PHPUnit\Framework\TestCase;

class NetworkToolExecutorTest extends TestCase
{
    private FakeNetworkIo $io;
    private NetworkToolExecutor $executor;

    protected function setUp(): void
    {
        $this->io = new FakeNetworkIo();
        $this->executor = new NetworkToolExecutor($this->io);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return string[]
     */
    private function lines(string $tool, array $params): array
    {
        return explode("\n", $this->executor->run($tool, $params));
    }

    // --- dispatching & shared input validation ---------------------------------

    public function testTheToolListAndLabelsAgree(): void
    {
        self::assertSame(['dns', 'ping', 'traceroute', 'socket', 'curl', 'ssl-cert', 'ciphers'], NetworkToolExecutor::TOOLS);
        self::assertSame(NetworkToolExecutor::TOOLS, array_keys(NetworkToolExecutor::TOOL_LABELS));
    }

    public function testAnUnknownToolIsRejectedBeforeAnyNetworkAccess(): void
    {
        try {
            $this->executor->run('nmap', ['host' => 'example.org']);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Unknown tool "nmap".', $e->getMessage());
        }
        self::assertSame([], $this->io->calls);
    }

    public function testTheRealPrimitivesAreUsedByDefault(): void
    {
        $executor = new NetworkToolExecutor();
        $io = (new \ReflectionProperty($executor, 'io'))->getValue($executor);

        self::assertInstanceOf(NativeNetworkIo::class, $io);
    }

    /**
     * @dataProvider invalidHostProvider
     *
     * @param array<string, mixed> $params
     */
    public function testInvalidHostsAreRejected(string $tool, array $params, string $message): void
    {
        try {
            $this->executor->run($tool, $params);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame($message, $e->getMessage());
        }
        self::assertSame([], $this->io->callsTo('tcpConnect'));
        self::assertSame([], $this->io->callsTo('curl'));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidHostProvider(): iterable
    {
        yield 'no host' => ['dns', [], 'A host is required.'];
        yield 'blank host' => ['ping', ['host' => '   '], 'A host is required.'];
        yield 'null host' => ['ssl-cert', ['host' => null], 'A host is required.'];
        yield 'spaces inside' => ['dns', ['host' => 'exa mple.org'], 'Invalid host. Use a hostname or IP address.'];
        yield 'path segment' => ['ping', ['host' => 'a/b'], 'Invalid host. Use a hostname or IP address.'];
        yield 'url outside curl' => ['ping', ['host' => 'http://example.org'], 'Invalid host. Use a hostname or IP address.'];
        yield 'shell metacharacters' => ['socket', ['host' => 'a;rm -rf /', 'port' => 22], 'Invalid host. Use a hostname or IP address.'];
        yield 'curl with a non-http scheme' => ['curl', ['host' => 'ftp://example.org'], 'Invalid host. Use a hostname or IP address.'];
        yield 'curl with a broken url' => ['curl', ['host' => 'http://'], 'Invalid URL.'];
        yield 'cloud metadata hostname' => ['dns', ['host' => 'metadata.google.internal'], 'This target is not permitted.'];
        yield 'cloud metadata hostname, any case' => ['ping', ['host' => 'Metadata.Google.Internal'], 'This target is not permitted.'];
        yield 'curl to the cloud metadata hostname' => ['curl', ['host' => 'http://metadata.google.internal/x'], 'This target is not permitted.'];
        yield 'ipv4 link-local' => ['ping', ['host' => '169.254.169.254'], 'Link-local / metadata addresses are not permitted.'];
        yield 'ipv6 link-local' => ['traceroute', ['host' => 'fe80::1'], 'Link-local / metadata addresses are not permitted.'];
        yield 'aws ipv6 metadata' => ['ssl-cert', ['host' => 'fd00:ec2::254'], 'Link-local / metadata addresses are not permitted.'];
        yield 'curl to a link-local url' => ['curl', ['host' => 'http://169.254.169.254/latest/meta-data'], 'Link-local / metadata addresses are not permitted.'];
        yield 'socket without a port' => ['socket', ['host' => 'example.org'], 'A port is required for this tool.'];
        yield 'port zero' => ['ping', ['host' => 'example.org', 'port' => 0], 'Port must be between 1 and 65535.'];
        yield 'port too big' => ['socket', ['host' => 'example.org', 'port' => 70000], 'Port must be between 1 and 65535.'];
        yield 'port not numeric' => ['ciphers', ['host' => 'example.org', 'port' => 'https'], 'Port must be between 1 and 65535.'];
    }

    public function testAHostnameResolvingToALinkLocalAddressIsRejected(): void
    {
        $this->io->resolutions = ['sneaky.example' => '169.254.169.254'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Link-local / metadata addresses are not permitted.');

        $this->executor->run('ping', ['host' => 'sneaky.example']);
    }

    public function testResolutionForTheTargetCheckIsDoneSilently(): void
    {
        $this->io->resolutions = ['db.internal' => '10.0.0.7'];

        $this->executor->run('socket', ['host' => 'db.internal', 'port' => 5432]);

        self::assertSame(['db.internal', true], $this->io->callsTo('resolve')[0]);
    }

    /**
     * @dataProvider allowedTargetProvider
     */
    public function testInternalAndUnresolvableTargetsAreAllowed(string $host, ?string $resolvesTo): void
    {
        if ($resolvesTo !== null) {
            $this->io->resolutions[$host] = $resolvesTo;
        }

        self::assertStringContainsString('socket ' . $host . ' 80', $this->executor->run('socket', ['host' => $host, 'port' => 80]));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function allowedTargetProvider(): iterable
    {
        yield 'loopback literal' => ['127.0.0.1', null];
        yield 'rfc1918 literal' => ['10.20.30.40', null];
        yield 'ipv6 loopback' => ['::1', null];
        yield 'public name' => ['example.org', '93.184.216.34'];
        yield 'name resolving to loopback' => ['localhost', '127.0.0.1'];
        yield 'unresolvable name' => ['does-not-resolve.invalid', null];
        yield 'name resolving to garbage' => ['weird.example', 'not-an-ip'];
    }

    /**
     * @dataProvider timeoutProvider
     */
    public function testTimeoutsAreClamped(mixed $given, float $expected): void
    {
        $this->executor->run('socket', ['host' => '127.0.0.1', 'port' => 80, 'timeout' => $given]);

        self::assertSame($expected, $this->io->callsTo('tcpConnect')[0][2]);
    }

    /**
     * @return iterable<string, array{mixed, float}>
     */
    public static function timeoutProvider(): iterable
    {
        yield 'unset uses the tool default' => [null, 3.0];
        yield 'empty uses the tool default' => ['', 3.0];
        yield 'in range' => ['15', 15.0];
        yield 'zero becomes one second' => [0, 1.0];
        yield 'negative becomes one second' => [-8, 1.0];
        yield 'garbage becomes one second' => ['soon', 1.0];
        yield 'capped at two minutes' => [9999, 120.0];
    }

    // --- dns -------------------------------------------------------------------

    public function testASimpleLookupOnlyAsksForARecords(): void
    {
        $this->io->dns[DNS_A] = [['ip' => '93.184.216.34', 'ttl' => 300]];

        self::assertSame([
            'dns example.org',
            '',
            'A      300    93.184.216.34',
        ], $this->lines('dns', ['host' => 'example.org', 'mode' => 'a']));
        self::assertSame([['example.org', DNS_A]], $this->io->callsTo('dnsRecords'));
    }

    /**
     * @dataProvider fullModeProvider
     *
     * @param array<string, mixed> $params
     */
    public function testAnyOtherModeIsAFullLookup(array $params): void
    {
        $this->executor->run('dns', ['host' => 'example.org'] + $params);

        self::assertSame(
            [DNS_A, DNS_AAAA, DNS_CNAME, DNS_MX, DNS_NS, DNS_TXT, DNS_SOA, DNS_SRV, DNS_CAA],
            array_column($this->io->callsTo('dnsRecords'), 1)
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function fullModeProvider(): iterable
    {
        yield 'no mode' => [[]];
        yield 'full' => [['mode' => 'full']];
        yield 'null' => [['mode' => null]];
        yield 'unknown' => [['mode' => 'zz']];
    }

    public function testAFullLookupFormatsEveryRecordType(): void
    {
        $this->io->dns = [
            DNS_A => [['ip' => '1.2.3.4', 'ttl' => 60]],
            DNS_AAAA => [['ipv6' => '2001:db8::1', 'ttl' => 61]],
            DNS_CNAME => [['target' => 'alias.example.org', 'ttl' => 62]],
            DNS_MX => [['target' => 'mx1.example.org', 'pri' => 10, 'ttl' => 63], ['target' => 'mx2.example.org', 'pri' => 20, 'ttl' => 63]],
            DNS_NS => [['target' => 'ns1.example.org', 'ttl' => 64]],
            DNS_TXT => [['txt' => 'v=spf1 -all', 'ttl' => 65]],
            DNS_SOA => [['mname' => 'ns1.example.org', 'rname' => 'hostmaster.example.org', 'serial' => 2024010101, 'ttl' => 66]],
            DNS_SRV => [['target' => 'sip.example.org', 'port' => 5060, 'pri' => 1, 'weight' => 5, 'ttl' => 67]],
            DNS_CAA => [['tag' => 'issue', 'value' => 'letsencrypt.org', 'ttl' => 68]],
        ];

        self::assertSame([
            'dns example.org',
            '',
            'A      60     1.2.3.4',
            'AAAA   61     2001:db8::1',
            'CNAME  62     alias.example.org',
            'MX     63     mx1.example.org (priority 10)',
            'MX     63     mx2.example.org (priority 20)',
            'NS     64     ns1.example.org',
            'TXT    65     v=spf1 -all',
            'SOA    66     ns1.example.org hostmaster.example.org (serial 2024010101)',
            'SRV    67     sip.example.org:5060 (priority 1, weight 5)',
            'CAA    68     issue letsencrypt.org',
        ], $this->lines('dns', ['host' => 'example.org']));
    }

    public function testRecordsMissingFieldsRenderAsBlanks(): void
    {
        $this->io->dns = [DNS_A => [[]], DNS_MX => [[]], DNS_SRV => [[]]];

        $lines = $this->lines('dns', ['host' => 'example.org']);

        self::assertSame('A' . str_repeat(' ', 13), $lines[2]);
        self::assertSame('MX' . str_repeat(' ', 12) . ' (priority 0)', $lines[3]);
        self::assertSame('SRV' . str_repeat(' ', 11) . ':0 (priority 0, weight 0)', $lines[4]);
    }

    public function testALookupWithoutAnswersSaysSo(): void
    {
        $this->io->dns = [DNS_A => [], DNS_MX => false];

        self::assertSame(['dns example.org', '', 'No DNS records found.'], $this->lines('dns', ['host' => 'example.org']));
    }

    public function testALookupStopsAtTheTimeLimitAndSaysWhatWasSkipped(): void
    {
        $this->io->dns[DNS_A] = [['ip' => '1.2.3.4', 'ttl' => 1]];
        // deadline = 1000 + 5; first check still in time, second is past it.
        $this->io->nowQueue = [1000.0, 1001.0, 1006.0];

        self::assertSame([
            'dns example.org',
            '',
            'A      1      1.2.3.4',
            '',
            '(some record types were skipped: DNS lookup time limit reached)',
        ], $this->lines('dns', ['host' => 'example.org', 'timeout' => 5]));
        self::assertCount(1, $this->io->callsTo('dnsRecords'));
    }

    public function testATimedOutLookupWithNoRecordsReportsBoth(): void
    {
        $this->io->nowQueue = [1000.0, 2000.0];

        $lines = $this->lines('dns', ['host' => 'example.org']);

        self::assertSame(['dns example.org', '', 'No DNS records found.', '', '(some record types were skipped: DNS lookup time limit reached)'], $lines);
        self::assertSame([], $this->io->callsTo('dnsRecords'));
    }

    public function testTheResolverIsTightenedOnlyDuringTheLookup(): void
    {
        putenv('RES_OPTIONS=ndots:2');
        try {
            $this->executor->run('dns', ['host' => 'example.org', 'mode' => 'a']);

            self::assertSame('timeout:2 attempts:1', $this->io->resOptionsDuringLookup);
            self::assertSame('ndots:2', getenv('RES_OPTIONS'));
        } finally {
            putenv('RES_OPTIONS');
        }
    }

    public function testAnUnsetResolverConfigurationStaysUnset(): void
    {
        putenv('RES_OPTIONS');

        $this->executor->run('dns', ['host' => 'example.org', 'mode' => 'a']);

        self::assertFalse(getenv('RES_OPTIONS'));
    }

    public function testTheResolverConfigurationIsRestoredWhenTheLookupFails(): void
    {
        putenv('RES_OPTIONS=ndots:3');
        $this->io->dnsThrows = new \RuntimeException('resolver exploded');

        try {
            $this->executor->run('dns', ['host' => 'example.org']);
            self::fail('Expected an exception.');
        } catch (\RuntimeException $e) {
            self::assertSame('resolver exploded', $e->getMessage());
            self::assertSame('ndots:3', getenv('RES_OPTIONS'));
        } finally {
            putenv('RES_OPTIONS');
        }
    }

    // --- ping / socket ---------------------------------------------------------

    public function testPingProbesTheResolvedAddressFourTimes(): void
    {
        $this->io->resolutions = ['example.org' => '93.184.216.34'];
        $this->io->tick = 0.25;

        $lines = $this->lines('ping', ['host' => 'example.org']);

        self::assertSame([
            'ping example.org (93.184.216.34)',
            'ICMP is unavailable in this environment; using a TCP connect probe to port 80.',
            '',
            'Reply from 93.184.216.34:80: time=250.0 ms',
            'Reply from 93.184.216.34:80: time=250.0 ms',
            'Reply from 93.184.216.34:80: time=250.0 ms',
            'Reply from 93.184.216.34:80: time=250.0 ms',
            '',
            '4 probes sent, 4 successful, 0% loss',
            'rtt min/avg/max = 250.0/250.0/250.0 ms',
        ], $lines);
        self::assertSame(
            [['93.184.216.34', 80, 2.0], ['93.184.216.34', 80, 2.0], ['93.184.216.34', 80, 2.0], ['93.184.216.34', 80, 2.0]],
            $this->io->callsTo('tcpConnect')
        );
        self::assertSame([[200000], [200000], [200000], [200000]], $this->io->callsTo('pause'));
    }

    public function testPingReportsLossAndTheRoundTripStatisticsOfTheRepliesOnly(): void
    {
        $this->io->tcp = [[true, 0, ''], [false, 111, 'Connection refused'], [false, 110, ''], [true, 0, '']];
        // start/end pairs: 100ms and 300ms for the two successful probes, arbitrary for the failed ones.
        $this->io->nowQueue = [10.0, 10.1, 20.0, 20.5, 30.0, 30.5, 40.0, 40.3];

        $lines = $this->lines('ping', ['host' => '10.0.0.9', 'port' => 8080, 'timeout' => 4]);

        self::assertSame('ping 10.0.0.9 (10.0.0.9)', $lines[0]);
        self::assertSame('ICMP is unavailable in this environment; using a TCP connect probe to port 8080.', $lines[1]);
        self::assertSame('Reply from 10.0.0.9:8080: time=100.0 ms', $lines[3]);
        self::assertSame('Probe 2: no reply (Connection refused)', $lines[4]);
        self::assertSame('Probe 3: no reply (errno 110)', $lines[5]);
        self::assertSame('Reply from 10.0.0.9:8080: time=300.0 ms', $lines[6]);
        self::assertSame('4 probes sent, 2 successful, 50% loss', $lines[8]);
        self::assertSame('rtt min/avg/max = 100.0/200.0/300.0 ms', $lines[9]);
        self::assertSame(4.0, $this->io->callsTo('tcpConnect')[0][2]);
    }

    public function testPingWithNoReplyHasNoRoundTripSummary(): void
    {
        $this->io->tcp = [[false, 111, 'Connection refused']];

        $lines = $this->lines('ping', ['host' => '10.0.0.9']);

        self::assertSame('4 probes sent, 0 successful, 100% loss', end($lines));
        self::assertStringNotContainsString('rtt min/avg/max', implode("\n", $lines));
        self::assertSame('Probe 4: no reply (Connection refused)', $lines[6]);
    }

    public function testSocketProbesFiveTimes(): void
    {
        $this->io->tcp = [[true, 0, ''], [false, 111, 'Connection refused'], [false, 0, ''], [true, 0, ''], [true, 0, '']];
        $this->io->tick = 0.0125;

        $lines = $this->lines('socket', ['host' => '10.0.0.9', 'port' => '5432']);

        self::assertSame([
            'socket 10.0.0.9 5432',
            '',
            'Probe 1: Connection successful, RTT=13ms',
            'Probe 2: Connection failed (Connection refused)',
            'Probe 3: Connection failed (errno 0)',
            'Probe 4: Connection successful, RTT=13ms',
            'Probe 5: Connection successful, RTT=13ms',
            'socket test completed',
        ], $lines);
        self::assertSame([], $this->io->callsTo('pause'));
    }

    // --- traceroute ------------------------------------------------------------

    public function testTracerouteStopsAtTheHopWhereTheDestinationAnswers(): void
    {
        $this->io->resolutions = ['example.org' => '93.184.216.34'];
        $this->io->hops = [
            ['created' => true, 'connected' => false, 'error' => null, 'rtt' => 0.0],
            ['created' => true, 'connected' => false, 'error' => 'Connection refused', 'rtt' => 0.0],
            ['created' => true, 'connected' => true, 'error' => null, 'rtt' => 12.34],
        ];

        self::assertSame([
            'traceroute to example.org (93.184.216.34), TCP port 80, max 30 hops',
            'Note: intermediate hop addresses require raw-socket privileges (unavailable here);',
            'reporting per-TTL reachability and the hop at which the destination responds.',
            '',
            ' 1  *',
            ' 2  *  (Connection refused)',
            ' 3  93.184.216.34  12.3 ms  (destination reached)',
            '',
            'Destination reached.',
        ], $this->lines('traceroute', ['host' => 'example.org']));
        self::assertSame(
            [['93.184.216.34', 80, 1], ['93.184.216.34', 80, 2], ['93.184.216.34', 80, 3]],
            $this->io->callsTo('traceHop')
        );
    }

    public function testTracerouteGivesUpAfterThirtyHops(): void
    {
        $lines = $this->lines('traceroute', ['host' => '10.0.0.9', 'port' => 443]);

        self::assertCount(30, $this->io->callsTo('traceHop'));
        self::assertSame('traceroute to 10.0.0.9 (10.0.0.9), TCP port 443, max 30 hops', $lines[0]);
        self::assertSame('30  *', $lines[4 + 29]);
        self::assertSame('Destination not reached within limits.', end($lines));
    }

    public function testTracerouteAbortsWhenTheTimeLimitIsReached(): void
    {
        // deadline = 1000 + 25 (default timeout); hop 1 is in time, the check before hop 2 is not.
        $this->io->nowQueue = [1000.0, 1001.0, 1100.0];

        $lines = $this->lines('traceroute', ['host' => '10.0.0.9']);

        self::assertCount(1, $this->io->callsTo('traceHop'));
        self::assertSame(['', 'Destination not reached within limits.'], \array_slice($lines, -2));
        self::assertSame('... aborted (time limit reached)', $lines[\count($lines) - 3]);
    }

    public function testTracerouteReportsAnUnavailableSocket(): void
    {
        $this->io->hops = [['created' => false, 'connected' => false, 'error' => null, 'rtt' => 0.0]];

        $lines = $this->lines('traceroute', ['host' => '10.0.0.9']);

        self::assertSame(['Unable to create socket.', '', 'Destination not reached within limits.'], \array_slice($lines, -3));
        self::assertCount(1, $this->io->callsTo('traceHop'));
    }

    // --- curl ------------------------------------------------------------------

    /**
     * @dataProvider urlProvider
     *
     * @param array<string, mixed> $params
     */
    public function testBuildsTheRequestedUrl(array $params, string $expectedUrl): void
    {
        $this->io->curl = ['response' => false, 'error' => 'x', 'info' => []];

        $this->executor->run('curl', $params);

        self::assertSame($expectedUrl, $this->io->callsTo('curl')[0][0]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function urlProvider(): iterable
    {
        yield 'bare host' => [['host' => 'example.org'], 'http://example.org'];
        yield 'port 443 means https' => [['host' => 'example.org', 'port' => 443], 'https://example.org'];
        yield 'port 80 is implied' => [['host' => 'example.org', 'port' => 80], 'http://example.org'];
        yield 'custom port' => [['host' => 'example.org', 'port' => 8080], 'http://example.org:8080'];
        yield 'path gets a leading slash' => [['host' => 'example.org', 'path' => 'a/b?x=1'], 'http://example.org/a/b?x=1'];
        yield 'path is trimmed' => [['host' => 'example.org', 'path' => '  /health '], 'http://example.org/health'];
        yield 'blank path' => [['host' => 'example.org', 'path' => '   '], 'http://example.org'];
        yield 'full url' => [['host' => 'https://example.org:8443/'], 'https://example.org:8443'];
        yield 'full url with a path param' => [['host' => 'HTTP://example.org//', 'path' => 'x'], 'HTTP://example.org/x'];
        yield 'full url ignores the port' => [['host' => 'http://example.org', 'port' => 443], 'http://example.org'];
    }

    public function testCurlPassesTheTimeoutAndFormatsTheResponse(): void
    {
        $headers = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nX-Test: 1\r\n\r\n";
        $this->io->curl = [
            'response' => $headers . '<h1>hello</h1>',
            'error' => '',
            'info' => ['header_size' => \strlen($headers), 'http_code' => 200, 'content_type' => 'text/html', 'total_time' => 0.0154],
        ];

        $lines = $this->lines('curl', ['host' => 'example.org', 'timeout' => 7]);

        self::assertSame([
            'curl http://example.org',
            '',
            'HTTP 200 | text/html | 15 ms | 14 bytes',
            '',
            // Header lines keep the CR of the wire format; only the trailing blank line is trimmed.
            "HTTP/1.1 200 OK\r",
            "Content-Type: text/html\r",
            'X-Test: 1',
            '',
            '<h1>hello</h1>',
        ], $lines);
        self::assertSame([['http://example.org', 7]], $this->io->callsTo('curl'));
    }

    public function testCurlDefaultsTheTimeoutToTenSeconds(): void
    {
        $this->executor->run('curl', ['host' => 'example.org']);

        self::assertSame(10, $this->io->callsTo('curl')[0][1]);
    }

    public function testALongBodyIsTruncatedToTwoThousandBytes(): void
    {
        $headers = "HTTP/1.1 200 OK\r\n\r\n";
        $this->io->curl = [
            'response' => $headers . str_repeat('x', 2001),
            'error' => '',
            'info' => ['header_size' => \strlen($headers), 'http_code' => 200, 'content_type' => 'text/plain', 'total_time' => 0.1],
        ];

        $lines = $this->lines('curl', ['host' => 'example.org']);

        self::assertSame('HTTP 200 | text/plain | 100 ms | 2001 bytes', $lines[2]);
        self::assertSame(str_repeat('x', 2000), $lines[\count($lines) - 2]);
        self::assertSame('... (body truncated)', end($lines));
    }

    public function testABodyOfExactlyTwoThousandBytesIsNotTruncated(): void
    {
        $this->io->curl = ['response' => str_repeat('y', 2000), 'error' => '', 'info' => ['header_size' => 0, 'http_code' => 200]];

        self::assertStringNotContainsString('truncated', $this->executor->run('curl', ['host' => 'example.org']));
    }

    public function testCurlToleratesMissingInfo(): void
    {
        $this->io->curl = ['response' => 'body', 'error' => '', 'info' => []];

        $lines = $this->lines('curl', ['host' => 'example.org']);

        self::assertSame('HTTP ? | - | 0 ms | 4 bytes', $lines[2]);
    }

    public function testCurlReportsATransportError(): void
    {
        $this->io->curl = ['response' => false, 'error' => 'Could not resolve host: example.org', 'info' => []];

        self::assertSame(
            ['curl http://example.org', '', 'Error: Could not resolve host: example.org'],
            $this->lines('curl', ['host' => 'example.org'])
        );
    }

    // --- ssl-cert --------------------------------------------------------------

    public function testASecureConnectionFailureIsReported(): void
    {
        $this->io->peer = ['connected' => false, 'errno' => 111, 'errstr' => 'Connection refused', 'cert' => null, 'chain' => []];

        self::assertSame(
            ['ssl-cert example.org:443', '', 'Connection failed: Connection refused (errno 111)'],
            $this->lines('ssl-cert', ['host' => 'example.org'])
        );
        self::assertSame([['example.org', 443, 10]], $this->io->callsTo('tlsPeer'));
    }

    public function testAHandshakeWithoutACertificateIsReported(): void
    {
        $this->io->peer = ['connected' => true, 'errno' => 0, 'errstr' => '', 'cert' => null, 'chain' => []];

        self::assertSame(
            ['ssl-cert example.org:8443', '', 'No certificate could be captured.'],
            $this->lines('ssl-cert', ['host' => 'example.org', 'port' => 8443, 'timeout' => 3])
        );
        self::assertSame([['example.org', 8443, 3]], $this->io->callsTo('tlsPeer'));
    }

    public function testTheCertificateIsDescribed(): void
    {
        $cert = $this->certificate('example.org', 'Example Org', ['example.org', 'www.example.org']);
        $parsed = openssl_x509_parse($cert);
        $this->io->peer = ['connected' => true, 'errno' => 0, 'errstr' => '', 'cert' => $cert, 'chain' => []];

        $lines = $this->lines('ssl-cert', ['host' => 'example.org']);

        self::assertSame('ssl-cert example.org:443', $lines[0]);
        self::assertSame('', $lines[1]);
        self::assertSame('Subject:     /CN=example.org/O=Example Org', $lines[2]);
        self::assertSame('Common Name: example.org', $lines[3]);
        self::assertSame('Issuer:      example.org', $lines[4]);
        self::assertSame('Valid from:  ' . gmdate('Y-m-d H:i:s', $parsed['validFrom_time_t']) . ' UTC', $lines[5]);
        self::assertMatchesRegularExpression(
            '/^Valid to:    ' . preg_quote(gmdate('Y-m-d H:i:s', $parsed['validTo_time_t']), '/') . ' UTC  \((29|30) days remaining\)$/',
            $lines[6]
        );
        self::assertSame('Serial:      ' . $parsed['serialNumberHex'], $lines[7]);
        self::assertSame('Signature:   RSA-SHA256', $lines[8]);
        self::assertSame('SAN:         DNS:example.org, DNS:www.example.org', $lines[9]);
        self::assertCount(10, $lines);
    }

    public function testAnExpiredCertificateShowsNegativeDaysRemaining(): void
    {
        $cert = $this->certificate('old.example', 'Old', [], 0);
        $this->io->peer = ['connected' => true, 'errno' => 0, 'errstr' => '', 'cert' => $cert, 'chain' => []];

        self::assertMatchesRegularExpression('/\((-1|0) days remaining\)/', $this->executor->run('ssl-cert', ['host' => 'old.example']));
    }

    public function testACertificateChainIsListedWhenThereIsMoreThanOneCertificate(): void
    {
        $leaf = $this->certificate('leaf.example', 'Leaf Org', ['leaf.example']);
        $ca = $this->certificate('Root CA', 'Root Org', []);
        $this->io->peer = ['connected' => true, 'errno' => 0, 'errstr' => '', 'cert' => $leaf, 'chain' => [$leaf, $ca]];

        $lines = $this->lines('ssl-cert', ['host' => 'leaf.example']);

        self::assertSame(['', 'Certificate chain:', '  0. /CN=leaf.example/O=Leaf Org', '  1. /CN=Root CA/O=Root Org'], \array_slice($lines, -4));
    }

    public function testASingleCertificateHasNoChainSection(): void
    {
        $leaf = $this->certificate('leaf.example', 'Leaf Org', []);
        $this->io->peer = ['connected' => true, 'errno' => 0, 'errstr' => '', 'cert' => $leaf, 'chain' => [$leaf]];

        self::assertStringNotContainsString('Certificate chain', $this->executor->run('ssl-cert', ['host' => 'leaf.example']));
    }

    public function testAnUnparsableCertificateRendersPlaceholders(): void
    {
        $this->io->peer = ['connected' => true, 'errno' => 0, 'errstr' => '', 'cert' => 'garbage', 'chain' => []];

        self::assertSame([
            'ssl-cert example.org:443',
            '',
            'Subject:     ',
            'Common Name: -',
            'Issuer:      -',
            'Valid from:  ? UTC',
            'Valid to:    ? UTC',
            'Serial:      -',
            'Signature:   -',
            'SAN:         ',
        ], $this->lines('ssl-cert', ['host' => 'example.org']));
    }

    public function testTheIssuerFallsBackToItsOrganization(): void
    {
        $cert = $this->certificate('', 'Only Org', []);
        $this->io->peer = ['connected' => true, 'errno' => 0, 'errstr' => '', 'cert' => $cert, 'chain' => []];

        $lines = $this->lines('ssl-cert', ['host' => 'example.org']);

        self::assertSame('Common Name: -', $lines[3]);
        self::assertSame('Issuer:      Only Org', $lines[4]);
    }

    // --- ciphers ---------------------------------------------------------------

    public function testCiphersNeedAReachablePort(): void
    {
        $this->io->tcp = [[false, 111, 'Connection refused']];

        self::assertSame(
            ['ciphers example.org:443', '', 'Cannot connect to example.org:443 (Connection refused)'],
            $this->lines('ciphers', ['host' => 'example.org'])
        );
        self::assertSame([], $this->io->callsTo('tlsHandshake'));
    }

    public function testCiphersReportTheNegotiatedProtocolAndEverySupportedSuite(): void
    {
        $this->io->crypto = ['protocol' => 'TLSv1.3', 'cipher_name' => 'TLS_AES_256_GCM_SHA384'];
        $this->io->supportedCiphers = ['ECDHE-RSA-AES128-GCM-SHA256', 'AES128-SHA'];

        $lines = $this->lines('ciphers', ['host' => 'example.org', 'port' => 8443, 'timeout' => 9]);

        self::assertSame([
            'ciphers example.org:8443',
            '',
            'Negotiated by default: TLSv1.3, TLS_AES_256_GCM_SHA384',
            '',
            'Supported cipher suites (TLS 1.0 - 1.2):',
            '  [+] ECDHE-RSA-AES128-GCM-SHA256',
            '  [+] AES128-SHA',
            '',
            '2 of 19 probed cipher suites supported.',
        ], $lines);
        self::assertSame([['example.org', 8443, 9]], $this->io->callsTo('tlsCrypto'));
        self::assertCount(19, $this->io->callsTo('tlsHandshake'));
    }

    public function testEachCipherIsProbedWithARestrictedLegacyContext(): void
    {
        $this->executor->run('ciphers', ['host' => 'example.org', 'timeout' => 30]);

        [$host, $port, $timeout, $options] = $this->io->callsTo('tlsHandshake')[0];
        self::assertSame(['example.org', 443, 4], [$host, $port, $timeout]);
        self::assertSame([
            'ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256',
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => 'example.org',
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ], $options);
        $probed = array_column(array_column($this->io->callsTo('tlsHandshake'), 3), 'ciphers');
        self::assertSame(array_values(array_unique($probed)), $probed);
        self::assertContains('DES-CBC3-SHA', $probed);
    }

    public function testThePerCipherTimeoutNeverExceedsTheRequestedOne(): void
    {
        $this->executor->run('ciphers', ['host' => 'example.org', 'timeout' => 2]);

        self::assertSame(2, $this->io->callsTo('tlsHandshake')[0][2]);
    }

    public function testCiphersWithoutAnyLegacySupportSaySo(): void
    {
        $this->io->supportedCiphers = [];

        $lines = $this->lines('ciphers', ['host' => 'example.org']);

        self::assertSame([
            'Supported cipher suites (TLS 1.0 - 1.2):',
            '  (none of the probed legacy cipher suites are supported)',
            '',
            '0 of 19 probed cipher suites supported.',
        ], \array_slice($lines, -4));
        self::assertStringNotContainsString('Negotiated by default', implode("\n", $lines));
    }

    public function testMissingCryptoMetadataRendersQuestionMarks(): void
    {
        $this->io->crypto = ['other' => 'x'];

        self::assertContains('Negotiated by default: ?, ?', $this->lines('ciphers', ['host' => 'example.org']));
    }

    // --- helpers ---------------------------------------------------------------

    /** @var array<string, \OpenSSLCertificate> */
    private static array $certificates = [];

    /**
     * A self-signed certificate generated on the fly (no fixtures, no network).
     *
     * @param string[] $san
     *
     */
    private function certificate(string $commonName, string $organization, array $san, int $days = 30): \OpenSSLCertificate
    {
        $cacheKey = implode('|', [$commonName, $organization, implode(',', $san), $days]);
        if (isset(self::$certificates[$cacheKey])) {
            return self::$certificates[$cacheKey];
        }

        $config = tempnam(sys_get_temp_dir(), 'aaxis_ssl_');
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\n"
            . ($san === [] ? '' : 'subjectAltName=' . implode(',', array_map(static fn (string $n) => 'DNS:' . $n, $san)) . "\n"));
        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256'];
            $key = openssl_pkey_new($options + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $subject = array_filter(['commonName' => $commonName, 'organizationName' => $organization], static fn (string $v) => $v !== '');
            $csr = openssl_csr_new($subject, $key, $options);
            $cert = openssl_csr_sign($csr, null, $key, $days, $options + ['x509_extensions' => 'v3'], 4242);
        } finally {
            unlink($config);
        }
        if ($cert === false) {
            self::markTestSkipped('OpenSSL cannot generate a test certificate here.');
        }

        return self::$certificates[$cacheKey] = $cert;
    }
}
