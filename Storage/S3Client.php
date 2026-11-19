<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;

/**
 * Minimal, dependency-free S3 client (AWS Signature V4, path-style addressing) built on curl.
 *
 * Implements just what the Bucket Viewer needs: list a bucket (one folder level, or recursively page by
 * page), fetch an object (whole, a byte range, or streamed to a local file), check an object exists,
 * and the write operations (put — from memory or streamed from a local file — and delete). It targets
 * S3-compatible endpoints such as MinIO. Credentials are never echoed back to the client.
 *
 * Signing lives in {@see S3Signer}, the HTTP exchange in {@see S3HttpTransport} and response
 * interpretation in {@see S3Response}; this class builds the requests and refuses metadata endpoints.
 */
class S3Client
{
    private const string DEFAULT_CONTENT_TYPE = 'application/octet-stream';

    private string $scheme;
    private string $host;
    private int $port;
    private string $hostHeader;
    private ?bool $endpointAllowed = null;
    private readonly S3Signer $signer;
    private readonly S3HttpTransport $transport;

    public function __construct(
        string $endpoint,
        private readonly string $accessKey,
        string $secretKey,
        private readonly string $region = 'us-east-1',
        ?S3HttpTransport $transport = null,
    ) {
        $parts = parse_url($endpoint) ?: [];
        $this->scheme = (string) ($parts['scheme'] ?? 'http');
        $this->host = (string) ($parts['host'] ?? 'localhost');
        $this->port = (int) ($parts['port'] ?? ($this->scheme === 'https' ? 443 : 80));
        $isDefaultPort = ($this->scheme === 'https' && $this->port === 443) || ($this->scheme === 'http' && $this->port === 80);
        $this->hostHeader = $isDefaultPort ? $this->host : $this->host . ':' . $this->port;
        $this->signer = new S3Signer($accessKey, $secretKey, $region);
        $this->transport = $transport ?? new S3HttpTransport();
    }

    /**
     * Lists the immediate children (common prefixes + objects) of $prefix in $bucket, following
     * pagination. Every page must be read even for small folders: OCI's S3 compatibility API ends a
     * delimited page early (after a folder holding many objects) and reports it truncated with a
     * single entry, although max-keys allows 1000.
     *
     * @return array{prefixes: array<int, string>, objects: array<int, array{key: string, size: int, lastModified: int}>}
     */
    public function listObjects(string $bucket, string $prefix): array
    {
        $prefixes = [];
        $objects = [];
        $token = '';
        do {
            $xml = $this->listPage($bucket, $prefix, ['list-type' => '2', 'delimiter' => '/', 'max-keys' => '1000'], $token);
            foreach ($xml->CommonPrefixes ?? [] as $cp) {
                $prefixes[] = (string) $cp->Prefix;
            }
            foreach ($xml->Contents ?? [] as $object) {
                $key = (string) $object->Key;
                // Skip the "folder marker" object that equals the prefix itself.
                if ($key === $prefix) {
                    continue;
                }
                $objects[] = [
                    'key' => $key,
                    'size' => (int) $object->Size,
                    'lastModified' => strtotime((string) $object->LastModified) ?: 0,
                ];
            }
            $token = S3Response::nextToken($xml);
        } while ($token !== '');

        return ['prefixes' => $prefixes, 'objects' => $objects];
    }

    /**
     * Fetches an object (optionally only the first $maxBytes).
     *
     * @return array{status: int, contentType: string, contentLength: int|null, body: string}
     */
    public function getObject(string $bucket, string $key, ?int $maxBytes = null): array
    {
        $headers = [];
        if ($maxBytes !== null && $maxBytes > 0) {
            $headers['range'] = 'bytes=0-' . ($maxBytes - 1);
        }
        $response = $this->request('GET', $this->objectPath($bucket, $key), [], $headers);
        if ($response['status'] >= 400) {
            throw new StorageException(S3Response::describeError($response));
        }

        $length = isset($response['responseHeaders']['content-length'])
            ? (int) $response['responseHeaders']['content-length']
            : null;

        return [
            'status' => $response['status'],
            'contentType' => $response['responseHeaders']['content-type'] ?? self::DEFAULT_CONTENT_TYPE,
            'contentLength' => $length,
            'body' => $response['body'],
        ];
    }

    /**
     * Verifies connectivity and credentials by listing the bucket root (HEAD-like check).
     *
     * @return array{success: bool, message: string, details: array<string, string>}
     */
    public function testConnection(string $bucket): array
    {
        $details = [
            'Endpoint' => sprintf('%s://%s:%d', $this->scheme, $this->host, $this->port),
            'Region' => $this->region,
            'Access key' => $this->accessKey,
            'Bucket' => $bucket,
        ];

        try {
            $response = $this->request('GET', '/' . $this->encodePath($bucket), ['list-type' => '2', 'max-keys' => '1']);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection failed: ' . $e->getMessage(), 'details' => $details];
        }

        return match (true) {
            $response['status'] === 0 => ['success' => false, 'message' => 'Endpoint unreachable (' . $response['error'] . ').', 'details' => $details],
            $response['status'] === 403 => ['success' => false, 'message' => 'Access denied (HTTP 403). Check the access key / secret.', 'details' => $details],
            $response['status'] === 404 => ['success' => false, 'message' => sprintf('Bucket "%s" not found (HTTP 404).', $bucket), 'details' => $details],
            $response['status'] >= 400 => ['success' => false, 'message' => S3Response::describeError($response), 'details' => $details],
            default => ['success' => true, 'message' => 'Connected to the bucket.', 'details' => $details],
        };
    }

    /**
     * Uploads (or overwrites) an object.
     */
    public function putObject(string $bucket, string $key, string $body, string $contentType): void
    {
        $response = $this->request(
            'PUT',
            $this->objectPath($bucket, $key),
            [],
            ['content-type' => $contentType !== '' ? $contentType : self::DEFAULT_CONTENT_TYPE],
            $body
        );
        if ($response['status'] >= 400) {
            throw new StorageException(S3Response::describeError($response));
        }
    }

    /**
     * Deletes a single object.
     */
    public function deleteObject(string $bucket, string $key): void
    {
        $response = $this->request('DELETE', $this->objectPath($bucket, $key));
        if ($response['status'] >= 400 && $response['status'] !== 404) {
            throw new StorageException(S3Response::describeError($response));
        }
    }

    /**
     * Lists every object key under $prefix (recursive, no delimiter), following pagination.
     *
     * @return array<int, string>
     */
    public function listAllKeys(string $bucket, string $prefix): array
    {
        $keys = [];
        $token = '';
        do {
            $page = $this->listObjectsPage($bucket, $prefix, $token);
            foreach ($page['objects'] as $object) {
                $keys[] = $object['key'];
            }
            $token = $page['nextToken'];
        } while ($token !== '');

        return $keys;
    }

    /**
     * One page (up to 1000 objects) of every object under $prefix (recursive, no delimiter), starting
     * at $token ("" = first page). Pass the returned nextToken back to read the following page; ""
     * means the listing is complete. A page may be empty while a token is still returned.
     *
     * @return array{objects: array<int, array{key: string, size: int}>, nextToken: string}
     */
    public function listObjectsPage(string $bucket, string $prefix, string $token = ''): array
    {
        $xml = $this->listPage($bucket, $prefix, ['list-type' => '2', 'max-keys' => '1000'], $token);
        $objects = [];
        foreach ($xml->Contents ?? [] as $object) {
            $objects[] = ['key' => (string) $object->Key, 'size' => (int) $object->Size];
        }

        return ['objects' => $objects, 'nextToken' => S3Response::nextToken($xml)];
    }

    /**
     * Whether an object exists (HEAD). Any answer other than found / not found is an error.
     */
    public function objectExists(string $bucket, string $key): bool
    {
        $response = $this->request('HEAD', $this->objectPath($bucket, $key));
        if ($response['status'] === 404) {
            return false;
        }
        S3Response::assertSuccessful($response);

        return true;
    }

    /**
     * Streams an object into a local file, so large objects never sit in memory.
     *
     * @return bool false when the object does not exist (the file is then left empty)
     */
    public function downloadObject(string $bucket, string $key, string $targetFile): bool
    {
        [$url, $headers] = $this->prepareRequest('GET', $this->objectPath($bucket, $key));
        $response = $this->transport->sendToFile('GET', $url, $headers, $targetFile);
        if ($response['status'] === 404) {
            file_put_contents($targetFile, '');

            return false;
        }
        S3Response::assertSuccessful($response);

        return true;
    }

    /**
     * Uploads (or overwrites) an object from a local file, streamed (single PUT, so at most the
     * endpoint's single-upload limit — 5 GB on AWS S3).
     */
    public function putObjectFromFile(string $bucket, string $key, string $sourceFile, string $contentType): void
    {
        $payloadHash = is_file($sourceFile) ? hash_file('sha256', $sourceFile) : false;
        if ($payloadHash === false) {
            throw new StorageException('Unable to read the file to upload.');
        }
        [$url, $headers] = $this->prepareRequest(
            'PUT',
            $this->objectPath($bucket, $key),
            [],
            ['content-type' => $contentType !== '' ? $contentType : self::DEFAULT_CONTENT_TYPE],
            $payloadHash
        );
        S3Response::assertSuccessful($this->transport->sendFromFile('PUT', $url, $headers, $sourceFile));
    }

    /**
     * Fetches one ListObjectsV2 page of $bucket below $prefix ("" = root), starting at $token ("" = first page).
     *
     * @param array<string, string> $query the listing parameters other than prefix and continuation token
     */
    private function listPage(string $bucket, string $prefix, array $query, string $token): \SimpleXMLElement
    {
        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }
        if ($token !== '') {
            $query['continuation-token'] = $token;
        }

        $response = $this->request('GET', '/' . $this->encodePath($bucket), $query);
        if ($response['status'] >= 400) {
            throw new StorageException(S3Response::describeError($response));
        }

        return S3Response::xml($response['body']);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $extraHeaders
     *
     * @return array{status: int, body: string, error: string, responseHeaders: array<string, string>}
     */
    private function request(string $method, string $path, array $query = [], array $extraHeaders = [], ?string $body = null): array
    {
        $payloadHash = $body === null ? S3Signer::EMPTY_PAYLOAD_HASH : hash('sha256', $body);
        [$url, $headers] = $this->prepareRequest($method, $path, $query, $extraHeaders, $payloadHash);

        return $this->transport->send($method, $url, $headers, $body);
    }

    /**
     * The URL and signed "Name: value" header lines of a request to this endpoint.
     *
     * @param array<string, string> $query
     * @param array<string, string> $extraHeaders
     *
     * @return array{0: string, 1: string[]}
     */
    private function prepareRequest(
        string $method,
        string $path,
        array $query = [],
        array $extraHeaders = [],
        string $payloadHash = S3Signer::EMPTY_PAYLOAD_HASH
    ): array {
        $this->assertEndpointAllowed();

        $headers = $this->signer->sign(
            $method,
            $this->hostHeader,
            $path,
            $query,
            $extraHeaders,
            $payloadHash,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
        $url = $this->scheme . '://' . $this->hostHeader . $path;
        if ($query !== []) {
            $url .= '?' . S3Signer::canonicalQuery($query);
        }

        return [$url, $headers];
    }

    private function objectPath(string $bucket, string $key): string
    {
        return '/' . $this->encodePath($bucket) . '/' . $this->encodePath($key);
    }

    private function encodePath(string $segment): string
    {
        // Encode each path component but keep "/" so nested keys/prefixes stay intact.
        return implode('/', array_map('rawurlencode', explode('/', $segment)));
    }

    /**
     * Rejects an endpoint that resolves to a link-local / cloud-metadata address (169.254.0.0/16,
     * IPv6 fe80::/10, fd00:ec2::254), so an attacker-supplied "test connection" URL cannot turn the
     * signed S3 request into an instance-metadata probe. RFC1918/loopback (e.g. MinIO) stay allowed.
     */
    private function assertEndpointAllowed(): void
    {
        if ($this->endpointAllowed === true) {
            return;
        }

        $ips = [];
        if (filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            $ips[] = $this->host;
        } else {
            $resolved = @gethostbyname($this->host);
            if ($resolved !== $this->host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $resolved;
            }
        }
        foreach ($ips as $ip) {
            if (self::isLinkLocalAddress($ip)) {
                throw new StorageException('The configured storage endpoint is not permitted.');
            }
        }

        $this->endpointAllowed = true;
    }

    private static function isLinkLocalAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($ip, '169.254.');
        }
        $packed = @inet_pton($ip);
        if ($packed === false || \strlen($packed) !== 16) {
            return false;
        }
        $bytes = unpack('C*', $packed);

        return ($bytes[1] === 0xfe && ($bytes[2] & 0xc0) === 0x80) || strtolower($ip) === 'fd00:ec2::254';
    }
}
