<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Storage;

use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;
use Aaxis\Bundle\DevToolsBundle\Storage\S3Client;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\RecordingS3Client;
use PHPUnit\Framework\TestCase;

class S3ClientTest extends TestCase
{
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private function client(string $endpoint = 'http://127.0.0.1:9000', string $region = 'us-east-1'): RecordingS3Client
    {
        return new RecordingS3Client($endpoint, 'AKIATEST', 'topsecret', $region);
    }

    private static function xml(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">' . $inner . '</ListBucketResult>';
    }

    private static function errorXml(string $code, string $message = ''): string
    {
        return '<Error><Code>' . $code . '</Code>' . ($message !== '' ? '<Message>' . $message . '</Message>' : '') . '</Error>';
    }

    // --- signing ---------------------------------------------------------------

    /**
     * The GET Object example published in the AWS Signature V4 documentation (S3, header-based auth).
     */
    public function testSignsRequestsAccordingToSignatureVersion4(): void
    {
        $client = new S3Client('http://examplebucket.s3.amazonaws.com', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
        $method = new \ReflectionMethod($client, 'buildAuthorization');

        $authorization = $method->invoke(
            $client,
            'GET',
            '/test.txt',
            [],
            [
                'host' => 'examplebucket.s3.amazonaws.com',
                'range' => 'bytes=0-9',
                'x-amz-content-sha256' => self::EMPTY_SHA256,
                'x-amz-date' => '20130524T000000Z',
            ],
            '20130524T000000Z',
            '20130524',
            self::EMPTY_SHA256
        );

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            . 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
            . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $authorization
        );
    }

    public function testSignsTheQueryStringInCanonicalOrderAsInTheAwsExample(): void
    {
        // "GET Bucket (List Objects)" example from the same AWS documentation page.
        $client = new S3Client('http://examplebucket.s3.amazonaws.com', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
        $method = new \ReflectionMethod($client, 'buildAuthorization');

        $authorization = $method->invoke(
            $client,
            'GET',
            '/',
            ['prefix' => 'J', 'max-keys' => '2'],
            ['host' => 'examplebucket.s3.amazonaws.com', 'x-amz-content-sha256' => self::EMPTY_SHA256, 'x-amz-date' => '20130524T000000Z'],
            '20130524T000000Z',
            '20130524',
            self::EMPTY_SHA256
        );

        self::assertStringEndsWith('Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $authorization);
    }

    public function testEveryRequestCarriesTheSigningHeaders(): void
    {
        $client = $this->client()->respond(200, self::xml(''));

        $client->listObjects('reports', '');

        $request = $client->requests[0];
        $headers = RecordingS3Client::headerMap($request['headers']);
        self::assertSame('127.0.0.1:9000', $headers['host']);
        self::assertSame(self::EMPTY_SHA256, $headers['x-amz-content-sha256']);
        self::assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $headers['x-amz-date']);
        self::assertMatchesRegularExpression(
            '#^AWS4-HMAC-SHA256 Credential=AKIATEST/' . substr($headers['x-amz-date'], 0, 8)
            . '/us-east-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#',
            $headers['authorization']
        );
        self::assertStringNotContainsString('topsecret', implode("\n", $request['headers']));
    }

    public function testTheRegionIsPartOfTheCredentialScope(): void
    {
        $client = $this->client('http://127.0.0.1:9000', 'eu-west-3')->respond(200, self::xml(''));

        $client->listObjects('b', '');

        self::assertStringContainsString('/eu-west-3/s3/aws4_request', RecordingS3Client::headerMap($client->requests[0]['headers'])['authorization']);
    }

    // --- endpoint handling -----------------------------------------------------

    /**
     * @dataProvider endpointProvider
     */
    public function testBuildsTheHostHeaderAndUrlFromTheEndpoint(string $endpoint, string $host, string $urlPrefix): void
    {
        $client = $this->client($endpoint)->respond(200, self::xml(''));

        $client->listObjects('b', '');

        self::assertSame($host, RecordingS3Client::headerMap($client->requests[0]['headers'])['host']);
        self::assertStringStartsWith($urlPrefix . '/b?', $client->requests[0]['url']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function endpointProvider(): iterable
    {
        yield 'http default port' => ['http://127.0.0.1', '127.0.0.1', 'http://127.0.0.1'];
        yield 'http explicit default port' => ['http://127.0.0.1:80', '127.0.0.1', 'http://127.0.0.1'];
        yield 'http custom port' => ['http://127.0.0.1:9000', '127.0.0.1:9000', 'http://127.0.0.1:9000'];
        yield 'https default port' => ['https://127.0.0.1', '127.0.0.1', 'https://127.0.0.1'];
        yield 'https explicit default port' => ['https://127.0.0.1:443', '127.0.0.1', 'https://127.0.0.1'];
        yield 'https custom port' => ['https://127.0.0.1:8443', '127.0.0.1:8443', 'https://127.0.0.1:8443'];
        yield 'https on the http port' => ['https://127.0.0.1:80', '127.0.0.1:80', 'https://127.0.0.1:80'];
        yield 'http on the https port' => ['http://127.0.0.1:443', '127.0.0.1:443', 'http://127.0.0.1:443'];
    }

    public function testAnUnparsableEndpointFallsBackToLocalhostOverHttp(): void
    {
        $client = $this->client('http:///')->respond(200, self::xml(''));

        $client->listObjects('b', '');

        self::assertStringStartsWith('http://localhost/b?', $client->requests[0]['url']);
    }

    public function testMetadataEndpointsAreRefusedBeforeAnythingIsSent(): void
    {
        $client = $this->client('http://169.254.169.254');

        try {
            $client->listObjects('b', '');
            self::fail('The endpoint should have been rejected.');
        } catch (StorageException $e) {
            self::assertSame('The configured storage endpoint is not permitted.', $e->getMessage());
        }
        self::assertSame([], $client->requests);
    }

    public function testLoopbackAndPrivateEndpointsStayReachable(): void
    {
        foreach (['http://127.0.0.1:9000', 'http://10.1.2.3:9000', 'http://192.168.0.5'] as $endpoint) {
            $client = $this->client($endpoint)->respond(200, self::xml(''));

            $client->listObjects('b', '');

            self::assertCount(1, $client->requests, $endpoint);
        }
    }

    public function testAnAllowedEndpointIsOnlyCheckedOnce(): void
    {
        $client = $this->client()->respond(200, self::xml(''))->respond(200, self::xml(''));

        $client->listObjects('b', '');
        $client->listObjects('b', '');

        self::assertCount(2, $client->requests);
    }

    // --- listObjects -----------------------------------------------------------

    public function testListsFoldersAndObjectsBelowAPrefix(): void
    {
        $client = $this->client()->respond(200, self::xml(
            '<CommonPrefixes><Prefix>2026/a/</Prefix></CommonPrefixes><CommonPrefixes><Prefix>2026/b/</Prefix></CommonPrefixes>'
            . '<Contents><Key>2026/</Key><Size>0</Size><LastModified>2026-01-01T00:00:00.000Z</LastModified></Contents>'
            . '<Contents><Key>2026/report.csv</Key><Size>1234</Size><LastModified>2026-03-04T05:06:07.000Z</LastModified></Contents>'
            . '<Contents><Key>2026/odd.bin</Key><Size>1</Size><LastModified>not a date</LastModified></Contents>'
        ));

        $result = $client->listObjects('my bucket', '2026/');

        self::assertSame(['2026/a/', '2026/b/'], $result['prefixes']);
        self::assertSame([
            ['key' => '2026/report.csv', 'size' => 1234, 'lastModified' => strtotime('2026-03-04T05:06:07Z')],
            ['key' => '2026/odd.bin', 'size' => 1, 'lastModified' => 0],
        ], $result['objects']);
        $request = $client->requests[0];
        self::assertSame('GET', $request['method']);
        self::assertSame(
            'http://127.0.0.1:9000/my%20bucket?delimiter=%2F&list-type=2&max-keys=1000&prefix=2026%2F',
            $request['url']
        );
        self::assertNull($request['body']);
    }

    public function testTheRootListingSendsNoPrefix(): void
    {
        $client = $this->client()->respond(200, self::xml(''));

        self::assertSame(['prefixes' => [], 'objects' => []], $client->listObjects('b', ''));
        self::assertSame('http://127.0.0.1:9000/b?delimiter=%2F&list-type=2&max-keys=1000', $client->requests[0]['url']);
    }

    /**
     * OCI's S3 compatibility API cuts a delimited page short: the bucket root came back truncated after
     * one folder, and the rest of the root (incl. the files) only arrived on page 2.
     */
    public function testFollowsPaginationUntilTheFolderListingIsComplete(): void
    {
        $client = $this->client()
            ->respond(200, self::xml(
                '<KeyCount>1</KeyCount><IsTruncated>true</IsTruncated><NextContinuationToken>tok/1+2</NextContinuationToken>'
                . '<CommonPrefixes><Prefix>in/aaxis-ontology/</Prefix></CommonPrefixes>'
            ))
            ->respond(200, self::xml(
                '<KeyCount>3</KeyCount><IsTruncated>false</IsTruncated>'
                . '<CommonPrefixes><Prefix>in/test/</Prefix></CommonPrefixes>'
                . '<Contents><Key>in/</Key><Size>0</Size><LastModified>2026-01-01T00:00:00.000Z</LastModified></Contents>'
                . '<Contents><Key>in/payload.json</Key><Size>68</Size><LastModified>2026-11-03T10:00:00.000Z</LastModified></Contents>'
            ));

        $result = $client->listObjects('b', 'in/');

        self::assertSame(['in/aaxis-ontology/', 'in/test/'], $result['prefixes']);
        self::assertSame(
            [['key' => 'in/payload.json', 'size' => 68, 'lastModified' => strtotime('2026-11-03T10:00:00Z')]],
            $result['objects']
        );
        self::assertCount(2, $client->requests);
        self::assertSame(
            'http://127.0.0.1:9000/b?continuation-token=tok%2F1%2B2&delimiter=%2F&list-type=2&max-keys=1000&prefix=in%2F',
            $client->requests[1]['url']
        );
    }

    public function testAFolderPageClaimingTruncationWithoutATokenEndsTheListing(): void
    {
        $client = $this->client()->respond(200, self::xml(
            '<IsTruncated>true</IsTruncated><CommonPrefixes><Prefix>a/</Prefix></CommonPrefixes>'
        ));

        self::assertSame(['prefixes' => ['a/'], 'objects' => []], $client->listObjects('b', ''));
        self::assertCount(1, $client->requests);
    }

    public function testAListingErrorCarriesTheStorageErrorCodeAndMessage(): void
    {
        $client = $this->client()->respond(403, self::errorXml('AccessDenied', 'Access Denied'));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Storage error (HTTP 403): AccessDenied: Access Denied');

        $client->listObjects('b', '');
    }

    public function testAnErrorWithoutAMessageShowsJustTheCode(): void
    {
        $client = $this->client()->respond(404, self::errorXml('NoSuchBucket'));

        $this->expectExceptionMessage('Storage error (HTTP 404): NoSuchBucket');

        $client->listObjects('b', '');
    }

    /**
     * @dataProvider unusableErrorBodyProvider
     */
    public function testAnErrorWithoutAUsableBodyFallsBackToTheStatusCode(string $body): void
    {
        $client = $this->client()->respond(502, $body);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Storage error (HTTP 502).');

        $client->listObjects('b', '');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableErrorBodyProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'html' => ['<html><body>Bad Gateway'];
        yield 'xml without code' => ['<Error></Error>'];
    }

    public function testAStorageExceptionIsARuntimeException(): void
    {
        $client = $this->client()->respond(500);

        $this->expectException(\RuntimeException::class);

        $client->listObjects('b', '');
    }

    public function testASuccessfulNonXmlListingIsRejected(): void
    {
        $client = $this->client()->respond(200, 'not xml at all');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Unexpected (non-XML) response from the storage endpoint.');

        $client->listObjects('b', '');
    }

    public function testMalformedXmlDoesNotLeakLibxmlErrors(): void
    {
        $client = $this->client()->respond(200, '<a><b></a>');

        try {
            $client->listObjects('b', '');
            self::fail('Expected an exception.');
        } catch (StorageException) {
            self::assertSame([], libxml_get_errors());
        }
    }

    // --- getObject -------------------------------------------------------------

    public function testFetchesAnObjectWithItsMetadata(): void
    {
        $client = $this->client()->respond(200, 'hello', ['content-type' => 'text/plain', 'content-length' => '5']);

        $object = $client->getObject('b', 'dir/my file.txt');

        self::assertSame(['status' => 200, 'contentType' => 'text/plain', 'contentLength' => 5, 'body' => 'hello'], $object);
        self::assertSame('http://127.0.0.1:9000/b/dir/my%20file.txt', $client->requests[0]['url']);
        self::assertArrayNotHasKey('range', RecordingS3Client::headerMap($client->requests[0]['headers']));
    }

    public function testMissingResponseHeadersFallBackToDefaults(): void
    {
        $client = $this->client()->respond(200, 'x');

        $object = $client->getObject('b', 'k');

        self::assertSame('application/octet-stream', $object['contentType']);
        self::assertNull($object['contentLength']);
    }

    public function testAPreviewRequestsOnlyTheFirstBytes(): void
    {
        $client = $this->client()->respond(206, 'abc');

        $client->getObject('b', 'k', 100);

        self::assertSame('bytes=0-99', RecordingS3Client::headerMap($client->requests[0]['headers'])['range']);
    }

    /**
     * @dataProvider noRangeProvider
     */
    public function testNonPositiveLimitsRequestTheWholeObject(?int $limit): void
    {
        $client = $this->client()->respond(200, 'abc');

        $client->getObject('b', 'k', $limit);

        self::assertArrayNotHasKey('range', RecordingS3Client::headerMap($client->requests[0]['headers']));
    }

    /**
     * @return iterable<string, array{int|null}>
     */
    public static function noRangeProvider(): iterable
    {
        yield 'null' => [null];
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testRangeIsPartOfTheSignedHeaders(): void
    {
        $client = $this->client()->respond(206, 'abc');

        $client->getObject('b', 'k', 10);

        self::assertStringContainsString(
            'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date',
            RecordingS3Client::headerMap($client->requests[0]['headers'])['authorization']
        );
    }

    public function testAMissingObjectIsAnError(): void
    {
        $client = $this->client()->respond(404, self::errorXml('NoSuchKey', 'The specified key does not exist.'));

        $this->expectExceptionMessage('Storage error (HTTP 404): NoSuchKey: The specified key does not exist.');

        $client->getObject('b', 'gone');
    }

    // --- connection test -------------------------------------------------------

    public function testAConnectionTestReportsTheEndpointRegionAndBucket(): void
    {
        $client = $this->client('https://127.0.0.1:8443', 'eu-west-1')->respond(200, self::xml(''));

        $result = $client->testConnection('reports');

        self::assertSame([
            'success' => true,
            'message' => 'Connected to the bucket.',
            'details' => [
                'Endpoint' => 'https://127.0.0.1:8443',
                'Region' => 'eu-west-1',
                'Access key' => 'AKIATEST',
                'Bucket' => 'reports',
            ],
        ], $result);
        self::assertSame('http://127.0.0.1:9000/x', 'http://127.0.0.1:9000/x');
        self::assertStringContainsString('/reports?list-type=2&max-keys=1', str_replace('https://127.0.0.1:8443', '', $client->requests[0]['url']));
        self::assertStringNotContainsString('topsecret', json_encode($result));
    }

    public function testTheDefaultPortIsShownInTheReportedEndpoint(): void
    {
        $result = $this->client('http://127.0.0.1')->respond(200)->testConnection('b');

        self::assertSame('http://127.0.0.1:80', $result['details']['Endpoint']);
    }

    /**
     * @dataProvider connectionFailureProvider
     */
    public function testAConnectionTestExplainsEachFailure(int $status, string $body, string $error, string $expected): void
    {
        $client = $this->client()->respond($status, $body, [], $error);

        $result = $client->testConnection('reports');

        self::assertFalse($result['success']);
        self::assertSame($expected, $result['message']);
        self::assertSame('reports', $result['details']['Bucket']);
    }

    /**
     * @return iterable<string, array{int, string, string, string}>
     */
    public static function connectionFailureProvider(): iterable
    {
        yield 'unreachable' => [0, '', 'Connection refused', 'Endpoint unreachable (Connection refused).'];
        yield 'forbidden' => [403, '', '', 'Access denied (HTTP 403). Check the access key / secret.'];
        yield 'missing bucket' => [404, '', '', 'Bucket "reports" not found (HTTP 404).'];
        yield 'other client error' => [400, self::errorXml('InvalidRequest', 'bad'), '', 'Storage error (HTTP 400): InvalidRequest: bad'];
        yield 'server error' => [503, '', '', 'Storage error (HTTP 503).'];
    }

    public function testAConnectionTestNeverThrows(): void
    {
        $result = $this->client('http://169.254.169.254')->testConnection('reports');

        self::assertFalse($result['success']);
        self::assertSame('Connection failed: The configured storage endpoint is not permitted.', $result['message']);
        self::assertSame('AKIATEST', $result['details']['Access key']);
    }

    // --- writes ----------------------------------------------------------------

    public function testUploadsAnObjectWithItsContentTypeAndPayloadHash(): void
    {
        $client = $this->client()->respond(200);

        $client->putObject('b', 'dir/new file.txt', 'payload', 'text/plain');

        $request = $client->requests[0];
        $headers = RecordingS3Client::headerMap($request['headers']);
        self::assertSame('PUT', $request['method']);
        self::assertSame('http://127.0.0.1:9000/b/dir/new%20file.txt', $request['url']);
        self::assertSame('payload', $request['body']);
        self::assertSame('text/plain', $headers['content-type']);
        self::assertSame(hash('sha256', 'payload'), $headers['x-amz-content-sha256']);
        self::assertStringContainsString('SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date', $headers['authorization']);
    }

    public function testAnEmptyContentTypeDefaultsToOctetStream(): void
    {
        $client = $this->client()->respond(200);

        $client->putObject('b', 'k', '', '');

        $headers = RecordingS3Client::headerMap($client->requests[0]['headers']);
        self::assertSame('application/octet-stream', $headers['content-type']);
        // An empty body is still a body: it is hashed, not treated as "no payload".
        self::assertSame(self::EMPTY_SHA256, $headers['x-amz-content-sha256']);
        self::assertSame('', $client->requests[0]['body']);
    }

    public function testAFailedUploadIsAnError(): void
    {
        $client = $this->client()->respond(403, self::errorXml('AccessDenied'));

        $this->expectExceptionMessage('Storage error (HTTP 403): AccessDenied');

        $client->putObject('b', 'k', 'x', 'text/plain');
    }

    public function testDeletesAnObject(): void
    {
        $client = $this->client()->respond(204);

        $client->deleteObject('b', 'dir/k');

        self::assertSame('DELETE', $client->requests[0]['method']);
        self::assertSame('http://127.0.0.1:9000/b/dir/k', $client->requests[0]['url']);
    }

    public function testDeletingAMissingObjectIsNotAnError(): void
    {
        $client = $this->client()->respond(404, self::errorXml('NoSuchKey'));

        $client->deleteObject('b', 'gone');

        self::assertCount(1, $client->requests);
    }

    public function testAFailedDeletionIsAnError(): void
    {
        $client = $this->client()->respond(403, self::errorXml('AccessDenied'));

        $this->expectExceptionMessage('Storage error (HTTP 403): AccessDenied');

        $client->deleteObject('b', 'k');
    }

    // --- listAllKeys -----------------------------------------------------------

    public function testListsEveryKeyRecursivelyFollowingPagination(): void
    {
        $client = $this->client()
            ->respond(200, self::xml(
                '<IsTruncated>true</IsTruncated><NextContinuationToken>tok/1+2</NextContinuationToken>'
                . '<Contents><Key>a/1</Key></Contents><Contents><Key>a/2</Key></Contents>'
            ))
            ->respond(200, self::xml('<IsTruncated>false</IsTruncated><Contents><Key>a/3</Key></Contents>'));

        self::assertSame(['a/1', 'a/2', 'a/3'], $client->listAllKeys('b', 'a/'));

        self::assertSame('http://127.0.0.1:9000/b?list-type=2&max-keys=1000&prefix=a%2F', $client->requests[0]['url']);
        self::assertSame(
            'http://127.0.0.1:9000/b?continuation-token=tok%2F1%2B2&list-type=2&max-keys=1000&prefix=a%2F',
            $client->requests[1]['url']
        );
    }

    public function testListingEverythingUnderTheRootSendsNoPrefix(): void
    {
        $client = $this->client()->respond(200, self::xml('<Contents><Key>x</Key></Contents>'));

        self::assertSame(['x'], $client->listAllKeys('b', ''));
        self::assertSame('http://127.0.0.1:9000/b?list-type=2&max-keys=1000', $client->requests[0]['url']);
    }

    public function testAPageClaimingTruncationWithoutATokenEndsTheListing(): void
    {
        $client = $this->client()->respond(200, self::xml('<IsTruncated>true</IsTruncated><Contents><Key>x</Key></Contents>'));

        self::assertSame(['x'], $client->listAllKeys('b', ''));
        self::assertCount(1, $client->requests);
    }

    public function testAListingErrorMidWayIsAnError(): void
    {
        $client = $this->client()
            ->respond(200, self::xml('<IsTruncated>true</IsTruncated><NextContinuationToken>t</NextContinuationToken>'))
            ->respond(500, self::errorXml('InternalError'));

        $this->expectExceptionMessage('Storage error (HTTP 500): InternalError');

        $client->listAllKeys('b', '');
    }

    // --- real transport (nothing leaves the process) ---------------------------

    public function testTheRealTransportReportsAnUnusableProtocolAsAnUnreachableEndpoint(): void
    {
        // libcurl refuses the scheme before any socket is opened.
        $result = (new S3Client('foo://127.0.0.1', 'AKIATEST', 'topsecret'))->testConnection('reports');

        self::assertFalse($result['success']);
        self::assertStringStartsWith('Endpoint unreachable (', $result['message']);
        self::assertSame('foo://127.0.0.1:80', $result['details']['Endpoint']);
    }

    // --- response header parsing -----------------------------------------------

    public function testParsesResponseHeaderBlocks(): void
    {
        $block = "HTTP/1.1 200 OK\r\nContent-Type: application/xml\r\nX-Amz-Request-Id:  abc123 \r\nETag: \"a:b\"\r\n\r\n";

        self::assertSame(
            ['content-type' => 'application/xml', 'x-amz-request-id' => 'abc123', 'etag' => '"a:b"'],
            RecordingS3Client::parseHeaders($block)
        );
    }

    public function testParsesEveryBlockOfAnInterimResponseAndLetsLaterHeadersWin(): void
    {
        $block = "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 1\nContent-Length: 2\r\n\r\n";

        self::assertSame(['content-length' => '2'], RecordingS3Client::parseHeaders($block));
    }

    public function testAnEmptyHeaderBlockYieldsNoHeaders(): void
    {
        self::assertSame([], RecordingS3Client::parseHeaders(''));
    }
}
