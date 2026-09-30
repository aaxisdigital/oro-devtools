<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Exception;

/**
 * Thrown when the Redis Viewer cannot open a connection to the configured server.
 */
class RedisConnectionException extends \RuntimeException
{
}
