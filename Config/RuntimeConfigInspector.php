<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Config;

use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

/**
 * Gathers the environment variables, container parameters and PHP runtime settings resolved for
 * the running instance.
 *
 * Values are redacted defensively: keys that look secret (password, secret, token, key, ...) are
 * fully masked, and credentials embedded in URLs/DSNs (scheme://user:pass@host) are stripped, so
 * the screen never reveals secrets even though it lists everything.
 */
class RuntimeConfigInspector
{
    public const string MASK = '••••••••';

    private const string SENSITIVE_KEY_PATTERN =
        '/(pass|passwd|password|secret|token|api[_-]?key|apikey|private|credential|salt|cert'
        . '|signature|encryption|cipher|bearer|authorization)/i';

    public function __construct(private ContainerBagInterface $parameters)
    {
    }

    /**
     * @return array<int, array{key: string, value: string, sensitive: bool}>
     */
    public function getEnvironmentVariables(): array
    {
        $env = getenv();
        if (!\is_array($env)) {
            $env = [];
        }

        $rows = [];
        foreach ($env as $key => $value) {
            $rows[] = $this->row((string) $key, $value);
        }

        return $this->sortRows($rows);
    }

    /**
     * @return array<int, array{key: string, value: string, sensitive: bool}>
     */
    public function getParameters(): array
    {
        $rows = [];
        foreach ($this->parameters->all() as $key => $value) {
            $rows[] = $this->row((string) $key, $value);
        }

        return $this->sortRows($rows);
    }

    /**
     * @return array<int, array{key: string, value: string, sensitive: bool}>
     */
    public function getRuntimeInfo(): array
    {
        $info = [
            'PHP version' => PHP_VERSION,
            'PHP SAPI' => \PHP_SAPI,
            'Operating system' => PHP_OS,
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'date.timezone' => (string) (ini_get('date.timezone') ?: date_default_timezone_get()),
            'opcache.enable' => ini_get('opcache.enable') ? 'on' : 'off',
            'xdebug' => \extension_loaded('xdebug') ? 'loaded' : 'not loaded',
            'loaded extensions' => implode(', ', get_loaded_extensions()),
        ];

        $rows = [];
        foreach ($info as $key => $value) {
            $rows[] = ['key' => $key, 'value' => $value, 'sensitive' => false];
        }

        return $rows;
    }

    /**
     * @return array{key: string, value: string, sensitive: bool}
     */
    private function row(string $key, mixed $value): array
    {
        $sensitive = false;

        if (\is_array($value)) {
            $display = sprintf('[array: %d items]', \count($value));
        } elseif (null === $value) {
            $display = 'null';
        } elseif (\is_bool($value)) {
            $display = $value ? 'true' : 'false';
        } elseif (!\is_scalar($value)) {
            $display = '[' . \gettype($value) . ']';
        } else {
            // Redaction is always on: a value is masked when either its key looks secret or the
            // value itself is unmistakably a secret (PEM key, JWT), and credentials embedded in
            // URLs/DSNs are stripped from anything else.
            $sensitive = $this->isSensitiveKey($key) || $this->isSensitiveValue((string) $value);
            $display = $sensitive ? self::MASK : $this->maskUrlCredentials((string) $value);
        }

        return ['key' => $key, 'value' => $display, 'sensitive' => $sensitive];
    }

    private function isSensitiveKey(string $key): bool
    {
        return 1 === preg_match(self::SENSITIVE_KEY_PATTERN, $key);
    }

    /**
     * Flags values that are unmistakably secret regardless of their key name — PEM/SSH private-key
     * blocks and JWTs — closing the gap where a secret sits under an innocuously named key.
     */
    private function isSensitiveValue(string $value): bool
    {
        $value = trim($value);
        if (str_contains($value, '-----BEGIN') && str_contains($value, 'PRIVATE KEY')) {
            return true;
        }
        if (str_starts_with($value, 'ssh-rsa ') || str_starts_with($value, 'ssh-ed25519 ')) {
            return true;
        }

        // JWT: three base64url segments separated by dots, beginning with the standard header.
        return 1 === preg_match('/^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $value);
    }

    /**
     * Replaces the password portion of "scheme://user:pass@host" style values with a mask.
     */
    private function maskUrlCredentials(string $value): string
    {
        return preg_replace('#(://[^:/@\s]+:)([^@/\s]+)(@)#', '$1' . self::MASK . '$3', $value) ?? $value;
    }

    /**
     * @param array<int, array{key: string, value: string, sensitive: bool}> $rows
     *
     * @return array<int, array{key: string, value: string, sensitive: bool}>
     */
    private function sortRows(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['key'], $b['key']));

        return $rows;
    }
}
