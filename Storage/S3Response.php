<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;

/**
 * Interpreting S3 responses: XML documents, error descriptions and listing pagination.
 */
final class S3Response
{
    /**
     * Fails unless the exchange reached the endpoint and succeeded.
     *
     * @param array{status: int, body: string, error: string} $response
     */
    public static function assertSuccessful(array $response): void
    {
        if ($response['status'] === 0) {
            throw new StorageException(sprintf('The storage endpoint is unreachable (%s).', $response['error']));
        }
        if ($response['status'] >= 400) {
            throw new StorageException(self::describeError($response));
        }
    }

    /**
     * "Storage error (HTTP 403): AccessDenied: …" from an S3 error document, or just the status.
     *
     * @param array{status: int, body: string} $response
     */
    public static function describeError(array $response): string
    {
        $message = '';
        try {
            $xml = self::xml($response['body']);
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

    /**
     * Parses an XML document without letting libxml errors leak.
     */
    public static function xml(string $body): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new StorageException('Unexpected (non-XML) response from the storage endpoint.');
        }

        return $xml;
    }

    /**
     * The continuation token of a listing's next page; "" once the listing is complete (a page that
     * claims truncation without a token also ends it).
     */
    public static function nextToken(\SimpleXMLElement $xml): string
    {
        if ((string) ($xml->IsTruncated ?? 'false') !== 'true') {
            return '';
        }

        return (string) ($xml->NextContinuationToken ?? '');
    }
}
