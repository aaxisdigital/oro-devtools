<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Config;

use Aaxis\Bundle\DevToolsBundle\Config\RuntimeConfigInspector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class RuntimeConfigInspectorTest extends TestCase
{
    private const MASK = RuntimeConfigInspector::MASK;

    /**
     * @param array<string, mixed> $parameters
     */
    private function inspector(array $parameters = []): RuntimeConfigInspector
    {
        return new RuntimeConfigInspector(new ContainerBag(new Container(new ParameterBag($parameters))));
    }

    /**
     * @param array<int, array{key: string, value: string, sensitive: bool}> $rows
     *
     * @return array{key: string, value: string, sensitive: bool}
     */
    private static function row(array $rows, string $key): array
    {
        foreach ($rows as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        self::fail(sprintf('No row "%s".', $key));
    }

    /**
     * @dataProvider secretKeyProvider
     */
    public function testValuesOfSecretLookingKeysAreMasked(string $key): void
    {
        $row = self::row($this->inspector([$key => 'hunter2'])->getParameters(), $key);

        self::assertTrue($row['sensitive']);
        self::assertSame(self::MASK, $row['value']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secretKeyProvider(): iterable
    {
        foreach (['database_password', 'APP_SECRET', 'jwt_token', 'stripe_api_key', 'apikey', 'api-key', 'private_thing',
            'db_credential', 'crypto_salt', 'tls_cert', 'request_signature', 'encryption_key', 'cipher_suite',
            'bearer_value', 'authorization_header', 'DB_PASSWD'] as $key) {
            yield $key => [$key];
        }
    }

    public function testInnocuousKeysAreShown(): void
    {
        $row = self::row($this->inspector(['kernel.environment' => 'prod'])->getParameters(), 'kernel.environment');

        self::assertFalse($row['sensitive']);
        self::assertSame('prod', $row['value']);
    }

    /**
     * @dataProvider secretValueProvider
     */
    public function testSecretLookingValuesAreMaskedUnderAnyKey(string $value): void
    {
        $row = self::row($this->inspector(['harmless' => $value])->getParameters(), 'harmless');

        self::assertTrue($row['sensitive']);
        self::assertSame(self::MASK, $row['value']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secretValueProvider(): iterable
    {
        yield 'pem private key' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIB\n-----END RSA PRIVATE KEY-----"];
        yield 'pem in whitespace' => ["  -----BEGIN PRIVATE KEY-----\nabc  "];
        yield 'ssh rsa' => ['ssh-rsa AAAAB3Nza user@host'];
        yield 'ssh ed25519' => ['ssh-ed25519 AAAAC3Nza user@host'];
        yield 'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl'];
    }

    /**
     * @dataProvider notSecretValueProvider
     */
    public function testOrdinaryValuesAreNotFlaggedAsSecrets(string $value): void
    {
        $row = self::row($this->inspector(['harmless' => $value])->getParameters(), 'harmless');

        self::assertFalse($row['sensitive']);
        self::assertSame($value, $row['value']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notSecretValueProvider(): iterable
    {
        yield 'begin without private key' => ['-----BEGIN CERTIFICATE-----'];
        yield 'private key word only' => ['PRIVATE KEY'];
        yield 'two-segment jwt lookalike' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0'];
        yield 'plain text' => ['just a value'];
    }

    public function testUrlCredentialsAreStrippedFromOtherwiseHarmlessValues(): void
    {
        $rows = $this->inspector([
            'dsn' => 'postgres://app:s3cret@db:5432/oro',
            'plain_dsn' => 'redis://cache:6379',
            'user_only' => 'mongodb://someone@host/db',
        ])->getParameters();

        $dsn = self::row($rows, 'dsn');
        self::assertFalse($dsn['sensitive']);
        self::assertSame('postgres://app:' . self::MASK . '@db:5432/oro', $dsn['value']);
        self::assertSame('redis://cache:6379', self::row($rows, 'plain_dsn')['value']);
        self::assertSame('mongodb://someone@host/db', self::row($rows, 'user_only')['value']);
    }

    public function testNonScalarValuesAreDescribedNotDumped(): void
    {
        $rows = $this->inspector([
            'list' => ['a', 'b', 'c'],
            'nothing' => null,
            'yes' => true,
            'no' => false,
            'number' => 12,
            'ratio' => 1.5,
        ])->getParameters();

        self::assertSame('[array: 3 items]', self::row($rows, 'list')['value']);
        self::assertSame('null', self::row($rows, 'nothing')['value']);
        self::assertSame('true', self::row($rows, 'yes')['value']);
        self::assertSame('false', self::row($rows, 'no')['value']);
        self::assertSame('12', self::row($rows, 'number')['value']);
        self::assertSame('1.5', self::row($rows, 'ratio')['value']);
        self::assertFalse(self::row($rows, 'list')['sensitive']);
    }

    public function testASecretHoldingArrayIsNotExpanded(): void
    {
        $row = self::row($this->inspector(['secret_map' => ['a' => 'topsecret']])->getParameters(), 'secret_map');

        self::assertSame('[array: 1 items]', $row['value']);
        self::assertStringNotContainsString('topsecret', $row['value']);
    }

    public function testObjectsAreReducedToTheirType(): void
    {
        $inspector = $this->inspector();
        $method = new \ReflectionMethod($inspector, 'row');

        $row = $method->invoke($inspector, 'thing', new \stdClass());

        self::assertSame(['key' => 'thing', 'value' => '[object]', 'sensitive' => false], $row);
    }

    public function testParametersAreSortedCaseInsensitivelyByKey(): void
    {
        $rows = $this->inspector(['b.one' => 1, 'A.two' => 2, 'c.three' => 3])->getParameters();

        self::assertSame(['A.two', 'b.one', 'c.three'], array_column($rows, 'key'));
    }

    public function testEnvironmentVariablesAreListedSortedAndRedacted(): void
    {
        putenv('AAXIS_TEST_ZED=plain');
        putenv('AAXIS_TEST_DB_PASSWORD=hunter2');
        putenv('AAXIS_TEST_URL=amqp://guest:guest@mq:5672');

        try {
            $rows = $this->inspector()->getEnvironmentVariables();
        } finally {
            putenv('AAXIS_TEST_ZED');
            putenv('AAXIS_TEST_DB_PASSWORD');
            putenv('AAXIS_TEST_URL');
        }

        self::assertSame('plain', self::row($rows, 'AAXIS_TEST_ZED')['value']);
        self::assertSame(self::MASK, self::row($rows, 'AAXIS_TEST_DB_PASSWORD')['value']);
        self::assertTrue(self::row($rows, 'AAXIS_TEST_DB_PASSWORD')['sensitive']);
        self::assertSame('amqp://guest:' . self::MASK . '@mq:5672', self::row($rows, 'AAXIS_TEST_URL')['value']);
        $keys = array_column($rows, 'key');
        $sorted = $keys;
        usort($sorted, 'strcasecmp');
        self::assertSame($sorted, $keys);
    }

    public function testRuntimeInfoExposesTheKernelEnvironmentAndDebugFlag(): void
    {
        $rows = $this->inspector(['kernel.environment' => 'staging', 'kernel.debug' => true])->getRuntimeInfo();

        self::assertSame('staging', self::row($rows, 'Symfony environment')['value']);
        self::assertSame('on', self::row($rows, 'Debug mode')['value']);
    }

    public function testRuntimeInfoDebugOff(): void
    {
        $rows = $this->inspector(['kernel.environment' => 'prod', 'kernel.debug' => false])->getRuntimeInfo();

        self::assertSame('off', self::row($rows, 'Debug mode')['value']);
    }

    public function testRuntimeInfoWithoutKernelParameters(): void
    {
        $rows = $this->inspector()->getRuntimeInfo();

        self::assertSame('unknown', self::row($rows, 'Symfony environment')['value']);
        self::assertSame('unknown', self::row($rows, 'Debug mode')['value']);
    }

    public function testRuntimeInfoReportsThePhpRuntime(): void
    {
        $rows = $this->inspector()->getRuntimeInfo();

        self::assertSame(PHP_VERSION, self::row($rows, 'PHP version')['value']);
        self::assertSame(\PHP_SAPI, self::row($rows, 'PHP SAPI')['value']);
        self::assertSame(PHP_OS, self::row($rows, 'Operating system')['value']);
        self::assertSame((string) ini_get('memory_limit'), self::row($rows, 'memory_limit')['value']);
        self::assertContains(self::row($rows, 'opcache.enable')['value'], ['on', 'off']);
        self::assertSame(
            extension_loaded('xdebug') ? 'loaded' : 'not loaded',
            self::row($rows, 'xdebug')['value']
        );
        self::assertStringContainsString('json', self::row($rows, 'loaded extensions')['value']);
        self::assertNotSame('', self::row($rows, 'date.timezone')['value']);
        foreach ($rows as $row) {
            self::assertFalse($row['sensitive']);
        }
    }
}
