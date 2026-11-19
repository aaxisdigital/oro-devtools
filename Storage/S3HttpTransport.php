<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;

/**
 * The HTTP exchanges of {@see S3Client}, over curl: a buffered request/response, a response streamed
 * into a local file, and a request body streamed from a local file. Requests arrive already signed.
 * A status of 0 means the endpoint could not be reached ("error" says why).
 */
class S3HttpTransport
{
    /** Transfers of any size: no overall deadline, but one that stalls (under 1 KB/s for 2 minutes) is aborted. */
    private const array STREAMING_OPTIONS = [
        \CURLOPT_CONNECTTIMEOUT => 5,
        \CURLOPT_TIMEOUT => 0,
        \CURLOPT_LOW_SPEED_LIMIT => 1024,
        \CURLOPT_LOW_SPEED_TIME => 120,
    ];

    /**
     * @param string[] $headers "Name: value" request header lines
     *
     * @return array{status: int, body: string, error: string, responseHeaders: array<string, string>}
     */
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, \CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, \CURLOPT_HTTPHEADER, $headers);
        // The response headers come back in front of the body (split below by their size).
        curl_setopt($ch, \CURLOPT_HEADER, true);
        if ($body !== null) {
            curl_setopt($ch, \CURLOPT_POSTFIELDS, $body);
        }
        if ($method === 'HEAD') {
            // Without NOBODY curl would wait for a body a HEAD response never sends.
            curl_setopt($ch, \CURLOPT_NOBODY, true);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        $headerSize = (int) curl_getinfo($ch, \CURLINFO_HEADER_SIZE);

        $responseHeaders = [];
        $payload = '';
        if (\is_string($raw)) {
            $responseHeaders = self::parseResponseHeaders(substr($raw, 0, $headerSize));
            $payload = substr($raw, $headerSize);
        }

        return [
            'status' => $status,
            'body' => $payload,
            'error' => $error,
            'responseHeaders' => $responseHeaders,
        ];
    }

    /**
     * Writes the response body straight into $targetFile. When the endpoint answers with an error,
     * that (small) error document is returned as "body".
     *
     * @param string[] $headers "Name: value" request header lines
     *
     * @return array{status: int, body: string, error: string}
     */
    public function sendToFile(string $method, string $url, array $headers, string $targetFile): array
    {
        $handle = @fopen($targetFile, 'wb');
        if ($handle === false) {
            throw new StorageException('Unable to write the downloaded object to a local file.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_FILE => $handle,
        ] + self::STREAMING_OPTIONS);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        fclose($handle);

        return [
            'status' => $status,
            'body' => $status >= 400 ? (string) file_get_contents($targetFile) : '',
            'error' => $error,
        ];
    }

    /**
     * Streams $sourceFile as the request body.
     *
     * @param string[] $headers "Name: value" request header lines
     *
     * @return array{status: int, body: string, error: string}
     */
    public function sendFromFile(string $method, string $url, array $headers, string $sourceFile): array
    {
        $size = @filesize($sourceFile);
        $handle = @fopen($sourceFile, 'rb');
        if ($handle === false || $size === false) {
            throw new StorageException('Unable to read the file to upload.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_UPLOAD => true,
            \CURLOPT_INFILE => $handle,
            \CURLOPT_INFILESIZE => $size,
            // An empty "Expect:" stops curl from waiting on a "100 Continue" some endpoints never send.
            \CURLOPT_HTTPHEADER => array_merge($headers, ['Expect:']),
            \CURLOPT_RETURNTRANSFER => true,
        ] + self::STREAMING_OPTIONS);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        fclose($handle);

        return ['status' => $status, 'body' => \is_string($raw) ? $raw : '', 'error' => $error];
    }

    /**
     * Lower-cased header name => value, from a raw header block (later duplicates win).
     *
     * @return array<string, string>
     */
    public static function parseResponseHeaders(string $block): array
    {
        $headers = [];
        foreach (preg_split('/\r\n|\n/', $block) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }

        return $headers;
    }
}
