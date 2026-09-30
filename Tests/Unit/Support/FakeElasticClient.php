<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support;

/**
 * Hand-written stand-in for Elastic\Elasticsearch\Client (a final class): only the cat()->indices()
 * and esql()->query() calls the inspector makes, each answering with an object exposing asArray().
 */
final class FakeElasticClient
{
    /** @var array<int, array<string, mixed>> */
    public array $catIndices = [];

    /** @var array<string, mixed> */
    public array $esql = [];

    /** @var array<string, mixed>|null */
    public ?array $catIndicesParams = null;

    /** @var array<string, mixed>|null */
    public ?array $esqlParams = null;

    public function cat(): object
    {
        $client = $this;

        return new class($client) {
            public function __construct(private readonly FakeElasticClient $client)
            {
            }

            public function indices(array $params): object
            {
                $this->client->catIndicesParams = $params;

                return FakeElasticClient::response($this->client->catIndices);
            }
        };
    }

    public function esql(): object
    {
        $client = $this;

        return new class($client) {
            public function __construct(private readonly FakeElasticClient $client)
            {
            }

            public function query(array $params): object
            {
                $this->client->esqlParams = $params;

                return FakeElasticClient::response($this->client->esql);
            }
        };
    }

    /**
     * @param array<mixed> $data
     */
    public static function response(array $data): object
    {
        return new class($data) {
            public function __construct(private readonly array $data)
            {
            }

            public function asArray(): array
            {
                return $this->data;
            }
        };
    }
}
