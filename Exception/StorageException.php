<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Exception;

/**
 * Thrown by the storage browsers (local filesystem, S3-compatible bucket) when an operation cannot
 * be completed: unreadable paths, unreachable/forbidden endpoints, storage-side errors.
 */
class StorageException extends \RuntimeException
{
}
