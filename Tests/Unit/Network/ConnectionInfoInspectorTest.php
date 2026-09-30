<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Network;

use Aaxis\Bundle\DevToolsBundle\Network\ConnectionInfoInspector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ConnectionInfoInspectorTest extends TestCase
{
    private ConnectionInfoInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new ConnectionInfoInspector();
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $headers
     */
    private function request(array $server = [], array $headers = []): Request
    {
        $request = new Request([], [], [], [], [], $server);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    public function testWithoutTrustedProxiesTheRemoteAddressIsTheTruth(): void
    {
        $request = $this->request(['REMOTE_ADDR' => '203.0.113.9'], ['X-Forwarded-For' => '198.51.100.1']);

        self::assertSame([
            'ip' => '203.0.113.9',
            'source' => 'remote_addr',
            'via' => null,
            'chain' => [['value' => '203.0.113.9', 'trusted' => false]],
        ], $this->inspector->resolveClientIp($request, []));
    }

    public function testAnUntrustedPeerCannotForgeItsIdentityThroughForwardedHeaders(): void
    {
        $request = $this->request(['REMOTE_ADDR' => '203.0.113.9'], ['X-Forwarded-For' => '198.51.100.1']);

        $result = $this->inspector->resolveClientIp($request, ['10.0.0.0/8']);

        self::assertSame('203.0.113.9', $result['ip']);
        self::assertSame('remote_addr', $result['source']);
    }

    public function testATrustedPeerLetsTheForwardedChainBeInspectedRightToLeft(): void
    {
        $request = $this->request(
            ['REMOTE_ADDR' => '10.0.0.5'],
            ['X-Forwarded-For' => '198.51.100.7, 192.0.2.44 , 10.0.0.9']
        );

        $result = $this->inspector->resolveClientIp($request, ['10.0.0.0/8']);

        self::assertSame('192.0.2.44', $result['ip']);
        self::assertSame('forwarded', $result['source']);
        self::assertSame('10.0.0.5', $result['via']);
        self::assertSame([
            ['value' => '10.0.0.5', 'trusted' => true],
            ['value' => '10.0.0.9', 'trusted' => true],
            ['value' => '192.0.2.44', 'trusted' => false],
            ['value' => '198.51.100.7', 'trusted' => false],
        ], $result['chain']);
    }

    public function testAHopWithAPortIsResolvedWithoutIt(): void
    {
        $request = $this->request(['REMOTE_ADDR' => '10.0.0.5'], ['X-Forwarded-For' => '192.0.2.44:51234']);

        $result = $this->inspector->resolveClientIp($request, ['10.0.0.5']);

        self::assertSame('192.0.2.44', $result['ip']);
        self::assertSame('192.0.2.44:51234', $result['chain'][1]['value']);
    }

    public function testIpv6HopsKeepTheirColons(): void
    {
        $request = $this->request(['REMOTE_ADDR' => '10.0.0.5'], ['X-Forwarded-For' => '2001:db8::1']);

        self::assertSame('2001:db8::1', $this->inspector->resolveClientIp($request, ['10.0.0.5'])['ip']);
    }

    public function testGarbageInTheChainIsNeverReportedAsTheClient(): void
    {
        $request = $this->request(['REMOTE_ADDR' => '10.0.0.5'], ['X-Forwarded-For' => 'not-an-ip, 192.0.2.44']);

        $result = $this->inspector->resolveClientIp($request, ['10.0.0.5']);

        // Right-to-left: 192.0.2.44 is the first untrusted valid hop.
        self::assertSame('192.0.2.44', $result['ip']);
        self::assertFalse($result['chain'][2]['trusted']);
    }

    public function testWhenEveryHopIsATrustedProxyTheOutermostHopIsReported(): void
    {
        $request = $this->request(['REMOTE_ADDR' => '10.0.0.5'], ['X-Forwarded-For' => '10.0.0.9, 10.0.0.8']);

        $result = $this->inspector->resolveClientIp($request, ['10.0.0.0/8']);

        self::assertSame('all_trusted', $result['source']);
        self::assertSame('10.0.0.9', $result['ip']);
        self::assertNull($result['via']);
        self::assertCount(3, $result['chain']);
    }

    public function testATrustedPeerWithoutForwardedHeadersReportsItself(): void
    {
        $request = $this->request(['REMOTE_ADDR' => '10.0.0.5']);

        $result = $this->inspector->resolveClientIp($request, ['10.0.0.0/8']);

        self::assertSame('all_trusted', $result['source']);
        self::assertSame('10.0.0.5', $result['ip']);
    }

    public function testServerInfo(): void
    {
        $request = $this->request(
            [
                'SERVER_ADDR' => '172.18.0.3', 'SERVER_PORT' => '443', 'SERVER_NAME' => 'oro.local',
                'REMOTE_ADDR' => '10.0.0.5', 'REMOTE_PORT' => '51000', 'HTTPS' => 'on',
            ],
            ['Host' => 'oro.example.com']
        );

        self::assertSame([
            'server_addr' => '172.18.0.3',
            'server_port' => '443',
            'server_name' => 'oro.local',
            'host_header' => 'oro.example.com',
            'https' => 'yes',
            'remote_addr' => '10.0.0.5',
            'remote_port' => '51000',
        ], $this->inspector->getServerInfo($request));
    }

    /**
     * @dataProvider plainHttpProvider
     */
    public function testServerInfoReportsPlainHttp(array $server): void
    {
        self::assertSame('no', $this->inspector->getServerInfo($this->request($server))['https']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function plainHttpProvider(): iterable
    {
        yield 'absent' => [[]];
        yield 'off' => [['HTTPS' => 'off']];
        yield 'empty' => [['HTTPS' => '']];
    }

    public function testProxyHeadersAreListedInAFixedOrderWithNullsForMissingOnes(): void
    {
        $request = $this->request([], ['X-Real-IP' => '1.2.3.4', 'Forwarded' => 'for=1.2.3.4']);

        $headers = $this->inspector->getProxyHeaders($request);

        self::assertSame([
            'X-Forwarded-For', 'X-Real-IP', 'Client-IP', 'CF-Connecting-IP', 'True-Client-IP',
            'X-Forwarded-Host', 'X-Forwarded-Proto', 'Forwarded',
        ], array_keys($headers));
        self::assertSame('1.2.3.4', $headers['X-Real-IP']);
        self::assertSame('for=1.2.3.4', $headers['Forwarded']);
        self::assertNull($headers['X-Forwarded-For']);
    }

    public function testLocalInterfacesMapNamesToTheirUnicastAddresses(): void
    {
        $interfaces = $this->inspector->getLocalInterfaces();

        self::assertNotEmpty($interfaces);
        foreach ($interfaces as $name => $ips) {
            self::assertIsString($name);
            foreach ($ips as $ip) {
                self::assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP), $ip);
            }
        }
        self::assertContains('127.0.0.1', array_merge(...array_values($interfaces)));
    }

    /**
     * @dataProvider trustedProxyCsvProvider
     *
     * @param string[] $expected
     */
    public function testParsesTrustedProxyLists(string $csv, array $expected): void
    {
        self::assertSame($expected, $this->inspector->parseTrustedProxies($csv));
    }

    /**
     * @return iterable<string, array{string, string[]}>
     */
    public static function trustedProxyCsvProvider(): iterable
    {
        yield 'empty' => ['', []];
        yield 'single ip' => ['10.0.0.1', ['10.0.0.1']];
        yield 'mixed with blanks and spaces' => [' 10.0.0.1 , ,192.168.0.0/16,', ['10.0.0.1', '192.168.0.0/16']];
        yield 'invalid tokens dropped' => ['nope, 999.1.1.1, 10.0.0.0/x, 10.0.0.2', ['10.0.0.2']];
        yield 'duplicates removed' => ['10.0.0.1,10.0.0.1', ['10.0.0.1']];
        yield 'ipv6 and cidr' => ['::1, 2001:db8::/32', ['::1', '2001:db8::/32']];
        yield 'slash without ip' => ['/24', []];
        yield 'bare word with no slash' => ['proxy', []];
    }
}
