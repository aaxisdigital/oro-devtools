<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support;

use Aaxis\Bundle\DevToolsBundle\Storage\S3Client;
use Aaxis\Bundle\DevToolsBundle\Storage\S3HttpTransport;

/**
 * S3Client on a {@see RecordingS3Transport}: canned responses instead of the network, and every request
 * that would have hit the wire recorded in $requests (signing and request building still run for real).
 */
final class RecordingS3Client extends S3Client
{
    /** @var array<int, array{method: string, url: string, headers: string[], body: string|null, targetFile?: string}> */
    public array $requests = [];

    private RecordingS3Transport $recorder;

    public function __construct(string $endpoint, string $accessKey, string $secretKey, string $region = 'us-east-1')
    {
        $this->recorder = new RecordingS3Transport();
        $this->requests = &$this->recorder->requests;
        parent::__construct($endpoint, $accessKey, $secretKey, $region, $this->recorder);
    }

    /**
     * @param array<string, string> $responseHeaders
     */
    public function respond(int $status, string $body = '', array $responseHeaders = [], string $error = ''): self
    {
        $this->recorder->respond($status, $body, $responseHeaders, $error);

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public static function parseHeaders(string $block): array
    {
        return S3HttpTransport::parseResponseHeaders($block);
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
