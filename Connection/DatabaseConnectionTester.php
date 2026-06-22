<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Connection;

use Aaxis\Bundle\CommonBundle\Connection\ConnectionTesterInterface;
use Doctrine\DBAL\Connection;

/**
 * "Test connection" check for the Database Viewer. Reports the configured driver/host/database and
 * the server version on success; the password is redacted from any failure message.
 *
 * @phpstan-import-type TestResult from ConnectionTesterInterface
 */
class DatabaseConnectionTester implements ConnectionTesterInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[\Override]
    public function getTool(): string
    {
        return 'database_viewer';
    }

    /**
     * @return TestResult
     */
    #[\Override]
    public function test(array $overrides = []): array
    {
        $params = $this->connection->getParams();
        $secrets = [(string) ($params['password'] ?? '')];
        $details = [
            'Driver' => (string) ($params['driver'] ?? ''),
            'Host' => (string) ($params['host'] ?? ''),
            'Port' => (string) ($params['port'] ?? ''),
            'Database' => (string) ($params['dbname'] ?? ''),
            'User' => (string) ($params['user'] ?? ''),
        ];

        try {
            $this->connection->executeQuery('SELECT 1');
            $details['Server version'] = $this->databaseServerVersion();

            return ['success' => true, 'message' => 'Database connection succeeded.', 'details' => $details];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Database connection failed: ' . $this->redact($e->getMessage(), $secrets),
                'details' => $details,
            ];
        }
    }

    private function databaseServerVersion(): string
    {
        try {
            $native = $this->connection->getNativeConnection();
            if (\is_object($native) && method_exists($native, 'getAttribute') && \defined('\PDO::ATTR_SERVER_VERSION')) {
                return (string) $native->getAttribute(\PDO::ATTR_SERVER_VERSION);
            }
        } catch (\Throwable) {
            // ignore - version is best-effort
        }

        return '';
    }

    /**
     * Replaces any non-empty secret value found in the message with a mask.
     *
     * @param string[] $secrets
     */
    private function redact(string $message, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return $message;
    }
}
