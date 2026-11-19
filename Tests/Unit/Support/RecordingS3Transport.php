<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support;

use Aaxis\Bundle\DevToolsBundle\Storage\S3HttpTransport;

/**
 * S3 transport answering from a queue of canned responses; every request that would have hit the wire
 * is recorded instead. Streamed downloads write a canned 2xx body into the target file; streamed
 * uploads are recorded with the file's content as their body.
 */
final class RecordingS3Transport extends S3HttpTransport
{
    /** @var array<int, array{method: string, url: string, headers: string[], body: string|null, targetFile?: string}> */
    public array $requests = [];

    /** @var array<int, array{status: int, body: string, error: string, responseHeaders: array<string, string>}> */
    private array $queue = [];

    /**
     * @param array<string, string> $responseHeaders
     */
    public function respond(int $status, string $body = '', array $responseHeaders = [], string $error = ''): void
    {
        $this->queue[] = ['status' => $status, 'body' => $body, 'error' => $error, 'responseHeaders' => $responseHeaders];
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->queue === []) {
            throw new \LogicException('No canned response left for ' . $method . ' ' . $url);
        }

        return array_shift($this->queue);
    }

    public function sendToFile(string $method, string $url, array $headers, string $targetFile): array
    {
        $response = $this->send($method, $url, $headers, null);
        $this->requests[\count($this->requests) - 1]['targetFile'] = $targetFile;
        file_put_contents($targetFile, $response['body']);

        return [
            'status' => $response['status'],
            'body' => $response['status'] >= 400 ? $response['body'] : '',
            'error' => $response['error'],
        ];
    }

    public function sendFromFile(string $method, string $url, array $headers, string $sourceFile): array
    {
        $response = $this->send($method, $url, $headers, (string) file_get_contents($sourceFile));

        return ['status' => $response['status'], 'body' => $response['body'], 'error' => $response['error']];
    }
}
