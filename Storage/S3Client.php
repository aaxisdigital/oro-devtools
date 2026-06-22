<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

/**
 * Minimal, dependency-free S3 client (AWS Signature V4, path-style addressing) built on curl.
 *
 * Only the read operations needed by the Bucket Browser are implemented: list a bucket with a
 * prefix/delimiter, fetch an object (optionally a byte range) and a HEAD bucket check. It targets
 * S3-compatible endpoints such as MinIO. Credentials are never echoed back to the client.
 */
class S3Client
{
    private const string EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private string $scheme;
    private string $host;
    private int $port;
    private ?bool $endpointAllowed = null;

    public function __construct(
        string $endpoint,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region = 'us-east-1',
    ) {
        $parts = parse_url($endpoint) ?: [];
        $this->scheme = (string) ($parts['scheme'] ?? 'http');
        $this->host = (string) ($parts['host'] ?? 'localhost');
        $this->port = (int) ($parts['port'] ?? ($this->scheme === 'https' ? 443 : 80));
    }

    /**
     * Lists the immediate children (common prefixes + objects) of $prefix in $bucket.
     *
     * @return array{prefixes: array<int, string>, objects: array<int, array{key: string, size: int, lastModified: int}>}
     */
    public function listObjects(string $bucket, string $prefix): array
    {
        $query = ['list-type' => '2', 'delimiter' => '/', 'max-keys' => '1000'];
        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }

        $response = $this->request('GET', '/' . $this->encodePath($bucket), $query);
        if ($response['status'] >= 400) {
            throw new \RuntimeException($this->describeError($response));
        }

        $xml = $this->parseXml($response['body']);
        $prefixes = [];
        foreach ($xml->CommonPrefixes ?? [] as $cp) {
            $prefixes[] = (string) $cp->Prefix;
        }
        $objects = [];
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
        $response = $this->request('GET', '/' . $this->encodePath($bucket) . '/' . $this->encodePath($key), [], $headers);
        if ($response['status'] >= 400) {
            throw new \RuntimeException($this->describeError($response));
        }

        $length = isset($response['responseHeaders']['content-length'])
            ? (int) $response['responseHeaders']['content-length']
            : null;

        return [
            'status' => $response['status'],
            'contentType' => $response['responseHeaders']['content-type'] ?? 'application/octet-stream',
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
            $response['status'] >= 400 => ['success' => false, 'message' => $this->describeError($response), 'details' => $details],
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
            '/' . $this->encodePath($bucket) . '/' . $this->encodePath($key),
            [],
            ['content-type' => $contentType !== '' ? $contentType : 'application/octet-stream'],
            $body
        );
        if ($response['status'] >= 400) {
            throw new \RuntimeException($this->describeError($response));
        }
    }

    /**
     * Deletes a single object.
     */
    public function deleteObject(string $bucket, string $key): void
    {
        $response = $this->request('DELETE', '/' . $this->encodePath($bucket) . '/' . $this->encodePath($key));
        if ($response['status'] >= 400 && $response['status'] !== 404) {
            throw new \RuntimeException($this->describeError($response));
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
        $token = null;
        do {
            $query = ['list-type' => '2', 'max-keys' => '1000'];
            if ($prefix !== '') {
                $query['prefix'] = $prefix;
            }
            if ($token !== null && $token !== '') {
                $query['continuation-token'] = $token;
            }

            $response = $this->request('GET', '/' . $this->encodePath($bucket), $query);
            if ($response['status'] >= 400) {
                throw new \RuntimeException($this->describeError($response));
            }
            $xml = $this->parseXml($response['body']);
            foreach ($xml->Contents ?? [] as $object) {
                $keys[] = (string) $object->Key;
            }
            $token = (string) ($xml->NextContinuationToken ?? '');
            $truncated = ((string) ($xml->IsTruncated ?? 'false')) === 'true';
        } while ($truncated && $token !== '');

        return $keys;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $extraHeaders
     *
     * @return array{status: int, body: string, error: string, responseHeaders: array<string, string>}
     */
    private function request(string $method, string $path, array $query = [], array $extraHeaders = [], ?string $body = null): array
    {
        $this->assertEndpointAllowed();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        $payloadHash = $body === null ? self::EMPTY_PAYLOAD_HASH : hash('sha256', $body);
        $hostHeader = $this->hostHeader();
        $headers = array_merge($extraHeaders, [
            'host' => $hostHeader,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ]);

        $authorization = $this->buildAuthorization($method, $path, $query, $headers, $amzDate, $dateStamp, $payloadHash);

        $url = $this->scheme . '://' . $hostHeader . $path;
        if ($query !== []) {
            $url .= '?' . $this->canonicalQuery($query);
        }

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $authorization;

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $header) use (&$responseHeaders): int {
            $pos = strpos($header, ':');
            if ($pos !== false) {
                $responseHeaders[strtolower(trim(substr($header, 0, $pos)))] = trim(substr($header, $pos + 1));
            }
            return \strlen($header);
        });

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        return [
            'status' => $status,
            'body' => $body === false ? '' : (string) $body,
            'error' => $error,
            'responseHeaders' => $responseHeaders,
        ];
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function buildAuthorization(
        string $method,
        string $path,
        array $query,
        array $headers,
        string $amzDate,
        string $dateStamp,
        string $payloadHash
    ): string {
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaderNames = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . trim($value) . "\n";
            $signedHeaderNames[] = strtolower($name);
        }
        $signedHeaders = implode(';', $signedHeaderNames);

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            $this->canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $dateStamp . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signingKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $scope,
            $signedHeaders,
            $signature
        );
    }

    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * @param array<string, string> $query
     */
    private function canonicalQuery(array $query): string
    {
        ksort($query);
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
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
                throw new \RuntimeException('The configured storage endpoint is not permitted.');
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
        if ($bytes[1] === 0xfe && ($bytes[2] & 0xc0) === 0x80) {
            return true;
        }

        return strtolower($ip) === 'fd00:ec2::254';
    }

    private function hostHeader(): string
    {
        $isDefault = ($this->scheme === 'https' && $this->port === 443) || ($this->scheme === 'http' && $this->port === 80);

        return $isDefault ? $this->host : $this->host . ':' . $this->port;
    }

    private function parseXml(string $body): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new \RuntimeException('Unexpected (non-XML) response from the storage endpoint.');
        }

        return $xml;
    }

    /**
     * @param array{status: int, body: string, error: string} $response
     */
    private function describeError(array $response): string
    {
        $message = '';
        try {
            $xml = $this->parseXml($response['body']);
            $code = (string) ($xml->Code ?? '');
            $msg = (string) ($xml->Message ?? '');
            $message = trim($code . ($msg !== '' ? ': ' . $msg : ''));
        } catch (\Throwable) {
            // ignore - fall back to the status code
        }

        return $message !== ''
            ? sprintf('Storage error (HTTP %d): %s', $response['status'], $message)
            : sprintf('Storage error (HTTP %d).', $response['status']);
    }
}
