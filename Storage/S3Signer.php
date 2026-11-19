<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

/**
 * AWS Signature Version 4 for S3 requests (header-based authentication).
 */
final class S3Signer
{
    public const string EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
    ) {
    }

    /**
     * The "Name: value" header lines of a signed request: $headers plus host, x-amz-content-sha256,
     * x-amz-date and Authorization. The secret key itself is never part of them.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers lower-cased names
     *
     * @return string[]
     */
    public function sign(
        string $method,
        string $host,
        string $path,
        array $query,
        array $headers,
        string $payloadHash,
        \DateTimeImmutable $now
    ): array {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $headers = array_merge($headers, [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ]);

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = 'Authorization: ' . $this->authorization($method, $path, $query, $headers, $amzDate, $now->format('Ymd'), $payloadHash);

        return $lines;
    }

    /**
     * The Authorization header value for a request whose headers are already final.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function authorization(
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
            self::canonicalQuery($query),
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

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp));

        return sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $scope,
            $signedHeaders,
            $signature
        );
    }

    /**
     * The query string in canonical (sorted, RFC 3986-encoded) form; also what goes on the URL.
     *
     * @param array<string, string> $query
     */
    public static function canonicalQuery(array $query): string
    {
        ksort($query);
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
