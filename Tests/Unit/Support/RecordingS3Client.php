<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support;

use Aaxis\Bundle\DevToolsBundle\Storage\S3Client;

/**
 * S3Client whose HTTP exchange is replaced by a queue of canned responses; every request that would
 * have hit the wire is recorded instead (signing and request building still run for real).
 */
final class RecordingS3Client extends S3Client
{
    /** @var array<int, array{method: string, url: string, headers: string[], body: string|null}> */
    public array $requests = [];

    /** @var array<int, array{status: int, body: string, error: string, responseHeaders: array<string, string>}> */
    private array $queue = [];

    /**
     * @param array<string, string> $responseHeaders
     */
    public function respond(int $status, string $body = '', array $responseHeaders = [], string $error = ''): self
    {
        $this->queue[] = ['status' => $status, 'body' => $body, 'error' => $error, 'responseHeaders' => $responseHeaders];

        return $this;
    }

    protected function sendRequest(string $method, string $url, array $curlHeaders, ?string $body): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $curlHeaders, 'body' => $body];
        if ($this->queue === []) {
            throw new \LogicException('No canned response left for ' . $method . ' ' . $url);
        }

        return array_shift($this->queue);
    }

    /**
     * @return array<string, string>
     */
    public static function parseHeaders(string $block): array
    {
        return self::parseResponseHeaders($block);
    }

    /**
     * Request header lines as a name => value map (names lower-cased).
     *
     * @param string[] $lines
     *
     * @return array<string, string>
     */
    public static function headerMap(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $map[strtolower($name)] = $value;
        }

        return $map;
    }
}
